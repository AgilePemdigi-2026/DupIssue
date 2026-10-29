# Audit dan perbaikan lokal Submit Issue Sprint 1

> Laporan historis sebelum integrasi main Reza. Untuk status terbaru setelah PR #18/#24,
> lihat [laporan integrasi lokal](SUBMIT_ISSUE_REZA_INTEGRATION.md).

Tanggal: 29 Oktober 2026. Workspace `dupissue-elsa-assist`, branch `assist/elsa-pr19`.
HEAD awal `c57519308fd023935edfce0044db2110699edf14`, sama dengan snapshot lokal
`origin/feature/submit-issue-complete`; worktree awal bersih.

**Kesimpulan: perbaikan lokal selesai; modul belum siap pengujian end-to-end.**
Suite lokal: **39 PASS, 0 FAIL, 25 SKIPPED** (64 test, 130 assertions).
Pemeriksaan pemuatan class Job asli PR #22: **FAIL**, konflik properti trait.
BDD: **NOT RUN**, belum ada runner/step definitions.

## 1. Masalah aktual

1. `Project`, `Membership`, `ProjectService`, migration Project/Membership,
   route `projects.show`, dan layout `layouts.app` tidak ada pada branch ini.
   Submit Reporter belum dapat dipakai. Tidak dibuat class/schema pengganti.
2. Kedua view Submit langsung merujuk layout dan route Project yang tidak ada.
   View juga akan gagal setelah model/service saja diintegrasikan, jika UI Project belum ikut.
3. Model Issue hanya mengizinkan `title`/`description` melalui `fill()`. Job asli
   Draft PR #22 memakai `$issue->update(['detection_status' => ...])` untuk
   PROCESSING/COMPLETED. Perubahan itu akan diabaikan (atau exception bila strict mode).
4. Penanganan enqueue failure dapat melempar exception kedua saat menulis FAILED.
   Issue sudah committed, tetapi pengguna menerima error dan berpotensi submit ulang.
5. Detail Issue hanya bergantung pada `canAccessProject`, yang juga mengizinkan owner
   tanpa membership. Reporter kini harus memiliki membership aktif juga untuk detail.
6. Submit hanya memiliki route middleware auth; pemeriksaan role ada di controller
   yang membutuhkan ProjectService. Gate role sekarang dapat berjalan sebelum binding/dependensi.
7. Isolasi SQLite sebelumnya hanya diperkuat di Submit/Logout. Login/Register masih
   bergantung pada environment PHPUnit yang dapat diwarisi dari shell/cache development.
8. Test worker memakai satu `queue:work --once --tries=1`, tetapi Job menetapkan
   `$tries=3`. Opsi CLI tidak mengalahkan nilai Job; satu attempt bukan final failure.
   Selain itu, `Http::fake()` catch-all dari setup mendahului fake 503 di test;
   diganti dengan `Http::fake([])` agar respons worker tidak tertutup fake awal.
9. Semua test Submit sebelumnya ikut skipped karena Project, termasuk guest access
   yang sebenarnya dapat diuji tanpa Project. Bukti jumlah dispatch juga belum eksplisit.
10. Tidak ada runner Behat/Mink atau step definitions. File feature saja tidak membuktikan BDD.
11. **Blocker PR #22 terverifikasi:** Job mendeklarasikan `public $afterCommit = true`
    sementara trait Laravel Queueable mendeklarasikan properti yang sama dengan default null.
    Memuat class asli menghasilkan fatal error (exit 255), sebelum `handle()` dijalankan.

## 2. Perbaikan

- Pertahankan alur controller Elsa: authorization, validasi 5–200/10–10.000,
  transaksi, satu callback afterCommit, dan `Bus::dispatch` ke database/nlp.
  Project/author berasal dari route dan pengguna, bukan request. Status awal tetap
  OPEN/PENDING, source FORM, vote_count 0, duplicate_of null.
- Tambahkan role middleware sebelum model binding; perketat detail Reporter dengan
  membership aktif. Akses owner Maintainer ke detail tetap mengikuti ProjectService.
