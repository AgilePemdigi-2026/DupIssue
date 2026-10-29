@extends('layouts.app')

@section('title', $project->name . ' - DupIssue')

@section('content')
<div class="row g-4">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h2 class="h3 fw-bold mb-0">{{ $project->name }}</h2>
                            <span class="badge bg-primary-subtle text-primary">
                                Akses: {{ $project->owner_id === auth()->id() ? 'Maintainer' : 'Reporter' }}
                            </span>
                        </div>
                        <p class="text-muted mb-0">
                            {{ $project->description ?: 'Tidak ada deskripsi untuk project ini.' }}
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        @if($project->owner_id === auth()->id())
                            <a href="/projects/{{ $project->id }}/members" class="btn btn-outline-primary">
                                <i class="bi bi-people me-1"></i> Anggota Project
                            </a>
                            <a href="/projects/{{ $project->id }}/settings" class="btn btn-outline-secondary">
                                <i class="bi bi-gear me-1"></i> Edit Project
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modul Navigasi Utama Project -->
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-primary-subtle text-primary p-2 rounded me-3">
                        <i class="bi bi-people-fill fs-4"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0">Anggota Project</h5>
                        <small class="text-muted">Kelola tim dan undangan kolaborator</small>
                    </div>
                </div>
                <p class="text-muted small">
                    Undang Reporter baru dan atur akses pengguna ke project ini.
                </p>
                <a href="/projects/{{ $project->id }}/members" class="btn btn-primary btn-sm w-100">
                    Buka Anggota Project
                </a>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-primary-subtle text-primary p-2 rounded me-3">
                        <i class="bi bi-bug-fill fs-4"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0">Daftar Issue</h5>
                        <small class="text-muted">Laporan bug dan deteksi duplikasi</small>
                    </div>
                </div>
                <p class="text-muted small">
                    Pantau issue yang dilaporkan atau kirim laporan issue baru.
                </p>
                <button class="btn btn-outline-secondary btn-sm w-100" disabled>
                    Lihat Issue (Fitur REQ-01 & REQ-02)
                </button>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-primary-subtle text-primary p-2 rounded me-3">
                        <i class="bi bi-sliders fs-4"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0">Pengaturan Project</h5>
                        <small class="text-muted">Informasi dan konfigurasi</small>
                    </div>
                </div>
                <p class="text-muted small">
                    Ubah nama dan deskripsi project serta parameter pendukung.
                </p>
                <a href="/projects/{{ $project->id }}/settings" class="btn btn-outline-primary btn-sm w-100">
                    Pengaturan Project
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
