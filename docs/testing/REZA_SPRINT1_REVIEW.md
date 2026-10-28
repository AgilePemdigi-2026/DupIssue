# Reza Sprint 1 — Project & Membership review

Workspace: `dupissue-reza-assist`; branch: `assist/reza-pr18`, tracking
`origin/feature/project-membership`; baseline HEAD: `cb73243`.
Working tree bersih sebelum perubahan. Tidak ada commit, push, merge, PR baru,
perubahan main, atau akses/migrasi database MySQL development.

**Keputusan final: READY TO COMMIT.** Kedua temuan Medium pada final review sudah
diperbaiki dan ditutup dengan regression tests permanen. Full suite terbaru:
**90 PASS, 0 FAIL, 0 ERROR, 0 SKIPPED, 481 assertions** pada SQLite memory.
Tidak ada commit/push/staging yang dilakukan. Keputusan ini menggantikan
status NEEDS FIX sebelumnya; batas verifikasi BDD/InnoDB tetap dicatat di bawah.

## Masalah dan perbaikan

1. Pemeriksaan duplicate invitation sebelumnya mengunci query membership/undangan
   yang mungkin belum mempunyai baris. Itu tidak cukup sebagai jaminan concurrency.
   Semua mutasi invite/cancel/respond/remove sekarang mengunci **baris project
   yang sudah ada terlebih dahulu**, lalu baris anak dalam transaksi. Pemeriksaan
   anak menggunakan locking read `first()`, bukan subquery `exists()`, agar membaca
   state terkini pada InnoDB. Transaksi memiliki maksimal tiga percobaan ketika
   Laravel mendeteksi concurrency error. Urutan project → anak konsisten.
2. Constraint unik nama project sudah ada, tetapi pelanggaran setelah pre-check
   dapat menjadi HTTP 500. Create/update sekarang menerjemahkan
   `UniqueConstraintViolationException` menjadi validasi nama. Pencarian nama
   menggunakan collation kolom yang sama dengan unique index; trim dilakukan
   sebelum validasi, termasuk untuk pemanggil service non-HTTP.
3. Controller mengubah input email menjadi string sebelum validasi; array dapat
   memicu exception PHP/HTTP 500. Bentuk input kini divalidasi sebelum service.
4. Helper akses sebelumnya mengizinkan owner tanpa memeriksa role terkini dan
   membership aktif tanpa membatasi role Reporter. Policy dan service sekarang
   konsisten: Maintainer pemilik atau Reporter dengan membership aktif. Service
   respons undangan juga memeriksa role Reporter, selain identitas penerima.
5. Seeder Reporter masih memakai password tetap `Password123!`. Akun baru kini
   memakai secret acak 32 karakter yang di-hash. Seeder hanya berjalan pada
   local/testing; tidak mereset password akun lama, menimpa project, mengaktifkan
   ulang membership lama, menghapus data, atau melakukan migrasi. Sebelum menulis,
   seeder kini memvalidasi email/nama/role akun existing dan owner project. Collision
   menghentikan operasi dengan error jelas. Seluruh operasi dibungkus transaksi
   agar kegagalan penulisan membership juga me-rollback akun/project yang baru dibuat.
6. Empat file memiliki BOM UTF-8: migration projects dan tiga view project.
   Hanya tiga byte awal dihapus; struktur UI dipertahankan.
7. PHPUnit sebelumnya belum memaksa environment database dan tidak menolak
   cached configuration. Bootstrap testing sekarang menolak config cache dan
   konfigurasi selain SQLite memory sebelum Laravel dijalankan. TestCase membatasi
   daftar koneksi ke SQLite, memverifikasi PDO serta `PRAGMA database_list` sebelum
   RefreshDatabase, dan memakai session/cache/mail/queue testing. Key acak 32 byte
   disimpan sekali dalam static property TestCase per proses; refresh aplikasi
   menggunakan key yang sama. Tidak mengubah APP_KEY environment atau file development.
8. Skenario BDD Project & Membership belum ada; Behat/Mink belum dikonfigurasi.
   Skenario dan petunjuk eksekusi ditambahkan, tanpa fake step definitions.