- Izinkan hanya tambahan `detection_status` pada fillable agar `update()` Job asli
  bekerja. Controller tetap hanya memasukkan hasil validasi title/description dan
  menentukan metadata sendiri. Field sistem lain tetap guarded.
- Lindungi kegagalan kedua saat menyimpan FAILED; catat jenis exception dan issue_id.
  Update FAILED tetap bersyarat PENDING agar tidak menimpa worker yang sudah maju.
  Jika DB juga gagal, issue tetap ada dengan PENDING dan membutuhkan rekonsiliasi operasional.
- Tambahkan layout khusus Issue sebagai fallback; gunakan layout Reza saat tersedia.
  Link kembali memakai `projects.show` dengan parameter `id` sesuai kontrak Reza,
  atau dashboard yang sudah ada jika route Project belum tersedia.
- Siapkan partial Submit Issue untuk Reporter anggota aktif. **Navigasi dari halaman
  Project belum terpasang**, karena halaman itu tidak ada pada branch ini.
- Terapkan guard testing sebelum provider/migration: hanya koneksi SQLite `:memory:`,
  cache/session array, queue database memakai SQLite; tolak cached config aplikasi.
  Bootstrap menimpa environment development. Tidak menyentuh database MySQL.
- Pisahkan test akses/model yang bisa berjalan dari test integrasi, tambah kasus
  job absen, revoked membership, rollback insert, callback rollback tidak bocor,
  queue driver salah, kegagalan status sekunder, tiga attempt worker, retry sukses
  dan redelivery. Test membutuhkan Job asli tetap skipped bila belum tersedia.
- Perjelas Gherkin dan dokumentasikan kebutuhan step definitions secara terpisah.

## 3. File berubah

| File | Perubahan |
| --- | --- |
| [IssueController.php](../backend/app/Http/Controllers/IssueController.php) | Gate detail Reporter, failure update FAILED |
| [Issue.php](../backend/app/Models/Issue.php) | Kompatibilitas update status worker |
| [bootstrap/app.php](../backend/bootstrap/app.php) | Prioritas role sebelum binding |
| [web.php](../backend/routes/web.php) | Middleware role pada GET/POST Submit |
| [create.blade.php](../backend/resources/views/issues/create.blade.php) | Layout dan navigasi fallback |
| [show.blade.php](../backend/resources/views/issues/show.blade.php) | Layout, navigasi, hindari success flash ganda dengan layout Reza |
| [layout.blade.php](../backend/resources/views/issues/layout.blade.php) | Baru: layout lokal Issue dan logout |
| [project-link.blade.php](../backend/resources/views/issues/project-link.blade.php) | Baru: link Project/dashboard |
| [submit-link.blade.php](../backend/resources/views/issues/submit-link.blade.php) | Baru: partial integrasi navigasi Project |
| [phpunit.xml](../backend/phpunit.xml) | Bootstrap testing aman |
| [tests/bootstrap.php](../backend/tests/bootstrap.php) | Baru: environment testing dan guard config cache |
| [TestCase.php](../backend/tests/TestCase.php) | Hapus koneksi development sebelum provider boot |
| [SubmitIssueTest.php](../backend/tests/Feature/SubmitIssueTest.php) | Gate dependensi, groups, regression dan retry tests |
| [SubmitIssueAccessTest.php](../backend/tests/Feature/SubmitIssueAccessTest.php) | Baru: guest/role tanpa dependensi Project |
| [TestingDatabaseIsolationTest.php](../backend/tests/Feature/TestingDatabaseIsolationTest.php) | Baru: bukti koneksi SQLite memory |
| [IssueTest.php](../backend/tests/Unit/IssueTest.php) | Baru: status worker, guarded fields, relasi |
| [submit-issue.feature](../backend/tests/Bdd/submit-issue.feature) | Audit spesifikasi Gherkin |
| [SUBMIT_ISSUE_BDD_NOTES.md](bdd/SUBMIT_ISSUE_BDD_NOTES.md) | Baru: status dan pemetaan step yang diperlukan |
| [Laporan ini](SUBMIT_ISSUE_SPRINT1_AUDIT.md) | Baru: hasil audit dan batas integrasi |

