"""Bounded offline F2 experiment. Diagnostic endpoints exist only in this QA host.

Run --serve baseline|optimized in a dedicated process, then --benchmark MODE.
Baseline source must be preserved at .qa/f2-baseline-main.py before editing main.
Never bind the diagnostic host to a public interface.
"""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import statistics
import subprocess
import sys
import threading
import time
from contextlib import asynccontextmanager

ROOT = Path(__file__).resolve().parents[3]
URL = "http://127.0.0.1:18766"


def serve(mode):
    import uvicorn
    path = ROOT / (".qa/f2-baseline-main.py" if mode == "baseline" else "nlp-service/main.py")
    sys.path.insert(0, str(ROOT / "nlp-service"))
    spec = importlib.util.spec_from_file_location("qa_nlp", path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    metrics = {"encode_seconds": 0., "cosine_seconds": 0., "encoded_texts": 0}
    original_cosine = module.util.cos_sim

    def cosine(*args, **kwargs):
        start = time.perf_counter()
        result = original_cosine(*args, **kwargs)
        metrics["cosine_seconds"] += time.perf_counter() - start
        return result

    module.util.cos_sim = cosine
    original_lifespan = module.app.router.lifespan_context

    @asynccontextmanager
    async def lifespan(app):
        async with original_lifespan(app):
            original_encode = app.state.model.encode

            def encode(texts, **kwargs):
                start = time.perf_counter()
                result = original_encode(texts, **kwargs)
                metrics["encode_seconds"] += time.perf_counter() - start
                metrics["encoded_texts"] += len(texts)
                return result

            app.state.model.encode = encode
            yield

    module.app.router.lifespan_context = lifespan

    @module.app.get("/qa/stats")
    def stats():
        cache = getattr(module.app.state, "embedding_cache", None)
        return {"pid": os.getpid(), **metrics, "cache": cache.stats() if cache else None}

    @module.app.post("/qa/reset")
    def reset():
        cache = getattr(module.app.state, "embedding_cache", None)
        if cache:
            cache.clear()
        metrics.update(encode_seconds=0., cosine_seconds=0., encoded_texts=0)
        return {"ok": True}

    uvicorn.run(module.app, host="127.0.0.1", port=18766, access_log=False)


def benchmark(mode):
    import httpx
    report = {"mode": mode, "repetitions": 5, "cpu_threads": 2, "rows": []}
    with httpx.Client(base_url=URL, timeout=120, trust_env=False) as client:
        pid = client.get("/qa/stats").json()["pid"]
        for size in [10, 100, 1000, 5000]:
            payload = {"project_id": 1, "new_issue": {"issue_id": 1, "title": "Checkout payment failure", "description": "The checkout payment button sometimes fails for an account."},
                       "candidates": [{"project_id": 1, "issue_id": i, "title": f"Checkout payment failure {i}", "description": f"The checkout payment button sometimes fails for account {i}."} for i in range(2, size + 1)]}
            phases = ["uncached"] if mode == "baseline" else ["cold", "warm", "incremental"]
            for phase in phases:
                runs = []
                for iteration in range(5):
                    if phase in ("cold", "uncached"):
                        client.post("/qa/reset").raise_for_status()
                    if phase == "incremental":
                        payload["candidates"][0]["description"] = f"Updated payment error number {iteration}."
                        payload["new_issue"]["description"] = f"Typing a revised checkout failure report {iteration}."
                    before = client.get("/qa/stats").json()
                    stop, samples = threading.Event(), []

                    def sample():
                        while not stop.is_set():
                            samples.append(int(subprocess.check_output(["ps", "-o", "rss=", "-p", str(pid)], text=True).strip()) / 1024)
                            stop.wait(.05)

                    sampler = threading.Thread(target=sample, daemon=True)
                    sampler.start()
                    start = time.perf_counter()
                    try:
                        response = client.post("/candidates", json=payload)
                        response.raise_for_status()
                        seconds = time.perf_counter() - start
                        body = response.json()
                        assert len(body["candidates"]) == size - 1
                    finally:
                        stop.set()
                        sampler.join()
                    after = client.get("/qa/stats").json()
                    run = {"seconds": seconds, "peak_rss_mib": max(samples), "encode_seconds": after["encode_seconds"] - before["encode_seconds"],
                           "cosine_seconds": after["cosine_seconds"] - before["cosine_seconds"], "encoded_texts": after["encoded_texts"] - before["encoded_texts"], "cache": after["cache"]}
                    if phase != "incremental":
                        reference = ROOT / f".qa/f2-scores-{size}.json"
                        if mode == "baseline" and iteration == 0:
                            reference.write_text(json.dumps(body))
                        elif mode == "optimized":
                            previous = json.loads(reference.read_text())["candidates"]
                            scores = {r["issue_id"]: r["similarity"] for r in body["candidates"]}
                            error = max(abs(r["similarity"] - scores[r["issue_id"]]) for r in previous)
                            assert error <= 1e-6, error
                            assert [r["issue_id"] for r in previous[:3]] == [r["issue_id"] for r in body["candidates"][:3]]
                            run["max_score_error"] = error
                            run["top3_equal"] = True
                    runs.append(run)
                row = {"size": size, "phase": phase, "runs": runs, "median_seconds": statistics.median(r["seconds"] for r in runs),
                       "requests_per_minute": 300 / sum(r["seconds"] for r in runs)}
                report["rows"].append(row)
                (ROOT / f".qa/f2-{mode}.json").write_text(json.dumps(report, indent=2))
                print(json.dumps({k: v for k, v in row.items() if k != "runs"}), flush=True)


def precompute():
    """Evaluate bounded prewarming through the existing API, outside user traffic.

    This is NOT an installed production scheduler or a 5000-job load test.
    """
    import httpx
    payload = {"project_id": 1, "new_issue": {"issue_id": 1, "title": "Checkout payment failure", "description": "The checkout payment button sometimes fails for an account."},
               "candidates": [{"project_id": 1, "issue_id": i, "title": f"Checkout payment failure {i}", "description": f"The checkout payment button sometimes fails for account {i}."} for i in range(2, 5001)]}
    results = []
    with httpx.Client(base_url=URL, timeout=120, trust_env=False) as client:
        for trial in range(3):
            client.post("/qa/reset").raise_for_status()
            start = time.perf_counter()
            durations = []
            for offset in range(0, len(payload["candidates"]), 256):
                chunk = dict(payload, candidates=payload["candidates"][offset:offset + 256])
                step = time.perf_counter()
                client.post("/candidates", json=chunk).raise_for_status()
                durations.append(time.perf_counter() - step)
            warmup_seconds = time.perf_counter() - start
            start = time.perf_counter()
            response = client.post("/candidates", json=payload)
            response.raise_for_status()
            seconds = time.perf_counter() - start
            baseline = json.loads((ROOT / ".qa/f2-scores-5000.json").read_text())["candidates"]
            actual = response.json()["candidates"]
            scores = {r["issue_id"]: r["similarity"] for r in actual}
            error = max(abs(r["similarity"] - scores[r["issue_id"]]) for r in baseline)
            assert error <= 1e-6
            assert [r["issue_id"] for r in baseline[:3]] == [r["issue_id"] for r in actual[:3]]
            results.append({"trial": trial, "precompute_seconds": warmup_seconds, "chunks": len(durations),
                            "chunk_seconds": durations, "first_full_request_seconds": seconds,
                            "max_score_error": error, "stats": client.get("/qa/stats").json()})
            print(json.dumps({k: v for k, v in results[-1].items() if k not in ["stats", "chunk_seconds"]}), flush=True)
            (ROOT / ".qa/f2-precompute.json").write_text(json.dumps(results, indent=2))


def concurrency():
    import concurrent.futures
    import httpx
    payload = {"project_id": 1, "new_issue": {"issue_id": 1, "title": "Checkout payment failure", "description": "The checkout payment button sometimes fails for an account."},
               "candidates": [{"project_id": 1, "issue_id": i, "title": f"Checkout payment failure {i}", "description": f"The checkout payment button sometimes fails for account {i}."} for i in range(2, 5001)]}
    with httpx.Client(base_url=URL, timeout=120, trust_env=False) as client:
        client.post("/qa/reset").raise_for_status()
        def request():
            start = time.perf_counter()
            result = client.post("/candidates", json=payload)
            return {"status": result.status_code, "seconds": time.perf_counter() - start,
                    "top3": result.json().get("candidates", [])[:3]}
        with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
            first = pool.submit(request)
            time.sleep(.25)
            competing = pool.submit(request).result()
            cold = first.result()
            assert cold["status"] == 200
            assert competing["status"] == 503
            warm = list(pool.map(lambda _: request(), range(4)))
        assert all(r["status"] == 200 and r["top3"] == cold["top3"] for r in warm)
        result = {"cold": cold, "competing": competing, "four_concurrent_warm": warm,
                  "stats": client.get("/qa/stats").json()}
        (ROOT / ".qa/f2-concurrency.json").write_text(json.dumps(result, indent=2))
        print(json.dumps(result), flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--serve", choices=["baseline", "optimized"])
    group.add_argument("--benchmark", choices=["baseline", "optimized"])
    group.add_argument("--precompute", action="store_true")
    group.add_argument("--concurrency", action="store_true")
    args = parser.parse_args()
    if args.serve:
        serve(args.serve)
    elif args.benchmark:
        benchmark(args.benchmark)
    elif args.precompute:
        precompute()
    else:
        concurrency()
