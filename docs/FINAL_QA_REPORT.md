# DupIssue — FINAL QA COMPLETION

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

### Latest performance evidence

| Metric | Result |
| --- | ---: |
| CSV imported issues | 5000 |
| Database jobs created | 5000 |
| NLP HTTP requests during import | 0 |
| CSV import duration | 1.625s |
| CSV import PHP peak memory | 72.50 MB |
| Large-project preparation | 10.9271s |
| Web warm median | 0.2037s |
| Extension warm median | 0.2022s |
| Web warm maximum | 0.2103s |
| Extension warm maximum | 0.3638s |

All ten observed warm requests completed within 3 seconds.
Preparation used an extended QA-only timeout.

| Project issues | First queue rerun | Hardened queue rerun |
| --- | ---: | ---: |
| 10 | 0.0504s | 0.0615s |
| 100 | 0.2883s | 0.2309s |
| 1000 | 2.4088s | 2.2771s |
| 5000 | 11.4244s | 2.7259s |

Each sample processed one detection job and persisted three candidates.
The hardened test verifies actual database dispatch and worker execution.
Cache conditions were not controlled identically between reruns.
The faster sample does not prove a new optimization or cold-cache SLA.
The production NLP model, scoring and timeout remain unchanged.

### Security verification

124 source files were screened for known credential patterns.
No matching credential or private key patterns were found.
Five concrete public tunnel references were identified for redaction.
Private QA artifacts were confirmed untracked and ignored.
Outbound URL and HTTP method boundary tests passed.
Pattern screening is not a comprehensive security audit.

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

## Actual extension defect checkpoint — 25 November 2026

User-reported actual Chrome for Testing installation/pairing/Reporter identity/repository
mapping/workspace/consent PASS; native GitHub draft capture and Top3 display FAIL.
Workspace stays WAITING_DRAFT. Root cause is not yet established: selectors alone would
normally produce INVALID_DRAFT if the content-message path were working. Need scoped
actual DOM metadata and injection/source-tab evidence.21/21 existing targeted tests PASS,
but fixture/mock coverage does not establish live DOM compatibility. Connection/history
preserved. No functional source change or speculative fix. Narrow workspace recorded as
separate usability observation; redesign deferred. See latest [LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md).
Older checkpoints below are historical; no actual recommendation PASS is claimed.

## Latest post-connection audit — 25 November 2026

Actual DB connection PASS: project1, installation183282780, repository1452856825,
valentinokevin/dupissue-testing, verified_at16:02:25WIB, exactly one connection. Historical
fingerprints unchanged. **OAuth per-stage runtime trace is NOT FULLY VERIFIED**: code
and resulting state support the expected flow, but redirect/state/PKCE/token-exchange
trace was not retained. Fresh bootstrap has0HTTP fakes/fixture files; no bypass found.
No OAuth replay or revocation. Existing authorization is the likely skipped-prompt reason,
not a verified fact for this session. Chrome QA extension rebuilt and Reporter membership
verified; live extension tests await manual installation/pairing. New tunnel expires
25November2026at16:47:04WIB; manual Webhook URL update required. See latest
[LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md) for exact URL, evidence limits and instructions.
Older checkpoints below describe earlier state.

## Latest actual execution — 25 November 2026

Actual GitHub App authentication, personal installation identity/read permissions,
repository identity, repository-scoped short-lived token and issue-read access PASS.
Credential use and manual settings save are now owner-authorized. GitHub Recent deliveries
confirms ping and installation-event HTTP202; this is not issue synchronization proof.
Services are active with QA_GITHUB_NETWORK=1 in isolated SQLite. Existing histories are
unchanged;0GitHubconnections. OAuth and actual extension workflows await manual user
login/authorization. Current tunnel expires25November2026at16:13:33WIB; never reuse an
expired hostname. Rollback unknowns remain documented, including no guarantee of exact
old-secret restoration. Full evidence and manual instructions are in the latest
[LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md) checkpoint. Older statuses below are historical.

## Latest — public ingress PASS; actual App credential gate, 24 November 2026

User now authorized official workspace-only cloudflared download and temporary
gateway-only Quick Tunnel with synthetic public probes. **Actual GitHub App/OAuth/import,
real GitHub DOM and Edge remain UNTESTED.** No development credential read/use, settings
change, actual issue or repository write. Older local-only status below is historical.

Changed QA gateway deadline from additive10sbody+8supstream to8s absolute TCP-acceptance
budget (headers/body/upstream combined),5sbody sublimit,100msforced-close grace.
Added2deadline tests and bounded [quick-tunnel.mjs](../backend/tests/qa/integration/quick-tunnel.mjs).
Business logic, NLP architecture and production configuration unchanged.

| New actual evidence | Result |
| --- | --- |
| Gateway |14/14 |
| Combined Node |71/71 including2parents;0failure/cancel/skip;7.9805s |
| Public synthetic fixture |14/14 probes: denied paths404/GET405/missing422/invalidHMAC401/signedping202IGNORED/unconnected403 |
| Probe DB afterwards |0deliveries/jobs/GitHubissues/sync_logs/connections |
| Final public hostname with main backend |10/10probes; paths/method denied, POST503NOT_CONFIGURED intentionally before credential activation |
| Local live NLP |Health200/candidates200/similarity1.0 identical text, original offline model |
| Main isolated QA DB |6COMPLETEDissues/0connections/0jobs/0failed_jobs |
| Lifecycle |Manual SIGTERM confirmed wrapper and cloudflared both exit; restarted30-minute session |

Public fixture samples52–810ms; final main samples56–4010ms, one sample per case.
No latency percentile/capacity claim.8slocal budget is not an Internet delivery SLA.
GitHub HTTP remains fake in readiness suite; synthetic HMAC/ping is not actual App
delivery/import evidence. Accepted PHP614/612passed/5100assertions/2skips,NLP29,MySQL9,
Chrome7fixture retained as prior results. Final full regression remains scheduled
after actual integration/fixes. F2cold≈10.49s/SRS/backlog/TechnicalLead gaps persist.

