<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Connect Browser Extension - DupIssue</title>
    <link rel="icon" type="image/svg+xml"
          href="{{ asset('images/dupissue-logo.svg') }}">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f5f4fa;
            color: #211b30;
            font-family: system-ui, -apple-system, sans-serif;
            line-height: 1.6;
        }
        .container {
            width: min(100% - 32px, 640px);
            margin: 40px auto;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #6f42c1;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
        }
        .card {
            background: white;
            border: 1px solid #e7e2ef;
            border-radius: 16px;
            padding: clamp(22px, 5vw, 36px);
            box-shadow: 0 6px 24px rgba(40, 24, 70, .06);
        }
        h1 { font-size: 25px; line-height: 1.3; }
        h2 { font-size: 18px; }
        .muted { color: #655e70; }
        li { margin-bottom: 10px; }
        button {
            display: block;
            width: 100%;
            border: 0;
            border-radius: 9px;
            padding: 13px;
            background: #6f42c1;
            color: white;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { background: #59339d; }
        button:focus-visible, a:focus-visible,
        textarea:focus-visible {
            outline: 3px solid #9575cd;
            outline-offset: 3px;
        }
        .notice {
            padding: 14px;
            background: #f3edfd;
            border-radius: 9px;
            font-size: 14px;
            margin-top: 20px;
        }
        textarea {
            width: 100%;
            min-height: 110px;
            padding: 12px;
            margin-top: 8px;
            border: 1px solid #aaa0bb;
            border-radius: 8px;
            font-family: ui-monospace, monospace;
            font-size: 14px;
            overflow-wrap: anywhere;
            resize: vertical;
        }
        .back {
            display: block;
            text-align: center;
            margin-top: 22px;
            color: #6f42c1;
        }
    </style>
</head>
<body>
    <main class="container">
        <div class="brand">
            <img src="{{ asset('images/dupissue-logo.svg') }}"
                 width="40" height="40" alt="">
            DupIssue
        </div>

        <section class="card" aria-labelledby="page-title">
            <h1 id="page-title">Connect Browser Extension</h1>

            <p class="muted">
                Hubungkan akun Reporter DupIssue dengan browser extension
                untuk memperoleh rekomendasi issue serupa langsung dari GitHub.
            </p>

            <ol>
                <li>Buka DupIssue Extension pada Chrome atau Edge.</li>
                <li>Buat pairing code melalui halaman ini.</li>
                <li>Salin kode ke browser extension untuk menghubungkan akun.</li>
            </ol>

            @if ($pairingCode !== null)
                <section aria-labelledby="pairing-code-heading">
                    <h2 id="pairing-code-heading">Pairing code Anda</h2>

                    <label for="extension-pairing-code">
                        Salin seluruh kode berikut:
                    </label>

                    <textarea id="extension-pairing-code"
                              readonly
                              spellcheck="false"
                              autocomplete="off"
                              autocapitalize="off">{{ $pairingCode }}</textarea>

                    <div class="notice" role="status">
                        Kode berlaku selama {{ $expiresInMinutes }} menit
                        sejak dibuat dan hanya dapat digunakan satu kali.
                        Jangan membagikan kode kepada orang lain.
                    </div>

                    <a class="back"
                       href="{{ route('extension.connect', [], false) }}">
                        Kembali ke halaman pairing
                    </a>
                </section>
            @else
                <form method="POST"
                      action="{{ route('extension.pairing.create', [], false) }}">
                    @csrf

                    <button type="submit">Buat Pairing Code</button>
                </form>

                <div class="notice">
                    Kode berlaku selama {{ $expiresInMinutes }} menit
                    dan hanya dapat digunakan satu kali.
                    Tidak diperlukan GitHub Personal Access Token.
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="sessions-title">
            <h2 id="sessions-title">Sesi extension Anda</h2>
            <p>Cabut sesi yang hilang atau tidak dikenali. Maksimal 100 sesi terbaru ditampilkan.</p>
            @forelse ($extensionSessions as $extensionSession)
                <form method="POST" action="{{ route('extension.sessions.revoke', $extensionSession->id, false) }}">
                    @csrf
                    @method('DELETE')
                    <p>Sesi #{{ $extensionSession->id }} — dibuat {{ $extensionSession->created_at }}.
                        {{ $extensionSession->paired_at ? 'Sudah dipasangkan' : 'Menunggu pairing' }}.
                        @if ($extensionSession->access_expires_at) Berakhir {{ $extensionSession->access_expires_at }}. @endif
                    </p>
                    <button type="submit">Cabut sesi #{{ $extensionSession->id }}</button>
                </form>
            @empty
                <p>Tidak ada sesi yang dapat dicabut.</p>
            @endforelse
        </section>

        <a class="back"
           href="{{ route('projects.index', [], false) }}">
            Kembali ke Daftar Project
        </a>
    </main>
</body>
</html>
