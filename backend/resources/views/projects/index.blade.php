@extends('layouts.app')

@section('title', 'Daftar Project - DupIssue')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h3 fw-bold mb-1">Daftar Project</h2>
        <p class="text-muted mb-0">Kelola workspace project dan kolaborasi pelaporan issue.</p>
    </div>
    @if(auth()->user() && auth()->user()->role === 'MAINTAINER')
        <a href="/projects/new" class="btn btn-primary px-4 py-2">
            <i class="bi bi-plus-circle me-1"></i> Buat Project
        </a>
    @endif
</div>

@if(isset($incomingInvitationsCount) && $incomingInvitationsCount > 0)
    <div class="alert bg-primary-subtle-custom border border-primary-subtle d-flex justify-content-between align-items-center mb-4">
        <div>
            <i class="bi bi-envelope-paper-fill text-primary me-2"></i>
            <strong>Undangan Baru Masuk!</strong> Anda memiliki {{ $incomingInvitationsCount }} undangan bergabung project dari Maintainer.
        </div>
        <a href="/invitations" class="btn btn-sm btn-outline-primary">Lihat Undangan</a>
    </div>
@endif

<div class="row g-4">
    @forelse($projects as $project)
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h5 class="card-title fw-bold text-dark mb-0">{{ $project->name }}</h5>
                        <span class="badge bg-secondary-subtle text-secondary small">
                            {{ $project->owner_id === auth()->id() ? 'Owner' : 'Anggota' }}
                        </span>
                    </div>
                    <p class="card-text text-muted small flex-grow-1">
                        {{ $project->description ?: 'Tidak ada deskripsi.' }}
                    </p>
                    <div class="border-top pt-3 mt-3 d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="bi bi-person me-1"></i>{{ $project->owner->name ?? 'Maintainer' }}
                        </small>
                        <a href="/projects/{{ $project->id }}" class="btn btn-outline-primary btn-sm">
                            Buka Project <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="card border-0 shadow-sm text-center py-5">
                <div class="card-body">
                    <i class="bi bi-folder2-open display-4 text-muted mb-3 d-block"></i>
                    <h5 class="text-secondary">Belum ada project</h5>
                    <p class="text-muted small">
                        @if(auth()->user() && auth()->user()->role === 'MAINTAINER')
                            Silakan buat project pertama Anda untuk memulai kolaborasi issue.
                        @else
                            Anda belum tergabung dalam project mana pun. Periksa menu Undangan untuk melihat permintaan bergabung.
                        @endif
                    </p>
                    @if(auth()->user() && auth()->user()->role === 'MAINTAINER')
                        <a href="/projects/new" class="btn btn-primary mt-2">
                            <i class="bi bi-plus-circle me-1"></i> Buat Project
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @endforelse
</div>
@endsection
