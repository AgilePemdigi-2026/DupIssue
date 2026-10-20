import unittest
from unittest.mock import patch

from fastapi.testclient import TestClient

import main


class SimilarityRow:
    def __init__(self, scores: list[float]):
        self.scores = scores

    def __getitem__(self, index: int):
        return self

    def tolist(self) -> list[float]:
        return self.scores


class FakeModel:
    def __init__(self):
        self.encoded_texts: list[str] = []

    def encode(self, texts: list[str], **kwargs: object) -> list[str]:
        self.encoded_texts = texts
        return [text.split("\n", maxsplit=1)[0] for text in texts]


class CandidatesEndpointTests(unittest.TestCase):
    def setUp(self):
        self.model = FakeModel()
        self.model_patcher = patch.object(
            main,
            "SentenceTransformer",
            return_value=self.model,
        )
        self.model_constructor = self.model_patcher.start()
        self.cosine_patcher = patch.object(main.util, "cos_sim", side_effect=self.fake_cosine)
        self.cosine_patcher.start()
        self.client = TestClient(main.app)
        self.client.__enter__()

    def tearDown(self):
        self.client.__exit__(None, None, None)
        self.cosine_patcher.stop()
        self.model_patcher.stop()

    @staticmethod
    def fake_cosine(query: str, candidates: list[str]) -> SimilarityRow:
        score_by_title = {
            "Title: candidate lower": 0.42,
            "Title: candidate higher": 0.88,
        }
        return SimilarityRow([score_by_title[candidate] for candidate in candidates])

    def test_health_endpoint_is_preserved(self):
        response = self.client.get("/health")

        self.assertEqual(response.status_code, 200)
        self.assertEqual(
            response.json(),
            {"status": "ok", "service": "DupIssue NLP Service"},
        )

    @staticmethod
    def request_payload() -> dict:
        return {
            "project_id": "project-a",
            "new_issue": {
                "issue_id": "new-issue",
                "title": "new issue title",
                "description": "new issue description",
            },
            "candidates": [
                {
                    "issue_id": "lower",
                    "project_id": "project-a",
                    "title": "candidate lower",
                    "description": "candidate description",
                },
                {
                    "issue_id": "higher",
                    "project_id": "project-a",
                    "title": "candidate higher",
                    "description": "candidate description",
                },
                {
                    "issue_id": "other-project",
                    "project_id": "project-b",
                    "title": "other project issue",
                    "description": "must be ignored",
                },
                {
                    "issue_id": "new-issue",
                    "project_id": "project-a",
                    "title": "same issue",
                    "description": "must be ignored",
                },
            ],
        }

    def test_same_project_candidates_are_scored_and_sorted(self):
        response = self.client.post("/candidates", json=self.request_payload())
        repeated_response = self.client.post("/candidates", json=self.request_payload())

        self.assertEqual(response.status_code, 200)
        self.assertEqual(repeated_response.status_code, 200)
        self.assertEqual(
            response.json(),
            {
                "candidates": [
                    {"issue_id": "higher", "similarity": 0.88},
                    {"issue_id": "lower", "similarity": 0.42},
                ]
            },
        )
        self.assertEqual(len(self.model.encoded_texts), 3)
        self.model_constructor.assert_called_once()

    def test_similarity_model_failure_returns_generic_service_unavailable(self):
        with patch.object(
            self.model,
            "encode",
            side_effect=RuntimeError("private/path/model-error"),
        ):
            response = self.client.post(
                "/similarity",
                json={
                    "issue1": {"title": "Login error", "description": "Cannot log in."},
                    "issue2": {"title": "Login failure", "description": "Login does not work."},
                },
            )

        self.assertEqual(response.status_code, 503)
        self.assertEqual(
            response.json(),
            {"detail": "Similarity service is temporarily unavailable."},
        )
        self.assertNotIn("private/path/model-error", response.text)

    def test_candidate_model_failure_returns_no_fake_results(self):
        payload = self.request_payload()
        payload["candidates"] = [payload["candidates"][0]]
        with patch.object(
            self.model,
            "encode",
            side_effect=RuntimeError("private/path/model-error"),
        ):
            response = self.client.post("/candidates", json=payload)

        self.assertEqual(response.status_code, 503)
        self.assertEqual(
            response.json(),
            {"detail": "Similarity service is temporarily unavailable."},
        )
        self.assertNotIn("candidates", response.json())
        self.assertNotIn("private/path/model-error", response.text)

    def test_similarity_cosine_failure_returns_service_unavailable(self):
        with patch.object(
            main.util,
            "cos_sim",
            side_effect=RuntimeError("private cosine failure"),
        ):
            response = self.client.post(
                "/similarity",
                json={
                    "issue1": {"title": "Login error", "description": "Cannot log in."},
                    "issue2": {"title": "Login failure", "description": "Login does not work."},
                },
            )

        self.assertEqual(response.status_code, 503)
        self.assertNotIn("private cosine failure", response.text)

    def test_candidates_cosine_failure_returns_no_fake_results(self):
        payload = self.request_payload()
        payload["candidates"] = [payload["candidates"][0]]
        with patch.object(
            main.util,
            "cos_sim",
            side_effect=RuntimeError("private cosine failure"),
        ):
            response = self.client.post("/candidates", json=payload)

        self.assertEqual(response.status_code, 503)
        self.assertNotIn("candidates", response.json())
        self.assertNotIn("private cosine failure", response.text)

    def test_model_startup_failure_is_generic_and_mockable(self):
        self.model_constructor.reset_mock()
        self.model_constructor.side_effect = RuntimeError("private model load path")

        with self.assertRaisesRegex(RuntimeError, "NLP model could not be loaded") as error:
            with TestClient(main.app):
                pass

        self.assertNotIn("private model load path", str(error.exception))
        self.model_constructor.assert_called_once()

    def test_invalid_similarity_input_still_returns_422(self):
        response = self.client.post(
            "/similarity",
            json={
                "issue1": {"title": " ", "description": "Cannot log in."},
                "issue2": {"title": "Login failure", "description": "Login does not work."},
            },
        )

        self.assertEqual(response.status_code, 422)

    def test_different_project_candidates_are_ignored(self):
        payload = self.request_payload()
        payload["candidates"] = [payload["candidates"][2]]

        response = self.client.post("/candidates", json=payload)

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {"candidates": []})
        self.assertEqual(self.model.encoded_texts, [])

    def test_empty_candidate_list_returns_empty_results(self):
        payload = self.request_payload()
        payload["candidates"] = []

        response = self.client.post("/candidates", json=payload)

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {"candidates": []})

    def test_invalid_issue_data_returns_validation_error(self):
        payload = self.request_payload()
        payload["candidates"][0]["title"] = "   "

        response = self.client.post("/candidates", json=payload)

        self.assertEqual(response.status_code, 422)

    def test_missing_required_field_returns_validation_error(self):
        payload = self.request_payload()
        del payload["candidates"][0]["description"]

        response = self.client.post("/candidates", json=payload)

        self.assertEqual(response.status_code, 422)


if __name__ == "__main__":
    unittest.main()
