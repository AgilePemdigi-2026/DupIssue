<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - DupIssue</title>

    <!-- Bootstrap 5.3 -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background-color: #f4f6f9;
            min-height: 100vh;
        }

        .register-card {
            width: 100%;
            max-width: 470px;
            border: none;
            border-radius: 14px;
        }

        .brand-title {
            font-weight: 700;
            color: #2563eb;
        }

        .btn-primary {
            background-color: #2563eb;
            border-color: #2563eb;
        }

        .btn-primary:hover {
            background-color: #1d4ed8;
            border-color: #1d4ed8;
        }
    </style>
</head>

<body>
    <div class="container min-vh-100 d-flex align-items-center justify-content-center py-5">

        <div class="card register-card shadow-sm">
            <div class="card-body p-4 p-md-5">

                <!-- Header -->
                <div class="text-center mb-4">
                    <h2 class="brand-title">DupIssue</h2>
                    <p class="text-muted mb-0">
                        Buat akun untuk mulai melaporkan issue
                    </p>
                </div>

                <!-- Pesan berhasil -->
                @if (session('success'))
                    <div class="alert alert-success" role="alert">
                        {{ session('success') }}
                    </div>
                @endif

                <!-- Form Register -->
                <form method="POST" action="{{ route('register.store') }}">
                    @csrf

                    <!-- Nama Lengkap -->
                    <div class="mb-3">
                        <label for="name" class="form-label">
                            Nama Lengkap
                        </label>

                        <input
                            type="text"
                            name="name"
                            id="name"
                            class="form-control @error('name') is-invalid @enderror"
                            value="{{ old('name') }}"
                            placeholder="Masukkan nama lengkap"
                            maxlength="100"
                            autocomplete="name"
                            required
                        >

                        @error('name')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <!-- Email -->
                    <div class="mb-3">
                        <label for="email" class="form-label">
                            Email
                        </label>

                        <input
                            type="email"
                            name="email"
                            id="email"
                            class="form-control @error('email') is-invalid @enderror"
                            value="{{ old('email') }}"
                            placeholder="nama@email.com"
                            maxlength="255"
                            autocomplete="email"
                            required
                        >

                        @error('email')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <!-- Password -->
                    <div class="mb-3">
                        <label for="password" class="form-label">
                            Password
                        </label>

                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-control @error('password') is-invalid @enderror"
                            placeholder="Minimal 8 karakter"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >

                        @error('password')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <!-- Konfirmasi Password -->
                    <div class="mb-4">
                        <label for="password_confirmation" class="form-label">
                            Konfirmasi Password
                        </label>

                        <input
                            type="password"
                            name="password_confirmation"
                            id="password_confirmation"
                            class="form-control"
                            placeholder="Ulangi password"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <!-- Tombol Register -->
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            Daftar Akun
                        </button>
                    </div>
                </form>

                <div class="text-center mt-4">
                    <small class="text-muted">
                        Sudah memiliki akun?
                        <a href="{{ route('login') }}">
                            Masuk sekarang
                        </a>
                    </small>
                </div>

            </div>
        </div>

    </div>
</body>
</html>
