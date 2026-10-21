@extends('layouts.app')

@section('title', 'Pengaturan Project - ' . $project->name)

@section('content')
<div class="row justify-content-center">
    <div class="col-md-9 col-lg-8">
        <div class="d-flex align-items-center mb-4">
            <a href="/projects/{{ $project->id }}" class="btn btn-outline-secondary btn-sm me-3">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div>
                <h3 class="h4 fw-bold mb-0">Pengaturan Project</h3>
                <small class="text-muted">Kelola informasi dan konfigurasi project.</small>
            </div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger error mb-4" id="error">
                <div class="fw-semibold mb-1">Terjadi kesalahan pada data formulir:</div>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $errorMsg)
                        <li>{{ $errorMsg }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Card Informasi Proyek Saat Ini -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <span class="text-muted small text-uppercase fw-bold">Informasi Umum Proyek</span>
                        <h4 class="h5 fw-bold text-dark mt-1 mb-0">{{ $project->name }}</h4>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#editProjectCard" aria-expanded="true">
                        <i class="bi bi-pencil-square me-1"></i> Edit Project
                    </button>
                </div>
                <p class="text-secondary small mb-3">
                    {{ $project->description ?: 'Belum ada deskripsi yang ditambahkan.' }}
                </p>
                <div class="d-flex gap-4 border-top pt-3 text-muted small">
                    <div><i class="bi bi-person me-1"></i> Pemilik: <strong>{{ $project->owner->name ?? 'Maintainer' }}</strong></div>
                    <div><i class="bi bi-calendar3 me-1"></i> Dibuat: {{ $project->created_at->format('d M Y') }}</div>
                </div>
            </div>
        </div>

        <!-- Form Edit Project -->
        <div class="collapse show" id="editProjectCard">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="card-title fw-bold h6 mb-0 text-primary">
                        <i class="bi bi-pencil-fill me-1"></i> Formulir Ubah Informasi Project
                    </h5>
                </div>
                <div class="card-body p-4 pt-0">
                    <form action="/projects/{{ $project->id }}/settings" method="POST">
                        @csrf
                        <div class="mb-3">
                            <label for="name" class="form-label fw-semibold">Nama Project</label>
                            <input type="text"
                                   name="name"
                                   id="name"
                                   class="form-control @error('name') is-invalid @enderror"
                                   placeholder="Nama Project"
                                   value="{{ old('name', $project->name) }}">
                            @error('name')
                                <div class="error text-danger mt-1 small" id="error">
                                    <span class="error">{{ $message }} (Nama Project)</span>
                                </div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="description" class="form-label fw-semibold">Deskripsi</label>
                            <textarea name="description"
                                      id="description"
                                      rows="4"
                                      class="form-control @error('description') is-invalid @enderror"
                                      placeholder="Deskripsi">{{ old('description', $project->description) }}</textarea>
                            @error('description')
                                <div class="error text-danger mt-1 small" id="error">
                                    <span class="error">{{ $message }}</span>
                                </div>
                            @enderror
                            <div class="form-text">Maksimal 1.000 karakter.</div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="/projects/{{ $project->id }}" class="btn btn-outline-secondary">Batal</a>
                            <button type="submit" class="btn btn-primary px-4">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
