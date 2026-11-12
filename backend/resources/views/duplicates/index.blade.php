@extends(view()->exists('layouts.app') ? 'layouts.app' : 'issues.layout')

@section('title', 'Daftar Kandidat Duplikat — ' . e($project->name))

@section('content')
<div class="di-review-page">
    <div class="di-page-header">
        <div>
            <h1 class="h3 mb-1">Daftar Kandidat Duplikat</h1>
            <p class="text-muted mb-0">Project: <strong>{{ $project->name }}</strong></p>
        </div>
        <div class="di-toolbar-actions">
            <a href="{{ route('projects.show', ['id' => $project->id]) }}" class="btn btn-outline-secondary btn-sm">Kembali ke Project</a>
            <a href="{{ route('issues.index', ['project' => $project]) }}" class="btn btn-outline-primary btn-sm">Daftar Issue</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if (session('status_notice'))
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            {{ session('status_notice') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if ($failedIssues->isNotEmpty())
        <div class="card border-warning mb-4 shadow-sm">
            <div class="card-header bg-warning-subtle text-warning-emphasis d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Issue dengan Deteksi Gagal (FAILED)</span>
                <span class="badge bg-warning text-dark">{{ $failedIssues->count() }} Issue</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#ID</th>
                                <th>Judul Issue</th>
                                <th>Pelapor</th>
                                <th>Status Issue</th>
                                <th>Status Deteksi</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($failedIssues as $failedIssue)
                                <tr>
                                    <td>#{{ $failedIssue->id }}</td>
                                    <td>
                                        <a href="{{ route('issues.show', ['project' => $project, 'issue' => $failedIssue]) }}" class="fw-semibold text-decoration-none">
                                            {{ $failedIssue->title }}
                                        </a>
                                    </td>
                                    <td>{{ $failedIssue->author?->name ?? 'User #' . $failedIssue->author_id }}</td>
                                    <td><span class="badge bg-secondary">{{ $failedIssue->status }}</span></td>
                                    <td><span class="badge bg-danger">{{ $failedIssue->detection_status }}</span></td>
                                    <td class="text-end">
                                        <form action="{{ route('issues.detection.retry', ['project' => $project, 'issue' => $failedIssue]) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Proses ulang deteksi NLP untuk Issue #{{ $failedIssue->id }}?')">
                                                Proses Ulang
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">Daftar Pasangan Kandidat Duplikat</h2>
            <small class="text-muted">Skor kemiripan dihitung otomatis oleh NLP. Keputusan final tetap diverifikasi manual oleh Maintainer.</small>
        </div>
        <div class="card-body p-0">
            @if ($candidates->isEmpty())
                <div class="text-center py-5">
                    <p class="text-muted mb-0">Belum ada kandidat duplikat pada project ini.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 di-table-candidates">
                        <thead class="table-light">
                            <tr>
                                <th>Issue Sumber</th>
                                <th>Kandidat Serupa</th>
                                <th>Skor Kemiripan</th>
                                <th>Deteksi</th>
                                <th>Status Keputusan</th>
                                <th>Riwayat Peninjau</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($candidates as $cand)
                                <tr>
                                    <td>
                                        <a href="{{ route('issues.show', ['project' => $project, 'issue' => $cand->issue]) }}" class="di-issue-link">
                                            <span class="d-block small text-muted">Issue #{{ $cand->issue->id }}</span>
                                            <span class="d-block">{{ $cand->issue->title }}</span>
                                        </a>
                                        <div class="di-issue-meta"><span>Status: {{ $cand->issue->status }}</span><span>Vote: {{ $cand->issue->vote_count }}</span></div>
                                    </td>
                                    <td>
                                        <a href="{{ route('issues.show', ['project' => $project, 'issue' => $cand->candidateIssue]) }}" class="di-issue-link">
                                            <span class="d-block small text-muted">Issue #{{ $cand->candidateIssue->id }}</span>
                                            <span class="d-block">{{ $cand->candidateIssue->title }}</span>
                                        </a>
                                        <div class="di-issue-meta"><span>Status: {{ $cand->candidateIssue->status }}</span><span>Vote: {{ $cand->candidateIssue->vote_count }}</span></div>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-primary">
                                            {{ number_format($cand->similarity_score * 100, 2, ',', '.') }}%
                                        </span>
                                    </td>
                                    <td>
                                        @if ($cand->issue->detection_status === 'COMPLETED')
                                            <span class="badge bg-success">COMPLETED</span>
                                        @elseif ($cand->issue->detection_status === 'FAILED')
                                            <span class="badge bg-danger">FAILED</span>
                                        @else
                                            <span class="badge bg-info text-dark">{{ $cand->issue->detection_status }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($cand->decision === 'DUPLICATE')
                                            <span class="badge bg-danger">DUPLICATE</span>
                                        @elseif ($cand->decision === 'NOT_DUPLICATE')
                                            <span class="badge bg-secondary">NOT_DUPLICATE</span>
                                        @else
                                            <span class="badge bg-warning text-dark">PENDING</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if ($cand->decided_at)
                                            <div>{{ $cand->decidedBy?->name ?? 'User #' . $cand->decided_by }}</div>
                                            <div class="text-muted">{{ $cand->decided_at }}</div>
                                        @else
                                            <span class="text-muted">Belum ditinjau</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('issues.duplicates.compare', ['project' => $project, 'issue' => $cand->issue, 'candidate' => $cand]) }}" class="btn btn-outline-primary btn-sm">
                                            Bandingkan & Review
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

