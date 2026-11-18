
@extends(view()->exists('layouts.app') ? 'layouts.app' : 'issues.layout')

@section('title', 'Submit Issue — DupIssue')

@section('content')
<div class="mb-4">
    <h1 class="h4 mb-2">Submit Issue</h1>
    <p class="text-muted mb-1">
        Project: <strong>{{ $project->name }}</strong>
    </p>
    <p class="small text-muted">
        Periksa rekomendasi issue serupa sebelum mengirim laporan.
        Deteksi kandidat duplikat juga diproses setelah issue tersimpan.
    </p>
</div>

<div class="row g-4 align-items-start">

    <!-- Kiri: Form Submit Issue -->
    <div class="col-12 col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">Informasi Issue</h2>

                @if ($errors->any())
                    <div class="alert alert-danger" role="alert">
                        Periksa kembali judul dan deskripsi.
                    </div>
                @endif

                <form id="issue-submit-form"
                      action="{{ route('issues.store', ['project' => $project]) }}"
                      data-recommendations-url="{{ route('issues.recommendations', ['project' => $project]) }}"
                      method="POST">
                    @csrf

                    <div class="mb-3">
                        <label for="title" class="form-label">
                            Judul Issue
                        </label>
                        <input
                            type="text"
                            class="form-control @error('title') is-invalid @enderror"
                            id="title"
                            name="title"
                            maxlength="200"
                            minlength="5"
                            value="{{ old('title') }}"
                            required
                        >
                        @error('title')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                        <div class="form-text">
                            5–200 karakter.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">
                            Deskripsi Issue
                        </label>
                        <textarea
                            class="form-control @error('description') is-invalid @enderror"
                            id="description"
                            name="description"
                            rows="8"
                            minlength="10"
                            maxlength="10000"
                            required
                        >{{ old('description') }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">
                                {{ $message }}
                            </div>
                        @enderror
                        <div class="form-text">
                            10–10.000 karakter.
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 di-actions">
                        <button type="submit" class="btn btn-primary">
                            Kirim Issue
                        </button>

                        @include('issues.project-link', ['label' => 'Batal'])
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Kanan: Panel Rekomendasi NLP -->
    <div class="col-12 col-lg-5">
        @include('issues.partials.recommendations')
    </div>

</div>
@endsection

@section('scripts')
<script src="{{ asset('js/issue-recommendations.js') }}" defer></script>
@endsection