#!/usr/bin/env python3
"""Generate a read-only, metadata-only Phase 8B representation review worklist.

No model/tokenizer, network, private snapshot, write operations or authorization.
The frozen Phase 8A representation audit is the only source of issue risk notes.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import sys
from pathlib import Path

from validate_review import (
    EXPECTED_AUDIT_SHA256, FIELDNAMES, expected, load_csv, validate,
)

NEXT_ACTION = {
    "REQUEST_REVISION": "AWAIT_SOURCED_MINIMAL_REVISION_AND_PRIVACY_REVIEW",
    "RETAIN_WITH_RISK": "DOCUMENT_RISK_IN_REVISED_PROTOCOL",
    "DEFER": "REASSESS_PAIR_MEMBERSHIP_BEFORE_REFREEZE",
}


def build_worklist(audit: list[dict], review: list[dict]) -> dict:
    """Build a human review agenda; a valid worklist is never an authorization."""
    validation = validate(review, audit)
    if validation["status"] == "INVALID_REVIEW_PACKAGE":
        raise ValueError("Invalid Phase 8B review input: " + "; ".join(validation["validation_errors"]))

    source = expected(audit)
    entries = []
    for row in review:
        a = source[row["issue_id"]]
        priority = row["priority"]
        decision = row["decision"]
        entries.append({
            "issue_id": row["issue_id"],
            "priority": priority,
            "affected_pairs": row["affected_pair_ids"].split(";"),
            "redaction_version": row["redaction_version"],
            "source_assessment": a["assessment"],
            "decision": decision,
            "next_action": NEXT_ACTION.get(
                decision,
                "REVIEW_LOST_ERROR_OR_REPRODUCTION_CONTEXT" if priority == "HIGH"
                else "REVIEW_AND_ACKNOWLEDGE_TECHNICAL_CONTEXT_LOSS",
            ),
        })
    entries.sort(key=lambda r: (0 if r["priority"] == "HIGH" else 1, r["issue_id"]))
    high = {p for r in entries if r["priority"] == "HIGH" for p in r["affected_pairs"]}
    flagged = {p for r in entries for p in r["affected_pairs"]}
    if len(entries) != 13 or len(high) != 11 or len(flagged) != 21:
        raise ValueError("Frozen Phase 8A issue/pair counts do not match")

    return {
        "phase": "8B",
        "status": validation["status"],
        "issue_count": len(entries),
        "high_priority_issues": sum(r["priority"] == "HIGH" for r in entries),
        "moderate_priority_issues": sum(r["priority"] == "MODERATE" for r in entries),
        "affected_pairs_all": len(flagged),
        "affected_pairs_high": len(high),
        "human_decisions_recorded": validation["decisions_recorded"],
        "human_decisions_pending": validation["pending"],
        "execution_authorized": False,
        "tokenization_authorized": False,
        "source_scope": "FROZEN_PHASE8A_REPRESENTATION_METADATA_ONLY",
        "instructions": (
            "Inspect only legitimately accessible minimized narratives and existing evidence locally. "
            "Do not copy private text into the public repository or this output. "
            "Do not infer missing technical facts or auto-fill human decisions. "
            "Review completion does not authorize model execution."
        ),
        "worklist": entries,
    }


def render_markdown(result: dict) -> str:
    lines = [
        "# Phase 8B — Human representation review worklist",
        "",
        f"**Status:** `{result['status']}` — **execution and tokenization NOT AUTHORIZED**.",
        "",
        f"Issues: {result['issue_count']} ({result['high_priority_issues']} HIGH, "
        f"{result['moderate_priority_issues']} MODERATE); affected pairs: "
        f"{result['affected_pairs_all']} unique ({result['affected_pairs_high']} from HIGH issues).",
        f"Human decisions: {result['human_decisions_recorded']} recorded, "
        f"{result['human_decisions_pending']} pending.",
        "",
        "Metadata only: this worklist contains no private issue text. Review evidence locally.",
        "A completed CSV is a representation review record, not experiment authorization.",
    ]
    for priority in ("HIGH", "MODERATE"):
        lines += ["", f"## {priority} priority", ""]
        for r in result["worklist"]:
            if r["priority"] != priority:
                continue
            lines += [
                f"### {r['issue_id']}",
                f"- Affected pairs: {', '.join(r['affected_pairs'])}",
                f"- Redaction version: `{r['redaction_version']}`",
                f"- Existing audit assessment: {r['source_assessment']}",
                f"- Human decision: `{r['decision']}`",
                f"- Next step: `{r['next_action']}`",
                "- Verify minimum evidence, source rights/privacy, and the correct decision in the **local** review CSV",
                "",
            ]
    lines.extend([
        "## Non-negotiable gates", "",
        "- Never infer human decisions from audit assessments or previously signed labels.",
        "- Do not restore raw SQL values, personal identifiers, paths, tokens or private attachments.",
        "- Keep existing Phase 7D snapshots and Phase 8A seal unchanged.",
        "- Corpus revision requires separate version/hashes, privacy review, refreeze, and new explicit authorization.",
    ])
    return "\n".join(lines) + "\n"


def main(argv=None) -> int:
    cli = argparse.ArgumentParser(description="Read-only Phase 8B metadata review worklist")
    cli.add_argument("--phase8a", type=Path, required=True, help="Local frozen Phase 8A folder")
    cli.add_argument("--review", type=Path, required=True, help="Local Phase 8B human decision CSV")
    cli.add_argument("--format", choices=("markdown", "json"), default="markdown")
    args = cli.parse_args(argv)
    try:
        source = args.phase8a / "REPRESENTATION_IMPACT_AUDIT.csv"
        if hashlib.sha256(source.read_bytes()).hexdigest() != EXPECTED_AUDIT_SHA256:
            raise ValueError("Frozen Phase 8A audit hash mismatch; abort")
        _, audit = load_csv(source)
        fields, review = load_csv(args.review)
        if fields != FIELDNAMES:
            raise ValueError("Review CSV headers differ from frozen template")
        result = build_worklist(audit, review)
    except (OSError, ValueError) as error:
        print(f"REVIEW_WORKLIST_INVALID: {error}", file=sys.stderr)
        return 3
    print(render_markdown(result) if args.format == "markdown" else json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
