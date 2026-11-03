<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifikasi OTP - DupIssue</title>
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
            <h2 class="brand-title">Verifikasi OTP</h2>
            <p class="text-muted">Masukkan 6 digit kode yang dikirim ke email <strong>{{ $email }}</strong>. Kode berlaku 5 menit.</p>
        </div>
        @if(session('status')) <div class="alert alert-info" role="status">{{ session('status') }}</div> @endif
        @if($errors->any()) <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div> @endif
        <form action="{{ route('password.otp.verify') }}" method="POST">
            @csrf
            <div class="mb-4">
                <label for="otp" class="form-label">Kode OTP</label>
                <input type="text" id="otp" name="otp" class="form-control text-center fs-4"
                       inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6"
                       autocomplete="one-time-code" placeholder="6 digit kode" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary w-100">Verifikasi OTP</button>
        </form>
        <div class="text-center mt-4">
            <p class="small text-muted mb-2">Belum menerima kode? Tunggu minimal 60 detik sebelum meminta ulang.</p>
            <a href="{{ route('password.request') }}">Kirim Ulang Kode / Ganti Email</a>
        </div>
    </div></div>
</main>
</body>
</html>
