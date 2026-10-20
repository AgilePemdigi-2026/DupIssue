# DupIssue NLP Service

Layanan API sederhana berbasis FastAPI untuk menghitung kemiripan semantik antar-issue menggunakan model Sentence Transformers `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`. Model dijalankan di CPU dan dimuat sekali ketika aplikasi mulai.

> Layanan hanya menghasilkan skor kemiripan. Layanan tidak memutuskan apakah issue berstatus `DUPLICATE`; keputusan tersebut tetap menjadi kewenangan Maintainer.

## Persyaratan

- Python 3.12
- `pip`
- Koneksi internet saat pertama kali model perlu diunduh, kecuali model sudah tersedia di cache lokal

## Instalasi

Dari direktori root proyek, buat dan aktifkan virtual environment. Jika `.venv` sudah tersedia, cukup aktifkan—tidak perlu membuatnya ulang.

```bash
# Hanya jika .venv belum ada
python3.12 -m venv .venv

# macOS / Linux
source .venv/bin/activate

python -m pip install --upgrade pip
python -m pip install -r requirements.txt
```

Folder `.venv` dan cache model lokal tidak dilacak Git sesuai `.gitignore`.

## Menjalankan FastAPI

Pastikan virtual environment aktif, lalu jalankan dari root proyek:

```bash
python -m uvicorn main:app --reload --host 127.0.0.1 --port 8000
```

Saat pertama kali aplikasi dijalankan, Sentence Transformers dapat mengunduh model yang diperlukan. Setelah server siap, buka dokumentasi interaktif Swagger UI di <http://127.0.0.1:8000/docs>. Schema OpenAPI tersedia di <http://127.0.0.1:8000/openapi.json>.

## Endpoint API

Semua respons berformat JSON. `title` dan `description` wajib berupa teks yang tidak kosong.

### `GET /health`

Memeriksa bahwa layanan merespons.

Contoh respons:

```json
{
  "status": "ok",
  "service": "DupIssue NLP Service"
}
```

### `POST /similarity`

Menghitung cosine similarity untuk dua issue. Masing-masing issue memiliki `title` dan `description`.

Contoh request:

```json
{
  "issue1": {
    "title": "Tidak bisa masuk",
    "description": "Login gagal setelah kredensial dimasukkan."
  },
  "issue2": {
    "title": "Masalah saat login",
    "description": "Pengguna tidak dapat masuk setelah memasukkan kata sandi."
  }
}
```

Contoh respons:

```json
{
  "similarity": 0.88
}
```

Nilai similarity adalah angka cosine similarity, bukan keputusan duplikasi.

### `POST /candidates`

Membandingkan issue baru dengan kandidat yang berasal dari project yang sama. Kandidat dari project lain dan kandidat dengan ID yang sama dengan issue baru diabaikan. Hasil diurutkan dari skor tertinggi; layanan tidak menerapkan threshold maupun batas jumlah hasil.

Contoh request:

```json
{
  "project_id": "project-a",
  "new_issue": {
    "issue_id": "issue-100",
    "title": "Aplikasi gagal saat login",
    "description": "Terjadi error setelah pengguna memasukkan kredensial."
  },
  "candidates": [
    {
      "issue_id": "issue-101",
      "project_id": "project-a",
      "title": "Tidak bisa masuk ke aplikasi",
      "description": "Login gagal setelah kredensial dimasukkan."
    },
    {
      "issue_id": "issue-202",
      "project_id": "project-b",
      "title": "Masalah login",
      "description": "Kandidat beda project ini akan diabaikan."
    }
  ]
}
```

Contoh respons:

```json
{
  "candidates": [
    {
      "issue_id": "issue-101",
      "similarity": 0.88
    }
  ]
}
```

Jika tidak ada kandidat yang dapat dibandingkan, responsnya `{"candidates": []}`. ID issue dan project dapat dikirim sebagai string atau integer.

### Validasi dan error

- Request dengan field wajib yang hilang, format tidak sesuai, atau teks `title`/`description` kosong menghasilkan HTTP `422`.
- Jika terjadi kegagalan internal saat menghitung similarity, endpoint mengembalikan HTTP `500` dengan pesan umum; detail teknis dicatat di log server dan tidak dikirim dalam respons.

## Menjalankan unit test

Dari root proyek dengan virtual environment aktif:

```bash
python -m unittest discover -s tests -v
```

Tes endpoint menggunakan data dan model simulasi, sehingga tidak memerlukan koneksi database/Laravel atau unduhan model NLP.

## File yang diabaikan Git

File `.gitignore` mengecualikan virtual environment, cache Python dan model, file model hasil unduhan, data lokal/database, serta file konfigurasi atau kredensial rahasia. Jangan menambahkan token, password, atau dataset sensitif ke repository.
