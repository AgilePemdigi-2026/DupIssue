# NLP Queue — final synchronization with main

Tanggal: 29 Oktober 2026 (Asia/Jakarta). Workspace: `dupissue-nlp-assist`.

**READY FOR PR #22 FINAL REVIEW** untuk integrasi Laravel dengan HTTP fake.
Tidak ditemukan blocker kode dalam cakupan pengujian. FastAPI/model live belum
terverifikasi; hasil ini bukan klaim E2E dengan model asli atau kesiapan deployment.

## Git dan sinkronisasi

- Branch aktif `assist/nlp-sync-main`; HEAD tetap `2c9d564342b96df55f839e40d67ce98997b65752`,
  merge PR #27 pada `origin/feature/nlp-database-queue`.
- `MERGE_HEAD` tetap `1003711bed3a97127c9f8ffff164e9c924581097`, identik `origin/main`
  dan remote main dari `git ls-remote` pada sesi ini. Main memuat PR #18 (`8ad8a70`),
  PR #25 (`bc95fdd`) dan PR #19 (`1003711`). Remote NLP juga masih identik HEAD.
- Merge non-committing sudah berlangsung ketika sesi dimulai. Tidak ada unmerged
  index entries atau konflik yang perlu diselesaikan. Tidak ada resolusi manual baru.
- Controller, model/migration Issue, Project/Membership, services, routes, UI dan
  testing guard dari main dipertahankan. Perbandingan tracked tree terhadap main
  hanya menampilkan enam file NLP yang memang sudah ada di branch fitur.
- Tidak melakukan commit, push, pembuatan PR, perubahan status PR, atau merge ke main.
  Merge masih pending; 63 file upstream tetap staged seperti saat sesi dimulai.

## Hasil regression aktual

Runtime final: PHP 8.3.35, PHPUnit 11.5.57. Full suite memakai SQLite `:memory:`.
**177 tests: 175 PASS, 0 FAIL, 0 ERROR, 2 SKIPPED; 1.015 assertions.** Exit 0,
tanpa deprecation pada run final. Baris data provider dihitung sekali melalui suite induk.

| Suite | PASS | FAIL | ERROR | SKIPPED | Assertions |
| --- | ---: | ---: | ---: | ---: | ---: |
| ExampleTest | 1 | 0 | 0 | 0 | 1 |
| IssueTest | 2 | 0 | 0 | 0 | 12 |
| NlpClientTest | 13 | 0 | 0 | 0 | 24 |
| LoginTest | 7 | 0 | 0 | 0 | 21 |
| RegisterTest | 7 | 0 | 0 | 0 | 22 |
| ExampleTest | 1 | 0 | 0 | 0 | 1 |
| LogoutTest | 4 | 0 | 0 | 0 | 30 |
| DetectIssueCandidatesJobTest | 14 | 0 | 0 | 0 | 56 |
| DuplicateCandidateIntegrationTest | 31 | 0 | 0 | 0 | 89 |
| SubmitIssueQueueIntegrationTest | 3 | 0 | 0 | 1 | 53 |
| ProjectManagementTest | 23 | 0 | 0 | 0 | 163 |
| ProjectMembershipSecurityTest | 25 | 0 | 0 | 0 | 160 |
| SubmitIssueAccessTest | 3 | 0 | 0 | 0 | 11 |
| SubmitIssueDatabaseIsolationTest | 4 | 0 | 0 | 0 | 26 |
| SubmitIssueSuccessTest | 3 | 0 | 0 | 0 | 26 |
| SubmitIssueTest | 24 | 0 | 0 | 1 | 253 |
| TestProjectSeederSafetyTest | 8 | 0 | 0 | 0 | 47 |
| TestingApplicationKeyTest | 1 | 0 | 0 | 0 | 12 |
| TestingDatabaseIsolationTest | 1 | 0 | 0 | 0 | 8 |
| **Total** | **175** | **0** | **0** | **2** | **1.015** |

Dua skip:

