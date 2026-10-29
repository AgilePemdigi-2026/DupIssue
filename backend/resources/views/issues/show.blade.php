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
        @if (auth()->user()->role === 'REPORTER')
            <a href="{{ route('issues.create', ['project' => $project]) }}" class="btn btn-outline-primary">Buat Issue Lagi</a>
        @endif
        @include('issues.project-link', ['label' => 'Kembali ke Project'])
    </div>
</div>
@endsection
