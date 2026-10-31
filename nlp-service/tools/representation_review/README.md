# Phase 8B representation-review tools (metadata only)

These tools are intentionally separate from the private NLP evaluation corpus. They do **not** accept or print raw Issue descriptions, execute the tokenizer/model, reconstruct missing evidence, set authorization flags, or modify frozen data.

## Scope

- `validate_review.py` checks the 13-row human decision CSV against the frozen Phase 8A audit hash and rejects missing or tampered static fields.
- `review_worklist.py` shows a **read-only** prioritized list (five HIGH / eight MODERATE Issues; 21 unique affected pairs), using only the frozen audit's operational assessment and current human decision status.
- No existing representation or label is accepted merely because the worklist or tests pass.

## Use from your local workspace

From `~/Developer/dupissue-kelompok4`, if the evaluation workspace is a sibling directory:

```bash
python3 -B nlp-service/tools/representation_review/review_worklist.py \
  --phase8a ../dupissue-nlp-evaluation/nlp-service/evaluation/phase8a-20261031 \
  --review ../dupissue-nlp-evaluation/nlp-service/evaluation/phase8b-review-20261031/REPRESENTATION_REVIEW.csv
```

For machine-readable output use `--format json`. This prints a status **but does not save anything**. Do not redirect private review notes into the public repository. Exit code 3 means invalid/mismatched metadata; `REVIEW_INCOMPLETE` is an expected substantive status, not permission to proceed.

To validate review fields use:

```bash
python3 -B nlp-service/tools/representation_review/validate_review.py \
  --phase8a ../dupissue-nlp-evaluation/nlp-service/evaluation/phase8a-20261031 \
  --review ../dupissue-nlp-evaluation/nlp-service/evaluation/phase8b-review-20261031/REPRESENTATION_REVIEW.csv
```

Validation returns exit code 2 while decisions are pending. All 13 real decisions must be made by the responsible human reviewer after inspecting authorized evidence **locally**. Keep source descriptions, personal identifiers, credentials, original SQL values, and private datasets off public GitHub.

## Testing

```bash
python3 -B -m unittest discover -s nlp-service/tools/representation_review -p 'test_*.py' -v
```

These checks are structural tests. They are not evidence of model accuracy, correctness of human judgments, rights clearance, or experiment authorization. Phase 8A remains frozen; authorization, tokenization, and inference remain blocked. Any later revision requires new private corpus version, integrity/privacy review, refrozen protocol/seal, and fresh explicit authorization.

## Controlled corpus-revision preflight (after all human decisions are recorded)

`revision_preflight.py` provides a **read-only, fail-closed** follow-up. It binds the completed local review CSV to the exact Phase 8A audit, checks the Phase 8A input manifest against the frozen Phase 7D corpus manifest, and optionally verifies all 33 private snapshots on disk (hash, file and directory permissions, and declared roots). It prints issue IDs, decision counts and cryptographic hashes **without any issue text**.

From `~/Developer/dupissue-kelompok4`, after pulling the latest branch:

```bash
python3 -B nlp-service/tools/representation_review/revision_preflight.py \
  --phase8a ../dupissue-nlp-evaluation/nlp-service/evaluation/phase8a-20261031 \
  --phase7d ../dupissue-nlp-evaluation/nlp-service/evaluation/phase7d-20261031 \
  --review ../dupissue-nlp-evaluation/nlp-service/evaluation/phase8b-review-20261031/REPRESENTATION_REVIEW.csv \
  --verify-snapshots
```

Exit code `0` indicates a successful **preparation check only**. Without `--verify-snapshots`, exit code `2` intentionally signals `SNAPSHOT_VERIFICATION_REQUIRED`; failed bindings or snapshot checks exit `3`. No corpus revision is produced, no private file is written, and all authorization fields stay `false`.

The six `REQUEST_REVISION` items still require manually reviewed, evidence-supported minimized candidate text, with a separate rights/privacy determination for the **new** representation; the seven `RETAIN_WITH_RISK` items require limitations to be preserved in the next protocol. Do not treat `rights_privacy_reviewed=YES` in the CSV as a global rights clearance or as approval to run tokenization or inference. Candidate creation, hashing, versioned manifests, regression tests, refreeze and specific human experiment authorization are separate later gates.


## Private candidate description workspace (human edits, no model execution)

After the 13 review decisions and 33 snapshot checks pass, `candidate_workspace.py init` creates **exactly six human-editable `.txt` draft descriptions** copied from the already-minimized, hash-verified private snapshots, plus a staging manifest binding the drafts to the frozen inputs and reviewed proposals. It does not fetch raw Issues, invent missing text, add new evidence, or mark any corpus revision/privacy approval as complete.

**Run only from the public-code workspace, with the output outside all Git repositories and frozen datasets:**

```bash
cd ~/Developer/dupissue-kelompok4
python3 -B nlp-service/tools/representation_review/candidate_workspace.py init \
  --phase8a ../dupissue-nlp-evaluation/nlp-service/evaluation/phase8a-20261031 \
  --phase7d ../dupissue-nlp-evaluation/nlp-service/evaluation/phase7d-20261031 \
  --review ../dupissue-nlp-evaluation/nlp-service/evaluation/phase8b-review-20261031/REPRESENTATION_REVIEW.csv \
  --output-root "$HOME/Library/Application Support/DupIssueResearch/phase8b-private-drafts-v1-20261031"
```

The new directory and draft files use private filesystem permissions (`0700` for directories, `0600` for files). `init` **fails instead of overwriting an existing workspace**. It copies only the already-minimized **description**, and it **does not automatically apply** the proposed changes. The human reviewer must compare each draft with its authorized evidence and edit only the six private text files under `drafts/`. Never copy those drafts into Git.

After editing:

```bash
python3 -B nlp-service/tools/representation_review/candidate_workspace.py check \
  --phase8a ../dupissue-nlp-evaluation/nlp-service/evaluation/phase8a-20261031 \
  --phase7d ../dupissue-nlp-evaluation/nlp-service/evaluation/phase7d-20261031 \
  --review ../dupissue-nlp-evaluation/nlp-service/evaluation/phase8b-review-20261031/REPRESENTATION_REVIEW.csv \
  --output-root "$HOME/Library/Application Support/DupIssueResearch/phase8b-private-drafts-v1-20261031"
```

`check` verifies bindings and changed/unchanged content **without printing private text**. Even if six drafts changed, `DRAFTS_EDITED_MANUAL_REVIEW_REQUIRED` is **not** a finding of correctness, privacy clearance, completion of a corpus revision, or experiment authorization. A separate human evidence review, new-text rights/privacy clearance, versioned corpus and manifest, independent regression checks, new protocol/seal, and explicit experiment authorization are required before tokenization/inference.
