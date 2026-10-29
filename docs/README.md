# Dokumentasi Proyek DupIssue

Dokumentasi ini mendukung pekerjaan Scrum Sprint 1–3 dan pengumpulan PPL Praktikum pada UAS. **Kode, artefak, dan hasil pengujian harus dapat ditelusuri ke fitur/penanggung jawabnya.**

## Indeks

| Lokasi | Isi | Status |
| --- | --- | --- |
| [`bdd/`](bdd/) | Skenario Gherkin Register dan Login/RBAC | Spesifikasi tersedia; belum menjadi bukti run Behat |
| [`artifacts/`](artifacts/README.md) | Struktur penyimpanan SRS, PlantUML, spesifikasi, GUI, dan database | Panduan tersedia; berkas sumber masih perlu dilengkapi tim |
| [`testing/`](testing/README.md) | Format bukti Laravel test, NLP test, Behat, dan validasi Product Owner | Panduan tersedia; bukti per fitur perlu diunggah |
| [`decisions.md`](decisions.md) | Keputusan teknis yang sudah diterapkan dan keputusan yang menunggu persetujuan | Perlu diperbarui bila keputusan berubah |
| [`../nlp-service/API_CONTRACT.md`](../nlp-service/API_CONTRACT.md) | Kontrak service FastAPI dan isu integrasi Laravel | Dokumen NLP tersedia |
| [`../README.md`](../README.md) | Panduan setup dan status proyek | Dokumentasi utama |

## Ketentuan menurut Panduan Monitoring PPL Prak Pasca UTS

- 3 sprint, masing-masing 2 minggu; **Sprint 1 = minggu 10–11, Sprint 2 = 12–13, Sprint 3 = 14–15** sesuai panduan terbaru.
- Setiap anggota bertanggung jawab mulai dari artefak hingga kode dan pengujian fitur.
- UAS memerlukan **working software**, persetujuan **Product Owner**, serta artefak rancangan (termasuk **teks PlantUML**), user story & scenario, dan hasil eksekusi BDD.
- Progress tracker per sprint harus memuat capaian, **burndown time**, risiko dan tindak lanjutnya.
- Dokumen SRS/diagram yang sudah final dipakai kembali saat UAS; hasil revisi harus dicatat, bukan diam-diam menimpa riwayat perancangan.

## Cara melengkapi

1. Tempatkan versi dokumen yang disetujui pada direktori terkait; gunakan nama file stabil tanpa `(1)`, `final-final`, atau nama lokal yang membingungkan.
2. Unggah sumber diagram `.puml` **dan** hasil render jika ada; gambar/PDF saja bukan pengganti source PlantUML.
3. Cantumkan ID requirement/use case atau GitHub Issue pada setiap artefak agar mudah diperiksa.
4. Simpan pengujian dalam `testing/` beserta hasil run dan konteks lingkungan pengujian, bukan hanya skenario `.feature`.
5. Perbarui tautan di indeks ini setelah file benar-benar diunggah; jangan mencantumkan status “selesai” jika baru direncanakan.

**Catatan penyelarasan:** dokumen Sprint Planning awal menggunakan pembagian minggu 9–10, 11–12, 13–14. Sebaiknya revisi jadwal di progress tracker berdasarkan panduan monitoring resmi, sambil mempertahankan rancangan pembagian fitur yang telah disetujui.
