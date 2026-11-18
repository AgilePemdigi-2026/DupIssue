# Hasil Uji — Feature 10 / REQ-05

- **PIC**: Fakhri Reza Abinaya (REZA) — Kelompok 4, Kelas I1
- **Commit SHA / branch**: `feature/sprint2-duplicate-review` (Revisi PR #38 — sinkronisasi dengan `origin/main` pasca merge Voting PR #37)
- **Tanggal WIB**: 2026-11-11 21:30 WIB
- **Lingkungan uji dan DB terisolasi**: PHP 8.3.30 (Win32, VS16, x64), Laravel 12.x, SQLite in-memory (`:memory:`, dikonfirmasi via `phpunit.xml` dan `.env.behat`)
- **Perintah aktual**:
  ```bash
  php artisan test --filter=DuplicateReviewCandidatesTest
  php artisan test --filter=IssueVotingTest
  php artisan test --filter=SubmitIssueTest
  php artisan test
  php ./vendor/bin/behat --config=behat.yml --suite=sprint2
  ```
- **Test/scenario/step yang dijalankan**:
  - `DuplicateReviewCandidatesTest`: 23 feature tests, 150 assertions (termasuk full cross-feature voting integration test)
  - `IssueVotingTest`: 21 feature tests, 107 assertions
  - `SubmitIssueTest`: 25 feature tests, 253 assertions
  - Full suite (`php artisan test`): 284 passed, 2 skipped, 1647 assertions
  - Behat sprint2: 39 scenarios, 323 steps
- **Hasil**:
  - `DuplicateReviewCandidatesTest`: **PASS 23** / **FAIL 0** / **SKIP 0** (150 assertions)
  - `IssueVotingTest`: **PASS 21** / **FAIL 0** / **SKIP 0** (107 assertions)
  - `SubmitIssueTest`: **PASS 24** / **FAIL 0** / **SKIP 1** (253 assertions)
  - Full test suite: **PASS 284** / **FAIL 0** / **SKIP 2** (1647 assertions)
  - Behat Sprint 2 suite: **PASS 39 scenarios** / **PASS 323 steps** (0 failed, 0 skipped)
- **Detail SKIPPED**:
  1. `Tests\Feature\Nlp\SubmitIssueQueueIntegrationTest > submit database worker and live fastapi` — Alasan: "FastAPI live opt-in: set NLP_LIVE_TEST_URL to a running local server".
  2. `Tests\Feature\SubmitIssueTest > missing job keeps issue and marks detection failed` — Alasan: "Only applicable before the real NLP Job is integrated".
- **Bukti**:
  - Test file: `backend/tests/Feature/DuplicateReviewCandidatesTest.php`
  - Voting test file: `backend/tests/Feature/IssueVotingTest.php`
  - Controller: `backend/app/Http/Controllers/DuplicateReviewController.php`
  - BDD Feature: `docs/bdd/sprint2/req-05-validasi-duplikat.feature`
  - Behat Output Log: `docs/testing/sprint2/reza-req05-behat-output.txt`
- **Penyelesaian Review Joshua — Poin 1 s/d 6 (Integrasi Main & PR #37)**:
  1. **Sinkronisasi Main (Poin 1)**: Branch `feature/sprint2-duplicate-review` berhasil dimerge dengan `origin/main` (setelah PR #37 Voting dimerge). Seluruh konflik di `Issue.php`, `behat.yml`, dan `routes/web.php` diselesaikan secara 3-way dengan mempertahankan kedua fitur.
  2. **Integrasi Voting & Duplicate Review (Poin 2)**:
     - Model `Issue.php` mempertahankan relasi `votes(): HasMany` dan `duplicateCandidates(): HasMany`.
     - `web.php` mempertahankan route voting (`issues.vote`, `issues.unvote`) serta seluruh route duplicate review (`projects.duplicates.index`, `issues.duplicates.compare`, `issues.duplicates.decide`, `issues.duplicates.cancel`, `issues.detection.retry`).
     - Test integrasi baru ditambahkan di `DuplicateReviewCandidatesTest::test_voting_and_duplicate_review_full_cross_feature_integration()`: membuktikan reporter aktif voting -> vote_count bertambah -> compare page menyarankan parent berdasarkan vote terbanyak -> maintainer konfirmasi DUPLICATE -> vote pada child diarahkan ke parent -> unvote mengurangi vote parent tanpa duplikasi atau korupsi status.
  3. **Behat Sprint 2 Suite (Poin 2 & 3)**:
     - Konfigurasi `behat.yml` suite sprint2 mendaftarkan seluruh 6 contexts: `RootContext`, `FeatureContext`, `IssueRecommendationBddContext`, `IssueStatusBddContext`, `VoteBddContext`, dan `DuplicateReviewBddContext`.
     - Step definitions `DuplicateReviewBddContext` melakukan assertion nyata (memeriksa session errors, status code + content text, dan flash message di session).
     - Hasil eksekusi Behat: 39 scenarios passed, 323 steps passed (0 failed).
  4. **Filter Riwayat Keputusan Pasangan (Poin 5)**: `compare()` memfilter `pairHistory` dari `activity_logs` hanya untuk log yang pasangan issue-nya tepat cocok dengan pasangan yang sedang dibandingkan.
  5. **Verifikasi Retry NLP & Concurrency (Poin 1 & 6)**:
     - Klaim atomik bersyarat: update status dengan `WHERE detection_status = 'FAILED'`. Jika row terdampak 0, tampilkan pesan status tanpa men-dispatch ulang.
     - Dispatch dibungkus try/catch; jika dispatch gagal, status dipulihkan ke FAILED secara bersyarat tanpa menimpa status worker lain.
     - Keputusan manual DUPLICATE/NOT_DUPLICATE dipertahankan saat retry (REQ-05.13).
  6. **Penanganan Transaksi Keputusan (REQ-05.14)**:
     - `decide()` dibungkus dalam `try/catch` di luar `DB::transaction`. Kegagalan transaksi DB di-rollback penuh dan mengembalikan pesan error generik yang actionable bagi pengguna.

---

## Status BDD

**Status: BDD: EXECUTED (PASS 39 / FAIL 0 / SKIP 0)**

Hasil eksekusi aktual:
1. Suite `sprint2` dikonfigurasi di `backend/behat.yml` dengan context `DuplicateReviewBddContext.php` dan `VoteBddContext.php`.
2. Seluruh 20 skenario Gherkin REQ-05 dari SRS Tabel 4.28 di `docs/bdd/sprint2/req-05-validasi-duplikat.feature` dijalankan bersama suite sprint2 yang mencakup REQ-08 (rekomendasi), REQ-02 (status), dan REQ-01 (voting).
3. Hasil eksekusi:
   - **39 scenarios (39 passed)**
   - **323 steps (323 passed)**
   - **Waktu**: 0m27.77s
4. Bukti log lengkap terekam di `docs/testing/sprint2/reza-req05-behat-output.txt`.

---

## Matriks Keterpenuhan REQ-05.1 s/d REQ-05.14 (Sesuai SRS Resmi)

| REQ | Deskripsi SRS | Status | Bukti Uji / Implementasi |
|---|---|---|---|
| REQ-05.1 | Halaman terpusat daftar kandidat duplikat per project bagi Maintainer pemilik project | Terpenuhi | `test_maintainer_can_view_centralized_duplicate_candidates_list`, `test_security_access_restrictions_to_duplicates_index` |
| REQ-05.2 | Menampilkan issue dengan status deteksi FAILED beserta opsi proses ulang | Terpenuhi | `test_maintainer_can_view_centralized_duplicate_candidates_list`, `test_retry_when_failed_dispatches_exactly_one_job_and_sets_status_to_pending` |
| REQ-05.3 | Perbandingan berdampingan (side-by-side) dua issue (ID, judul, deskripsi, status, vote, asal, waktu) | Terpenuhi | `test_maintainer_can_view_side_by_side_comparison`, `test_compare_rejects_cross_project_and_unauthorized_users` |
| REQ-05.4 | Skor kemiripan NLP hanya rekomendasi/indikator awal, bukan keputusan otomatis sistem | Terpenuhi | Controller tidak pernah mengubah status issue secara otomatis dari skor; keputusan manual oleh Maintainer |
| REQ-05.5 | Validasi server-side aturan relasi duplikat (induk ≠ duplikat, satu project, induk bukan duplikat, tanpa siklus, data versi terbaru) | Terpenuhi | `test_validation_relations_server_side_enforcement` (422 invalid parent, 409 parent is duplicate, 409 cycle) |
| REQ-05.6 | Rekomendasi induk otomatis berbasis vote terbanyak serta ringkasan konsekuensi status sebelum konfirmasi | Terpenuhi | `test_maintainer_can_view_side_by_side_comparison` (assertSee 'Disarankan — Vote Terbanyak', 'Aturan Relasi Duplikat', 'Ringkasan Konsekuensi Status') |
| REQ-05.7 | Konfirmasi penetapan DUPLICATE: simpan `decision=DUPLICATE`, `decided_by`, `decided_at`, `duplicate_of`, status child, dan activity log | Terpenuhi | `test_confirm_duplicate_marks_child_closed_if_parent_resolved_or_closed`, `test_voting_and_duplicate_review_full_cross_feature_integration` |
| REQ-05.8 | Jika issue induk berstatus RESOLVED atau CLOSED, status issue anak langsung menjadi CLOSED | Terpenuhi | `test_confirm_duplicate_marks_child_closed_if_parent_resolved_or_closed` |
| REQ-05.9 | Penandaan Bukan Duplikat (NOT_DUPLICATE) mempertahankan status kedua issue dan mencatat log | Terpenuhi | `test_confirm_not_duplicate_leaves_statuses_unchanged_and_logs` |
| REQ-05.10 | Review ulang pasangan yang pernah ditolak: riwayat lama tidak ditimpa, keputusan baru menambah histori | Terpenuhi | `test_re_review_rejected_candidate_appends_to_activity_logs`, `test_compare_page_only_shows_history_for_the_specific_issue_pair` |
| REQ-05.11 | Pembatalan penandaan duplikat wajib alasan koreksi (10-500 char), mengembalikan status OPEN, dan menghapus `duplicate_of` | Terpenuhi | `test_cancel_duplicate_requires_valid_reason_and_resets_child_to_open` |
| REQ-05.12 | Proses ulang (retry) deteksi duplikat hanya diperbolehkan untuk issue dengan `detection_status = FAILED` | Terpenuhi | `test_retry_when_already_pending_dispatches_zero_additional_jobs`, `test_retry_when_completed_dispatches_zero_jobs_and_preserves_status`, `test_retry_when_processing_dispatches_zero_jobs_and_preserves_status` |
| REQ-05.13 | Proses ulang (retry) mempertahankan keputusan manual yang sudah ada (DUPLICATE/NOT_DUPLICATE) dan menyaring kandidat tidak valid | Terpenuhi | `test_req05_13_retry_nlp_preserves_manual_decisions_and_filters_invalid_candidates` |
| REQ-05.14 | Kegagalan transaksi penyimpanan keputusan membatalkan seluruh perubahan (rollback) dan memberikan pesan error yang dapat ditindaklanjuti | Terpenuhi | `test_req05_14_decision_transaction_failure_rolls_back_cleanly_and_shows_actionable_error` |
