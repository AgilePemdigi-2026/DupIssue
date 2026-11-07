@extends('layouts.app')

@section('title', 'Sumber Issue - ' . $project->name)

@section('content')

<!-- Header Halaman -->
<div class="mb-4">
    <a href="{{ route('projects.show', ['id' => $project->id]) }}"
       class="btn btn-outline-secondary btn-sm mb-3">
        <i class="bi bi-arrow-left me-1"></i>
        Kembali ke Project
    </a>

    <h1 class="h3 fw-bold mb-2">Sumber Issue</h1>

    <p class="text-muted mb-0">
        Project: <strong>{{ $project->name }}</strong>
    </p>

    <p class="text-muted small mt-2">
        Tambahkan issue melalui dataset CSV atau repository GitHub.
        Data akan divalidasi sebelum diimpor.
    </p>
</div>

<!-- Card Utama -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-4">

        <!-- Navigasi Tab -->
        <ul class="nav nav-tabs mb-4"
            id="sourceTabs"
            role="tablist">

            <li class="nav-item" role="presentation">
                <button class="nav-link active"
                        id="csv-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#csv-panel"
                        type="button"
                        role="tab"
                        aria-controls="csv-panel"
                        aria-selected="true">
                    <i class="bi bi-file-earmark-arrow-up me-2"></i>
                    Upload Dataset
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button class="nav-link"
                        id="github-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#github-panel"
                        type="button"
                        role="tab"
                        aria-controls="github-panel"
                        aria-selected="false">
                    <i class="bi bi-github me-2"></i>
                    GitHub
                </button>
            </li>
        </ul>

        <div class="tab-content" id="sourceTabsContent">

            <!-- ======================================= -->
            <!-- TAB 1: UPLOAD DATASET CSV               -->
            <!-- ======================================= -->

            <div class="tab-pane fade show active"
                 id="csv-panel"
                 role="tabpanel"
                 aria-labelledby="csv-tab"
                 tabindex="0">

                <div class="row g-4">

                    <!-- Area Upload CSV -->
                    <div class="col-lg-8">

                        <h2 class="h5 fw-bold mb-2">
                            Upload Dataset CSV
                        </h2>

                        <p class="text-muted small">
                            Pilih file CSV untuk diperiksa sebelum impor.
                        </p>

                        @php
                            $csvUploadReady =
                                \Illuminate\Support\Facades\Route::has(
                                    'sources.csv.preview'
                                );
                        @endphp

                        <!-- Form Upload dan Preview -->
                        <form
                            action="{{ $csvUploadReady
                                ? route('sources.csv.preview', [
                                    'project' => $project->id
                                ])
                                : '#' }}"
                            method="POST"
                            enctype="multipart/form-data"
                        >
                            @csrf

                            <div class="rounded-3 p-4 text-center bg-light"
                                 style="border: 2px dashed #c5b6e2;">

                                <i class="bi bi-cloud-arrow-up text-primary"
                                   style="font-size: 2.5rem;"></i>

                                <p class="fw-semibold mt-3 mb-2">
                                    Upload Dataset CSV
                                </p>

                                <p class="text-muted small mb-3">
                                    Pilih file CSV untuk diperiksa
                                    sebelum impor.
                                </p>

                                <label for="csv-file"
                                       class="form-label fw-semibold">
                                    Pilih File
                                </label>

                                <input
                                    type="file"
                                    id="csv-file"
                                    name="file"
                                    accept=".csv,text/csv"
                                    class="form-control @error('file') is-invalid @enderror"
                                    required
                                    @disabled(!$csvUploadReady)
                                >

                                @error('file')
                                    <div class="invalid-feedback d-block text-start">
                                        {{ $message }}
                                    </div>
                                @enderror

                                <div class="form-text mt-2">
                                    CSV UTF-8, maksimal 5 MB
                                    dan 5.000 baris data.
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mt-3">

                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                    @disabled(!$csvUploadReady)
                                >
                                    <i class="bi bi-check-circle me-1"></i>
                                    Validasi File
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    disabled
                                >
                                    <i class="bi bi-download me-1"></i>
                                    Unduh Template CSV
                                </button>

                            </div>
                        </form>

                        @if(!$csvUploadReady)
                            <div class="alert alert-secondary small mt-3 mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                Upload melalui browser belum diaktifkan.
                                Endpoint CSV masih menunggu integrasi route aplikasi.
                            </div>
                        @endif

                    </div>

                    <!-- Ketentuan Format CSV -->
                    <div class="col-lg-4">

                        <div class="bg-light rounded-3 p-4 h-100">

                            <h2 class="h6 fw-bold mb-3">
                                <i class="bi bi-info-circle me-1"></i>
                                Ketentuan Format
                            </h2>

                            <ul class="small mb-0 ps-3">

                                <li class="mb-2">
                                    Format CSV UTF-8
                                </li>

                                <li class="mb-2">
                                    Ukuran maksimal 5 MB
                                </li>

                                <li class="mb-2">
                                    Maksimal 5.000 baris data
                                </li>

                                <li class="mb-2">
                                    Kolom wajib:
                                    <code>source_id</code>,
                                    <code>title</code>,
                                    <code>description</code>
                                </li>

                                <li>
                                    Data yang sudah pernah diimpor
                                    tidak akan disimpan ulang.
                                </li>

                            </ul>

                        </div>
                    </div>
                </div>

                <!-- ======================================= -->
                <!-- PRATINJAU HASIL VALIDASI CSV            -->
                <!-- ======================================= -->

                <div class="border-top mt-4 pt-4">

                    <h2 class="h5 fw-bold mb-3">
                        Pratinjau Hasil Validasi
                    </h2>

                    @if(isset($csvPreview))

                        <!-- Informasi File -->
                        <div class="alert alert-info small mb-3"
                             role="status">

                            <i class="bi bi-info-circle me-1"></i>

                            File
                            <strong>
                                {{ $csvOriginalFileName ?? 'dataset.csv' }}
                            </strong>
                            berhasil diperiksa.

                            Total {{ $csvPreview['total_rows'] }}
                            baris data.

                            Belum ada issue yang disimpan.
                        </div>

                        <!-- Ringkasan Hasil Validasi -->
                        <div class="row g-3 mb-4">

                            <!-- Data Valid -->
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">

                                    <div class="small text-muted mb-1">
                                        Data Valid Baru
                                    </div>

                                    <div class="fs-3 fw-bold text-success">
                                        {{ $csvPreview['valid_count'] }}
                                    </div>

                                    <small class="text-muted">
                                        Siap untuk diimpor
                                    </small>

                                </div>
                            </div>

                            <!-- Data Invalid -->
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">

                                    <div class="small text-muted mb-1">
                                        Data Invalid
                                    </div>

                                    <div class="fs-3 fw-bold text-danger">
                                        {{ $csvPreview['invalid_count'] }}
                                    </div>

                                    <small class="text-muted">
                                        Tidak akan diimpor
                                    </small>

                                </div>
                            </div>

                            <!-- Sudah Diimpor -->
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">

                                    <div class="small text-muted mb-1">
                                        Sudah Diimpor
                                    </div>

                                    <div class="fs-3 fw-bold text-secondary">
                                        {{ $csvPreview['already_imported_count'] }}
                                    </div>

                                    <small class="text-muted">
                                        Tidak akan disimpan ulang
                                    </small>

                                </div>
                            </div>

                        </div>

                        <!-- Tabel Detail Validasi -->
                        <h3 class="h6 fw-bold mb-3">
                            Detail Validasi per Baris
                        </h3>

                        <div class="table-responsive border rounded">

                            <table class="table table-hover align-middle mb-0">

                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Baris</th>
                                        <th scope="col">Source ID</th>
                                        <th scope="col">Judul Issue</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Keterangan</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    @foreach($csvPreview['rows'] as $row)

                                        <tr>

                                            <!-- Nomor Baris -->
                                            <td class="text-muted">
                                                {{ $row['record_number'] }}
                                            </td>

                                            <!-- Source ID -->
                                            <td>
                                                <code>
                                                    {{ data_get($row, 'data.source_id') ?: '—' }}
                                                </code>
                                            </td>

                                            <!-- Judul Issue -->
                                            <td>
                                                {{ data_get($row, 'data.title') ?: '—' }}
                                            </td>

                                            <!-- Status Validasi -->
                                            <td>

                                                @if($row['status'] === 'VALID')

                                                    <span class="badge bg-success">
                                                        Valid
                                                    </span>

                                                @elseif($row['status'] === 'INVALID')

                                                    <span class="badge bg-danger">
                                                        Invalid
                                                    </span>

                                                @elseif($row['status'] === 'ALREADY_IMPORTED')

                                                    <span class="badge bg-secondary">
                                                        Sudah Diimpor
                                                    </span>

                                                @endif

                                            </td>

                                            <!-- Alasan Validasi -->
                                            <td class="small">

                                                @if($row['reason'])

                                                    <span class="text-danger">
                                                        {{ $row['reason'] }}
                                                    </span>

                                                @else

                                                    <span class="text-success">
                                                        Siap diimpor
                                                    </span>

                                                @endif

                                            </td>

                                        </tr>

                                    @endforeach

                                </tbody>
                            </table>

                        </div>

                        <!-- ======================================= -->
                        <!-- KONFIRMASI IMPOR CSV                    -->
                        <!-- ======================================= -->

                        <div class="d-flex flex-wrap justify-content-between
                                    align-items-center gap-3 mt-4">

                            <p class="text-muted small mb-0">
                                Hanya data valid baru yang akan diimpor.
                            </p>

                            @if(
                                \Illuminate\Support\Facades\Route::has(
                                    'sources.csv.import'
                                )
                                && $csvPreview['valid_count'] > 0
                            )

                                <!-- Form Konfirmasi Impor -->
                                <form
                                    action="{{ route('sources.csv.import', [
                                        'project' => $project->id
                                    ]) }}"
                                    method="POST"
                                    class="m-0"
                                >
                                    @csrf

                                    <button
                                        type="submit"
                                        class="btn btn-primary"
                                    >
                                        <i class="bi bi-database-add me-1"></i>
                                        Impor {{ $csvPreview['valid_count'] }}
                                        Baris Valid
                                    </button>
                                </form>

                            @else

                                <!-- Tombol Nonaktif Jika Route Belum Tersedia -->
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    disabled
                                >
                                    <i class="bi bi-database-add me-1"></i>
                                    Impor {{ $csvPreview['valid_count'] }}
                                    Baris Valid
                                </button>

                            @endif

                        </div>

                        @if($csvPreview['valid_count'] === 0)

                            <div class="alert alert-warning mt-3 mb-0">
                                Tidak ada issue baru yang valid
                                untuk diimpor.
                            </div>

                        @endif

                    @else

                        <!-- Pratinjau Sebelum Validasi -->
                        <div class="row g-3 mb-3">

                            <div class="col-md-4">
                                <div class="border rounded p-3">
                                    <div class="small text-muted">
                                        Data Valid Baru
                                    </div>
                                    <div class="fs-4 fw-bold">—</div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="border rounded p-3">
                                    <div class="small text-muted">
                                        Data Invalid
                                    </div>
                                    <div class="fs-4 fw-bold">—</div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="border rounded p-3">
                                    <div class="small text-muted">
                                        Sudah Diimpor
                                    </div>
                                    <div class="fs-4 fw-bold">—</div>
                                </div>
                            </div>

                        </div>

                        <!-- Empty State -->
                        <div class="text-center bg-light rounded p-4">

                            <i class="bi bi-table fs-2 text-muted"></i>

                            <p class="text-muted mb-0 mt-2">
                                Belum ada hasil validasi CSV.
                            </p>

                        </div>

                    @endif

                </div>

            </div>

            <!-- ======================================= -->
            <!-- TAB 2: GITHUB                           -->
            <!-- ======================================= -->

            <div class="tab-pane fade"
                 id="github-panel"
                 role="tabpanel"
                 aria-labelledby="github-tab"
                 tabindex="0">

                <h2 class="h5 fw-bold mb-2">
                    Integrasi Repository GitHub
                </h2>

                <p class="text-muted small mb-4">
                    Hubungkan repository untuk mengambil issue
                    melalui GitHub REST API.
                </p>

                <!-- Repository -->
                <div class="mb-3">

                    <label for="github-repo"
                           class="form-label fw-semibold">
                        Repository
                    </label>

                    <input
                        type="text"
                        id="github-repo"
                        name="repository"
                        class="form-control"
                        placeholder="owner/repository"
                        disabled
                    >

                    <div class="form-text">
                        Contoh: pandas-dev/pandas
                    </div>

                </div>

                <!-- GitHub PAT -->
                <div class="mb-3">

                    <label for="github-pat"
                           class="form-label fw-semibold">
                        Personal Access Token (PAT)
                    </label>

                    <input
                        type="password"
                        id="github-pat"
                        name="pat"
                        class="form-control"
                        autocomplete="off"
                        placeholder="Masukkan token akses GitHub"
                        disabled
                    >

                    <div class="form-text">
                        Token akan diproses secara aman di backend.
                    </div>

                </div>

                <!-- Tombol GitHub -->
                <div class="d-flex flex-wrap gap-2">

                    <button
                        type="button"
                        class="btn btn-outline-primary"
                        disabled
                    >
                        Uji Koneksi
                    </button>

                    <button
                        type="button"
                        class="btn btn-primary"
                        disabled
                    >
                        Simpan Koneksi
                    </button>

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        disabled
                    >
                        Ambil Issue
                    </button>

                </div>

                <div class="alert alert-secondary small mt-4 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Integrasi GitHub akan diaktifkan setelah
                    endpoint dan mekanisme autentikasi selesai dibuat.
                </div>

            </div>

        </div>
    </div>
</div>

<!-- Informasi Status Integrasi -->
<div class="alert alert-info mt-4 small" role="status">
    <i class="bi bi-info-circle me-1"></i>
    Fitur konfirmasi impor CSV dan koneksi GitHub
    masih dalam tahap integrasi.
</div>

@endsection