Official cloudflared2026.11.2 ARM64 verified via archive API digest, executable release
checksum and strict Cloudflare Inc. macOS code signature. Ignored workspace only;
no global install/account/DNS change; no raw provider/request logs retained.
Current URL: https://qa-tunnel.invalid/api/github/webhook
automatically expires **24November2026 23:49:33WIB**. Fixed upstream18081; metrics18082
loopback. Automatic30-minute expiry implemented, not yet elapsed at checkpoint.
Private state `.qa/tunnel-sessions/1795537173519-ad8ee787/state.json`.

Laravel18080/gateway18081/NLP18765/worker/scheduler running GitHub-disabled;8000PID41577
untouched. QA extension build refreshed. Private nonsecret rollback template prepared,
actual settings unknown until user records them. Exact user steps/current commands in
[LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md). Reports in ignored `.qa/integration-execution-node.log`,
`.qa/public-ingress-results.json`, `.qa/public-main-ingress-results.json`.

Updated4QA docs; gateway/tests changed and1source wrapper added. Git19modified,
84untracked,0staged; branch fix/github-app-repository-management, HEAD
b62f83bef6746771d8c30745308162d1a0a52607 unchanged. Disk6.8GiB. No deletion or Git writes.
**Checkpoint: user prepares local credentials, records settings, confirms App not in
shared use and explicitly authorizes credential activation before actual OAuth/API.**

## Latest checkpoint — integration QA implementation, 2026-11-24

**Local integration environment and webhook gateway implemented and tested.
Actual GitHub App/real GitHub DOM/Edge integration is NOT yet PASS.**
Authorization covers local QA only. Existing NLP cache, website/extension business
logic, local-app fixture launcher and prior work preserved; no production changes.

### Implementation delivered

New files:

1. [integration-app.php](../backend/tests/qa/integration-app.php) — guarded CLI/server entry.
2. [Environment.php](../backend/tests/qa/integration/Environment.php) — private runtime, migrations/seed, config/recovery.
3. [OutboundPolicy.php](../backend/tests/qa/integration/OutboundPolicy.php) — exact source-derived network allowlist.
4. [github-config.example.json](../backend/tests/qa/integration/github-config.example.json) — blank credential template.
5. [webhook-gateway.mjs](../backend/tests/qa/integration/webhook-gateway.mjs) — loopback-only fixed upstream/path gateway.
6. [webhook-gateway.test.mjs](../backend/tests/qa/integration/webhook-gateway.test.mjs) —12security cases.
7. [launcher.test.mjs](../backend/tests/qa/integration/launcher.test.mjs) —5runtime scenarios.
8. [readiness.test.mjs](../backend/tests/qa/integration/readiness.test.mjs) —12integration scenarios.
9. [test-fixture.php](../backend/tests/qa/integration/test-fixture.php) — test-only GitHub fake, separate random DB profile.
10. [IntegrationQaPolicyTest.php](../backend/tests/Unit/IntegrationQaPolicyTest.php) —4tests/35assertions in default PHPUnit suite.

Updated documents: [LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md),
[DEVELOPMENT_PROGRESS](DEVELOPMENT_PROGRESS.md),
[EXTENSION_SYNC_OPERATIONS](EXTENSION_SYNC_OPERATIONS.md), this report.
Ignored local build regenerated with `--qa`; packaged production origin unchanged.
No dependency installation, new migration, credential activation or NLP changes.

### Actual evidence and limits

| Check | Actual result |
| --- | --- |
| Full PHP8.3 regression |614tests,612passed,5100assertions,2existing skips,0failures/errors;16.688s/82.50MiB |
| Existing JS regression |38/38 |
| Gateway |12/12 cases; exact paths/methods, raw bytes, headers, sizes, invalid input, HMAC result, upstream failure/timeout/unsafe response, bounded concurrency |
| Dedicated launcher |5/5 scenarios + runner parent; overwrite/config refusal, real login/CSRF/host guard, real NLP smoke, scheduler cadence, worker and no orphan shutdown |
| Readiness chain |12/12 scenarios + runner parent; real HTTP/SQLite/session/CSRF/gateway/worker/NLP; GitHub fake |
| Combined Node |69/69 (67leaf tests/scenarios +2parents),0failure/cancel/skip |
| NLP tests |29/29; original existing cache behavior unchanged |
| Live submit |1test/21assertions PASS actual local model |
| Live recommendation subset |1test/6assertions PASS actual web/extension parity; prior3/56/F2large benchmark not rerun wholesale |
| MySQL/Chrome |Prior9/9 and7fixture scenarios retained, not new runs |

Sanitized logs: `.qa/integration-final-phpunit.log`, `.qa/integration-final-node.log`.
Real model loaded offline from existing cache, no download. New main integration DB
has6synthetic issues COMPLETED,0GitHubconnections,0jobs/failed_jobs. Each readiness run
creates a separate private random test DB; test-only OAuth/PKCE + generated RSA key
and HTTP fakes create its connection through existing application flow. No synthetic
mapping masquerades as actual authorization in main QA DB.

Observed chain: signed raw body → gateway → HMAC → durable delivery/task → database
worker → fake verified GitHub API fetch → shared persistence + SYNC_GITHUB audit →
actual offline NLP/candidates. Invalid signature rejected; nonempty ping ignored
without delivery; completed redelivery idempotent; manual preview recognizes import;
six stale pending issues recover and complete. CSRF, Reporter membership/project
mapping, vote/unvote, disconnect and forged project rejection tested. Private config
keys/path/permissions, symlink/overwrite refusal, default credential non-parsing,
outbound denial and redacted logs tested. No real database accessed; readiness
checksums verify old fixture and main integration DB unchanged by that test flow.

Initial runs exposed wrong harness assumptions for empty ping/preview response and
shutdown handling that could leave a child server. Corrected expectations and used
same-process exec; final combined run passes with no cancellation. Existing receiver,
OAuth/import/voting/NLP production behavior was not changed to make tests pass.

### Operational/security status

Local Laravel18080/gateway18081/FastAPI18765 and worker/scheduler running; bind loopback
only. Worker/scheduler auto-stop after2hours. Port8000 unchanged. No public endpoint.
GitHub disabled by default; enabling requires explicit future flag and private
configuration. No dev secret/PEM used. Only synthetic test keys were generated/read.
Exact GitHub settings, credential procedure, commands and browser steps are in
[LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md). QA exception message/context is deliberately
removed from logs; use safe DB status/error codes for diagnostics. Outbound guard
applies to Laravel HTTP, not arbitrary new raw clients or an OS firewall.

