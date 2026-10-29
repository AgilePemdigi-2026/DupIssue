<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#171533">
    <meta name="description" content="DupIssue membantu tim melaporkan issue, melihat kandidat laporan serupa, dan melakukan triase dengan lebih terarah.">
    <title>DupIssue — More clarity. Less duplication.</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/dupissue-logo.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/dupissue-landing.css') }}">
</head>
<body>
    <div class="site-shell">
        <header class="site-header">
            <div class="site-container nav-container">
                <a class="brand" href="{{ route('home') }}" aria-label="DupIssue — Beranda">
                    <img src="{{ asset('images/dupissue-logo.svg') }}" alt="" width="39" height="39">
                    <span>DupIssue</span>
                </a>
                <nav class="primary-nav" aria-label="Navigasi utama">
                    <a href="#fitur">Fitur</a>
                    <a href="#alur">Cara Kerja</a>
                    @auth
                        <a class="nav-action" href="{{ auth()->user()->role === 'MAINTAINER' ? route('maintainer.dashboard') : route('reporter.projects.index') }}">Dashboard <span aria-hidden="true">↗</span></a>
                    @else
                        <a href="{{ route('login') }}">Masuk</a>
                        <a class="nav-action" href="{{ route('register') }}">Daftar <span aria-hidden="true">↗</span></a>
                    @endauth
                </nav>
            </div>
        </header>

        <main>
            <section class="hero site-container" aria-labelledby="hero-title">
                <div class="hero-copy">
                    <div class="eyebrow"><span class="eyebrow-line"></span> WORKSPACE UNTUK ISSUE TRACKING</div>
                    <h1 id="hero-title">More clarity.<br><span>Less duplication.</span></h1>
                    <p class="hero-lead">Satu ruang kerja untuk melaporkan issue, menemukan kandidat laporan serupa, dan membantu Maintainer melakukan triase dengan lebih terarah.</p>
                    <div class="hero-actions">
                        @auth
                            <a class="btn-main" href="{{ auth()->user()->role === 'MAINTAINER' ? route('maintainer.dashboard') : route('reporter.projects.index') }}">Buka Dashboard <span aria-hidden="true">↗</span></a>
                        @else
                            <a class="btn-main" href="{{ route('register') }}">Mulai Sekarang <span aria-hidden="true">↗</span></a>
                            <a class="btn-quiet" href="{{ route('login') }}">Masuk ke Akun <span aria-hidden="true">→</span></a>
                        @endauth
                    </div>
                    <a class="hero-scroll" href="#fitur">Jelajahi fitur <span aria-hidden="true">↓</span></a>
                </div>

                <div class="hero-visual" aria-label="Ilustrasi alur pelaporan dan peninjauan issue">
                    <div class="preview-topline"><span>WORKFLOW PREVIEW</span><span class="preview-indicator">ILUSTRASI</span></div>
                    <div class="preview-card report-card">
                        <span class="preview-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="5" y="3.5" width="14" height="17" rx="2.5"/><path d="M8.5 8.5h7M8.5 12h7M8.5 15.5h4"/></svg>
                        </span>
                        <div class="preview-card-copy">
                            <div class="preview-meta">01 / REPORTER</div>
                            <strong>Issue dilaporkan</strong>
                            <p>Laporan tercatat di project tim.</p>
                        </div>
                        <span class="preview-state state-received">OPEN</span>
                    </div>
                    <div class="preview-connector" aria-hidden="true"><span></span></div>
                    <div class="preview-card similarity-card">
                        <span class="preview-icon similarity-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="8" height="12" rx="2"/><rect x="13" y="7" width="8" height="12" rx="2"/><path d="m9 9 6 6"/></svg>
                        </span>
                        <div class="preview-card-copy">
                            <div class="preview-meta">02 / NLP SIMILARITY</div>
                            <strong>Kandidat issue serupa</strong>
                            <p>Analisis membantu menemukan laporan terkait.</p>
                            <div class="similarity-bars" aria-hidden="true"><span></span><span></span><span></span></div>
                        </div>
                    </div>
                    <div class="preview-connector" aria-hidden="true"><span></span></div>
                    <div class="preview-card review-card">
                        <span class="preview-icon review-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="m8 12 3 3 5-6"/></svg>
                        </span>
                        <div class="preview-card-copy">
                            <div class="preview-meta">03 / MAINTAINER</div>
                            <strong>Review &amp; keputusan</strong>
                            <p>Maintainer menilai hasil, bukan sistem otomatis.</p>
                        </div>
                        <span class="preview-state state-review">REVIEW</span>
                    </div>
                    <div class="preview-footnote">Report <span aria-hidden="true">→</span> Similarity <span aria-hidden="true">→</span> Review</div>
                </div>
            </section>
            <div class="hero-baseline site-container"><span>BUILT FOR BETTER TRIAGE</span><span>Laravel · FastAPI · NLP</span></div>
        </main>
    </div>

    <section id="fitur" class="section features-section" aria-labelledby="features-title">
        <div class="site-container">
            <div class="section-heading">
                <div><p class="section-kicker">KEMAMPUAN UTAMA</p><h2 id="features-title">Laporan yang jelas.<br>Kolaborasi yang terarah.</h2></div>
                <p>DupIssue menghubungkan proses pelaporan, pengelolaan project, dan pemeriksaan kemiripan dalam satu alur kerja.</p>
            </div>
            <div class="features-grid">
                <article class="feature-card">
                    <div class="feature-number">01 <span>ACCOUNT &amp; ACCESS</span></div>
                    <div class="feature-mark" aria-hidden="true">↗</div>
                    <h3>Login &amp; Hak Akses</h3>
                    <p>Reporter dan Maintainer memiliki akses yang sesuai dengan tanggung jawab masing-masing.</p>
                </article>
                <article class="feature-card">
                    <div class="feature-number">02 <span>ISSUE MANAGEMENT</span></div>
                    <div class="feature-mark" aria-hidden="true">□</div>
                    <h3>Issue Tracking</h3>
                    <p>Kelola project dan laporan issue dalam satu tempat agar konteks pekerjaan tetap terjaga.</p>
                </article>
                <article class="feature-card">
                    <div class="feature-number">03 <span>SEMANTIC MATCHING</span></div>
                    <div class="feature-mark" aria-hidden="true">≈</div>
                    <h3>NLP Similarity</h3>
                    <p>Temukan kandidat laporan yang memiliki makna serupa sebagai referensi bagi proses triase.</p>
                </article>
            </div>
            <p class="feature-note">Fitur DupIssue dikembangkan bertahap mengikuti sprint. Ilustrasi pada halaman ini menunjukkan alur yang dituju, bukan hasil NLP langsung.</p>
        </div>
    </section>

    <section id="alur" class="section workflow-section" aria-labelledby="workflow-title">
        <div class="site-container workflow-layout">
            <div class="workflow-intro">
                <p class="section-kicker">CARA KERJA</p>
                <h2 id="workflow-title">Dari laporan<br>hingga keputusan.</h2>
                <p>Setiap langkah dirancang untuk membantu tim bekerja dengan informasi yang lebih baik. Penetapan duplikasi tetap berada pada Maintainer.</p>
            </div>
            <ol class="steps">
                <li><span class="step-index">01</span><div><h3>Masuk ke Akun</h3><p>Pengguna mengakses workspace sesuai role Reporter atau Maintainer.</p></div></li>
                <li><span class="step-index">02</span><div><h3>Laporkan Issue</h3><p>Reporter menambahkan judul, deskripsi, dan informasi issue pada project.</p></div></li>
                <li><span class="step-index">03</span><div><h3>Analisis NLP</h3><p>Sistem mengusulkan kandidat laporan dengan kemiripan semantik.</p></div></li>
                <li><span class="step-index">04</span><div><h3>Review Maintainer</h3><p>Maintainer meninjau kandidat dan memutuskan apakah issue merupakan duplikat.</p></div></li>
            </ol>
        </div>
    </section>

    <section class="closing-section" aria-labelledby="closing-title">
        <div class="site-container closing-panel">
            <div>
                <p class="section-kicker">START WITH CLARITY</p>
                <h2 id="closing-title">Siap bekerja lebih terarah?</h2>
                <p>Masuk ke workspace DupIssue atau buat akun untuk mulai berkolaborasi.</p>
            </div>
            <div class="closing-actions">
                @auth
                    <a class="btn-main" href="{{ auth()->user()->role === 'MAINTAINER' ? route('maintainer.dashboard') : route('reporter.projects.index') }}">Buka Dashboard <span aria-hidden="true">↗</span></a>
                @else
                    <a class="btn-main" href="{{ route('register') }}">Daftar Sekarang <span aria-hidden="true">↗</span></a>
                    <a class="btn-quiet" href="{{ route('login') }}">Sudah punya akun? Masuk <span aria-hidden="true">→</span></a>
                @endauth
            </div>
        </div>
    </section>

    <footer class="site-footer">
        <div class="site-container footer-inner">
            <a class="footer-brand" href="{{ route('home') }}"><img src="{{ asset('images/dupissue-logo.svg') }}" alt="" width="28" height="28"><strong>DupIssue</strong></a>
            <span>More clarity. Less duplication.</span>
            <small>© 2026 DupIssue · PPL Praktikum</small>
        </div>
    </footer>
</body>
</html>
