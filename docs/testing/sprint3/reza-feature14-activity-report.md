# Hasil Uji Sprint 3 — Feature 14: Riwayat & Log
- PIC dan tanggal WIB: Fakhri Reza (NIM: 187241093), 25 November 2026 WIB
- Requirement SRS: REQ-07.7, REQ-03.12, REQ-04.8, REQ-06.11, REQ-01.18 (sesuai panduan tim)
- Branch/commit/PR: Branch: `feature/sprint3-activity-log` / Commit: `618259b` (dan final review commit) / PR: #59
- Lingkungan (PHP, MySQL/SQLite, FastAPI, queue) dan database terisolasi:
  - PHP: 8.3.30 (Laragon Win32-vs16-x64)
  - SQLite: in-memory `:memory:` untuk automated testing suite & Behat
  - MySQL: 8.4.3 LTS (teruji pada database terisolasi `dupissue_test_isolated` di 127.0.0.1:3306 untuk sintaks ANSI `LIKE ... ESCAPE '~'`)
  - Queue: database / sync (lingkungan pengujian lokal)
  - FastAPI: NLP client mock/contract stub
- Perintah yang benar-benar dijalankan:
  - `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=ActivityLogModelTest`
  - `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=ActivityLogViewAndExportTest`
  - `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=ProjectActivityLogTest`
  - `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test` (Full Test Suite)
  - `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe vendor/bin/behat --config=behat.sprint3.activity.yml --suite=sprint3_activity` (BDD Suite)
