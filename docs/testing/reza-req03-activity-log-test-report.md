# Laporan Uji — Feature: Project Activity Log (REQ-03.12)

- **PIC**: Fakhri Reza Abinaya (REZA) — Kelompok 4, Kelas I1
- **Branch**: `feature/project-activity-log` (PR #36)
- **Reviewer**: Joshua
- **Lingkungan Uji**: PHP 8.3.30 (Win32, VS16, x64), Laravel 12.x, SQLite in-memory (`:memory:`)
- **Tanggal Eksekusi**: 2026-11-11 22:40 WIB

---

## 1. Ringkasan Implementasi (REQ-03.12 / REQ-07.7)

Fitur Activity Log mencatat seluruh aktivitas pengelolaan project dan keanggotaan ke dalam tabel database `activity_logs` serta file log aplikasi secara atomik.

### Aksi yang Dicatat (`action`):
1. **`CREATE_PROJECT`**: Dicatat saat Maintainer membuat project baru.
   - `old_values`: `null`
   - `new_values`: `{"name": "...", "description": "..."}`
2. **`UPDATE_PROJECT`**: Dicatat saat Maintainer pemilik memperbarui nama atau deskripsi project.
   - `old_values`: `{"name": "...", "description": "..."}` (nilai sebelum perubahan)
   - `new_values`: `{"name": "...", "description": "..."}` (nilai sesudah perubahan)
3. **`INVITE_REPORTER`**: Dicatat saat Maintainer mengundang Reporter ke project.
   - `old_values`: `null`
   - `new_values`: `{"invitation_id": ..., "reporter_id": ..., "email": "...", "status": "PENDING"}`
4. **`CANCEL_INVITATION`**: Dicatat saat Maintainer membatalkan undangan yang berstatus PENDING.
   - `old_values`: `{"invitation_id": ..., "status": "PENDING"}`
   - `new_values`: `{"invitation_id": ..., "status": "CANCELED"}`
5. **`ACCEPT_INVITATION`**: Dicatat saat Reporter menerima undangan masuk.
   - `old_values`: `{"status": "PENDING"}`
   - `new_values`: `{"status": "ACCEPTED", "is_active": true, "membership_id": ...}`
6. **`REJECT_INVITATION`**: Dicatat saat Reporter menolak undangan masuk.
   - `old_values`: `{"status": "PENDING"}`
   - `new_values`: `{"status": "REJECTED"}`
7. **`REMOVE_MEMBER`**: Dicatat saat Maintainer menonaktifkan status keanggotaan Reporter.
   - `old_values`: `{"membership_id": ..., "is_active": true}`
   - `new_values`: `{"membership_id": ..., "is_active": false}`

---

## 2. Mekanisme Transaksi dan Perbaikan Bug Transaksi

### Perbaikan P1:
Pada revisi sebelumnya, di `ProjectService::createProject()` dan `updateProject()` terdapat blok `if` yang melakukan `DB::table('projects')->insert(...)` di dalam penanganan `ValidationException` setelah pemanggilan `DB::rollBack()`. Hal ini berisiko membuat project liar tersimpan tanpa activity log ketika validasi gagal.

**Perbaikan yang telah diterapkan:**
1. Menghapus seluruh blok `if` dan `DB::table('projects')->insert()` dari kedua method `createProject()` dan `updateProject()`.
2. Membungkus operasi penyimpanan project dan pencatatan activity log dalam satu transaksi database atomik menggunakan `DB::transaction()`.
3. Menjamin bahwa jika validasi gagal (nama kosong, terlalu pendek, duplikat case-insensitive, dsb.), seluruh perubahan dibatalkan (rollback) tanpa ada row project yang tersimpan, tanpa activity log, dan tanpa transaksi menggantung.
4. Menjamin bahwa jika penyimpanan activity log gagal (misal simulasi exception pada tabel `activity_logs`), perubahan pada project dibatalkan secara penuh (rollback atomik).

---

## 3. Hasil Eksekusi Pengujian Aktual

Seluruh pengujian dijalankan pada database terisolasi SQLite in-memory:

```bash
php artisan test --filter="ProjectActivityLogTest|ProjectMembershipSecurityTest|ProjectManagementTest|IssueVotingTest|DuplicateReviewCandidatesTest"
php artisan test
vendor/bin/behat --config=behat.yml --suite=sprint1
vendor/bin/behat --config=behat.yml --suite=sprint2
```

### Ringkasan Hasil:

| Test Suite | File / Scope | Total Tests / Scenarios | Hasil | Durasi |
|---|---|---|---|---|
| **Activity Log Test** | `ProjectActivityLogTest.php` | 17 tests (128 assertions) | **PASS 17** / FAIL 0 | 1.19s |
| **Membership Security** | `ProjectMembershipSecurityTest.php` | 25 tests (160 assertions) | **PASS 25** / FAIL 0 | 1.46s |
| **Project Management** | `ProjectManagementTest.php` | 23 tests (163 assertions) | **PASS 23** / FAIL 0 | 1.73s |
| **Issue Voting (PR #37)** | `IssueVotingTest.php` | 21 tests (107 assertions) | **PASS 21** / FAIL 0 | 7.59s |
| **Duplicate Review (PR #38)**| `DuplicateReviewCandidatesTest.php` | 23 tests (150 assertions) | **PASS 23** / FAIL 0 | 4.51s |
| **Full PHPUnit Suite** | Seluruh test framework | 303 tests (1775 assertions) | **PASS 301** / FAIL 0 / SKIP 2* | 29.38s |
| **Behat Sprint 1** | Suite `sprint1` | 27 scenarios (243 steps) | **PASS 27** / FAIL 0 | 17.64s |
| **Behat Sprint 2** | Suite `sprint2` | 39 scenarios (323 steps) | **PASS 39** / FAIL 0 | 27.22s |

*\*Catatan Skipped*:
1. `SubmitIssueQueueIntegrationTest`: Menunggu URL live FastAPI lokal.
2. `missing job keeps issue and marks detection failed`: Hanya berlaku sebelum integrasi real job NLP.

---

## 4. Daftar File yang Diubah

1. `backend/app/Services/ProjectService.php`:
   - Menghapus blok insert liar setelah rollback pada `createProject()` dan `updateProject()`.
   - Menggunakan `DB::transaction()` atomik untuk menjamin konsistensi perubahan project dan activity log.
2. `backend/tests/Feature/ProjectActivityLogTest.php`:
   - Menambahkan 3 regression test baru untuk membuktikan bahwa failure validasi di dalam transaksi tidak menghasilkan orphan project, activity log kosong, dan transaction level kembali utuh.
3. `backend/tests/Feature/ProjectMembershipSecurityTest.php`:
   - Menyesuaikan penanganan single-connection SQLite in-memory pada test race condition competing write agar kompatibel dengan rollback transaksi atomik.
4. `docs/testing/reza-req03-activity-log-test-report.md`:
   - Dokumen evidence pengujian komprehensif ini.
