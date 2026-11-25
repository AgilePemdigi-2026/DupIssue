# Extension dan GitHub synchronization: arsitektur dan operasi

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

## Current public execution checkpoint —24November2026

Gateway-only Quick Tunnel now user-authorized and launched. Actual App credential
use/OAuth/settings/issues remain gated. Current URL,30-minute expiry, safe shutdown,
rollback and credential checklist: latest [LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md) section.
Never reuse expired hostname. [quick-tunnel.mjs](../backend/tests/qa/integration/quick-tunnel.mjs)
pins verified official binary/hash,18081upstream only, loopbackmetrics18082, empty
private config, minimal environment without inherited secrets/proxy, no raw logs,
timed shutdown. Manual shutdown of wrapper and child verified; automatic expiry
implemented but full30-minute interval not yet elapsed at this checkpoint.

Gateway now enforces8s absolute TCP-acceptance deadline including headers/body/upstream,
5sbody sublimit and100msforced-close grace. Prior10s+8s wording below is historical.
GitHub10s response requirement includes external network time; local bound alone
does not guarantee that requirement. Timeout does not return false acceptance.

Gateway14/14 and combinedNode71/71 passed. Public fixture14/14 proved synthetic HMAC401,
ping202IGNORED,unconnected403 and denied paths without imports/deliveries/jobs. Final
main-backend hostname10/10probes passed restrictions; main POST503NOT_CONFIGURED is
intentional before credential activation. Actual GitHub signature origin/delivery/import
and actual browser DOM remain untested. Main0GitHubconnections, services remain
QA_GITHUB_NETWORK=0. Shared NLP cache and its cold/SRS/TL-review limitations unchanged.

## Dedicated GitHub integration QA — implemented, local only

Executable [integration-app.php](../backend/tests/qa/integration-app.php) uses new
`.qa/github-integration` SQLite/storage/sessions/key/logs/queue and synthetic accounts.
No verified repository connection is seeded. Existing fixture launcher remains
unchanged and must not run concurrently on18080. GitHub HTTP is disabled by default;
only NLP18765 is reachable through the guarded Laravel HTTP client. Future activation
requires explicit `QA_GITHUB_NETWORK=1` and a validated private config/key inside the
ignored QA directory. Real credentials were not used during preparation.

[Webhook gateway](../backend/tests/qa/integration/webhook-gateway.mjs) binds only
127.0.0.1:18081 and forwards exact POST `/api/github/webhook` to127.0.0.1:18080.
No URL/host/target CLI override, public listener or generic proxy. Raw signed bytes
preserved;1MiBbody,10sbody/8supstream deadlines,16in-flight/32connections. Required
GitHub headers only; cookies/auth/forwarded headers dropped. Backend HMAC still
authoritative. Responses expose only safe status/code, not upstream HTML/secrets.
Native PHP access logging suppressed; application log message/context removed to
avoid OAuth query/SQL/payload leaks. Gateway records no raw requests.

Integration CLI modes: `init` (refuses overwrite), `serve`, `status`, `worker`, `drain`,
`schedule`, `scheduler`, `nlp-smoke`, `check-config`. Worker timeout60/retry_after90,
queues default,nlp; worker and scheduler bounded2hours. Drain bounded20jobs.
Scheduler runs actions in the same guarded process with cadence60/300/3600seconds
and DB locks, never an unguarded artisan subprocess. Main synthetic6issues completed;
0GitHubconnections/0jobs/0failed_jobs. Services18080/18081/18765 + worker/scheduler
left running at checkpoint with GitHub disabled;8000 untouched.

Actual tests: PHP614/5100assertions/2skips; existingJS38, gateway12, launcher5scenarios,
readiness12scenarios (combined Node69 including2parents); NLP29; live submit1/21;
live parity subset1/6. Readiness uses real database/session/CSRF/HTTP/queue/NLP but
**GitHub HTTP fake in a separate random test DB**. It proves local handling, not
actual App access, GitHub signature origin or real GitHub DOM/Edge compatibility.

