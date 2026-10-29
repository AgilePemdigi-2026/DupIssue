# Status BDD Submit Issue Sprint 1

Audit lokal 29 Oktober 2026, branch `assist/elsa-pr19`.

## Status eksekusi

[submit-issue.feature](../../backend/tests/Bdd/submit-issue.feature) adalah spesifikasi
Gherkin, **belum executable BDD**. Tidak ada package Behat/Mink di
[composer.json](../../backend/composer.json) atau lockfile, konfigurasi Behat,
FeatureContext, maupun step definitions yang cocok. Tidak ada hasil Behat PASS.
Tag `@needs-steps` menyatakan kebutuhan tersebut; tag integrasi bukan implementasi runner.

Perbaikan spesifikasi: GET/POST dipisahkan menjadi contoh Scenario Outline;
skenario status awal PENDING mengharuskan worker dijeda agar tidak race dengan UI;
ditambahkan job belum tersedia, membership dicabut, retry sukses/redelivery,
dan commit baru setelah rollback. Rekomendasi Top 3 dan Vote tetap di luar scope.

## Step definitions yang perlu dibuat

| Kelompok langkah | Kebutuhan implementasi nyata | Bukti PHPUnit terkait |
| --- | --- | --- |
| Login Reporter/Maintainer/guest | Login melalui route Joshua, session isolation, logout antar skenario | LoginTest, LogoutTest, SubmitIssueAccessTest |
| Project/member aktif, nonaktif, bukan anggota | Model, migration dan ProjectService Reza; fixture owner dan membership asli | SubmitIssueTest: akses GET/POST, inactive detail |
| Submit valid/invalid, batas panjang, field forged | Form Mink dan POST langsung untuk bypass validasi HTML; assert errors, old input, row DB | SubmitIssueTest: validasi, batas input, server fields |
| Detail antar-project | Dua project nyata, membership hanya di satu project; assert 404/403 | SubmitIssueTest: cross-project |
| Initial OPEN/PENDING/FORM/0/null | Periksa row setelah commit dengan worker belum berjalan; jangan menuntut PENDING selamanya di browser | SubmitIssueTest: server fields |
| Outer transaction commit/rollback | Backend context pada koneksi yang sama, assert jobs sebelum/sesudah commit, lalu commit lain setelah rollback | SubmitIssueTest: outer commit, rollback, failed insert |
| Enqueue gagal/job tidak tersedia | Job asli untuk queue failure; kondisi absennya Job untuk skenario terpisah; tanpa class pengganti | SubmitIssueTest: enqueue failure, missing job |
| Worker exhausted/retry/redelivery | Worker database/nlp sungguhan, HTTP boundary stub FastAPI, advance test clock sesuai backoff; assert failed_jobs dan kandidat | SubmitIssueTest: worker failure, retry/redelivery |
| Tidak memanggil FastAPI saat submit | Catat HTTP request selama request submit; worker baru dijalankan sesudah assert | Http::assertNothingSent pada test integrasi |

Semua nama di tabel mengacu pada [suite Submit](../../backend/tests/Feature/SubmitIssueTest.php),
[akses dasar](../../backend/tests/Feature/SubmitIssueAccessTest.php),
[Login](../../backend/tests/Feature/Auth/LoginTest.php), dan
[Logout](../../backend/tests/Feature/LogoutTest.php). Pemetaan ini bukan klaim step telah tersedia.

## Prasyarat runner

1. Integrasikan modul Project asli serta Job/DuplicateCandidate asli. Selesaikan fatal
   trait `$afterCommit` pada PR #22 terlebih dahulu; lihat [laporan audit](../SUBMIT_ISSUE_SPRINT1_AUDIT.md).
2. Pasang Behat, Mink dan driver yang dipilih, buat konfigurasi suite dan context.
   Pastikan semua langkah terikat, tanpa undefined/pending steps.
3. Gunakan SQLite testing terisolasi. `:memory:` hanya cocok bila HTTP kernel,
   fixture dan worker berada dalam proses yang berbagi koneksi. Browser/server
   terpisah membutuhkan file SQLite temporary khusus testing dan guard sebelum
   boot/migration; jangan mengarahkannya ke server development atau MySQL.
4. Jangan menggunakan RefreshDatabase outer transaction untuk langkah after-commit.
   Buat batas transaksi eksplisit dan jalankan worker hanya setelah commit.
5. Simulasikan respons FastAPI pada batas HTTP; jangan membuat ProjectService,
   Project/Membership, atau Job palsu. Pisahkan hasil yang blocked/skipped dari PASS.

BDD baru boleh disebut selesai setelah runner benar-benar mengeksekusi skenario,
tanpa langkah undefined/pending, pada dependensi asli dan database testing terisolasi.
