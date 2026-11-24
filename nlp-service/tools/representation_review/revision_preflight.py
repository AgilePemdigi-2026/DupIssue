#!/usr/bin/env python3
"""Read-only Phase 8B corpus-revision preflight, without accessing model inputs.

No revised corpus is generated, and review decisions cannot authorize inference.
The frozen Phase 7D and 8A files are verified before reporting proposed actions.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import stat
import sys
from pathlib import Path

from validate_review import EXPECTED_AUDIT_SHA256, FIELDNAMES, load_csv, validate


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def read_json(path: Path) -> tuple[dict, str]:
    content = path.read_bytes()
    return json.loads(content), sha256(content)


def check_snapshot(path_str: str, sha: str, roots: list[Path]) -> None:
    """Ensure read-only snapshot integrity and restrictive file/directory permissions."""
    path = Path(path_str)
    if path.is_symlink() or not path.is_file():
        raise ValueError("snapshot missing or symbolic link")
    resolved = path.resolve(strict=True)
    if not any(resolved.is_relative_to(root.resolve(strict=True)) for root in roots):
        raise ValueError("snapshot outside declared private roots")
    if stat.S_IMODE(resolved.stat().st_mode) & 0o077:
        raise ValueError("snapshot file permissions too permissive")
    if stat.S_IMODE(resolved.parent.stat().st_mode) & 0o077:
        raise ValueError("snapshot directory permissions too permissive")
    digest = hashlib.sha256()
    with resolved.open("rb") as stream:
        for block in iter(lambda: stream.read(65536), b""):
            digest.update(block)
    if digest.hexdigest() != sha:
        raise ValueError("snapshot SHA-256 mismatch")


def prepare_plan(
    phase8a: Path, phase7d: Path, review_file: Path,
    *, verify_snapshots: bool = False,
) -> dict:
    audit_path = phase8a / "REPRESENTATION_IMPACT_AUDIT.csv"
    audit_bytes = audit_path.read_bytes()
    if sha256(audit_bytes) != EXPECTED_AUDIT_SHA256:
        raise ValueError("frozen Phase 8A audit checksum mismatch")
    _, audit = load_csv(audit_path)
    fields, reviews = load_csv(review_file)
    if fields != FIELDNAMES:
        raise ValueError("review CSV headers do not match frozen schema")
    result = validate(reviews, audit)
    if result["status"] != "REVIEW_RECORDED_ONLY":
        raise ValueError("review must be complete, valid, and recorded before planning")

    inputs, _ = read_json(phase8a / "input_manifest.json")
    corpus, corpus_sha = read_json(phase7d / "PRIVATE_CORPUS_MANIFEST.json")
    if corpus_sha != inputs["corpus_manifest_sha256"]:
        raise ValueError("frozen corpus manifest checksum mismatch")
    if inputs["corpus_version"] != corpus["version"]:
        raise ValueError("frozen corpus version mismatch")
    if inputs["pair_count"] != 32 or inputs["snapshot_count"] != 33:
        raise ValueError("unexpected frozen dataset size")
    if corpus.get("authorization_pending") is not True or inputs.get("holdout_access") is not False:
        raise ValueError("unexpected authorization/holdout status")
    corpus_entries = {entry["issue_id"]: entry for entry in corpus["entries"]}
    input_entries = {entry["issue_id"]: entry for entry in inputs["inputs"]}
    if len(input_entries) != 33 or len(inputs["inputs"]) != 33:
        raise ValueError("expected exactly 33 unique input Issues")
    if any(row["issue_id"] not in input_entries for row in reviews):
        raise ValueError("review references an Issue outside the frozen input set")

    roots = [Path(corpus[k]) for k in ("private_root", "legacy_private_root")]
    for issue_id, source in input_entries.items():
        item = corpus_entries.get(issue_id)
        if not item or item.get("snapshot_sha256") != source["snapshot_sha256"] or item.get("snapshot_path") != source["snapshot_path"]:
            raise ValueError(f"{issue_id}: frozen snapshot binding mismatch")
        for flag in ("rights_gate_pass", "privacy_gate_pass", "corpus_integrity_pass"):
            if source.get(flag) is not True or item.get(flag) is not True:
                raise ValueError(f"{issue_id}: prerequisite {flag} not satisfied")
        if item.get("public_redistribution") != "NOT_CLEARED":
            raise ValueError(f"{issue_id}: public redistribution policy unexpectedly changed")
        if item.get("inference_authorized") is not False:
            raise ValueError(f"{issue_id}: unexpected prior inference authorization")
        if verify_snapshots:
            try:
                check_snapshot(source["snapshot_path"], source["snapshot_sha256"], roots)
            except (OSError, ValueError) as exc:
                raise ValueError(f"{issue_id}: {exc}") from exc

    decisions = {"REQUEST_REVISION": [], "RETAIN_WITH_RISK": [], "DEFER": []}
    for row in reviews:
        decision = row["decision"]
        if decision not in decisions:
            raise ValueError("unexpected review decision")
        decisions[decision].append({
            "issue_id": row["issue_id"],
            "affected_pairs": row["affected_pair_ids"].split(";"),
            "source_snapshot_sha256": row["source_snapshot_sha256"],
            "review_proposal_sha256": sha256(row["proposed_minimal_change"].encode("utf-8")),
        })
    for entries in decisions.values():
        entries.sort(key=lambda row: row["issue_id"])
    return {
        "status": "REVISION_PREPARATION_ONLY" if verify_snapshots else "SNAPSHOT_VERIFICATION_REQUIRED",
        "phase": "8B",
        "frozen_corpus_version": inputs["corpus_version"],
        "snapshot_count": len(input_entries),
        "snapshot_sha256_verified_locally": bool(verify_snapshots),
        "decision_counts": {k: len(v) for k, v in decisions.items()},
        "revision_candidates": decisions["REQUEST_REVISION"],
        "retain_with_risk": decisions["RETAIN_WITH_RISK"],
        "deferred": decisions["DEFER"],
        "corpus_revised": False,
        "privacy_approval_for_candidate_corpus": False,
        "protocol_refrozen": False,
        "execution_authorized": False,
        "tokenization_authorized": False,
        "human_experiment_authorization_issued": False,
        "next_gate": "Manual evidence-backed candidate text; scoped privacy/rights clearance; new versioned corpus/manifest, hashes and tests; new freeze/seal; fresh explicit authorization.",
        "note": "Plan includes only Issue IDs and hashes, never the private texts or human reasoning.",
    }


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="Read-only local Phase 8B corpus-revision preflight")
    parser.add_argument("--phase8a", type=Path, required=True)
    parser.add_argument("--phase7d", type=Path, required=True)
    parser.add_argument("--review", type=Path, required=True)
    parser.add_argument("--verify-snapshots", action="store_true", help="Read and verify all private snapshots without printing contents")
    args = parser.parse_args(argv)
    try:
        result = prepare_plan(args.phase8a, args.phase7d, args.review, verify_snapshots=args.verify_snapshots)
    except (ValueError, OSError, KeyError, json.JSONDecodeError) as exc:
        print(json.dumps({"status": "PREFLIGHT_FAILED", "error": str(exc), "execution_authorized": False}, indent=2), file=sys.stderr)
        return 3
    print(json.dumps(result, indent=2, ensure_ascii=False))
    return 0 if args.verify_snapshots else 2


if __name__ == "__main__":
    raise SystemExit(main())