1. Kasus Submit sebelum Job tersedia: tidak berlaku setelah Job asli terintegrasi.
2. Smoke test FastAPI live: opt-in `NLP_LIVE_TEST_URL` tidak diaktifkan karena service
   lokal tidak tersedia.

Baseline sebelum penambahan test: 173 tests, 172 PASS, 0 FAIL/ERROR, 1 SKIPPED,
962 assertions. Perintah `php` awal memakai PHP 8.5.3 dan melaporkan dua deprecation;
run final memakai path PHP 8.3 eksplisit. Tidak mengubah dependency untuk menutupinya.
Angka referensi 175/174/1.050 bukan hasil run ini dan tidak dipaksakan.

## Database Queue yang benar-benar dijalankan

Job, NlpClient, DatabaseQueue, payload serialization, database migrations, model dan
`queue:work database --queue=nlp --once` adalah implementasi asli. Worker dijalankan
in-process melalui Artisan agar berbagi SQLite memory. Transport HTTP menggunakan
`Http::fake` dan `Http::preventStrayRequests`; tidak ada Queue fake pada test worker.
Test Submit field/authorization lain memang memakai Queue fake dan tidak dihitung
sebagai bukti worker.

- Reporter anggota aktif → POST Submit → Issue OPEN/FORM/PENDING → satu jobs pada
  queue nlp setelah commit → worker → NlpClient → DuplicateCandidate → COMPLETED.
- Outer transaction belum commit: jobs kosong; sesudah commit: tepat satu job.
  Rollback membuang Issue dan callback; commit berikutnya tidak menghidupkan callback lama.
- HTTP tidak dipanggil pada request Submit. Skor 0.75 diterima, 0.7499 ditolak,
  kandidat beda project/self difilter, dan Job membatasi hasil ke sepuluh kandidat.
- HTTP 503: retry sukses serta exhausted retry tiga attempts diuji dengan worker asli.
  Clock testing dimajukan mengikuti backoff. Exhausted failure menyimpan satu failed_jobs,
  status FAILED, serta mempertahankan Issue OPEN. Konfigurasi retry tetap 3 attempts,
  backoff `[10,30,60]`, connection database, queue nlp, dan job afterCommit=true.
- Redelivery setelah sukses tidak menggandakan kandidat atau memanggil HTTP lagi.
- Regression exhausted redelivery: attempt ketiga dan reservation kedaluwarsa
  disimulasikan, Job menyimpan hasil, kemudian worker asli memproses redelivery yang
  sudah melebihi tries. failed_jobs berisi satu delivery gagal, tetapi COMPLETED dan
  kandidat tersimpan tetap identik. Ini simulasi acknowledgment hilang, bukan crash OS nyata.
- Tambahan regression HTTP 422: satu HTTP call, Issue FAILED/OPEN, jobs habis,
  kandidat kosong, tanpa retry. failed_jobs kosong karena Job menangani error permanen
  dan worker mengakui delivery. Ini berbeda dari failure yang menghabiskan retry.
- Kegagalan insert kandidat me-rollback perubahan status/kandidat secara atomik;
  callback failure terlambat, stale read dan respons yang selesai belakangan tidak
  menurunkan COMPLETED. Interleaving ini deterministik dalam satu proses.
- Dua regression integrasi dari laporan sebelumnya dibawa ke
  `backend/tests/Feature/Nlp/SubmitIssueQueueIntegrationTest.php`: threshold pada
  alur Submit/worker dan exhausted redelivery setelah sukses. File juga menyediakan
  smoke test live opt-in; regression worker HTTP 422 ditambahkan dalam sesi ini.

## Integrasi, keamanan dan QA

- Login, Register, Logout, role global, owner Project, membership aktif/revoked,
  forged input, akses lintas-project dan escaping view lulus suite yang tercantum.
- Issue.detection_status tetap fillable untuk worker; controller hanya menerima
  title/description dan menetapkan field keamanan di server.