## Audit requirement dan database

- Maintainer dapat create/update miliknya; owner berasal dari server, bukan input.
  Reporter dan Maintainer lain ditolak untuk mutasi. Nama 3–100 karakter dan
  deskripsi maksimal 1.000 karakter diuji pada create/update.
- Migration projects memiliki unique `name` dengan MySQL `utf8mb4_unicode_ci`
  dan SQLite `NOCASE`. Constraint benar-benar diuji melalui insert langsung,
  bukan hanya service validation. SQLite NOCASE hanya melakukan case folding
  ASCII; ekuivalensi Unicode/accent MySQL belum diverifikasi pada mesin MySQL.
- Membership memiliki unique `(project_id, user_id)`, termasuk baris nonaktif.
  Accept ulang memperbarui baris yang sama; remove mengisi `removed_at` dan
  mematikan `is_active`, tanpa menghapus user/membership/riwayat invitation.
- Invitations memiliki foreign keys dan enum status. Tidak ditambah unique global
  `(project_id, reporter_id)` karena akan menghalangi undangan ulang dan histori.
  Pencegahan pending ganda bergantung pada semua writer melalui protokol locking
  service; penulisan langsung SQL di luar service tidak mendapat jaminan ini.
- PENDING tidak memberi akses. Accept mengaktifkan membership; reject/cancel tidak
  membuatnya. Invalid action, array input, ID tidak ada, status terminal, dan
  membership nonaktif ditangani tanpa HTTP 500 pada test terkait.
- Manipulasi invitation ID, project ID, target member, dan penerima undangan
  ditolak; test baru juga memastikan record asli tidak berubah.
- Controller Auth Login/Register/Logout, middleware role, dan bootstrap auth sama
  dengan referensi lokal `origin/main`. Route auth tetap tersedia. Perubahan route
  Reza yang sudah ada mengganti landing placeholder dengan daftar project.
- Tidak ada migration baru, termasuk `activity_logs`. Tidak mengubah NLP,
  eksperimen NLP, Submit Issue Elsa, atau menghapus file anggota lain.

## File yang diubah

| File | Alasan |
| --- | --- |
| [ProjectMemberController.php](../../backend/app/Http/Controllers/ProjectMemberController.php) | Validasi bentuk email sebelum konversi/panggilan service |
| [ProjectService.php](../../backend/app/Services/ProjectService.php) | Parent locking, current reads, retry transaksi, penanganan unique race, trim, role guard, TODO audit |
| [ProjectPolicy.php](../../backend/app/Policies/ProjectPolicy.php) | Menyamakan aturan akses berdasarkan role dan membership aktif |
| [Migration projects](../../backend/database/migrations/2026_10_21_200001_create_projects_table.php) | Hapus BOM saja; tidak mengubah schema |
| [TestProjectSeeder.php](../../backend/database/seeders/TestProjectSeeder.php) | Password acak, environment guard, validasi identitas/owner sebelum menulis, dan transaksi atomik |
| [phpunit.xml](../../backend/phpunit.xml) | Paksa SQLite memory dan bootstrap pengaman |
| [TestCase.php](../../backend/tests/TestCase.php) | Isolasi koneksi, verifikasi PDO sebelum migrasi test, key testing stabil per proses |
| [create.blade.php](../../backend/resources/views/projects/create.blade.php), [members.blade.php](../../backend/resources/views/projects/members.blade.php), [settings.blade.php](../../backend/resources/views/projects/settings.blade.php) | Hapus BOM saja |
| [ProjectMembershipSecurityTest.php](../../backend/tests/Feature/ProjectMembershipSecurityTest.php) — baru | 25 kasus keamanan, constraint, validasi, lifecycle, race nama, dan seeder |
| [TestProjectSeederSafetyTest.php](../../backend/tests/Feature/TestProjectSeederSafetyTest.php) — baru | 8 kasus first run/idempotensi, collision owner/akun, preservasi data, dan rollback |
| [TestingApplicationKeyTest.php](../../backend/tests/Feature/TestingApplicationKeyTest.php) — baru | Round-trip enkripsi setelah refresh, key valid/stabil, APP_KEY environment tetap, serta isolasi SQLite |
| [tests/bootstrap.php](../../backend/tests/bootstrap.php) — baru | Fail-fast untuk config cache dan koneksi yang tidak aman |
| [project-membership.feature](../bdd/project-membership.feature) — baru | Skenario create/update/invite/accept/reject/cancel/remove/access denied/duplicate |
| [PROJECT_MEMBERSHIP_BDD_NOTES.md](../bdd/PROJECT_MEMBERSHIP_BDD_NOTES.md) — baru | Prasyarat dan prosedur BDD nyata, termasuk batas concurrency |
| Laporan ini — baru | Temuan, hasil QA, batas verifikasi, dan keputusan integrasi |

