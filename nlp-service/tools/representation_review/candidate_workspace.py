#!/usr/bin/env python3
"""Create/check restricted local Phase 8B human-editable description drafts.

Drafts are not a revised corpus or authorization. No private text is printed.
The frozen Phase 7D/8A snapshot files are never modified.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import stat
import sys
from pathlib import Path

from revision_preflight import prepare_plan

STAGE_VERSION = "PHASE8B_PRIVATE_DRAFT_V1"


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def secure_write(path: Path, content: bytes) -> None:
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
    if hasattr(os, "O_NOFOLLOW"):
        flags |= os.O_NOFOLLOW
    fd = os.open(path, flags, 0o600)
    with os.fdopen(fd, "wb") as stream:
        stream.write(content)


def safe_root(output_root: Path, phase7d: Path, phase8a: Path, review: Path) -> Path:
    """Require a new, non-repository directory outside all frozen/review paths."""
    out = output_root.expanduser().absolute()
    if out.exists() or out.is_symlink():
        raise ValueError("draft output directory already exists; do not overwrite")
    parent = out.parent.resolve(strict=True)
    for ancestor in (parent, *parent.parents):
        if (ancestor / ".git").exists():
            raise ValueError("drafts must not be created inside a Git worktree")
    for source in (phase7d, phase8a, review):
        actual = source.resolve(strict=True)
        if out.is_relative_to(actual) or actual.is_relative_to(out):
            raise ValueError("draft directory overlaps existing frozen/review sources")
    if out.parent != parent:
        raise ValueError("draft parent must not be a symlink")
    return out


def load_review_snapshot(path: Path, expected_sha: str, issue_id: str) -> dict:
    if path.is_symlink() or not path.is_file():
        raise ValueError(f"{issue_id}: frozen snapshot missing or symlinked")
    data = path.read_bytes()
    if digest(data) != expected_sha:
        raise ValueError(f"{issue_id}: frozen snapshot digest mismatch")
    snap = json.loads(data)
    if snap.get("issue_id") != issue_id:
        raise ValueError(f"{issue_id}: snapshot issue identity mismatch")
    if snap.get("representation") != "MINIMIZED_TITLE_NEWLINE_DESCRIPTION":
        raise ValueError(f"{issue_id}: unexpected snapshot representation")
    if not isinstance(snap.get("title"), str) or not isinstance(snap.get("description"), str):
        raise ValueError(f"{issue_id}: title/description must be strings")
    if snap.get("inference_authorized") is not False:
        raise ValueError(f"{issue_id}: authorization state must remain false")
    if snap.get("public_redistribution") != "NOT_CLEARED":
        raise ValueError(f"{issue_id}: unexpected redistribution policy")
    return snap


def prerequisites(phase8a: Path, phase7d: Path, review: Path) -> tuple[dict, dict]:
    plan = prepare_plan(phase8a, phase7d, review, verify_snapshots=True)
    if plan["status"] != "REVISION_PREPARATION_ONLY":
        raise ValueError("frozen review preflight did not pass")
    inputs = json.loads((phase8a / "input_manifest.json").read_text(encoding="utf-8"))
    index = {row["issue_id"]: row for row in inputs["inputs"]}
    return plan, index


def init_workspace(phase8a: Path, phase7d: Path, review: Path, output_root: Path) -> dict:
    plan, inputs = prerequisites(phase8a, phase7d, review)
    out = safe_root(output_root, phase7d, phase8a, review)
    rows = []
    file_contents = {}
    candidates = plan["revision_candidates"]
    if len(candidates) != 6:
        raise ValueError("expected six human revision proposals")
    for item in candidates:
        issue_id = item["issue_id"]
        source = inputs[issue_id]
        snap = load_review_snapshot(Path(source["snapshot_path"]), source["snapshot_sha256"], issue_id)
        name = issue_id.replace("/", "__") + ".txt"
        if name in file_contents:
            raise ValueError("duplicate draft filename")
        file_contents[name] = snap["description"].encode("utf-8")
        rows.append({
            "issue_id": issue_id,
            "draft_path": "drafts/" + name,
            "source_snapshot_sha256": item["source_snapshot_sha256"],
            "review_proposal_sha256": item["review_proposal_sha256"],
            "original_title_sha256": digest(snap["title"].encode("utf-8")),
            "original_description_sha256": digest(file_contents[name]),
            "affected_pairs": item["affected_pairs"],
        })
    metadata = {
        "format_version": STAGE_VERSION,
        "status": "DRAFT_ONLY_NOT_REVIEWED",
        "frozen_corpus_version": plan["frozen_corpus_version"],
        "frozen_input_manifest_sha256": digest((phase8a / "input_manifest.json").read_bytes()),
        "frozen_corpus_manifest_sha256": digest((phase7d / "PRIVATE_CORPUS_MANIFEST.json").read_bytes()),
        "review_csv_sha256": digest(review.read_bytes()),
        "entries": rows,
        "corpus_revised": False,
        "privacy_approved_for_new_text": False,
        "tokenization_authorized": False,
        "execution_authorized": False,
    }
    # Validate and read all sources before creating any output.
    out.mkdir(mode=0o700)
    draft_dir = out / "drafts"
    draft_dir.mkdir(mode=0o700)
    for name, content in file_contents.items():
        secure_write(draft_dir / name, content)
    secure_write(out / "STAGING_MANIFEST.json", (json.dumps(metadata, ensure_ascii=False, indent=2) + "\n").encode("utf-8"))
    return {
        "status": "DRAFTS_CREATED_FOR_HUMAN_EDIT",
        "drafts_created": len(rows),
        "output_root": str(out),
        "original_snapshots_modified": False,
        "corpus_revised": False,
        "privacy_approved_for_new_text": False,
        "execution_authorized": False,
        "instructions": "Edit only the six private drafts after checking original source evidence; do not publish them.",
    }


def verify_regular_private(path: Path) -> bytes:
    if not path.is_file() or path.is_symlink():
        raise ValueError("draft file missing or symlinked")
    if stat.S_IMODE(path.stat().st_mode) & 0o077:
        raise ValueError("draft file has excessive permissions")
    return path.read_bytes()


def check_workspace(phase8a: Path, phase7d: Path, review: Path, output_root: Path) -> dict:
    plan, inputs = prerequisites(phase8a, phase7d, review)
    out = output_root.expanduser().resolve(strict=True)
    if not out.is_dir() or output_root.expanduser().is_symlink():
        raise ValueError("invalid staging directory")
    for directory in (out, out / "drafts"):
        if not directory.is_dir() or directory.is_symlink() or stat.S_IMODE(directory.stat().st_mode) & 0o077:
            raise ValueError("staging directory has excessive permissions or is missing")
    manifest_data = verify_regular_private(out / "STAGING_MANIFEST.json")
    staged = json.loads(manifest_data)
    if staged.get("format_version") != STAGE_VERSION:
        raise ValueError("unknown staging version")
    required_false = ("corpus_revised", "privacy_approved_for_new_text", "tokenization_authorized", "execution_authorized")
    if any(staged.get(field) is not False for field in required_false):
        raise ValueError("staging contains unexpected authorization or corpus state")
    if staged.get("frozen_input_manifest_sha256") != digest((phase8a / "input_manifest.json").read_bytes()):
        raise ValueError("staged input binding mismatch")
    if staged.get("frozen_corpus_manifest_sha256") != digest((phase7d / "PRIVATE_CORPUS_MANIFEST.json").read_bytes()):
        raise ValueError("staged corpus binding mismatch")
    if staged.get("review_csv_sha256") != digest(review.read_bytes()):
        raise ValueError("staged human-review binding mismatch")
    expected = {x["issue_id"]: x for x in plan["revision_candidates"]}
    entries = staged.get("entries")
    if not isinstance(entries, list) or len(entries) != len(expected):
        raise ValueError("staged candidate count mismatch")
    seen = set()
    changed = []
    unchanged = []
    for row in entries:
        issue_id = row["issue_id"]
        if issue_id not in expected or issue_id in seen:
            raise ValueError("unexpected or duplicate staged Issue")
        seen.add(issue_id)
        original = expected[issue_id]
        if (row.get("source_snapshot_sha256") != original["source_snapshot_sha256"] or
            row.get("review_proposal_sha256") != original["review_proposal_sha256"] or
            row.get("affected_pairs") != original["affected_pairs"]):
            raise ValueError(f"{issue_id}: candidate source/review binding mismatch")
        name = issue_id.replace("/", "__") + ".txt"
        if row.get("draft_path") != "drafts/" + name:
            raise ValueError(f"{issue_id}: draft path mismatch")
        src = inputs[issue_id]
        snap = load_review_snapshot(Path(src["snapshot_path"]), src["snapshot_sha256"], issue_id)
        if (row.get("original_title_sha256") != digest(snap["title"].encode("utf-8")) or
            row.get("original_description_sha256") != digest(snap["description"].encode("utf-8"))):
            raise ValueError(f"{issue_id}: original text binding mismatch")
        data = verify_regular_private(out / "drafts" / name)
        try:
            content = data.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise ValueError(f"{issue_id}: draft is not valid UTF-8") from exc
        if not content.strip():
            raise ValueError(f"{issue_id}: empty description draft")
        result = {"issue_id": issue_id, "draft_sha256": digest(data)}
        (changed if digest(data) != row["original_description_sha256"] else unchanged).append(result)
    if set(expected) != seen:
        raise ValueError("missing staged Issue")
    actual_files = {f.name for f in (out / "drafts").iterdir()}
    required_files = {row["draft_path"].split("/")[-1] for row in entries}
    if actual_files != required_files:
        raise ValueError("unexpected files in draft directory")
    return {
        "status": "DRAFTS_EDITED_MANUAL_REVIEW_REQUIRED" if len(unchanged) == 0 else "DRAFTS_INCOMPLETE",
        "draft_count": len(entries),
        "changed_count": len(changed),
        "unchanged_count": len(unchanged),
        "changed_drafts": sorted(changed, key=lambda x: x["issue_id"]),
        "original_snapshots_modified": False,
        "corpus_revised": False,
        "privacy_approved_for_new_text": False,
        "execution_authorized": False,
        "tokenization_authorized": False,
        "next_gate": "Manual factual/technical review, new-text rights/privacy signoff, regression tests, and separately versioned corpus.",
    }


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="Local-only Stage 8B candidate description drafts")
    parser.add_argument("action", choices=("init", "check"))
    parser.add_argument("--phase8a", type=Path, required=True)
    parser.add_argument("--phase7d", type=Path, required=True)
    parser.add_argument("--review", type=Path, required=True)
    parser.add_argument("--output-root", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        fn = init_workspace if args.action == "init" else check_workspace
        response = fn(args.phase8a, args.phase7d, args.review, args.output_root)
    except (ValueError, KeyError, OSError, json.JSONDecodeError) as exc:
        print(json.dumps({"status": "CANDIDATE_WORKSPACE_FAILED", "error": str(exc), "execution_authorized": False}, indent=2), file=sys.stderr)
        return 3
    print(json.dumps(response, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
