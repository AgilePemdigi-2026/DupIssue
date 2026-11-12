@extends('layouts.app')

@section('title', 'Daftar Issue — DupIssue')

@section('content')
<div class="card shadow-sm border-0">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3 di-page-header">
            <div>
                <h1 class="h4 fw-bold mb-1">Daftar Issue</h1>
                <p class="text-muted mb-0">Project: {{ $project->name }}</p>
            </div>
            <div class="d-flex flex-wrap gap-2 di-toolbar-actions">
                @if (auth()->user()?->role === 'MAINTAINER' && (int) $project->owner_id === (int) auth()->id())
                    <a class="btn btn-warning btn-sm" href="{{ route('projects.duplicates.index', ['project' => $project]) }}">
                        Review Kandidat Duplikat
                    </a>
                @endif
                @if (auth()->user()->role === 'REPORTER')
                    <a class="btn btn-primary btn-sm" href="{{ route('issues.create', ['project' => $project]) }}">Submit Issue</a>
                @endif
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('projects.show', ['id' => $project->id]) }}">Kembali ke Project</a>
            </div>
        </div>

        <form method="GET" action="{{ route('issues.index', ['project' => $project]) }}" class="border rounded p-3 mb-4 di-filter-panel" role="search">
            <div class="row g-3 align-items-end">
                <div class="col-md-7">
                    <label for="cari" class="form-label">Cari judul atau deskripsi issue</label>
                    <input class="form-control @error('cari') is-invalid @enderror" type="search" id="cari" name="cari"
                           value="{{ $cari }}" maxlength="200" placeholder="Ketik kata kunci" autocomplete="off">
                    @error('cari') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select @error('status') is-invalid @enderror" id="status" name="status">
                        <option value="">Semua status</option>
                        @foreach ($allowedStatuses as $choice)
                            <option value="{{ $choice }}" @selected($status === $choice)>{{ $choice === 'IN_PROGRESS' ? 'In Progress' : ucfirst(strtolower($choice)) }}</option>
                        @endforeach
                    </select>
                    @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-2 di-filter-actions">
                    <button class="btn btn-primary" type="submit">Cari</button>
                    <a class="btn btn-outline-secondary" href="{{ route('issues.index', ['project' => $project]) }}">Reset Filter</a>
                </div>
            </div>
        </form>

        @if ($issues->count() === 0)
            <div class="alert alert-light border" role="status">
                <strong>Issue tidak tersedia</strong>
                @if ($cari !== '' || $status !== '')
    <p class="mb-2 small text-muted">Tidak ditemukan hasil yang sesuai filter.</p>
@else
    <p class="mb-2 small text-muted">Belum ada issue pada project ini.</p>
@endif
                <a class="btn btn-outline-primary btn-sm" href="{{ route('issues.index', ['project' => $project]) }}">Reset Filter</a>
            </div>
        @else
            <div class="table-responsive" id="issue_list">
                <table class="table table-hover align-middle di-table-issues">
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Judul Issue</th>
                            <th scope="col">Status</th>
                            <th scope="col">Sumber</th>
                            <th scope="col">Vote</th>
                            <th scope="col">Deteksi NLP</th>
                            <th scope="col">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($issues as $issue)
                            <tr>
                                <td>#{{ $issue->id }}</td>
                                <td>
                                    @if ($issue->duplicate_of !== null)
                                        <s class="text-muted">{{ $issue->title }}</s>
                                        <div class="small mt-1">
                                            @if ($issue->parentIssue)
                                                Duplikat dari
                                                <a href="{{ route('issues.show', ['project' => $project, 'issue' => $issue->parentIssue]) }}">#{{ $issue->parentIssue->id }}</a>
                                            @else
                                                <span class="text-warning">Issue induk tidak tersedia</span>
                                            @endif
                                        </div>
                                    @else
                                        {{ $issue->title }}
                                        @if ($issue->duplicate_count > 0)
                                            <div class="mt-1">
                                                <span class="badge text-bg-info">Master Cluster</span>
                                                <small class="text-muted">{{ $issue->duplicate_count }} duplikat terhubung</small>
                                            </div>
                                        @endif
                                    @endif
                                </td>
                                <td><span class="badge bg-light text-dark border">{{ $issue->status }}</span></td>
                                <td>{{ ['FORM' => 'Manual', 'CSV' => 'CSV', 'GITHUB' => 'GitHub'][$issue->source_type] ?? $issue->source_type }}</td>
                                <td>{{ (int) $issue->vote_count }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $issue->detection_status }}</span></td>
                                <td>
                                    <a class="btn btn-outline-primary btn-sm" href="{{ route('issues.show', ['project' => $project, 'issue' => $issue]) }}">
                                        Lihat Detail
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $issues->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection
