import concurrent.futures
import threading
import time
import unittest
from unittest.mock import patch

import torch
from fastapi.testclient import TestClient
from sentence_transformers import util

import main
from embedding_cache import EmbeddingCache


class CountingModel:
    def __init__(self, delay=0):
        self.calls = []
        self.delay = delay
        self.fail = False

    def encode(self, texts, **kwargs):
        self.calls.append(list(texts))
        time.sleep(self.delay)
        if self.fail:
            raise RuntimeError("Model unavailable")
        return torch.tensor([[float(b) for b in EmbeddingCache.digest(t)[:8]] for t in texts])


class EmbeddingCacheTests(unittest.TestCase):
    def setUp(self):
        self.cache = EmbeddingCache()
        self.model = CountingModel()
        self.issues = [main.NewIssue(issue_id=i, title=f"Issue {i}", description="Description") for i in range(3)]

    def encode(self, project=1, version="v1", issues=None, cache=None):
        return (cache or self.cache).encode(self.model, version, project,
                                           self.issues if issues is None else issues, main.combine_issue_text)

    def test_cold_miss_then_hit_without_model_call(self):
        cold = self.encode()
        warm = self.encode()
        self.assertTrue(torch.equal(cold, warm))
        self.assertEqual(len(self.model.calls), 1)
        self.assertEqual(self.cache.stats()["hits"], 3)
        self.assertEqual(self.cache.stats()["misses"], 3)

    def test_title_and_description_edits_replace_only_changed_embedding(self):
        self.encode()
        for field in ["title", "description"]:
            setattr(self.issues[1], field, "New content")
            self.encode()
            self.assertEqual(len(self.model.calls[-1]), 1)
        self.assertEqual(self.cache.stats()["entries"], 3)
        self.assertEqual(self.cache.stats()["replacements"], 2)

    def test_normalization_matches_existing_validator(self):
        self.encode()
        equivalent = [main.NewIssue(issue_id=i.issue_id, title=f" {i.title} ", description=f"\n{i.description}\n") for i in self.issues]
        self.encode(issues=equivalent)
        self.assertEqual(len(self.model.calls), 1)

    def test_version_change_cannot_reuse_old_model_embedding(self):
        self.encode()
        self.encode(version="v2")
        self.assertEqual(len(self.model.calls), 2)
        self.assertEqual(self.cache.stats()["hits"], 0)

    def test_project_and_issue_identity_are_isolated(self):
        self.encode(project="private-a")
        self.encode(project="private-b")
        self.issues[0].issue_id = 99
        self.encode(project="private-b")
        self.assertEqual([len(call) for call in self.model.calls], [3, 3, 1])

    def test_deleted_or_replaced_repository_issues_are_never_enumerated(self):
        self.encode()
        subset = self.encode(issues=self.issues[1:])
        self.assertEqual(len(subset), 2)
        replacement = main.NewIssue(issue_id=100, title="Replacement repo", description="New issue")
        self.assertEqual(len(self.encode(issues=[replacement])), 1)
        self.assertEqual(len(self.model.calls[-1]), 1)

    def test_restart_has_no_persistent_embeddings(self):
        self.encode()
        self.encode(cache=EmbeddingCache())
        self.assertEqual(len(self.model.calls), 2)

    def test_lru_eviction_and_vector_storage_do_not_retain_batch(self):
        cache = EmbeddingCache(max_entries=2)
        self.encode(cache=cache)
        self.assertEqual(cache.stats()["entries"], 2)
        self.assertEqual(cache.stats()["evictions"], 1)
        for _, vector, _, _ in cache._entries.values():
            self.assertEqual(vector.untyped_storage().nbytes(), vector.numel() * vector.element_size())
        self.encode(cache=cache, issues=[self.issues[1]])
        self.encode(cache=cache, issues=[self.issues[0]])
        self.assertEqual(cache.stats()["evictions"], 2)

    def test_byte_limit_and_disabled_cache(self):
        for budget, expected in [(1056, 1), (1, 0)]:
            cache = EmbeddingCache(max_bytes=budget)
            self.encode(cache=cache)
            self.assertEqual(cache.stats()["entries"], expected)
            self.assertLessEqual(cache.stats()["accounted_bytes"], budget)

    def test_absolute_ttl_expires_even_frequently_read_vectors(self):
        now = [0]
        cache = EmbeddingCache(ttl_seconds=10, clock=lambda: now[0])
        self.encode(cache=cache)
        now[0] = 9
        self.encode(cache=cache)
        now[0] = 10
        self.encode(cache=cache)
        self.assertEqual(cache.stats()["expirations"], 3)
        self.assertEqual(len(self.model.calls), 2)

    def test_concurrent_requests_single_flight_and_identical_results(self):
        self.model.delay = .03
        barrier = threading.Barrier(4)
        def request(_):
            barrier.wait()
            return self.encode()
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            results = list(pool.map(request, range(4)))
        self.assertEqual(len(self.model.calls), 1)
        self.assertTrue(all(torch.equal(results[0], r) for r in results))
        self.assertEqual(self.cache.stats()["hits"], 9)

    def test_wait_is_bounded_and_capacity_recovers(self):
        cache = EmbeddingCache(wait_seconds=.01)
        with cache.inference():
            with self.assertRaises(TimeoutError):
                self.encode(cache=cache)
        self.assertEqual(len(self.encode(cache=cache)), 3)

    def test_concurrent_old_and_new_content_each_use_their_own_snapshot(self):
        old = self.issues[0]
        new = main.NewIssue(issue_id=old.issue_id, title="Edited title", description="Edited description")
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            old_future = pool.submit(self.encode, issues=[old])
            new_future = pool.submit(self.encode, issues=[new])
            old_vector, new_vector = old_future.result(), new_future.result()
        self.assertFalse(torch.equal(old_vector, new_vector))
        self.assertTrue(torch.equal(self.encode(issues=[new]), new_vector))
        self.assertTrue(torch.equal(self.encode(issues=[old]), old_vector))

    def test_encode_failure_does_not_poison_cache_or_hold_lock(self):
        self.model.fail = True
        with self.assertRaises(RuntimeError):
            self.encode()
        self.assertEqual(self.cache.stats()["entries"], 0)
        self.model.fail = False
        self.encode()
        self.assertEqual(self.cache.stats()["entries"], 3)

    def test_nonfinite_embeddings_are_not_cached(self):
        with patch.object(self.model, "encode", return_value=torch.full((3, 8), float("nan"))):
            with self.assertRaises(ValueError):
                self.encode()
        self.assertEqual(self.cache.stats()["entries"], 0)

    def test_scores_and_stable_ranking_match_uncached_after_incremental_change(self):
        for _ in range(2):
            baseline = self.model.encode([main.combine_issue_text(i) for i in self.issues])
            cached = self.encode()
            before = util.cos_sim(baseline[0], baseline[1:])[0]
            after = util.cos_sim(cached[0], cached[1:])[0]
            torch.testing.assert_close(before, after, rtol=0, atol=1e-6)
            self.assertEqual(before.argsort().tolist(), after.argsort().tolist())
            self.issues[1].description = "Changed issue content"

    def test_actual_endpoint_concurrency_and_overload_recovery(self):
        with patch.object(main, "SentenceTransformer", return_value=self.model):
            with TestClient(main.app) as client:
                payload = {"project_id": 1, "new_issue": self.issues[0].model_dump(),
                           "candidates": [dict(i.model_dump(), project_id=1) for i in self.issues[1:]]}
                with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
                    results = list(pool.map(lambda _: client.post("/candidates", json=payload), range(4)))
                self.assertTrue(all(r.status_code == 200 for r in results))
                self.assertTrue(all(r.json() == results[0].json() for r in results))
                self.assertEqual(len(self.model.calls), 1)
                cache = main.app.state.embedding_cache
                cache.wait_seconds = .01
                with cache.inference():
                    response = client.post("/candidates", json=payload)
                self.assertEqual(response.status_code, 503)
                self.assertEqual(client.post("/candidates", json=payload).status_code, 200)


if __name__ == "__main__":
    unittest.main()
