<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'DupIssue - Sistem Deteksi Duplikasi Issue')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/dupissue-logo.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/dupissue-brand.css') }}">
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bs-primary: #6f42c1;
            --bs-primary-rgb: 111, 66, 193;
        }
        .btn-primary {
            background-color: #6f42c1;
            border-color: #6f42c1;
        }
        .btn-primary:hover {
            background-color: #59339d;
            border-color: #59339d;
        }
        .bg-primary-subtle-custom {
            background-color: #f3f0fb;
        }
        .navbar-brand {
            font-weight: 700;
            color: #6f42c1 !important;
        }
    </style>
</head>
<body class="di-app bg-light min-vh-100 d-flex flex-column">
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="/projects">
                <img src="{{ asset('images/dupissue-logo.svg') }}" alt="" width="38" height="38" class="rounded-3 flex-shrink-0">
                <span>DupIssue</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/projects">Daftar Project</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/invitations">Undangan</a>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-3">
                    @auth
                        <span class="badge bg-secondary">
                            {{ auth()->user()->role ?? 'User' }}: {{ auth()->user()->name ?? auth()->user()->email }}
                        </span>
                    @endauth
                </div>
            </div>
        </div>
    </nav>

    <main class="container py-4 flex-grow-1">
        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show error" id="error" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="bg-white border-top py-3 text-center text-muted small mt-auto">
        <div class="container">
            &copy; 2026 DupIssue — Sistem Deteksi Duplikasi Bug/Issue Report Berbasis NLP Semantic Similarity
        </div>
    </footer>

    <!-- Bootstrap 5.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @yield('scripts')
</body>
</html>
