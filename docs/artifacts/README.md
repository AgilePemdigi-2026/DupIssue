# Artefak Perancangan DupIssue

Panduan ini mengatur penyimpanan artefak perancangan yang diminta **Panduan Program Monitoring PPL Prak Pasca UTS**. Semua anggota bertanggung jawab atas artefak dan implementasi fitur yang dikerjakannya.

> **Status:** ini adalah **panduan struktur**, bukan klaim bahwa seluruh artefak UAS sudah tersedia di repository.

## Artefak yang harus dilengkapi

| Artefak | Format penyimpanan disarankan | Keterangan |
| --- | --- | --- |
| Use Case Diagram | `.puml` dan opsional `.png`/`.svg` | Simpan sumber teks PlantUML |
| Use Case Specification | `.md` atau dokumen SRS final | Traceability ke nama/ID use case |
| User Story & User Story Scenario | `.md` / `.feature` | Scenario dengan Given–When–Then (format Behat cheat sheet) |
| GUI Design | PDF/PNG dan tautan file desain editable jika ada | Jelaskan status mockup vs implementasi |
| Database / PDM | Sumber diagram (`.puml` bila dibuat dengan PlantUML), SQL/migration referensi, serta render | Jangan mengunggah dump data riil/kredensial |
| Activity Diagram | `.puml` dan opsional render | Sesuai alur Use Case |
| Sequence Diagram | `.puml` dan opsional render | Gunakan stereotypes berdasarkan Robustness Analysis Diagram |
| Class Diagram | `.puml` dan opsional render | Selaras dengan implementasi |
| BDD dan hasil pengujian | `docs/bdd/*.feature` dan `docs/testing/` | Harus ada hasil run pengujian aplikasi |

**Penting:** PDF berisi gambar diagram tidak menggantikan **sumber teks PlantUML** yang secara eksplisit diminta panduan. Spesifikasi berbentuk teks boleh ditulis sebagai Markdown/SRS, sedangkan diagram yang dibuat dengan PlantUML disimpan sebagai `.puml`.

## Struktur yang disarankan

Tambahkan folder berikut bertahap, bersamaan dengan file asli yang sudah siap:

```text
docs/
├── artifacts/
│   ├── README.md                  # Panduan ini
│   ├── srs/                       # Dokumen SRS final/versi disetujui
│   ├── use-case/                  # Diagram .puml + use case specification
│   ├── user-story/                # Daftar user story dan scenario
│   ├── gui/                       # Mockup UI dan tautan desain
│   ├── database/                  # PDM/ERD, .puml, dan skema
│   ├── activity/                  # Activity Diagram .puml
│   ├── sequence/                  # Sequence Diagram .puml
│   └── class/                     # Class Diagram .puml
├── bdd/                           # Skenario .feature
├── testing/                       # Bukti run test / persetujuan PO
└── sprints/
    ├── sprint-1/                  # Sprint Planning, tracker, burndown, risiko
    ├── sprint-2/
    └── sprint-3/
```

Git tidak melacak folder kosong: buat folder melalui file pertama yang benar-benar diunggah. Struktur di atas adalah **rencana lokasi**, bukan daftar berkas yang dipastikan sudah ada.

## Dokumen awal yang sudah dimiliki tim

Siapkan versi yang benar-benar disetujui dari dokumen berikut untuk diunggah ke lokasi yang sesuai:

| Dokumen | Lokasi yang disarankan |
| --- | --- |
| SRS DupIssue (UTS) | `docs/artifacts/srs/DupIssue_SRS_v1.0.pdf` |
| Dokumen proyek UTS / hasil analisis | `docs/artifacts/srs/DupIssue_UTS_Report.pdf` (opsional sebagai referensi) |
| Data mockup antarmuka | `docs/artifacts/gui/DupIssue_UI_Mockup.pdf` |
| Sprint Planning Kelompok 4 | `docs/sprints/sprint-1/Sprint_Planning.pdf` |
| Progress Tracker Sprint 1–3 | `docs/sprints/sprint-<n>/Progress_Tracker.pdf` atau `.md` |

Jika SRS atau Sprint Planning diperbarui setelah UTS, catat nomor versi, tanggal revisi, dan perubahan yang disetujui agar konsisten dengan UAS.

## Standar untuk setiap fitur

Setiap anggota mencantumkan:

1. **Nama fitur, ID requirement / use case, GitHub Issue, dan PIC.**
2. Link ke **source PlantUML** terkait (tidak hanya screenshot).
3. Link ke **user story dan scenario Gherkin**.
4. Link kode/PR yang mengimplementasikannya.
5. Link **bukti pengujian** dan status validasi Product Owner jika sudah ada.

Tidak perlu membuat ulang diagram yang sama untuk setiap fitur. Gunakan satu sumber diagram gabungan jika itu memang sudah disepakati tim, lalu tautkan bagian yang relevan.

## Cara upload melalui GitHub

1. Buka repository DupIssue dan pilih branch dokumentasi, misalnya `docs/readme-artifact-guide`, atau buat branch baru dari `main`.
2. Masuk folder `docs/` atau direktori target. Pilih **Add file → Upload files**.
3. Pilih PDF/PNG yang sudah final. Untuk source diagram, unggah file `.puml` dan file `.md` terkait.
4. Isi commit message yang jelas, misalnya `docs: add approved SRS and PlantUML use cases`.
5. Buat Pull Request ke `main` dan lakukan review sebelum merge.

Jangan unggah `.env`, token GitHub, kata sandi, dump database berisi data pribadi, hasil unduhan model NLP, atau dataset yang tidak berizin untuk dibagikan.
