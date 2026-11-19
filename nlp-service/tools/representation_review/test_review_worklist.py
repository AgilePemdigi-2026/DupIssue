"""Structural tests for metadata-only Phase 8B review worklists."""
import unittest

from review_worklist import build_worklist, render_markdown
import test_validate_review


class WorklistTests(unittest.TestCase):
    def setUp(self):
        fixture = test_validate_review.ReviewValidationTests()
        fixture.setUp()
        self.audit = fixture.audit
        for r in self.audit:
            r["assessment"] = "Metadata-only synthetic assessment"
        self.review = fixture.review

    def test_all_priorities_and_frozen_pair_counts(self):
        report = build_worklist(self.audit, self.review)
        self.assertEqual(13, report["issue_count"])
        self.assertEqual(5, report["high_priority_issues"])
        self.assertEqual(8, report["moderate_priority_issues"])
        self.assertEqual(11, report["affected_pairs_high"])
        self.assertEqual(21, report["affected_pairs_all"])
        self.assertEqual("REVIEW_INCOMPLETE", report["status"])

    def test_no_private_text_or_implicit_authorization(self):
        self.review[0]["reason"] = ""
        report = build_worklist(self.audit, self.review)
        self.assertFalse(report["execution_authorized"])
        self.assertFalse(report["tokenization_authorized"])
        rendered = render_markdown(report)
        self.assertIn("execution and tokenization NOT AUTHORIZED", rendered)
        self.assertNotIn("source_snapshot_sha256", rendered)

    def test_invalid_immutable_source_rejected(self):
        self.review[0]["source_snapshot_sha256"] = "tampered"
        with self.assertRaisesRegex(ValueError, "Invalid Phase 8B"):
            build_worklist(self.audit, self.review)

    def test_rejected_partial_human_decision(self):
        self.review[0]["decision"] = "REQUEST_REVISION"
        with self.assertRaises(ValueError):
            build_worklist(self.audit, self.review)

    def test_completed_review_still_not_authorized(self):
        for r in self.review:
            r.update(decision="RETAIN_WITH_RISK", evidence_reference="test-only", rights_privacy_reviewed="YES",
                     reviewer_name="SYNTHETIC TEST ONLY", reviewed_at="2026-10-31T12:00:00+07:00",
                     reason="Synthetic test review (not human approval)")
        report = build_worklist(self.audit, self.review)
        self.assertEqual("REVIEW_RECORDED_ONLY", report["status"])
        self.assertFalse(report["execution_authorized"])
        self.assertFalse(report["tokenization_authorized"])


if __name__ == "__main__":
    unittest.main()
