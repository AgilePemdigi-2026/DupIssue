# Panduan mencoba FINAL QA lokal

## Feature 5 final checkpoint - 26 November 2026

> This checkpoint supersedes earlier statements concerning
> current test totals, opened-only webhook scope and required
> GitHub App permissions. Previous checkpoints are retained
> as historical records. Merge, deployment and formal
> acceptance remain pending.

### Feature 5 automated tests

Run only in an appropriately isolated testing environment.
Do not reuse production or historical database credentials.

    cd backend
    php artisan test --filter=GithubInboundStatusSynchronizationTest
    php artisan test --filter=GithubOutboundStatusSynchronizationTest
    php artisan test --filter=GithubOutboundStatusWorkerTest
    php artisan test --filter=GithubAppRepositoryReplacementTest

Final PHPUnit verification used an isolated source snapshot,
SQLite in-memory testing and restricted network access.

### Actual QA summary

- Full PHPUnit: 682 tests, 680 passed, 2 skipped.
- Actual GitHub integration: 10/10 scenarios passed.
- Actual MySQL 8.0.46 mutex verification: 11 recorded checks passed.
- Final queue state: zero pending and zero failed jobs.

The temporary actual QA runtime is no longer running.

### Future live QA prerequisites

Obtain explicit approval for external GitHub writes and use
dedicated testing repositories.

Use a fresh isolated database, authorized development App,
protected webhook secret, scoped repository access, queue
worker and scheduler.

Feature 5 requires Issues read-and-write permission and the
Issues event subscription. Confirm permission updates have
been accepted by the installation owner.

The restored read-only development configuration is not
sufficient for outbound status synchronization.

Verify bidirectional close and reopen, status history,
webhook redelivery, repository replacement, historical
repository rejection and automatic new-repository ingestion.

Restore App settings and stop isolated services afterwards.
Preserve evidence in a Git-ignored local location.

Do not expose credentials or commit private QA databases.

See FINAL_QA_REPORT.md and EXTENSION_SYNC_OPERATIONS.md
for current results and operational limitations.

---

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

## Actual GitHub draft-capture defect — 25 November 2026

Owner-reported Chrome for Testing actual-site results (no issue submitted): installation,
Reporter login/pairing/identity, repository detection valentinokevin/dupissue-testing,
Integration Checkout mapping, workspace and explicit consent PASS. Native title
`Checkout payment fails` and description `Clicking the payment button does not complete
checkout.` are filled, but workspace remains **Isi draft pada tab GitHub yang terhubung**.
Actual draft capture and Top3 display FAIL; functional fix and actual retest remain pending.

Root-cause audit so far, not a final diagnosis:
- That literal label is WAITING_DRAFT, assigned on enable. A received empty/unsupported
  draft instead transitions to INVALID_DRAFT; valid capture transitions WAITING/LOADING.
  Therefore selector mismatch alone does not explain the reported unchanged label if the
  content script/message path is functioning. Missing/stale injection, message authorization,
  source-tab binding and delivery must be checked before attributing the defect to selectors.
- Existing selectors accept only input name issue[title]/IDs issue_title or issue-title,
  and textarea name issue[body]/IDs issue_body or issue-body. Real DOM metadata is pending.
  Existing browser fixtures use issue-title/issue-body and legacy names. The VM content
  test returns fields based on selector prefix, not real CSS matching: it cannot validate
  actual GitHub selector compatibility.
- Content reads fields only after captureState confirms consent; observes input, turbo:load,
  popstate and500ms polling. No explicit change listener; polling may cover value updates.
  Worker state is keyed by source tab ID; messages require own extension/top frame/GitHub
  repository consistency. Workspace fixes source tab from its URL; opening currently
  resolves active/currentWindow in the worker. Multiple-window behavior needs reproduction.
- Existing draft capture is retried per consent epoch; form replacement is polled. Worker
  navigation resets consent/results. Recommendation debounce500ms uses AbortController
  plus revision checks against stale results; API validates draft before request and uses
  only the configured loopback origin for QA. No backend/NLP cause established.
