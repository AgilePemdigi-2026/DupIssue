<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#191735">
    <meta name="description" content="DupIssue membantu tim melaporkan issue, menemukan kandidat laporan serupa, dan melakukan triase dengan lebih terarah.">
    <title>DupIssue — More clarity. Less duplication.</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/dupissue-logo.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/dupissue-landing.css') }}">
</head>
<body>
    <header class="site-header">
        <div class="nav-container">
            <a class="brand" href="{{ route('home') }}" aria-label="DupIssue — Beranda">
                <img src="{{ asset('images/dupissue-logo.svg') }}" alt="" width="39" height="39">
                <span>DupIssue</span>
            </a>
            <nav class="primary-nav" aria-label="Navigasi utama">
                <a class="nav-home active" href="{{ route('home') }}">Beranda</a>
                <a href="{{ route('about') }}">Tentang</a>
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

    <main>
        <section id="beranda" class="hero hero-intro" aria-labelledby="hero-title">
            <div class="hero-copy">
                <p class="eyebrow">WORKSPACE UNTUK ISSUE TRACKING</p>
                <h1 id="hero-title">More clarity.<br><span>Less duplication.</span></h1>
                <p class="hero-lead">
                    Satu ruang kerja untuk melaporkan issue, menemukan kandidat laporan serupa, dan
                    membantu Maintainer melakukan triase dengan lebih terarah.
                </p>
                <div class="hero-actions">
                    @auth
                        <a class="btn-main" href="{{ auth()->user()->role === 'MAINTAINER' ? route('maintainer.dashboard') : route('reporter.projects.index') }}">Daftar</a>
                        <a class="btn-quiet" href="{{ auth()->user()->role === 'MAINTAINER' ? route('maintainer.dashboard') : route('reporter.projects.index') }}">Masuk</a>
                    @else
                        <a class="btn-main" href="{{ route('register') }}">Daftar</a>
                        <a class="btn-quiet" href="{{ route('login') }}">Masuk</a>
                    @endauth
                </div>
            </div>
        </section>

    </main>
</body>
</html>
