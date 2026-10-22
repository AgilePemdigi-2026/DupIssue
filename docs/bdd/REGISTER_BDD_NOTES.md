# ADDENDUM USER STORY SCENARIO & BDD — REGISTER (SPRINT 1)

**Proyek:** DupIssue — PPL Praktikum Kelompok 4/I1  
**Fitur:** Register (Feature 1 Sprint 1), GitHub Issue [#5](https://github.com/AgilePemdigi-2026/DupIssue/issues/5)  
**Branch kerja:** `feature/register` (milik Joshua)  
**Status saat dibuat:** Register diimplementasikan dan diuji lokal; belum otomatis tersedia di `main` sampai PR di-merge.

## 1. Dasar dan alasan addendum

1. **Sprint Planning**, Feature 1 Register, meminta **Front End Register** dan **Back End Registrasi & Validasi Akun**; tanggung jawab Joshua meliputi validasi email unik dan BDD.
2. **SRS**, 4.2–4.7, memiliki User Story Scenario Gherkin untuk enam use case utama, tetapi **tidak menyediakan User Story Scenario Register**. Bagian 4.8 REQ-07.1 mengatur **Login**, REQ-07.3 **RBAC**, sedangkan REQ-NF-18 mengatur **hash password**.
3. **Panduan Monitoring PPL Praktikum** meminta User Story Scenario mengikuti **Behat cheat sheet** serta **BDD berdasarkan User Story Scenario beserta hasil menjalankan pengujian aplikasi**.
4. **Amandemen kebutuhan yang harus dicatat/disetujui kelompok:** SRS awal berasumsi akun disediakan melalui konfigurasi awal, sementara Sprint 1 menambahkan registrasi publik; pengecualian autentikasi REQ-NF-15 harus mencakup halaman `/register` dan POST `/register`.

Karena itu `register.feature` adalah **addendum User Story Scenario** yang menjaga gaya Given–When–Then seperti di SRS, bukan pengganti enam User Story Scenario yang sudah ada.

## 2. Aturan bisnis untuk Register

- Field `name` wajib, maksimal **100 karakter** mengikuti penyesuaian PDM.
- `email` wajib, berformat benar, maksimal 255 karakter, **UNIQUE**.
- `password` wajib, minimal **8 karakter**, wajib cocok dengan `password_confirmation`.
- Password di-hash bcrypt/Argon2, **tidak disimpan plaintext** (REQ-NF-18).
- Pengguna yang register publik mendapat role awal **REPORTER**. Field `role` yang dikirim manual **tidak boleh** menaikkan akun menjadi `MAINTAINER`. Ini **keputusan implementasi sementara yang harus dicatat/disetujui**, sebab SRS awal belum menentukan pendaftaran publik atau provisioning Maintainer.
- User yang baru register **belum otomatis menjadi anggota project**. Membership dan ownership tetap tanggung jawab modul Reza.
- Validasi berjalan di **backend Laravel**; `required` dan `minlength` HTML hanyalah bantuan pada browser.

## 3. Ketertelusuran scenario Gherkin ↔ Laravel Feature Test

| No. | Scenario dalam `register.feature` | Nama tes PHP pada `backend/tests/Feature/Auth/RegisterTest.php` | Bukti yang diuji |
|---:|---|---|---|
| 1 | Pengunjung dapat membuka halaman Register | `test_registration_page_can_be_accessed` | GET 200 dan teks form |
| 2 | Pengunjung berhasil mendaftarkan akun | `test_user_can_register_successfully` | Berhasil redirect, flash success, role REPORTER, hash benar |
| 3 | Email sudah digunakan | `test_duplicate_email_is_rejected` | Validasi email; tidak membuat akun kedua |
| 4 | Konfirmasi password berbeda | `test_password_confirmation_must_match` | Validasi password; tidak menyimpan akun |
| 5 | Password < 8 karakter | `test_password_must_have_at_least_eight_characters` | Validasi **backend** saat permintaan langsung |
| 6 | Field wajib kosong | `test_required_fields_are_validated` | Validasi backend; tidak menyimpan akun |
| 7 | Role MAINTAINER dikirim oleh pengguna | `test_user_cannot_register_as_maintainer` | Role tetap REPORTER |

**Bukti eksekusi yang telah diamati dari screenshot Joshua:** `PASS Tests\Feature\Auth\RegisterTest` — **7 passed (22 assertions), 13.61 s**. Screenshot terminal PASS tersebut perlu dilampirkan pada PR/laporan. Catatan: bukti ini menunjukkan eksekusi **Laravel Feature Test**, **bukan eksekusi file `register.feature` oleh Behat**.

### Penting: status Behat

File `register.feature` adalah spesifikasi Gherkin. **Belum ada bukti file ini dieksekusi dengan Behat/Mink**. Skenario berlabel `@needs-steps` membutuhkan step definitions/fixtures untuk menjalankan pemeriksaan database atau mengirim request backend; skenario `@ui` pun membutuhkan Behat/Mink, konfigurasi base URL, browser driver, dan database/fixture uji terpisah.

**Jika dosen mensyaratkan executable Behat**, buat dan jalankan langkah Behat/Mink pada pekerjaan pengujian lanjutan. Jangan tulis "Behat PASS" hanya berdasarkan output Laravel Feature Test. Untuk PR kode Register sekarang, lampirkan screenshot PHPUnit/Laravel test plus addendum Gherkin dan nyatakan keterbatasannya dengan jujur.

## 4. Cara memverifikasi sebelum PR

**Dari root repository:**

```powershell
git branch --show-current
git status
```

Harus `feature/register`. Pastikan perubahan branch sendiri masih aman, tanpa file lokal berisi kredensial.

**Dari folder `backend`:**

```powershell
php artisan route:list --path=register
php artisan migrate:status
```

**Pastikan PHPUnit/Laravel test menggunakan DB terpisah** dari DB pengembangan `dupissue`, misalnya SQLite `:memory:` aktif dalam `phpunit.xml` dan driver pdo_sqlite tersedia. Baru jalankan:

```powershell
php artisan test --filter=RegisterTest
php artisan test
npm.cmd run build
```

Tes suite penuh bisa gagal oleh pekerjaan lain; dokumentasikan kegagalan dan investigasi sebelum merge. `php artisan test` menggunakan environment testing yang sesuai, jangan mengarahkannya ke DB `dupissue` yang menyimpan akun nyata.

**Dari root repository setelah file disalin:**

```powershell
Test-Path .\docs\bdd\register.feature
Test-Path .\docs\bdd\REGISTER_BDD_NOTES.md
git status
git diff --stat
```

## 5. Saran isi deskripsi Pull Request

**Judul:** `feat: implement user registration with validation and tests`

**Ringkasan untuk deskripsi PR:**

> Implemented DupIssue user registration on Laravel 12 using Blade and Bootstrap. The feature adds a users-table schema adjustment (name length and REPORTER/MAINTAINER role), GET/POST `/register`, validation, bcrypt hashing through Laravel's User model, unique email protection, and default REPORTER access. Created 7 automated Laravel feature tests; local result: 7 PASS / 22 assertions. Added Gherkin user story scenario as an addendum to SRS, following Sprint Planning and the monitoring guide. Note: Gherkin file is a specification; no Behat/Mink execution is claimed. Registration is an agreed Sprint 1 addition to the original SRS scope.

**Checklist PR:**
- [ ] `backend/.env`, `vendor/`, `node_modules/`, `.venv` tidak ikut di-commit.
- [ ] `users.role` dibuat dalam **migration baru**, bukan `migrate:fresh`.
- [ ] Screenshot `7 passed (22 assertions)` dilampirkan sebagai evidence.
- [ ] Full test suite dan build dicatat (PASS atau alasan kegagalan).
- [ ] Code review anggota dilakukan.
- [ ] Setelah merge `feature/register` ke `main`, Reza/Elsa/Valentino diberi tahu untuk `git pull` + `php artisan migrate` agar mendapatkan kolom `users.role`.
- [ ] Kontrak register publik REPORTER vs asumsi akun awal SRS ditambahkan ke catatan keputusan `docs/decisions.md` / revisi SRS.

## 6. Bukti untuk laporan praktikum

Minimal arsip:
1. Screenshot halaman `/register` dan pesan registrasi berhasil.
2. Screenshot validasi email duplikat, password tidak cocok, serta validasi wajib/pendek.
3. Screenshot query MySQL yang menampilkan email, `role=REPORTER`, prefix hash dan panjang hash (tanpa menunjukkan password asli).
4. Screenshot **Laravel Feature Test: 7 passed, 22 assertions**.
5. File `register.feature`, `RegisterTest.php`, dan URL PR yang sudah direview.

**Keputusan rilis:** fitur Register dapat diajukan untuk code review/PR setelah bukti tes terlampir. Apakah disebut seluruh "BDD executable" selesai tergantung kebijakan penilaian dan apakah Behat runner benar-benar dijalankan.
