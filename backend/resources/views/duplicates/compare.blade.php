@extends(view()->exists('layouts.app') ? 'layouts.app' : 'issues.layout')

@section('title', 'Bandingkan Issue — #' . $source->id . ' & #' . $suggested->id)

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Bandingkan Pasangan Issue</h1>
            <p class="text-muted mb-0">Project: <strong>{{ $project->name }}</strong> | Skor Kemiripan NLP: <span class="badge text-bg-primary fs-6">{{ number_format($candidate->similarity_score * 100, 2, ',', '.') }}%</span></p>
        </div>
        <div>
            <a href="{{ route('projects.duplicates.index', ['project' => $project]) }}" class="btn btn-outline-secondary btn-sm">Kembali ke Daftar Kandidat</a>
            <a href="{{ route('issues.show', ['project' => $project, 'issue' => $source]) }}" class="btn btn-outline-primary btn-sm">Detail Issue Sumber</a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Perbandingan Berdampingan (REQ-05.3, REQ-NF-21) --}}
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card h-100 shadow-sm border-primary-subtle">
                <div class="card-header bg-primary-subtle text-primary-emphasis d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Issue Sumber #{{ $source->id }}</span>
                    <span class="badge bg-primary">Sumber</span>
                </div>
                <div class="card-body">
                    <h2 class="h5 card-title text-break">{{ $source->title }}</h2>
                    <div class="mb-3 d-flex flex-wrap gap-2">
                        <span class="badge bg-secondary">Status: {{ $source->status }}</span>
                        <span class="badge bg-info text-dark">Vote: {{ $source->vote_count }}</span>
                        <span class="badge bg-light text-dark border">Sumber: {{ $source->source_type }}</span>
                    </div>
                    <div class="small text-muted mb-3">
                        <div><strong>Pelapor:</strong> {{ $source->author?->name ?? 'User #' . $source->author_id }} ({{ $source->author?->email ?? '-' }})</div>
                        <div><strong>Dilaporkan:</strong> {{ $source->created_at?->format('d M Y H:i') ?? '-' }}</div>
                        @if ($source->duplicate_of)
                            <div class="text-danger fw-semibold">Duplikat dari: #{{ $source->duplicate_of }}</div>
                        @endif
                    </div>
                    <hr>
                    <h3 class="h6 fw-bold">Deskripsi:</h3>
                    <div class="p-3 bg-light rounded text-break" style="white-space: pre-wrap; max-height: 350px; overflow-y: auto;">{{ $source->description }}</div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100 shadow-sm border-info-subtle">
                <div class="card-header bg-info-subtle text-info-emphasis d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Kandidat Serupa #{{ $suggested->id }}</span>
                    <span class="badge bg-info text-dark">Kandidat</span>
                </div>
                <div class="card-body">
                    <h2 class="h5 card-title text-break">{{ $suggested->title }}</h2>
                    <div class="mb-3 d-flex flex-wrap gap-2">
                        <span class="badge bg-secondary">Status: {{ $suggested->status }}</span>
                        <span class="badge bg-info text-dark">Vote: {{ $suggested->vote_count }}</span>
                        <span class="badge bg-light text-dark border">Sumber: {{ $suggested->source_type }}</span>
                    </div>
                    <div class="small text-muted mb-3">
                        <div><strong>Pelapor:</strong> {{ $suggested->author?->name ?? 'User #' . $suggested->author_id }} ({{ $suggested->author?->email ?? '-' }})</div>
                        <div><strong>Dilaporkan:</strong> {{ $suggested->created_at?->format('d M Y H:i') ?? '-' }}</div>
                        @if ($suggested->duplicate_of)
                            <div class="text-danger fw-semibold">Duplikat dari: #{{ $suggested->duplicate_of }}</div>
                        @endif
                    </div>
                    <hr>
                    <h3 class="h6 fw-bold">Deskripsi:</h3>
                    <div class="p-3 bg-light rounded text-break" style="white-space: pre-wrap; max-height: 350px; overflow-y: auto;">{{ $suggested->description }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Kotak Aturan Relasi & Ringkasan Dampak Status (REQ-05.6, REQ-NF-11) --}}
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card bg-light border-0 shadow-sm h-100">
                <div class="card-body">
                    <h3 class="h6 fw-bold text-dark"><i class="bi bi-shield-check me-1"></i> Aturan Relasi Duplikat (SRS BR-08–BR-12)</h3>
                    <ul class="small text-muted mb-0 ps-3">
                        <li>Issue induk dan duplikat harus berada dalam satu project yang sama.</li>
                        <li>Induk tidak boleh merupakan duplikat dari issue lain (tidak berantai).</li>
                        <li>Relasi tidak boleh membentuk siklus (parent tidak boleh menjadi anak dari keturunannya).</li>
                        <li>Konten teks, lampiran, dan histori issue anak <strong>tidak dihapus</strong> atau digabungkan.</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card bg-light border-0 shadow-sm h-100">
                <div class="card-body">
                    <h3 class="h6 fw-bold text-dark"><i class="bi bi-info-circle me-1"></i> Ringkasan Konsekuensi Status (REQ-05.8)</h3>
                    <ul class="small text-muted mb-0 ps-3">
                        <li>Jika induk berstatus <strong>OPEN</strong> / <strong>IN_PROGRESS</strong>: Issue anak berubah status menjadi <strong>DUPLICATE</strong>.</li>
                        <li>Jika induk berstatus <strong>RESOLVED</strong> / <strong>CLOSED</strong>: Issue anak otomatis berubah status menjadi <strong>CLOSED</strong>.</li>
                        <li>Penandaan duplikat dapat dibatalkan di kemudian hari dengan menyertakan alasan koreksi wajib.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- Form Keputusan Validasi Duplikat --}}
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">Formulir Keputusan Validasi</h2>
        </div>
        <div class="card-body">
            @if ($candidate->decision !== 'DUPLICATE')
                <form method="POST" action="{{ route('issues.duplicates.decide', ['project' => $project, 'issue' => $source, 'candidate' => $candidate]) }}">
                    @csrf
                    <input type="hidden" name="expected_decision" value="{{ $candidate->decision }}">
                    <input type="hidden" name="expected_source_status" value="{{ $source->status }}">
                    <input type="hidden" name="expected_candidate_status" value="{{ $suggested->status }}">
                    <input type="hidden" name="expected_source_parent" value="{{ $source->duplicate_of ?? 0 }}">
                    <input type="hidden" name="expected_candidate_parent" value="{{ $suggested->duplicate_of ?? 0 }}">

                    <div class="mb-4">
                        <label class="form-label fw-bold">Pilih Issue Induk (Issue yang dipertahankan):</label>
                        <div class="form-check p-3 border rounded mb-2 {{ $suggestedParentId === (int) $source->id ? 'border-primary bg-primary-subtle' : '' }}">
                            <input class="form-check-input" type="radio" name="parent_issue_id" id="parent-source" value="{{ $source->id }}" {{ $suggestedParentId === (int) $source->id ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="parent-source">
                                Jadikan Issue #{{ $source->id }} sebagai INDUK
                                (Issue #{{ $suggested->id }} menjadi duplikat)
                                @if ((int) $source->vote_count > (int) $suggested->vote_count)
                                    <span class="badge bg-success ms-2">Disarankan — Vote Terbanyak ({{ $source->vote_count }} vs {{ $suggested->vote_count }})</span>
                                @elseif ((int) $source->vote_count === (int) $suggested->vote_count && $suggestedParentId === (int) $source->id)
                                    <span class="badge bg-secondary ms-2">Disarankan (Vote Sama)</span>
                                @endif
                            </label>
                            <div class="small text-muted mt-1 ps-4">{{ $source->title }}</div>
                        </div>

                        <div class="form-check p-3 border rounded {{ $suggestedParentId === (int) $suggested->id ? 'border-primary bg-primary-subtle' : '' }}">
                            <input class="form-check-input" type="radio" name="parent_issue_id" id="parent-suggested" value="{{ $suggested->id }}" {{ $suggestedParentId === (int) $suggested->id ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="parent-suggested">
                                Jadikan Issue #{{ $suggested->id }} sebagai INDUK
                                (Issue #{{ $source->id }} menjadi duplikat)
                                @if ((int) $suggested->vote_count > (int) $source->vote_count)
                                    <span class="badge bg-success ms-2">Disarankan — Vote Terbanyak ({{ $suggested->vote_count }} vs {{ $source->vote_count }})</span>
                                @elseif ((int) $source->vote_count === (int) $suggested->vote_count && $suggestedParentId === (int) $suggested->id)
                                    <span class="badge bg-secondary ms-2">Disarankan (Vote Sama)</span>
                                @endif
                            </label>
                            <div class="small text-muted mt-1 ps-4">{{ $suggested->title }}</div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="decision" value="DUPLICATE" class="btn btn-danger" onclick="return confirm('Apakah Anda yakin ingin mengonfirmasi pasangan ini sebagai DUPLIKAT sesuai induk yang dipilih? Status issue anak akan disesuaikan.')">
                            Konfirmasi Duplikat
                        </button>
                        <button type="submit" name="decision" value="NOT_DUPLICATE" class="btn btn-outline-secondary" onclick="return confirm('Apakah Anda yakin ingin menandai pasangan ini sebagai BUKAN DUPLIKAT? Status kedua issue tidak akan berubah.')">
                            Bukan Duplikat
                        </button>
                        <a href="{{ route('projects.duplicates.index', ['project' => $project]) }}" class="btn btn-link text-decoration-none">Batal</a>
                    </div>
                </form>
            @else
                <div class="alert alert-warning mb-3" role="status">
                    <h3 class="h6 fw-bold mb-1">Pasangan ini telah dikonfirmasi sebagai DUPLIKAT</h3>
                    <p class="mb-0 small">
                        Ditetapkan oleh {{ $candidate->decidedBy?->name ?? 'User #' . $candidate->decided_by }} pada {{ $candidate->decided_at }}.
                        Untuk membatalkan hubungan duplikat ini, isi alasan koreksi di bawah.
                    </p>
                </div>

                @php
                    $childIssue = ($source->duplicate_of !== null) ? $source : $suggested;
                @endphp

                <form method="POST" action="{{ route('issues.duplicates.cancel', ['project' => $project, 'issue' => $childIssue]) }}" class="border rounded p-3 bg-light">
                    @csrf
                    <h3 class="h6 fw-bold mb-2">Batalkan Penandaan Duplikat (Koreksi)</h3>
                    <input type="hidden" name="expected_duplicate_of" value="{{ $childIssue->duplicate_of }}">
                    <input type="hidden" name="expected_status" value="{{ $childIssue->status }}">

                    <div class="mb-3">
                        <label for="cancel-reason" class="form-label fw-semibold">Alasan Koreksi (Wajib diisi, 10–500 karakter):</label>
                        <textarea id="cancel-reason" name="reason" class="form-control" rows="3" minlength="10" maxlength="500" required placeholder="Contoh: Kesalahan penandaan, setelah diteliti ternyata kedua issue membahas modul berbeda...">{{ old('reason') }}</textarea>
                    </div>

                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Batalkan penandaan duplikat untuk Issue #{{ $childIssue->id }} dan kembalikan statusnya ke OPEN?')">
                        Batalkan Penandaan Duplikat
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- Riwayat Keputusan Pasangan (REQ-05.2, REQ-05.10) --}}
    @if ($pairHistory->isNotEmpty())
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h2 class="h6 mb-0">Riwayat Keputusan Pasangan Ini</h2>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    @foreach ($pairHistory as $hist)
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge bg-secondary">{{ $hist->action }}</span>
                                <small class="text-muted">{{ $hist->created_at }}</small>
                            </div>
                            <div class="small">
                                <strong>User #{{ $hist->user_id }}</strong> melakukan tindakan pada Issue #{{ $hist->issue_id }}.
                                @if ($hist->reason)
                                    <div class="mt-1 p-2 bg-light rounded text-muted"><strong>Alasan:</strong> {{ $hist->reason }}</div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
@endsection

