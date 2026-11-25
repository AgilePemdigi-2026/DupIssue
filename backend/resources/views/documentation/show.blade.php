<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $current['title'] }} — Dokumentasi DupIssue</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/dupissue-logo.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/dupissue-landing.css') }}?v=20261125">
    <link rel="stylesheet" href="{{ asset('css/dupissue-documentation.css') }}?v=20261125">
</head>
<body>
<header class="site-header docs-header">
    <div class="nav-container docs-nav-container">
        <button class="mobile-menu-button" type="button" aria-controls="documentation-sidebar" aria-expanded="false">
            <span aria-hidden="true">☰</span><span>Menu</span>
        </button>
        <a class="brand" href="{{ route('home') }}" aria-label="DupIssue — Beranda">
            <img src="{{ asset('images/dupissue-logo.svg') }}" alt="" width="39" height="39">
            <span>DupIssue</span>
        </a>
        <nav class="primary-nav docs-primary-nav" aria-label="Navigasi utama">
            <a href="{{ route('home') }}">Beranda</a>
            <a href="{{ route('about') }}">Tentang</a>
            <a class="nav-home active" href="{{ route('documentation') }}">Dokumentasi</a>
            @auth
                <a class="nav-login" href="{{ auth()->user()->role === 'MAINTAINER' ? route('maintainer.dashboard') : route('reporter.projects.index') }}">Dashboard</a>
            @else
                <a class="nav-login" href="{{ route('login') }}">Masuk</a>
                <a class="nav-register" href="{{ route('register') }}">Daftar</a>
            @endauth
        </nav>
    </div>
</header>

