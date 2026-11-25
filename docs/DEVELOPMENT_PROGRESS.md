# DupIssue development checkpoint

## Final local QA checkpoint — 26 November 2026

### Final restoration and cleanup status

The user confirmed restoration of the known development
GitHub App settings, including:

- Original homepage, callback URLs and setup URL.
- Additional QA callback removed.
- Webhook disabled and Issues event subscription removed.
- Original read-only permissions retained.
- Repository access restricted to the original testing repository.

Restoration is based on user confirmation, not a separate API audit.
Exact original webhook secret and SSL settings were not established.

All dedicated QA service listeners have been stopped or verified inactive.
Private QA artifacts and database evidence remain preserved.

The local integration database retains its final tested connection
to the second repository as historical QA evidence.
That repository is no longer authorized by the development GitHub App.

No new staging, commit, push, PR, merge or deployment performed.



This checkpoint supersedes earlier status statements. Previous sections remain as historical evidence.

### Final implementation contract

- Native Chromium side panel, minimum Chrome version 116.
- Reporter-only extension authentication with revocable sessions.
- Explicit consent before transmitting GitHub draft content.
- Extension: maximum three distinct candidates at raw similarity >= 0.75.
- Website recommendation behavior remains unchanged.
- CSV detection: maximum three candidates at >= 0.75.
- FORM and GITHUB detection: maximum ten candidates at >= 0.75.
- Shared authoritative Vote and Unvote behavior.
- No automatic duplicate status changes.
- Navigation and tab switching reset consent and private results.
- SPA sender exception limited to the same repository Issues index.
- Active tab must still be a genuine GitHub New Issue route.
- Raw-body webhook HMAC validation and durable delivery deduplication.
- Stable GitHub repository identity and queued issue import.
- Only supported opened-issue synchronization is in scope.
- No reverse synchronization or automatic GitHub issue updates.
- Repository replacement preserves historical data.
- Cancellation does not change the active repository.
- Manual PAT functionality retired while legacy history is preserved.

| Validation | Final result |
| --- | --- |
| PHPUnit | 627 tests, 625 passed, 2 skipped |
| PHPUnit assertions | 5261 |
| Website JavaScript | 17/17 PASS |
| Extension JavaScript | 48/48 PASS |
| NLP unit and cache | 29/29 PASS |
| Webhook gateway | 14/14 PASS |
| Integration readiness | 13/13 PASS, including parent test |
| Live Submit Issue | 1 test, 21 assertions PASS |
| Live recommendations | 3 tests, 105 assertions PASS |
| Hardened database queue | 1 test, 44 assertions PASS |
| Outbound security | 6 tests, 75 assertions PASS |
| Actual Chrome and Edge | Tested scenarios PASS |
| MySQL concurrency | 9 checks, prior evidence |
| Launcher lifecycle | Prior evidence |
| Automated browser fixture | 7 scenarios, prior evidence |
| PHP syntax | 57 files PASS |
| JavaScript syntax | 25 files PASS |
| Docker and remote CI | Not executed |

The live Submit Issue test passed separately.
The other default PHPUnit skip applies only before NLP job integration.
Overlapping test suites must not be summed as independent coverage.

### Actual integration evidence

- Actual GitHub App authentication and OAuth repository connection passed.
- Two actual GitHub issues were automatically imported and processed.
- Webhook redelivery did not create duplicate records or jobs.
- Repository replacement preserved historical issues and candidates.
- Cancellation preserved the active connection.
- The old repository webhook was rejected after replacement.
- The newly connected repository webhook was accepted.
- Chrome and Edge native side panel recommendations passed.
- Actual Vote and Unvote passed.
- Edge vote persistence and removal were verified in SQLite.
- Repository switching cleared private results and required reactivation.
- SPA sender compatibility fix passed automated and actual browser tests.

### Final actual QA database

| Table | Count |
| --- | ---: |
| Issues | 8 |
| Completed detections | 8 |
| Duplicate candidates | 19 |
| Votes | 0 |
| GitHub connections | 1 |
| Webhook deliveries | 2 |
| Webhook tasks | 2 |
| Pending jobs | 0 |
| Failed jobs | 0 |

Eight monitored table fingerprints remained unchanged during final regression.
The synthetic readiness fixture used fake GitHub HTTP and real local NLP.

### Latest local runtime status

| Service | Status |
| --- | --- |
| Laravel QA 18080 | Stopped |
| Webhook gateway 18081 | Stopped |
| Tunnel metrics 18082 | Inactive |
| Queue worker | Stopped |
| Scheduler | Stopped |
| Offline NLP 18765 | Stopped |
| Actual integration database | Preserved |

Use only the isolated enhancement worktree.
Do not initialize an existing QA database.
Verify process ownership before restarting or stopping services.

Local extension build command:

    node browser-extension/tools/build-local.mjs --qa

Load the generated .local-extension directory in Chrome or Edge.
Actual network mode requires explicit authorization.
Never commit credentials, raw webhook payloads, database files,
browser profiles or private QA artifacts.

### Remaining limitations and approval gates

- REQ-NF-02 and REQ-NF-05 remain partially verified.
- Cold-cache response does not meet the 3-second SRS target.
- Full 5000-job backlog completion is not established.
- Twenty-user sustained load and production SLA remain unverified.
- Docker and remote CI have not been executed.
- Actual replacement across separate GitHub installations was not tested.
- Actual Maintainer-specific duplicate review remains unverified.
- NLP optimization requires Technical Lead approval.
- Shared voting changes require the relevant owner review.
- PAT retirement and replacement confirmation require scope approval.
- Known development GitHub App settings restored by user confirmation.
- No new staging, commit, push, PR, merge or deployment authorized.

---

## F5/F6 actual execution — public gateway PASS, credential gate (latest)

24November2026: approved official Cloudflare2026.11.2 ARM64 download into ignored QA;
archive/executable checksums and macOS Cloudflare signature verified. Added bounded
30-minute tunnel wrapper with fixed gateway18081, isolated config/env, no raw logs.
Manual shutdown verified wrapper AND child exit; then restarted current session:
https://qa-tunnel.invalid/api/github/webhook
expires **24November2026 23:49:33WIB**. Temporary endpoint, not deployment.

