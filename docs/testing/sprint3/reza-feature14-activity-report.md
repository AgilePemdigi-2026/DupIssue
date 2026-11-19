# Hasil Uji Sprint 3 — Feature 14: Riwayat & Log
- PIC dan tanggal WIB: Fakhri Reza (NIM: 187241093), 18 November 2026 WIB
- Requirement SRS: REQ-07.7, REQ-03.12, REQ-04.8, REQ-06.11 (sesuai panduan tim)
- Branch/commit/PR: Branch: `feature/sprint3-activity-log` / Commit: (diisi setelah PR dibuat) / PR: (diisi setelah PR dibuat)
- Lingkungan (PHP, MySQL/SQLite, FastAPI, queue) dan database terisolasi:
  - PHP: 8.3.30 (Laragon Win32-vs16-x64)
  - SQLite: in-memory `:memory:` untuk automated testing suite
  - MySQL: 8.4.3 LTS (teruji pada database terisolasi `dupissue_test_isolated` di 127.0.0.1:3306 untuk sintaks ANSI `LIKE ... ESCAPE '~'`)
  - Queue: sync (lingkungan pengujian lokal)
  - FastAPI: NLP client mock/contract stub
- Perintah yang benar-benar dijalankan:
  - `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=ActivityLogModelTest`
  - `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=ActivityLogViewAndExportTest`
  - `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test --filter=ProjectActivityLogTest`
  - `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test` (Full Test Suite)
- Total: PASS 327 / FAIL 0 / SKIPPED 2 / assertions 1919:
  - Durasi eksekusi seluruh suite: 32.30 detik
  - `Tests\Unit\ActivityLogModelTest`: 7 passed (28 assertions)
  - `Tests\Feature\ActivityLogViewAndExportTest`: 19 passed (105 assertions)
  - `Tests\Feature\ProjectActivityLogTest`: 17 passed (138 assertions)
  - Regresi modul lain: 284 tests passed
  - Skipped tests: 2 tests (bawaan repository lama)
- Skenario BDD yang benar-benar dieksekusi dan hasil:
  Belum dieksekusi via Behat runner, karena fokus tahapan ini adalah unit dan feature testing komprehensif berbasis PHPUnit/Laravel Test. Skenario Behat untuk Feature 14 akan dikonsolidasikan bersama BDD runner tim pada tahap finalisasi artefak UAS/sprint review.
- Bukti screenshot/log yang aman:
  - Log audit trail read-only terverifikasi: method `save()` (pada entitas existing) dan `delete()` melempar `BadMethodCallException`.
  - Percobaan HTTP verb mutasi (`POST`, `PUT`, `PATCH`, `DELETE`) ke `/projects/{project}/activity` terbukti ditolak dengan HTTP 405 Method Not Allowed.
  - Sanitasi nilai sensitif (`token`, `password`, `pat`, `api_key`, `secret`) terbukti tersamarkan menjadi `***REDACTED***` pada tampilan HTML maupun file ekspor CSV, tanpa false-positive pada kolom umum seperti `path` atau `status`.
  - Proteksi formula injection pada CSV streaming (chunk 200, UTF-8 BOM) terbukti menetralkan karakter `=`, `+`, `-`, `@`, `\t`, dan `\r` (termasuk yang diawali spasi) dengan awalan tanda petik tunggal (`'`).
  - Target issue foreign project ditampilkan terisolasi dengan teks `#ID (Terisolasi)` tanpa tautan hyperlink.
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
- Regression modul lain:
  Tidak ada regresi. Seluruh modul existing (Project & Keanggotaan, Status Issue, Review Duplikat, dan Voting) lulus 100%. Audit kode mengonfirmasi seluruh producer log (`ProjectService`, `IssueStatusController`, `DuplicateReviewController`, `VoteController`) telah menulis log aktivitas secara atomik di dalam `DB::transaction()`.
- Gap atau penerimaan yang masih pending:
  1. *Aksi `IMPORT_CSV` dan `IMPORT_GITHUB`*: Berada di PR #48 (`feature/sprint3-issue-sources`) yang belum di-merge ke `origin/main`. Mapping model telah disiapkan (*forward-compatible*), namun pengujian end-to-end penulisan log dari sumber issue menunggu PR #48 terintegrasi.
  2. *Sinkronisasi Terakhir GitHub*: Kartu ringkasan saat ini menampilkan fallback `"Belum pernah"` karena konfigurasi dan log GitHub belum tersedia di `main`. Perlu dicek ulang setelah PR #48 di-merge ke main.
  3. *Keterbatasan Pengukuran Performa*: Target non-fungsional REQ-NF-01 (<3 detik pada 5.000 issue) dan REQ-NF-04 (ekspor CSV 5.000 baris) belum diuji pada dataset skala 5.000 issue terisolasi; durasi yang terukur adalah waktu eksekusi automated test suite (32.30s untuk 327 tests).
- Status: REVIEW
