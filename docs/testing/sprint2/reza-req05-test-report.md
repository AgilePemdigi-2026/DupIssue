# Laporan Pengujian REQ-05 – Review & Validasi Duplikat Issue

| Atribut       | Nilai                                                  |
|---------------|--------------------------------------------------------|
| Fitur         | REQ-05 Review & Validasi Duplikat Issue (Sprint 2)     |
| Author        | Fakhri Reza Abinaya (REZA) – Kelompok 4, Kelas I1     |
| Branch        | `feature/sprint2-duplicate-review`                     |
| Base commit   | `a3d4155` (main, 2026-11-03)                          |
| Tanggal run   | 2026-11-03                                             |
| PHP           | 8.3.30 (Win32, VS16, x64)                             |
| Laravel       | 12.x                                                   |
| DB (test)     | SQLite in-memory (`:memory:`)                         |

---

## Ringkasan Hasil

| Suite                          | Pass | Skip | Fail | Assertion |
|-------------------------------|------|------|------|-----------|
| `DuplicateReviewCandidatesTest` | 11   | 0    | 0    | 64        |
| **Seluruh suite (php artisan test)** | **215** | **2** | **0** | **1229** |

> Hasil di atas adalah hasil aktual dari run `php artisan test` tanggal 2026-11-03.
> 2 test yang SKIPPED bukan milik Pekerjaan B (satu adalah NLP live test opt-in,
> satu adalah legacy placeholder sebelum integrasi NLP Job nyata).

---

## Detail `DuplicateReviewCandidatesTest` (11 Test)

| No | Nama Test | REQ | Status |
|----|-----------|-----|--------|
| 1 | `test_maintainer_can_view_centralized_duplicate_candidates_list` | 05.1, 05.2 | ✅ PASS |
| 2 | `test_security_access_restrictions_to_duplicates_index` | 05.1 | ✅ PASS |
| 3 | `test_maintainer_can_view_side_by_side_comparison` | 05.3, 05.6 | ✅ PASS |
| 4 | `test_compare_rejects_cross_project_and_unauthorized_users` | 05.3 | ✅ PASS |
| 5 | `test_validation_relations_server_side_enforcement` | 05.10, 05.11 | ✅ PASS |
| 6 | `test_confirm_duplicate_marks_child_closed_if_parent_resolved_or_closed` | 05.7 | ✅ PASS |
| 7 | `test_confirm_not_duplicate_leaves_statuses_unchanged_and_logs` | 05.8, 05.14 | ✅ PASS |
| 8 | `test_re_review_rejected_candidate_appends_to_activity_logs` | 05.14 | ✅ PASS |
| 9 | `test_cancel_duplicate_requires_valid_reason_and_resets_child_to_open` | 05.9 | ✅ PASS |
| 10 | `test_retry_detection_only_allowed_for_failed_status` | 05.12, 05.13 | ✅ PASS |
| 11 | `test_retry_concurrency_guard_prevents_duplicate_job_dispatch` | 05.13 | ✅ PASS |

---

## File yang Diubah / Dibuat (Pekerjaan B)

| File | Tipe | Keterangan |
|------|------|------------|
| `backend/app/Http/Controllers/DuplicateReviewController.php` | Modified | +3 method baru: `index`, `compare`, `retry`; perbaikan `decide`, `cancel` |
| `backend/app/Models/DuplicateCandidate.php` | Modified | +`decidedBy()` relationship |
| `backend/app/Models/Issue.php` | Modified | +`duplicateCandidates()` relationship |
| `backend/resources/views/duplicates/index.blade.php` | Created | View daftar kandidat terpusat (REQ-05.1, 05.2) |
| `backend/resources/views/duplicates/compare.blade.php` | Created | View perbandingan berdampingan (REQ-05.3, 05.4, 05.5, 05.6) |
| `backend/routes/web.php` | Modified | +5 route (INTEGRATION PENDING – untuk Joshua) |
| `backend/tests/Feature/DuplicateReviewCandidatesTest.php` | Created | 11 test, 64 assertions |
| `docs/bdd/sprint2/req-05-validasi-duplikat.feature` | Created | Skenario BDD (BDD BLOCKED – lihat catatan) |

---

## Perbaikan Bug yang Ditemukan Selama Testing

| Bug | Root Cause | Fix |
|-----|-----------|-----|
| `compare` mengembalikan 403 alih-alih 404 untuk cross-project | `authorizeOwner()` dipanggil sebelum scope check project | Pindah scope check SEBELUM `authorizeOwner()` di `compare()` |
| `confirm_duplicate` gagal 409 walau data valid | `$model->update()` di test fixture diam-diam gagal karena `status` tidak ada di `$fillable` Issue | Ganti dengan `DB::table('issues')->where(...)→update(...)` di test |
| `cancel_duplicate` melaporkan status OPEN padahal kandidat baru DUPLICATE | Sama: `$candidate->update(['status'=>'DUPLICATE'])` diam-diam gagal | Ganti dengan `DB::table()` di test |

---

## Catatan BDD

**Status: BDD BLOCKED**

- Behat runner ada di branch `feature/sprint1-bdd-behat` (PR #35).
- PR #35 **belum di-merge** ke `main`.
- `composer.json` di `main` **tidak** memiliki `behat/behat`.
- File `docs/bdd/sprint2/req-05-validasi-duplikat.feature` sudah dibuat dan mendokumentasikan
  semua skenario sesuai SRS Tabel 4.28 (REQ-05.1 s/d REQ-05.14).
- File siap dijalankan begitu PR #35 di-merge dan Behat runner tersedia.

---

## Coverage REQ-05 Map

| REQ | Deskripsi | Coverage |
|-----|-----------|----------|
| 05.1 | Daftar kandidat duplikat terpusat | ✅ Test #1, #2 |
| 05.2 | Tampilkan issue FAILED + tombol retry | ✅ Test #1 |
| 05.3 | Perbandingan berdampingan | ✅ Test #3, #4 |
| 05.4 | NLP score hanya rekomendasi (bukan otomatis DUPLICATE) | ✅ Controller: tidak ada auto-set; Test #3 |
| 05.5 | Tampilkan aturan relasi & konsekuensi | ✅ Test #3 (assertSee) |
| 05.6 | Rekomendasi induk berbasis vote terbanyak | ✅ Test #3 |
| 05.7 | DUPLICATE → child status DUPLICATE / CLOSED jika parent RESOLVED | ✅ Test #6 |
| 05.8 | NOT_DUPLICATE → tidak mengubah status | ✅ Test #7 |
| 05.9 | Pembatalan wajib alasan ≥ 10 karakter | ✅ Test #9 |
| 05.10 | Validasi server: parent_issue_id harus bagian pasangan | ✅ Test #5 |
| 05.11 | Validasi server: tidak cycle, parent bukan duplikat | ✅ Test #5 |
| 05.12 | Retry hanya untuk FAILED | ✅ Test #10 |
| 05.13 | Concurrency guard pada retry (lockForUpdate) | ✅ Test #10, #11 |
| 05.14 | Activity log setiap keputusan | ✅ Test #7, #8, #9 |

