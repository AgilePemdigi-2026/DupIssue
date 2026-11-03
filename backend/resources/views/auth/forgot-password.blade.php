<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lupa Password - DupIssue</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/dupissue-logo.svg') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; min-height:100vh; }
        .auth-card { width:100%; max-width:450px; border:0; border-radius:14px; }
        .brand-title { color:#6f42c1; font-weight:700; }
        .btn-primary { background:#6f42c1; border-color:#6f42c1; }
        .btn-primary:hover { background:#59339d; border-color:#59339d; }
        .form-control:focus { border-color:#a78bfa; box-shadow:0 0 0 .2rem rgba(111,66,193,.16); }
    </style>
</head>
<body>
<main class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="card auth-card shadow-sm"><div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
            <img src="{{ asset('images/dupissue-logo.svg') }}" alt="Logo DupIssue" width="64" height="64" class="mb-3">
            <h2 class="brand-title">Lupa Password?</h2>
            <p class="text-muted">Masukkan email akun. Kami akan mengirim kode verifikasi OTP 6 digit.</p>
        </div>
        @if($errors->any()) <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div> @endif
        <form action="{{ route('password.otp.send') }}" method="POST">
            @csrf
            <div class="mb-4">
                <label for="email" class="form-label">Email Akun</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control" autocomplete="email" maxlength="255" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Kirim Kode OTP</button>
        </form>
        <div class="text-center mt-4"><a href="{{ route('login') }}">Kembali ke Login</a></div>
    </div></div>
</main>
</body>
</html>
