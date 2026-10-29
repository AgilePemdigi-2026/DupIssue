# Final review NLP Queue PR #22

Tanggal: 29 Oktober 2026. Workspace: `dupissue-nlp-assist`.

**READY TO COMMIT untuk patch NLP; validasi integrasi target BLOCKED_BY_PR19
(termasuk dependensi PR #18 dan konfigurasi testing bersama).** Ini bukan
pernyataan full suite hijau atau siap merge. Tidak melakukan commit/push/merge/PR.

## Baseline dan sumber

- Branch aktif `assist/nlp-pr22`, working tree awal bersih.
- HEAD dan `origin/feature/nlp-database-queue` sama:
  `df9381a33d0888badbf18405c46c6157ad8e28b2` (implementasi awal PR #22).
- Referensi dibaca dari `../dupissue-sprint1-integration`, branch
  `integration/elsa-nlp-sprint1`, termasuk laporan
  `docs/testing/ELSA_NLP_SPRINT1_INTEGRATION.md`.
- Empat file NLP di bawah disalin identik dari hasil integrasi terverifikasi.
  Migration kandidat dibandingkan dan sudah identik sehingga tidak diedit.
- Target belum membawa Project/Membership PR #18 maupun Issue PR #19. Fakta PR #18
  sudah merged ke main tidak berarti branch target sudah memuatnya.
- Workspace referensi dan branch asli `feature/nlp-database-queue` tidak diubah.

## Perubahan yang layak di-commit

| File | Perubahan terhadap PR #22 awal |
| --- | --- |
| `backend/app/Jobs/DetectIssueCandidatesJob.php` | Hapus properti yang konflik dengan Queueable; panggil `afterCommit()` di constructor. Transisi PROCESSING/FAILED/COMPLETED memakai conditional UPDATE; completion dan penggantian kandidat berada dalam satu transaction. |
| `backend/app/Models/DuplicateCandidate.php` | Validasi atribut skor mentah saat ini sebelum float cast; menolak nonnumerik, null/boolean/array, NaN, infinity, overflow dan nilai di luar 0–1. Numeric string valid tetap diterima. |
| `backend/tests/Feature/Nlp/DetectIssueCandidatesJobTest.php` | Fixture Project/User asli, cegah stray HTTP, perkuat filter kandidat, tambah regression late failure, stale read, interleaving, rollback dan retry FAILED. |
| `backend/tests/Feature/Nlp/DuplicateCandidateIntegrationTest.php` | File baru: schema/FK/unique/cascade, relasi, validasi skor create/update dan pelestarian nilai tersimpan saat update invalid. |
| `docs/testing/NLP_PR22_FINAL_REVIEW.md` | Laporan finalisasi ini; dapat menjadi commit dokumentasi terpisah. |

`backend/database/migrations/2026_10_22_000000_create_duplicate_candidates_table.php`
tidak memiliki diff baru. Retry 3 attempts, backoff `[10,30,60]`, idempotency,
maksimum 10 kandidat, threshold produksi **0.75**, connection **database**, queue
**nlp**, dan reprocessing FAILED tetap dipertahankan. Penolakan rentang 0–1 di atas
adalah kontrak model; perilaku clamp respons di Job tidak diubah dari sumber.

## Pengujian aktual pada target

PHP 8.3.35, Laravel 12.70.0, PHPUnit 11.5.57. Dependency dipasang dari lockfile
dengan `composer install --no-scripts --no-interaction --prefer-dist --no-progress`.
Lockfile tidak berubah; vendor/cache/log tetap diabaikan Git.

Seluruh run Laravel memakai environment proses `APP_ENV=testing`,
`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL` kosong,
`DB_FOREIGN_KEYS=true`, `DB_QUEUE_CONNECTION=sqlite`, queue sync, cache/session/mail
array dan APP_KEY acak sementara. Tidak ada `.env` atau cached config sebelum run.
Tidak mengakses MySQL development. Daftar konfigurasi koneksi standar masih
memuat nama mysql dan lainnya; keberadaan nama tersebut bukan koneksi aktif.

| Pemeriksaan | Hasil aktual |
| --- | --- |
| PHP lint 5 file NLP (termasuk migration) | PASS |
| Full PHPUnit target | **78 tests: 33 PASS, 30 ERROR, 1 FAIL, 14 SKIPPED; 100 assertions; exit 2** |
| NlpClientTest (bagian dari full suite) | 13 PASS, 24 assertions |
| Auth, Logout, Example (bagian dari full suite) | 20 PASS, 75 assertions |
| DetectIssueCandidatesJobTest | 14 SKIPPED oleh dependency guard yang sudah ada pada referensi |
| DuplicateCandidateIntegrationTest | 30 ERROR: `App\Models\Project` belum tersedia; 1 FAIL: assertion daftar koneksi hanya sqlite, sementara TestCase target belum memiliki guard integrasi |
| Probe langsung Job | 6 PASS: class-load/afterCommit, connection, queue, attempts, backoff, serialization |
| Probe migration SQLite tanpa schema pengganti | 6 PASS: memory/FK aktif, kedua deklarasi FK CASCADE ke issues.id, UNIQUE pasangan, index skor, migration down |
| Git whitespace check tracked dan untracked | PASS |

Probe migration hanya memverifikasi DDL/metadata. SQLite mengizinkan deklarasi FK
ke tabel parent yang belum ada; ini **bukan** bukti FK enforcement atau integrasi
Issue telah lulus. Tidak membuat Issue/Project palsu, menonaktifkan FK, mengubah
assertion, atau menambahkan skip agar suite tampak lulus.

Artefak lokal sementara (tidak di-commit):

- `/tmp/dupissue-pr22-target.xml` — JUnit full suite.
- `/tmp/dupissue-pr22-target-output.txt` — output dan penyebab failure/error.
- `/tmp/dupissue-pr22-probe.php` — probe Job dan metadata migration.

## BLOCKED_BY_PR19 dan keputusan shared tests

Validasi model skor mentah, retry runtime, idempotency, perlindungan COMPLETED,
rollback, FAILED reprocessing, top-ten/threshold, relasi, migration order, orphan
rejection dan cascade memerlukan Issue PR #19 beserta Project PR #18. Test schema
juga memerlukan guard SQLite pada TestCase/bootstrap testing dari integrasi.
Jalankan ulang 45 kasus Job/model setelah dependency tersebut tersedia; jangan
menganggap error/skip saat ini sebagai PASS.

`SubmitIssueQueueIntegrationTest.php` telah ditinjau dan **tidak disalin saat ini**.
Test tersebut mencakup Submit asli → jobs → worker database/nlp, boundary threshold,
dan exhausted redelivery sesudah hasil sukses. Simpan sebagai test lintas PR;
tempatkan sekali pada PR #22 dalam commit terpisah setelah #19/#18 dan konfigurasi
testing tersedia. Jangan menggandakan ke PR Elsa. Test live FastAPI tetap opt-in.

Kompatibilitas source dengan Issue Elsa diperiksa: `detection_status` fillable,
enum PENDING/PROCESSING/COMPLETED/FAILED tersedia, FK kandidat cocok dengan `issues.id`,
field project/title/description/status cocok, dan migration issues bertanggal lebih
awal daripada kandidat. Job tidak memerlukan relasi inverse `Project::issues()`.
Tidak menyalin controller, model/migration Issue, routes, views atau modul Project.

## Temuan audit dan batas bukti

- **High terlambatnya callback failure:** perbaikan referensi sudah dipindahkan;
  conditional UPDATE mencegah downgrade COMPLETED dan transaction menjaga hasil.
- **Medium validasi setelah float cast:** perbaikan raw current attribute sudah
  dipindahkan, termasuk regression create/update yang tidak diubah.
- Konflik trait afterCommit sudah terbukti selesai pada class-load aktual target.
- Seluruh High/Medium dalam laporan referensi telah ditangani pada source. Bukti
  runtime lifecycle/model di target tetap BLOCKED_BY_PR19; tidak dinyatakan lulus.
- Laporan referensi mencatat 174 PASS/2 SKIPPED (176 tests, 998 assertions), termasuk
  14 Job dan 31 model PASS. Angka tersebut adalah hasil historis workspace referensi,
  bukan pengujian yang dijalankan ulang dalam sesi ini.
- Belum ada distributed lock/attempt ownership: pemanggilan NLP bersamaan masih
  mungkin. Outbox/crash antara commit Issue dan enqueue, worker multiprocess,
  MySQL-specific CHECK dan FastAPI/model asli belum divalidasi pada sesi ini.

## Batas perubahan dan rekomendasi

Tidak ada perubahan FastAPI `main.py`, threshold produksi, NlpClient, Project Reza,
Submit Issue Elsa, eksperimen akurasi MiniLM, composer lock atau konfigurasi produksi.
Tidak ada credential, `.env`, cache atau vendor dalam daftar perubahan Git.
Index tetap kosong. Finalisasi berhenti setelah review ini.

Rekomendasi commit kode/test:

```text
fix(nlp): preserve completed queue results and validate raw similarity scores
```

Jika dokumentasi dipisahkan:

```text
docs(nlp): record PR22 verification and PR19 integration blockers
```