-21/21 existing targeted extension tests passed with node --test browser-extension/tests/*.test.mjs.
  This is baseline evidence, not proof of live compatibility or a repaired defect.
- QA package host18080/content-script registration/source parity PASS. Actual repository
  connection remains correct, historical issue fingerprint unchanged. No cookies, tokens,
  personal credentials or personal browser profile read for this defect audit.

Requested manual evidence: whether GitHub was reloaded after extension install/reload;
Inspect each actual title/description control and provide only tag,id,name,type,role,
aria-label,placeholder,data-testid,value length,visibility and current selector match counts.
Do not provide values, outerHTML/full DOM, cookies, storage or a HAR. Also report whether
DevTools Sources > Content scripts contains DupIssue content.js. This evidence is needed
before selecting a minimal fix and deriving representative DOM regression fixtures.
No speculative selector expansion, functional source edit or rebuild has been performed
at this checkpoint. Do not rerun the old qa-demo/qa-browser harness against this live
session: those tools route GitHub to a fixture and use the earlier interactive DB accounts.

UI observation recorded separately: shared body max-width740px and initial popup window
width430px explain narrow content in a wide browser window. Redesign deferred until the
functional defect is resolved. OAuth retrospective trace gap remains unchanged.

## Post-connection audit and Chrome preparation — 25 November 2026

**Repository connection PASS; OAuth end-to-end trace NOT FULLY VERIFIED.** The user
reported returning directly from Hubungkan GitHub with the saved-connection banner.
Read-only DB audit finds exactly one connection (id1/project1 Integration Checkout),
installation183282780, repository1452856825, valentinokevin/dupissue-testing,
verified_at/created_at25November2026at16:02:25WIB. Project owner is local Maintainer1;
verified GitHub user212716235 matches the actual public valentinokevin identity API.
last_synced_at remains null. Existing issue/project/membership/legacy-config fingerprints
match pre-OAuth evidence. No mapping was inserted by the audit or diagnostic scripts.

Evidence boundaries:
- Source startConnection redirects to local authorize; beginAuthorization creates random
  state and PKCE S256, then redirects to github.com/login/oauth/authorize.
- Callback requires matching state hash, actor/project ownership, expiry, verifier and
  code, consumes pending state, exchanges code+verifier at GitHub, verifies user/installations/
  repository access, and issues a repository-scoped token before saving. A single available
  repository auto-saves on a new connection, explaining absence of the repository picker.
- These are source guarantees and strong inference from the resulting saved connection,
  NOT retained per-request runtime evidence of the actual redirect, callback, state equality,
  challenge/verifier use or token exchange. Native access logging is suppressed; application
  log content is redacted. No OAuth trace was collected before the user's action.
- Maintainer session last activity matches16:02:25WIB, with pending OAuth/verified-repository
  state absent. Session browser family is Safari. Scoped Chrome history has no matching
  entries; scoped Safari history could not be read. No raw URLs/query values were printed.
- Fresh integration bootstrap has0HTTP fake callbacks and0fixture/local-app files loaded;
  source audit finds no fake in the active application/bootstrap/launcher. This fresh check
  is not a retrospective measurement of the prior request. No bypass or synthetic mapping
  was found; a blanket retrospective proof is not claimed.
- Existing GitHub authorization can skip the consent prompt, per
  [GitHub's tutorial](https://docs.github.com/en/apps/creating-github-apps/writing-code-for-a-github-app/building-a-login-with-github-button-with-a-github-app).
  This is the likely explanation, not confirmed consent-history evidence for this session.
  Do not repeat OAuth or revoke existing authorization merely to display a prompt.

Old tunnel stopped automatically25November2026at16:13:34WIB. Existing verified binary and
wrapper opened **https://qa-tunnel.invalid/api/github/webhook**,
expiring **25 November 2026 16:47:04 WIB**. Only upstream127.0.0.1:18081; public login,
extension API and database paths404, GETwebhook405, invalidHMAC401. **Manual GitHub webhook
URL update is required**; App settings still refer to the previous hostname until the owner
updates them. Do not request redelivery. No OAuth change is required for this URL update.

Laravel/worker/scheduler remain in the correct worktree with QA_GITHUB_NETWORK=1; gateway
and NLP remain available. Real-model NLP health/candidate smoke200, identical-text score1.
QA extension rebuilt using `node browser-extension/tools/build-local.mjs --qa`: loopback18080
API origin/host permission and16copied source-file parity checks PASS. Reporter membership
on project1 is active; project1 has3completed synthetic issues as recommendation candidates.
This is preparation, not a claim that live Chrome/GitHub extension behavior passed.

Manual Chrome installation/pairing:
1. Open chrome://extensions, enable Developer mode, Load unpacked, select the worktree-root
   `.local-extension` folder (use Cmd+Shift+G in the folder chooser to enter its absolute
   path). If already installed, reload that QA extension; verify DupIssue (LOCAL ONLY).
2. In Chrome open http://127.0.0.1:18080/login. Use the `reporter` and `password` fields from
   ignored `.qa/github-integration/accounts.json` locally. Preserve the Maintainer Safari
   session; do not copy credentials, pairing codes, tokens or browser storage into chat.
3. Open the extension popup, Connect to DupIssue, then on /extension/connect choose
   **Buat Pairing Code**. Paste the code into the popup's Pairing code field and click
   **Connect** within5minutes. Expected identity: Integration QA Reporter.
4. Stop at pairing confirmation for runtime verification. The later live checklist opens
   https://github.com/valentinokevin/dupissue-testing/issues/new (do not submit), opens
   **Buka ruang rekomendasi**, verifies repository/project mapping, then deliberately clicks
   **Aktifkan rekomendasi** before transmitting draft text. Top3/model output, vote/unvote,
   detail and disconnect remain pending actual execution. Full OAuth trace remains an
   explicit evidence gap and is not silently promoted to PASS by extension preparation.

Private evidence: `.qa/github-integration/post-oauth-audit.json`. No production/source/NLP
architecture edits, OAuth replay, revocation, issue creation, redelivery or access changes.

## Actual GitHub authentication verified — 25 November 2026

This checkpoint supersedes older credential-gate statements below. The owner explicitly
authorized development credentials and reported saving the App settings, preserving both
existing callback URLs and adding the QA callback. Unknown rollback SSL/event/old-secret
fields remain null. After Webhook Active was enabled, the owner observed all event
checkboxes empty before selecting Issues; this does not verify previously hidden settings.
Exact restoration of the unknown previous webhook secret cannot be guaranteed.

Actual API evidence: App JWT authentication PASS; personal installation identity and
selected-repository mode PASS; Issues/Metadata read permission PASS; repository identity
and issue-read access PASS. Installation ID **183282780**, repository ID **1452856825**,
repository **valentinokevin/dupissue-testing**. A short-lived installation token was issued
with read access scoped to this repository, and its accessible repository list contained
exactly that one repository. Token expiry: **25 November 2026 16:57:47 WIB**. No token,
JWT, secret, key material or raw API response was saved in evidence or printed.
The one-off ignored diagnostic uses a fixed endpoint allowlist; application outbound
policy and production code were not changed.

Laravel18080, gateway18081, NLP18765, database worker and scheduler are running in this
worktree. QA_GITHUB_NETWORK=1 verified on Laravel/worker/scheduler; isolated SQLite,
6 completed synthetic issues, 0 GitHub connections, 0 queued/failed jobs. Scheduler tick
and actual-model NLP health/candidate smoke PASS. Fingerprints of issues, projects,
memberships and connection/config history match the pre-OAuth baseline.

Current webhook: `https://qa-tunnel.invalid/api/github/webhook`.
The existing tunnel remained active when probed; bounded expiry **25 November 2026
16:13:33 WIB**. Never reuse it after expiry. GitHub webhook configuration API confirms
this URL and TLS verification enabled; App API confirms Issues subscription. Public
login/extension/database paths return404; GET webhook405; invalid HMAC401 with actual
configured secret. Recent deliveries API confirms ping at15:47:50 WIB and installation
`new_permissions_accepted` at15:49:19 WIB, both202. These are not automatic issue-sync
proof. The application intentionally ignores these events without inserting issue-delivery
rows; current delivery/task counts are0. No redelivery was requested.

**Manual OAuth checkpoint:** open http://127.0.0.1:18080/login. Read the `maintainer`
and `password` fields locally from ignored `.qa/github-integration/accounts.json`;
never paste them into chat. Open Integration Checkout (project1), then
http://127.0.0.1:18080/projects/1/sources. In the GitHub section click **Hubungkan GitHub**,
authorize the designated App as valentinokevin, and return to127.0.0.1. With exactly one
verified repository the new connection can save automatically. Otherwise choose only
valentinokevin/dupissue-testing and click **Simpan Koneksi GitHub App**. Expected banner:
**Koneksi tersimpan: valentinokevin/dupissue-testing**. Do not widen installation access.
OAuth has NOT yet been performed. After user completion, compare saved installation/repo
IDs with API evidence, verified_at, project owner authorization and preserved history.
No synthetic verified mapping was inserted.

The existing QA extension build is at worktree-root `.local-extension` (not inside
browser-extension); manifest host permission and API origin both target127.0.0.1:18080.
Actual GitHub extension pairing/draft/recommendation/vote/unvote/detail/disconnect tests
remain pending actual connection. No actual issue was created; no source/NLP architecture
change, permission/access change, redelivery, commit, staging, push, PR or deployment.
Private sanitized evidence: `.qa/github-integration/actual-api-evidence.json`,
`pre-oauth-history.json`, and `credential-activation-review.json`.

## Current execution — public ingress tested, credential gate (24 November 2026)

This section supersedes older runtime status below. **Public HTTPS transport/security
PASS with synthetic data; actual GitHub App/OAuth/import/real DOM/Edge remain PENDING.**

### Temporary URL, lifecycle and integrity

Current webhook: **https://qa-tunnel.invalid/api/github/webhook**.
Started23:19:33WIB; automatic shutdown **24November2026 23:49:33WIB** (16:49:33UTC).
Do not configure an expired hostname. Restart creates a different hostname. Earlier
apr-reports-fold-results hostname was stopped to verify shutdown; it is obsolete.

[quick-tunnel.mjs](../backend/tests/qa/integration/quick-tunnel.mjs) fixes the verified
binary/hash and target127.0.0.1:18081, private empty config, loopback metrics18082,
minimal environment without inherited credentials/proxy/TUNNEL variables, no auto-update,
30-minute lifetime, SIGTERM with5s SIGKILL fallback. It drains but never persists raw
provider logs; only hostname/PIDs/timestamps/status are saved. No account/DNS/global
installation/system change. Quick Tunnel has no availability guarantee.

Current wrapper PID79522, cloudflared PID79523. For early stop, first verify
`ps -p 79522 -o pid=,command=` identifies this Node wrapper, then `kill -TERM 79522`.
Never blindly reuse an expired PID. Session state is private/ignored:
`.qa/tunnel-sessions/1795537173519-ad8ee787/state.json`.
Actual manual SIGTERM test confirmed both wrapper and child exited before restarting.
Automatic expiry is implemented; full30-minute interval has not elapsed at checkpoint.

Restart only for the approved temporary QA session, after verifying gateway readiness
and no existing tunnel/metrics listener:

```sh
node backend/tests/qa/integration/quick-tunnel.mjs
```

Official [cloudflared2026.11.2 release](https://github.com/cloudflare/cloudflared/releases/tag/2026.11.2):
workspace-only `.qa/tools/cloudflared-2026.11.2/cloudflared`, macOS ARM64.
Archive SHA256 `f6d58eeec91ad1ec5fd1f7e4a587303772929b2625be5d24068033851c36ff55`
matches official API asset digest. Extracted executable SHA256
`8654fbdc9057f3e13e95f7892f417f8643113bee1b91ee2388fd0acb05210d55` matches release
notes. The two digests describe different files. Strict macOS codesign verification
PASS, Cloudflare Inc. team68WVV388M8. Archive retained; no deletion. Download19.9MB,
executable approximately39MB; available disk6.8GiB.

### Actual evidence at this checkpoint

| Check | Actual result |
| --- | --- |
| Gateway deadline | Changed additive10s body+8s upstream to8s absolute TCP-acceptance budget, including headers/body/upstream;5sbody sublimit;100msforced-close grace |
| Gateway security |14/14; two new cases prove incomplete-header and combined-stage deadline |
| Combined Node |71/71,69leaf tests/scenarios+2parents,0failure/cancel/skip,7.9805s |
| First public hostname |14/14 probes with separate test DB and synthetic HMAC secret; no actual App credentials |
| Public route/method protection |UI/extension/callback/database/env/route variants404, GETwebhook405 |
| Synthetic public signature |Missing422, invalid401, valid signed ping202IGNORED, signed unconnected issue403 |
| Probe database after requests |0deliveries/0jobs/0GitHubissues/0sync_logs/0connections |
| Final hostname, main backend restored |10/10 probes; restricted routes/method denied; POST503NOT_CONFIGURED because credentials remain disabled |
| Main integration database |6COMPLETEDsyntheticissues,0connections/0jobs/0failed_jobs |
| Services |Laravel18080/gateway18081/NLP18765/worker/scheduler running; GitHub disabled;8000untouched |
| Live local NLP smoke |HTTP200 and identical-text similarity1.0, existing offline model |

Public fixture response samples52–810ms; main samples56–4010ms (first request4010ms).
One sample per case is not a percentile or throughput benchmark. Local8s deadline
does not guarantee Internet end-to-end10s; failures do not receive a false202.
HMAC401 evidence is synthetic-only. Ping is not automatic import proof.
Accepted PHP614/612passed/5100assertions/2skips,NLP29,MySQL9,Chrome7fixture remain prior
results, not rerun full suites. Final full regression follows actual integration/fixes.
NLP architecture unchanged; cold≈10.49s, backlog/SRS gaps and Technical Lead review persist.
Private sanitized evidence: `.qa/integration-execution-node.log`,
`.qa/public-ingress-results.json`, `.qa/public-main-ingress-results.json`.

### Your next steps — existing credentials and App settings

1. Sign in to GitHub yourself. Settings → Developer settings → GitHub Apps →
   **dupissue-kel4-dev-valentinokevin → Edit**. Confirm it is not actively used by
   teammates or another service; pause changes if shared/in use.
2. Before saving changes, record NONSECRET settings in the prepared private
   [rollback template](../.qa/github-integration/app-settings-before-qa.json): current
   URLs, webhook active/SSL flags, permissions/events, OAuth-during-install,
   redirect-on-update and installation access mode. Values are null until you fill
   them. Never put secrets there or share secret-containing screenshots. Retain
   existing secret values in your own secure local store for restoration.
3. Edit private [github-config.json](../.qa/github-integration/github-config.json)
   locally. Find App ID and Client ID on this App's settings page. App ID is numeric,
   different from installation ID. Credentials/Client secrets identifies existing
   OAuth credentials; retrieve the existing secret from your secure local record.
   Credentials → Key pairs (Private keys in older UI) shows key metadata; use the
   corresponding previously downloaded PEM. GitHub retains only the public key.
   Webhook settings indicate where the secret is configured; retrieve its existing
   value from your secure record. Do not generate/revoke/replace any credential.
   If a credential is unavailable, report only which item is missing, without value.
4. Supply existing development PEM yourself at exact path
   `/Users/valentinokeviniversen/Developer/dupissue-github-enhancement/.qa/github-integration/private-key.pem`.
   Fill these7JSON keys (no shell-variable literals in JSON):

   | Key | Value/source |
   | --- | --- |
   | GITHUB_APP_ID | Numeric App ID from designated development App |
   | GITHUB_APP_CLIENT_ID | Client ID from same App |
   | GITHUB_APP_CLIENT_SECRET | Existing OAuth secret, local only |
   | GITHUB_APP_SLUG | dupissue-kel4-dev-valentinokevin |
   | GITHUB_APP_CALLBACK_URL | http://127.0.0.1:18080/integrations/github/callback |
   | GITHUB_APP_PRIVATE_KEY_PATH | Exact absolute PEM path above |
   | GITHUB_APP_WEBHOOK_SECRET | Existing ≥32-character secret matching App webhook settings |

   After placing the key, run from worktree root:

   ```sh
   chmod 700 .qa/github-integration
   chmod 600 .qa/github-integration/github-config.json .qa/github-integration/private-key.pem
   ```

5. Prepare these App settings only after backup/no-shared-use confirmation: retain
   valid existing Homepage URL (owner page https://github.com/valentinokevin is an option);
   Callback `http://127.0.0.1:18080/integrations/github/callback`;
   Setup `http://127.0.0.1:18080/integrations/github/setup`;
   Webhook current temporary URL above; SSL verification ON; Issues Read-only;
   Metadata Read-only; subscribe Issues; OAuth during installation OFF;
   Redirect on update OFF. Do not broaden unrelated permissions. Installation must
   select only **valentinokevin/dupissue-testing**, never all repositories. Start
   installation through DupIssue's existing flow so session/state/PKCE are valid.
6. Confirm without values: files prepared, rollback recorded, App safe to use,
   settings prepared/saved, and permission to use the development credentials.
   I will structurally validate without value output, restart Laravel/worker/scheduler
   with `QA_GITHUB_NETWORK=1`, then guide Maintainer Sources → Connect GitHub App →
   your manual authorization. Saving webhook settings early can trigger ping503
   NOT_CONFIGURED until activation; coordinate activation before relying on delivery.

References: [App credentials](https://docs.github.com/en/rest/authentication/authenticating-to-the-rest-api),
[private keys](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/managing-private-keys-for-github-apps),
[GitHub10s response guidance](https://docs.github.com/en/webhooks/using-webhooks/best-practices-for-using-webhooks),
[Quick Tunnel lifecycle](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/).

### Browser and remaining gates

QA build refreshed: `browser-extension/.local-extension`, fixed API18080. Chrome for
Testing available in `.qa/chrome-158`; use separate QA profile, never personal profile.
chrome://extensions → Developer mode → Load unpacked → QA build. After actual App
connection: synthetic Reporter login18080 (private `.qa/github-integration/accounts.json`),
popup Connect Extension → pairing/approve → actual testing repository New Issue →
explicit consent → synthetic title/body without submitting. Verify mapping, Top3/scores
inside extension-owned workspace, vote/unvote/detail, dynamic navigation, authorized
project switching, disconnect and native form. Fixture runner is not actual DOM proof.
Edge not found in /Applications; no download/install authorized.

Later gates: ONE issue creation approval, webhook redelivery approval, second-repository
and installation-access approval for replacement (current allowlist only first repo),
Edge download, missing-credential generation, temporary settings restoration. Stop
tunnel after testing and restore only approved settings. No reverse synchronization.

## Latest — integration QA environment implemented, 24 November 2026

**PASS_LOCAL readiness; GitHub HTTP tetap fake dalam test. Actual GitHub/DOM/Edge
belum diuji.** Launcher integration dan webhook gateway kini diimplementasikan
terpisah dari fixture launcher. Experimental NLP cache tidak diubah.

Status akhir: Laravel **127.0.0.1:18080**, gateway **127.0.0.1:18081**, FastAPI
offline **127.0.0.1:18765**, database worker dan scheduler QA berjalan lokal.
GitHub outbound **disabled**; tidak ada public tunnel atau credential dev aktual.
Port8000/worktree lain tidak disentuh. DB baru `.qa/github-integration/database.sqlite`:
6 synthetic issues COMPLETED, 0 GitHub connections, 0 jobs, 0 failed_jobs.
DB fixture existing tetap9issues/9completed dan checksum-nya tidak berubah selama
test. Disk available6.9GiB setelah pengujian; tidak ada penghapusan/download model.

### Implementasi dan commands

- [integration-app.php](../backend/tests/qa/integration-app.php): entry point init,
  serve, status, worker, drain, schedule, scheduler, nlp-smoke, check-config.
- [Environment.php](../backend/tests/qa/integration/Environment.php): private paths,
  forced SQLite/storage/session/queue/key, config validation, recovery cadence.
- [OutboundPolicy.php](../backend/tests/qa/integration/OutboundPolicy.php): exact
  methods/hosts/ports/routes dan bounded pagination; default hanya NLP loopback.
- [webhook-gateway.mjs](../backend/tests/qa/integration/webhook-gateway.mjs): dependency-free
  Node gateway; CLI fixed loopback18081 → loopback18080, tidak menerima target arg.
- [Configuration template](../backend/tests/qa/integration/github-config.example.json):
  nama setting dan nilai nonsecret tetap; field credential kosong.

Dari root worktree; gunakan PHP8.3. **Jangan jalankan server kedua** jika port sudah
dipakai. `init` sudah dijalankan; pemanggilan ulang menolak overwrite secara aman.
Jangan memakai fixture `local-app.php` bersamaan pada18080. Untuk menghentikan server,
gunakan Ctrl-C pada proses QA miliknya atau identifikasi PID/command sendiri dahulu;
jangan gunakan broad kill atau menghentikan8000. Launcher mengganti prosesnya dengan
PHP server, sehingga shutdown tidak meninggalkan child server.

```sh
# Hanya untuk worktree baru yang belum memiliki .qa/github-integration:
# /opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php init

# Terminal terpisah; default mode ini tidak membaca credential GitHub.
QA_GITHUB_NETWORK=0 /opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php serve
node backend/tests/qa/integration/webhook-gateway.mjs
QA_GITHUB_NETWORK=0 /opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php worker
QA_GITHUB_NETWORK=0 /opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php scheduler

# Readiness/status dan bounded worker; jangan paralel dengan worker lain untuk test.
/opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php status
/opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php nlp-smoke
/opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php schedule
/opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php drain
node browser-extension/tools/build-local.mjs --qa
```

FastAPI offline command tetap tersedia pada bagian restart fixture di bawah; service
18765 yang sama digunakan, tanpa credentials/download. `worker` dibatasi2jam;
`drain` maksimal20jobs/2jam/stop-when-empty. `scheduler` dibatasi2jam, tick30detik,
webhook recovery60detik/NLP300detik/prune3600detik dengan DB lock dan cadence state.
Semua command berjalan dalam bootstrap terjaga; tidak ada child artisan yang membaca
environment aktual. Initial6issues berstatus PENDING, dipulihkan saat stale≥5menit;
run aktual recovery dan worker sudah menyelesaikannya. Scheduler tidak mengulang FAILED
NLP secara otomatis. Recovery bukan bukti kapasitas backlog5.000.

Akun sintetis integration berada di `.qa/github-integration/accounts.json` (0600),
berbeda dari akun fixture. Pengguna membuka file itu secara lokal; jangan kirim
password melalui chat. Login Reporter di18080 dapat membuka pairing page, tetapi
repository resolver tidak menyediakan project sebelum actual connection dibuat.
Tidak ada synthetic verified mapping dalam environment ini. Test-only OAuth fake
membuat mapping di `.qa/integration-tests/<random>/`, tidak di DB integration utama.

### Credential setup — hanya setelah persetujuan penggunaan credential

Initializer sudah membuat `.qa/github-integration/github-config.json` dari template
dengan credential kosong. Edit **file privat ini saja** secara lokal; jangan edit
tracked template atau `.env` development. Isi7nama `GITHUB_APP_*` pada tabel source
di bawah. Tempatkan dev PEM sebagai `.qa/github-integration/private-key.pem` dengan
permission0600, lalu isi `GITHUB_APP_PRIVATE_KEY_PATH` dengan absolute path file itu.
Jangan gunakan `${PWD}` literal di JSON. Directory0700; config/key/accounts/DB/logs
0600; symlink, hardlinked file dan file yang dapat dibaca group/others ditolak.

`check-config` hanya memvalidasi struktur/path/permissions, **bukan** kebenaran
credential pada GitHub; tidak mencetak value atau membaca isi PEM. Default startup
bahkan tidak mem-parse private GitHub config. Setelah credential use disetujui:

```sh
# FUTURE APPROVAL REQUIRED — belum dijalankan dengan credential development aktual.
/opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php check-config
# Restart masing-masing proses QA dengan flag ini setelah approval:
QA_GITHUB_NETWORK=1 /opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php serve
QA_GITHUB_NETWORK=1 /opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php worker
QA_GITHUB_NETWORK=1 /opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/integration-app.php scheduler
```

Flag tidak mengizinkan host/repo arbitrer: hanya OAuth token exchange, user/install
discovery dan exact repository testing endpoints sesuai policy. Proxy env diabaikan,
redirect dimatikan. Ini guard Laravel HTTP untuk source yang diaudit, bukan OS/network
sandbox untuk arbitrary PHP/cURL baru. Jangan menambah client yang melewati guard.
Application log menyimpan level/time dan marker `QA_APPLICATION_EVENT` saja; message,
context dan extra dibuang agar exception/SQL/OAuth values tidak bocor. PHP access log
disupresi oleh `serve`; jangan menggantinya dengan raw `php -S` saat OAuth aktual.
Status aman berada pada delivery/task/error_code/failed_jobs DB privat.

### Gateway security dan actual local evidence

Gateway hanya exact POST `/api/github/webhook`: tanpa query/trailing slash/encoded
alias/absolute target. Body maksimum1MiB termasuk chunked, header16KiB, body deadline
10s, upstream deadline8s, maksimum16in-flight/32connections. Hanya JSON UTF8 tanpa
content encoding; empty body/malformed required headers/duplicate headers ditolak.
Body diteruskan byte-for-byte; backend melakukan HMAC. Cookie/Authorization/forwarded
headers tidak diteruskan. Upstream response dibatasi4KiB dan hanya status/safe code
yang dikenal diteruskan, bukan HTML, redirect, Set-Cookie atau arbitrary JSON.
Tidak ada payload/header logging, generic proxy, secret gateway, atau public bind.
Laravel webhook throttle120/min tetap berlaku; semua traffic gateway terlihat dari
loopback dan berbagi bucket tersebut.

| Actual testing | Hasil |
| --- | --- |
| Full PHPUnit |614tests,612passed,5100assertions,2existing skips,0failure/error;16.688s |
| Existing website/extension JS |38/38 |
| Gateway security |12/12 cases: routes/methods/raw bytes/headers/limits/invalid input/HMAC result/upstream errors/timeouts/capacity |
| Dedicated launcher |5/5 scenarios; Node runner juga menghitung1parent test |
| Integration readiness |12/12 scenarios; Node runner juga menghitung1parent test; includes12 safety assertions/checks in guard scenario |
| Combined Node command |69/69 including2parent tests; bukan69 unique user flows |
| NLP unit/cache |29/29 |
| Live submit opt-in |1test/21assertions, actual offline model |
| Live web/extension parity subset |1test/6assertions; large-dataset F2 benchmark tidak diulang |

Readiness chain: synthetic OAuth PKCE dengan HTTP fake → legitimate application
connection persistence di test DB → real signed bytes melalui gateway → durable
delivery → real DB worker → GitHub metadata/issue HTTP fake → real offline NLP →
candidate persistence. Invalid signature401, valid nonempty ping202IGNORED,
redelivery idempotent, manual preview already-imported, auth/CSRF/pairing/vote/unvote/
disconnect, six stale pending jobs recovery dan cadence repeat semua lulus.
Perbedaan mock/live ini wajib dipertahankan; bukan actual GitHub integration PASS.

Reproduksi memerlukan18765 aktif dan18080/18081 **bebas**. Test suite menjalankan
server lokal sendiri lalu menghentikannya; simpan main environment, jangan reset DB.
Setiap readiness run menambah private test directory; tidak ada cleanup otomatis.

```sh
/opt/homebrew/opt/php@8.3/bin/php backend/vendor/bin/phpunit -c backend/phpunit.xml
node --test --test-concurrency=1 backend/tests/javascript/*.test.cjs \
  browser-extension/tests/*.test.mjs backend/tests/qa/integration/*.test.mjs
```

Raw sanitized results: `.qa/integration-final-phpunit.log`,
`.qa/integration-final-node.log`. Awal implementasi memperbaiki test ping kosong
(receiver memerlukan object nonempty), expectation preview200 dan lifecycle server
shutdown; final rerun lulus tanpa failure/cancellation. Tidak ada perubahan business
logic untuk menyesuaikan test. Prior MySQL9 dan Chrome7fixture tetap prior evidence.

### Temporary HTTPS provider recommendation — belum diinstal/diaktifkan

Audit PATH: `cloudflared`, `ngrok`, `devtunnel` tidak ditemukan; `ssh` tersedia.

| Opsi | Akun/hostname | Security/compatibility dan tradeoff |
| --- | --- | --- |
| **Cloudflare Quick Tunnel — rekomendasi** | Tanpa akun/domain; temporary random `trycloudflare.com` | HTTPS untuk webhook gateway; hostname berubah tiap sesi, tidak ada SLA, bounded200in-flight/provider. Tidak perlu SSE untuk webhook. Memerlukan binary ARM64 yang disetujui di workspace |
| ngrok | Account/authtoken; dev domain assigned ke akun | Host lebih stabil, tetapi tambahan account/credential. Review/disable local inspection dan full capture; jangan merekam payload issue |
| SSH reverse tunnel | Client tersedia; memerlukan server/account/HTTPS termination yang disetujui | Lebih banyak provisioning/operasional; bukan siap hanya karena ssh tersedia |

Dasar rekomendasi: [Cloudflare Quick Tunnels](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/),
[ngrok setup](https://ngrok.com/docs/start),
[ngrok traffic inspection](https://ngrok.com/docs/obs/traffic-inspection/http-requests),
[ngrok agent inspection config](https://ngrok.com/docs/gateway/agent/config/v2).
Keduanya menjadi perantara TLS/payload; gateway/signature tidak menghilangkan kebutuhan
persetujuan confidentiality terhadap provider. Login/email challenge pada ingress
tidak cocok untuk pengiriman GitHub server-to-server; gunakan signature backend.

Setelah approval provider, install binary resmi yang diverifikasi **hanya dalam
workspace**, tanpa mengubah config personal existing. Contoh FUTURE command, belum
dijalankan dan binary belum tersedia:
`.qa/tools/cloudflared tunnel --url http://127.0.0.1:18081 --no-autoupdate`.
Daftarkan hostname yang benar-benar dihasilkan + `/api/github/webhook`, jangan
menebak URL. Hanya gateway18081 boleh ditunnel, tidak18080/18765/8000. Approval harus
mencakup download binary, provider/data transit, durasi exposure, App settings dan
shutdown sesi. Secret dev dan pembuatan/redelivery issue memerlukan approval terpisah.

## Previous preparation audit — retained historical state

**PREPARATION COMPLETE; actual GitHub App, real GitHub DOM dan Edge belum PASS.**
Audit ini read-only terhadap source/runtime. Tidak ada credential GitHub dibaca atau
dipakai, public endpoint dibuka, konfigurasi App diubah, atau issue aktual dibuat.
Instruksi live di bawah adalah prosedur setelah approval, bukan hasil eksekusi.

| Pemeriksaan lokal aktual | Hasil |
| --- | --- |
| Disk `df -h .` | 7.9 GiB available; tidak ada cleanup/download |
| Git | `fix/github-app-repository-management`, HEAD `b62f83bef6746771d8c30745308162d1a0a52607`; 19 modified, 73 untracked, 0 staged |
| Laravel 18080 / FastAPI 18765 | Tidak sedang listening pada audit; perlu restart sebelum demo |
| Port 8000 | Dipakai proses PHP lain; tidak disentuh |
| Guarded QA status | SQLite sintetis: 9 issues, 9 COMPLETED, 0 jobs, 0 failed_jobs |
| Database dan file akun | Existing, permission 0600; password tidak dibaca/dicatat dalam audit |
| Local extension | API origin dan satu host permission `http://127.0.0.1:18080`; build existing dipertahankan |
| Chrome for Testing | Binary existing executable di `.qa/chrome-158/`; hasil 7 flow sebelumnya memakai HTML fixture |
| Edge | `/Applications/Microsoft Edge.app` tidak ditemukan; lokasi lain tidak diasumsikan, instalasi tidak dilakukan |

Baseline accepted dipertahankan: PHP 610/608 passed/5065 assertions/2 skips;
JS 38/38; NLP 29/29; live submit 1/21; live recommendation 3/56; MySQL 9/9.
Tidak mengulang regression karena perubahan checkpoint ini hanya dokumentasi.

## Environment yang sudah disiapkan

- Laravel: **http://127.0.0.1:18080**. Port8000 milik worktree lain tidak diubah.
- FastAPI model asli offline: **http://127.0.0.1:18765**.
- Database: `.qa/interactive/database.sqlite`, hanya fixture sintetis.
- Akun Reporter/Maintainer dan password acak: `.qa/interactive/accounts.json`
  (permission0600; tidak di-stage, tidak dimasukkan laporan atau Git).
- Dua project: QA Checkout dan QA Mobile Checkout. Mapping repository di QA
  adalah fixture sintetis, **bukan bukti installation GitHub App aktual**.
- Worker `default,nlp` memproses database QA. Sembilan issue seeded tersedia;
  satu job seeded telah selesai melalui FastAPI asli; jobs0/failed_jobs0.
- Chrome for Testing158.0.8127.88 ARM64 di `.qa/chrome-158/`, profil baru
  `.qa/manual-browser-profile`. Chrome/Edge pribadi tidak dipakai.

Launcher menolak config cache, mengisolasi environment/storage/database/session,
memblokir outgoing HTTP selain NLP loopback, dan hanya menerima host127.0.0.1:18080.
Jangan mengarahkan tunnel ke launcher ini; ini bukan environment GitHub live.

## Mencoba popup, pairing, rekomendasi dan voting

Demo sebelumnya membuka tiga tab: web DupIssue, popup extension dan form fixture.
Jalankan ulang layanan dan demo di bawah bila ingin mencoba sekarang.
Semua request GitHub dari profil demo ini diintersep menjadi fixture lokal.
Banner halaman menjelaskan bahwa ini bukan GitHub aktual; tidak ada submit issue.

1. Buka file akun lokal di atas. Login di web sebagai Reporter.
2. Login existing mengarah ke daftar project. Buka kembali
   http://127.0.0.1:18080/extension/connect atau klik **Connect to DupIssue** lagi.
3. Klik **Buat Pairing Code**. Salin kode ke tab popup dan klik **Connect**.
4. Pindah ke tab **DupIssue QA — GitHub form fixture**. Klik ikon puzzle/Extensions
   pada toolbar Chrome, pilih **DupIssue (LOCAL ONLY)**, lalu
   **Buka ruang rekomendasi**. Klik toolbar pada tab ini memberi izin activeTab;
   membuka popup sebagai tab saja tidak memberi izin tersebut.
5. Susun jendela rekomendasi dan jendela draft berdampingan. Pilih **QA Checkout**,
   baca pemberitahuan pengiriman draft, lalu **Aktifkan rekomendasi**.
6. Isi judul `Checkout payment fails` dan deskripsi
   `Clicking the payment button does not complete checkout.`
   Top3 memakai cosine model asli; teks identik mendapat sekitar1,0.
7. Coba **Vote Issue**, **Unvote**, dan **Lihat detail**. Ganti project untuk
   memastikan hasil lama hilang dan consent harus diaktifkan kembali.
8. Klik **Disconnect** pada popup. Coba login Maintainer dari sesi browser terpisah
   jika ingin memeriksa tampilan pengelolaan project; jangan mengubah mapping ke
   GitHub nyata dalam environment fixture ini.

Popup adalah koneksi singkat; workspace terpisah tetap terbuka saat fokus berada
pada draft. Dengan dua jendela berdampingan, rekomendasi tetap terlihat ketika
mengetik. Pada layar kecil, gunakan split view/resize; kartu memerlukan scroll.
Workspace tidak always-on-top dan dapat tertutup jendela lain. Ini tradeoff UX
yang nyata, bukan alasan memasukkan data privat ke DOM GitHub.

Screenshot browser aktual (fixture sintetis, tanpa token/kode terlihat):
[popup](../.qa/screenshots/popup-connected.png),
[pairing dengan kode disamarkan](../.qa/screenshots/pairing-page.png),
[rekomendasi](../.qa/screenshots/recommendations.png).

## Menjalankan ulang environment

Jalankan dari root worktree. Jangan menjalankan `init` lagi pada direktori yang
sudah ada; launcher sengaja menolak overwrite. Tidak ada perintah cleanup/reset.

```sh
# Terminal 1 — model offline, CPU dua thread
cd nlp-service
HF_HUB_OFFLINE=1 TRANSFORMERS_OFFLINE=1 HF_HUB_DISABLE_IMPLICIT_TOKEN=1 \
HF_HUB_DISABLE_TELEMETRY=1 HF_HOME="$HOME/.cache/huggingface" \
OMP_NUM_THREADS=2 MKL_NUM_THREADS=2 TOKENIZERS_PARALLELISM=false \
../.qa/nlp-venv/bin/python -m uvicorn main:app --host 127.0.0.1 --port 18765

# Terminal 2 — dari root worktree
/opt/homebrew/opt/php@8.3/bin/php -S 127.0.0.1:18080 \
  -t backend/public backend/tests/qa/local-app.php

# Terminal 3 — worker (berhenti otomatis setelah maksimal dua jam)
/opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/local-app.php worker

# Recovery tick, menjalankan actions dalam proses dengan guard yang sama
/opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/local-app.php schedule

# Status dan browser demo, dari root worktree
/opt/homebrew/opt/php@8.3/bin/php backend/tests/qa/local-app.php status
node browser-extension/tools/build-local.mjs --qa
node browser-extension/tools/qa-demo.mjs
```

Jangan meluncurkan server/worker kedua jika proses di port yang sama masih aktif.
Demo bertahan sampai browser ditutup. Server hanya loopback; tidak ada public tunnel.
Recovery tick sudah diuji manual; scheduler daemon kontinu belum dijalankan.

## Reproduksi automation dan benchmark

Playwright-core1.65.1 adalah tooling QA lokal di `.qa/browser-tools`, bukan runtime
dependency aplikasi. Browser diunduh dari distribusi resmi Chrome for Testing;
tidak memakai akun atau profil existing. Basis API automation:
[Playwright extensions](https://playwright.dev/docs/chrome-extensions) dan
[Chrome extension action](https://chromedevtools.github.io/devtools-protocol/tot/Extensions/#method-triggerAction).
Flag debugging extension hanya digunakan pada profil automation baru.

```sh
node browser-extension/tests/qa-browser.mjs
# PID harus PID server NLP QA milik sesi ini; angka di bawah adalah placeholder.
QA_NLP_PID=<pid-nlp-qa> .qa/nlp-venv/bin/python backend/tests/qa/benchmark-nlp.py
```

Default regression tetap SQLite in-memory dan NLP fake. Live opt-in memakai
`NLP_LIVE_TEST_URL=http://127.0.0.1:18765` untuk
`SubmitIssueQueueIntegrationTest::test_submit_database_worker_and_live_fastapi`
dan `tests/qa/LiveRecommendationTest.php`. Jangan menggunakan production URL.
Benchmark tiga request per ukuran bukan estimasi p95 yang kuat. Snapshot model:
`e8f8c211226b894fcb81acc59f3b34ba3efd5f42`; bobot safetensors SHA256
`eaa086f0ffee582aeb45b36e34cdd1fe2d6de2bef61f8a559a1bbc9bd955917b`.

## Actual Chrome/Edge dan GitHub DOM — masih manual

Automation lulus di Chrome for Testing dengan GitHub document fixture. Ini tidak
membuktikan selector pada DOM GitHub terbaru, semua template, branded Chrome atau
Microsoft Edge. Untuk QA tersebut, gunakan profil kosong, local build `--qa`, dan
checklist browser-extension/README. Login GitHub dilakukan pengguna sendiri hanya
setelah menyetujui akses akun. Tidak perlu membuat issue untuk menguji typing/vote.

## GitHub App actual integration — persiapan, belum dijalankan

Target hanya App `dupissue-kel4-dev-valentinokevin` dan repository
`valentinokevin/dupissue-testing`. Konfigurasi external belum dibaca melalui akun;
daftar ini adalah kebutuhan yang harus diverifikasi, bukan klaim setting existing.

### 1. Konfigurasi App yang harus diverifikasi pengguna

Source acuan: [routes web](../backend/routes/issue_sources.php),
[routes API](../backend/routes/api.php), [API prefix](../backend/bootstrap/app.php),
[connection controller](../backend/app/Http/Controllers/GithubAppConnectController.php),
[service config](../backend/config/services.php).

| Setting GitHub App | Nilai untuk rancangan integration QA |
| --- | --- |
| App slug | `dupissue-kel4-dev-valentinokevin` |
| Homepage URL | `https://github.com/valentinokevin` dapat dipakai sebagai halaman pemilik; source tidak mensyaratkan route homepage khusus |
| Callback URL | `http://127.0.0.1:18080/integrations/github/callback` — exact path, tanpa query/fragment |
| Setup URL | `http://127.0.0.1:18080/integrations/github/setup` |
| Request user authorization (OAuth) during installation | **OFF**: source menangani Setup URL dahulu lalu OAuth/PKCE sendiri setelah instalasi |
| Redirect on update | **OFF**: setup handler menerima `install` atau action kosong, bukan `update` |
| Device flow | Tidak digunakan; tidak perlu diaktifkan |
| Webhook URL | `https://<approved-webhook-host>/api/github/webhook` — hostname belum dipilih/disediakan; placeholder bukan endpoint aktif |
| Webhook | Active, SSL verification aktif, secret acak minimal 32 karakter |
| Repository permissions | **Issues: Read-only**, Metadata read; tidak membutuhkan Contents/write permission untuk source yang diaudit |
| Event subscription | **Issues**; receiver hanya mengimpor action `opened` |
| Installation access | Only selected repositories: **valentinokevin/dupissue-testing** saja |

Issues harus tepat `read`: pemeriksaan installation pada source menolak nilai
`write`, walaupun permission itu lebih luas. Jangan mengubah App bersama tanpa
memeriksa dampak ke pemakai development lain dan approval pemiliknya.
Mulai koneksi dari halaman Sources milik Maintainer, bukan membuka Setup URL secara
langsung. Session pending/state/PKCE dan identitas pengguna wajib valid. Alur awal
melakukan OAuth discovery; bila belum terinstal, lanjut install → Setup → OAuth.
Satu repository terverifikasi dapat otomatis disimpan pada koneksi baru; replacement
memerlukan konfirmasi dan snapshot koneksi masih sama.

GitHub mendokumentasikan callback lokal saat development, pilihan homepage, dan
hubungan pilihan OAuth-during-installation dengan Setup URL:
[registering an App](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/registering-a-github-app),
[local development callback](https://docs.github.com/en/apps/creating-github-apps/writing-code-for-a-github-app/about-writing-code-for-a-github-app),
[Setup URL](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/about-the-setup-url).
Callback/setup adalah navigasi browser pada Mac pengguna, bukan server GitHub
menghubungi loopback. Verifikasi penerimaan URL pada form App saat konfigurasi
disetujui; jika Setup URL loopback ditolak, berhenti untuk meninjau alternatif TLS
terbatas. Jangan otomatis mengekspos seluruh Laravel. Gunakan `127.0.0.1` konsisten,
bukan campuran `localhost`, hostname publik dan loopback, agar session tetap sama.

### 2. Environment variable names yang benar

Nilai rahasia diisi pengguna secara lokal, tidak melalui chat atau command history.
Jangan membaca `.env` development atau menampilkan PEM untuk menyiapkan QA.

| Nama | Isi / batas |
| --- | --- |
| `GITHUB_APP_ID` | Numeric App ID dari settings dev App, bukan installation ID |
| `GITHUB_APP_CLIENT_ID` | Client ID dev App; dipakai OAuth dan issuer JWT source |
| `GITHUB_APP_CLIENT_SECRET` | Secret OAuth dev App, hanya file/proses QA privat |
| `GITHUB_APP_SLUG` | `dupissue-kel4-dev-valentinokevin` |
| `GITHUB_APP_CALLBACK_URL` | Exact callback loopback di tabel sebelumnya |
| `GITHUB_APP_PRIVATE_KEY_PATH` | Absolute path file PEM development yang pengguna sediakan; file 0600, tidak disalin ke laporan |
| `GITHUB_APP_WEBHOOK_SECRET` | Secret yang sama dengan settings webhook; minimal 32 karakter |
| `NLP_SERVICE_URL` | `http://127.0.0.1:18765` |
| `APP_URL` | `http://127.0.0.1:18080` |
| `APP_ENV`, `APP_DEBUG`, `APP_KEY` | Mode QA terpisah, `false`, key QA baru yang tidak digunakan environment lain |
| `DB_CONNECTION`, `DB_DATABASE` | `sqlite`, absolute path DB integration QA baru |
| `DB_URL`, `DATABASE_URL` | Kosong; jangan warisi environment database aktual |
| `QUEUE_CONNECTION`, `DB_QUEUE_CONNECTION` | `database`, `sqlite`; jobs dan durable delivery harus memakai DB yang sama |
| `DB_QUEUE_RETRY_AFTER` | `90` sesuai [queue config](../backend/config/queue.php) |
| `CACHE_STORE`, `SESSION_DRIVER`, `SESSION_COOKIE` | `database`, `database`, nama khusus integration QA |
| `SESSION_SECURE_COOKIE` | `false` hanya untuk UI loopback HTTP; jangan dipakai pada UI publik |
| `MAIL_MAILER`, `LOG_CHANNEL` | `array`, `single` di storage QA privat; jangan log token/body |

Tidak ada `GITHUB_APP_SETUP_URL`, `GITHUB_APP_HOMEPAGE_URL`, atau environment override
timeout NLP pada service config. Timeout tetap 10 detik, connect timeout 5 detik.
Installation ID/repository ID diperoleh dan diverifikasi melalui flow, bukan env
atau angka fixture yang dimasukkan manual. Endpoint GitHub memakai API version
`2026-03-10`, connect timeout 5 detik/request 15 detik, dan tidak mengikuti redirect.

### 3. Kontrak isolation integration QA — kini diimplementasikan

Endpoint lokal existing (base `http://127.0.0.1:18080`):

| Method | Path | Authentication |
| --- | --- | --- |
| GET | `/projects/{project}/sources` | Session Maintainer pemilik |
| POST | `/projects/{project}/sources/github/app/connect` | Session + CSRF, memulai flow |
| POST | `/projects/{project}/sources/github/app/save` | Session + CSRF, verified selection |
| POST | `/projects/{project}/sources/github/app/preview` dan `/import` | Session + CSRF; `/import` relatif pada prefix `/projects/{project}/sources/github/app` |
| GET | `/extension/connect` | Session Reporter |
| POST | `/extension/pairing` | Session Reporter + CSRF |
| POST | `/api/extension/v1/pair` | One-time pairing code, rate limited |
| GET | `/api/extension/v1/me` | Bearer extension |
| POST | `/api/extension/v1/repositories/resolve` | Bearer + active membership/current mapping |
| POST | `/api/extension/v1/recommendations` | Bearer + scoped project/mapping |
| GET / POST / DELETE | `/api/extension/v1/votes` | Bearer + scoped project/issue; status/vote/unvote |
| DELETE | `/api/extension/v1/session` | Bearer, disconnect |

Routes extension source: [API](../backend/routes/api.php),
[web pairing](../backend/routes/extension_web.php). Jangan mengekspos endpoint ini
melalui public webhook gateway.

Existing [local-app.php](../backend/tests/qa/local-app.php) tetap utuh: tidak membaca
`.env` aktual, mengosongkan GitHub config, menolak outbound selain NLP loopback.
Menambahkan credential pada shell tidak mengubahnya menjadi launcher live.

Integration mode kini memakai launcher terpisah dengan kontrak berikut, sudah diuji
lokal tanpa credential aktual. Executable/commands ada pada latest checkpoint di
atas; jangan menggantinya dengan `php artisan serve` biasa.

1. Direktori baru `.qa/github-integration/`, mode 0700, DB/storage/session/key/akun
   sintetis sendiri, file privat 0600. Tolak symlink, existing-path overwrite dan
   config cache. Baca hanya konfigurasi integration yang disediakan pengguna;
   paksa/validasi driver SQLite dan exact DB path setelah bootstrap. Migrations
   hanya ke DB QA baru; tidak reset/migrate DB fixture atau DB aktual.
2. Gunakan port **18080 secara bergantian**, bukan bersamaan dengan fixture server.
   Ini menjaga build extension `--qa` yang sama. Port 8000/worktree lain tetap bebas
   dari tindakan QA. Hentikan hanya proses QA yang identitasnya telah diverifikasi;
   simpan fixture DB dan profilnya. Gunakan session cookie dan profil browser baru.
3. Seed project/anggota Reporter aktif dan Maintainer pemilik, serta sedikit issue
   sintetis untuk rekomendasi. Jangan seed koneksi GitHub dengan `verified_at` palsu.
   Koneksi actual harus berasal dari flow OAuth/installation. Untuk project switching,
   boleh dua project QA terotorisasi pada repository testing yang sama; catat bahwa
   satu webhook menghasilkan task dan issue per project, bukan satu untuk seluruh DB.
4. Fail-closed outbound allowlist untuk semua web/worker/recovery process:
   `https://github.com:443` hanya POST `/login/oauth/access_token`;
   `https://api.github.com:443` hanya GET `/user`, `/user/installations`,
   `/user/installations/{id}/repositories`, `/app/installations/{id}`,
   POST `/app/installations/{id}/access_tokens`, serta GET
   `/repos/valentinokevin/dupissue-testing`, `/issues` dan `/issues/{number}`
   relatif terhadap repository itu. ID harus positive integer dan pagination
   dibatasi sesuai client. NLP hanya `http://127.0.0.1:18765` pada endpoint yang
   digunakan client. Tolak host/port/userinfo/redirect/path lain; jangan memakai
   wildcard semua subdomain GitHub. Browser punya kebutuhan asset GitHub tersendiri;
   allowlist ini untuk backend. Laravel HTTP guard bukan firewall OS.
5. Worker database queues `default,nlp`, timeout 60 detik, retry_after 90 detik.
   Sync job sendiri punya tries 5, backoff 60/120/300/600 detik dan claim lease 120
   detik. Jalankan recovery dalam bootstrap terjaga yang sama: webhook recovery tiap
   menit, NLP recovery tiap 5 menit, session prune tiap jam, dengan overlap lock.
   [Jadwal source](../backend/routes/console.php) jangan dijalankan lewat subprocess
   artisan biasa yang dapat membaca `.env` aktual. Perintah fixture `schedule`
   di atas hanya satu tick manual, bukan scheduler daemon live.
6. Jangan jalankan 5.000 issue untuk smoke live. Mulai dataset kecil, ukur cold
   request pertama secara terpisah, observasi jobs/failed_jobs/status/error_code.
   Embedding cache tetap process-local; jangan membuat persistent store/precompute
   scheduler baru dalam tahap preparation ini.

### 4. Public HTTPS: hanya webhook

Pilihan yang diprioritaskan setelah approval: temporary HTTPS endpoint melalui
provider yang disetujui → gateway loopback khusus (port **18081**, sudah diuji lokal) →
integration Laravel 18080. Gateway hanya meneruskan **POST `/api/github/webhook`**;
tolak semua path/method lain, query string, ambiguous/encoded path, dan body >1 MiB.
Pertahankan byte raw body dan header `X-Hub-Signature-256`, `X-GitHub-Delivery`,
`X-GitHub-Event`, Content-Type. Buang Cookie, Authorization dan forwarded headers
dari internet; rewrite upstream Host ke `127.0.0.1:18080`, tanpa mempercayai spoofed
client headers. Jangan log payload, signature, OAuth query atau credential.
Laravel tetap melakukan HMAC SHA-256/hash_equals; gateway bukan pengganti signature.
Batasi durasi, traffic dan observasi rate limit; matikan endpoint setelah sesi dengan
approval lifecycle yang jelas, tanpa menghapus QA evidence.

Sebelum tunnel: uji gateway lokal bahwa `/`, `/login`, callback/setup, extension API,
static files dan NLP semuanya ditolak. Public UI/DB/FastAPI/worker tidak diperlukan.
Callback/setup tetap browser loopback. Jangan menunjuk tunnel langsung ke Laravel.
Alternative isolated QA hosting memerlukan deployment approval tersendiri. Webhook
relay pihak ketiga (misalnya Smee) menerima payload issue; tidak dipilih otomatis
karena confidentiality. Provider/TLS termination/data retention harus disetujui
pemilik. Tidak ada tunnel CLI/provider/account yang diinstal atau diaktifkan sekarang.

### 5. Instalasi extension dan pengujian browser asli

Build QA sudah tersedia. Bila build perlu diperbarui, gunakan hanya
`node browser-extension/tools/build-local.mjs --qa`; tanpa `--qa` origin menjadi
8000. Load folder **`.local-extension/`**, bukan source `browser-extension/` yang
memakai konfigurasi paket. Periksa config origin dan host permission hanya18080.

Setelah approval akses akun, buka Chrome for Testing dengan profil baru khusus
actual GitHub, tanpa fixture interception. Jangan gunakan `qa-demo.mjs` atau
`qa-browser.mjs` untuk mengklaim real DOM. Contoh command dari root worktree:

```sh
".qa/chrome-158/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing" \
  --user-data-dir="$PWD/.qa/github-browser-profile" \
  --no-first-run --no-default-browser-check about:blank
```

Di `chrome://extensions`: Developer mode → Load unpacked → pilih `.local-extension`.
Pin DupIssue (LOCAL ONLY). Pengguna login GitHub sendiri, tanpa browser sync/import
password, dan tanpa membagikan cookies/session/credential kepada agent. Login
Maintainer untuk koneksi App; setelah itu gunakan sesi Reporter QA untuk pairing
dan web detail. Jika menggunakan profil Reporter lain, GitHub login dilakukan
pengguna sendiri di profil itu juga. Jangan memakai profil personal existing.

| Langkah manual | Bukti/expected result |
| --- | --- |
| Popup → Connect to DupIssue | Membuka `http://127.0.0.1:18080/extension/connect`; Reporter login, generate pairing code melalui web CSRF |
| Pairing | Masukkan kode lokal; Connected state. Jangan screenshot kode/token; Maintainer bukan role extension |
| New Issue | Buka `https://github.com/valentinokevin/dupissue-testing/issues/new`; jangan submit dulu |
| Repository detection | Toolbar extension pada tab GitHub memberikan activeTab; repo tepat, project hanya dari membership Reporter aktif dan mapping current |
| Workspace + consent | Buka ruang rekomendasi, pilih project, aktifkan explicit consent sebelum draft dikirim ke API QA |
| Standard fields | Ketik judul/body sintetis; ukur waktu sampai Top3 dan scores, cek parity web pada draft/project yang sama |
| Dynamic navigation | Dari daftar issues ke New Issue dan back/forward pada repo testing; hasil/tab lama harus dibersihkan, tidak menampilkan rekomendasi salah konteks |
| Markdown template | Bila repo sudah punya template yang sesuai, pilih dan ketik field judul/body; jangan membuat/mengubah template external tanpa approval |
| Vote/Unvote | Perubahan tercermin di web dan GET status; klik berulang tidak menambah count/menjadi negatif; actual target root konsisten |
| Detail issue | Link menuju local QA web; butuh session Reporter web yang berwenang terpisah dari Bearer token |
| Project switching | Dua project QA yang sah pada repo testing yang sama; hasil lama hilang dan consent diaktifkan ulang |
| Disconnect | Session extension dicabut, hasil dibersihkan, request berikutnya ditolak sampai pairing ulang |

Batas selector source [content.js](../browser-extension/content.js): path hanya
`/owner/repo/issues/new` (trailing slash boleh), satu title input dari
`input[name="issue[title]"], input#issue_title, input#issue-title` dan satu body dari
`textarea[name="issue[body]"], textarea#issue_body, textarea#issue-body`.
Template chooser `/issues/new/choose` dan structured Issue Forms tidak didukung
secara umum; field ambiguous/missing harus fail closed, bukan menebak atau membaca
seluruh DOM. Input event, `turbo:load`, popstate dan polling navigasi 500 ms tersedia;
kompatibilitas current GitHub DOM tetap **belum diuji**. Jika selector gagal, catat
struktur field yang disanitasi dari profil QA saja; jangan klaim PASS template.

Rekomendasi tetap berada di extension-owned window yang dapat ditata berdampingan
dengan draft. Data privat tidak disisipkan ke DOM GitHub, localStorage halaman atau
komentar issue; jangan mengubah desain ini untuk meniru sidebar GitHub. Pada layar
kecil diperlukan resize/split view dan window dapat tertutup window lain.

Untuk Edge: pengguna menyediakan Edge terlebih dahulu bila belum tersedia. Buat
profil QA terpisah (jangan profil personal), buka `edge://extensions`, Developer mode
→ Load unpacked → `.local-extension`, pin extension, ulangi seluruh checklist.
Pastikan API tetap18080, tidak ada production host permission. Catat versi/channel
Edge dan hasil sendiri; Chrome PASS tidak otomatis berarti Edge PASS.
[Instruksi resmi Edge](https://learn.microsoft.com/en-us/microsoft-edge/extensions/getting-started/extension-sideloading).

### 6. Prosedur actual signed webhook dan ledger bukti

Lakukan hanya setelah seluruh approval/config/guard/worker di atas siap. Tidak ada
langkah berikut yang telah dieksekusi pada GitHub aktual dalam checkpoint ini.

1. Pengguna login Maintainer pemilik project QA → Sources → Connect GitHub App.
   Selesaikan OAuth/installation dengan akses hanya repo testing. Verifikasi numeric
   App ID, installation ID, repository ID, full name dan verified_at dari hasil
   source/API yang sah; jangan menganggap angka fixture123/456/789 sebagai bukti.
   Source membatasi discovery satu halaman100 installation/repository dan menolak
   hasil tidak lengkap. Stop bila akses memerlukan perluasan ke repo lain.
2. Aktifkan webhook setelah koneksi tersimpan agar `issues.opened` menemukan mapping.
   Ping valid mendapat202 `IGNORED`, **bukan** durable issue delivery/import.
   Gateway harus sudah lulus deny-route dan signature-negative checks lokal.
3. Sebelum submit, catat counts delivery/task/issues/candidates/audit/jobs dan
   `last_synced_at` pada isolated DB. Pilih satu project terhubung untuk baseline
   sederhana. Gunakan judul/body sintetis semisal `QA integration checkout payment`
   / `Clicking the payment button does not complete checkout.` dengan marker waktu.
   Setelah approval **satu issue**, klik Submit hanya pada repo testing. Tidak ada
   reverse sync, edit/close/delete issue existing, atau cleanup external otomatis.
4. Di GitHub App Recent deliveries, catat UUID, event/action, delivery time/status,
   installation ID, repository ID dan issue number tanpa menyalin raw payload atau
   headers rahasia. Expected202 `ACCEPTED`; verifikasi satu durable delivery UUID,
   satu task per connection/project, dan database job queue `default`.
5. Worker harus fetch ulang metadata repository dan issue menggunakan installation
   token read-only/repository-scoped. Metadata ID/full_name, issue number,
   repository_url dan non-PR harus cocok. Payload title/body webhook bukan sumber
   kebenaran impor. Source mengidentifikasi issue dengan **repository ID + number**;
   global `issue.id` dari webhook tidak disimpan/divalidasi sebagai identity terpisah.
6. Expected task `COMPLETED`, satu issue per project dengan source `GITHUB`, stable
   `github_repository_id`, `github_number`, source_url
   `https://github.com/valentinokevin/dupissue-testing/issues/<number>`, status OPEN,
   system audit `SYNC_GITHUB`, dan `last_synced_at` terisi. Source URL dibentuk dari
   verified repo/number, bukan mempercayai arbitrary html_url payload. Delivery
   COMPLETED dan detection COMPLETED adalah dua tahap berbeda.
7. NLP job setelah commit berjalan di queue `nlp`; observasi PENDING/PROCESSING →
   COMPLETED atau dokumentasikan FAILED/retry/error. GitHub/FORM detection mempertahankan
   threshold≥0.75 dan maksimal10; **CSV-source detection** threshold≥0.75 maksimal3
   dengan tie order stable. Web/extension pre-submit tetap Top3 target root melalui
   shared recommendation service. Gunakan CSV sintetis kecil lokal bila perlu bukti
   baru lintas-source; tidak ada klaim 5.000 jobs dari satu webhook.
8. Setelah task selesai, pengguna redeliver **delivery yang sama** sekali (approval
   external action). UUID/body yang sama tidak menambah issue, audit import atau job
   untuk task completed. Jika GitHub memberikan UUID berbeda, catat apa adanya:
   delivery baru boleh tercatat tetapi unique issue identity tetap satu per project.
   Jangan mengklaim UUID-dedup jika yang diuji sebenarnya delivery baru.
9. Manual GitHub preview melalui Sources setelah approval network use harus menandai
   issue tadi already imported; jangan confirm bila semua row sudah diimpor. Bila
   menguji confirm, pilih hanya row sintetis yang disetujui. List API bounded500items
   (termasuk PR sebelum filter), bukan bukti full-sync repository besar. Bandingkan
   jumlah issue/identity dan timestamp; duplicate completed redelivery tidak perlu
   mengubah last_synced_at. Keberhasilan sync/importlah yang memperbarui timestamp.
10. Repository replacement actual **BLOCKED** oleh scope satu repo: butuh nama repo
    testing kedua dan approval perubahan installation/access. Setelah disetujui nanti,
    confirm replacement harus reset last_synced_at, mapping extension pindah, queued
    old-snapshot task ditolak, dan issue/vote/audit historis tetap ada. Jangan mengganti
    repo dengan repo produksi/anggota lain untuk melengkapi checklist.

Read-only evidence di DB integration saja (sesudah file dibuat oleh launcher aman):

```sh
test -f .qa/github-integration/database.sqlite && sqlite3 -readonly \
  .qa/github-integration/database.sqlite <<'SQL'
SELECT delivery_uuid, installation_id, repository_id, issue_number, received_at
FROM github_webhook_deliveries ORDER BY id DESC LIMIT 10;
SELECT delivery_id, project_id, status, attempts, error_code, completed_at
FROM github_webhook_tasks ORDER BY id DESC LIMIT 10;
SELECT project_id, installation_id, repository_id, repo_full_name, verified_at, last_synced_at
FROM github_app_connections;
SELECT id, project_id, source_type, github_repository_id, github_number, source_url, detection_status
FROM issues WHERE source_type = 'GITHUB' ORDER BY id DESC LIMIT 10;
SELECT queue, count(*) FROM jobs GROUP BY queue;
SELECT count(*) FROM failed_jobs;
SQL
```

Source evidence: [receiver](../backend/app/Http/Controllers/GithubWebhookController.php),
[dispatch](../backend/app/Services/GithubWebhookDispatchService.php),
[sync](../backend/app/Services/GithubWebhookSyncService.php),
[API verification](../backend/app/Services/GithubApiClient.php),
[shared import](../backend/app/Services/GithubIssueImportService.php),
[preview](../backend/app/Services/GithubIssuePreviewService.php),
[detection](../backend/app/Jobs/DetectIssueCandidatesJob.php),
[stable unique identity](../backend/database/migrations/2026_11_24_000004_add_stable_github_identity_to_issues.php).

Negative cases telah memiliki automated local evidence; jangan menandai sebagai
actual GitHub PASS tanpa eksekusi. Untuk endpoint live yang telah disetujui, invalid
signature diharapkan401 `INVALID_SIGNATURE`, wrong installation/repo yang ditandatangani
di QA403 `UNCONNECTED_REPOSITORY`, malformed identity422 `INVALID_IDENTITY`, same UUID
different body409 `DELIVERY_CONFLICT`, oversize413. Jangan mengubah/forging payload
actual pengguna; negative probes memakai data sintetis dan credential dev secara
lokal setelah approval. Source membatasi120webhook/min; test bounded, tanpa flood.
Durable metadata disimpan tanpa raw payload; task failures memakai safe error codes.
Response webhook/extension relevan menggunakan `Cache-Control: no-store`.

Extension audit: [repository mapping](../backend/app/Services/ExtensionRepositoryService.php)
memeriksa membership Reporter aktif dan current verified connection, tanpa GitHub
API fetch saat resolve. [Recommendation](../backend/app/Http/Controllers/ExtensionRecommendationController.php)
memeriksa ulang auth/mapping setelah NLP; [voting](../backend/app/Http/Controllers/ExtensionVoteController.php)
memakai shared VoteService dan pemeriksaan project/issue, credential/membership dan
snapshot mapping. Ini alasan mapping fixture tidak membuktikan actual App access.

### 7. Approval checklist dan remaining blockers

- [x] Implementasi launcher/gateway terpisah dan negative local checks selesai.
- [ ] Pilih/approve temporary HTTPS provider/hostname, scope webhook-only dan durasi
  exposure sebelum credential/tunnel diaktifkan.
- [ ] Pengguna menyiapkan development credentials secara lokal; approve penggunaan
  terbatas oleh web/worker. Jangan kirim secret/PEM/password lewat chat.
- [ ] Pengguna approve/perbarui App settings callback/setup/webhook/permissions/events
  dan installation **only selected testing repository**, dengan review dampak ke
  pemakai dev App lain. Tidak ada perubahan configuration external sekarang.
- [ ] Approve browser account access; pengguna login sendiri di profil QA baru.
- [ ] Approve satu actual synthetic GitHub issue dan satu redelivery, serta manual
  preview/import scope yang disebutkan; dilarang mengedit existing GitHub issues.
- [ ] Edge tersedia dari pengguna; tidak ada install aplikasi tambahan otomatis.
- [ ] Replacement actual menunggu repository testing kedua yang disetujui terpisah.

Remaining: public host dan actual App config/access/credentials belum diverifikasi;
actual GitHub DOM/Markdown template/Edge/webhook-chain belum diuji. Services lokal
berjalan default GitHub-disabled pada latest checkpoint. Simpan evidence
minimal: versi browser, case, timestamp, sanitized URL/status/UUID/IDs, expected vs
actual counts/ranking; jangan ekspor HAR/cookies/Authorization/raw webhook/private key.

F2 tetap eksperimen lokal yang diterima: warm5.000 web0.2215s/extension0.2232s;
cold≈10.49s melampaui target3s dan timeout10s. TTL900s, eviction10.000entries/32MiB,
restart dan setiap process baru dapat menyebabkan cold encoding; cache bukan
durable backlog storage. Kontensi mengembalikan503 setelah bounded wait≈2s.
20users/5.000inference jobs belum disertifikasi. Tidak ada NLP architecture baru.
Lihat [performance report](NLP_PERFORMANCE_REPORT.md); approval Joshua/Technical Lead
untuk shared NLP dan acceptance SRS tetap pending.

Belum ada setting diubah, credential dipakai, tunnel dibuka, atau actual issue dibuat.
Approval Elsa/Joshua dan Docker/remote CI tetap terpisah dari local QA.
