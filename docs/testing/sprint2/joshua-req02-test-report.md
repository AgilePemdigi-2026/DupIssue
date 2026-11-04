# Hasil Uji Feature 7 — Lihat Status Issue (REQ-02)

- **PIC:** Joshua Charis Giovanni Mangialu
- **Sprint:** 2
- **Baseline saat paket dibuat:** `main@1bf6907`, 4 November 2026 (WIB)
- **Branch target:** `feature/sprint2-issue-status`
- **Requirement:** REQ-02.1–REQ-02.10
- **Status:** **NOT EXECUTED** — implementasi disiapkan sebagai overlay untuk diuji di laptop Joshua. **Jangan mengubah menjadi PASS tanpa log pengujian aktual.**

## Tes yang harus dijalankan

Dari direktori `backend/` pada worktree Joshua:

```powershell
php -l app/Http/Controllers/IssueController.php
php -l routes/web.php
php -l tests/Feature/IssueStatusReadOnlyTest.php
node --check public/js/issue-status-refresh.js
php artisan test --filter=IssueStatusReadOnlyTest
php artisan test --filter=IssueStatusManagementTest
php artisan test
vendor/bin/behat --config=behat.sprint2-joshua.yml --suite=sprint2-req02 --format=pretty --no-colors
powershell -NoProfile -ExecutionPolicy Bypass -File .\run-bdd-sprint1.ps1
```

**Pengamanan:** PHPUnit harus menggunakan SQLite `:memory:` sesuai `phpunit.xml`; Behat harus memakai `.env.behat` dan guard SQLite `:memory:` yang sudah ada di `FeatureContext`. Jangan jalankan `migrate:fresh` di database MySQL development.

## Evidence hasil aktual (diisi setelah run)

| Pengujian | PASS | FAIL | SKIPPED | Exit code | Bukti |
| --- | ---: | ---: | ---: | ---: | --- |
| PHPUnit targeted REQ-02 | — | — | — | — | NOT EXECUTED |
| PHPUnit regression REQ-06 | — | — | — | — | NOT EXECUTED |
| PHPUnit full regression | — | — | — | — | NOT EXECUTED |
| Behat Sprint 2 REQ-02 | — | — | — | — | NOT EXECUTED |
| Behat Sprint 1 (3 suites) | — | — | — | — | NOT EXECUTED |
| Browser manual refresh/error | — | — | — | — | NOT EXECUTED |

## Verifikasi browser manual yang diperlukan

1. Login Reporter yang memiliki membership aktif.
2. Pada daftar issue, cari berdasarkan judul dan deskripsi, pilih filter status, periksa pagination, klik **Reset Filter**.
3. Buka detail, pastikan sumber, vote, status dan riwayat tampil. Klik **Muat Ulang**. Periksa data terbaru tanpa mengubah issue.
4. Di Chrome DevTools > Network, aktifkan **Offline** setelah halaman detail termuat. Klik **Muat Ulang**: status lama harus tetap terlihat, muncul **Gagal memuat status**, penanda **Data lama belum diperbarui**, dan **Coba Lagi**.
5. Kembalikan Network ke Online, klik **Coba Lagi**, pastikan pesan error/penanda stale hilang setelah sukses.
6. Untuk ID issue yang tidak ada atau issue project lain: pastikan respons HTTP 404 dengan **Issue tidak tersedia** dan **Kembali ke Daftar** tanpa membocorkan konten project lain.
7. Reporter harus tidak melihat fitur mengubah status dan request langsung menuju route mutasi harus ditolak.

**Batas pengujian BDD saat ini:** Mink `laravel` driver tidak mengeksekusi JavaScript browser. Skenario UX refresh gagal/Coba Lagi memerlukan manual browser evidence atau E2E browser yang benar-benar mengeksekusi JS. Jangan melabelinya otomatis PASS hanya karena tes PHP lulus.

## Follow-up

- **Commit SHA:** (isi setelah commit)
- **Tanggal WIB:** (isi ketika tes benar-benar dijalankan)
- **Bug dan tindakan:** (isi)
- **Link PR:** (isi)
- **Reviewer:** (isi)
- **Product Owner acceptance:** PENDING
