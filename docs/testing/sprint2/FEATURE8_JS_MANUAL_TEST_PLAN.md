# Feature 8 — QA Evidence & Reproduction Checklist (PR #39)

**Environment:** Safari on macOS; Laravel 12 at `127.0.0.1:8000`; FastAPI at `127.0.0.1:8001`; project `DupIssue Testing` (ID 1), logged-in Reporter with active membership. **Do not run destructive migrations against local MySQL.**

## Previously observed manual integration results (3 Nov 2026)

1. **Top 3 display:** With valid title/description, Safari displayed three recommendations. Network JSON showed three same-project issue records, sorted by similarity (in test data, several similarities tied), without saving a new issue.
2. **Detail modal and draft preservation:** `Lihat Detail` opened a modal showing candidate details. `Kembali ke Formulir` retained the unsent title and description. `Batal Submit dan Buka Issue` required explicit confirmation; cancel returned to the modal. Confirmed navigation opened the chosen same-project issue without submitting a new report.
3. **NLP outage:** With Uvicorn stopped, API returned HTTP 503, code `NLP_UNAVAILABLE`, empty recommendations, and safe message `Rekomendasi belum dapat dimuat`. Form remained usable.
4. **Submit during NLP outage:** Before submit, project 1 had 3 issues. One `Kirim Issue` created issue #4 `UJI SPRINT2 NLP OFF - tombol bayar gagal`, status `OPEN`, detection `PENDING`. Count became 4 (one new record).
5. **Queue recovery:** After FastAPI restarted, `curl /health` returned HTTP 200. One `queue:work database --queue=nlp --once --tries=3 -v` ran successfully. Issue #4 became `COMPLETED`; issue count stayed 4, queued NLP jobs 0, failed jobs 0.

**Evidence status:** These results were provided in the project conversation (screenshots and terminal output), **not yet uploaded to this PR**. Reviewer-accessible screenshots/videos must be attached manually or added under `docs/testing/sprint2/evidence/`. Do not interpret these notes as a new execution on the latest `main`.

## Browser behavior checklist to rerun after synchronizing with latest main

| Check | Reproduction steps | Expected | Evidence required |
|---|---|---|---|
| Debounce 500 ms | Keep Safari Network open; clear log; enter valid title/description; make 3 edits less than 500 ms apart; observe requests/timestamps | No request before final 500-ms quiet interval; one request for final draft | Network screenshot/video with timing |
| Late/stale response protection | Artificially slow first recommendation request; edit form and let newer request complete first; release old response | New recommendations remain; older results never overwrite | Network order + panel screen capture (not yet demonstrated) |
| Max Top 3 | Valid draft in project with ≥4 matching candidates | At most 3 visible cards; project isolation | Panel screenshot + JSON |
| Modal draft preserved | Open `Lihat Detail`, then `Kembali ke Formulir` | Title and description unchanged; no issue created | Before/after screenshots |
| Confirm navigation | Open modal, `Batal Submit dan Buka Issue`, `Tidak, Kembali`, then `Ya, Buka Issue` | Confirmation before navigation; no automatic submit; correct parent URL if candidate has `duplicate_of` | Modal + final URL screenshots |
| NLP down/timeout | Stop Uvicorn, issue valid recommendation request; optionally test server timeout | HTTP 503 / safe fallback; `Kirim Issue` stays enabled | Network status/response + panel |
| Submit during NLP outage | Record count, stop NLP, click submit once, record count and status | Exactly 1 new `OPEN` issue; `PENDING` until async worker | Terminal + detail screenshot |

**Unverified in previous manual evidence:** the precise 500-ms interval and controlled out-of-order completion. These must be retested. Neither Behat/Mink Laravel nor HTTP Feature Tests execute browser JavaScript. Avoid claiming browser automation PASS until evidence exists.

## Regression commands on the developer's Mac

From `backend/`, with `.env.behat` guarded for SQLite `:memory:`:

```sh
php artisan test --filter=IssueRecommendationTest
php artisan test --filter=SubmitIssueTest
php artisan test
vendor/bin/behat --config=behat.yml --suite=sprint1 --format=progress
vendor/bin/behat --config=behat.yml --suite=sprint2 --format=pretty --no-colors
node --check public/js/issue-recommendations.js
php -l app/Services/IssueRecommendationService.php
php -l routes/web.php
git diff --check origin/main...HEAD
```

**Log reporting rule:** record actual test counts for PASS, FAIL, SKIP and date/commit SHA; do not reuse the 35/289 figures after changing baseline unless rerun. Do not execute `php artisan migrate:fresh` against development MySQL.

## Scope / unchanged behavior

The recommendation endpoint only reads existing issues and requests NLP scores. It does not store `duplicate_candidates`, create an issue, change `duplicate_of`, or decide DUPLICATE. Existing async post-submit job, queue, and Maintainer review remain unchanged.