Gateway corrected to8s total including headers/body/upstream,5sbody sublimit,
100msforced-close grace. Gateway14/14, combinedNode71/71 passed (7.9805s).
Public synthetic fixture14/14 checks passed (invalidHMAC401,ignoredping202,unconnected403,
path404/GET405);0durable deliveries/imports/jobs. Main QA backend restored; final
hostname10/10probes passed restrictions and intentional503NOT_CONFIGURED.
**Public transport/security PASS; actual GitHub App, OAuth, import and real DOM NOT PASS.**

Local services restarted GitHub-disabled; original offline model smoke200/similarity1;
6COMPLETEDsyntheticissues,0connections/jobs/failed.8000untouched; QA build refreshed.
Prior PHP614/5100/2skips,NLP29,MySQL9,Chrome7fixture evidence preserved, not new full runs.
Final full regression after actual integration remains pending. F2cold≈10.49s/TLreview
unchanged. No NLP architecture modifications.

Private rollback template prepared without reading actual settings. Specific existing
credential/settings steps in [LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md). User must confirm App
availability/local credentials/activation permission; then actual OAuth proceeds.
No credential read/use, App edit, actual issue, personal browser access or Git writes.
Git19modified/84untracked/0staged, branch/HEAD unchanged; disk6.8GiB. Earlier runtime
and local-only entries below are historical, superseded by this checkpoint.

## F5/F6 — dedicated integration QA implemented, 2026-11-24, latest state

**PASS_LOCAL environment/gateway readiness. Actual GitHub integration remains
PENDING external approval; no development credential or external write used.**
User authorized local implementation/testing only. Existing local-app launcher,
business logic, production extension config and experimental NLP cache unchanged.

Created dedicated integration launcher, private Environment bootstrap, exact outbound
policy, blank credential config template, webhook-only Node gateway, gateway tests,
launcher tests, synthetic-only HTTP fixture, readiness suite and PHP policy tests.
All10source additions are QA-only under `backend/tests`; list/commands/security details
in [LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md). No new production dependency or migration.
Git/Docker ignore already exclude private `.qa` and local build/test runtime artifacts.

Integration DB/storage/sessions/key/logs/queue separate from fixture and real DBs;
6synthetic issues completed by real offline NLP,0GitHubconnections,0jobs/failed_jobs.
GitHub disabled by default; explicit future `QA_GITHUB_NETWORK=1` plus validated
private config required. No fake verified mapping in main integration DB. Fake
OAuth creates test mappings only in separate `.qa/integration-tests/<random>` DBs.
Generated test-only signing keys are not development App credentials.

Gateway127.0.0.1:18081 forwards only exact POST webhook to127.0.0.1:18080;1MiBbody,
10sbody/8supstream deadlines,16in-flight/32connections, raw bytes preserved,
required GitHub headers only, backend HMAC verification, sanitized safe response.
No public ingress/request logs. Launcher suppresses native access logs and strips
app log messages/context to protect OAuth values. Same-process exec prevents orphan
server shutdown; recovery stays inside guarded bootstrap with cadence/DB locks.

Final actual regression: **PHP614tests/612passed/5100assertions/2existing skips,
0failures/errors (16.688s); Node69/69 including2parent tests =38existing JS +12gateway
+5launcher scenarios +12readiness scenarios +2parents; NLP29/29.**
Live submit1test/21assertions; live web/extension parity subset1test/6assertions.
Prior large-dataset F2, MySQL9 and Chrome7fixture evidence retained, not rerun.
Readiness uses real SQLite/CSRF/session/HTTP/gateway/worker/NLP, but **GitHub HTTP fake**.
Verified signed delivery,invalid signature,ping,dedup,manual preview,6stale-job
recovery,auth/project mapping,vote/unvote/disconnect,configuration guards and isolation.
Fixture and main integration DB checksums unchanged by readiness test flow.

Local services left running: Laravel18080,gateway18081,NLP18765,worker/scheduler
(latter two bounded2hours). Other-worktree PHP8000 untouched. QA extension rebuilt
with `--qa`; API fixed18080. Initial testing corrected empty-ping/preview expectations
and server shutdown handling; final run has no failure/cancellation.

Cloudflare Quick Tunnel recommended for a short approved session: no account/domain,
temporary hostname, gateway-only forwarding; cloudflared/ngrok/devtunnel absent from
PATH, none installed. Provider/hostname/data transit, credential use, App settings,
account access and synthetic issue/redelivery await explicit approval. Actual
replacement still requires a second approved testing repository. See guide for
source-derived settings and Cloudflare/ngrok comparison.

Updated4QA docs. Branch/HEAD unchanged; final Git19modified/83untracked/0staged.
Disk7.9→6.9GiB available, no deletion/model download. No real DB access, public
tunnel, actual GitHub API/settings/issues, stage/commit/push/PR/merge/deploy.
F2 cold≈10.49s/TTL900s/eviction/restart/backlog limitations and Technical Lead review
remain unresolved. **Stop before first credential activation/public/external action.**

## F5/F6 — preparation audit, previous checkpoint

**Source audit and preparation documentation complete; external integration NOT
EXECUTED.** User accepts F2 as a local cache experiment, not full SRS performance
acceptance. No further NLP architecture/persistent storage changes. Previous work
and accepted test results retained; only three QA documents updated this checkpoint.

Read-only runtime check: disk7.9GiB free; same branch
`fix/github-app-repository-management`, HEAD
`b62f83bef6746771d8c30745308162d1a0a52607`; Git19modified/73untracked/0staged.
Laravel18080/FastAPI18765 are currently stopped; unrelated PHP8000 untouched.
Existing guarded SQLite status: issues9/completed9/jobs0/failed_jobs0. Database and
account file remain0600; passwords not inspected. Chrome for Testing binary and
local extension build remain available, API origin/permission restricted to18080.
Standard Edge app path absent; no installation performed.

Audited OAuth/PKCE/setup, installation/user/repository verification, replacement
snapshot locks, signed webhook/durable dispatch/API refetch/shared import/NLP,
stable repository identity, extension mapping/recommendation/shared voting, queues
and recovery. Source requires Issues permission exactly `read`; configure separate
Setup URL and keep OAuth-during-installation / Redirect-on-update OFF for this flow.
Actual App settings/installation/credential validity were not inspected via account.

[LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md) contains exact source-derived routes/env names,
restart commands, Chrome/Edge real-DOM checks, signed webhook evidence ledger,
approval checklist and isolated integration-mode design. Keep fixture launcher
unchanged. Separate integration DB/bootstrap with narrow outbound allowlist and a
webhook-only HTTPS gateway are **designed, not implemented/provisioned/validated**.
Reuse18080 mutually exclusively; public gateway proposed18081. Public hostname is
pending approval. Callback/setup remain browser loopback, not public application.

Actual target remains only App `dupissue-kel4-dev-valentinokevin` and repository
`valentinokevin/dupissue-testing`. Real replacement test blocked until another test
repository is explicitly approved. Current GitHub DOM/templates and Edge remain
untested; Chrome7 accepted evidence uses fixture HTML. No new regression run for
documentation-only changes; no actual webhook/browser PASS claim.

F2 cold≈10.49s still exceeds3s target/default10s timeout. TTL900s, bounded eviction,
restart and per-process cache can return cold behavior; backlog5000jobs/20users
remain uncertified. Joshua/Technical Lead review of shared NLP remains pending.
No cleanup, credentials/account access, tunnel, setting change, issue creation,
production DB action, stage/commit/push/PR/merge/deploy. **Checkpoint: obtain scoped
external approvals and finish locally tested integration bootstrap/gateway before
activating credentials or public ingress.** Earlier runtime statements are history.

## F2 — NLP PERFORMANCE OPTIMIZATION, 2026-11-24, previous checkpoint

User accepted live NLP/real queue/Chrome results within their tested scope and
authorized a local experimental optimization of shared NLP. Same isolated worktree,
branch `fix/github-app-repository-management`, HEAD
`b62f83bef6746771d8c30745308162d1a0a52607`; prior changes preserved.

Measured root cause: five5,000issue baseline requests spend mean10.8574s encoding
versus0.000913s vectorized cosine. Added bounded process-local embedding LRU with
model/revision/project/issue/content hashing,10,000entry/32MiB limits,900sTTL,
independent vector storage and single-flight inference with2s bounded wait.
Pinned the existing model snapshot; no model/precision/ranking/threshold/API change.
PHP business logic, parent resolution, CSV75%/Top3 and human review unchanged.

HTTP medians before→cold/warm/incremental (5runs each):
10issues0.03035→0.02485/0.00227/0.01568s;
100issues0.21729→0.23455/0.00548/0.02926s;
1,000issues2.25421→2.09018/0.01293/0.02244s;
5,000issues10.61953→10.49264/0.04903/0.06224s.
Actual worker5,000 median10.87972→10.68846/0.43380/0.45327s.
20baseline+60optimized bounded real deliveries, not5,000jobs. Cache payload7.324MiB
at5,000; accounted12.207MiB. Cold RSS peak533.63MiB; no memory-neutral claim.

Cold/warm benchmark scores identical; original-model multilingual/incremental/
prewarm max error1.79e−7 within1e−6; ranking/threshold decisions preserved in fixtures.
Actual concurrency/recovery: cold200, competitor503 after2.028s, four warm200
in0.135–0.173s. Isolated prewarming20chunks costs11.03–12.22s, then full request
0.050–0.229s. Production prewarm scheduler/index not installed.

**Performance acceptance still PARTIAL:** cold fails3s and default10s timeout.
Restart/expiry/eviction, contention and20-user/backlog acceptance remain unresolved.
Warm Laravel web/extension at5,000existing candidates, default10s timeout:
median0.2215/0.2232s; parity and content modification/deletion checks pass.

Final actual tests: PHPUnit610/608passed/5,065assertions/2existing skips;
JavaScript38/38; NLP29/29; live submit1/21; live recommendation3/56;
worker benchmark baseline1/180 and optimized1/540. No failures/errors.
Prior Chrome7/MySQL9 preserved, not rerun. PHP/JS production code unchanged here.
Full evidence/file list/reproduction: [NLP_PERFORMANCE_REPORT](NLP_PERFORMANCE_REPORT.md).

Final Git19modified/73untracked/0staged; disk8.0→6.9GiB available. No files deleted,
model downloads, actual DB changes, external credentials/tunnels/GitHub writes,
commit/stage/push/PR/merge/deploy. Diagnostics18766 stopped; normal optimized
FastAPI18765 is loopback-only. **Pending Joshua/Technical Lead review of shared NLP,
cache policy/overload/precision and cold-latency follow-up. Stop before actual GitHub
App integration requiring external approval.** Earlier entries below are historical.

## FINAL QA COMPLETION — 2026-11-24, previous checkpoint

User accepted E5–E11 local implementation and authorized final QA after manual disk
cleanup. Disk recheck: 9.4GiB available at start. Same isolated worktree, branch and
HEAD; all previous modified/untracked files preserved. No files/environments/cache
deleted, no production credentials, no actual GitHub writes or public tunnel.

### F1 — PASS_LIVE_INTEGRATION

Python3.12.6 in `.qa/nlp-venv`, original multilingual MiniLM snapshot
`e8f8c211226b894fcb81acc59f3b34ba3efd5f42`, safetensors hash verified. Loaded from
existing shared cache with HF_HUB_OFFLINE/TRANSFORMERS_OFFLINE and implicit tokens
disabled; no model download. CPU two threads, loopback FastAPI18765.
Smoke: load0.7619s, inference0.3330s, identical cosine1.0, unrelated0.08805,
384dimensions, process high-water RSS550.22MiB. This is not an accuracy evaluation.
Existing live queue test: **1 test /21 assertions PASS**. Opt-in live parity and
representative queue benchmark: **2 tests /26 assertions PASS**.

### F2 — measurements completed; performance acceptance FAILED/PARTIAL

Four actual NLP jobs completed, one per project size10/100/1000/5000:
0.0436/0.2729/2.3734/10.5567seconds; PHP peak48.5/48.5/56.5/90.5MiB.
Twelve additional real HTTP requests (three per size) completed. Median latency:
0.0392/0.2434/2.1265/12.5153seconds; 5000 range10.407–14.797seconds,
sampled server RSS peak317.88MiB. Machine8GiB ARM64, CPU2threads. Concurrent local
setup activity means these are observed local measurements, not controlled SLA.
Target95%≤3s at5000 not met; default backend timeout10s is below observed responses.
The opt-in benchmark used120s timeout, which MUST NOT be represented as production
configuration success. No5000-job backlog or20-user load completion claim.
Recommend separately reviewed embedding cache/incremental indexing, invalidation
and bounded concurrency; no model/threshold/production timeout change made.

