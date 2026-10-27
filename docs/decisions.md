# Keputusan Teknis dan Integrasi DupIssue

Dokumen ini membedakan **yang sudah tercermin di kode `main`** dari **keputusan yang masih perlu disepakati**. Perubahan keputusan harus dicatat bersama PIC dan PR/Issue terkait; jangan menganggap rancangan sebagai implementasi yang sudah selesai.

## Teknologi proyek

- Antarmuka saat ini: Laravel Blade, Bootstrap 5.3, JavaScript.
- Backend: Laravel 12 (PHP 8.2+; tim menggunakan PHP 8.3).
- Database aplikasi: MySQL 8.
- NLP: Python 3.12, FastAPI, model `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`.
- Queue Laravel Database Queue direncanakan untuk integrasi pemrosesan issue/NLP; **belum dinyatakan selesai**.
- Git: `main` sebagai branch integrasi; pekerjaan baru melalui `feature/*`, `docs/*`, atau `chore/*`, di-review sebelum merge.

## Sudah diterapkan pada `main`

### Register dan Login/RBAC

- Registrasi publik melalui Laravel membuat akun dengan role default **`REPORTER`**. Klien tidak dapat memilih `MAINTAINER` melalui input Register.
- Password akun disimpan dalam bentuk hash.
- Login menggunakan Laravel session; setelah autentikasi berhasil, session diregenerasi.
- Reporter diarahkan ke named route `reporter.projects.index` (`/reporter/projects`); Maintainer ke `maintainer.dashboard` (`/maintainer/dashboard`).
- Kedua halaman tujuan masih **placeholder** sampai terintegrasi dengan modul Project & Membership.
- Middleware `role` memeriksa **role global** pengguna. Hak akses **per project** (owner dan anggota aktif) memerlukan policy/logic tambahan dalam modul Project & Membership.
- Endpoint Login menggunakan rate limit awal; parameter final perlu dievaluasi terhadap ketentuan keamanan SRS.

### NLP service

- FastAPI menyediakan `GET /health`, `POST /similarity`, dan `POST /candidates` (lihat [API Contract](../nlp-service/API_CONTRACT.md)).
- NLP hanya menghasilkan **skor kandidat kemiripan**; tidak mengubah status menjadi `DUPLICATE` secara otomatis.
- Laravel harus menentukan dan memvalidasi kandidat dalam **project yang sama** saat integrasi.
- Integrasi API, antrean Laravel, dan penyimpanan hasil NLP **belum dinyatakan selesai**.

## Terbuka: keputusan atau pekerjaan integrasi

| Topik | Pertanyaan / tindak lanjut | PIC terkait |
| --- | --- | --- |
| Akun Maintainer | Bagaimana provisioning akun Maintainer yang aman dilakukan? Registrasi publik hanya untuk Reporter. | Joshua, Reza |
| Keanggotaan project | Sinkronkan `projects.owner_id`, undangan, dan `memberships.is_active` dengan pemeriksaan akses di semua route project. | Reza, Joshua, Elsa |
| Route dashboard | Ganti placeholder `reporter.projects.index` dan `maintainer.dashboard` secara terkoordinasi; jangan mendaftarkan nama route ganda. | Reza, Joshua |
| Kontrak Laravel–NLP | Tetapkan base URL/port, timeout, retry, kegagalan layanan, bentuk payload, dan mekanisme antrean. Saat berjalan bersama, port 8000 Laravel dan 8001 NLP menghindari bentrok. | Valentino, Elsa, Joshua |
| Rentang skor NLP | Cosine similarity mentah dapat berada pada rentang **-1 sampai 1**, sedangkan rancangan kolom `duplicate_candidates` pada SRS/PDM membatasi **0–1**. Sepakati representasi penyimpanan sebelum integrasi. | Valentino, Joshua, Reza |
| Eksekusi BDD | File `.feature` sudah ditulis untuk Register dan Login/RBAC; perlu runner, step definitions, dan bukti hasil eksekusi bila Behat diwajibkan. | Semua PIC fitur |
| Dokumen sprint | Selaraskan jadwal di Sprint Planning/Progress Tracker dengan minggu 10–11, 12–13, 14–15 menurut panduan monitoring pasca UTS. | Joshua, seluruh tim |
| Product Owner | Catat penerimaan tiap fitur dan persetujuan sistem pada evaluasi UAS. | Joshua, seluruh tim |

## Dokumen terkait

- [README proyek](../README.md)
- [Indeks dokumentasi](README.md)
- [Artefak perancangan](artifacts/README.md)
- [Panduan bukti pengujian](testing/README.md)
- [NLP API Contract](../nlp-service/API_CONTRACT.md)

**Status diperbarui sesuai hasil review dan integrasi; bukan persetujuan otomatis atas item yang masih terbuka.**