Gateway accepts only POST `/api/github/webhook`, max1MiB,10sbody/8supstream deadlines,
16in-flight/32connections, no sensitive header forwarding, no arbitrary response
proxying, no request logging. HMAC remains enforced by backend. Gateway traffic
shares Laravel loopback bucket120/min. This is local QA tooling, not a production proxy.

Recommended: **Cloudflare Quick Tunnel**, after approval and workspace-only verified
binary provisioning. No account/domain required; temporary hostname only known on
launch. Ngrok requires account/authtoken and inspection/privacy review; SSH exists
but no approved relay server. Neither tunnel CLI found in PATH; no install/tunnel
performed. Official sources/comparison in guide.

Remaining: approved provider/data transit/hostname; dev credential provisioning/use;
App settings/selected-repository installation; manual account login; actual GitHub
DOM/Markdown/Edge checks; approved synthetic issue/redelivery and real delivery chain.
Replacement needs a separately approved second testing repo. F2cold≈10.49s,TTL900s,
eviction/restart/overload and5000-job/20-user capacity gaps remain; Technical Lead
review and SRS acceptance are not implied by this environment.

Final branch `fix/github-app-repository-management`, HEAD
`b62f83bef6746771d8c30745308162d1a0a52607`;19modified/83untracked/0staged;
disk6.9GiB free. No files deleted, model download, real DB changes, external account
use/write, public tunnel, stage/commit/push/PR/merge/deploy. **Checkpoint before
actual credentials, public ingress or GitHub settings/issue mutation.**

## Previous checkpoint — F5/F6 integration preparation, 2026-11-24

**SOURCE AUDIT / PREPARATION DOCUMENTATION COMPLETE; ACTUAL EXTERNAL INTEGRATION
PENDING.** This is a source and local-runtime audit plus a concrete runbook, not
actual GitHub/Edge test evidence. No application/NLP/launcher source was changed.
Updated documents: [LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md),
[DEVELOPMENT_PROGRESS](DEVELOPMENT_PROGRESS.md), and this report.

| Readiness item | Actual state at this checkpoint |
| --- | --- |
| Disk | `df -h .`:7.9GiB available; no deletion/download |
| Git | Branch `fix/github-app-repository-management`; HEAD `b62f83bef6746771d8c30745308162d1a0a52607`;19modified/73untracked/0staged |
| Existing QA data | Guarded status: SQLite9issues/9completed/0jobs/0failed_jobs; private DB/accounts files preserved |
| Services | Laravel18080 and NLP18765 not running; port8000 belongs to unrelated PHP process and was untouched |
| Local extension | Existing build fixed to127.0.0.1:18080, one local API host permission |
| Browser | CFT binary exists; prior7fixture scenarios remain PASS. Actual GitHub DOM/Edge not tested; standard Edge app path absent |
| GitHub integration | App config/access/credentials not queried; no tunnel, external issue, or actual signed delivery |
| Integration environment | Separate DB/bootstrap/outbound allowlist/webhook-only gateway design documented; implementation and local guard checks still prerequisite to activation |

Source audit confirms OAuth state/PKCE plus owner-bound session, installation/user
access verification, repository-scoped token, fresh metadata identity verification,
replacement snapshot locking, signed raw webhook validation, durable dedup/task queue,
verified API issue fetch and shared import after authorization. Receiver binds
installation ID/repository ID/issue number; it does not persist a separate global
GitHub issue ID. One task is created per matching project connection. Ping is202
IGNORED, not an import. Completed redelivery is distinct from retrying an incomplete
task. NLP is a separate after-commit queue stage.

Configuration gotchas: source requires Issues exactly `read`; separate Setup URL
means OAuth-during-installation OFF; setup action accepts install/empty, so Redirect
on update OFF. Callback exact path `/integrations/github/callback`, Setup
`/integrations/github/setup`, webhook POST `/api/github/webhook`. Proposed callback
and Setup use `http://127.0.0.1:18080`; public HTTPS host is unresolved, and public
exposure is restricted to the webhook through a dedicated gateway. Existing
fixture launcher remains fail-closed to GitHub HTTP and must never be tunneled.

Runbook includes exact env names, local service commands, browser installation and
consent/dynamic-navigation/templates/Top3/vote/unvote/detail/switch/disconnect cases,
webhook-to-NLP ledger, invalid signature/identity expectations, manual-import
consistency, timestamp semantics and historical-preservation checks. Actual
replacement needs a second approved testing repository; the sole current authorized
target is `valentinokevin/dupissue-testing` with App
`dupissue-kel4-dev-valentinokevin`. No broader repository experiment authorized.

Validation this checkpoint: source/route/config comparison, local port/file/build
checks, guarded QA status, documentation links/format and Git whitespace checks.
No PHP/JS/NLP regressions repeated because only documentation changed. Accepted
baseline remains PHP610/608passed/5065assertions/2skips; JS38; NLP29; live submit1/21;
live recommendation3/56; MySQL9; Chrome7fixture flows. These are prior results,
not newly executed tests or actual GitHub E2E.

Remaining blockers: locally implemented/tested integration bootstrap and narrow
gateway; approved HTTPS provider/host; user-supplied dev credentials and approval
to use them; App configuration/installation and browser account consent; approval
for a synthetic issue/redelivery; actual DOM/template/Edge execution; separate
repository approval for actual replacement. Docker/remote CI and Elsa/Joshua
reviews remain separate. Public ingress, credentials and external writes are all
stopped at the approval checkpoint.

F2 cache preserved unchanged. Cold≈10.49s fails3s and exceeds default10s; TTL900s,
eviction, restart/multiple-process cold misses and bounded contention still apply.
5,000-job backlog/20-user capacity is not established. Shared NLP requires Technical
Lead review; no persistent embedding storage or new architecture introduced.
No files deleted, actual DB changed, credential read, account login, tunnel,
external settings/issue mutation, stage/commit/push/PR/merge/deployment performed.

## Previous checkpoint — F2 NLP performance optimization, 2026-11-24

**Local experimental optimization and regression completed; pending Technical Lead
review. SRS performance acceptance remains PARTIAL, not production-ready.**
Full audit, architecture, lifecycle, raw evidence paths, memory, concurrency,
limitations and reproduction: [NLP_PERFORMANCE_REPORT.md](NLP_PERFORMANCE_REPORT.md).

