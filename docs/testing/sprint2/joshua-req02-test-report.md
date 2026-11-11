# Laporan Hasil Pengujian Sprint 2 — Feature 7: Lihat Status Issue (REQ-02)

## 1. Informasi Pengujian

| Informasi | Keterangan |
|---|---|
| Project | DupIssue |
| Sprint | Sprint 2 |
| Feature | Feature 7 — Lihat Status Issue |
| Requirement | REQ-02.1–REQ-02.10 |
| Developer / PIC | Joshua Charis Giovanni Mangialu |
| Reviewer | Valentino Kevin Iversen |
| Branch | `feature/sprint2-issue-status` |
| Pull Request | [PR #42](https://github.com/AgilePemdigi-2026/DupIssue/pull/42) |
| Baseline integrasi | `main@fa9520c` |
| Tanggal pengujian | 11 November 2026 (WIB) |
| Status | Pengujian lokal PASS; menunggu CI terbaru dan persetujuan reviewer |

## 2. Tujuan Pengujian

Pengujian dilakukan untuk memastikan implementasi Feature 7 memenuhi kebutuhan pemantauan status issue secara *read-only* bagi Reporter dan Maintainer. Pengujian mencakup pencarian dan filter issue, tampilan detail, riwayat perubahan status, relasi issue duplikat, pembaruan status melalui tombol Muat Ulang, serta pembatasan akses berdasarkan keanggotaan project.

Pengujian ulang juga dilakukan untuk menindaklanjuti lima catatan review PR #42 dan memastikan perubahan tersebut tidak mengganggu fitur Sprint 1 maupun Feature 8 yang telah terintegrasi.

## 3. Lingkungan dan Metode Pengujian

Pengujian dilakukan menggunakan Laravel, PHPUnit, Behat/Mink, Node.js, dan browser.

- **PHPUnit:** Menggunakan database SQLite `:memory:` melalui konfigurasi `phpunit.xml`.
- **BDD:** Menggunakan `APP_ENV=behat` dengan SQLite `:memory:` dan konfigurasi `behat.yml`.
- **JavaScript:** Menggunakan pemeriksaan sintaks Node.js dan pengujian interaksi Feature 8.
- **Manual browser:** Menggunakan server Laravel lokal dan database QA terpisah `sprint2_pr42_qa.sqlite`.
- **Isolasi data:** Database pengujian dipisahkan dari database demonstrasi Sprint 1.

## 4. Hasil Pengujian Otomatis

| Pengujian | Hasil | Keterangan |
|---|---|---|
| PHPUnit — IssueStatusReadOnlyTest | PASS | Pengujian pembacaan status, daftar issue, riwayat, otorisasi, dan relasi duplikat |
| PHPUnit — IssueStatusManagementTest | PASS | Pengujian perubahan status, konfirmasi, audit log, dan regresi tampilan |
| PHPUnit — Full Regression | PASS | **240 passed, 2 skipped, 0 failed** |
| Behat — Sprint 1 | PASS | Regresi alur Sprint 1 |
| Behat — Sprint 2 | PASS | Integrasi BDD Feature 7 dan Feature 8 |
| JavaScript Syntax Check | PASS | Pemeriksaan `issue-status-refresh.js` |
| JavaScript — Feature 8 | PASS | Regresi interaksi rekomendasi issue serupa |

Dua pengujian PHPUnit yang berstatus *skipped* merupakan pengujian kondisional dan tidak dihitung sebagai kegagalan. Seluruh pengujian yang dieksekusi pada regresi terakhir berhasil tanpa kegagalan.

Konfigurasi BDD Sprint 2 telah mencakup `IssueRecommendationBddContext` dan `IssueStatusBddContext`, sehingga skenario kedua fitur dapat dijalankan melalui suite yang sama.

## 5. Tindak Lanjut Catatan Code Review

### 5.1 P1 — Penyempurnaan Riwayat Perubahan Status

**Temuan:** Tampilan sebelumnya hanya memperlihatkan kode aktivitas seperti `ISSUE_STATUS_CHANGED`, waktu, dan alasan, tanpa menunjukkan perubahan status yang terjadi.

**Perbaikan:** Data `old_values` dan `new_values` dari `activity_logs` dimanfaatkan untuk menampilkan transisi status. Respons API refresh dan tampilan detail diselaraskan.

**Hasil:** PASS. Riwayat menampilkan perubahan seperti `OPEN → IN_PROGRESS`, disertai waktu dan alasan apabila tersedia.

### 5.2 P1 — Sinkronisasi Relasi Issue Induk

**Temuan:** Ketika Maintainer mengubah issue menjadi duplikat, tombol Muat Ulang sebelumnya hanya memperbarui status tanpa memperbarui tautan Issue Induk.

**Perbaikan:** Respons refresh menyediakan informasi relasi induk. Jika terjadi perubahan relasi, halaman dimuat ulang sepenuhnya agar tautan dan komponen kondisional tersinkronisasi.

**Hasil:** PASS. Perubahan relasi dapat ditampilkan setelah Reporter menekan Muat Ulang tanpa harus melakukan refresh manual sebelumnya.

### 5.3 P2 — Kesesuaian Daftar Issue dengan Mockup

**Temuan:** Daftar issue belum menampilkan informasi Master Cluster dan Duplikat dari secara eksplisit.

**Perbaikan:** Tampilan daftar dilengkapi identifikasi Master Cluster, jumlah duplikat terkait, serta penanda dan tautan Duplikat dari pada issue duplikat.

**Hasil:** PASS. Informasi relasi issue menjadi lebih mudah dibedakan tanpa mengubah fungsi utama pencarian dan filter.

### 5.4 P2 — Manual Browser Testing dan HTTP 419

**Temuan:** Pengujian Behat berbasis BrowserKit tidak mengeksekusi JavaScript browser. Selain itu, sebelumnya terdapat laporan HTTP 419 pada lingkungan lokal.

**Tindakan:** Dilakukan pengujian browser nyata untuk tombol Muat Ulang, kondisi offline, indikator data lama, tombol Coba Lagi, dan pembatasan akses pengguna.

**Hasil:** PASS untuk skenario manual yang diuji.

HTTP 419 tidak boleh dinyatakan telah diperbaiki secara permanen tanpa bukti penyebabnya. Apabila tidak muncul kembali dalam pengujian terbaru, hasilnya dicatat sebagai *not reproduced* pada lingkungan QA, bukan sebagai bug aplikasi yang sudah dikonfirmasi terselesaikan.

### 5.5 P2 — Sinkronisasi Dokumentasi dan BDD

**Temuan:** Dokumentasi pengujian belum mencerminkan hasil eksekusi terbaru. Terdapat pula perbedaan antara heading halaman dan ekspektasi pengujian lama.

**Perbaikan:** Heading pengguna diselaraskan menjadi **Riwayat perubahan status**, dan assertion pada `IssueStatusManagementTest` diperbarui agar sesuai dengan tampilan aktual.

**Hasil:** PASS. Setelah perbaikan assertion, regresi PHPUnit menghasilkan **240 passed, 2 skipped, 0 failed**.

## 6. Hasil Pengujian Manual Browser

| Skenario | Hasil |
|---|---|
| Pencarian berdasarkan judul dan deskripsi | PASS |
| Filter status dan Reset Filter | PASS |
| Detail issue dan riwayat perubahan status | PASS |
| Tombol Muat Ulang membaca status terbaru | PASS |
| Tampilan transisi status sebelum dan sesudah perubahan | PASS |
| Sinkronisasi tautan Issue Induk setelah perubahan relasi | PASS |
| Tampilan Master Cluster dan Duplikat dari | PASS |
| Simulasi offline dan indikator data lama (*stale*) | PASS |
| Tombol Coba Lagi setelah koneksi pulih | PASS |
| Pembatasan akses Reporter dan Maintainer | PASS |

Pengujian manual dilakukan menggunakan browser nyata untuk memverifikasi interaksi yang tidak dapat dibuktikan oleh Behat/Mink dengan driver BrowserKit.

## 7. Bukti Pengujian (*Evidence*)

Bukti pengujian yang perlu dilampirkan pada PR #42 meliputi:

1. Output PHPUnit full regression dengan hasil 240 passed, 2 skipped, 0 failed.
2. Output pengujian Behat Sprint 1 dan Sprint 2.
3. Output pengujian JavaScript.
4. Screenshot daftar issue dengan penanda Master Cluster dan Duplikat dari.
5. Screenshot riwayat status yang menampilkan transisi `OPEN → IN_PROGRESS`.
6. Screenshot tautan Issue Induk setelah perubahan relasi dan proses Muat Ulang.
7. Screenshot kondisi offline, pesan gagal, indikator *stale*, dan tombol Coba Lagi.
8. Screenshot kondisi berhasil setelah koneksi dipulihkan.

Screenshot dan log pengujian dilampirkan sesuai file evidence aktual; tidak ada evidence yang dinyatakan tersedia sebelum diunggah.

## 8. GitHub Actions dan Validasi Integrasi

Pada commit PR sebelumnya (`271120c`), dua workflow GitHub Actions telah selesai dengan status SUCCESS:

- [Sprint 1 BDD](https://github.com/AgilePemdigi-2026/DupIssue/actions/runs/42785632769)
- [Sprint 2 Feature 8 — PHPUnit and Behat Regression](https://github.com/AgilePemdigi-2026/DupIssue/actions/runs/42785632764)

Hasil tersebut merupakan bukti pengujian commit sebelumnya, bukan validasi otomatis terhadap revisi terbaru.

Setelah revisi PR #42 di-push, kedua workflow harus dijalankan kembali dan diperiksa pada commit terbaru sebelum merge.

## 9. Kesimpulan dan Status Akhir

Pengujian lokal menunjukkan bahwa Feature 7 — Lihat Status Issue telah menjalankan fungsi utama REQ-02 dengan baik. Hasil regresi PHPUnit menunjukkan 240 pengujian berhasil, dua pengujian dilewati, dan tidak terdapat kegagalan.

Perbaikan terhadap dua temuan P1 dan tiga temuan P2 telah ditindaklanjuti dan diuji ulang, termasuk tampilan riwayat status, sinkronisasi Issue Induk, penyesuaian mockup, interaksi JavaScript, dan konsistensi assertion pengujian.

**Status pengujian lokal: PASS.**

**Status merge: PENDING**, sampai hasil GitHub Actions pada commit terbaru dinyatakan SUCCESS, evidence dilampirkan, dan reviewer memberikan persetujuan final.

## 10. Sign-off

| Tahap | Status |
|---|---|
| Implementasi Feature 7 | Completed |
| PHPUnit Regression | PASS |
| BDD Regression | PASS |
| JavaScript Testing | PASS |
| Manual Browser Testing | PASS |
| Tindak Lanjut Code Review | Implemented and locally tested |
| GitHub Actions setelah revisi terakhir | PENDING |
| Final Reviewer Approval | PENDING |
| Product Owner Acceptance | PENDING |
| Merge PR #42 | PENDING |

**Developer:** Joshua Charis Giovanni Mangialu

**Reviewer:** Valentino Kevin Iversen

**Pull Request:** https://github.com/AgilePemdigi-2026/DupIssue/pull/42