### F3/F4 — evidence reviewed; new real local worker evidence

Prior isolated MySQL9/9 remains valid (no shared vote/import/schema behavior change),
not rerun merely to increase evidence. No existing MySQL database accessed.
New interactive SQLite database contains synthetic fixtures only; one queued seeded
issue completed through real FastAPI, final issues9/completed9/jobs0/failed_jobs0.
Recovery tick executes commands inside the guarded launcher; no unguarded artisan
subprocess or continuous scheduler daemon. Existing real-worker regression passes.

### F5 — PASS_LIVE_INTEGRATION for Chrome engine/local stack; actual GitHub/Edge pending

Downloaded official Chrome for Testing158.0.8127.88 ARM64 to ignored QA directory;
Playwright-core1.65.1 is QA-only. Actual unpacked MV3 load, toolbar activeTab grant,
web login/CSRF pairing, Bearer exchange, two-project mapping, real NLP Top3,
Vote/Unvote/web detail, unchanged draft/privacy, project switch and disconnect PASS.
GitHub document is routed synthetic HTML; no actual GitHub requests/account access.
No page errors. Screenshots inspected. Headed demo browser remains open for user.
Actual GitHub DOM/templates, branded Chrome and Microsoft Edge remain manual.

Real-browser testing found15.529s UI lag caused by using15s access polling to render
new results. Fixed with trusted-only local snapshot every500ms, retaining server
revalidation every15s. Added tests for no extra HTTP requests, navigation clearing,
content sender rejection and no cached-result resurrection after refresh failure.
Browser reruns displayed results in1.824s and2.355s. API origin build adds only fixed
loopback18080 via `--qa`; production permissions unchanged. Port8000 belongs to a
different worktree and was not interrupted. Existing login redirects preserved;
QA instructions explicitly reopen pairing after login.

### F6/F7/F8/F9 — preparation/documentation complete, external gates remain

LOCAL_QA_GUIDE.md contains runnable local services, synthetic accounts location,
browser steps, UX tradeoffs and exact GitHub dev-App configuration/testing checklist.
No settings/credential/tunnel/issue action authorized or executed. Docker unavailable;
no remote CI/deployment run. Existing PHP8.3/Node22 CI includes new Node regressions.
Additive migrations reviewed, historical migrations unchanged. SRS traceability
updated for live evidence and failed performance gate; Elsa/Joshua approvals pending.

### Final verification after changes

Full PHPUnit: **610 tests,608passed,5065assertions,2existing skips,0failures/errors**,
17.927s/82.50MiB. Live opt-in skip passes separately; historical applicability skip
retained. Node **38/38** (website17,extension21). Python mocked12/12 and MySQL9/9 are
prior valid evidence, not newly rerun. PHP/JS syntax, editor diagnostics and diff
checks passed. No dependency/lockfile change in application; QA tooling is ignored.
New QA files: local-app.php, benchmark-nlp.py, qa-browser.mjs, qa-demo.mjs,
LOCAL_QA_GUIDE.md. Existing source edits confined to extension local build/API origin,
trusted snapshot routing/state/UI and regression tests; existing live harness only
formatted. Latest inventory/results: FINAL_QA_REPORT.md.

Remaining gates: 5000-issue latency/timeout capacity, actual GitHub/Edge/browser DOM,
external approval/credentials/HTTPS, Docker/remote CI and team approval.
Not production-ready. Previous checkpoints below are historical records.

## Autonomous continuation — E5–F

The latest attachment accepts E4 and authorizes all safe local E5–F development
without intermediate approval. This supersedes the historical stop instructions
below. Work remains confined to this worktree, branch
`fix/github-app-repository-management`, HEAD
`b62f83bef6746771d8c30745308162d1a0a52607`. No staging, commits, external writes,
deployment, or migration against an actual database is authorized.

### E5 — PASS_LOCAL (2026-11-24)

Created `browser-extension/`: native MV3 manifest, fixed-origin API client,
strict response contracts, sender authorization, trusted-only session storage,
pairing/disconnect lifecycle, popup, icons, local build tool and installation
README. Added owner-authorized, CSRF-protected web session revocation in
ExtensionAuthController, extension_web routes, pairing view, and
ExtensionSessionRevocationTest. No dependencies added. Existing web authentication
and token lifetime remain unchanged. Session tokens never enter content messages;
late pairing tokens are revoked; failed disconnect explicitly remains unconfirmed.

Evidence: new PHP tests **3 / 17 assertions**; extension Node mocks **11/11**;
existing website JavaScript **17/17**. Baseline reconfirmed before implementation:
574 PHP tests, 4,839 assertions, two existing skips. Browser installation is still
pending F5; Node mocks do not prove Chrome/Edge installation or live GitHub DOM.
All changes remain unstaged; historical modified/untracked files preserved.

E5 full regression: **577 tests, 4,856 assertions, 2 existing skips, no failures
or errors** (575 passed). Website Node tests 17/17.

### E6 — PASS_LOCAL

Implemented extension-owned recommendation window; no private issue data enters
GitHub DOM. Explicit consent per tab/project, repository revalidation, 500ms
debounce, request cancellation/generation checks, maximum three signed cosine
results, and authoritative Vote/Unvote are implemented. Restart/navigation clears
consent. Content capture supports validated single title/body Markdown fields;
ambiguous structured issue forms fail closed. Native GitHub form is not mutated.
Polling every 15s revalidates session/project and refreshes vote counts; server
authorization still applies to every request. Browser live validation is pending.
New modules: lib/workspace.mjs, workspace.mjs; content.js/workspace.html/background
updated. Node tests currently **18/18**, including content DOM simulation and
debounce/stale-project/repository switching. Security review added read-time
membership revalidation; rerun passed 18/18 extension and 17/17 website tests.
Full PHP regression remains 577 tests / 4,856 assertions / 2 existing skips.

### E7 — PASS_LOCAL

Added raw-body HMAC receiver, API route, environment-configured webhook secret,
additive delivery/task migration, database queue dispatcher and job envelope.
Only signed `issues.opened` for verified installation/repository IDs is accepted.
1 MiB maximum, UUID deduplication, immutable body hash, per-connection snapshots,
rollback and dispatch recovery are tested. No raw issue payload is retained.
Targeted **7 tests / 36 assertions**. Full regression **584 tests / 4,892
assertions / 2 existing skips**, no failures/errors. Queue consumes `default`;
actual processing is implemented in E8. No GitHub settings changed.