Root cause measured with the original offline model: encoding all candidate texts
on every request (mean10.8574s at5,000), while vectorized cosine takes≈0.000913s.
New process-local LRU caches embeddings by model/revision/project/issue/exact content;
10,000entries,32MiB accounted storage,900sTTL, bounded2s inference-lock wait.
Text/model/score meaning, API schemas, CSV75%, Top3, parent resolution and human
review preserved. No PHP production voting/import/recommendation logic changed.

| Issues | Before HTTP median | Optimized cold | Warm | One candidate +draft edited |
| ---: | ---: | ---: | ---: | ---: |
|10|0.03035s|0.02485s|0.00227s|0.01568s|
|100|0.21729s|0.23455s|0.00548s|0.02926s|
|1,000|2.25421s|2.09018s|0.01293s|0.02244s|
|5,000|10.61953s|10.49264s|0.04903s|0.06224s|

Five samples per cell, same synthetic dataset/CPU2threads, no percentile claim.
Actual5,000-issue worker median: before10.87972s; optimized cold10.68846s,
warm0.43380s, incremental0.45327s.20baseline+60optimized completed worker deliveries,
not5,000inference jobs. Warm backend integration at5,000existing candidates: web
median0.2215s/extension0.2232s with default10s timeout, identical responses.

Cache5,000vectors:7.324MiB tensor data,12.207MiB accounted; sampled server cold
RSS peak533.63MiB versus baseline339.38MiB (includes transient allocation/model,
not all attributable to cache). Parity error0 for captured cold/warm benchmark;
multilingual/incremental/prewarm maximum1.79e−7, within1e−6, ordering unchanged.

Cold still fails3s/exceeds10s. Isolated prewarming20chunks takes11.03–12.22s;
subsequent full request0.050–0.229s. No production prewarming lifecycle installed.
Restart, TTL/eviction, multi-process cache duplication and cold head-of-line blocking
remain operational limitations. Busy requests fail503 after bounded wait and recover.
5,000CSVjobs/full backlog and20users are not certified. Default timeout unchanged.

Actual regression: **PHP610tests/608passed/5,065assertions/2skips/0failure-error;
JavaScript38/38; NLP29/29; live submit1test/21assertions; live recommendation3tests/
56assertions; worker benchmark baseline1/180 and optimized1/540.** Chrome7flows and
MySQL9checks are accepted previous evidence, not newly rerun. Source/syntax/diff
checks passed; no Docker/remoteCI/actualGitHub execution claim.

Final Git:19modified,73untracked,0staged; branch/HEAD unchanged. Disk6.9GiB free.
No deletion, model download, actual DB access, commit/push/PR/merge/deploy or external
credential/account action. Shared NLP needs Joshua approval; stop before actual
GitHub App integration. The previous FINAL QA report below is retained as history;
this checkpoint supersedes its performance and latest test/Git counts only.

---

## Previous FINAL QA COMPLETION — retained evidence

Tanggal:24 November2026. Worktree `dupissue-github-enhancement`;
branch `fix/github-app-repository-management`;
HEAD `b62f83bef6746771d8c30745308162d1a0a52607`.

## 1. Executive summary

**Original-model NLP dan actual Chrome for Testing/local-stack integration berhasil.**
Model multilingual MiniLM dimuat offline dari snapshot existing, tanpa download
ulang. Real queue job dan parity web/extension lulus. Pengujian browser menemukan
delay tampilan15detik; perbaikan minimal menurunkannya menjadi1,824–2,355detik pada
fixture kecil. Vote/Unvote, pairing, detail, pergantian project dan disconnect lulus.

**Belum production-ready.** Respons pada5000issues10,407–14,797detik tidak memenuhi
target3detik dan melampaui timeout client default10detik. Actual GitHub App/DOM,
Microsoft Edge, Docker/remote CI dan approval Elsa/Joshua masih terbuka.

Tidak ada commit, staging, push, PR, merge, deployment, public tunnel, penggunaan
credential produksi, perubahan setting GitHub atau actual issue creation.
Tidak ada file/environment/cache dihapus. Pekerjaan sebelumnya dipertahankan.

## 2. Overall status dan baseline

E5–E11 sudah diterima pengguna sebagai PASS automated lokal; tidak diimplementasikan
ulang. FINAL QA menambahkan bukti live, launcher terisolasi, browser automation dan
dua regression tests untuk perbaikan render/privacy.

| Pengujian | Baseline yang diterima | Hasil akhir aktual |
| --- | --- | --- |
| PHPUnit PHP8.3 |610tests,608passed,5065assertions,2skips|**Sama:610/608/5065/2,0failure/error**|
| Website Node |17/17|**17/17**|
| Extension Node |19/19|**21/21**|
| Combined Node |36/36|**38/38**|
| NLP unit mocked |12/12|Bukti sebelumnya dipertahankan; tidak diulang tanpa perubahan NLP|
| MySQL isolated concurrency |9/9|Bukti sebelumnya direview; shared logic/schema tidak berubah|
| Existing live NLP opt-in |Belum dijalankan|**1test/21assertions PASS**|
| Live parity + queue benchmark |Belum dijalankan|**2tests/26assertions PASS**|
| Actual Chrome for Testing |Belum dijalankan|**7 pemeriksaan alur PASS,0page errors**; GitHub HTML fixture|

Regresi akhir610tests berjalan17,927s, PHP peak82,50MiB. Dua skip default tetap:
live NLP opt-in (lulus pada run terpisah), dan missing-job test yang hanya berlaku
sebelum real NLP job terintegrasi. Jangan menjumlahkan run opt-in yang overlap
sebagai tambahan test unik. Terhadap baseline E4 lama574tests/4839assertions,
tambahan36tests/226assertions sebelumnya tetap dipertahankan.

## 3. Status empat fitur utama

| Fitur | Bukti | Batas acceptance |
| --- | --- | --- |
| Extension + auto sync | Actual MV3/local web/NLP/voting PASS; signed webhook/import/retry tests dan prior MySQL PASS | GitHub document fixture; actual external delivery dan Edge belum diuji |
| CSV Top3 |5000row insertion/jobs PASS;4real NLP jobs pada ukuran representatif selesai | Tidak ada klaim5000inference jobs selesai; latency5000 belum memenuhi target |
| Repository replacement | Existing tests/prior MySQL race/history PASS | Actual dev-App replacement membutuhkan repository kedua yang disetujui |
| GitHub App only | PAT retirement regression dan runtime source review PASS | Actual installation/credential QA dan amendemen SRS belum disahkan |