Commands, secure local credential setup, exact App settings, Chrome/Edge instructions,
test reproduction and provider comparison: [LOCAL_QA_GUIDE](LOCAL_QA_GUIDE.md).
Recommend approved Cloudflare Quick Tunnel to gateway18081 only; CLI not installed,
no endpoint opened. Provider/data transit/credential use/settings/login/issue creation
remain external approval gates. No reverse synchronization or production migration.

Dokumen ini menjelaskan implementasi lokal E5–E11. Instalasi extension dan
konfigurasi external tetap memerlukan QA/approval dalam [laporan akhir](FINAL_QA_REPORT.md).
Update FINAL QA: model asli offline dan Chrome for Testing/local-stack integration
sudah lulus; actual GitHub/Edge serta performance acceptance masih terbuka.
Environment interaktif tersedia sesuai [LOCAL_QA_GUIDE.md](LOCAL_QA_GUIDE.md).

## Batas komponen

- Website dan extension menggunakan `IssueRecommendationService` serta
  `VoteService` yang sama. Tidak ada tabel voting baru.
- Content script membaca satu pasangan title/body Markdown GitHub setelah consent.
  Service worker memvalidasi sender/tab/repository, mengelola credential, dan
  memanggil endpoint allowlist. Private results hanya masuk jendela extension.
- Webhook receiver memverifikasi raw HMAC, menyimpan delivery/task, lalu enqueue
  `SyncGithubIssueJob` pada database queue `default`. Tidak memanggil GitHub/NLP
  selama request receiver.
- Worker memperoleh repository-scoped installation token, memverifikasi metadata
  repository dan mengambil issue asli melalui GitHub API. Setelah fetch, transaksi
  mengunci project, connection, task dan memeriksa identitas/lease lagi.
- `GithubIssueImportService` menggunakan persistence helper yang sama untuk manual
  import dan webhook. Manual owner authorization/fingerprint tetap berlaku.
  Automatic sync memakai `author_id = null`, audit `SYNC_GITHUB`, `user_id = null`,
  dan metadata actor `github_app_webhook`; tidak menyamar sebagai Maintainer.
- NLP job tetap pada queue `nlp` dan dijadwalkan setelah commit. Model dan threshold
  existing tidak diubah. CSV mempertahankan Top 3 kandidat di atas threshold 0,75;
  draft recommendations menggunakan ranking signed cosine tanpa threshold CSV.

## Authentication lifecycle

Reporter login melalui web, membuat pairing code lima menit/single use, lalu
exchange dari popup. Bearer token delapan jam disimpan hanya pada
`chrome.storage.session` dengan `TRUSTED_CONTEXTS`. Code tidak disimpan persisten.
Worker restart memulihkan credential dari storage tetapi menghapus consent/draft.
Browser shutdown menghapus storage session. Website session/cookies tidak dipakai
oleh API extension. Password/auth-version change atau pencabutan membuat token
tidak berlaku. UI memeriksa `/me` dan project access, backend selalu memeriksa ulang.

Disconnect membersihkan credential setelah revocation berhasil atau server sudah
menyatakan 401. Jika jaringan gagal, revocation belum dikonfirmasi dan credential
dipertahankan untuk retry. Pairing response terlambat tidak dapat menghidupkan
kembali session; token yang terbit dicabut best effort. Lost/orphan sessions dapat
dicabut di `/extension/connect`, dengan ownership dan CSRF web existing.

## Kontrak API

Semua endpoint extension memakai JSON, kecuali parameter query pada GET votes.
Tidak ada token dalam URL. Response relevan memakai `Cache-Control: no-store`.

