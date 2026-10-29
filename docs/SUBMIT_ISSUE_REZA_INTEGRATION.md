# Integrasi lokal Elsa Submit Issue dengan main Reza

Tanggal pemeriksaan: 29 Oktober 2026. Branch: `assist/elsa-pr19`.

## Hasil utama

- Main yang diintegrasikan: `8ad8a70242379b5a63a76de868b1768b17c539f4`, merge PR #18,
  sudah mencakup merge PR #24 (`2971ab0`) dan fix Reza `82efef8`.
- Checkpoint lokal sebelum fetch/merge: `7b39824`, pesan persis
  `wip(elsa): checkpoint submit issue fixes before project integration`.
- **Full PHPUnit: 128 tests — 120 PASS, 0 FAIL, 0 ERROR, 8 SKIPPED; 736 assertions.**
- **17 dari 25 test Elsa yang sebelumnya skipped kini berjalan dan PASS.**
- **27 routes**, termasuk 24 routes aplikasi, 2 storage routes dan health `/up`.
- Sisi Elsa/Reza siap untuk tahap integrasi NLP. Eksekusi integrasi NLP tetap
  **BLOCKED_BY_NLP_QUEUE**, bukan E2E NLP selesai.
- Tidak ada fake Job, penyalinan/merge implementasi NLP, push, PR baru, atau perubahan remote.

## Checkpoint dan audit scope

Sebelum integrasi, branch diverifikasi dan 19 perubahan lama diaudit. Seluruhnya
terkait Submit Issue, testing dan dokumentasi. Checkpoint menggunakan daftar file
eksplisit; tidak menyertakan environment development, credential, vendor, cache,
log, SQLite file atau hasil test sementara. Working tree bersih setelah checkpoint.
Dependency vendor dan cache runtime yang diabaikan Git tetap ada untuk pengujian;
tidak dihapus dan tidak dicommit.

`git fetch origin` kemudian memperbarui remote-tracking main dan branch Project.
Merge dilakukan hanya pada branch bantuan ini. Branch lokal main tetap `5b0b55c`,
dan sumber `origin/feature/submit-issue-complete` tetap `c575193`.
Pekerjaan lama tetap tersimpan dalam checkpoint; tidak menggunakan reset atau stash.

## Konflik dan penyelesaian manual

| File | Konflik | Resolusi |
| --- | --- | --- |
| [web.php](../backend/routes/web.php) | Import controller dan penambahan route pada blok auth | Gabungkan seluruh import dan kedua kelompok route; dashboard Reporter/Maintainer tetap ProjectMemberController@index |
| [TestCase.php](../backend/tests/TestCase.php) | Bootstrap/isolasi database | Pertahankan implementasi PR #24: parent bootstrap, penolakan cache/non-testing, satu APP_KEY per proses, purge dan verifikasi PDO SQLite memory; tambahkan config queue/failed_jobs/batching ke SQLite |
| [tests/bootstrap.php](../backend/tests/bootstrap.php) | add/add | Pertahankan validasi awal PR #24 termasuk APP_CONFIG_CACHE kustom/relatif; sinkronkan environment tervalidasi ke $_ENV dan $_SERVER tanpa mengubah APP_KEY |

[phpunit.xml](../backend/phpunit.xml) auto-merge dengan baik dan identik dengan main:
APP_ENV/DB_CONNECTION/DB_DATABASE/DB_URL memakai `force="true"`.
[bootstrap/app.php](../backend/bootstrap/app.php) mempertahankan prioritas middleware
role sebelum SubstituteBindings dari checkpoint Elsa.

Tidak memilih seluruh ours/theirs. Kode ProjectService, ProjectMemberController,
Project, Membership, migration Reza, dan test Reza dipertahankan identik dengan main.

Saat uji environment dummy pertama, dua test guard berhenti dengan ERROR sebelum
migration: getenv() sudah testing, tetapi $_SERVER masih membawa APP_ENV=local.
Sinkronisasi empat environment yang sudah divalidasi memperbaikinya tanpa melemahkan
guard. Pengulangan menghasilkan **2 PASS, 20 assertions**, termasuk enkripsi/dekripsi
setelah refreshApplication() dengan key yang sama. Ini kegagalan awal yang sudah
ditangani; bukan error pada suite final.