- NLP hanya menyimpan rekomendasi: status bisnis tetap OPEN dan duplicate_of null;
  keputusan final duplikat tetap kewenangan Maintainer. Tidak menambah endpoint keputusan.
- Similarity score divalidasi dari current raw attribute sebelum casting; nilai
  invalid pada create/update ditolak, numeric string valid diterima, hasil tersimpan
  tidak ditimpa input update invalid. Threshold produksi tetap 0.75.
- Migration order users → projects → memberships/invitations → issues →
  duplicate_candidates kompatibel. FK aktif; issue project RESTRICT, author/parent
  SET NULL, kedua candidate FK CASCADE; orphan dan pasangan duplikat ditolak;
  PRAGMA foreign_key_check bersih.
- PHP syntax: **86 file PASS** pada app/bootstrap/config/routes/tests/database/views,
  di luar cache generated. Class-load Job dan trait juga lulus melalui suite.
- `route:list --json -vv`: **27 routes**, exit 0. Submit create/store memakai
  auth + role:REPORTER; show memakai auth dan pemeriksaan project/membership controller.
- `git diff --check`, `git diff --cached --check`, serta pemeriksaan whitespace
  file baru bersih. Tidak ditemukan conflict markers pada source integrasi.
- phpunit.xml memaksa APP_ENV/testing, SQLite memory dan DB_URL kosong. Bootstrap
  menolak cached config sebelum Laravel boot; TestCase membatasi daftar koneksi ke
  sqlite saja, termasuk queue/failed_jobs/batching. Tidak ada .env atau config cache
  aplikasi pada checkout ini saat pemeriksaan. MySQL development tidak diakses.
- Probe terpisah dengan shell APP_ENV=local, DB_CONNECTION=mysql, database/URL dummy
  menghasilkan **2 PASS, 20 assertions** untuk guard dan kestabilan APP_KEY; koneksi
  aktual tetap PDO SQLite memory. Hasil probe tidak ditambahkan ke total full suite.

## FastAPI dan batas verifikasi

GET tanpa proxy ke `http://127.0.0.1:8000/health` dan port 8001 sama-sama gagal
terhubung (curl exit 7, HTTP 000). FastAPI live **NOT VERIFIED**. Tidak menjalankan
server, mengunduh model, atau mengubah `nlp-service/main.py`, threshold maupun hasil
eksperimen NLP. HTTP fake membuktikan integrasi aplikasi, bukan kualitas model asli.

Worker daemon multiprocess, crash nyata, locking MySQL dan MySQL-specific CHECK
belum diuji. Risiko yang sudah dicatat sebelumnya tetap ada: crash antara commit Issue
dan enqueue dapat meninggalkan PENDING tanpa job; tanpa attempt ownership/lock, dua
worker bisa memanggil NLP bersamaan. Guard COMPLETED melindungi hasil yang sudah commit,
bukan jaminan exactly-once untuk seluruh proses. Ini batas bukti review Sprint 1.

## File yang berubah dan rekomendasi

Perubahan baru sesi ini hanya dua file untracked:

- `backend/tests/Feature/Nlp/SubmitIssueQueueIntegrationTest.php` — tiga test worker
  permanen dan satu smoke test live opt-in.
- `docs/testing/NLP_MAIN_SYNC_FINAL_REVIEW.md` — laporan ini.

Tidak ada perubahan source produksi baru. Enam file NLP yang berbeda dari main
sudah tersedia dari branch sumber: Job, model DuplicateCandidate, migration kandidat,
DetectIssueCandidatesJobTest, DuplicateCandidateIntegrationTest, dan laporan historis
NLP_PR22_FINAL_REVIEW. Daftar 63 file staged dari merge tersedia di bawah.

Untuk langkah berikutnya, rekomendasi commit merge lokal:
`merge(nlp): sync database queue branch with main after PR #19`.
Tempatkan test lintas PR pada commit terpisah:
`test(nlp): verify submit queue boundaries and permanent failures`.
Dokumentasi dapat dipisahkan dengan pesan
`docs(nlp): record final main synchronization review`.

