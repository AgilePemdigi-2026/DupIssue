@extends(view()->exists('layouts.app') ? 'layouts.app' : 'issues.layout')

@section('title', 'Submit Issue — DupIssue')

@section('content')
<div class="card shadow-sm">
    <div class="card-body">
        <h1 class="h4 mb-2">Submit Issue</h1>
        <p class="text-muted">Project: <strong>{{ $project->name }}</strong></p>
        <p class="small text-muted">Deteksi kandidat duplikat diproses setelah issue tersimpan.</p>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">Periksa kembali judul dan deskripsi.</div>
        @endif

        <form action="{{ route('issues.store', ['project' => $project]) }}" method="POST">
            @csrf
            <div class="mb-3">
                <label for="title" class="form-label">Judul Issue</label>
                <input type="text" class="form-control @error('title') is-invalid @enderror"
                       id="title" name="title" maxlength="200" minlength="5"
                       value="{{ old('title') }}" required>
                @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Deskripsi Issue</label>
                <textarea class="form-control @error('description') is-invalid @enderror"
                          id="description" name="description" rows="6" minlength="10"
                          maxlength="10000" required>{{ old('description') }}</textarea>
                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <button type="submit" class="btn btn-primary">Kirim Issue</button>
            @include('issues.project-link', ['label' => 'Batal'])
        </form>
    </div>
</div>
@endsection