<div class="docs-shell">
    <aside id="documentation-sidebar" class="docs-sidebar" aria-label="Navigasi dokumentasi">
        <div class="sidebar-search">
            <label for="docs-search">Cari dokumentasi</label>
            <div class="search-control">
                <span aria-hidden="true">⌕</span>
                <input id="docs-search" type="search" placeholder="Search topics..." autocomplete="off">
            </div>
            <div id="search-results" class="search-results" hidden></div>
        </div>

        @php
            $groups = [
                'GETTING STARTED' => ['introduction', 'getting-started'],
                'USER GUIDES' => [
                    'account-and-user-roles',
                    'project-and-member-management',
                    'submit-an-issue',
                    'track-issue-status',
                    'review-and-validate-duplicates',
                    'import-issues',
                    'dashboard-and-activity-logs',
                ],
                'HELP' => ['frequently-asked-questions', 'troubleshooting'],
            ];
        @endphp
        @foreach ($groups as $groupName => $topicKeys)
            <section class="sidebar-group">
                <button class="group-toggle" type="button" aria-expanded="true">
                    <span>{{ $groupName }}</span><span class="group-arrow" aria-hidden="true">⌃</span>
                </button>
                <div class="group-links">
                    @foreach ($topicKeys as $topicKey)
                        <a class="{{ $topic === $topicKey ? 'active' : '' }}"
                           href="{{ route('documentation', ['topic' => $topicKey]) }}">
                            {{ $topics[$topicKey]['title'] }}
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </aside>
    <button class="sidebar-backdrop" type="button" aria-label="Tutup menu dokumentasi"></button>

    <main class="docs-main">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="{{ route('documentation') }}">Docs</a>
            <span aria-hidden="true">/</span>
            <span>{{ $current['category'] }}</span>
            <span aria-hidden="true">/</span>
            <strong>{{ $current['title'] }}</strong>
        </nav>

        <div class="article-layout">
            <article class="article">
                <header class="article-header">
                    <span class="article-category">{{ $current['category'] }}</span>
                    <h1>{{ $current['title'] }}</h1>
                    <p>{{ $current['subtitle'] }}</p>
                    <small>Estimasi waktu baca: 4–8 menit</small>
                </header>

                @if ($topic === 'introduction')
                    <section id="tentang"><h2>1. Apa Itu DupIssue?</h2><p>DupIssue adalah aplikasi web untuk membantu tim pengembangan mengelola laporan bug dan issue serta mengidentifikasi kemungkinan duplikasi berdasarkan kemiripan makna teks. Sistem menggunakan NLP Semantic Similarity untuk membandingkan judul dan deskripsi, lalu menampilkan kandidat laporan yang relevan.</p></section>
                    <section id="permasalahan"><h2>2. Permasalahan yang Diselesaikan</h2><p>Permasalahan serupa dapat dilaporkan berkali-kali dengan judul atau deskripsi yang berbeda. Akibatnya, tim dapat membuang waktu memeriksa laporan berulang, kehilangan konteks, atau menangani pekerjaan yang seharusnya dapat dipusatkan.</p></section>
                    <section id="tujuan"><h2>3. Tujuan Penggunaan</h2><ul><li>Menemukan issue serupa sebelum laporan baru dibuat.</li><li>Mengurangi laporan duplikat yang tidak diperlukan.</li><li>Memberi Maintainer konteks untuk memutuskan hubungan duplikat.</li><li>Membantu pemantauan status dan penanganan issue.</li></ul></section>
                    <section id="cara-kerja-nlp"><h2>4. Cara Kerja NLP Semantic Similarity</h2><ol><li>Judul dan deskripsi issue diproses menjadi representasi teks semantik.</li><li>Sistem menghitung kemiripan menggunakan cosine similarity terhadap laporan lain dalam project yang sama.</li><li>Kandidat yang sesuai ditampilkan sebagai rekomendasi atau pasangan yang perlu ditinjau.</li><li>Maintainer menilai kandidat tersebut. NLP tidak menetapkan keputusan duplikat secara otomatis.</li></ol></section>
                    <section id="role"><h2>5. Reporter dan Maintainer</h2><table><thead><tr><th>Reporter</th><th>Maintainer</th></tr></thead><tbody><tr><td>Anggota aktif project yang dapat membuat issue, melihat rekomendasi, memberikan vote, dan memantau status.</td><td>Pemilik project yang mengelola project, anggota, sumber issue, kandidat duplikat, status, statistik, dan aktivitas.</td></tr></tbody></table></section>
                    <section id="alur"><h2>6. Gambaran Alur Kerja</h2><p>Maintainer membuat project dan mengundang Reporter. Reporter menerima undangan, membuka project, mengisi laporan, memeriksa rekomendasi, dan mengirim issue baru atau memberikan vote pada issue yang sudah ada. Sistem menjalankan deteksi kandidat. Maintainer meninjau kandidat, menetapkan atau menolak relasi duplikat, lalu mengelola status issue.</p></section>
                    <aside class="callout"><strong>Penting</strong><p>Duplikasi adalah keputusan manusia. Rekomendasi NLP hanya membantu proses triase.</p></aside>

                @elseif ($topic === 'getting-started')
                    <section id="buka-aplikasi"><h2>1. Membuka Website DupIssue</h2><p>Buka alamat website DupIssue pada browser. Halaman utama menyediakan navigasi <strong>Masuk</strong> dan <strong>Daftar</strong>. Pengguna yang sudah login dapat diarahkan ke dashboard sesuai role.</p></section>
                    <section id="registrasi"><h2>2. Membuat Akun</h2><ol><li>Pilih <strong>Daftar</strong>.</li><li>Isi nama, email, password, dan data lain yang diminta formulir.</li><li>Pilih peran sesuai akun yang ingin dibuat jika pilihan tersebut tersedia pada formulir.</li><li>Kirim formulir registrasi dan tunggu validasi dari sistem.</li></ol><p>Sistem harus memvalidasi data dan menolak data yang tidak memenuhi ketentuan aplikasi.</p></section>
                    <section id="login"><h2>3. Login</h2><ol><li>Buka halaman <strong>Masuk</strong>.</li><li>Masukkan email dan password.</li><li>Kirim formulir.</li><li>Jika berhasil, sistem mengarahkan Maintainer ke dashboard Maintainer atau Reporter ke daftar project dan undangan.</li></ol></section>
                    <section id="lupa-password"><h2>4. Lupa Password</h2><p>Jika lupa password, gunakan tautan <strong>Lupa Password</strong> pada halaman login. Ikuti proses OTP yang tersedia: kirim permintaan reset, verifikasi OTP, lalu buat password baru sesuai validasi aplikasi.</p></section>
                    <section id="dashboard"><h2>5. Mengenal Dashboard Setelah Login</h2><p>Maintainer melihat project miliknya dan dapat membuka dashboard statistik per project. Reporter melihat project aktif yang diikuti serta undangan masuk yang belum diproses.</p></section>
                    <section id="project-keanggotaan"><h2>6. Project dan Keanggotaan</h2><p>Project milik Maintainer memiliki pemilik dan anggota Reporter. Reporter hanya dapat mengakses project yang mengirimkan undangan dan keanggotaannya berstatus aktif.</p></section>
                    <section id="langkah-reporter"><h2>7. Langkah Awal Reporter</h2><ol><li>Login atau daftar akun.</li><li>Terima undangan project.</li><li>Pastikan keanggotaan aktif.</li><li>Buka project dan daftar issue.</li><li>Gunakan Submit Issue untuk membuat laporan baru.</li></ol></section>
                    <section id="langkah-maintainer"><h2>8. Langkah Awal Maintainer</h2><ol><li>Login sebagai Maintainer.</li><li>Buka dashboard Maintainer.</li><li>Buat project dan lengkapi informasi project.</li><li>Undang Reporter.</li><li>Kelola anggota dan buka sumber issue bila diperlukan.</li></ol></section>

                @elseif ($topic === 'account-and-user-roles')
                    <section id="reporter"><h2>1. Role Reporter</h2><ul><li>Mengakses project yang memiliki keanggotaan aktif.</li><li>Membuat issue pada project tersebut.</li><li>Melihat rekomendasi issue serupa sebelum submit.</li><li>Memberikan satu vote aktif pada issue yang mengalami masalah sama.</li><li>Membuka detail issue, status, histori, dan relasi duplikat yang diizinkan.</li></ul></section>
                    <section id="maintainer"><h2>2. Role Maintainer</h2><ul><li>Membuat dan mengubah project miliknya.</li><li>Mengundang serta mengelola Reporter.</li><li>Impor issue dari CSV atau GitHub.</li><li>Membandingkan dan memvalidasi kandidat duplikat.</li><li>Mengubah status penanganan issue sesuai aturan transisi.</li><li>Membuka dashboard statistik project dan histori issue yang tersedia.</li></ul></section>
                    <section id="hak-akses"><h2>3. Perbedaan Hak Akses</h2><table><thead><tr><th>Tindakan</th><th>Reporter</th><th>Maintainer</th></tr></thead><tbody><tr><td>Vote issue</td><td>Ya</td><td>Tidak memiliki tindakan voting sebagai alur utama</td></tr><tr><td>Buat project</td><td>Tidak</td><td>Ya</td></tr><tr><td>Kelola anggota</td><td>Tidak</td><td>Ya</td></tr><tr><td>Validasi duplikat</td><td>Tidak</td><td>Ya, pada project miliknya</td></tr><tr><td>Ubah status</td><td>Tidak</td><td>Ya, sesuai aturan status</td></tr></tbody></table></section>
                    <aside class="callout"><strong>Keanggotaan aktif</strong><p>Anggota aktif menentukan akses Reporter ke project. Undangan yang belum diterima tidak memberi hak akses project.</p></aside>

                @elseif ($topic === 'project-and-member-management')
                    <section id="buat-project"><h2>1. Membuat Project</h2><ol><li>Login sebagai Maintainer.</li><li>Buka daftar project atau dashboard Maintainer.</li><li>Pilih <strong>Buat Project</strong>.</li><li>Masukkan nama dan deskripsi project.</li><li>Simpan. User yang login menjadi pemilik project.</li></ol></section>
                    <section id="lihat-ubah"><h2>2. Melihat dan Mengubah Project</h2><p>Buka detail project dari daftar project. Hanya Maintainer pemilik yang dapat membuka pengaturan dan mengubah nama atau deskripsi project.</p></section>
                    <section id="undang"><h2>3. Mengundang Reporter</h2><ol><li>Buka pengaturan anggota project.</li><li>Masukkan email Reporter.</li><li>Kirim undangan.</li><li>Undangan awal berstatus PENDING sampai Reporter menanggapinya.</li></ol></section>
                    <section id="kelola-undangan"><h2>4. Mengelola Undangan</h2><p>Maintainer dapat melihat daftar undangan pada halaman anggota. Undangan PENDING dapat dibatalkan jika fitur pembatalan tersedia pada implementasi interface.</p></section>
                    <section id="hapus-anggota"><h2>5. Menghapus Anggota</h2><p>Maintainer pemilik dapat menghapus anggota aktif dari project melalui halaman anggota. Setelah dihapus, Reporter tidak lagi memperoleh akses berbasis keanggotaan aktif.</p></section>
                    <section id="membership"><h2>6. Keanggotaan Aktif</h2><p>Keanggotaan aktif diperlukan untuk submit dan melihat project sebagai Reporter. Undangan, keanggotaan pending, atau project milik Maintainer lain tidak cukup untuk membuka data project secara otomatis.</p></section>

                @elseif ($topic === 'submit-an-issue')
                    <section id="buka-submit"><h2>1. Membuka Project dan Submit Issue</h2><ol><li>Login sebagai Reporter.</li><li>Terima undangan dan pastikan keanggotaan aktif.</li><li>Buka project.</li><li>Pilih <strong>Submit Issue</strong>.</li></ol></section>
                    <section id="isi-laporan"><h2>2. Mengisi Judul dan Deskripsi</h2><p>Masukkan judul yang jelas dan deskripsi yang menjelaskan permasalahan. Judul dan deskripsi menjalani validasi backend. Panjang atau format yang tidak memenuhi aturan akan ditolak.</p></section>
                    <section id="rekomendasi"><h2>3. Memeriksa Rekomendasi</h2><p>Setelah teks dimasukkan, sistem dapat menampilkan rekomendasi issue serupa. Rekomendasi diambil dari project yang sama dan mendukung pertimbangan Reporter. Rekomendasi bukan keputusan bahwa issue sudah pasti duplikat.</p></section>
                    <section id="buka-rekomendasi"><h2>4. Membuka Detail Rekomendasi</h2><p>Reporter dapat membuka detail issue kandidat untuk membandingkan konteks, status, dan informasi yang tersedia sebelum menentukan tindakan berikutnya.</p></section>
                    <section id="vote"><h2>5. Jika Masalah Sudah Ada</h2><ol><li>Batalkan submit laporan baru yang sedang diproses.</li><li>Buka issue yang sudah ada.</li><li>Pilih aksi vote <strong>Saya juga mengalami ini +1</strong> apabila tersedia.</li><li>Satu Reporter hanya memiliki satu vote aktif pada issue tersebut.</li></ol></section>
                    <section id="lanjut"><h2>6. Jika Masalah Berbeda</h2><p>Jika tidak ada issue yang sesuai, lanjutkan pengiriman laporan. Setelah valid, issue disimpan dengan status awal sesuai implementasi aplikasi dan deteksi kandidat dijadwalkan secara asynchronous.</p></section>
                    <section id="hasil"><h2>7. Hasil Setelah Disimpan</h2><p>Issue baru dapat dilihat pada daftar dan detail project. Status deteksi dapat berubah PENDING, PROCESSING, COMPLETED, atau FAILED tanpa mengubah status penanganan issue secara langsung.</p></section>

                @elseif ($topic === 'track-issue-status')
                    <section id="daftar"><h2>1. Membuka Daftar Issue</h2><p>Buka project lalu gunakan menu Daftar Issue. Daftar dapat dicari berdasarkan judul atau deskripsi dan difilter berdasarkan status yang tersedia.</p></section>
                    <section id="detail"><h2>2. Melihat Detail Issue</h2><p>Pilih issue pada daftar untuk membuka detail. Data yang dapat ditampilkan mencakup judul, deskripsi, status, hubungan issue induk/duplikat, vote, kandidat, histori status, atau histori review bila role mengizinkannya.</p></section>
                    <section id="status-issue"><h2>3. Status Penanganan Issue</h2><table><thead><tr><th>Status</th><th>Arti sederhana</th></tr></thead><tbody><tr><td>OPEN</td><td>Issue baru dan belum mulai ditangani.</td></tr><tr><td>IN_PROGRESS</td><td>Penanganan sudah dimulai.</td></tr><tr><td>RESOLVED</td><td>Permasalahan dianggap telah ditangani.</td></tr><tr><td>CLOSED</td><td>Issue ditutup, termasuk duplikat yang ditutup otomatis.</td></tr><tr><td>DUPLICATE</td><td>Issue ditandai sebagai duplikat melalui proses validasi Maintainer.</td></tr></tbody></table></section>
                    <section id="status-detection"><h2>4. Status Deteksi NLP</h2><table><thead><tr><th>Status deteksi</th><th>Arti sederhana</th></tr></thead><tbody><tr><td>PENDING</td><td>Deteksi menunggu pemrosesan.</td></tr><tr><td>PROCESSING</td><td>Pekerjaan deteksi sedang berjalan.</td></tr><tr><td>COMPLETED</td><td>Deteksi selesai dan kandidat dapat tersedia.</td></tr><tr><td>FAILED</td><td>Deteksi tidak berhasil; issue tetap dapat tersimpan.</td></tr></tbody></table></section>
                    <aside class="callout"><strong>Bedakan kedua jenis status</strong><p><code>OPEN</code> sampai <code>DUPLICATE</code> adalah status penanganan issue. <code>PENDING</code> sampai <code>FAILED</code> adalah status pekerjaan deteksi kandidat.</p></aside>

                @elseif ($topic === 'review-and-validate-duplicates')
                    <section id="candidate-list"><h2>1. Membuka Kandidat Duplikat</h2><p>Login sebagai Maintainer pemilik project lalu buka halaman Kandidat Duplikat. Daftar difokuskan pada project yang sedang dikelola dan berasal dari pasangan kandidat yang tersimpan.</p></section>
                    <section id="compare"><h2>2. Membandingkan Issue</h2><p>Buka perbandingan issue untuk melihat kedua issue berdampingan, skor kemiripan bila tersedia, status, vote, dan konsekuensi relasi sebelum memutuskan.</p></section>
                    <section id="set-parent"><h2>3. Menetapkan Relasi Duplikat</h2><ol><li>Pilih issue yang akan menjadi issue induk.</li><li>Konfirmasi keputusan sebagai duplikat.</li><li>Sistem menyimpan satu relasi <code>duplicate_of</code> pada issue anak.</li><li>Status issue anak akan menjadi DUPLICATE atau CLOSED sesuai status induk.</li></ol></section>
                    <section id="reject"><h2>4. Menolak Kandidat</h2><p>Pilih <strong>Bukan Duplikat</strong> bila pair dinilai tidak memiliki masalah yang sama. Keputusan dicatat dan kandidat tidak otomatis ditetapkan sebagai relasi duplikat.</p></section>
                    <section id="correction"><h2>5. Mengoreksi Penandaan</h2><p>Penandaan DUPLICATE yang sudah dikonfirmasi dapat dikoreksi atau dibatalkan melalui fungsi koreksi. Alasan koreksi wajib dicatat sesuai interface. Periksa dampaknya pada relasi dan status issue sebelum konfirmasi.</p></section>
                    <section id="rules"><h2>6. Aturan Relasi</h2><ul><li>Issue induk dan anak harus berbeda serta berada dalam project yang sama.</li><li>Issue induk tidak boleh merupakan issue duplikat atau sudah memiliki induk.</li><li>Issue anak tidak boleh sudah memiliki induk.</li><li>Relasi tidak boleh membentuk siklus.</li><li>Decision ditentukan Maintainer, bukan NLP.</li></ul></section>

                @elseif ($topic === 'import-issues')
                    <section id="buka-sumber"><h2>1. Membuka Sumber Issue</h2><p>Login sebagai Maintainer pemilik project lalu buka halaman <strong>Sumber Issue</strong> pada project. Hanya Maintainer pemilik yang dapat mengakses fitur ini.</p></section>
                    <section id="csv-format"><h2>2. Format CSV yang Didukung</h2><p>Implementasi saat ini menerima CSV UTF-8 dengan maksimal 5 MB dan maksimal 5.000 baris. Kolom wajib yang diperiksa adalah:</p><table><thead><tr><th>Kolom</th><th>Ketentuan</th></tr></thead><tbody><tr><td><code>source_id</code></td><td>Wajib, maksimal 100 karakter, unik dalam file dan project untuk sumber CSV.</td></tr><tr><td><code>title</code></td><td>Wajib, minimal 5 dan maksimal 200 karakter.</td></tr><tr><td><code>description</code></td><td>Wajib, minimal 10 dan maksimal 10.000 karakter.</td></tr></tbody></table></section>
                    <section id="csv-import"><h2>3. Menjalankan Import CSV</h2><ol><li>Pilih tab Upload Dataset.</li><li>Pilih file dengan ekstensi CSV.</li><li>Klik Validasi File untuk membuat preview.</li><li>Tinjau baris VALID, INVALID, atau ALREADY_IMPORTED.</li><li>Konfirmasi impor jika preview sudah sesuai.</li></ol></section>
                    <section id="github"><h2>4. Menghubungkan GitHub</h2><p>Pada tab GitHub, aplikasi mendukung integrasi GitHub App/OAuth maupun mekanisme PAT untuk project. Maintainer pemilik memilih atau menyimpan repository yang diverifikasi, lalu memeriksa koneksi. PAT tidak boleh dibagikan atau ditempel pada dokumentasi.</p></section>
                    <section id="github-preview"><h2>5. Preview dan Import GitHub</h2><ol><li>Pilih repository GitHub yang terverifikasi.</li><li>Minta preview issue dari repository.</li><li>Pull Request dilewati dan tidak diimpor sebagai issue.</li><li>Tinjau status VALID, INVALID, atau ALREADY_IMPORTED.</li><li>Konfirmasi import hanya untuk baris yang valid.</li></ol></section>
                    <section id="import-errors"><h2>6. Hasil dan Kesalahan Import</h2><p>Jumlah baris valid, invalid, dan sudah diimpor ditampilkan pada preview. Import tidak boleh dianggap berhasil jika koneksi gagal, preview kedaluwarsa, file melampaui batas, atau ada data yang tidak valid.</p></section>

                @elseif ($topic === 'dashboard-and-activity-logs')
                    <section id="dashboard"><h2>1. Fungsi Dashboard</h2><p>Maintainer dapat membuka dashboard khusus pada project miliknya. Dashboard menampilkan data berdasarkan project yang sedang dibuka, bukan gabungan seluruh project.</p></section>
                    <section id="dashboard-metrics"><h2>2. Ringkasan Statistik yang Tersedia</h2><table><thead><tr><th>Informasi</th><th>Arti</th></tr></thead><tbody><tr><td>Total dan status issue</td><td>Jumlah total serta jumlah OPEN, IN_PROGRESS, RESOLVED, dan CLOSED.</td></tr><tr><td>Issue duplikat</td><td>Jumlah issue dengan relasi duplicate_of dan persentasenya.</td></tr><tr><td>Kandidat pending</td><td>Jumlah pasangan kandidat dengan keputusan PENDING.</td></tr><tr><td>Issue vote tertinggi</td><td>Maksimal lima issue non-duplikat dengan vote terbanyak.</td></tr><tr><td>Waktu triase</td><td>Rata-rata waktu dari created_at issue sampai keputusan triase valid yang tercatat, atau Belum ada data bila tidak tersedia.</td></tr></tbody></table></section>
                    <section id="activity-logs"><h2>3. Activity Logs</h2><p>Pencatatan aktivitas tersedia pada database aplikasi untuk aksi penting seperti perubahan status dan keputusan duplikat. Pada halaman detail issue, Maintainer pemilik dapat melihat histori review dan histori status yang terkait issue tersebut. Saat ini tidak ada klaim bahwa seluruh log global memiliki halaman navigasi terpisah bila route tersebut belum tersedia.</p></section>
                    <section id="cross-project"><h2>4. Pembatasan Data Project</h2><p>Dashboard hanya dapat diakses Maintainer pemilik project. Data statistik, vote, kandidat, dan histori harus berasal dari project yang terbuka; project lain tidak boleh muncul.</p></section>

                @elseif ($topic === 'frequently-asked-questions')
                    <section id="general"><h2>General Questions</h2>
                        <div class="faq-list">
                            <details open><summary>Apa itu DupIssue?</summary><p>DupIssue membantu mengelola issue dan menemukan kandidat laporan serupa berbasis NLP Semantic Similarity.</p></details>
                            <details><summary>Apa perbedaan Reporter dan Maintainer?</summary><p>Reporter melaporkan issue dan memberi vote pada project aktif. Maintainer mengelola project, anggota, sumber, validasi duplikat, status, dashboard, dan histori.</p></details>
                            <details><summary>Apakah rekomendasi otomatis menetapkan duplikat?</summary><p>Tidak. NLP hanya menampilkan kandidat; Maintainer melakukan validasi akhir.</p></details>
                        </div>
                    </section>
                    <section id="project-access"><h2>Account &amp; Project</h2>
                        <div class="faq-list">
                            <details><summary>Kenapa saya tidak bisa membuka project?</summary><p>Reporter memerlukan undangan yang diterima dan keanggotaan aktif. Maintainer hanya dapat mengakses project miliknya untuk tindakan khusus kepemilikan.</p></details>
                            <details><summary>Siapa yang dapat memvalidasi duplikat?</summary><p>Maintainer pemilik project.</p></details>
                        </div>
                    </section>
                    <section id="issue-vote"><h2>Issue dan Vote</h2>
                        <div class="faq-list">
                            <details><summary>Bagaimana cara memberikan vote?</summary><p>Buka issue yang sama dan pilih tindakan Saya juga mengalami ini +1 jika Anda masih dapat voting pada issue tersebut. Sistem mencegah vote ganda dari user yang sama.</p></details>
                            <details><summary>Apa perbedaan OPEN dan PENDING?</summary><p>OPEN adalah status penanganan issue. PENDING adalah status deteksi kandidat yang menunggu pemrosesan.</p></details>
                        </div>
                    </section>
                    <section id="import-faq"><h2>Import dan Deteksi</h2>
                        <div class="faq-list">
                            <details><summary>Bagaimana cara mengimpor issue?</summary><p>Maintainer membuka Sumber Issue pada project lalu memilih tab CSV atau GitHub, menjalankan preview, dan mengonfirmasi import.</p></details>
                            <details><summary>Apa yang dilakukan jika deteksi gagal?</summary><p>Status deteksi menjadi FAILED. Issue yang sudah tersimpan tidak otomatis dibatalkan; gunakan proses ulang deteksi bila tersedia pada halaman kandidat.</p></details>
                        </div>
                    </section>

                @else
                    <section id="login"><h2>1. Gagal Login</h2><p>Periksa kembali email dan password, pastikan akun sudah terdaftar, dan gunakan proses lupa password jika password tidak diketahui. Pesan login tidak akan membocorkan bagian mana yang salah.</p></section>
                    <section id="project"><h2>2. Tidak Dapat Mengakses Project</h2><p>Pastikan undangan Reporter sudah diterima dan keanggotaan aktif. Maintainer yang bukan pemilik tidak dapat menggunakan aksi kepemilikan pada project lain.</p></section>
                    <section id="submit"><h2>3. Gagal Mengirim Issue</h2><p>Periksa judul dan deskripsi wajib, batas panjang, keanggotaan aktif, dan pesan validasi pada formulir. Jika NLP gagal, issue valid dapat tetap diproses menurut perilaku aplikasi; jangan ulangi submit bila issue sudah tersimpan.</p></section>
                    <section id="recommendation"><h2>4. Rekomendasi Tidak Muncul</h2><p>Kandidat dapat tidak tersedia jika data belum diproses, layanan NLP gagal, atau tidak ditemukan kemiripan. Biarkan proses laporan tetap sesuai aturan aplikasi dan periksa kembali status deteksi.</p></section>
                    <section id="detection"><h2>5. Deteksi Gagal</h2><p>Status FAILED menunjukkan deteksi tidak selesai. Gunakan fitur proses ulang pada kandidat jika tersedia dan pastikan issue serta layanan NLP dapat diproses kembali.</p></section>
                    <section id="vote"><h2>6. Gagal Memberikan Vote</h2><p>Pastikan Anda aktif sebagai Reporter pada project dan belum memberikan vote yang sama. Refresh data issue karena tombol dapat ditolak jika status atau data issue telah berubah.</p></section>
                    <section id="validate"><h2>7. Gagal Memvalidasi Duplikat</h2><p>Pastikan Anda Maintainer pemilik, kedua issue satu project, data kandidat belum berubah, dan pasangan memenuhi aturan induk/anak. Tombol koreksi memerlukan alasan yang valid.</p></section>
                    <section id="import"><h2>8. Gagal Import CSV atau GitHub</h2><p>Untuk CSV, periksa format UTF-8, ukuran 5 MB, maksimum 5.000 baris, dan kolom source_id, title, description. Untuk GitHub, periksa nama repository, koneksi PAT atau GitHub App, hak akses repository, serta preview yang masih berlaku.</p></section>
                @endif

                @include('documentation.partials.article-nav', ['previousTopic' => $previousTopic, 'nextTopic' => $nextTopic])
            </article>

            <aside class="on-this-page" aria-label="On This Page">
                <strong>On This Page</strong>
                <div id="page-headings"></div>
            </aside>
        </div>
    </main>
</div>

<script>
@php
    $searchTopics = [];
    foreach ($topics as $topicKey => $topicItem) {
        $searchTopics[$topicKey] = [
            'title' => $topicItem['title'],
            'category' => $topicItem['category'],
            'search' => $topicItem['search'],
            'url' => route('documentation', ['topic' => $topicKey]),
        ];
    }
@endphp
window.DupIssueDocumentationTopics = {!! json_encode($searchTopics, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
</script>
<script src="{{ asset('js/dupissue-documentation.js') }}?v=20261125" defer></script>
</body>
</html>