| Method/path | Input | Output utama |
| --- | --- | --- |
| POST `/extension/pairing` | Web session Reporter + CSRF | `pairing_code`, `expires_in` atau halaman protected |
| POST `/api/extension/v1/pair` | `pairing_code` dalam JSON | `access_token`, `token_type: Bearer`, `expires_at` |
| GET `/api/extension/v1/me` | Bearer | `user: {id,name,role}` |
| DELETE `/api/extension/v1/session` | Bearer | `disconnected: true` |
| DELETE `/extension/sessions/{session}` | Web session pemilik + CSRF | Redirect kembali ke halaman koneksi |
| POST `/api/extension/v1/repositories/resolve` | `repository` | Repository canonical dan daftar authorized `projects: [{id,name}]` |
| POST `/api/extension/v1/recommendations` | `repository`, `project_id`, `title`, `description` | Maksimal tiga `recommendations` |
| GET/POST/DELETE `/api/extension/v1/votes` | `repository`, `project_id`, `issue_id` | `issue_id`, `target_issue_id`, `voted`, `vote_count`, `changed` |
| POST `/api/github/webhook` | Raw JSON + `X-Hub-Signature-256`, `X-GitHub-Event`, `X-GitHub-Delivery` | Safe `code`, HTTP 202 setelah durable acceptance |

Recommendation item: `issue_id`, `target_issue_id`, `title`, `description`, `status`,
`similarity` (positive display range 0–1), `cosine_similarity` (signed), `detail_url`.
Detail URL harus persis `/projects/{project_id}/issues/{target_issue_id}`.
Skor bukan probabilitas duplikasi. Vote pada duplicate memakai valid root parent;
perubahan parent membuat extension meminta reload, bukan menggunakan target lama.

401 berarti token tidak berlaku; 403 akses/mapping gagal; 404 issue tidak ada;
409 target/state berubah; 422 validasi gagal; 429 rate limited; 503 dependency
sementara tidak tersedia. API client hanya meneruskan safe code/status, menolak
redirect, memakai timeout 15 detik, `credentials: omit`, dan fixed origin.

Authenticated extension endpoints berbagi limit 60 request/menit. Poll workspace
15 detik dapat memakai `/me`, resolve, dan sampai tiga vote-status reads. Debounce
500 ms mengurangi request tetapi pengetikan terus-menerus masih dapat mencapai
limit; UI menampilkan 429 dan menyediakan retry, tanpa background mutation retry.

## Webhook, identity dan recovery

Receiver mendukung `issues.opened` saja. Event/action lain dengan signature valid
diabaikan dengan 202. Signature wajib `sha256=` dengan 64 hex, dibandingkan memakai
`hash_equals` terhadap raw bytes. Secret minimal 32 karakter berasal dari config.
Payload maksimal 1 MiB; delivery UUID, integer installation/repository/issue ID,
dan koneksi verified diperiksa. Pull request ditolak. Raw body tidak disimpan;
database menyimpan hash, ID, snapshot connection dan status pemrosesan.

Unique delivery UUID mencegah redelivery membuat task baru. UUID sama dengan body
berbeda ditolak 409. Setiap project memperoleh task terisolasi. Unique stable
`(project_id, github_repository_id, github_number)` melengkapi unique identity
existing. Nama repository historis dan source URL dipertahankan. Rename yang
menghasilkan redirect atau metadata berbeda ditolak; Maintainer harus memverifikasi
ulang koneksi. Stable ID mencegah duplikasi issue yang sudah mempunyai metadata ID.
Historical repository yang tidak terhubung saat migration tidak dapat dibackfill
secara tepercaya dan perlu rekonsiliasi jika namanya kemudian digunakan ulang.

Task: `RECEIVED → QUEUED → PROCESSING → COMPLETED`, dengan `RETRY`, `REJECTED`,
atau `FAILED` pada kegagalan. Lease 120 detik dan claim token mencegah stale worker
menyimpan hasil setelah claim diganti. Project-first locking sama dengan replacement
dan manual import. Retry transaksi dibatasi tiga kali; processing attempts lima.
Backoff 60/120/300/600 detik, termasuk rate limit dan transient failures. HTTP
400/403/404/409/410/422 dianggap permanent. Token diperoleh baru setiap attempt;
401 dapat retry dengan token baru. Rate-limit reset yang panjang mungkin tetap
memerlukan recovery operator setelah batas attempts tercapai.

