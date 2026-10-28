<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Issue #{{ $issue->id }} - DupIssue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex gap-2">
            <a href="{{ route('issues.index', ['project' => $projectId]) }}" class="btn btn-outline-secondary btn-sm">
                &larr; Daftar Issue
            </a>
            <a href="{{ route('issues.create', ['project' => $projectId]) }}" class="btn btn-outline-primary btn-sm">
                + Submit Issue Baru
            </a>
        </div>
        @include('partials.logout-button')
    </div>

    <!-- Konfirmasi Issue Berhasil Disimpan -->
    @if(session('success'))
        <div class="alert alert-success fw-semibold" role="alert">
            {{ session('success') }}
        </div>
    @endif

    @if(session('vote_success'))
        <div class="alert alert-info fw-semibold" role="alert">
            {{ session('vote_success') }}
        </div>
    @endif

    @if(session('vote_warning'))
        <div class="alert alert-warning fw-semibold" role="alert">
            {{ session('vote_warning') }}
        </div>
    @endif

    <div class="card shadow-sm border-0" id="detail">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <span class="text-muted small">ISS-{{ $issue->id }} &bull; detail issue</span>
                    <h3 class="fw-bold mb-1">{{ $issue->title }}</h3>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <!-- Status Penanganan Issue -->
                    <span class="badge bg-primary" id="status">{{ $issue->status }}</span>
                    <!-- Status Deteksi Dasar NLP -->
                    <span class="badge bg-warning text-dark" id="detection_status">{{ $issue->detection_status }}</span>
                </div>
            </div>

            @if($issue->status === 'DUPLICATE' && $issue->duplicate_of)
                <div class="alert alert-secondary small">
                    Issue ini ditandai sebagai duplikat. Lihat
                    <a href="{{ route('issues.show', ['project' => $projectId, 'issue' => $issue->duplicate_of]) }}" class="fw-bold">
                        Issue Induk
                    </a>.
                </div>
            @endif

            <hr>

            <h6 class="fw-semibold text-muted">Deskripsi Lengkap</h6>
            <p class="mb-4" style="white-space: pre-line;">{{ $issue->description }}</p>

            <!-- Panel Status Deteksi Dasar & Vote (REQ-01.15 & REQ-01.16) -->
            <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded border">
                <div class="small">
                    <div><strong>Status Issue:</strong> {{ $issue->status }}</div>
                    <div><strong>Status Deteksi NLP:</strong> {{ $issue->detection_status }}</div>
                    <div><strong>Jumlah Dukungan Vote:</strong> <span id="vote_count" class="fw-bold">{{ $issue->vote_count }}</span></div>
                </div>

                <div class="d-flex gap-2">
                    <form action="{{ route('issues.vote', ['project' => $projectId, 'issue' => $issue->id]) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm" style="background-color: #5b46f5;">
                            Saya juga mengalami ini +1
                        </button>
                    </form>

                    @if($hasVoted)
                        <form action="{{ route('issues.unvote', ['project' => $projectId, 'issue' => $issue->id]) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                Batalkan Vote
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>