# Bukti Pengujian dan Validasi DupIssue

Direktori ini dipakai untuk **hasil eksekusi pengujian**, bukan hanya definisi skenario. Panduan Program Monitoring PPL Prak meminta **BDD berdasarkan user story scenario beserta hasil run pengujian aplikasi**.

## Bedakan ketiga jenis bukti

| Jenis | Contoh | Apa yang dibuktikan |
| --- | --- | --- |
| Laravel Feature Test / PHPUnit | `php artisan test --filter=LoginTest` | Perilaku backend dan HTTP yang diuji Laravel |
| NLP unit test | `python -m unittest discover -s tests -v` | Perilaku service Python yang diuji |
| Behat / BDD | Runner menjalankan `docs/bdd/*.feature` dengan step definitions | Bahwa scenario Gherkin benar-benar dieksekusi |

**Hasil PASS dari PHPUnit tidak setara dengan hasil PASS dari Behat.** File `.feature` tanpa runner dan step definitions yang dapat menjalankannya adalah **spesifikasi BDD**, belum bukti eksekusi.

## Format dokumentasi bukti per fitur

Simpan file `<feature>-test-report.md` dan screenshot/output seperlunya. Isinya minimal:

- **Fitur / Issue / PIC / Sprint**.
- **Requirement atau User Story Scenario** yang diuji.
- **Tanggal, branch atau commit SHA**.
- **Lingkungan pengujian:** PHP/Python, Laravel/FastAPI, DB test terisolasi, URL bila relevan.
- **Perintah yang dieksekusi**, tool/runner, jumlah scenario/test dan assertion, serta hasil sukses/gagal.
- **Lampiran screenshot/log** dari eksekusi dan link ke test code.
- **Kegagalan, tindak lanjut**, dan hasil retest jika ada.
- **Hasil verifikasi Product Owner** jika sudah dilakukan, tanpa mengklaim persetujuan sebelum diberikan.

Contoh penamaan:

```text
docs/testing/
├── README.md
├── sprint-1/
│   ├── register-test-report.md
│   ├── register-phpunit-pass.png
│   ├── login-rbac-test-report.md
│   └── login-rbac-phpunit-pass.png
└── uas/
    └── product-owner-acceptance.md
```

File contoh di atas **belum dinyatakan tersedia**: unggah hanya bukti asli yang benar-benar dihasilkan.

## Tahap berikutnya untuk Sprint 1

1. Unggah bukti Laravel Feature Test Register dan Login/RBAC yang sudah dijalankan secara lokal.
2. Siapkan runner, konteks/step definitions, fixtures dan database test terisolasi untuk Behat sesuai kebijakan praktikum.
3. Jalankan skenario Gherkin Register dan Login/RBAC, lalu unggah **bukti run Behat yang sesungguhnya**.
4. Minta anggota lain melengkapi bukti per fitur saat modul masing-masing di-merge.
5. Lakukan validasi Product Owner dan simpan catatan persetujuan ketika sudah dilakukan.

Jangan menjalankan `migrate:fresh` pada database pengembangan atau database yang memuat akun nyata hanya untuk menguji fitur.
