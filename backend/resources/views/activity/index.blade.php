@extends(view()->exists('layouts.app') ? 'layouts.app' : 'issues.layout')

@section('title', 'Riwayat & Log Aktivitas — ' . e($project->name))

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 di-page-header">
    <div>
        <a href="{{ route('projects.show', ['id' => $project->id]) }}" class="text-decoration-none small">
            Kembali ke Project
        </a>
        <h1 class="h2 fw-bold mt-2 mb-1">Riwayat &amp; Log Aktivitas</h1>
        <p class="text-muted mb-0">Audit trail read-only seluruh peristiwa dan perubahan pada project <strong>{{ $project->name }}</strong>.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 di-toolbar-actions">
        <a href="{{ route('projects.activity.export', array_merge(['project' => $project], request()->query())) }}" class="btn btn-outline-success btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-download"></i> Ekspor CSV
        </a>
    </div>
</div>

{{-- Validation Errors --}}
@if ($errors->any())
    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
        <h6 class="alert-heading mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Parameter filter tidak valid:</h6>
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

{{-- Ringkasan Kartu / Summary Cards --}}
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-primary border-4">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Aktivitas 7 Hari Terakhir</div>
                <div class="h3 my-1 fw-bold text-primary">{{ number_format($activity7d) }}</div>
                <div class="text-muted small">Peristiwa tercatat (WIB)</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-success border-4">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Duplikat Tereduksi</div>
                <div class="h3 my-1 fw-bold text-success">{{ number_format($reducedDuplicates) }}</div>
                <div class="text-muted small">Issue unik dengan induk aktif</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-info border-4">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Sinkronisasi Terakhir GitHub</div>
                <div class="h5 my-2 fw-bold text-secondary">{{ $lastSync }}</div>
                <div class="text-muted small">Riwayat impor GitHub</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-warning border-4">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Status Audit Trail</div>
                <div class="h5 my-2 fw-bold text-dark">
                    <span class="badge bg-success-subtle text-success border border-success-subtle">Aktif (Read-Only)</span>
                </div>
                <div class="text-muted small">Integritas data terjaga</div>
            </div>
        </div>
    </div>
</div>

{{-- Filter Form --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-semibold text-secondary"><i class="bi bi-funnel me-1"></i> Filter Aktivitas</h6>
    </div>
    <div class="card-body">
        <form method="GET" action="{{ route('projects.activity.index', ['project' => $project]) }}" class="row g-3">
            <div class="col-12 col-md-3">
                <label for="filter-category" class="form-label small text-muted">Kategori</label>
                <select name="category" id="filter-category" class="form-select form-select-sm">
                    <option value="">-- Semua Kategori --</option>
                    @foreach ($categories as $key => $label)
                        <option value="{{ $key }}" @selected(request('category') === $key)>{{ $label }} ({{ $key }})</option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label for="filter-date-from" class="form-label small text-muted">Dari Tanggal (WIB)</label>
                <input type="date" name="date_from" id="filter-date-from" class="form-control form-control-sm" value="{{ request('date_from') }}">
            </div>

            <div class="col-6 col-md-2">
                <label for="filter-date-to" class="form-label small text-muted">Sampai Tanggal (WIB)</label>
                <input type="date" name="date_to" id="filter-date-to" class="form-control form-control-sm" value="{{ request('date_to') }}">
            </div>

            <div class="col-12 col-md-3">
                <label for="filter-keyword" class="form-label small text-muted">Kata Kunci (Aksi / Alasan)</label>
                <input type="text" name="keyword" id="filter-keyword" class="form-control form-control-sm" placeholder="Cari aksi atau alasan..." value="{{ request('keyword') }}" maxlength="100">
            </div>

            <div class="col-12 col-md-2 d-flex align-items-end gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">Terapkan</button>
                <a href="{{ route('projects.activity.index', array_merge(['project' => $project], request()->query())) }}" class="btn btn-outline-secondary btn-sm" title="Muat Ulang Filter">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
                <a href="{{ route('projects.activity.index', ['project' => $project]) }}" class="btn btn-outline-danger btn-sm" title="Reset Filter">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </div>
</div>

{{-- Tabel Log Aktivitas --}}
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">Daftar Catatan Aktivitas ({{ $logs->total() }})</h6>
        <span class="text-muted small">Menampilkan 20 log per halaman</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 140px;">Waktu (WIB)</th>
                    <th>Pelaku</th>
                    <th>Kategori</th>
                    <th>Aksi</th>
                    <th>Target</th>
                    <th>Detail Perubahan</th>
                    <th>Alasan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="small text-nowrap">
                            {{ $log->created_at ? $log->created_at->setTimezone('Asia/Jakarta')->format('d M Y, H:i') . ' WIB' : '-' }}
                        </td>
                        <td>
                            <span class="badge {{ $log->user ? 'bg-secondary' : 'bg-dark' }}">
                                {{ $log->actor_name }}
                            </span>
                        </td>
                        <td>
                            @php
                                $catClass = match($log->ui_category) {
                                    'PROJECT' => 'bg-primary-subtle text-primary border border-primary-subtle',
                                    'MEMBERSHIP' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
                                    'ISSUE_STATUS' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                                    'DUPLICATE_REVIEW' => 'bg-success-subtle text-success border border-success-subtle',
                                    'ISSUE_SOURCE' => 'bg-purple-subtle text-purple border border-purple-subtle',
                                    default => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                                };
                            @endphp
                            <span class="badge {{ $catClass }}">{{ $log->ui_category }}</span>
                        </td>
                        <td>
                            <code class="text-dark">{{ $log->action }}</code>
                        </td>
                        <td class="small">
                            @if ($log->issue && (int) $log->issue->project_id === (int) $project->id)
                                <a href="{{ route('issues.show', ['project' => $project, 'issue' => $log->issue]) }}" class="text-decoration-none fw-semibold">
                                    #{{ $log->issue->id }} {{ \Illuminate\Support\Str::limit($log->issue->title, 25) }}
                                </a>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td class="small" style="max-width: 280px; word-break: break-word;">
                            @php
                                $old = $log->sanitized_old_values;
                                $new = $log->sanitized_new_values;
                            @endphp
                            @if (empty($old) && empty($new))
                                <span class="text-muted">-</span>
                            @else
                                @if (!empty($old))
                                    <div class="text-muted"><span class="badge bg-light text-dark border">Lama</span> <code class="text-secondary">{{ json_encode($old, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></div>
                                @endif
                                @if (!empty($new))
                                    <div class="mt-1"><span class="badge bg-light text-success border">Baru</span> <code class="text-success">{{ json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></div>
                                @endif
                            @endif
                        </td>
                        <td class="small" style="max-width: 200px; word-break: break-word;">
                            {{ $log->sanitized_reason ?? '-' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-clock-history fs-1 d-block mb-2 text-secondary"></i>
                            <strong>Belum ada catatan aktivitas</strong>
                            <p class="small mb-0">Tidak ada log aktivitas yang cocok dengan filter yang dipilih atau project belum memiliki aktivitas.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($logs->hasPages())
        <div class="card-footer bg-white d-flex justify-content-end py-3">
            {{ $logs->links() }}
        </div>
    @endif
</div>
@endsection