## 4. Status E5–E11 dan F1–F9

| Stage | Status | Keterangan |
| --- | --- | --- |
|E5|PASS_LOCAL|MV3/auth/session; kini installation/pairing actual Chrome for Testing juga lulus|
|E6|PASS_LOCAL|Real model UI/vote lulus pada fixture; render-lag diperbaiki|
|E7|PASS_LOCAL|Raw HMAC/durable delivery/security regression|
|E8|PASS_MOCKED_INTEGRATION|GitHub HTTP fake; shared persistence dan identity race teruji|
|E9|PASS_MOCKED_INTEGRATION|Actual DB worker/recovery; original provider HTTP fake|
|E10|PASS_MOCKED_INTEGRATION|Cross-feature133tests1877assertions historis; full regression tetap PASS|
|E11|PASS_LOCAL|Source/config/regression/docs review; belum commit|
|F1|PASS_LIVE_INTEGRATION|Original model offline, live queue, web/extension parity|
|F2|FAILED untuk target latency; pengukuran selesai|5000issues10,407–14,797s; throughput/backlog acceptance belum penuh|
|F3|PASS_LIVE_INTEGRATION, bukti sebelumnya direview|MySQL8.0.46 isolated9checks; tidak diulang|
|F4|PASS_LIVE_INTEGRATION untuk local queue→NLP|Tambahan seeded job selesai via real FastAPI; GitHub tetap fake|
|F5|PASS_LIVE_INTEGRATION terbatas|Actual Chrome engine/unpacked/local stack; live GitHub DOM dan Edge REQUIRES_USER_ACTION|
|F6|REQUIRES_USER_ACTION|Checklist konkret siap; approval/settings/credentials/HTTPS belum tersedia|
|F7|PASS_LOCAL audit; build/remote execution BLOCKED|Docker tidak tersedia; tidak ada remote CI/deploy|
|F8|PASS_LOCAL traceability|SRS aktual direview, performance gate gagal ditandai; approval tim pending|
|F9|PASS_LOCAL|Laporan, checkpoint, operasi, traceability, panduan lokal diperbarui|

## 5. Arsitektur dan perubahan final QA

Content script membaca draft setelah consent; background worker memegang Bearer;
hasil privat hanya di extension-owned workspace. Backend memakai recommendation
dan VoteService existing. Signed webhook→durable task→default worker→verified
GitHub fetch→shared persistence→after-commit NLP queue. Tidak ada two-way sync.

Perbaikan QA: trusted `snapshot` membaca state lokal setiap500ms; `view` tetap
memverifikasi akses/vote ke server setiap15s. Content script tidak boleh meminta
snapshot. Refresh gagal menghapus state sehingga snapshot tidak menghidupkan
kembali hasil privat lama. Navigasi/project change menghapus hasil/consent.

Build `--qa` hanya memilih fixed origin127.0.0.1:18080. Production manifest tetap
origin produksi; tidak ada arbitrary-origin configuration. Port8000 yang dipakai
worktree lain tidak disentuh. Login redirect existing dipertahankan.

Launcher interactive memiliki DB/storage/session/key sintetis terpisah, menolak
cached config dan database symlink, tidak membaca actual app .env, dan memblokir
outgoing HTTP selain NLP loopback. Recovery actions berjalan dalam guarded process.
Detail:[operations](EXTENSION_SYNC_OPERATIONS.md),[panduan lokal](LOCAL_QA_GUIDE.md).

## 6. Seluruh file created/modified

Inventaris akhir di bawah mencakup semua perubahan, termasuk E1–E4 yang sebelumnya
sudah untracked. Tambahan pada FINAL QA:
- backend/tests/qa/local-app.php
- backend/tests/qa/benchmark-nlp.py
- browser-extension/tests/qa-browser.mjs
- browser-extension/tools/qa-demo.mjs
- docs/LOCAL_QA_GUIDE.md

File existing yang diubah pada kelanjutan ini: extension API/local build, message
validation, workspace state/UI, security/workspace tests, README dan empat dokumen
QA. LiveRecommendationTest hanya diformat. App dependency/lockfiles, schema dan
shared voting/import logic tidak berubah.

## 7–8. Bukti pengujian dan error yang diselesaikan

PHP8.3 executable tetap /opt/homebrew/opt/php@8.3/bin/php. Default PHPUnit memaksa
SQLite in-memory; QA interactive menggunakan file baru sintetis. Tidak ada actual
development/production database digunakan. Final PHP/Node hasilnya pada tabel di atas.

Real browser awal gagal karena harness mengasumsikan login menuju intended URL;
existing login memang menuju daftar project. Harness diperbaiki, auth tidak diubah.
Automation harus memicu toolbar action untuk activeTab; direct popup-tab tidak
menggantikan gesture tersebut. Setelah harness benar, ditemukan defect produk:
hasil baru tampil15,529s karena renderer menunggu polling15s. Regression menangkap
defect; local snapshot memperbaikinya, lalu flow lulus1,824s dan2,355s.

PHP/JS syntax, editor diagnostics dan git diff --check PASS. Existing CI memakai
PHP8.3/Node22 dan menjalankan seluruh21extension Node tests. Source migrations,
queue60s/retry_after90s, Docker ignore dan workflow direview. Docker image build
dan remote Actions belum dijalankan. Prior Composer/YAML validation tetap valid
karena konfigurasi/dependency tersebut tidak berubah.

## 9. Security review

Fixed origins/endpoints, trusted session storage, sender/tab checks, no cookies/
redirect credentials, safe DOM/links, project/role/member authorization, HMAC/raw
body, unique votes/deliveries/stable identity dan transactional audit dipertahankan.
Tidak ada perluasan permission manifest untuk pengujian. Snapshot hanya trusted UI.
Server revalidation tetap berkala dan setiap mutation/recommendation reauthorizes.

Chrome automation memakai profil baru dan synthetic accounts. GitHub URLs diroute
ke fixture; external requests diblokir oleh harness. Code pairing pada screenshot
disamarkan. Credential QA acak disimpan lokal0600 diignored directory, bukan source.
Tidak ada actual GitHub token/private key/secret diakses. Ini bukan penetration-test
atau TLS/deployment certification.

