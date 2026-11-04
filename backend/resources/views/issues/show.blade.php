@extends(view()->exists('layouts.app') ? 'layouts.app' : 'issues.layout')

@section('title', 'Detail Issue — DupIssue')

@section('content')
<div class="card shadow-sm">
    <div class="card-body">
        @if (session('success') && ! view()->exists('layouts.app'))
            <div class="alert alert-success" role="status">{{ session('success') }}</div>
        @endif
        <h1 class="h4">{{ $issue->title }}</h1>
        <p class="text-muted">Project: {{ $project->name }}</p>
        <p>{{ $issue->description }}</p>
        <p><strong>Status:</strong> {{ $issue->status }}</p>
        <p><strong>Status deteksi NLP:</strong> {{ $issue->detection_status }}</p>
        @if (session('status_notice'))
            <div class="alert alert-info" role="status">{{ session('status_notice') }}</div>
        @endif

        <div class="border rounded p-3 my-4">
            <h2 class="h5 mb-1">Vote Issue</h2>
            @if ($votingIssue->id !== $issue->id)
                <p class="text-muted mb-1">
                    Vote dicatat pada issue induk #{{ $votingIssue->id }}.
                </p>
            @endif
            <p class="text-muted mb-2">
                Jumlah Vote:
                <strong>{{ number_format((int) $votingIssue->vote_count) }}</strong>
            </p>
            @if ($canVote)
                @if ($hasVoted)
                    <form method="POST" action="{{ route('issues.unvote', ['project' => $project, 'issue' => $issue]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Apakah kamu yakin ingin membatalkan vote?')">Batalkan Vote</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('issues.vote', ['project' => $project, 'issue' => $issue]) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">Vote Issue</button>
                    </form>
                @endif
            @endif
            <p class="text-muted small mt-2 mb-0">
                Kamu hanya dapat memberikan satu vote untuk setiap issue.
                Vote dapat dibatalkan kapan saja.
            </p>
        </div>

        @if ($issue->duplicate_of)
            <div class="alert alert-warning" role="status">
                Issue ini telah ditandai sebagai duplikat.
                <a href="{{ route('issues.show', ['project' => $project, 'issue' => $issue->duplicate_of]) }}">Issue Induk #{{ $issue->duplicate_of }}</a>
            </div>
            @if (auth()->user()->role === 'MAINTAINER' && (int) $project->owner_id === (int) auth()->id())
                <form action="{{ route('issues.duplicates.cancel', ['project' => $project, 'issue' => $issue]) }}" method="POST" class="border rounded p-3 mb-3">
                    @csrf
                    <h2 class="h6">Batalkan Penandaan Duplikat</h2>
                    <input type="hidden" name="expected_duplicate_of" value="{{ $issue->duplicate_of }}">
                    <input type="hidden" name="expected_status" value="{{ $issue->status }}">
                    <label for="correction-reason" class="form-label">Alasan koreksi (wajib, 10–500 karakter)</label>
                    <textarea id="correction-reason" name="reason" class="form-control mb-2" minlength="10" maxlength="500" required>{{ old('reason') }}</textarea>
                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Batalkan penandaan duplikat dan kembalikan status menjadi OPEN?')">Konfirmasi Pembatalan</button>
                </form>
            @endif
        @endif
        @if (auth()->user()->role === 'MAINTAINER'
            && (int) $project->owner_id === (int) auth()->id()
            && $issue->duplicate_of === null && $issue->status !== 'DUPLICATE')
            @php $legalStatuses = \App\Http\Controllers\IssueStatusController::allowedTransitions($issue->status); @endphp
            <form action="{{ route('issues.status.preview', ['project' => $project, 'issue' => $issue]) }}" method="POST" class="border rounded p-3 mb-3">
                @csrf
                <h2 class="h6">Ubah Status</h2>
                <input type="hidden" name="expected_status" value="{{ $issue->status }}">
                <input type="hidden" name="expected_updated_at" value="{{ $issue->getRawOriginal('updated_at') ?? '' }}">
                <label for="new-status" class="form-label">Status Tujuan</label>
                <select id="new-status" class="form-select mb-2" name="new_status">
                    <option value="{{ $issue->status }}">{{ $issue->status }} (saat ini)</option>
                    @foreach ($legalStatuses as $choice)
                        <option value="{{ $choice }}">{{ $choice }}</option>
                    @endforeach
                </select>
                <p class="text-muted small">Transisi yang diizinkan: {{ count($legalStatuses) ? implode(', ', $legalStatuses) : 'Tidak ada' }}.</p>
                <button type="submit" class="btn btn-outline-primary btn-sm">Simpan Status</button>
            </form>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                @foreach ($errors->all() as $error) <div>{{ $error }}</div> @endforeach
            </div>
        @endif
        @if (in_array($issue->detection_status, ['PENDING', 'PROCESSING'], true))
            <div class="alert alert-info" role="status">Deteksi kemiripan sedang diproses. Muat ulang halaman untuk melihat hasilnya.</div>
        @elseif ($issue->detection_status === 'FAILED')
            <div class="alert alert-warning" role="status">Deteksi kemiripan belum berhasil. Laporan issue tetap tersimpan.</div>
        @elseif ($issue->detection_status === 'COMPLETED')
            <h2 class="h5 mt-4">Rekomendasi Issue Serupa</h2>
            @if ($recommendations->isEmpty())
                <p class="text-muted">Belum ada kandidat yang memenuhi batas similarity 75% saat ini.</p>
            @else
                <ul class="list-group mb-3">
                    @foreach ($recommendations as $recommendation)
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-3">
                            <a href="{{ route('issues.show', ['project' => $project, 'issue' => $recommendation->candidateIssue]) }}">
                                #{{ $recommendation->candidate_issue_id }} — {{ $recommendation->candidateIssue->title }}
                            </a>
                            <span class="badge text-bg-primary">
                                {{ number_format($recommendation->similarity_score * 100, 2, ',', '.') }}%
                            </span>
                            <span class="badge bg-secondary">{{ $recommendation->decision }}</span>
                            @if (auth()->user()->role === 'MAINTAINER' && (int) $project->owner_id === (int) auth()->id() && $issue->detection_status === 'COMPLETED')
                                <details class="w-100 mt-2">
                                    <summary class="btn btn-outline-primary btn-sm">Bandingkan dan Validasi</summary>
                                    <div class="row mt-3 g-3">
                                        <div class="col-md-6">
                                            <div class="border rounded p-3 h-100">
                                                <strong>Issue #{{ $issue->id }} — {{ $issue->title }}</strong>
                                                <p class="mb-1">Status: {{ $issue->status }} | Vote: {{ $issue->vote_count }} | Sumber: {{ $issue->source_type }}</p>
                                                <p class="mb-0" style="white-space: pre-wrap">{{ $issue->description }}</p>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="border rounded p-3 h-100">
                                                <strong>Issue #{{ $recommendation->candidateIssue->id }} — {{ $recommendation->candidateIssue->title }}</strong>
                                                <p class="mb-1">Status: {{ $recommendation->candidateIssue->status }} | Vote: {{ $recommendation->candidateIssue->vote_count }} | Sumber: {{ $recommendation->candidateIssue->source_type }}</p>
                                                <p class="mb-0" style="white-space: pre-wrap">{{ $recommendation->candidateIssue->description }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    @if ($recommendation->decision !== 'DUPLICATE')
                                        <form class="mt-3" method="POST" action="{{ route('issues.duplicates.decide', ['project' => $project, 'issue' => $issue, 'candidate' => $recommendation]) }}">
                                            @csrf
                                            <input type="hidden" name="expected_decision" value="{{ $recommendation->decision }}">
                                            <input type="hidden" name="expected_source_status" value="{{ $issue->status }}">
                                            <input type="hidden" name="expected_candidate_status" value="{{ $recommendation->candidateIssue->status }}">
                                            <input type="hidden" name="expected_source_parent" value="{{ $issue->duplicate_of ?? 0 }}">
                                            <input type="hidden" name="expected_candidate_parent" value="{{ $recommendation->candidateIssue->duplicate_of ?? 0 }}">
                                            <label class="form-label">Pilih Issue Induk saat mengonfirmasi duplikat</label>
                                            <select name="parent_issue_id" class="form-select mb-2">
                                                <option value="{{ $recommendation->candidateIssue->id }}">Issue #{{ $recommendation->candidateIssue->id }} menjadi induk (Issue #{{ $issue->id }} duplikat)</option>
                                                <option value="{{ $issue->id }}">Issue #{{ $issue->id }} menjadi induk (Issue #{{ $recommendation->candidateIssue->id }} duplikat)</option>
                                            </select>
                                            <p class="text-muted small">Konfirmasi: issue anak menjadi DUPLICATE; jika induknya RESOLVED/CLOSED, issue anak menjadi CLOSED. Konten issue tidak dihapus.</p>
                                            <button type="submit" name="decision" value="DUPLICATE" class="btn btn-danger btn-sm" onclick="return confirm('Konfirmasi duplikat sesuai induk yang dipilih?')">Konfirmasi Duplikat</button>
                                            <button type="submit" name="decision" value="NOT_DUPLICATE" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Konfirmasi bahwa pasangan ini bukan duplikat?')">Bukan Duplikat</button>
                                        </form>
                                    @else
                                        <p class="text-muted small mt-2">Pasangan sudah dikonfirmasi. Untuk membatalkan, buka detail issue anak dan masukkan alasan koreksi.</p>
                                    @endif
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="text-muted small">Skor ini hanya rekomendasi kemiripan dari NLP. Keputusan duplikat tetap dilakukan Maintainer.</p>
        @endif
        @if (auth()->user()->role === 'MAINTAINER' && (int) $project->owner_id === (int) auth()->id() && $reviewHistory->isNotEmpty())
            <h2 class="h6 mt-4">Riwayat Keputusan Duplikat</h2>
            <ul class="list-group mb-3">
                @foreach ($reviewHistory as $event)
                    <li class="list-group-item">
                        <strong>{{ $event->action }}</strong> · {{ $event->created_at }} · User #{{ $event->user_id }}
                        @if ($event->reason) <p class="mb-0">Alasan: {{ $event->reason }}</p> @endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($statusHistory->isNotEmpty())
            <h2 class="h6 mt-4">Riwayat Status</h2>
            <ul class="list-group mb-3">
                @foreach ($statusHistory as $entry)
                    <li class="list-group-item">
                        <strong>{{ $entry->action }}</strong> · {{ $entry->created_at }}
                        @if ($entry->reason)<p class="mb-0">{{ $entry->reason }}</p>@endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if (auth()->user()->role === 'REPORTER')
            <a href="{{ route('issues.create', ['project' => $project]) }}" class="btn btn-outline-primary">Buat Issue Lagi</a>
        @endif
        @include('issues.project-link', ['label' => 'Kembali ke Project'])
    </div>
</div>
@endsection