### E8 — PASS_MOCKED_INTEGRATION

Shared GithubIssueImportService persistence now serves manual confirmation and
verified webhook delivery. Manual owner/fingerprint requirements are preserved.
Automatic audit uses nullable user_id with explicit system actor metadata.
Added stable repository identity/unique index via additive migration, with bounded
backfill only for currently connected repositories. Fetch uses repository-scoped
installation token, verifies API repository ID/name and issue identity, then
rechecks locked project/connection before persistence. Existing issues, vote
counts and review status are preserved. NLP dispatch follows commit.
Targeted sync + webhook + existing import/preview tests: **35 / 176 assertions**.
Initial HTTP fake overlap caused eight fixture failures; isolated HTTP factories
fixed the fixture and all security scenarios passed without weakening assertions.
Full E8 regression: **599 tests / 4,959 assertions / 2 existing skips**.

### E9 — PASS_MOCKED_INTEGRATION

Added bounded retry/backoff, processing leases, recovery command/schedule, safe
failed-job metadata, pending NLP recovery for after-commit crash gaps, and session
hash cleanup/seven-day metadata retention. Queue outbox requires the same DB
connection as jobs. Job timeout 60s remains below database retry_after 90s;
120s processing lease handles worker crashes. No new queue names.
Real SQLite database worker tests (GitHub/NLP HTTP mocked) plus retention:
**9 tests / 77 assertions**. These test queue consumption, retries, failed_jobs,
redelivery, dispatch recovery, rollback and actual NLP job completion. Full
regression **608 tests / 5,036 assertions / 2 existing skips**. MySQL concurrent
row-lock behavior remains pending F3. Scheduler must be provisioned before deploy.

### E10 — PASS_MOCKED_INTEGRATION

Added full login → pairing → repository resolution → recommendation → vote → web
visibility → unvote → disconnect workflow. Combined existing replacement, legacy
PAT retirement, CSV, duplicate review and voting integration suite:
**133 tests / 1,877 assertions**, no failures/errors. Full regression passed:
609 tests / 5,062 assertions / two existing skips.
No browser/live GitHub claim: GitHub/NLP HTTP remains fake in this checkpoint.

### E11 — PASS_LOCAL

Auditing tracked/untracked source, migrations, CI, Docker context and dependency
requirements. Extension tests added to existing PHP8.3/Node22 CI. Excluded extension,
local build, QA artifacts and docs from production Docker build context; ignored
local build/QA outputs in Git. Added GitHub App environment names (no secrets) to
Compose and webhook secret name to example config. Updated obsolete PAT wording
in shared GitHub API client and its test. No dependency or historical migration
changed. Local commit preparation is complete; actual commit remains unauthorized.
No staging/commit/push/deployment/external writes performed.

E10/E11 full regression after formatting/configuration changes: **609 tests /
5,062 assertions / 2 existing skips / 0 failures/errors**. Combined JavaScript
35/35 (17 website, 18 extension). PHP/JS/Python syntax and git diff --check passed.
Six workflow/Compose YAML documents parsed successfully. PHP source formatted
using existing Pint. Chrome/Edge executables are not installed at standard paths;
Docker executable unavailable. Deployment/build/browser installation remain pending.

### Final F checkpoints (2026-11-24)

- F1 **BLOCKED**: created ignored `.qa/nlp-venv`, explicitly configured Python
  3.12.6, installed exact existing NLP requirements from PyPI. The environment tool
  initially reported installation success incorrectly; direct interpreter import
  checks caught this, and explicit venv pip installation completed successfully.
  Model startup uses loopback port 18765, CPU threads bounded to two, workspace
  Hugging Face cache and no implicit saved token. No model substitution.
  Original model download requires about 471MB; available disk fell below that
  requirement (final check approximately 357MiB). Stopped the QA Uvicorn process.
  Live model tests were NOT run. Existing NLP unit tests: **12/12**, using model
  mocks. Need user-managed disk space before resuming original-model validation.
- F2 **BLOCKED** for actual NLP performance. Existing CSV insertion test proves
  5,000 issues and 5,000 database jobs (one observed run 1.595s), not inference
  completion. Added opt-in `backend/tests/qa/LiveRecommendationTest.php` for actual
  web/extension parity and bounded 10/100/1000/5000-project-size measurements;
  syntax checked, not executed. No throughput/latency percentile claim.
- F3 PASS_LIVE_INTEGRATION (local MySQL): installed MySQL **8.0.46** used in a
  new workspace-only datadir, `--no-defaults --skip-networking --mysqlx=OFF`,
  64MiB buffer pool. Harness verifies actual server datadir before creating a
  fresh QA schema; no existing DB accessed. Nine concurrent checks passed:
  vote idempotence/count/audit, unvote, duplicate delivery, manual/webhook shared
  identity/audit/NLP dispatch, replacement race and historical preservation.
  Harness: `backend/tests/qa/mysql-concurrency.php`. Original PHPUnit TestCase
  SQLite safety guards unchanged. Schema retained in ignored QA datadir.
  QA MySQL server shut down after testing; no existing database was accessed.
- F4 PASS_MOCKED_INTEGRATION: real database worker evidence in E9; external
  HTTP fake explicitly distinguished from actual queue storage/worker execution.
- F5 REQUIRES_USER_ACTION for real Chrome/Edge installation and GitHub DOM QA;
  local contract/DOM simulations passed. No actual browser E2E claimed.
- F6 REQUIRES_USER_ACTION: actual GitHub issue/settings/credential use requires
  explicit approval; none performed. Local mocked integration completed.
- F7 **PASS_LOCAL** for configuration review; Docker build/remote CI remain
  **BLOCKED/REQUIRES_USER_ACTION** (Docker unavailable; no push/deploy authorized).
  CI includes extension Node tests. Six YAML files parsed, Composer validation,
  syntax and diff checks passed. Scheduler/private-key mount/HTTPS configuration
  remain operator deployment prerequisites in EXTENSION_SYNC_OPERATIONS.md.
