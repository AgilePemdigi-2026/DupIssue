# Laporan Pengujian BDD — Fitur Register

## 1. Identitas Pengujian

| Komponen | Keterangan |
|---|---|
| Proyek | DupIssue |
| Sprint | Sprint 1 |
| Fitur | Register Akun |
| Metode | Behavior-Driven Development (BDD) |
| Framework Pengujian | Behat |
| Framework Aplikasi | Laravel 12 |
| Database Testing | SQLite In-Memory |
| Tanggal Pengujian | 29 Oktober 2026 |
| Branch Pengujian | `test/bdd-setup` |
| Hasil | PASS |

## 2. Tujuan Pengujian

Pengujian BDD dilakukan untuk memastikan bahwa fitur registrasi akun DupIssue berjalan sesuai kebutuhan fungsional dan aturan validasi yang telah ditentukan. Pengujian mencakup keberhasilan registrasi, penolakan input tidak valid, serta pembatasan penentuan role pengguna baru.

## 3. Skenario dan Hasil Pengujian

| No. | Skenario | Hasil |
|---|---|---|
| 1 | Pengunjung dapat membuka halaman Register | PASS |
| 2 | Pengunjung berhasil mendaftarkan akun dengan data valid | PASS |
| 3 | Pendaftaran ditolak jika email sudah digunakan | PASS |
| 4 | Pendaftaran ditolak jika konfirmasi password tidak sesuai | PASS |
| 5 | Backend menolak password kurang dari delapan karakter | PASS |
| 6 | Backend menolak field wajib yang kosong | PASS |
| 7 | Pengunjung tidak dapat menentukan role MAINTAINER sendiri | PASS |

**Ringkasan hasil eksekusi:**

- Total skenario: 7
- Skenario berhasil: 7
- Skenario gagal: 0
- Total langkah: 39
- Langkah berhasil: 39
- Langkah gagal: 0

## 4. Lingkungan dan Pelaksanaan

Pengujian dilakukan menggunakan Behat dengan skenario berformat Gherkin (Given–When–Then). Step definitions diimplementasikan menggunakan PHP dan dihubungkan dengan Laravel HTTP Kernel.

Database SQLite in-memory digunakan untuk mengisolasi data testing dari database MySQL pengembangan.

Perintah pengujian:

`.\vendor\bin\behat.bat --no-interaction --no-snippets`

Pengujian dilakukan melalui HTTP Kernel Laravel, bukan otomatisasi browser visual.

## 5. Artefak Pendukung

- **Skenario Gherkin:** `docs/bdd/register.feature`
- **Step Definitions:** `backend/features/bootstrap/FeatureContext.php`
- **Konfigurasi Behat:** `backend/behat.yml`
- **Controller:** `backend/app/Http/Controllers/Auth/RegisterController.php`
- **Laravel Feature Test:** `backend/tests/Feature/Auth/RegisterTest.php`
- **Bukti screenshot:** `register-behat-pass.png`
- **Log pengujian:** `register-behat-output.txt`

## 6. Kesimpulan

Berdasarkan hasil eksekusi Behat, seluruh tujuh skenario BDD fitur Register berhasil dijalankan dengan total 39 langkah yang berstatus PASS.

Hasil ini menunjukkan bahwa perilaku fitur Register yang dicakup oleh skenario pengujian telah sesuai dengan hasil yang diharapkan. Pengujian BDD ini menjadi bukti tahap Testing pada Sprint 1 dan melengkapi proses implementasi fitur Register yang telah dikembangkan.