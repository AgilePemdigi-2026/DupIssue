@extends('layouts.app')

@section('title', 'Sumber Issue - ' . $project->name)

@section('content')

@php
    // Buka tab GitHub ketika OAuth baru selesai
    // atau koneksi GitHub App sudah tersedia.
    $githubAppTabActive =
        empty($csvPreview)
        && (
            !empty($githubAppVerifiedRepositories)
            || !empty($githubAppRepoFullName)
        );
@endphp

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
                <button class="{{ $githubAppTabActive ? 'nav-link' : 'nav-link active' }}"
                        id="csv-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#csv-panel"
                        type="button"
                        role="tab"
                        aria-controls="csv-panel"
                        aria-selected="{{ $githubAppTabActive ? 'false' : 'true' }}">
                    <i class="bi bi-file-earmark-arrow-up me-2"></i>
                    Upload Dataset
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button class="{{ $githubAppTabActive ? 'nav-link active' : 'nav-link' }}"
                        id="github-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#github-panel"
                        type="button"
                        role="tab"
                        aria-controls="github-panel"
                        aria-selected="{{ $githubAppTabActive ? 'true' : 'false' }}">
                    <i class="bi bi-github me-2"></i>
                    GitHub
                </button>
            </li>
        </ul>

        <div class="tab-content" id="sourceTabsContent">

            <!-- ======================================= -->
            <!-- TAB 1: UPLOAD DATASET CSV               -->
            <!-- ======================================= -->

            <div class="{{ $githubAppTabActive ? 'tab-pane fade' : 'tab-pane fade show active' }}"
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

            <div class="{{ $githubAppTabActive ? 'tab-pane fade show active' : 'tab-pane fade' }}"
                 id="github-panel"
                 data-connected-repo="{{ $githubRepoFullName ?? '' }}"
                 data-test-url="{{ route('sources.github.test', ['project' => $project->id]) }}"
                 data-save-url="{{ route('sources.github.save', ['project' => $project->id]) }}"
                 data-preview-url="{{ route('sources.github.preview', ['project' => $project->id]) }}"
                 data-import-url="{{ route('sources.github.import', ['project' => $project->id]) }}"
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

                <!-- Integrasi GitHub App tanpa PAT -->
                <section id="github-app-section"
                         class="border rounded-3 p-4 mb-4"
                         aria-labelledby="github-app-heading">

                    <h3 id="github-app-heading"
                        class="h5 fw-bold mb-2">
                        Hubungkan dengan GitHub App
                    </h3>

                    <p class="text-muted small">
                        Otorisasi melalui GitHub tanpa memasukkan
                        Personal Access Token (PAT) secara manual.
                    </p>

                    @if(!empty($githubAppRepoFullName))
                        <div class="alert alert-success small"
                             role="status">
                            <strong>Koneksi tersimpan:</strong>
                            {{ $githubAppRepoFullName }}
                        </div>
                    @else
                        <div class="alert alert-secondary small"
                             role="status">
                            Belum ada koneksi GitHub App
                            untuk project ini.
                        </div>
                    @endif

                    @if(!empty($githubAppVerifiedRepositories))
                        <div class="border rounded p-3 mb-3">
                            <h4 class="h6 fw-semibold mb-2">
                                Pilih Repository
                            </h4>

                            <p class="text-muted small">
                                Repository berikut telah diperiksa
                                melalui otorisasi GitHub.
                                Pilih satu repository untuk project ini.
                            </p>

                            <form method="POST"
                                  action="{{ route('sources.github.app.save', [
                                      'project' => $project->id
                                  ]) }}">
                                @csrf

                                <label for="github-app-repository"
                                       class="form-label">
                                    Repository GitHub
                                </label>

                                <select id="github-app-repository"
                                        name="repository_id"
                                        class="form-select mb-3"
                                        required>
                                    @foreach($githubAppVerifiedRepositories as $repository)
                                        <option value="{{ $repository['id'] }}">
                                            {{ $repository['full_name'] }}
                                        </option>
                                    @endforeach
                                </select>

                                @if(!empty($githubAppRepoFullName))
                                    <div class="alert alert-warning small">
                                        Koneksi yang aktif:
                                        <strong>{{ $githubAppRepoFullName }}</strong>.
                                        Repository lama tetap tersimpan sebagai
                                        asal issue historis. Perubahan koneksi
                                        berlaku untuk pengambilan issue berikutnya.
                                    </div>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input"
                                               type="checkbox" value="1"
                                               name="confirm_replace"
                                               id="githubAppConfirmReplace" required>
                                        <label class="form-check-label"
                                               for="githubAppConfirmReplace">
                                            Saya menyetujui penggantian
                                            repository aktif pada project ini.
                                        </label>
                                    </div>
                                @endif

                                <button type="submit"
                                        class="btn btn-primary">
                                    <i class="bi bi-check-circle me-1"></i>
                                    {{ !empty($githubAppRepoFullName)
                                        ? 'Konfirmasi Ganti Repository'
                                        : 'Simpan Koneksi GitHub App' }}
                                </button>
                            </form>
                        </div>
                    @endif

                    @if(!empty($githubAppRepoFullName))
                        <button type="button"
                                class="btn btn-outline-primary"
                                data-bs-toggle="modal"
                                data-bs-target="#githubAppReplaceModal">
                            <i class="bi bi-arrow-left-right me-1"></i>
                            Ganti Repository
                        </button>

                        <div class="modal fade" id="githubAppReplaceModal"
                             tabindex="-1" aria-hidden="true"
                             aria-labelledby="githubAppReplaceTitle">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h4 class="modal-title h5"
                                            id="githubAppReplaceTitle">
                                            Ganti Repository GitHub?
                                        </h4>
                                        <button type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal"
                                                aria-label="Tutup"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p class="mb-2">
                                            Repository saat ini:
                                            <strong>{{ $githubAppRepoFullName }}</strong>
                                        </p>
                                        <p class="text-muted small mb-0">
                                            Repository baru akan menjadi sumber
                                            pengambilan issue berikutnya. Issue
                                            yang sudah diimpor tetap tersimpan
                                            dengan identitas repository asal,
                                            dan tidak otomatis dihapus.
                                            Jika memilih repository lain,
                                            konfirmasi akhir diperlukan sebelum
                                            koneksi berubah.
                                        </p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button"
                                                class="btn btn-outline-secondary"
                                                data-bs-dismiss="modal">Batal</button>
                                        <form method="POST"
                                              action="{{ route('sources.github.app.connect', [
                                                  'project' => $project->id
                                              ]) }}">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-primary">
                                                Lanjutkan Penggantian
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <form method="POST"
                              action="{{ route('sources.github.app.connect', [
                                  'project' => $project->id
                              ]) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary">
                                <i class="bi bi-github me-1"></i>
                                Hubungkan GitHub
                            </button>
                        </form>
                    @endif

                    <p class="text-muted small mt-3 mb-0">
                        Instalasi dan izin akses dikelola melalui GitHub.
                        Issue hanya akan diimpor setelah pratinjau
                        dan konfirmasi.
                    </p>
                    @if(!empty($githubAppRepoFullName))

                        <!-- Preview dan Import GitHub App -->
                        <div id="github-app-operations"
                             class="border-top mt-4 pt-4"
                             data-preview-url="{{ route('sources.github.app.preview', ['project' => $project->id]) }}"
                             data-import-url="{{ route('sources.github.app.import', ['project' => $project->id]) }}">

                            <form id="github-app-operations-form">
                                @csrf

                                <button type="button"
                                        id="github-app-preview-btn"
                                        class="btn btn-outline-primary"
                                        disabled>
                                    <i class="bi bi-arrow-repeat me-1"></i>
                                    Ambil Issue melalui GitHub App
                                </button>
                            </form>

                            <div id="github-app-feedback"
                                 class="alert d-none mt-3"
                                 role="status"
                                 aria-live="polite">
                            </div>

                            <section id="github-app-preview-section"
                                     class="mt-4 d-none"
                                     aria-labelledby="github-app-preview-heading">

                                <h4 id="github-app-preview-heading"
                                    class="h6 fw-bold mb-3">
                                    Pratinjau Issue GitHub App
                                </h4>

                                <div class="row g-3 mb-4">

                                    <div class="col-sm-6 col-lg-3">
                                        <div class="border rounded p-3">
                                            <div class="small text-muted">
                                                Total Issue
                                            </div>
                                            <div id="github-app-total-count"
                                                 class="fs-4 fw-bold">0</div>
                                        </div>
                                    </div>

                                    <div class="col-sm-6 col-lg-3">
                                        <div class="border rounded p-3">
                                            <div class="small text-muted">
                                                Valid Baru
                                            </div>
                                            <div id="github-app-valid-count"
                                                 class="fs-4 fw-bold text-success">0</div>
                                        </div>
                                    </div>

                                    <div class="col-sm-6 col-lg-3">
                                        <div class="border rounded p-3">
                                            <div class="small text-muted">
                                                Invalid
                                            </div>
                                            <div id="github-app-invalid-count"
                                                 class="fs-4 fw-bold text-danger">0</div>
                                        </div>
                                    </div>

                                    <div class="col-sm-6 col-lg-3">
                                        <div class="border rounded p-3">
                                            <div class="small text-muted">
                                                Sudah Diimpor
                                            </div>
                                            <div id="github-app-imported-count"
                                                 class="fs-4 fw-bold">0</div>
                                        </div>
                                    </div>

                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle small">
                                        <thead class="table-light">
                                            <tr>
                                                <th scope="col">No.</th>
                                                <th scope="col">Issue</th>
                                                <th scope="col">Status</th>
                                                <th scope="col">Keterangan</th>
                                                <th scope="col">Sumber</th>
                                            </tr>
                                        </thead>

                                        <tbody id="github-app-preview-rows">
                                        </tbody>
                                    </table>
                                </div>

                                <div class="d-flex justify-content-end mt-3">
                                    <button type="button"
                                            id="github-app-import-btn"
                                            class="btn btn-success"
                                            disabled>
                                        <i class="bi bi-check-circle me-1"></i>
                                        Konfirmasi Impor
                                    </button>
                                </div>

                            </section>
                        </div>

                    @endif

                </section>

                <!-- Koneksi PAT lama tetap tersedia -->
                <h3 class="h6 fw-bold mb-2">
                    Koneksi Manual (PAT)
                </h3>

                <p class="text-muted small">
                    Metode koneksi lama tetap tersedia
                    selama masa transisi GitHub App.
                </p>

                <!-- Form Koneksi GitHub -->
                <form id="github-connection-form"
                      autocomplete="off"
                      novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="github-repo"
                               class="form-label fw-semibold">
                            Repository
                        </label>

                        <input type="text"
                               id="github-repo"
                               name="repo_full_name"
                               value="{{ $githubRepoFullName ?? '' }}"
                               class="form-control"
                               placeholder="owner/repository"
                               maxlength="255"
                               spellcheck="false"
                               autocapitalize="off"
                               autocomplete="off"
                               required>

                        <div class="form-text">
                            Contoh: pandas-dev/pandas
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="github-pat"
                               class="form-label fw-semibold">
                            Personal Access Token (PAT)
                        </label>

                        <input type="password"
                               id="github-pat"
                               name="pat"
                               class="form-control"
                               autocomplete="new-password"
                               placeholder="Masukkan token akses GitHub"
                               required>

                        <div class="form-text">
                            Token disimpan secara terenkripsi di backend
                            dan tidak ditampilkan kembali.
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="button"
                                id="github-test-btn"
                                class="btn btn-outline-primary"
                                disabled>
                            <i class="bi bi-plug me-1"></i>
                            Uji Koneksi
                        </button>

                        <button type="button"
                                id="github-save-btn"
                                class="btn btn-primary"
                                disabled>
                            <i class="bi bi-save me-1"></i>
                            Simpan Koneksi
                        </button>

                        <button type="button"
                                id="github-preview-btn"
                                class="btn btn-outline-secondary"
                                disabled>
                            <i class="bi bi-arrow-repeat me-1"></i>
                            Ambil Issue
                        </button>
                    </div>
                </form>

                <!-- Notifikasi operasi GitHub -->
                <div id="github-feedback"
                     class="alert d-none mt-3"
                     role="status"
                     aria-live="polite">
                </div>

                <!-- Hasil Pratinjau GitHub -->
                <section id="github-preview-section"
                         class="mt-4 d-none"
                         aria-labelledby="github-preview-heading">

                    <hr class="my-4">

                    <h3 id="github-preview-heading"
                        class="h5 fw-bold mb-2">
                        Pratinjau Issue GitHub
                    </h3>

                    <p class="text-muted small mb-3">
                        Periksa issue sebelum mengonfirmasi impor.
                        Hanya issue berstatus VALID yang akan disimpan.
                    </p>

                    <div class="row g-3 mb-4">
                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted">
                                    Total Issue
                                </div>
                                <div id="github-total-count"
                                     class="fs-4 fw-bold">0</div>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted">
                                    Valid Baru
                                </div>
                                <div id="github-valid-count"
                                     class="fs-4 fw-bold text-success">0</div>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted">
                                    Invalid
                                </div>
                                <div id="github-invalid-count"
                                     class="fs-4 fw-bold text-danger">0</div>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted">
                                    Sudah Diimpor
                                </div>
                                <div id="github-imported-count"
                                     class="fs-4 fw-bold">0</div>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle small">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">No.</th>
                                    <th scope="col">Issue</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Keterangan</th>
                                    <th scope="col">Sumber</th>
                                </tr>
                            </thead>

                            <tbody id="github-preview-rows">
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3">
                        <p class="small text-muted mb-0">
                            Data invalid dan issue yang sudah diimpor
                            akan dilewati.
                        </p>

                        <button type="button"
                                id="github-import-btn"
                                class="btn btn-success"
                                disabled>
                            <i class="bi bi-check-circle me-1"></i>
                            Konfirmasi Impor
                        </button>
                    </div>
                </section>

                <div class="alert alert-secondary small mt-4 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Issue GitHub harus melalui pratinjau
                    sebelum dapat diimpor.
                </div>

            </div>

        </div>
    </div>
</div>


<!-- Informasi Status Integrasi -->
<div class="alert alert-info mt-4 small" role="status">
    <i class="bi bi-info-circle me-1"></i>
    Impor CSV dan GitHub tersedia dengan validasi,
    pratinjau, konfirmasi, dan pencatatan riwayat impor.
    Issue GitHub yang berhasil diimpor akan diproses oleh
    layanan NLP untuk menghasilkan rekomendasi kandidat duplikat.
</div>

<script src="{{ asset('js/github-connection.js') }}" defer></script>
<script src="{{ asset('js/github-preview.js') }}" defer></script>
<script src="{{ asset('js/github-app.js') }}" defer></script>

@endsection