- F8 **PASS_LOCAL** for SRS review and traceability documentation. Read the actual
  109-page SRS. Corrected automatic new-import status to OPEN to preserve REQ-04.7
  and added regression for a remote issue closed after opened delivery.
  SCOPE_TRACEABILITY.md lists amendments and Elsa/Joshua approvals still required.
- F9 **PASS_LOCAL**: architecture, API/authentication, install/runbook/recovery,
  migration/rollback, actual tests, limitations, proposed staging/PR and final
  inventory documented in FINAL_QA_REPORT.md, EXTENSION_SYNC_OPERATIONS.md,
  SCOPE_TRACEABILITY.md and browser-extension/README.md.

### Final regression and handoff

Final PHP 8.3 regression: **610 tests, 608 passed, 5,065 assertions, two existing
skips, zero failures/errors** (14.995s; PHP peak 82.50MB). Baseline was 574 tests,
572 passed, 4,839 assertions; delta +36 tests / +226 assertions.
Final JavaScript: **36/36**, comprising website 17 and extension 19; includes
safe rendering/XSS regression. NLP Python unit tests: **12/12**. Real isolated
MySQL concurrency harness: **9 checks passed**, distinct from mocked HTTP tests.

Latest additions include safe oversized-draft rejection, last-workspace-close
consent reset, render test, local build verification, QA harnesses and final docs.
No runtime dependency or lockfile change; ignored QA venv/cache/data are local only.
All tracked modifications and untracked E1–E4 work remain present. Branch/HEAD
unchanged; index empty. Full inventory and stage-specific evidence are recorded
in [FINAL_QA_REPORT.md](FINAL_QA_REPORT.md).

Remaining work requires disk space, Chrome/Edge manual QA, explicitly approved
GitHub dev-App integration, Docker/remote CI availability and team approvals.
Development is ready for those validations; production readiness is NOT claimed.
Historical checkpoints below are retained as records, not active stop instructions.

## Current checkpoint — Step 49 / E4: Extension Vote API

Date: 2026-11-24. Status: **PASS — local automated integration and regression**.
The user accepted Step 48 as a local automated PASS and explicitly authorized E4.
E4 is complete locally; stop pending user approval before E5 (Manifest V3 Browser
Extension). No commit, push, merge, deployment, live GitHub action, or migration
against an actual development/production database was performed.

### Audit findings and shared-component impact

Reviewed [VoteController.php](../backend/app/Http/Controllers/VoteController.php),
[IssueVote.php](../backend/app/Models/IssueVote.php),
[votes migration](../backend/database/migrations/2026_11_03_000001_create_votes_table.php),
[activity log migration](../backend/database/migrations/2026_11_01_000001_add_duplicate_review_and_activity_logs.php),
membership and GitHub connection schemas, web issue display, existing voting tests,
duplicate review integration, repository replacement, and membership removal.

Existing behavior already included the unique `(issue_id, voter_id)` constraint,
ordered issue/parent locking, idempotent mutations, authoritative count updates,
and transactional `VOTE_ADDED` / `VOTE_REMOVED` logs. Authorization was checked
before the transaction, so it needed a locked recheck for mutation safety.

Extracted the transaction, parent resolution, and logging into
[VoteService.php](../backend/app/Services/VoteService.php). Both web and extension
use the same transaction implementation. Existing web redirect destinations,
success/info messages, Reporter restriction, project authorization, and valid-root
parent behavior are preserved. Closed children still vote on a valid parent;
closed root issues remain votable. Orphan, self, nested, duplicate-status, and
cross-project parents remain invalid. Historical child votes are not migrated or
deleted when an issue becomes a duplicate.

The service locks project, current user, and membership rows before operating.
The project-first order matches repository replacement and membership removal.
Extension guards then lock the credential and actual GitHub connection rows,
revalidate the existing repository policy, and compare stable connection,
repository, and installation IDs against the preflight snapshot. Sorted issue
and parent locks reject a changed parent rather than voting on an old target.
Locking reads of the actual votes avoid a stale InnoDB repeatable-read snapshot.

Effective changes update the cached vote count from locked vote records and write
the audit entry in the same transaction. Repeated requests do not create extra
votes or audit entries. A final extension access guard runs before commit; failure
rolls back ballot, count, and log together. Token expiry is checked after acquiring
the credential lock and again at the final guard. Transactions retain three
attempts for deadlocks/serialization failures.

This is a shared voting change relevant to Elsa and shared API routing relevant
to Joshua. The reason and impact are documented here; no external coordination
message was sent and no Technical Lead scope approval is claimed.

### API contract

All operations require `Authorization: Bearer <extension access token>` and the
existing authenticated extension rate limit (60 requests/minute, shared with
other authenticated extension endpoints). Tokens are never placed in a URL.

| Method | Endpoint | Meaning | Parameters |
| --- | --- | --- | --- |
| GET | `/api/extension/v1/votes` | Read actual voting status, without writes | Query: `repository`, `project_id`, `issue_id` |
| POST | `/api/extension/v1/votes` | Ensure the Reporter's vote exists | JSON: `repository`, `project_id`, `issue_id` |
| DELETE | `/api/extension/v1/votes` | Ensure the Reporter's vote is absent | JSON: `repository`, `project_id`, `issue_id` |

The issue must belong to the selected authorized project. All users may vote only
as the authenticated Reporter; client-supplied voter IDs, counts, or target IDs
do not control the operation. A Reporter may vote on another author's issue in
the same authorized project, consistent with web voting.

Successful response example (illustrative IDs):

```json
{
  "issue_id": 24,
  "target_issue_id": 12,
  "voted": true,
  "vote_count": 3,
  "changed": true
}
```

`issue_id` is the requested issue; `target_issue_id` is the validated root target.
`voted` is the current Reporter's actual status, not whether an insertion happened.
`changed` is false for GET and idempotent repeats. Status reflects the transaction's
read; another authorized operation can change it after the transaction completes.

Success and controller-handled error responses use `Cache-Control: no-store`.
401 responses also include `WWW-Authenticate: Bearer`. Errors: 401 invalid session;
403 membership/mapping unavailable; 404 issue unavailable in selected project;
409 invalid or changed voting target; 422 validation failure; 429 existing
middleware throttle; 503 database operation unavailable after rollback/retries.
Database errors expose neither SQL nor bindings; the log contains only the
exception class. Web session authentication alone cannot authorize the API.

