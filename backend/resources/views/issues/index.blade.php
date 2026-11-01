@extends('layouts.app')

@section('title', 'Daftar Issue — DupIssue')

@section('content')
<div class="card shadow-sm border-0">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-1">Daftar Issue</h1>
                <p class="text-muted mb-0">Project: {{ $project->name }}</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if (auth()->user()->role === 'REPORTER')
                    <a class="btn btn-primary btn-sm" href="{{ route('issues.create', ['project' => $project]) }}">Submit Issue</a>
                @endif
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('projects.show', ['id' => $project->id]) }}">Kembali ke Project</a>
            </div>
        </div>

        @if ($issues->count() === 0)
            <p class="text-muted mb-0">Belum ada issue pada project ini.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Judul Issue</th>
                            <th scope="col">Status</th>
                            <th scope="col">Deteksi NLP</th>
                            <th scope="col">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($issues as $issue)
                            <tr>
                                <td>#{{ $issue->id }}</td>
                                <td>{{ $issue->title }}</td>
                                <td>{{ $issue->status }}</td>
                                <td>{{ $issue->detection_status }}</td>
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
