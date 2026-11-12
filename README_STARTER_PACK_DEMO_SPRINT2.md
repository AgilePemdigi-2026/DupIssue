# DupIssue — Starter Pack Demo Sprint 2

Target deployment: **https://dupissue.caiis.ai** melalui Coolify, resource Docker Compose dengan database `dupissue` (MySQL 8.0).

**Penting:** paket ini tidak otomatis membuat akun saat di-deploy. Perintah CLI harus dijalankan secara eksplisit di container **`web`** lewat Terminal Coolify. Tidak ada password statis di source code; setiap password baru akan dibuat secara acak dan hanya dicetak ke terminal saat pembuatan atau reset.

## Akun demo

| Peran | Email login | Password |
|---|---|---|
| Maintainer | `sprint2.maintainer@dupissue.test` | Dibuat acak saat perintah dijalankan |
| Reporter A | `sprint2.reporter.a@dupissue.test` | Dibuat acak saat perintah dijalankan |
| Reporter B | `sprint2.reporter.b@dupissue.test` | Dibuat acak saat perintah dijalankan |

Catat password secara privat. Jangan screenshot atau mengirimnya ke anggota yang tidak perlu. Email domain `.test` bukan kotak email sungguhan; fitur Forgot Password OTP perlu akun dengan inbox sungguhan dan SMTP yang sudah dikonfigurasi.

## Instalasi melalui branch dan PR

1. Buka PowerShell di root repository.
2. Ekstrak ZIP paket ke root (path harus menjadi `backend/app/Console/Commands/SeedSprint2Demo.php`).
3. Buat branch baru berbasis `origin/main`, lalu stage **hanya** file PHP dan README ini. Jangan stage ZIP, `.env`, script pengujian, atau password.
4. Commit, push, buat PR, minta review, merge ke `main`.
5. Pada Coolify, jalankan **Redeploy** aplikasi Docker Compose agar file perintah Artisan ikut terpasang pada image `web` terbaru. Tidak perlu reset DB atau volume.

Contoh perintah jika ZIP ada di Downloads, dilakukan sebelum ada perubahan lokal belum disimpan:

```powershell
cd "C:\SEM5\PPL Prak\Projects\DupIssue\DupIssue"
git fetch origin
git switch -c chore/sprint2-demo-fixtures origin/main
Expand-Archive -LiteralPath "$env:USERPROFILE\Downloads\DupIssue_Sprint2_Demo_StarterPack.zip" -DestinationPath . -Force
php -l backend/app/Console/Commands/SeedSprint2Demo.php
git add -- backend/app/Console/Commands/SeedSprint2Demo.php README_STARTER_PACK_DEMO_SPRINT2.md
git --no-pager diff --cached --name-only
git diff --cached --check
git commit -m "chore(demo): add safe Sprint 2 demo fixture command"
git push -u origin chore/sprint2-demo-fixtures
```

Jika Git menolak `switch` karena perubahan lokal, jangan gunakan `-f`; selesaikan konflik working tree terlebih dahulu.

## Menjalankan di Coolify

1. Setelah PR masuk `main`, Coolify **Redeploy** resource DupIssue.
2. Coolify → DupIssue → **Terminal** (pilih service **`web`**, BUKAN `mysql`, `nlp`, atau `worker-nlp`).
3. Di shell container web:

```sh
cd /var/www/html
php artisan list --raw | grep demo:sprint2
php artisan demo:sprint2
```

Perintah tanpa `--create` adalah **DRY RUN** (tidak mengubah database). Pastikan database yang tampil sesuai konfigurasi DupIssue.

4. Jalankan satu kali:

```sh
php artisan demo:sprint2 --create
```

Output menampilkan tabel **3 email + 3 password acak** serta URL project dan ID issue. **Salin password ke tempat privat saat itu juga**, karena password tidak bisa dibaca lagi dari hash database.

5. Jika perintah dijalankan ulang, tidak menambah akun/project/issue ganda dan tidak mengganti password. Jika password hilang, kamu dapat merotasi dengan sengaja:

```sh
php artisan demo:sprint2 --create --reset-passwords
```