## Integrasi routes, UI dan field

Route Register, Login, Logout, kedua dashboard, Project create/read/update,
membership, invitations serta tiga routes Issue tetap ada. Tidak menambahkan endpoint
delete Project yang memang tidak disediakan main. Tidak ada dashboard placeholder
yang menggantikan ProjectMemberController.

| Kelompok | Jumlah route |
| --- | ---: |
| Home | 1 |
| Register/Login/Logout | 5 |
| Reporter/Maintainer dashboard | 2 |
| Project | 7 |
| Membership + pembatalan undangan project | 4 |
| Invitations Reporter | 2 |
| Submit create/store/show | 3 |
| Storage dan health bawaan Laravel | 3 |
| **Total route:list** | **27** |

GET/HEAD dihitung sebagai satu entri route. Route GET `/projects/new` tetap mendahului
route dinamis Project. Alias PUT settings tetap dipertahankan selain POST.

[Halaman Project](../backend/resources/views/projects/show.blade.php) kini memasang
[partial Submit](../backend/resources/views/issues/submit-link.blade.php), hanya untuk
Reporter anggota aktif. Controller tetap memeriksa role dan membership untuk GET/POST;
tombol tersembunyi bukan satu-satunya kontrol akses. [Layout bersama](../backend/resources/views/layouts/app.blade.php)
juga memasang partial Logout yang sudah ada agar navigasi Logout dari halaman Issue
tetap tersedia setelah beralih ke layout Reza.

Test membuktikan title/description divalidasi, field forged diabaikan, dan Issue
memiliki OPEN/PENDING/FORM/0/null sebelum callback afterCommit berjalan. Callback
tetap DB::afterCommit; tidak ada HTTP FastAPI saat Submit.
**Karena Job asli belum terintegrasi, setelah commit detection_status menjadi FAILED,
sementara Issue tetap tersimpan OPEN dan halaman sukses dapat dibuka.** PENDING
awal dan FAILED akibat Job absen adalah dua tahap berbeda; bukan bukti queue sukses.

## Pengujian final

Runtime: PHP 8.3.35, Laravel 12.70.0, PHPUnit 11.5.57. Perintah dari backend:

```sh
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --display-skipped
```

| Suite | PASS | FAIL | ERROR | SKIPPED | Assertions |
| --- | ---: | ---: | ---: | ---: | ---: |
| ProjectManagementTest | 23 | 0 | 0 | 0 | 163 |
| ProjectMembershipSecurityTest | 25 | 0 | 0 | 0 | 160 |
| SubmitIssueTest | 17 | 0 | 0 | 8 | 172 |
| SubmitIssueSuccessTest (baru) | 3 | 0 | 0 | 0 | 26 |
| SubmitIssueDatabaseIsolationTest (baru) | 4 | 0 | 0 | 0 | 26 |
| SubmitIssueAccessTest | 3 | 0 | 0 | 0 | 11 |
| Login/RBAC | 7 | 0 | 0 | 0 | 21 |
| Register | 7 | 0 | 0 | 0 | 22 |
| Logout | 4 | 0 | 0 | 0 | 30 |
| TestProjectSeederSafetyTest | 8 | 0 | 0 | 0 | 47 |
| TestingApplicationKeyTest | 1 | 0 | 0 | 0 | 12 |
| TestingDatabaseIsolationTest | 1 | 0 | 0 | 0 | 8 |
| IssueTest | 2 | 0 | 0 | 0 | 12 |
| NlpClientTest | 13 | 0 | 0 | 0 | 24 |
| Existing example tests | 2 | 0 | 0 | 0 | 2 |
| **Total** | **120** | **0** | **0** | **8** | **736** |