### Files changed in Step 49

- Added [VoteService.php](../backend/app/Services/VoteService.php).
- Refactored [VoteController.php](../backend/app/Http/Controllers/VoteController.php)
  to delegate business logic while retaining web responses.
- Added [ExtensionVoteController.php](../backend/app/Http/Controllers/ExtensionVoteController.php).
- Extended [ExtensionAuthService.php](../backend/app/Services/ExtensionAuthService.php)
  with an optional transaction-only locking authentication mode and a post-lock
  expiration check; ordinary authentication remains the default.
- Extended [api.php](../backend/routes/api.php) with the vote/status route.
- Added [ExtensionVoteTest.php](../backend/tests/Feature/ExtensionVoteTest.php).
- Updated this progress log while preserving Step 48 history below.

No schema or historical migration was changed. Existing voting tests were not
edited, skipped, or weakened. Existing E1–E3.2 work and
[.dockerignore](../.dockerignore) are preserved.

### Actual testing results

PHPUnit 11.5.57, PHP **8.3.35**, explicitly invoked with
`/opt/homebrew/opt/php@8.3/bin/php`. All PHP runs used SQLite `:memory:` with the
same environment safeguards documented in Step 48. No backend `.env`,
`.env.testing`, or cached application configuration was present at preflight;
credential contents were not read. HTTP stray requests were prohibited in the
new suite, and every case asserts no NLP HTTP request and no queue dispatch.

| Run | Tests | Passed | Assertions | Failures | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Existing voting baseline before refactor | 21 | 21 | 107 | 0 | 0 | 0 |
| Final extension voting suite | 52 | 52 | 943 | 0 | 0 | 0 |
| Existing voting + duplicate review integration | 22 | 22 | 127 | 0 | 0 | 0 |
| Final full PHPUnit regression | 574 | 572 | 4,839 | 0 | 0 | 2 |
| JavaScript regression | 17 | 17 | Not reported | 0 | 0 | 0 |

All runs exited 0. No PHP deprecations were reported. The same two existing skips
listed in Step 48 remain unchanged. Relative to the accepted 522-test baseline,
this adds 52 executed test cases and 943 assertions (including data-provider cases).

Coverage includes successful Vote/Unvote and repeats, web/extension visibility in
both directions, exact original web messages, multiple Reporters and voter-ID
forgery, status read without writes, valid duplicate/closed-parent resolution,
invalid parents, forged projects/issues, cross-project ownership, repository
mismatch/replacement, disconnected/invalid connections, inactive/removed membership,
missing/invalid/expired/revoked tokens, role/auth_version invalidation, cache headers,
count and log consistency, and throttling.

Transaction safeguards are tested with deterministic interleavings at transaction
begin and model mutation events. These cover stale authorization, same-name
repository ID replacement, parent changes/deletion, issue deletion/project changes,
and expiration/revocation during an operation. An actual SQLite audit-insert failure
proves rollback for both Vote and Unvote, followed by a successful retry. Simulated
serialization exceptions after ballot/count writes prove successful third-attempt
recovery and bounded failure after three attempts, without extra ballots/logs.
The existing unique constraint is independently exercised with a duplicate insert.

Commands use the safe environment prefix from Step 48, with these PHPUnit targets:

```sh
# Extension-specific tests
--configuration phpunit.xml --display-skipped tests/Feature/ExtensionVoteTest.php

# All existing voting tests plus their cross-feature integration
--configuration phpunit.xml --display-skipped \
  --filter 'IssueVotingTest|test_voting_and_duplicate_review_full_cross_feature_integration'

# Full suite
--configuration phpunit.xml --display-skipped
```

JavaScript: `node --test tests/javascript/*.test.cjs`, from the backend directory.
Tracked diff whitespace checks passed. New/untracked implementation and test
files were reviewed directly; editor diagnostics reported no errors.

### Final state, limitations, and next checkpoint

Branch remains `fix/github-app-repository-management`, HEAD remains
`b62f83bef6746771d8c30745308162d1a0a52607`, and the worktree remains
`/Users/valentinokeviniversen/Developer/dupissue-github-enhancement`.
The three earlier tracked changes remain; VoteController is the fourth modified
tracked file. Earlier untracked files remain, including the updated auth service,
API routes, and this log. New untracked E4 files are VoteService,
ExtensionVoteController, and ExtensionVoteTest. Nothing is staged or committed.

SQLite in-memory does not execute MySQL row-level locks. The tests establish
application behavior, rollback, retry control, and simulated interleaving safety;
they do **not** prove real concurrent MySQL execution. Isolated MySQL tests,
browser E2E, and actual extension use remain pending in Tahap F. Project locks
intentionally serialize voting/status reads with membership removal and repository
replacement; assess contention and deadlocks under real MySQL load before release.
No actual credential was read, logged, or added to source.

Overall: A–D complete locally per prior history, E2/E3 preserved, E3.2 approved by
the user, E4 now locally validated. E5–E11 and F remain pending. Recommended next
step after explicit user approval: E5, implement the Manifest V3 extension using
the existing pairing, mapping, recommendation, and vote/status APIs.

## Step 48 — E3.2: Extension Top 3 Recommendation API

Date: 2026-11-24. Status: **PASS — local automated integration and regression**.
Historical checkpoint: paused here until the user approved E4; see Step 49 above
for the current state and next approval boundary.

### Workspace and initial inspection

- Work only in `/Users/valentinokeviniversen/Developer/dupissue-github-enhancement`.
- Branch: `fix/github-app-repository-management`.
- HEAD: `b62f83bef6746771d8c30745308162d1a0a52607` (unchanged).
- Development baseline: `fccfd3b7a5219a4232d501650239aa6809e0dff6` (provided context).
- Existing uncommitted E2/E3.1 authentication, pairing UI, repository mapping,
  routes, migration, and tests were present and preserved.
- Step 48 was not implemented at the start of this session.
- No repository-local AGENTS.md was found.
- No `.env`, `.env.testing`, or application configuration cache was present
  in the backend. Their contents were not read. Existing test bootstrap and
  TestCase guard against cached config and non-memory databases.
- No commit, push, merge, pull request, deployment, live GitHub request, or
  development/production database migration was performed.

### Source findings and implementation