- Total: PASS 522 / FAIL 0 / SKIPPED 2 / assertions 2937:
  - Durasi eksekusi seluruh suite: 50.66 detik
  - `Tests\Unit\ActivityLogModelTest`: 8 passed (46 assertions)
  - `Tests\Feature\ActivityLogViewAndExportTest`: 23 passed (134 assertions)
  - `Tests\Feature\ProjectActivityLogTest`: 17 passed (138 assertions)
  - Regresi seluruh modul main (Feature 11 CSV & GitHub Import, GitHub App, Feature 12 Kelola Status & Auto-close, Feature 13 Dashboard, Submit Issue, UI/UX Refinement PR #61): 474 tests passed
  - Skipped tests: 2 tests (bawaan repository lama sebelum Sprint 3)
- Skenario BDD yang benar-benar dieksekusi dan hasil:
  Telah dieksekusi via Behat runner (`vendor/bin/behat --config=behat.sprint3.activity.yml --suite=sprint3_activity`) pada konfigurasi terisolasi SQLite in-memory:
  - 5 scenarios (5 passed)
  - 41 steps (41 passed)
  - Durasi: 5.76 detik
  - Skenario teruji:
    1. `@empty_state`: Maintainer mengakses halaman activity log kosong (menampilkan teks status audit read-only aktif dan pesan empty state ramah pengguna).
    2. `@filtering`: Maintainer memfilter aktivitas berdasarkan kategori (`MEMBERSHIP`) dan kata kunci (`Undangan`), memverifikasi isolasi aktivitas asing (`Secret Foreign Log`).
    3. `@export_csv`: Maintainer mengekspor log ke file CSV (memverifikasi header waktu WIB, baris aksi, dan penolakan kebocoran data asing).
    4. `@authorization`: Reporter ditolak mengakses activity log (tidak ada tautan dan HTTP 403 saat akses langsung).
    5. `@authorization`: Maintainer dari project lain ditolak mengakses activity log project (HTTP 403 Forbidden).
- Bukti screenshot/log yang aman:
  - Log audit trail read-only terverifikasi: method `save()` (pada entitas existing) dan `delete()` melempar `BadMethodCallException`.
  - Percobaan HTTP verb mutasi (`POST`, `PUT`, `PATCH`, `DELETE`) ke `/projects/{project}/activity` terbukti ditolak dengan HTTP 405 Method Not Allowed.
  - Pencatatan pembuatan issue `CREATE_ISSUE` (REQ-01.18) pada `IssueController::store()` terbukti tercatat secara atomik dalam database transaction bersama mutasi penyimpanan issue.
  - Integrasi producer aktual (`CsvIssueImportService`, `GithubIssueImportService`, `GithubAppIssueImportService`) terbukti muncul pada Activity Log di bawah kategori `ISSUE_SOURCE`.
  - Sanitasi nilai sensitif (`token`, `password`, `pat`, `api_key`, `secret`, `refresh_token`, `client_secret`, `installation_access_token`, `pat_encrypted`, `password_confirmation`, `bearer_token`) terbukti tersamarkan menjadi `***REDACTED***` pada tampilan HTML maupun file ekspor CSV, tanpa false-positive pada kolom umum seperti `path`, `pattern`, atau `status`.
  - Sanitasi teks bebas (`sanitizeText`) terbukti menyamarkan token mentah GitHub (`ghp_...`, `github_pat_...`, `ghs_...`, dsb.) dan `Bearer ...` pada kolom alasan/keterangan.
  - Proteksi formula injection pada CSV streaming (chunk 200, UTF-8 BOM) terbukti menetralkan karakter `=`, `+`, `-`, `@`, `\t`, dan `\r` (termasuk yang diawali spasi) dengan awalan tanda petik tunggal (`'`).
  - Target issue foreign project: terbukti terisolasi penuh dengan menampilkan `-` tanpa identifier `#ID` maupun judul issue (zero data leakage).
  - Sinkronisasi ringkasan kartu "Sinkronisasi Terakhir GitHub" terverifikasi membaca status `COMPLETED` dari tabel `import_batches` dan `activity_logs` dengan format WIB, serta fallback `"Belum pernah"`.
  - Eager loading `with(['user', 'issue'])` membuktikan query database terikat (bounded) dan bebas masalah N+1.
- Bug + perbaikan + retest:
  1. *Bug Pemanggilan Relasi `Project::issues()`*: Terjadi error `Call to undefined method` karena model `Project.php` di `main` belum memiliki relasi `issues()`.
     - *Perbaikan*: Diganti kueri langsung `Issue::where('project_id', $project->id)->whereNotNull('duplicate_of')->count()` tanpa memodifikasi modul `Project.php`.
     - *Retest*: PASS.
  2. *Bug Return Type Hint pada Method `export()`*: Return type `StreamedResponse` menyebabkan `TypeError` saat validasi filter gagal dan me-redirect back.
     - *Perbaikan*: Return type hint disesuaikan menjadi `Symfony\Component\HttpFoundation\Response`.
     - *Retest*: PASS.
  3. *Bug Variabel `$project` pada Closure Chunking*: Terjadi `Undefined variable $project` pada penanganan target issue di dalam chunk CSV.
     - *Perbaikan*: Melewatkan `$project` ke dalam closure kueri `use ($handle, $project)`.
     - *Retest*: PASS.
  4. *Ambiguitas Karakter Escape LIKE Antara SQLite dan MySQL 8*: Sintaks `ESCAPE '\\'` memiliki interpretasi berbeda pada literal backslash antar-driver PDO MySQL dan SQLite.
     - *Perbaikan*: Menggunakan karakter escape standar ANSI SQL tilde `~` (`ESCAPE '~'`) via `str_replace(['~', '%', '_'], ['~~', '~%', '~_'], $keyword)`.
     - *Retest*: PASS 100% pada SQLite dan terverifikasi di MySQL 8.4.3.
  5. *Standarisasi Karakter HTML Entitas Tombol*: Penyesuaian teks tombol menjadi `Riwayat &amp; Log` (HTML valid).
     - *Retest*: PASS.
  6. *Penyelarasan UI/UX PR #61*: Penyesuaian container dan header activity index agar selaras dengan `di-page-header` tanpa nesting container ganda, serta menghapus tombol navigasi "Kembali ke Project" yang redundan di toolbar.
     - *Retest*: PASS.
  7. *Penguatan Zero Data Leakage Relasi Issue Asing*: Mengubah tampilan target issue dari `#ID (Terisolasi)` menjadi `-` sepenuhnya agar tidak membocorkan ID issue milik project lain.
     - *Retest*: PASS.
  8. *Pencatatan Audit Submit Issue (REQ-01.18)*: Mengisi gap existing pada `IssueController::store()` dengan mencatat aksi `CREATE_ISSUE` dalam transaksi DB.
     - *Retest*: PASS.
- Regression modul lain:
  Tidak ada regresi. Seluruh modul existing (Project & Keanggotaan, Status Issue, Review Duplikat, Voting, Submit Issue, Import CSV, GitHub PAT, GitHub App, dan Dashboard Statistik) lulus 100%. Audit kode mengonfirmasi seluruh producer log (`ProjectService`, `IssueStatusController`, `DuplicateReviewController`, `VoteController`, `IssueController`, `CsvIssueImportService`, `GithubIssueImportService`) telah menulis log aktivitas secara atomik dan konsisten.
- Gap atau catatan requirement:
  1. *Klarifikasi REQ-NF-04*: Diklarifikasi bahwa REQ-NF-04 dalam SRS v1.0 mengatur validasi dan impor file CSV hingga 5.000 baris (lingkup Feature 11), bukan ekspor CSV Activity Log. Performa ekspor Activity Log (Feature 14) diimplementasikan dengan `lazyById(200)` dan `StreamedResponse` untuk menjaga footprint memori tetap konstan terlepas dari ukuran dataset log.
- Status: REVIEW (Siap Final Review & Merge ke Main)
