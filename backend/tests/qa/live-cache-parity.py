"""Offline actual-model parity with multilingual, ties and incremental inputs."""
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / "nlp-service"))
import main
from embedding_cache import EmbeddingCache
from sentence_transformers import SentenceTransformer, util

model = SentenceTransformer(main.MODEL_NAME, revision=main.MODEL_REVISION, device="cpu", local_files_only=True)
cache = EmbeddingCache()
pairs = [
    ("Checkout payment failure", "The checkout payment button sometimes fails for an account."),
    ("Checkout button does not respond", "Clicking the checkout button does not complete the payment."),
    ("Pembayaran gagal", "Tombol pembayaran tidak merespons saat melakukan checkout."),
    ("Échec du paiement", "Le bouton de paiement ne répond pas."),
    ("支払いエラー", "購入時に支払いボタンを押しても反応しません。"),
    ("Dark mode", "Add a dark color theme for the settings screen."),
    ("Export failure", "CSV export fails for a large dataset."),
    ("Login error", "Invalid password errors appear for valid credentials."),
    ("Checkout payment failure", "The checkout payment button sometimes fails for an account."),
    ("Long issue", "Long description " * 180),
]
issues = [main.NewIssue(issue_id=i, title=t, description=d) for i, (t, d) in enumerate(pairs)]
results = []
for phase in ["cold", "warm", "changed_query", "changed_candidate", "new_revision", "new_project"]:
    if phase == "changed_query":
        issues[0].description = "Payment processing fails after pressing the checkout button twice."
    if phase == "changed_candidate":
        issues[2].description = "Tombol checkout tidak memproses pembayaran kartu kredit."
    version = main.MODEL_IDENTITY + (":qa-new-revision" if phase == "new_revision" else "")
    project = 2 if phase == "new_project" else 1
    baseline = model.encode([main.combine_issue_text(i) for i in issues], convert_to_tensor=True, show_progress_bar=False)
    actual = cache.encode(model, version, project, issues, main.combine_issue_text)
    expected_scores = util.cos_sim(baseline[0], baseline[1:])[0].tolist()
    actual_scores = util.cos_sim(actual[0], actual[1:])[0].tolist()
    error = max(abs(a - b) for a, b in zip(expected_scores, actual_scores))
    expected_rank = sorted(range(len(expected_scores)), key=lambda i: (-expected_scores[i], i))
    actual_rank = sorted(range(len(actual_scores)), key=lambda i: (-actual_scores[i], i))
    assert error <= 1e-6, (phase, error)
    assert expected_rank == actual_rank, (phase, expected_rank, actual_rank)
    assert [s >= .75 for s in expected_scores] == [s >= .75 for s in actual_scores]
    results.append({"phase": phase, "max_absolute_score_error": error, "full_ranking_equal": True,
                    "csv_threshold_decisions_equal": True, "cache": cache.stats()})
(ROOT / ".qa/f2-live-parity.json").write_text(json.dumps(results, indent=2))
print(json.dumps(results, indent=2))
