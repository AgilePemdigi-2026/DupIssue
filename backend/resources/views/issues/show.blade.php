@extends(view()->exists('layouts.app') ? 'layouts.app' : 'issues.layout')

@section('title', 'Detail Issue — DupIssue')

@section('content')
<div class="card shadow-sm">
    <div class="card-body">
        @if (session('success') && ! view()->exists('layouts.app'))
            <div class="alert alert-success" role="status">{{ session('success') }}</div>
        @endif
        <h1 class="h4">{{ $issue->title }}</h1>
        <p class="text-muted">Project: {{ $project->name }}</p>
        <p>{{ $issue->description }}</p>
        <p><strong>Status:</strong> {{ $issue->status }}</p>
        <p><strong>Status deteksi NLP:</strong> {{ $issue->detection_status }}</p>
        @if (in_array($issue->detection_status, ['PENDING', 'PROCESSING'], true))
            <div class="alert alert-info" role="status">Deteksi kemiripan sedang diproses. Muat ulang halaman untuk melihat hasilnya.</div>
        @elseif ($issue->detection_status === 'FAILED')
            <div class="alert alert-warning" role="status">Deteksi kemiripan belum berhasil. Laporan issue tetap tersimpan.</div>
        @elseif ($issue->detection_status === 'COMPLETED')
            <h2 class="h5 mt-4">Rekomendasi Issue Serupa</h2>
            @if ($recommendations->isEmpty())
                <p class="text-muted">Belum ada kandidat yang memenuhi batas similarity 75% saat ini.</p>
            @else
                <ul class="list-group mb-3">
                    @foreach ($recommendations as $recommendation)
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-3">
                            <a href="{{ route('issues.show', ['project' => $project, 'issue' => $recommendation->candidateIssue]) }}">
                                #{{ $recommendation->candidate_issue_id }} — {{ $recommendation->candidateIssue->title }}
                            </a>
                            <span class="badge text-bg-primary">
                                {{ number_format($recommendation->similarity_score * 100, 2, ',', '.') }}%
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="text-muted small">Skor ini hanya rekomendasi kemiripan dari NLP. Keputusan duplikat tetap dilakukan Maintainer.</p>
        @endif
        @if (auth()->user()->role === 'REPORTER')
            <a href="{{ route('issues.create', ['project' => $project]) }}" class="btn btn-outline-primary">Buat Issue Lagi</a>
        @endif
        @include('issues.project-link', ['label' => 'Kembali ke Project'])
    </div>
</div>
@endsection
