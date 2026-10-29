<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - DupIssue</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/dupissue-logo.svg') }}">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background-color: #f4f6f9;
            min-height: 100vh;
        }

        .login-card {
            width: 100%;
            max-width: 450px;
            border: none;
            border-radius: 14px;
        }

        .brand-title {
            font-weight: 700;
            color: #6f42c1;
        }

        .form-control:focus {
            border-color: #a78bfa;
            box-shadow: 0 0 0 0.2rem rgba(111, 66, 193, 0.16);
        }

        .btn-primary {
            background-color: #6f42c1;
            border-color: #6f42c1;
        }

        .btn-primary:hover {
            background-color: #59339d;
            border-color: #59339d;
        }
    </style>
</head>

<body>
    <div class="container min-vh-100 d-flex align-items-center justify-content-center py-5">

        <div class="card login-card shadow-sm">
            <div class="card-body p-4 p-md-5">

                <div class="text-center mb-4">
                    <img src="{{ asset('images/dupissue-logo.svg') }}" alt="" width="64" height="64" class="d-block mx-auto mb-3 rounded-3">
                    <h2 class="brand-title">DupIssue</h2>
                    <p class="text-muted mb-0">
                        Masuk ke akun DupIssue Anda
                    </p>
                </div>

                @if (session('status'))
                    <div class="alert alert-success">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.authenticate') }}">
                    @csrf

                    <!-- Email -->
                    <div class="mb-3">
                        <label for="email" class="form-label">
                            Email
                        </label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control @error('email') is-invalid @enderror"
                            value="{{ old('email') }}"
                            placeholder="nama@email.com"
                            autocomplete="username"
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
                            id="password"
                            name="password"
                            class="form-control @error('password') is-invalid @enderror"
                            placeholder="Masukkan password"
                            autocomplete="current-password"
                            required
                        >

                        @error('password')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <!-- Remember Me -->
                    <div class="form-check mb-4">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="remember"
                            id="remember"
                            value="1"
                        >

                        <label class="form-check-label" for="remember">
                            Ingat saya
                        </label>
                    </div>

                    <!-- Tombol Login -->
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            Masuk
                        </button>
                    </div>
                </form>

                <div class="text-center mt-4">
                    <small class="text-muted">
                        Belum memiliki akun?
                        <a href="{{ route('register') }}">
                            Daftar sekarang
                        </a>
                    </small>
                </div>

            </div>
        </div>
    </div>
</body>
</html>
