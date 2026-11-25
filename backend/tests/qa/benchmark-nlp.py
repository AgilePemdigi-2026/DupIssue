"""Bounded real HTTP benchmark; no production hosts or model downloads."""
import json
import os
import statistics
import subprocess
import threading
import time
from pathlib import Path

import httpx

ROOT = Path(__file__).resolve().parents[3]
PID = int(os.environ["QA_NLP_PID"])
results = []
with httpx.Client(base_url="http://127.0.0.1:18765", timeout=120, trust_env=False) as client:
    assert client.get("/health").json()["status"] == "ok"
    for size in [10, 100, 1000, 5000]:
        payload = {"project_id": 1, "new_issue": {"issue_id": 1, "title": "Checkout payment failure", "description": "The checkout payment button sometimes fails for an account."},
                   "candidates": [{"project_id": 1, "issue_id": i, "title": f"Checkout payment failure {i}", "description": f"The checkout payment button sometimes fails for account {i}."} for i in range(2, size + 1)]}
        durations, peaks = [], []
        for iteration in range(3):
            stop = threading.Event()
            samples = []
            def sample():
                while not stop.is_set():
                    samples.append(int(subprocess.check_output(["ps", "-o", "rss=", "-p", str(PID)], text=True).strip()) / 1024)
                    stop.wait(.1)
            sampler = threading.Thread(target=sample, daemon=True)
            sampler.start()
            started = time.perf_counter()
            try:
                response = client.post("/candidates", json=payload)
                response.raise_for_status()
                assert len(response.json()["candidates"]) == size - 1
                durations.append(time.perf_counter() - started)
            finally:
                stop.set()
                sampler.join()
            peaks.append(max(samples))
        result = {"project_issues": size, "requests_completed": 3, "seconds": durations, "median_seconds": statistics.median(durations),
                  "max_seconds": max(durations), "sampled_peak_server_rss_mib": max(peaks), "sequential_requests_per_minute": 180 / sum(durations)}
        results.append(result)
        print(json.dumps(result), flush=True)
        (ROOT / ".qa/live-nlp-latency.json").write_text(json.dumps(results, indent=2))
