# Project & Membership BDD — Sprint 1

Status: **NOT RUN**. [Skenario Gherkin](./project-membership.feature) tersedia sebagai
spesifikasi. Ini bukan klaim BDD PASS. Repository memiliki skenario auth/NLP/logout,
tetapi `composer.json`/`composer.lock` tidak memuat Behat, Mink, driver browser,
`behat.yml`, atau context/step definitions yang menjalankan skenario tersebut.
Tidak ada step definitions palsu yang ditambahkan.

## Langkah eksekusi yang masih diperlukan

1. Sepakati versi Behat, Mink, extension Behat–Mink, dan driver yang kompatibel
   dengan PHP/Laravel proyek. Tambahkan dependency development dan commit lockfile
   melalui pekerjaan BDD tersendiri; dependency tersebut belum dipasang oleh patch ini.
2. Buat `behat.yml` dengan suite menunjuk `docs/bdd/project-membership.feature`,
   context nyata, dan base URL server testing. Buat step definitions yang membuat
   fixture, login, mengisi/mengirim form dengan CSRF, mengikuti redirect, serta
   memeriksa HTTP status dan database. Step undefined/pending harus dianggap belum lulus.
3. Gunakan environment testing tanpa config cache, key testing tersendiri,
   session/cache terisolasi, dan **SQLite sementara yang khusus dibuat untuk BDD**.
   Server browser dan runner harus memakai file SQLite sementara yang sama;
   `:memory:` tidak dibagikan antarproses. Jangan memakai `.env` development,
   MySQL development, atau menjalankan `migrate:fresh` pada koneksi development.
   Guard PHPUnit tidak otomatis melindungi Behat: context BDD wajib memverifikasi
   driver, path SQLite sementara, dan environment sebelum migrasi/fixture.
4. Jalankan server testing, lalu dari `backend` jalankan
   `vendor/bin/behat --config=behat.yml --tags='~@concurrency'` setelah konfigurasi
   dan context nyata selesai. Simpan jumlah passed/failed/undefined/pending/skipped.
5. Skenario `@concurrency` memerlukan dua proses/koneksi independen dan barrier
   agar request benar-benar tumpang tindih. SQLite tidak menerapkan row-level
   `SELECT ... FOR UPDATE`; untuk memvalidasi locking InnoDB gunakan instance
   MySQL testing disposable khusus, **bukan MySQL development**, pada pekerjaan
   integrasi terpisah. Jangan menyebut request berurutan sebagai test concurrency.

## Verifikasi yang tersedia sekarang

Test PHPUnit nyata berada di
[ProjectManagementTest](../../backend/tests/Feature/ProjectManagementTest.php) dan
[ProjectMembershipSecurityTest](../../backend/tests/Feature/ProjectMembershipSecurityTest.php).
Keduanya menjalankan request Laravel dan constraint SQLite in-memory.
Test race nama project menyisipkan competing write setelah pre-check sebelum SQL
utama; itu simulasi interleaving deterministik, bukan benchmark request paralel.
Hasil PHPUnit tidak menggantikan hasil Behat/Mink.