Model Project/Membership/Invitation, migration memberships/invitations, routes,
view invitation, dan ProjectManagementTest diperiksa dan tidak perlu diubah.

## Hasil QA aktual

PHP **8.3.35**, PHPUnit **11.5.57**, database **SQLite `:memory:`**.
Dependency dipasang dari composer.lock menggunakan `--no-scripts --no-plugins`;
composer.json/lock tidak diubah dan tidak ada script migrasi Composer dijalankan.

Perintah suite dari backend:

```sh
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --log-junit /tmp/dupissue-final-fixes-full.xml
```

| Kelompok | PASS | FAIL / ERROR | SKIPPED | Assertions |
| --- | ---: | ---: | ---: | ---: |
| ProjectManagementTest | 23 | 0 | 0 | 163 |
| ProjectMembershipSecurityTest | 25 | 0 | 0 | 160 |
| TestProjectSeederSafetyTest | 8 | 0 | 0 | 47 |
| TestingApplicationKeyTest | 1 | 0 | 0 | 12 |
| Login + Register + Logout | 18 | 0 | 0 | 73 |
| Regression lain: NlpClient (HTTP fake), Example unit/feature | 15 | 0 | 0 | 26 |
| **Total** | **90** | **0** | **0** | **481** |

- Syntax `php -l`: **79 file PHP/Blade lulus**. Blade yang dimuat test juga
  dikompilasi oleh Laravel; lint file Blade sendiri bukan pengujian seluruh UI browser.
- `artisan route:list -v`: **24 route**, dengan auth/guest/throttle/role sesuai
  route masing-masing; dijalankan dengan env SQLite testing eksplisit.
- `git diff --check`: lulus. Editor diagnostics file PHP yang diperbaiki: tanpa error.
- Scan ulang 79 file PHP/Blade: **0 BOM tersisa**.
- Regression baru dijalankan sebelum fix: **9 test, 1 PASS, 8 FAIL, 0 ERROR,
  0 SKIPPED, 27 assertions**. Setelah fix, test yang sama tanpa perubahan assertion:
  **9 PASS, 0 FAIL/ERROR/SKIPPED, 59 assertions**. Test rollback memastikan dua akun
  dan satu project benar-benar sempat ditulis sebelum error membership disuntikkan,
  lalu seluruh snapshot kembali seperti semula. Tidak ada test lama diubah/dihapus.
- Run pertama 23 test lama lulus/163 assertions pada PHP 8.5 dengan dua deprecation
  dependency. Validasi akhir memakai PHP 8.3 tanpa deprecation yang dilaporkan.
- Kegagalan sementara test baru diperbaiki: snapshot sebelum/sesudah dimuat dari DB
  agar urutan kolom sama; test production guard memanggil seeder langsung agar
  menguji guard, bukan prompt konfirmasi Artisan. Tidak ada test dihapus atau di-skip.
- **BDD: NOT RUN**; jumlah PASS BDD tidak diklaim.

## Kekurangan dan keputusan integrasi

**READY TO COMMIT untuk lingkup Sprint 1.** Tidak ada temuan Critical, High, atau
Medium yang masih terbuka dalam lingkup review ini. Seluruh regression baru dan
suite lama lulus. Batas verifikasi BDD browser dan concurrent InnoDB tetap berlaku.