Setelah reset, simpan lagi ketiga password baru. Perintah ini hanya untuk akun demo yang namanya sesuai persis; akun lain tidak disentuh.

**Jangan jalankan** `php artisan migrate:fresh`, `db:wipe`, atau `db:seed` tanpa class karena itu bukan bagian starter pack dan dapat berdampak pada data lain.

## Data yang ditambahkan

Project: **`[DEMO SPRINT 2] Bug Tracker Kampus`**. Akun Maintainer menjadi owner; kedua Reporter memiliki membership aktif.

Lima issue contoh:

1. `[DEMO S2] Tombol Login tidak merespons di Chrome` — OPEN; contoh induk issue dan target Voting.
2. `[DEMO S2] Halaman dashboard project lambat dimuat` — IN_PROGRESS; demo filter status.
3. `[DEMO S2] Lampiran screenshot gagal diunggah` — OPEN; demo status.
4. `[DEMO S2] Filter daftar issue tidak diperbarui` — RESOLVED; demo filter.
5. `[DEMO S2] Duplikat: tombol login tidak merespons` — DUPLICATE; tersambung ke induk login secara *seed data*, bukan output NLP asli.

**Penting:** contoh tersebut adalah data buatan untuk demonstrasi, bukan hasil deteksi model. Submit issue baru melalui UI akan menguji FastAPI NLP yang sebenarnya.

## Urutan demo di kelas (5–8 menit)

1. Buka https://dupissue.caiis.ai lalu login **Reporter A**. Pilih project demo. Tunjukkan daftar issue dan filter `OPEN`/`IN_PROGRESS`/`RESOLVED`.
2. Buka issue login (`[DEMO S2] Tombol Login tidak merespons di Chrome`). Klik **Vote Issue**, lihat count naik; klik **Batalkan Vote**, lihat turun.
3. Login **Reporter B** di jendela Chrome Incognito. Vote pada issue yang sama; kembali ke Reporter A dan gunakan refresh status untuk menunjukkan count tersimpan. (Dua sesi browser membantu pergantian akun.)
4. Login Reporter A lagi. Di project demo, **Submit Issue** yang mirip:

   **Judul:** `Tombol Login tidak merespons di Chrome setelah password diisi`

   **Deskripsi:** `Ketika Reporter memasukkan email dan password pada halaman login lalu menekan tombol Masuk di Google Chrome, halaman tetap berada pada formulir login dan tidak muncul pesan kesalahan. Masalah berulang setiap kali tombol Masuk ditekan.`

   Saat pengisian, lihat rekomendasi sebelum submit jika NLP tersedia. Submit dan tunggu worker NLP memproses. Rekomendasi kemiripan bergantung pada hasil model nyata; **jangan menjanjikan skor persentase tertentu**.
5. Login **Maintainer**. Buka halaman project → **Review Duplikat** (`/projects/{id}/duplicates`), bandingkan kandidat baru yang muncul, dan lakukan validasi duplikat jika rekomendasinya tepat. Jika tidak muncul, jelaskan status deteksi melalui detail issue dan cek log `nlp` dan `worker-nlp`.
6. Maintainer buka salah satu issue OPEN, ubah status **OPEN → IN_PROGRESS**, konfirmasi; kemudian lanjut **IN_PROGRESS → RESOLVED**. Lihat riwayat status dan login Reporter untuk membaca status terbaru.
7. Buka issue yang sudah ditandai duplikat; jelaskan bahwa Vote pada child sah diteruskan ke issue induk. Jangan mencampur contoh status dengan hasil NLP; seeded fixture dan hasil model real adalah hal berbeda.

## Aturan keamanan

- Akun `.test` adalah **akun demo**, bukan email sungguhan. Gunakan inbox nyata jika ingin demonstrasi Forgot Password OTP.
- Jangan menaruh password di README, PR, screenshot, file `.env`, GitHub Issues, atau chat publik.
- Gunakan project demo saja. Perintah tidak menghapus existing data; jangan hapus volume database di Coolify.
- Hapus atau nonaktifkan akun demo setelah kegiatan berakhir sesuai kebijakan tim. Perintah ini **tidak** otomatis melakukan cleanup.