Count mencakup data provider, tanpa menjumlahkan suite anak dua kali.
SubmitIssueSuccessTest dan SubmitIssueDatabaseIsolationTest belum ada pada checkpoint
atau main; ditambahkan untuk pengujian navigasi/persistence dan FK/isolation yang diminta.
Tidak mengurangi assertion atau mengubah expected result untuk menyembunyikan kegagalan.
Gate Project yang sebelumnya skip kini fail jika dependency Reza hilang lagi.

### Delapan kasus BLOCKED_BY_NLP_QUEUE

Semua berada dalam [SubmitIssueTest](../backend/tests/Feature/SubmitIssueTest.php),
group `nlp-integration`, menggunakan Job asli bila tersedia:

1. Submit dengan field server dan bukti enqueue NLP sukses.
2. Database job hanya masuk sesudah outer commit.
3. Rollback membuang callback; commit berikutnya hanya mengirim satu Job.
4. Queue unavailable tidak menghapus committed Issue.
5. Worker menghabiskan tiga attempts dan menyimpan FAILED.
6. Konfigurasi driver database yang salah tidak menjalankan NLP inline.
7. Kegagalan sekunder menyimpan FAILED tidak menggagalkan response Submit.
8. Retry sukses dan redelivery tidak menggandakan kandidat.

Ketujuh test baru bukan bagian dari 17 test lama yang menjadi unblocked.
No-job failure path, field protection, authorization, input limits, rollback insert,
detail lintas-project dan revoked membership sudah lulus dengan model Reza asli.

## QA database dan aplikasi

- Sebelum suite bermigration, guard diperiksa dengan APP_ENV=local, DB_CONNECTION=mysql,
  DB_DATABASE=do_not_use dan DB_URL dummy. Hasil akhir hanya PDO SQLite `:memory:`;
  tidak ada koneksi MySQL. APP_KEY dan ciphertext bertahan setelah application refresh.
- Migration order diuji pada migration records: users → projects → memberships → issues;
  invitation migration juga tersedia sebelum issues.
- Foreign keys aktif. Issue project_id → projects.id RESTRICT; author_id → users.id SET NULL;
  duplicate_of → issues.id SET NULL. PRAGMA foreign_key_check bersih.
- FK menolak project yang tidak ada, penghapusan author menjaga Issue dan membuat
  author_id null, serta project ber-Issue tidak dapat dihapus. Relasi Issue ke Project
  memakai model asli.
- PHP syntax app/bootstrap/routes/tests/migrations/views PASS; route:list sukses;
  Git diff check bersih. Blade dijalankan melalui feature tests navigasi dan rendering.
- Tidak ada perubahan pada NlpClient, dependency lock, model/threshold NLP, eksperimen,
  atau implementasi Job Valentino. Top 3/Vote tidak ditambahkan.
- Behat/Mink **NOT RUN**: runner dan step definitions tetap belum ada.

## Detail konflik trait Draft PR #22

Snapshot aktual yang diperiksa setelah fetch:
`origin/feature/nlp-database-queue` = `df9381a33d0888badbf18405c46c6157ad8e28b2`.

