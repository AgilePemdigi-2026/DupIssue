@extends('layouts.app')

@section('title', 'Dashboard Statistik - ' . $project->name)

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 di-page-header">
    <div>
        <a href="{{ route('projects.show', ['id' => $project->id]) }}" class="text-decoration-none small">
            Kembali ke Project
        </a>
        <h1 class="h2 fw-bold mt-2 mb-1">Dashboard Statistik</h1>
        <p class="text-muted mb-0">{{ $project->name }}</p>
    </div>
    <nav class="d-flex flex-wrap gap-2 di-toolbar-actions" aria-label="Navigasi project">
        <a href="{{ route('issues.index', ['project' => $project]) }}" class="btn btn-outline-primary btn-sm">
            Daftar Issue
        </a>
        <a href="{{ route('projects.duplicates.index', ['project' => $project]) }}" class="btn btn-outline-warning btn-sm">
            Kandidat Duplikat
        </a>
    </nav>
</div>

@if ($stats['totalIssueCount'] === 0)
    <div class="alert alert-info d-flex align-items-start gap-2" role="status">
        <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
        <div>
            <h2 class="h5 alert-heading mb-1">Belum ada issue pada project ini</h2>
            <p class="mb-3">
                Statistik akan muncul setelah issue tersedia.
                Tambahkan issue melalui dataset CSV atau repository GitHub.
            </p>
            <a href="{{ route('sources.index', ['project' => $project]) }}"
               class="btn btn-primary btn-sm">
                Impor CSV atau Hubungkan GitHub
            </a>
        </div>
    </div>
@endif

<section class="mb-4" aria-labelledby="summary-heading">
    <h2 id="summary-heading" class="h5 fw-bold mb-3">Ringkasan Issue</h2>
    <div class="row g-3">
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="small text-muted mb-2">Total Issue</p>
                    <p class="display-6 fw-bold mb-0">{{ number_format($stats['totalIssueCount']) }}</p>
                </div>
            </div>
        </div>
        @foreach (['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'DUPLICATE'] as $status)
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <p class="mb-2"><span class="badge di-issue-status" data-status="{{ $status }}">{{ str_replace('_', ' ', $status) }}</span></p>
                        <p class="display-6 fw-bold mb-0">{{ number_format($stats['statusCounts'][$status]) }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>

<section class="mb-4" aria-label="Metrik tambahan">
    <div class="row g-3">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="small text-muted mb-2">Issue Duplikat</p>
                    <p class="h3 fw-bold mb-1">{{ number_format($stats['duplicateIssueCount']) }}</p>
                    <p class="small text-muted mb-0">{{ number_format($stats['duplicatePercentage'], 1) }}% dari total</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="small text-muted mb-2">Kandidat Menunggu Validasi</p>
                    <p class="h3 fw-bold mb-0">{{ number_format($stats['pendingDuplicateCount']) }}</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="small text-muted mb-2">Rata-rata Waktu Triase</p>
                    @if ($stats['averageTriageMinutes'] === null)
                        <p class="h3 fw-bold mb-0">Belum ada data</p>
                    @else
                        <p class="h3 fw-bold mb-0">{{ number_format($stats['averageTriageMinutes'], 1, ',', '.') }} menit</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>

<section class="mb-4" aria-labelledby="sources-heading">
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div>
                    <h2 id="sources-heading" class="h5 fw-bold mb-1">Sumber Issue</h2>
                    <p class="small text-muted mb-0">
                        Ringkasan konfigurasi dan riwayat impor project.
                    </p>
                </div>
                <a href="{{ route('sources.index', ['project' => $project]) }}"
                   class="btn btn-outline-primary btn-sm">
                    Buka Sumber Issue
                </a>
            </div>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 fw-bold">
                            <i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>
                            Dataset CSV
                        </h3>
                        <p class="small text-muted mb-1">Impor terakhir</p>
                        <p class="fw-semibold mb-0">
                            {{ $stats['sources']['csvLastImportedAt'] ?? 'Belum pernah mengimpor' }}
                        </p>
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <div class="border rounded p-3 h-100">
                        <h3 class="h6 fw-bold">
                            <i class="bi bi-github me-1" aria-hidden="true"></i>
                            Repository GitHub
                        </h3>

                        @if ($stats['sources']['githubAppRepository'])
                            <p class="small mb-1 text-break">
                                GitHub App: {{ $stats['sources']['githubAppRepository'] }}
                            </p>
                        @endif

                        @if ($stats['sources']['githubPatRepository'])
                            <p class="small mb-1 text-break">
                                PAT: {{ $stats['sources']['githubPatRepository'] }}
                            </p>
                        @endif

                        @if (! $stats['sources']['githubAppRepository'] && ! $stats['sources']['githubPatRepository'])
                            <p class="small text-muted mb-1">
                                Belum ada konfigurasi GitHub tersimpan.
                            </p>
                        @else
                            <p class="small text-muted mb-1">
                                Konfigurasi tersimpan. Status akses tidak diperiksa secara langsung.
                            </p>
                        @endif

                        <p class="small text-muted mb-1">Impor terakhir</p>
                        <p class="fw-semibold mb-0">
                            {{ $stats['sources']['githubLastImportedAt'] ?? 'Belum pernah mengimpor' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="row g-4">
    <section class="col-12 col-lg-6" aria-labelledby="distribution-heading">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-4">
                <h2 id="distribution-heading" class="h5 fw-bold mb-4">Distribusi Status</h2>
                @php
                    $statusColors = [
                        'OPEN' => '#2563eb',
                        'IN_PROGRESS' => '#b45309',
                        'RESOLVED' => '#15803d',
                        'CLOSED' => '#64748b',
                        'DUPLICATE' => '#7e22ce',
                    ];
                @endphp
                @foreach ($stats['statusDistribution'] as $item)
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center small mb-1">
                            <span>{{ str_replace('_', ' ', $item['status']) }}</span>
                            <span class="text-muted">{{ number_format($item['count']) }} ({{ number_format($item['percentage'], 1) }}%)</span>
                        </div>
                        <div
                            class="progress"
                            role="progressbar"
                            aria-label="Distribusi {{ str_replace('_', ' ', $item['status']) }}"
                            aria-valuenow="{{ $item['percentage'] }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            style="height: 0.65rem;"
                        >
                            <div
                                class="progress-bar"
                                style="width: {{ $item['percentage'] }}%; background-color: {{ $statusColors[$item['status']] }};"
                            ></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="col-12 col-lg-6" aria-labelledby="top-votes-heading">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-4">
                <h2 id="top-votes-heading" class="h5 fw-bold mb-3">Issue dengan Vote Terbanyak</h2>
                @if ($stats['topVotedIssues']->isEmpty())
                    <p class="text-muted mb-0">Belum ada issue untuk ditampilkan.</p>
                @else
                    <ol class="list-group list-group-numbered list-group-flush">
                        @foreach ($stats['topVotedIssues'] as $issue)
                            <li class="list-group-item d-flex justify-content-between align-items-start gap-3 px-0">
                                <a
                                    class="text-decoration-none"
                                    href="{{ route('issues.show', ['project' => $project, 'issue' => $issue->id]) }}"
                                >
                                    {{ $issue->title }}
                                </a>
                                <span class="badge text-bg-primary rounded-pill flex-shrink-0">
                                    {{ number_format($issue->vote_count) }} vote
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>
    </section>
</div>
@endsection