1. Verifikasi request paralel pada instance MySQL testing disposable diperlukan
   untuk membuktikan runtime locking InnoDB. SQLite tidak mendukung row-level
   `FOR UPDATE`; test duplicate sekarang menjalankan request berurutan. Test race
   nama menyisipkan competing write secara deterministik, bukan proses paralel.
2. Behat/Mink, driver, context nyata, dan environment BDD terisolasi masih perlu
   disiapkan sesuai [petunjuk BDD](../bdd/PROJECT_MEMBERSHIP_BDD_NOTES.md).
3. **Integration TODO Sprint 3 / Joshua:** sepakati schema dan kepemilikan audit
   permanen, simpan event setelah commit dengan actor/project/target/waktu, uji
   rollback dan histori removal/reactivation. `Log::info` saat ini hanya log
   aplikasi dan dapat muncul sebelum commit; **tidak tersimpan ke activity_logs**.
4. Akun demo lama yang sudah dibuat dengan password tetap tidak diubah otomatis.
   Pemilik environment perlu mengatur ulang kredensial tersebut secara eksplisit.
5. Uji collation Unicode dan schema aktual database deployment diperlukan pada
   integrasi; patch tidak memigrasikan maupun menginspeksi MySQL development.

## Penyelesaian temuan final review

Critical: 0 terbuka. High: 0 terbuka. Medium: 0 terbuka, dua temuan sudah diperbaiki.
Low: tidak ada defect tambahan yang terkonfirmasi. Riwayat reproduksi berikut
menjelaskan alasan perbaikan; hasil gagal tersebut bukan hasil suite terbaru.

### Medium — Collision project seeder — RESOLVED

Lokasi: [TestProjectSeeder.php](../../backend/database/seeders/TestProjectSeeder.php).
Sebelumnya seeder mencari `Project Seeder Demo` hanya berdasarkan nama, kemudian membuat
membership aktif tanpa memeriksa kesesuaian owner. Jika project development dengan
nama itu sudah dimiliki Maintainer lain, Reporter demo mendapat akses tanpa undangan.
Perilaku ini sudah ada sebelum patch password dan saat review awal menggagalkan syarat
seeder aman pada database development yang berisi data. Guard local/testing tidak
mencegah collision ini. Password acak untuk akun baru sudah benar dan dipertahankan.

Reproduksi pada SQLite memory: buat Maintainer lain dan project dengan nama tersebut,
jalankan seeder, lalu periksa membership project. Probe mengharapkan tidak ada
membership baru tetapi menemukan satu baris: **FAIL**.

Perbaikan selesai: akun dicocokkan dengan email, nama fixture, dan role; project
existing harus dimiliki Maintainer fixture. Validasi dilakukan sebelum penulisan,
di dalam transaksi. Tidak ada pengambilalihan owner atau penyesuaian akun existing.
Delapan test seeder lulus; test lama untuk preservasi membership nonaktif juga lulus.
Jika identitas akun fixture telah diedit manual, seeder berhenti dengan error dan
tidak mengembalikan identitas lama secara diam-diam.

### Medium — Key testing berubah pada refresh — RESOLVED

Lokasi: [TestCase.php](../../backend/tests/TestCase.php).
Sebelumnya `createApplication()` selalu mengganti `app.key` dengan random baru. Test yang
mengenkripsi fixture/cookie lalu memanggil `refreshApplication()` tidak dapat
mendekripsi payload itu. Probe `Crypt::encryptString` → refresh aplikasi →
`Crypt::decryptString` menghasilkan **ERROR: The MAC is invalid**.
Ini regresi test harness baru dan dapat merusak test anggota lain yang
mensimulasikan restart aplikasi. Suite awal belum menguji alur tersebut.

Perbaikan selesai: private static property menyimpan key acak 32 byte berformat
base64 sekali per proses. Key hanya diterapkan setelah environment testing dan
config tanpa cache diverifikasi. Tidak ada key tersimpan dalam source atau file
environment dan tidak ada perubahan lifecycle Laravel lain. Regression test
memverifikasi key yang sama serta dekripsi ciphertext lama setelah refresh.
Pengaman SQLite, DB_URL, dan config cache tetap utuh.

