# Hasil Uji — Feature 10 / REQ-05

- **PIC**: Fakhri Reza Abinaya (REZA) — Kelompok 4, Kelas I1
- **Commit SHA / branch**: `feature/sprint2-duplicate-review` (Revisi PR #38 pasca-merge `origin/main` commit `1bf6907`)
- **Tanggal WIB**: 2026-11-04 20:35 WIB
- **Lingkungan uji dan DB terisolasi**: PHP 8.3.30 (Win32, VS16, x64), Laravel 12.x, SQLite in-memory (`:memory:`, dikonfirmasi via `phpunit.xml`)
- **Perintah aktual**:
  ```bash
  php artisan test --filter=DuplicateReviewCandidatesTest
  php artisan test --filter=SubmitIssueTest
  php artisan test
  ```
- **Test/scenario/step yang dijalankan**:
  - `DuplicateReviewCandidatesTest`: 19 feature tests, 111 assertions
  - `SubmitIssueTest`: 25 feature tests, 253 assertions
  - Full suite (`php artisan test`): 235 tests, 1343 assertions
- **Hasil**:
  - `DuplicateReviewCandidatesTest`: **PASS 19** / **FAIL 0** / **SKIP 0** (111 assertions)
  - `SubmitIssueTest`: **PASS 24** / **FAIL 0** / **SKIP 1** (253 assertions)
  - Full test suite: **PASS 233** / **FAIL 0** / **SKIP 2** (1343 assertions)
- **Detail SKIPPED**:
  1. `Tests\Feature\Nlp\SubmitIssueQueueIntegrationTest > submit database worker and live fastapi` — Alasan: "FastAPI live opt-in: set NLP_LIVE_TEST_URL to a running local server".
  2. `Tests\Feature\SubmitIssueTest > missing job keeps issue and marks detection failed` — Alasan: "Only applicable before the real NLP Job is integrated".
- **Bukti**:
  - Test file: `backend/tests/Feature/DuplicateReviewCandidatesTest.php`
  - Controller: `backend/app/Http/Controllers/DuplicateReviewController.php`
  - BDD Feature: `docs/bdd/sprint2/req-05-validasi-duplikat.feature`
- **Temuan bug (Review Joshua)**:
  1. Status menggantung di `PENDING` jika dispatch job NLP gagal pada retry.
  2. Kurang tes konkurensi di level endpoint HTTP (bukan sekadar query DB) dan simulasi kegagalan dispatch.
  3. Belum ada tombol/tautan navigasi "Review Kandidat Duplikat" dari halaman Project / Issue untuk Maintainer pemilik.
- **Perbaikan dan retest**:
  1. Implementasi klaim atomik bersyarat (`WHERE id=? AND project_id=? AND detection_status='FAILED'`) dan pemulihan bersyarat `PENDING` → `FAILED` dalam `try/catch` aman jika dispatch gagal. Pemulihan tidak menimpa status jika worker lain telah memproses (`PROCESSING`/`COMPLETED`). Error dicatat via `Log::error`.
  2. Penambahan 8 tes baru di `DuplicateReviewCandidatesTest` (total 19 tests) untuk menguji: retry saat FAILED (1 job), retry saat PENDING/COMPLETED/PROCESSING (0 job), dispatch failure recovery (kembali FAILED tanpa menggantung), 2 competing requests (1 job), stale read race condition (1 job), proteksi hak akses (Guest, Reporter, Non-owner Maintainer ditolak), proteksi cross-project (404), dan recovery race guard (tidak menimpa status `PROCESSING`/`COMPLETED` milik worker).
  3. Penambahan tautan "Review Kandidat Duplikat" di `backend/resources/views/projects/show.blade.php` dan `backend/resources/views/issues/index.blade.php` dengan pengecekan ganda di Blade: role `MAINTAINER` dan `owner_id === auth()->id()`.
- **Keputusan reviewer**: Menunggu review ulang Joshua (Tech Lead).

---

## Status BDD

**Status: BDD: NOT EXECUTED**

Alasan faktual:
1. PR #40 yang mengintegrasikan Behat Sprint 1 telah di-merge ke `main`, namun dependensi vendor Behat belum terpasang di environment lokal (memerlukan unduhan paket baru via `composer install`).
2. File konfigurasi `behat.yml` di `main` saat ini hanya mendefinisikan suite `sprint1` (`features/sprint1`), belum memiliki konfigurasi suite Sprint 2 maupun step definitions untuk REQ-05.
3. Skenario Gherkin lengkap sesuai SRS Tabel 4.28 telah didokumentasikan di `docs/bdd/sprint2/req-05-validasi-duplikat.feature` dan siap dieksekusi begitu runner Sprint 2 tersedia.

---

## Matriks Keterpenuhan REQ-05.1 s/d REQ-05.14

| REQ | Deskripsi | Status | Bukti Uji / Implementasi |
|---|---|---|---|
| REQ-05.1 | Halaman terpusat daftar kandidat duplikat per project | Terpenuhi | `test_maintainer_can_view_centralized_duplicate_candidates_list`, `test_security_access_restrictions_to_duplicates_index` |
| REQ-05.2 | Menampilkan issue deteksi FAILED + tombol proses ulang | Terpenuhi | `test_maintainer_can_view_centralized_duplicate_candidates_list`, `test_retry_when_failed_dispatches_exactly_one_job_and_sets_status_to_pending` |
| REQ-05.3 | Perbandingan berdampingan (side-by-side) dua issue | Terpenuhi | `test_maintainer_can_view_side_by_side_comparison`, `test_compare_rejects_cross_project_and_unauthorized_users` |
| REQ-05.4 | Skor NLP hanya rekomendasi, bukan keputusan otomatis | Terpenuhi | Controller tidak mengubah status issue dari skor; keputusan manual oleh Maintainer |
| REQ-05.5 | Tampilkan aturan relasi dan ringkasan konsekuensi status | Terpenuhi | `test_maintainer_can_view_side_by_side_comparison` (assertSee 'Aturan Relasi Duplikat', 'Ringkasan Konsekuensi Status') |
| REQ-05.6 | Rekomendasi induk otomatis berbasis vote terbanyak | Terpenuhi | `test_maintainer_can_view_side_by_side_comparison` (assertSee 'Disarankan — Vote Terbanyak') |
| REQ-05.7 | DUPLICATE mengubah status child ke DUPLICATE / CLOSED (jika parent RESOLVED/CLOSED) | Terpenuhi | `test_confirm_duplicate_marks_child_closed_if_parent_resolved_or_closed` |
| REQ-05.8 | NOT_DUPLICATE mempertahankan status issue | Terpenuhi | `test_confirm_not_duplicate_leaves_statuses_unchanged_and_logs` |
| REQ-05.9 | Pembatalan penandaan duplikat wajib alasan (min 10 char) & mencatat log | Terpenuhi | `test_cancel_duplicate_requires_valid_reason_and_resets_child_to_open` |
| REQ-05.10 | Validasi server: parent harus salah satu dari pasangan issue | Terpenuhi | `test_validation_relations_server_side_enforcement` (422 jika invalid parent) |
| REQ-05.11 | Validasi server: induk bukan duplikat lain & tidak membentuk siklus | Terpenuhi | `test_validation_relations_server_side_enforcement` (409 'Induk bukan duplikat', 409 'Tidak membentuk siklus') |
| REQ-05.12 | Retry hanya diperbolehkan untuk issue berstatus FAILED | Terpenuhi | `test_retry_when_already_pending_dispatches_zero_additional_jobs`, `test_retry_when_completed_dispatches_zero_jobs_and_preserves_status`, `test_retry_when_processing_dispatches_zero_jobs_and_preserves_status` |
| REQ-05.13 | Concurrency guard & dispatch failure handling pada retry | Terpenuhi | `test_retry_dispatch_failure_recovers_to_failed_without_hanging_and_logs_error`, `test_retry_competing_requests_and_stale_read_race_condition_simulation`, `test_retry_failure_recovery_does_not_overwrite_worker_processing_or_completed_status` |
| REQ-05.14 | Audit trail: setiap keputusan/koreksi dicatat ke activity_logs | Terpenuhi | `test_confirm_not_duplicate_leaves_statuses_unchanged_and_logs`, `test_re_review_rejected_candidate_appends_to_activity_logs`, `test_cancel_duplicate_requires_valid_reason_and_resets_child_to_open` |