Worker timeout 60s < `retry_after` 90s. Queue names tetap `default` dan `nlp`.
Outbox/job insertion harus memakai database connection yang sama. Receiver tetap
mencatat task jika dispatch gagal; redelivery atau scheduler dapat memulihkannya.
NLP after-commit crash gap dipulihkan melalui stale pending recovery.

Command berikut adalah runbook untuk environment yang sudah disetujui, bukan
perintah yang telah dijalankan terhadap database aktual:

```sh
php artisan github:webhooks-recover --limit=100
php artisan github:webhooks-recover --task=123
php artisan nlp:recover-pending --limit=100
php artisan extension:sessions-prune
```

`--task` secara eksplisit mengulang failed/rejected task setelah penyebab diperbaiki;
connection/identity tetap diverifikasi. Jangan menghapus delivery dedup records
secara sembarang. Failed NLP memerlukan review/retry existing; recovery otomatis
hanya untuk PENDING/PROCESSING yang stale lebih dari lima menit. Credential hashes
expired dibersihkan, metadata inactive dihapus setelah tujuh hari. Jadwal terdaftar
di console routes; scheduler deployment perlu disediakan operator.

Recovery NLP memakai at-least-once delivery. Pending lama yang masih berada dalam
backlog dapat memperoleh job tambahan; job existing melindungi hasil COMPLETED
dan candidate persistence secara idempotent, tetapi komputasi redundan masih
mungkin. Pantau queue depth/age dan sesuaikan jadwal atau limit recovery berdasarkan
benchmark sebelum menjalankan workload besar. Jangan menganggap recovery sebagai
bukti kapasitas inferensi 5000 jobs.

## Testing dan local development

Instalasi extension dan local build: [README extension](../browser-extension/README.md).
QA runtime dibuat di `.qa/` dan diabaikan Git/Docker. Jangan memakai production URL
atau database untuk test. PHPUnit default tetap dipaksa SQLite `:memory:` oleh
`tests/TestCase.php`; guard tersebut tidak diubah.

```sh
cd backend
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
NLP_LIVE_TEST_URL= /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit \
  --configuration phpunit.xml --display-skipped
node --test tests/javascript/*.test.cjs
cd ..
node --test browser-extension/tests/*.test.mjs
```

NLP unit tests: `cd nlp-service && ../.qa/nlp-venv/bin/python -m unittest discover -s tests -v`.
Disk blocker diselesaikan pengguna; snapshot model lengkap dimuat offline dari
shared cache tanpa download ulang. Dedicated local
URL adalah `http://127.0.0.1:18765`; CPU threads dibatasi dua. Jalankan existing
`SubmitIssueQueueIntegrationTest::test_submit_database_worker_and_live_fastapi`
dengan `NLP_LIVE_TEST_URL` tersebut. File opt-in
`backend/tests/qa/LiveRecommendationTest.php` menguji paritas web/extension serta
satu real NLP queue job pada ukuran project 10, 100, 1000, 5000. File ini tidak
termasuk default test discovery. F2 menambahkan project5,000issues, warm parity
dan content edit/delete: **PASS:3tests/56assertions**, original model. Existing
live submit1test/21assertions, NLP29/29, PHP610/5,065assertions/2skips, Node38/38.
Timeout benchmark 120s/worker150s hanya untuk QA; bukan konfigurasi production.
Empat job selesai, satu pada tiap ukuran project; bukan seluruh5000-job backlog.
Baseline lama5000 latency10.407–14.797s; F2 repeated baseline median10.6195s.
Bounded cache baru menurunkan HTTP warm median0.0490s/incremental0.0622s, tetapi
cold10.4926s tetap melampaui timeout10s/target3s. Model pinning, content/project keys,
10,000entry/32MiB/900sTTL dan2s inference wait perlu Technical Lead review.
Jangan menaikkan timeout untuk menyatakan target tercapai. Restart/eviction perlu
warmup; tiap NLP process punya cache sendiri. Overload503 bersifat retryable melalui
queue existing, tetapi cold inference dapat memblokir interactive traffic.
Prewarming terisolasi20chunks dievaluasi, belum menjadi scheduler produksi.
Detail lifecycle, memory, benchmark worker dan batas acceptance:
[NLP_PERFORMANCE_REPORT.md](NLP_PERFORMANCE_REPORT.md).