### Bukti historis sebelum perbaikan

PHP 8.3.35, PHPUnit 11.5.57, seluruh database menggunakan SQLite `:memory:` dan
pengaman TestCase yang sama. Tidak ada koneksi MySQL development dibuka.

| Eksekusi | Tests | PASS | FAIL | ERROR | SKIPPED | Assertions |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Suite repository, dijalankan ulang | 81 | 81 | 0 | 0 | 0 | 422 |
| Dua probe sementara di luar repository | 2 | 0 | 1 | 1 | 0 | 2 |
| Total eksekusi final review | 83 | 81 | 1 | 1 | 0 | 424 |

Probe dan JUnit disimpan sebagai scratch lokal di
`/tmp/dupissue-final-review.Ub9qrt/`, bukan file yang akan di-commit.
Perintah probe dari backend:

```sh
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit /tmp/dupissue-final-review.Ub9qrt/FinalReviewProbeTest.php
```

Kedua probe historis sudah digantikan oleh regression permanen di repository;
hasil terbaru ada pada tabel QA di atas. Test yang ada menjalankan HTTP Laravel,
service, serta constraint DB nyata; tidak
ada fake step definitions atau test baru yang sengaja di-skip. Test duplicate
invitation tetap berurutan, sedangkan race nama memakai injeksi write nyata pada
SQLite setelah pre-check: keduanya tidak membuktikan concurrency InnoDB.

### Kelayakan scope dan staging

- Parent locking, current reads, dan constraint membership konsisten untuk writer
  yang melewati service. Reinvite dan reaktivasi tetap lulus. Tidak ada unique
  global invitation yang menghalangi histori.
- Exception handler tidak menelan seluruh exception database: hanya unique
  constraint pada save project diterjemahkan sebagai validasi nama. Dengan schema
  sekarang, selain primary key yang tidak dapat diisi dari input, nama adalah
  unique key terkait. Error database lainnya tetap diteruskan.
- Auth/RBAC sama dengan referensi lokal origin/main dan 18 test auth lulus.
  Migration hanya berubah BOM: nama tabel, kolom, foreign keys, dan tipe ID tidak
  berubah. Tidak ada perubahan kontrak schema untuk Submit Issue Elsa; integrasi
  branch Elsa yang belum ada di checkout ini belum diuji.
- Isolasi database aman untuk suite yang ada: konfigurasi dipaksa SQLite memory,
  DB_URL kosong, config cache ditolak, dan PDO/path diverifikasi sebelum migrasi.
  Semua koneksi non-SQLite sengaja dikeluarkan dari TestCase. Suite MySQL/multiple
  connections di masa depan memerlukan harness terpisah; jangan memakai DB development.
- Seluruh file perubahan sesuai lingkup, termasuk BDD dan laporan yang diminta.
  Dua perbaikan terakhir terbatas pada seeder, TestCase, dua test baru, dan laporan.
- Seluruh 17 kandidat file dalam tabel perubahan aman untuk di-stage setelah
  persetujuan pengguna. Staging/commit/push belum dilakukan.
- Jangan sertakan vendor, PHPUnit cache, package/services cache, compiled Blade,
  log Laravel, file environment, credential, atau probe/JUnit sementara. File
  generated yang ditemukan sudah ignored; tidak ada secret nyata yang ditemukan
  dalam 17 kandidat file. Literal password dalam assertion adalah data test negatif,
  bukan kredensial baru yang diberikan kepada akun demo.

Rekomendasi commit message:
`fix(project): harden membership lifecycle and isolate Sprint 1 tests`

## Git

Diff tracked: **10 file, +176 / -73 baris**. Ada **7 file baru (untracked)**,
termasuk laporan ini; file baru belum masuk hitungan `git diff --stat`.
Semua perubahan tetap unstaged pada `assist/reza-pr18`. Commit/push menunggu
persetujuan pengguna setelah review.