## 10. Performance benchmark aktual

Model:paraphrase-multilingual-MiniLM-L12-v2, snapshot
e8f8c211226b894fcb81acc59f3b34ba3efd5f42. Safetensors SHA256 cocok.
Python3.12.6, sentence-transformers6.1.0, torch2.15.0, CPU2threads, ARM64/RAM8GiB.
Disk mulai9,4GiB kosong setelah cleanup pengguna; tidak ada download model ulang.

Smoke: load0,7619s;3text inference0,3330s; cosine identik1,0/unrelated0,08805;
embedding384dim; process high-water RSS550,22MiB. Ini membuktikan model asli berjalan,
bukan sertifikasi kualitas semantik lintas bahasa/domain.

| Ukuran project | HTTP median,3request | Rentang HTTP | Sampled peak server RSS | Actual queue job,1per ukuran |
| --- | ---: | ---: | ---: | ---: |
|10|0,0392s|0,0318–0,4417s|171,89MiB|0,0436s|
|100|0,2434s|0,2302–0,2473s|249,47MiB|0,2729s|
|1000|2,1265s|2,1007–2,3145s|293,02MiB|2,3734s|
|5000|12,5153s|10,4070–14,7966s|317,88MiB|10,5567s|

Empat queue jobs benar-benar selesai dengan maksimal3CSV candidates. PHP peak
pada5000:90,5MiB. Observed single-job throughput5000≈5,68jobs/min; HTTP sequential
throughput5000≈4,77requests/min. Angka tersebut bukan sustained queue capacity.
RSS sampling100ms tidak sama dengan high-water process memory; mmap/shared pages
dan aktivitas setup/browser lokal memengaruhi hasil. Tidak ada klaim p95 dari n=3.

**Target95%≤3s tidak terpenuhi pada sampel5000.** Default NlpClient timeout10s lebih
pendek daripada pengukuran. Benchmark queue memakai120s/worker150s khusus QA;
tidak membuktikan konfigurasi production lulus. Tidak ada5000-job backlog atau
20-user load run. Existing CSV5000row insertion1,595s hanya bukti import/enqueue.

Bottleneck: encoding query dan seluruh kandidat di setiap request. Rekomendasi
perbaikan terpisah: cache/precompute embeddings keyed model/version/project/content,
incremental invalidation saat edit/import/delete, bounded memory/concurrency,
parity ranking/threshold dan project-isolation tests. Ukur ulang latency dan
throughput sebelum mempertimbangkan timeout. Model/threshold tidak diganti.

## 11–12. Real dan mocked integration

**Real:** original model offline; Laravel real NlpClient; SQLite queue worker;
web/extension API parity; actual Chrome for Testing158.0.8127.88 unpacked MV3,
real storage/message/sender/activeTab/browser APIs; local CSRF/login/pairing/votes.
Interactive DB:9issues,9COMPLETED,0jobs,0failed_jobs setelah seeded job diproses.
Recovery tick berhasil; continuous scheduler daemon tidak dijalankan.

**Prior real:** MySQL8.0.46, workspace datadir/socket/schema sintetis,9concurrency
checks; server QA ditutup pada tahap sebelumnya. Bukti direview tanpa mengakses
instance MySQL lain yang ada di Mac.

**Mocked/fixture:** GitHub HTML pada browser; GitHub API/installation/webhook
provider integration pada regression. Fixture verified repository IDs bukan
installation nyata. Actual GitHub DOM, account connection, delivery dan Edge
belum terbukti. Tidak ada deployment atau remote CI PASS.

## 13–14. Limitations dan blockers

- Performance5000 belum memenuhi target/default timeout; full backlog/load pending.
- Actual GitHub DOM/templates dan Edge/branded Chrome pending. Markdown pair saja;
  tidak ada universal structured-form support.
- Workspace terpisah perlu split view; dapat tertutup window lain. Tidak always-on-top.
  Ini menjaga private issue data keluar dari DOM GitHub.
- Poll access15s, request rate60/min; repeated typing dapat mencapai429.
- NLP recovery at-least-once dapat enqueue redundant work ketika backlog lama;
  hasil idempotent, komputasi redundan tetap perlu dipantau.
- Webhook opened-only; actual secret/installation/public HTTPS belum dikonfigurasi.
- Historical unmatched repositories tidak dibackfill sembarang; rename/reuse perlu review.
- Docker build/remote CI, deployment prerequisites dan Elsa/Joshua approval pending.

## 15. Langkah pengguna

Browser QA terlihat sudah dibuka. Baca [LOCAL_QA_GUIDE.md](LOCAL_QA_GUIDE.md):
login dengan akun lokal, buka pairing lagi setelah redirect login, copy code ke
popup, lalu klik toolbar DupIssue pada tab fixture untuk activeTab. Susun workspace
di samping draft, pilih project, aktifkan, ketik, vote/unvote/detail/disconnect.
Credential lokal hanya pada .qa/interactive/accounts.json; nilainya tidak disalin
ke dokumen ini. Laravel18080/FastAPI18765 dan worker QA tersedia selama proses hidup;
worker dibatasi dua jam. Command restart tercantum pada panduan.

Actual GitHub harus disetujui terpisah: dev App dupissue-kel4-dev-valentinokevin,
repo valentinokevin/dupissue-testing, public QA HTTPS, credential use, webhook
settings dan satu issue sintetis. Checklist permissions/events/callback/env/
installation/redelivery/import/NLP lengkap di panduan. Tidak ada public tunnel dibuka.
Repository kedua untuk replacement memerlukan persetujuan khusus.

## 16. Approval teknis

Elsa:shared VoteService/web semantics/locking/interoperability.
Joshua:routing/service-to-service boundary, SRS04.8/04.15/NF15/NF19, performance
optimization dan deployment. [Traceability](SCOPE_TRACEABILITY.md) diperbarui.
Tidak ada approval diasumsikan atau pesan eksternal dikirim.

## 17. Proposed commit grouping dan staging — belum dijalankan