- [Job asli baris 20–24](https://github.com/AgilePemdigi-2026/DupIssue/blob/df9381a33d0888badbf18405c46c6157ad8e28b2/backend/app/Jobs/DetectIssueCandidatesJob.php#L20)
  memakai trait `Illuminate\Bus\Queueable` dan mendeklarasikan `public $afterCommit = true`
  pada **baris 24**.
- [Queueable.php](../backend/vendor/laravel/framework/src/Illuminate/Bus/Queueable.php),
  **baris 57**, sudah mendeklarasikan `public $afterCommit` (default null).
- Nilai default kedua properti berbeda sehingga komposisi class/trait fatal.
  Pemuatan source Job langsung dari Git ke proses PHP terpisah menghasilkan **exit 255**.
  `handle()` tidak dipanggil, tidak ada DB access, file Job tidak disalin ke branch ini.
- Ini cacat pada **Job asli terhadap trait framework yang dipakai**, bukan konflik
  file akibat integrasi Reza/Elsa. Lint `php -l` pada file Job saja tidak cukup untuk
  mendeteksi komposisi trait yang baru diperiksa saat class dimuat.

Perbaikan minimal yang diperlukan pada pekerjaan Valentino (tidak diterapkan di sini):

```php
// Remove the Job's duplicate property declaration: public $afterCommit = true;
public function __construct(public readonly int $issueId)
{
    $this->onConnection('database')->onQueue('nlp')->afterCommit();
}
```

Hasil FAIL class-load ini terpisah dari PHPUnit lokal 0 FAIL/ERROR; bukan klaim PR #22 lulus.
Setelah class dapat dimuat dan Job/DuplicateCandidate/migration tersedia melalui proses
integrasi yang disepakati, jalankan group nlp-integration dengan worker asli.
Risiko concurrency/recovery yang dicatat pada [audit awal](SUBMIT_ISSUE_SPRINT1_AUDIT.md)
belum terselesaikan oleh merge Reza.

## File yang disesuaikan pada tahap ini

Selain file Reza/dokumentasi upstream yang masuk melalui merge main, perubahan manual:

| File | Tujuan |
| --- | --- |
| [routes/web.php](../backend/routes/web.php) | Resolusi import dan route kedua modul, dashboard asli |
| [tests/TestCase.php](../backend/tests/TestCase.php) | Pertahankan PR #24 dan tambahkan queue SQLite |
| [tests/bootstrap.php](../backend/tests/bootstrap.php) | Pertahankan guard PR #24 dan selaraskan $_SERVER |
| [phpunit.xml](../backend/phpunit.xml) | Hasil auto-merge menggunakan force=true dari main |
| [projects/show.blade.php](../backend/resources/views/projects/show.blade.php) | Pasang link Submit |
| [layouts/app.blade.php](../backend/resources/views/layouts/app.blade.php) | Pertahankan akses Logout di layout bersama |
| [issues/submit-link.blade.php](../backend/resources/views/issues/submit-link.blade.php) | Komentar integrasi sudah aktif |
| [SubmitIssueTest.php](../backend/tests/Feature/SubmitIssueTest.php) | Fail untuk dependency Reza hilang; NLP skip dengan label eksplisit |
| [SubmitIssueSuccessTest.php](../backend/tests/Feature/SubmitIssueSuccessTest.php) | Baru: navigasi, role, field awal sebelum scheduling dan committed persistence |
| [SubmitIssueDatabaseIsolationTest.php](../backend/tests/Feature/SubmitIssueDatabaseIsolationTest.php) | Baru: isolation, migration order, relasi dan FK |
| [Audit awal](SUBMIT_ISSUE_SPRINT1_AUDIT.md) | Tandai historis dan arahkan ke hasil terbaru |
| [Catatan BDD](bdd/SUBMIT_ISSUE_BDD_NOTES.md) | Project tersedia; status BDD tetap belum executable |
| [Laporan ini](SUBMIT_ISSUE_REZA_INTEGRATION.md) | Hasil integrasi lokal |
| [Template PR](../.github/PULL_REQUEST_TEMPLATE.md) | Hapus tiga trailing spaces bawaan main agar staged diff check lulus; isi tidak diubah |

Untuk daftar lengkap yang juga mencakup semua file upstream:
`git diff --name-status 7b39824 HEAD` setelah merge selesai.

## Kesiapan dan batas akhir

Fungsi Submit dan Project yang dapat dijalankan tanpa NLP asli telah lulus; siap
menjadi dasar pengujian integrasi NLP berikutnya. Belum boleh disebut berhasil
end-to-end NLP atau siap merge PR #22 sampai blocker class-load dan delapan test
tersebut diselesaikan. Tidak membuat atau memperbaiki Job milik Valentino di branch ini.

Merge diselesaikan sebagai commit lokal di atas checkpoint setelah QA. Tidak ada push,
PR bantuan, merge remote, atau modifikasi branch main/sumber Elsa. Hasil test XML
sementara berada di luar repository dan tidak masuk commit.
