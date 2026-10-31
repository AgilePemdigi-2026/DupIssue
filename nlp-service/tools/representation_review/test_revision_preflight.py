"""Read-only Phase 8B revision preflight structural regression tests."""
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from revision_preflight import prepare_plan, check_snapshot, sha256
import test_validate_review as _fixtures
from validate_review import FIELDNAMES


class RevisionPreflightTests(unittest.TestCase):
    def setUp(self):
        fixture = _fixtures.ReviewValidationTests()
        fixture.setUp()
        self.audit = fixture.audit
        self.review = fixture.review
        for row in self.review[:6]:
            row.update(decision="REQUEST_REVISION", proposed_minimal_change="synthetic proposal", evidence_reference="fixture", rights_privacy_reviewed="YES", reviewer_name="SYNTHETIC TEST", reviewed_at="2026-10-30T12:00:00+07:00", reason="synthetic fixture")
        for row in self.review[6:]:
            row.update(decision="RETAIN_WITH_RISK", evidence_reference="fixture", rights_privacy_reviewed="YES", reviewer_name="SYNTHETIC TEST", reviewed_at="2026-10-30T12:00:00+07:00", reason="synthetic fixture")
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.phase8a = root / "phase8a"
        self.phase7d = root / "phase7d"
        self.phase8a.mkdir()
        self.phase7d.mkdir()
        self.review_file = root / "review.csv"
        self.source_dir = root / "private"
        self.source_dir.mkdir(mode=0o700)
        self.source_file = self.source_dir / "sample.json"
        self.source_file.write_text('{"issue_id":"issue-0"}')
        self.source_file.chmod(0o600)
        hash_value = sha256(self.source_file.read_bytes())
        self.corpus = {"version":"v1", "authorization_pending":True, "private_root":str(self.source_dir), "legacy_private_root":str(self.source_dir), "entries":[]}
        self.inputs = {"corpus_version":"v1", "pair_count":32, "snapshot_count":33, "holdout_access":False, "inputs":[]}
        for i in range(33):
            ident = f"issue-{i}"
            path = str(self.source_file)
            entry = {"issue_id":ident,"snapshot_path":path,"snapshot_sha256":hash_value,"rights_gate_pass":True,"privacy_gate_pass":True,"corpus_integrity_pass":True,"inference_authorized":False,"public_redistribution":"NOT_CLEARED"}
            self.corpus["entries"].append(entry)
            self.inputs["inputs"].append({k:entry[k] for k in ("issue_id","snapshot_path","snapshot_sha256","rights_gate_pass","privacy_gate_pass","corpus_integrity_pass")})
        self.write_files()

    def write_files(self):
        import csv
        with self.review_file.open("w",newline="") as fd:
            w=csv.DictWriter(fd, fieldnames=FIELDNAMES)
            w.writeheader()
            w.writerows(self.review)
        corpus_bytes=json.dumps(self.corpus).encode()
        (self.phase7d/"PRIVATE_CORPUS_MANIFEST.json").write_bytes(corpus_bytes)
        self.inputs["corpus_manifest_sha256"]=sha256(corpus_bytes)
        (self.phase8a/"input_manifest.json").write_text(json.dumps(self.inputs))
        (self.phase8a/"REPRESENTATION_IMPACT_AUDIT.csv").write_text("synthetic audit")

    def run_plan(self, *, verify=False):
        with patch("revision_preflight.EXPECTED_AUDIT_SHA256", sha256(b"synthetic audit")), patch("revision_preflight.load_csv", side_effect=[([], self.audit), (FIELDNAMES, self.review)]):
            return prepare_plan(self.phase8a,self.phase7d,self.review_file,verify_snapshots=verify)

    def test_valid_review_is_not_approval(self):
        result=self.run_plan()
        self.assertEqual("SNAPSHOT_VERIFICATION_REQUIRED",result["status"])
        self.assertEqual(6,result["decision_counts"]["REQUEST_REVISION"])
        self.assertEqual(7,result["decision_counts"]["RETAIN_WITH_RISK"])
        self.assertFalse(result["corpus_revised"])
        self.assertFalse(result["execution_authorized"])
        self.assertNotIn("synthetic proposal",json.dumps(result))

    def test_pending_review_blocked(self):
        self.review[0].update(decision="PENDING", proposed_minimal_change="", evidence_reference="", rights_privacy_reviewed="", reviewer_name="", reviewed_at="", reason="")
        with self.assertRaisesRegex(ValueError,"complete"):
            self.run_plan()

    def test_manifest_hash_tamper_blocked(self):
        self.inputs["corpus_manifest_sha256"]="a"*64
        (self.phase8a/"input_manifest.json").write_text(json.dumps(self.inputs))
        with self.assertRaisesRegex(ValueError,"corpus manifest checksum"):
            self.run_plan()

    def test_missing_snapshot_blocked(self):
        with self.assertRaisesRegex(ValueError, "snapshot missing"):
            check_snapshot(str(self.source_dir/"missing.json"),"0"*64,[self.source_dir])

    def test_world_readable_snapshot_blocked(self):
        self.source_file.chmod(0o644)
        with self.assertRaisesRegex(ValueError, "permissions"):
            check_snapshot(str(self.source_file),self.inputs["inputs"][0]["snapshot_sha256"],[self.source_dir])

    def test_snapshot_hash_tamper_blocked(self):
        with self.assertRaisesRegex(ValueError,"SHA-256 mismatch"):
            check_snapshot(str(self.source_file),"b"*64,[self.source_dir])

    def test_snapshot_file_and_directory_permissions_success(self):
        check_snapshot(str(self.source_file),self.inputs["inputs"][0]["snapshot_sha256"],[self.source_dir])


if __name__=="__main__":
    unittest.main()
