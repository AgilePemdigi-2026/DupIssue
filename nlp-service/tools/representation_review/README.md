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