| Proposed English message | Scope staging |
| --- | --- |
|feat(extension): add secure authentication and repository APIs|Preserved E1–E3 controllers/services/middleware/session migration/routes/view/tests|
|refactor(voting): share atomic voting between web and extension|VoteService, web/API controllers, route hunk dan voting tests|
|feat(extension): add private Manifest V3 recommendation client|Extension source/tests, web revocation/retention; termasuk snapshot render/privacy fix|
|feat(github): synchronize verified issue webhooks through durable queue|Webhook/job/services/recovery, shared GitHub adapters, migrations003/004 dan tests|
|test(qa): validate offline NLP and isolated browser workflows|tests/qa, qa-browser/demo tools, local launcher dan benchmark|
|chore(ci): validate extension and document synchronization operations|Workflow/Compose/example env/ignore dan docs|

Stage explicit paths/hunks setelah approval. Routes/auth/console lintas kelompok
harus direkonsiliasi atau digabung agar intermediate commits runnable. Jangan
git add seluruhworkspace; .qa/.local-extension/vendor/credential/cache tidak di-stage.

## 18. Proposed PR description — belum dibuat

**Title:** Add private browser recommendations and durable GitHub issue synchronization

> Reporters can pair an MV3 extension and receive private recommendations and
> shared web/API voting. Signed opened events use durable delivery tasks, verified
> installation/repository fetch and shared import persistence with queue recovery.
> A local state refresh fixes delayed recommendation rendering while preserving
> server authorization checks and content-script isolation.
>
> Validation:PHP8.3 610tests/5065assertions/2existing skips; Node38/38; separate
> original-model live runs1test21assertions and2tests26assertions; actual Chrome for
> Testing with routed GitHub fixture; prior isolated MySQL9checks. At5000issues,
> observed NLP latency10.407–14.797s fails the3s target and exceeds default10s timeout.
> Actual GitHub/Edge, Docker/remote CI and team approvals remain pending.
>
> Additive schema and operational prerequisites are documented. No reverse GitHub
> writes or web auth changes. Preserve additive data on rollback and coordinate
> migration/worker restart after approval. Not production-ready.

## 19. Deployment checklist — belum dijalankan

- [ ] Resolve5000-issue performance/default timeout and run bounded load/backlog validation.
- [ ] Finish actual GitHub/Chrome/Edge DOM QA and Elsa/Joshua review.
- [ ] Review stable-ID backfill/conflicts and backup data before additive migrations.
- [ ] Supply secret-manager env names/private-key read-only mount.
- [ ] Configure same-connection DB queue, workers default/nlp, scheduler and restart.
- [ ] Validate HTTPS/proxy raw body, HMAC secret, event/installation permissions.
- [ ] Pass Docker build/remote CI; monitor health, delivery status, queue age/failures.
- [ ] Authorize rollout separately; preserve delivery/history during operational rollback.

Detailed migration/rollback/env checklist:[operations](EXTENSION_SYNC_OPERATIONS.md).

## 20. Final Git status dan inventory

Branch/HEAD tetap. Semua perubahan unstaged. Tidak ada file dihapus, commit atau
external write. Daftar berikut dihasilkan ulang dari Git; termasuk baseline E1–E4
untracked, bukan seluruhnya file baru pada FINAL QA. Artifacts QA lokal diabaikan.

**16 tracked modified, 67 untracked, zero staged.**

