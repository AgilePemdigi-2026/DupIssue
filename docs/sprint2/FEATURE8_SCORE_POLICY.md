# Feature 8 — Kebijakan Representasi Skor NLP (v1.1, untuk review)

**Ruang lingkup:** hanya rekomendasi pre-submit pada `POST /projects/{project}/issues/recommendations`. Perubahan kebijakan tampilan ini **belum dinyatakan disetujui Joshua**. Dokumen ini menjelaskan keputusan teknis yang diusulkan untuk menyelesaikan review PR #39.

## Sumber skor dan pemeringkatan

FastAPI `/candidates` mengembalikan skor cosine mentah (idealnya dalam rentang `[-1, 1]`). Backend mengurutkan kandidat berdasarkan **skor mentah menurun**, lalu ID issue menaik saat seri. Tidak ada threshold 0,75 pada pre-submit; maksimal tiga **target issue unik** yang valid ditampilkan. Skor tidak otomatis menetapkan status DUPLICATE.

Skor non-finite (`INF`/`-INF`/`NaN`) dan nilai di luar rentang cosine dengan toleransi numerik `1e-6` **diabaikan** sebagai kandidat tidak valid. Item dengan tipe skor salah ditolak oleh `NlpClient` dan menghasilkan respons aman `NLP_UNAVAILABLE` (HTTP 503).

## Kontrak respons dan interpretasi tampilan

- `cosine_similarity`: skor mentah bertanda, misalnya `-0.35`, `0`, atau `0.82`. Nilai ini **ditampilkan kepada pengguna** sebagai *Cosine mentah* untuk menjelaskan nilai negatif.
- `similarity`: `max(0.0, min(1.0, cosine_similarity))`, **hanya indikator kekuatan cosine positif untuk progress bar**. Dikalikan 100 untuk tampilan persen. Ini **bukan peluang (probabilitas) dua laporan merupakan duplikat**.
- Cosine `-0.35` menghasilkan `similarity: 0` dan UI `0%`, **tetapi** nilai `cosine_similarity: -0.35` tetap terlihat. Cosine nol juga menghasilkan `0%`; kedua nilai dapat dibedakan dari label *Cosine mentah*.
- Cosine `1` menghasilkan `100%`; `-1` menghasilkan `0%` dengan raw `-1` tetap terlihat.
- Pelabelan frontend menggunakan **"Sinyal kemiripan positif"**; panel menjelaskan bahwa 0% dapat mewakili cosine negatif dan bahwa skor bukan keputusan duplikat.

**Mengapa tetap memakai clamp:** menjaga kontrak indikator 0–100% yang telah dipakai panel dan progress bar, sehingga pengurutan tetap mencerminkan cosine asli, tanpa menyalahartikan nilai negatif sebagai confidence. Menampilkan cosine signed secara terpisah mengatasi kelemahan clamp yang semula menyamarkan nilai negatif.

## Batas perubahan

Tidak ada perubahan pada `DetectIssueCandidatesJob`, threshold 0,75 pasca-submit, tabel `duplicate_candidates`, atau keputusan manual Maintainer. Tidak ada normalisasi probabilistik `(cosine + 1) / 2`: transformasi tersebut mengubah arti angka dan membutuhkan persetujuan eksplisit tim.

## Perubahan perilaku kandidat duplikat

Setiap kandidat dengan `duplicate_of` bukan null (termasuk jika statusnya `CLOSED`) diarahkan ke parent root yang ada, berbeda dari kandidat, berada dalam project sama, tidak berstatus `DUPLICATE`, dan tidak mempunyai `duplicate_of` sendiri. Kandidat tidak valid diabaikan. Deduplikasi dilakukan terhadap `target_issue_id` setelah pemeringkatan.

## Pengujian untuk kebijakan ini

`IssueRecommendationTest` mencakup cosine `-1`, `0`, `1`, non-finite (`1e400`), nilai di luar rentang (`1.5`), tipe invalid (`"NaN"`), duplikat berstatus `CLOSED`, relasi self-parent, nested parent, serta parent dari project berbeda.

**Konfirmasi yang diminta dari Joshua:** setujui tambahan `cosine_similarity` bertanda dalam respons dan wording `similarity` sebagai sinyal positif untuk tampilan, bukan probabilitas; atau tetapkan kebijakan skor alternatif sebelum PR ditandai *Ready for Review*.