Migration issues dan User dibaca tetapi tidak diubah. Relasi `project_id`, `author_id`
dan `duplicate_of` sudah sesuai. FK project restrict-delete, author/duplicate nullable
null-on-delete dipertahankan. Migration Project harus mendahului issues; duplicate_candidates
harus sesudah issues. Belum ada validasi eksekusi skema lintas-modul/MySQL.

## 4. Hasil pengujian

Dependensi Composer dipasang dari lockfile menggunakan `composer install --no-interaction
--prefer-dist --no-scripts`; tidak ada upgrade package atau script migration Composer.
Runtime final PHP 8.3.35, Laravel 12.70.0, PHPUnit 11.5.57.

| Pemeriksaan | PASS | FAIL | SKIPPED / belum jalan |
| --- | ---: | ---: | ---: |
| Login/RBAC | 7 | 0 | 0 |
| Logout | 4 | 0 | 0 |
| Register | 7 | 0 | 0 |
| NlpClient (HTTP fake, bukan FastAPI hidup) | 13 | 0 | 0 |
| Issue model contract | 2 | 0 | 0 |
| Gate Submit guest/Maintainer | 3 | 0 | 0 |
| Isolasi SQLite | 1 | 0 | 0 |
| Existing example tests | 2 | 0 | 0 |
| Submit membutuhkan Reza saja | 0 | 0 | 17 |
| Submit membutuhkan Reza + NLP Queue Valentino | 0 | 0 | 8 |
| **Total PHPUnit lokal** | **39** | **0** | **25** |
| Guard SQLite dengan env shell dummy MySQL/local | 1 | 0 | 0 |
| Load class Job asli snapshot Draft #22 (proses terpisah) | 0 | **1** | 0 |
| Behat/Mink | — | — | NOT RUN |

Perintah utama dari backend:

```sh
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --display-skipped --testdox
```

Hasil: 64 tests, 130 assertions, 25 skipped. Semua skip saat ini menyebut Project
belum tersedia; grouping menyatakan dependensi lanjut, bukan hasil integrasi yang lulus.
Test khusus job absen akan skipped dengan alasan berbeda sesudah Job benar-benar terintegrasi.

Untuk memisahkan suite setelah integrasi:

```sh
php vendor/bin/phpunit --exclude-group project-integration
php vendor/bin/phpunit --group project-integration --exclude-group nlp-integration --display-skipped
php vendor/bin/phpunit --group nlp-integration --display-skipped
```

Guard teruji saat shell memasang `DB_CONNECTION=mysql`, `DB_DATABASE=do_not_use`,
`DB_URL=mysql://invalid.invalid/do_not_use`, `APP_ENV=local`: tetap hanya SQLite
memory, 1 PASS / 8 assertions. Tidak ada koneksi ke MySQL.
PHP syntax check app/bootstrap/routes/tests PASS. Pemeriksaan editor tidak menemukan error.
`git diff --check` PASS; tidak ada perubahan pada file NLP atau composer.lock.
Percobaan awal memakai PHP default 8.5.3 mengeluarkan 2 deprecation; verifikasi final
menggunakan PHP 8.3 yang dipakai proyek tanpa deprecation. Jangan menjadikan hasil awal
sebagai sertifikasi PHP 8.5.

## 5. Dependensi dan kontrak Draft PR #22

PR #22 dibaca melalui GitHub; head `df9381a33d0888badbf18405c46c6157ad8e28b2`
sesuai referensi lokal `origin/feature/nlp-database-queue`. Status saat audit: open/draft.
Project dibaca dari snapshot lokal `origin/feature/project-membership` pada `cb73243`.
Tidak checkout, cherry-pick, merge, atau menyalin modul tersebut ke branch ini.

