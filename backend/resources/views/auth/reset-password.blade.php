<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Reset Password - DupIssue</title>
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/dupissue-polish.css') }}?v=20261112">
</head>
<body class="di-auth">
<main class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="card auth-card shadow-sm"><div class="card-body p-4 p-md-5">
        <div class="text-center mb-4">
            <img src="{{ asset('images/dupissue-logo.svg') }}" alt="Logo DupIssue" width="64" height="64" class="mb-3">
            <h2 class="brand-title">Password Baru</h2>
            <p class="text-muted">Kode OTP sudah diverifikasi. Buat password baru untuk akun Anda.</p>
        </div>
        @if($errors->any()) <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div> @endif
        <form action="{{ route('password.update') }}" method="POST">
            @csrf
            <div class="mb-3">
                <label for="password" class="form-label">Password Baru</label>
                <input type="password" id="password" name="password" class="form-control" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="mb-4">
                <label for="password_confirmation" class="form-label">Konfirmasi Password Baru</label>
                <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" minlength="8" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Simpan Password Baru</button>
        </form>
    </div></div>
</main>
</body>
</html>
