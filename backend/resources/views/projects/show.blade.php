@extends('layouts.app')

@section('title', $project->name . ' - DupIssue')

@section('content')
@if(auth()->user()?->role === 'REPORTER')
<div class="card shadow-sm border-0">
    <div class="card-body p-4">
        <h1 class="h4 fw-bold mb-2">{{ $project->name }}</h1>
        <p class="text-muted mb-3">{{ $project->description ?: 'Lihat dan laporkan issue untuk project ini.' }}</p>
        <div class="d-flex flex-wrap gap-2 di-actions">
            <a href="{{ route('issues.index', ['project' => $project]) }}" class="btn btn-primary">Lihat Daftar Issue</a>
            <a href="{{ route('issues.create', ['project' => $project]) }}" class="btn btn-outline-primary">Submit Issue</a>
            <a href="{{ route('projects.index') }}" class="btn btn-outline-secondary">Daftar Project</a>
        </div>
    </div>
</div>
@else
<div class="row g-4 di-project-tiles">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 di-page-header mb-0">
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
                <div class="di-card-actions">
                    <a href="/projects/{{ $project->id }}/members" class="btn btn-primary btn-sm w-100">
                        Buka Anggota Project
                    </a>
                </div>
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
                <div class="di-card-actions">
                    @include('issues.submit-link', ['project' => $project])
                    <a href="{{ route('issues.index', ['project' => $project]) }}" class="btn btn-outline-primary btn-sm w-100">
                        Lihat Issue
                    </a>
                    @if((int) $project->owner_id === (int) auth()->id() && auth()->user()?->role === 'MAINTAINER')
                        <a href="{{ route('projects.duplicates.index', ['project' => $project]) }}" class="btn btn-outline-warning btn-sm w-100">
                            Review Kandidat Duplikat
                        </a>
                    @endif
                </div>
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
                <div class="di-card-actions">
                    <a href="/projects/{{ $project->id }}/settings" class="btn btn-outline-primary btn-sm w-100">
                        Pengaturan Project
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigasi Sumber Issue: khusus Maintainer pemilik project -->
    @if((int) $project->owner_id === (int) auth()->id())
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <div class="bg-primary-subtle text-primary p-2 rounded me-3">
                            <i class="bi bi-database-fill-gear fs-4"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0">Sumber Issue</h5>
                            <small class="text-muted">
                                Integrasi CSV dan GitHub
                            </small>
                        </div>
                    </div>

                    <p class="text-muted small">
                        Tambahkan issue melalui dataset CSV atau
                        repository GitHub dengan validasi sebelum impor.
                    </p>

                    <a href="{{ route('sources.index', ['project' => $project->id]) }}"
                       class="btn btn-outline-primary btn-sm w-100">
                        Buka Sumber Issue
                    </a>
                </div>
            </div>
        </div>
    @endif

</div>
@endif
@endsection
