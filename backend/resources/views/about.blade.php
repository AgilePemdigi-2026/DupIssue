<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#191735">
    <meta name="description" content="Pelajari latar belakang, tujuan, teknologi, dan manfaat DupIssue untuk pelaporan serta triase issue.">
    <title>Tentang — DupIssue</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/dupissue-logo.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/dupissue-landing.css') }}?v=20261125">
    <link rel="stylesheet" href="{{ asset('css/dupissue-about.css') }}?v=20261125">
</head>
<body>
    <header class="site-header about-header">
        <div class="nav-container">
            <a class="brand" href="{{ route('home') }}" aria-label="DupIssue — Beranda">
                <img src="{{ asset('images/dupissue-logo.svg') }}" alt="" width="39" height="39">
                <span>DupIssue</span>
            </a>
            <nav class="primary-nav about-primary-nav" aria-label="Navigasi utama">
                <a href="{{ route('home') }}">Beranda</a>
                <a class="nav-home active" href="{{ route('about') }}">Tentang</a>
                <a href="{{ route('documentation', ['topic' => 'introduction']) }}">Dokumentasi</a>
                @auth
                    <a class="nav-login" href="{{ auth()->user()->role === 'MAINTAINER' ? route('maintainer.dashboard') : route('reporter.projects.index') }}">Dashboard</a>
                @else
                    <a class="nav-login" href="{{ route('login') }}">Masuk</a>
                    <a class="nav-register" href="{{ route('register') }}">Daftar</a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="about-main">
        <section class="about-hero" aria-labelledby="about-title">
            <div class="about-container">
                <p class="about-kicker">Tentang DupIssue</p>
                <h1 id="about-title"><span>Apa itu DupIssue?</span></h1>
                <p class="about-lead">
                    DupIssue adalah aplikasi berbasis web untuk membantu tim pengembangan menemukan
                    kemiripan laporan bug atau issue, meninjau kandidat duplikat, dan melakukan triase
                    dengan konteks yang lebih terarah.
                </p>
            </div>
        </section>

        <section class="about-section" aria-labelledby="background-title">
            <div class="about-container about-grid">
                <article>
                    <p class="section-kicker">Latar Belakang</p>
                    <h2 id="background-title">Permasalahan yang berulang dapat memperlambat triase.</h2>
                    <p>
                        Laporan bug dapat ditulis oleh banyak orang dengan judul dan deskripsi yang berbeda.
                        Permasalahan yang sama dapat membuat tim memeriksa laporan berulang, menduplikasi
                        pekerjaan, dan kehilangan konteks penanganan.
                    </p>
                    <p>
                        DupIssue dibangun untuk membantu menghubungkan laporan baru dengan laporan yang
                        sudah ada berdasarkan kemiripan makna, sehingga pemeriksaan dapat dilakukan lebih terarah.
                    </p>
                </article>
                <aside class="about-principle" aria-label="Prinsip DupIssue">
                    <span>01</span>
                    <strong>Rekomendasi, bukan keputusan otomatis.</strong>
                    <p>NLP membantu menampilkan kandidat serupa. Maintainer tetap memutuskan hubungan duplikat.</p>
                </aside>
            </div>
        </section>

        <section class="about-section about-section-soft" aria-labelledby="purpose-title">
            <div class="about-container">
                <p class="section-kicker">Tujuan dan Manfaat</p>
                <h2 id="purpose-title">Membantu tim bekerja dengan informasi yang lebih jelas.</h2>
                <div class="about-purpose-grid">
                    <article>
                        <span>01</span>
                        <h3>Lebih sedikit laporan berulang</h3>
                        <p>Reporter dapat memeriksa kandidat issue yang sudah tersedia sebelum mengirim laporan baru.</p>
                    </article>
                    <article>
                        <span>02</span>
                        <h3>Proses triase lebih terarah</h3>
                        <p>Maintainer mendapatkan pasangan kandidat untuk dibandingkan sebelum menetapkan duplikat.</p>
                    </article>
                    <article>
                        <span>03</span>
                        <h3>Perkembangan lebih mudah dipantau</h3>
                        <p>Status penanganan, validasi duplikat, dan aktivitas project dapat dilihat melalui fitur yang tersedia.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="about-section" aria-labelledby="technology-title">
            <div class="about-container about-grid">
                <article>
                    <p class="section-kicker">Teknologi Secara Singkat</p>
                    <h2 id="technology-title">Dibangun dengan proses yang terstruktur.</h2>
                    <p>
                        DupIssue menggunakan Laravel untuk aplikasi web dan backend, MySQL untuk penyimpanan data,
                        serta Laravel Database Queue untuk pemrosesan deteksi kandidat secara asynchronous.
                    </p>
                    <p>
                        Rekomendasi kemiripan teks diproses oleh layanan FastAPI menggunakan Sentence Transformers,
                        model <code>paraphrase-multilingual-MiniLM-L12-v2</code>, dan cosine similarity.
                    </p>
                    <p>
                        CSV dan issue repository GitHub dapat digunakan sebagai sumber issue sesuai fitur yang tersedia.
                    </p>
                </article>
                <aside class="technology-list" aria-label="Komponen teknologi">
                    <div><span>Web</span><strong>Laravel</strong></div>
                    <div><span>NLP</span><strong>FastAPI</strong></div>
                    <div><span>Model</span><strong>Sentence Transformers</strong></div>
                    <div><span>Database</span><strong>MySQL</strong></div>
                </aside>
            </div>
        </section>

        <section class="about-section about-roles" aria-labelledby="roles-title">
            <div class="about-container">
                <p class="section-kicker">Pengguna Utama</p>
                <h2 id="roles-title">Dua peran, satu alur kerja yang terarah.</h2>
                <div class="role-grid">
                    <article>
                        <span>Reporter</span>
                        <h3>Menyampaikan dan memantau issue</h3>
                        <p>
                            Reporter menjadi anggota aktif project untuk membuat laporan, memeriksa rekomendasi,
                            memberikan vote pada issue yang sudah ada, serta melihat detail dan status issue.
                        </p>
                    </article>
                    <article>
                        <span>Maintainer</span>
                        <h3>Mengelola project dan triase</h3>
                        <p>
                            Maintainer memiliki project, mengelola anggota dan sumber issue, meninjau kandidat
                            duplikat, mengubah status penanganan, serta memantau dashboard dan aktivitas project.
                        </p>
                    </article>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer about-footer">
        <div class="about-container footer-inner">
            <a class="footer-brand" href="{{ route('home') }}">
                <img src="{{ asset('images/dupissue-logo.svg') }}" alt="" width="28" height="28">
                <strong>DupIssue</strong>
            </a>
            <small>More clarity. Less duplication.</small>
        </div>
    </footer>
</body>
</html>