The existing web recommendation controller already owns draft validation,
Reporter and active membership checks, safe NLP failure handling, and the response
contract. Its service uses the real Laravel NlpClient, selects candidates by
project, validates duplicate parents, ranks scores with deterministic tie ordering,
deduplicates targets, and returns at most three recommendations. It does not apply
the CSV 75% cutoff.

The extension endpoint is a thin adapter around that controller, following the
planned approach without duplicating or changing the shared NLP algorithm:

1. Authenticate the bearer token using existing stateless middleware.
2. Apply the existing authenticated extension limit of 60 requests per minute.
3. Validate repository syntax and an explicit positive integer project ID.
4. Resolve current repository mapping and active Reporter membership.
5. Delegate draft validation and recommendation computation to the web controller.
6. Reauthenticate the bearer token, then resolve membership and repository mapping
   again before returning the result. Discard the result if access was revoked,
   the token expired, auth_version changed, or the repository was replaced.
   This check also runs after a handled NLP failure.

Endpoint: `POST /api/extension/v1/recommendations`.
Route name: `extension.api.recommendations`.
Input: `repository`, `project_id`, `title` (5–200 characters), `description`
(10–10,000 characters).
Success envelope: `recommendations`, containing up to three entries with
`issue_id`, `target_issue_id`, `title`, `description`, `status`, `similarity`,
`cosine_similarity`, and `detail_url`.

HTTP behavior: 401 invalid/revoked/expired credentials; 403 unavailable project or
mapping; 422 invalid input; 429 existing rate limit; 503 safe `NLP_UNAVAILABLE`.
Successful and handled NLP failure responses inherit `Cache-Control: no-store`
from extension authentication middleware. Reauthentication failures also use
`no-store` and `WWW-Authenticate: Bearer`.

### Files changed in this step

- Added [ExtensionRecommendationController.php](../backend/app/Http/Controllers/ExtensionRecommendationController.php).
- Extended the existing untracked [api.php](../backend/routes/api.php) with one
  controller import and one authenticated route.
- Added [ExtensionRecommendationTest.php](../backend/tests/Feature/ExtensionRecommendationTest.php)
  with 15 tests.
- Added this progress log.

The shared web controller, recommendation service, NLP client, voting logic,
authentication service, repository service, historical migrations, and
[.dockerignore](../.dockerignore) were not edited.

Shared routing impact is limited to the new extension route. The adapter's
controller dependency deliberately keeps web validation and response parity in
one place. No Technical Lead scope approval is claimed; the team must coordinate
extension and one-way synchronization scope through its established process.

### Actual validation results

| Run | Runtime | Tests | Passed | Assertions | Failures | Errors | Skipped |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Initial full baseline | PHP 8.5.3 (default executable) | 507 | 505 | 3,625 | 0 | 0 | 2 |
| New endpoint targeted suite | PHP 8.3.35 | 15 | 15 | 271 | 0 | 0 | 0 |
| Final full PHPUnit regression | PHP 8.3.35 | 522 | 520 | 3,896 | 0 | 0 | 2 |
| JavaScript regression | Node test runner | 17 | 17 | Not reported | 0 | 0 | 0 |

All runs exited 0. The initial PHP 8.5 baseline reported two deprecations.
The final PHP 8.3 run reported no deprecations. PHP 8.3 was selected explicitly
after discovering that the default executable differed from the intended runtime.

Targeted tests cover bearer-only authentication, revoked credentials, exact web
response and outgoing NLP payload parity, Top 3 ordering and low scores, duplicate
parent resolution, cross-project isolation even for two authorized projects on
the same repository, forged mappings, stale membership/mapping, input boundaries,
empty projects, upstream errors/malformed responses/connection failure, access
revocation during NLP, token expiry and auth_version invalidation, repository
replacement during NLP, reauthentication after NLP failure, and throttling.

Side-effect assertions check unchanged issue records and no new issues, votes,
duplicate candidates, queue jobs, or activity logs; Queue::fake also verifies no
dispatch. The real Laravel controller/service/NlpClient path is exercised with
HTTP fake, not a mocked recommendation service.

The two existing skips remain unchanged:

- `SubmitIssueQueueIntegrationTest::test_submit_database_worker_and_live_fastapi`:
  requires opt-in live FastAPI with the real model.
- `SubmitIssueTest::test_missing_job_keeps_issue_and_marks_detection_failed`:
  only applicable before NLP Job integration.

Final PHPUnit command, from the backend directory:

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
NLP_LIVE_TEST_URL= /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit \
--configuration phpunit.xml --display-skipped
```

For targeted tests, append `tests/Feature/ExtensionRecommendationTest.php`.
JavaScript command, also from the backend directory:

```sh
node --test tests/javascript/*.test.cjs
```

Editor diagnostics found no errors in the three changed PHP files.
`git diff --check` passed for tracked changes; the new implementation and tests
were also reviewed directly because they are untracked.

### Git status and security review

The three pre-existing tracked modifications remain:
[bootstrap/app.php](../backend/bootstrap/app.php),
[layouts/app.blade.php](../backend/resources/views/layouts/app.blade.php), and
[routes/web.php](../backend/routes/web.php).
All pre-existing untracked E2/E3.1 files remain. This step adds the controller,
test file, and progress log; the API route file remains untracked with its new
route. Nothing is staged or committed.

No actual credentials were read or added. Tests generate temporary credentials
against SQLite in-memory. No credentials or upstream response bodies are exposed
by the new controller. Authentication remains request-local and cannot establish
a web session. Authorization is rechecked after the potentially slow NLP call,
without holding a database transaction open during HTTP.

### Limits and continuation

- A–D remain completed locally per prior context. E2 and E3.1 are preserved;
  E3.2 is now locally tested. E4–E11 and F remain pending.
- This is mocked HTTP integration, not live model inference, browser E2E, real
  GitHub authorization, webhook delivery, or production validation.
- Repository access still relies on stored GitHub App connection data and
  DupIssue membership; it does not independently verify the Reporter's GitHub
  identity. The post-NLP check is a final database authorization read, not a lock
  spanning response transmission.
- Existing tests confirm insertion of 5,000 CSV issues and queue jobs, not the
  performance of 5,000 real NLP inferences.
- Next step after user approval: E4, inspect and reuse existing voting behavior,
  preserve web responses, and add extension Vote/Unvote API tests.
- Do not advance to E4, commit, push, merge, or deploy without explicit approval.