Setelah pengguna memutuskan melakukan commit/push, PR bantuan yang sesuai adalah
`assist/nlp-sync-main` → `feature/nlp-database-queue`, kemudian final review PR #22
terhadap main. Tidak perlu menyalin ulang kode Reza/Elsa atau cherry-pick PR #27.
Sesi ini berhenti pada pengujian dan laporan; seluruh langkah tersebut belum dilakukan.

## Inventaris staged dari merge main

```text
A	.github/PULL_REQUEST_TEMPLATE.md
M	README.md
A	backend/app/Http/Controllers/IssueController.php
A	backend/app/Http/Controllers/ProjectMemberController.php
A	backend/app/Models/Invitation.php
A	backend/app/Models/Issue.php
A	backend/app/Models/Membership.php
A	backend/app/Models/Project.php
A	backend/app/Policies/ProjectPolicy.php
A	backend/app/Services/ProjectService.php
M	backend/bootstrap/app.php
A	backend/database/factories/InvitationFactory.php
A	backend/database/factories/MembershipFactory.php
A	backend/database/factories/ProjectFactory.php
A	backend/database/migrations/2026_10_21_200001_create_projects_table.php
A	backend/database/migrations/2026_10_21_200002_create_memberships_table.php
A	backend/database/migrations/2026_10_21_200003_create_invitations_table.php
A	backend/database/migrations/2026_10_21_200004_create_issues_table.php
A	backend/database/seeders/TestProjectSeeder.php
M	backend/phpunit.xml
A	backend/resources/views/invitations/index.blade.php
A	backend/resources/views/issues/create.blade.php
A	backend/resources/views/issues/layout.blade.php
A	backend/resources/views/issues/project-link.blade.php
A	backend/resources/views/issues/show.blade.php
A	backend/resources/views/issues/submit-link.blade.php
A	backend/resources/views/layouts/app.blade.php
A	backend/resources/views/projects/create.blade.php
A	backend/resources/views/projects/index.blade.php
A	backend/resources/views/projects/members.blade.php
A	backend/resources/views/projects/settings.blade.php
A	backend/resources/views/projects/show.blade.php
M	backend/routes/web.php
A	backend/tests/Bdd/submit-issue.feature
A	backend/tests/Feature/ProjectManagementTest.php
A	backend/tests/Feature/ProjectMembershipSecurityTest.php
A	backend/tests/Feature/SubmitIssueAccessTest.php
A	backend/tests/Feature/SubmitIssueDatabaseIsolationTest.php
A	backend/tests/Feature/SubmitIssueSuccessTest.php
A	backend/tests/Feature/SubmitIssueTest.php
A	backend/tests/Feature/TestProjectSeederSafetyTest.php
A	backend/tests/Feature/TestingApplicationKeyTest.php
A	backend/tests/Feature/TestingDatabaseIsolationTest.php
M	backend/tests/TestCase.php
A	backend/tests/Unit/IssueTest.php
A	backend/tests/bootstrap.php
M	docs/README.md
A	docs/SUBMIT_ISSUE_REZA_INTEGRATION.md
A	docs/SUBMIT_ISSUE_SPRINT1_AUDIT.md
A	docs/artifacts/README.md
A	docs/artifacts/srs/DupIssue_SRS_v1.0.pdf
A	docs/artifacts/srs/README.md
A	docs/assets/dupissue-banner.svg
A	docs/assets/dupissue-logo.svg
A	docs/assets/ui-maintainer.svg
A	docs/assets/ui-reporter.svg
A	docs/assets/ui-submit.svg
A	docs/bdd/PROJECT_MEMBERSHIP_BDD_NOTES.md
A	docs/bdd/SUBMIT_ISSUE_BDD_NOTES.md
A	docs/bdd/project-membership.feature
M	docs/decisions.md
A	docs/testing/README.md
A	docs/testing/REZA_SPRINT1_REVIEW.md
```
