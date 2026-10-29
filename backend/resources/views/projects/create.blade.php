@extends('layouts.app')

@section('title', 'Buat Project Baru - DupIssue')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <div class="d-flex align-items-center mb-3">
                    <a href="/projects" class="btn btn-outline-secondary btn-sm me-3">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <h3 class="h4 fw-bold mb-0">Buat Project</h3>
                </div>
                <p class="text-muted small mb-4">
                    Buat workspace baru untuk memantau laporan issue dan mendeteksi duplikasi secara otomatis.
                </p>

                @if ($errors->any())
                    <div class="alert alert-danger error mb-3" id="error">
                        <div class="fw-semibold mb-1">Terjadi kesalahan pada formulir:</div>
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $errorMsg)
                                <li>{{ $errorMsg }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="/projects" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label for="name" class="form-label fw-semibold">Nama Project</label>
                        <input type="text"
                               name="name"
                               id="name"
                               class="form-control @error('name') is-invalid @enderror"
                               placeholder="Nama Project"
                               value="{{ old('name') }}">
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
                                  placeholder="Deskripsi">{{ old('description') }}</textarea>
                        @error('description')
                            <div class="error text-danger mt-1 small" id="error">
                                <span class="error">{{ $message }}</span>
                            </div>
                        @enderror
                        <div class="form-text">Maksimal 1.000 karakter.</div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="/projects" class="btn btn-outline-secondary">Batal</a>
                        <button type="submit" class="btn btn-primary px-4">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
