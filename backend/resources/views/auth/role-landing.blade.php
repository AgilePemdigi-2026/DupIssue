<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - DupIssue</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">

    <div class="container py-5">
        <div class="card shadow-sm border-0">
            <div class="card-body p-5">

                <h2 class="text-primary mb-3">
                    DupIssue
                </h2>

                <h4>
                    Selamat datang, {{ auth()->user()->name }}!
                </h4>

                <p class="text-muted">
                    Role Anda:
                    <strong>{{ $role }}</strong>
                </p>

                @if ($role === 'REPORTER')
                    <div class="alert alert-info">
                        Halaman Daftar Project dan Undangan
                        Reporter akan diintegrasikan dengan
                        modul Project & Membership Reza.
                    </div>
                @else
                    <div class="alert alert-info">
                        Dashboard Maintainer akan
                        dikembangkan dan diintegrasikan
                        dengan fitur pengelolaan project.
                    </div>
                @endif

                <p class="text-secondary">
                    Autentikasi dan pemeriksaan role
                    berhasil dilakukan.
                </p>

            </div>
        </div>
    </div>

</body>
</html>