| Kontrak | Hasil audit |
| --- | --- |
| ProjectService `canAccessProject(User, Project)` | Ada di snapshot Reza, belum ada di branch ini; owner ATAU membership aktif |
| Project memberships `user_id`, `is_active` | Sesuai query Submit; model/migration belum terintegrasi |
| Route Project | Snapshot Reza: `projects.show` memakai `{id}`, view belum memiliki tombol Submit |
| Job `__construct(int $issueId)`, ShouldQueue, database/nlp | Sesuai dispatch controller, tetapi class gagal load akibat trait |
| Issue fields | `id`, `project_id`, `title`, `description`, `detection_status` sesuai pembacaan Job |
| Detection enum | PENDING/PROCESSING/COMPLETED/FAILED konsisten; fillable status diperbaiki |
| Candidate fields | Draft memakai `issue_id`, `candidate_issue_id`, `similarity_score` di tabel terpisah, bukan duplicate_of |
| NlpClient | Sudah ada dan unit tests PASS; FastAPI tidak dipanggil sinkron oleh Submit |
| Model/migration DuplicateCandidate | Belum tersedia di branch ini; diperlukan worker persistResults, termasuk hasil kosong |

## 6. Risiko integrasi dan tindakan berikutnya

**Reza:** integrasikan model, service, migration dan UI asli. Sertakan partial berikut
di area aksi view `projects.show` sesudah file itu tersedia:

```blade
@include('issues.submit-link', ['project' => $project])
```

Gunakan route names Joshua yang sudah ada untuk landing Login/Logout; jangan menduplikasi
route dengan nama sama ketika menggabungkan routes Project. Verifikasi active/inactive
membership, role gate, detail antar-project dan rendering layout pada suite asli.

**Valentino:** perbaiki deklarasi `$afterCommit` di Job pada pekerjaan integrasinya:
hapus deklarasi properti yang konflik dan panggil `$this->afterCommit()` di constructor
melalui trait. Ini rekomendasi, **belum diterapkan di branch Valentino atau di sini**.
Fatal komposisi trait tidak bisa dianggap sebagai exception enqueue biasa yang aman
ditangkap controller; setelah commit issue dapat tetap PENDING dan response gagal.

Sesudah blocker class load selesai, jalankan worker tests dengan Job/migration asli.
Job menetapkan tiga attempts dengan backoff 10/30/60; retry 503 harus meninggalkan
PROCESSING sampai sukses/final failure. Jangan mengubah threshold produksi 0.75.
Test Draft #22 sendiri memakai ID Project/User hardcoded tanpa fixture nyata;
perlu penyesuaian di integrasi dengan foreign keys aktif.

Satu submit memiliki satu dispatch site, tanpa observer dispatch tambahan di branch ini.
Test commit/rollback dan count job menargetkan pengiriman ganda akibat callback bocor.
Namun dua request POST terpisah masih membuat dua issue; tidak ada idempotency key.
Queue juga memiliki semantik at-least-once: redelivery berurutan diuji dalam test yang
masih skipped, sedangkan concurrency dua worker belum terbukti aman. Job Draft #22
tidak memiliki lock per issue dan `failed()` tidak mengecualikan issue COMPLETED;
review race worker gagal yang menimpa hasil sukses diperlukan pada integrasi Valentino.

Tidak ada transactional outbox/reconciler. Crash setelah commit sebelum enqueue dapat
meninggalkan PENDING; failure update FAILED ketika DB terputus juga memerlukan pemulihan
berdasarkan log. Controller tidak menghapus issue atau membuat issue baru saat retry.

## 7. Kesiapan end-to-end dan batas pekerjaan

**Belum siap E2E.** Local auth/model checks lulus, tetapi submit valid, membership,
foreign key Project, rendering dengan data Project asli, database queue, final worker
failure/retry, dan navigasi dari halaman Project belum tervalidasi bersama.
Selain dependensi belum terintegrasi, class Job asli Draft #22 memiliki FAIL nyata.
BDD membutuhkan pekerjaan runner/context yang dirinci dalam [catatan BDD](bdd/SUBMIT_ISSUE_BDD_NOTES.md).

Tidak ada commit, push, merge, atau PR baru. Branch Reza dan Valentino tidak diubah.
Model NLP, threshold 0.75, file/hasil eksperimen, Top 3 pre-submit, serta fitur Vote
tidak diubah. Migration hanya dijalankan oleh test terhadap SQLite memory yang dibuang;
tidak ada migration destruktif terhadap database development.