MySQL harness `backend/tests/qa/mysql-concurrency.php` memverifikasi `.qa/mysql`
sebagai datadir aktual server sebelum membuat schema baru. Gunakan instance
`--no-defaults --skip-networking --mysqlx=OFF`, socket `.qa/mysql.sock`, dan jalankan
dengan `APP_ENV=testing DB_URL=`. Jangan mengarahkan socket ke instance existing.
Harness tidak mengubah guard SQLite PHPUnit, tidak menghapus schema, dan tidak
menggunakan external GitHub credentials. Semua fixture sintetis.

## Deployment checklist (belum dijalankan)

- [ ] Selesaikan live NLP/performance, Chrome/Edge QA, dan approved GitHub dev-App QA.
- [ ] Dapatkan review Elsa untuk shared voting dan Joshua untuk scope/routing.
- [ ] Backup database; evaluasi historical identity conflicts sebelum migration.
- [ ] Set nama env berikut di secret manager: `GITHUB_APP_ID`, `GITHUB_APP_CLIENT_ID`,
  `GITHUB_APP_CLIENT_SECRET`, `GITHUB_APP_SLUG`, `GITHUB_APP_CALLBACK_URL`,
  `GITHUB_APP_PRIVATE_KEY_PATH`, `GITHUB_APP_WEBHOOK_SECRET`, `NLP_SERVICE_URL`.
- [ ] Mount private key read-only pada path yang tersedia di web dan worker;
  path env saja tidak memasang file. Jangan masukkan key ke image/build context.
- [ ] Pastikan queue database memakai connection yang sama, workers `default,nlp`
  aktif, timeout60/retry_after90 sesuai, dan scheduler `schedule:run` tiap menit.
- [ ] Review `MIGRATE_ON_START` existing; migration harus terkoordinasi sebelum worker
  baru berjalan. Tidak ada startup/deployment dijalankan oleh sesi pengembangan ini.
- [ ] Jalankan additive migrations setelah approval, lalu restart queue workers.
- [ ] HTTPS publik, trusted reverse proxy, raw body tidak ditransformasi, limit
  request proxy konsisten dengan 1 MiB receiver; FastAPI hanya di jaringan internal.
- [ ] GitHub dev App: subscribe Issues/read, endpoint `/api/github/webhook`, secret
  sesuai, SSL verification aktif. Perubahan setting memerlukan approval pengguna.
- [ ] Docker build dan GitHub Actions harus dijalankan di environment yang tersedia;
  session ini hanya memvalidasi source/config lokal, bukan remote CI/deploy.
- [ ] Health `/up`, health NLP, delivery status, queue depth, failed_jobs, dan
  disk/model cache dipantau. Jangan mencatat token, raw payload, atau SQL bindings.

## Migration dan rollback

Migration additive baru: extension sessions (`000002`, pekerjaan E2), webhook
delivery/tasks (`000003`), stable issue repository ID/index (`000004`). Historical
migrations tidak diedit. Stable-ID backfill hanya menyentuh source GITHUB yang
namanya cocok dengan active connection; historical issues tidak dihapus.

Rollback operasional: hentikan acceptance webhook di konfigurasi eksternal hanya
setelah approval, hentikan/drain worker, dan kembalikan aplikasi secara terkoordinasi.
Pertahankan tables/data additive untuk investigasi dan deduplication. Jangan
menjalankan `migrate:rollback` secara otomatis: down migration menghapus metadata
delivery/stable identity. Rollback schema memerlukan backup dan keputusan operator.
Website voting/schema votes tidak berubah, dan tidak ada reverse sync ke GitHub.
