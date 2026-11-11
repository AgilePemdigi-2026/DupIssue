# Hasil Uji — Feature 10 / REQ-05

- **PIC**: Fakhri Reza Abinaya (REZA) — Kelompok 4, Kelas I1
- **Commit SHA / branch**: `feature/sprint2-duplicate-review` (Revisi PR #38 — commit `dea11c6`)
- **Tanggal WIB**: 2026-11-11 18:04 WIB
- **Lingkungan uji dan DB terisolasi**: PHP 8.3.30 (Win32, VS16, x64), Laravel 12.x, SQLite in-memory (`:memory:`, dikonfirmasi via `phpunit.xml`)
- **Perintah aktual**:
  ```bash
  php artisan test --filter=DuplicateReviewCandidatesTest
  php artisan test --filter=SubmitIssueTest
  php artisan test
  ```
- **Test/scenario/step yang dijalankan**:
  - `DuplicateReviewCandidatesTest`: 22 feature tests, 130 assertions
  - `SubmitIssueTest`: 25 feature tests, 253 assertions
  - Full suite (`php artisan test`): 264 tests, 1520 assertions
- **Hasil**:
  - `DuplicateReviewCandidatesTest`: **PASS 22** / **FAIL 0** / **SKIP 0** (130 assertions)
  - `SubmitIssueTest`: **PASS 24** / **FAIL 0** / **SKIP 1** (253 assertions)
  - Full test suite: **PASS 262** / **FAIL 0** / **SKIP 2** (1520 assertions)
- **Detail SKIPPED**:
  1. `Tests\Feature\Nlp\SubmitIssueQueueIntegrationTest > submit database worker and live fastapi` — Alasan: "FastAPI live opt-in: set NLP_LIVE_TEST_URL to a running local server".
  2. `Tests\Feature\SubmitIssueTest > missing job keeps issue and marks detection failed` — Alasan: "Only applicable before the real NLP Job is integrated".
- **Bukti**:
  - Test file: `backend/tests/Feature/DuplicateReviewCandidatesTest.php`
  - Controller: `backend/app/Http/Controllers/DuplicateReviewController.php`
  - BDD Feature: `docs/bdd/sprint2/req-05-validasi-duplikat.feature`
- **Perbaikan Revisi PR #38 (Review Joshua — putaran kedua)**:
  1. **Pair History Filter (Item 5)**: `compare()` kini memfilter `pairHistory` dari `activity_logs` untuk hanya menyertakan log yang pasangan issue-nya (`issue_id` + `candidate_issue_id` dari JSON kolom `new_values`/`old_values`) tepat sama dengan pasangan sumber dan kandidat yang sedang ditinjau. Log yang melibatkan issue ketiga yang tidak terkait dikeluarkan.
  2. **BDD Assertions yang nyata (Item 3)**:
     - `sayaMendapatErrorValidasi()`: memeriksa session errors (melalui `RedirectResponse->getSession()` dan session global) lalu fallback ke teks halaman setelah mengikuti redirect. Tidak lagi `return;` tanpa pemeriksaan.
     - `responStatusDenganPesan()`: memeriksa status code DAN konten respons/teks halaman mengandung pesan yang diharapkan.
     - `responRedirectDenganFlash()`: memeriksa status 302/301 DAN flash key ada di session (`RedirectResponse->getSession()` atau session global).
     - `postWithCsrf()`: diperbaiki untuk memanggil `followRedirects(false)` agar status 302 tertangkap dengan benar; menyimpan `lastResponse` untuk inspeksi session.
  3. **REQ-05.13 — Retry mempertahankan keputusan manual (Item 4)**:
     - Test baru `test_req05_13_retry_nlp_preserves_manual_decisions_and_filters_invalid_candidates()`: membuktikan bahwa pada retry NLP, kandidat DUPLICATE/NOT_DUPLICATE dipertahankan, kandidat PENDING diganti, dan kandidat tidak valid (project lain, diri sendiri, ID tidak ada, skor rendah) difilter.
  4. **REQ-05.14 — Kegagalan transaksi keputusan (Item 4)**:
     - `decide()` kini dibungkus `try/catch` di luar `DB::transaction`. `HttpException` (409/422/404) tetap propagate untuk guard logis. Exception lain (kegagalan DB) di-rollback otomatis, dicatat via `Log::error`, dan dikembalikan pesan generik yang bisa diulang ke user (REQ-NF-24).
     - Test baru `test_req05_14_decision_transaction_failure_rolls_back_cleanly_and_shows_actionable_error()`: mensimulasikan kegagalan DB saat insert activity_log, memverifikasi rollback penuh (tidak ada perubahan status issue/kandidat) dan flash error yang actionable.
  5. **Pair History Test (Item 5)**:
     - Test baru `test_compare_page_only_shows_history_for_the_specific_issue_pair()`: memverifikasi halaman compare hanya menampilkan log yang relevan dengan pasangan yang ditinjau, bukan log pasangan lain.
  6. **Behat Sprint 2 (Item 2)**: Suite sprint2 diperbarui — `34 scenarios (34 passed), 285 steps (285 passed)`. Semua assertion sekarang real (tidak ada dummy `return;`).

---

## Status BDD

**Status: BDD: EXECUTED (PASS 34 / FAIL 0 / SKIP 0)**

Hasil eksekusi aktual (revisi kedua, 2026-11-11):
1. Suite `sprint2` dikonfigurasi di `backend/behat.yml` dengan context `DuplicateReviewBddContext.php` (`backend/features/bootstrap/DuplicateReviewBddContext.php`).
2. Seluruh 20 skenario Gherkin REQ-05 dari SRS Tabel 4.28 di `docs/bdd/sprint2/req-05-validasi-duplikat.feature` dijalankan bersama suite sprint2 yang termasuk skenario dari REQ-08 (feature/rekomendasi) dan REQ-02 (issue status).
3. Hasil eksekusi:
   - **34 scenarios (34 passed)**
   - **285 steps (285 passed)**
   - **Waktu**: 0m25.74s
4. Bukti log lengkap terekam di `docs/testing/sprint2/reza-req05-behat-output.txt`.

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
| REQ-05.13 | Retry mempertahankan keputusan manual & menyaring kandidat tidak valid | Terpenuhi | `test_req05_13_retry_nlp_preserves_manual_decisions_and_filters_invalid_candidates` — membuktikan bahwa DUPLICATE/NOT_DUPLICATE dipertahankan, PENDING diganti, kandidat cross-project/self/non-existent/low-score difilter oleh `persistResults()` di `DetectIssueCandidatesJob` |
| REQ-05.14 | Kegagalan transaksi penyimpanan keputusan membatalkan seluruh perubahan & tampilkan pesan actionable | Terpenuhi | `test_req05_14_decision_transaction_failure_rolls_back_cleanly_and_shows_actionable_error` — simulasi DB failure saat insert activity_log, verifikasi rollback penuh dan flash error `'Terjadi kesalahan sistem saat menyimpan keputusan validasi. Silakan muat ulang halaman dan coba lagi.'` |