|Status|File|
|---|---|
|M|[.dockerignore](../.dockerignore)|
|M|[.github/workflows/sprint3-github-app.yml](../.github/workflows/sprint3-github-app.yml)|
|M|[.gitignore](../.gitignore)|
|M|[backend/.env.example](../backend/.env.example)|
|M|[backend/app/Http/Controllers/VoteController.php](../backend/app/Http/Controllers/VoteController.php)|
|M|[backend/app/Services/GithubApiClient.php](../backend/app/Services/GithubApiClient.php)|
|M|[backend/app/Services/GithubAppIssueFetchService.php](../backend/app/Services/GithubAppIssueFetchService.php)|
|M|[backend/app/Services/GithubIssueImportService.php](../backend/app/Services/GithubIssueImportService.php)|
|M|[backend/app/Services/GithubIssuePreviewService.php](../backend/app/Services/GithubIssuePreviewService.php)|
|M|[backend/bootstrap/app.php](../backend/bootstrap/app.php)|
|M|[backend/config/services.php](../backend/config/services.php)|
|M|[backend/resources/views/layouts/app.blade.php](../backend/resources/views/layouts/app.blade.php)|
|M|[backend/routes/console.php](../backend/routes/console.php)|
|M|[backend/routes/web.php](../backend/routes/web.php)|
|M|[backend/tests/Feature/GithubApiClientTest.php](../backend/tests/Feature/GithubApiClientTest.php)|
|M|[docker-compose.yaml](../docker-compose.yaml)|
|??|[backend/app/Console/Commands/PruneExtensionSessions.php](../backend/app/Console/Commands/PruneExtensionSessions.php)|
|??|[backend/app/Console/Commands/RecoverGithubWebhooks.php](../backend/app/Console/Commands/RecoverGithubWebhooks.php)|
|??|[backend/app/Console/Commands/RecoverPendingDetection.php](../backend/app/Console/Commands/RecoverPendingDetection.php)|
|??|[backend/app/Http/Controllers/ExtensionAuthController.php](../backend/app/Http/Controllers/ExtensionAuthController.php)|
|??|[backend/app/Http/Controllers/ExtensionRecommendationController.php](../backend/app/Http/Controllers/ExtensionRecommendationController.php)|
|??|[backend/app/Http/Controllers/ExtensionRepositoryController.php](../backend/app/Http/Controllers/ExtensionRepositoryController.php)|
|??|[backend/app/Http/Controllers/ExtensionVoteController.php](../backend/app/Http/Controllers/ExtensionVoteController.php)|
|??|[backend/app/Http/Controllers/GithubWebhookController.php](../backend/app/Http/Controllers/GithubWebhookController.php)|
|??|[backend/app/Http/Middleware/AuthenticateExtension.php](../backend/app/Http/Middleware/AuthenticateExtension.php)|
|??|[backend/app/Jobs/SyncGithubIssueJob.php](../backend/app/Jobs/SyncGithubIssueJob.php)|
|??|[backend/app/Services/ExtensionAuthService.php](../backend/app/Services/ExtensionAuthService.php)|
|??|[backend/app/Services/ExtensionRepositoryService.php](../backend/app/Services/ExtensionRepositoryService.php)|
|??|[backend/app/Services/GithubWebhookDispatchService.php](../backend/app/Services/GithubWebhookDispatchService.php)|
|??|[backend/app/Services/GithubWebhookSyncService.php](../backend/app/Services/GithubWebhookSyncService.php)|
|??|[backend/app/Services/VoteService.php](../backend/app/Services/VoteService.php)|
|??|[backend/database/migrations/2026_11_24_000002_create_extension_sessions_table.php](../backend/database/migrations/2026_11_24_000002_create_extension_sessions_table.php)|
|??|[backend/database/migrations/2026_11_24_000003_create_github_webhook_deliveries.php](../backend/database/migrations/2026_11_24_000003_create_github_webhook_deliveries.php)|
|??|[backend/database/migrations/2026_11_24_000004_add_stable_github_identity_to_issues.php](../backend/database/migrations/2026_11_24_000004_add_stable_github_identity_to_issues.php)|
|??|[backend/resources/views/extension/connect.blade.php](../backend/resources/views/extension/connect.blade.php)|
|??|[backend/routes/api.php](../backend/routes/api.php)|
|??|[backend/routes/extension_web.php](../backend/routes/extension_web.php)|
|??|[backend/tests/Feature/ExtensionAuthEndpointTest.php](../backend/tests/Feature/ExtensionAuthEndpointTest.php)|
|??|[backend/tests/Feature/ExtensionAuthServiceTest.php](../backend/tests/Feature/ExtensionAuthServiceTest.php)|
|??|[backend/tests/Feature/ExtensionPairingPageTest.php](../backend/tests/Feature/ExtensionPairingPageTest.php)|
|??|[backend/tests/Feature/ExtensionRecommendationTest.php](../backend/tests/Feature/ExtensionRecommendationTest.php)|
|??|[backend/tests/Feature/ExtensionRepositoryTest.php](../backend/tests/Feature/ExtensionRepositoryTest.php)|
|??|[backend/tests/Feature/ExtensionSessionRetentionTest.php](../backend/tests/Feature/ExtensionSessionRetentionTest.php)|
|??|[backend/tests/Feature/ExtensionSessionRevocationTest.php](../backend/tests/Feature/ExtensionSessionRevocationTest.php)|
|??|[backend/tests/Feature/ExtensionVoteTest.php](../backend/tests/Feature/ExtensionVoteTest.php)|
|??|[backend/tests/Feature/ExtensionWorkflowTest.php](../backend/tests/Feature/ExtensionWorkflowTest.php)|
|??|[backend/tests/Feature/GithubWebhookQueueTest.php](../backend/tests/Feature/GithubWebhookQueueTest.php)|
|??|[backend/tests/Feature/GithubWebhookSyncTest.php](../backend/tests/Feature/GithubWebhookSyncTest.php)|
|??|[backend/tests/Feature/GithubWebhookTest.php](../backend/tests/Feature/GithubWebhookTest.php)|
|??|[backend/tests/qa/LiveRecommendationTest.php](../backend/tests/qa/LiveRecommendationTest.php)|
|??|[backend/tests/qa/benchmark-nlp.py](../backend/tests/qa/benchmark-nlp.py)|
|??|[backend/tests/qa/local-app.php](../backend/tests/qa/local-app.php)|
|??|[backend/tests/qa/mysql-concurrency.php](../backend/tests/qa/mysql-concurrency.php)|
|??|[browser-extension/README.md](../browser-extension/README.md)|
|??|[browser-extension/background.mjs](../browser-extension/background.mjs)|
|??|[browser-extension/config.mjs](../browser-extension/config.mjs)|
|??|[browser-extension/content.js](../browser-extension/content.js)|
|??|[browser-extension/icons/icon128.png](../browser-extension/icons/icon128.png)|
|??|[browser-extension/icons/icon16.png](../browser-extension/icons/icon16.png)|
|??|[browser-extension/icons/icon48.png](../browser-extension/icons/icon48.png)|
|??|[browser-extension/lib/api.mjs](../browser-extension/lib/api.mjs)|
|??|[browser-extension/lib/contracts.mjs](../browser-extension/lib/contracts.mjs)|
|??|[browser-extension/lib/messages.mjs](../browser-extension/lib/messages.mjs)|
|??|[browser-extension/lib/session.mjs](../browser-extension/lib/session.mjs)|
|??|[browser-extension/lib/workspace.mjs](../browser-extension/lib/workspace.mjs)|
|??|[browser-extension/manifest.json](../browser-extension/manifest.json)|
|??|[browser-extension/popup.html](../browser-extension/popup.html)|
|??|[browser-extension/popup.mjs](../browser-extension/popup.mjs)|
|??|[browser-extension/styles.css](../browser-extension/styles.css)|
|??|[browser-extension/tests/qa-browser.mjs](../browser-extension/tests/qa-browser.mjs)|
|??|[browser-extension/tests/render.test.mjs](../browser-extension/tests/render.test.mjs)|
|??|[browser-extension/tests/security.test.mjs](../browser-extension/tests/security.test.mjs)|
|??|[browser-extension/tests/workspace.test.mjs](../browser-extension/tests/workspace.test.mjs)|
|??|[browser-extension/tools/build-local.mjs](../browser-extension/tools/build-local.mjs)|
|??|[browser-extension/tools/generate-icons.mjs](../browser-extension/tools/generate-icons.mjs)|
|??|[browser-extension/tools/qa-demo.mjs](../browser-extension/tools/qa-demo.mjs)|
|??|[browser-extension/workspace.html](../browser-extension/workspace.html)|
|??|[browser-extension/workspace.mjs](../browser-extension/workspace.mjs)|
|??|[docs/DEVELOPMENT_PROGRESS.md](../docs/DEVELOPMENT_PROGRESS.md)|
|??|[docs/EXTENSION_SYNC_OPERATIONS.md](../docs/EXTENSION_SYNC_OPERATIONS.md)|
|??|[docs/FINAL_QA_REPORT.md](../docs/FINAL_QA_REPORT.md)|
|??|[docs/LOCAL_QA_GUIDE.md](../docs/LOCAL_QA_GUIDE.md)|
|??|[docs/SCOPE_TRACEABILITY.md](../docs/SCOPE_TRACEABILITY.md)|
