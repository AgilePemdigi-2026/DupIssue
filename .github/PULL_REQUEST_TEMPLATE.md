## Ringkasan

Jelaskan fitur/perbaikan yang dibuat dan alasan perubahan.

**GitHub Issue:** #...

**Sprint / PIC:** ...

## Perubahan utama

-
-

## Requirement dan artefak

- [ ] Requirement / Use Case / User Story terkait disebutkan dan sesuai SRS atau addendum yang disepakati.
- [ ] Artefak rancangan diperbarui jika ada perubahan alur, database, hak akses, atau antarmuka.
- [ ] Source diagram `.puml` disertakan apabila relevan; tidak hanya hasil screenshot/render.
- [ ] Perubahan route, schema, API, dan aturan hak akses sudah dikoordinasikan dengan pemilik fitur lain.

## Pengujian

**Perintah yang dijalankan:**
```text
# contoh: php artisan test --filter=LoginTest
```

**Hasil aktual:** (jumlah PASS/FAIL, screenshot atau link bukti bila tersedia)

- [ ] Pengujian otomatis terkait sudah dijalankan.
- [ ] Regression test pada modul yang terkait sudah dijalankan.
- [ ] Skenario Gherkin/user story scenario diperbarui jika diperlukan.
- [ ] Status Behat dicatat apa adanya: **belum dijalankan / PASS / FAIL**, dengan bukti run jika ada.
- [ ] Tidak ada `.env`, token, kredensial, data sensitif, atau artefak lokal yang ikut di-commit.

## Dampak integrasi dan risiko

Apakah branch ini memerlukan migration, konfigurasi lokal, queue, service NLP, atau perubahan yang dapat menimbulkan konflik dengan fitur lain?

## Checklist review

- [ ] Sudah direview oleh anggota lain.
- [ ] Tidak ada merge conflict dan required checks sudah lolos.
- [ ] Dokumentasi, status Issue, dan bukti test akan diperbarui sesuai hasil aktual.

> Jangan menganggap Gherkin yang baru ditulis telah lulus Behat tanpa eksekusi oleh runner.
