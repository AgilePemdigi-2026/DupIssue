@extends('layouts.app')

@section('title', 'Detail Issue — DupIssue')

@section('content')
<div class="card shadow-sm">
    <div class="card-body">
        @if (session('success'))
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
        <a href="{{ route('projects.show', ['id' => $project->id]) }}" class="btn btn-outline-secondary">Kembali ke Project</a>
    </div>
</div>
@endsection
