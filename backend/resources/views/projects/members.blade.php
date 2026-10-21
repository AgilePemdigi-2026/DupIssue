@extends('layouts.app')

@section('title', 'Anggota Project - ' . $project->name)

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <div class="d-flex align-items-center gap-2">
            <a href="/projects/{{ $project->id }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i>
            </a>
            <h3 class="h4 fw-bold mb-0">Anggota Project: {{ $project->name }}</h3>
        </div>
        <p class="text-muted small mb-0 mt-1">Kelola kolaborator Reporter dan pantau status undangan.</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#inviteMemberCollapse" aria-expanded="true">
        <i class="bi bi-person-plus-fill me-1"></i> Undang Anggota
    </button>
</div>

@if ($errors->any())
    <div class="alert alert-danger error mb-4" id="error">
        <div class="fw-semibold mb-1">Pemberitahuan Undangan:</div>
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $errorMsg)
                <li class="error">{{ $errorMsg }}</li>
            @endforeach
        </ul>
    </div>
@endif

<!-- Form Undang Anggota (Collapsible) -->
<div class="collapse show mb-4" id="inviteMemberCollapse">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-0 py-3">
            <h5 class="h6 fw-bold mb-0 text-primary">
                <i class="bi bi-envelope-plus me-1"></i> Formulir Undang Anggota
            </h5>
        </div>
        <div class="card-body p-4 pt-0">
            <form action="/projects/{{ $project->id }}/members/invite" method="POST">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label for="email_anggota" class="form-label fw-semibold">Email Anggota</label>
                        <input type="text"
                               name="email"
                               id="email_anggota"
                               class="form-control @error('email') is-invalid @enderror"
                               placeholder="Email Anggota (contoh: user@dupissue.test)"
                               value="{{ old('email') }}">
                        @error('email')
                            <div class="error text-danger mt-1 small" id="error">
                                <span class="error">{{ $message }}</span>
                            </div>
                        @enderror
                        <div class="form-text">
                            Pengguna harus sudah terdaftar sebagai Reporter dan belum memiliki akses aktif.
                        </div>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-send me-1"></i> Kirim Undangan
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Tabel Anggota Aktif -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h5 class="h6 fw-bold mb-0">
                    <i class="bi bi-people-fill text-success me-1"></i> Anggota Aktif
                </h5>
                <span class="badge bg-success-subtle text-success">{{ $activeMembers->count() }} Anggota</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th>Nama & Email</th>
                                <th>Bergabung</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($activeMembers as $membership)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $membership->user->name ?? 'Reporter' }}</div>
                                        <small class="text-muted">{{ $membership->user->email }}</small>
                                    </td>
                                    <td>
                                        <small class="text-muted">{{ $membership->joined_at ? $membership->joined_at->format('d M Y') : '-' }}</small>
                                    </td>
                                    <td class="text-end">
                                        <button type="button"
                                                class="btn btn-outline-danger btn-sm"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalRemoveMember{{ $membership->user_id }}">
                                            Hapus Anggota
                                        </button>

                                        <!-- Modal Konfirmasi Hapus Anggota -->
                                        <div class="modal fade text-start" id="modalRemoveMember{{ $membership->user_id }}" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title h6 fw-bold text-danger">Konfirmasi Hapus Anggota</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="mb-2">
                                                            Apakah Anda yakin ingin menghapus <strong>{{ $membership->user->name ?? $membership->user->email }}</strong> dari project ini?
                                                        </p>
                                                        <div class="alert alert-warning small mb-0">
                                                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                                            Tindakan ini memiliki <strong>dampak pencabutan akses</strong> ke seluruh pelaporan dan interaksi issue project. Riwayat laporan dan vote lama anggota tetap tersimpan dalam sistem.
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                                                        <form action="/projects/{{ $project->id }}/members/{{ $membership->user_id }}/remove" method="POST">
                                                            @csrf
                                                            <button type="submit" class="btn btn-danger btn-sm">Konfirmasi</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4 small">
                                        Belum ada Reporter yang bergabung sebagai anggota aktif.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabel Status Undangan -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h5 class="h6 fw-bold mb-0">
                    <i class="bi bi-clock-history text-warning me-1"></i> Riwayat & Status Undangan
                </h5>
                <span class="badge bg-secondary-subtle text-secondary">{{ $invitations->count() }} Undangan</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th>Tujuan Email</th>
                                <th>Status</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($invitations as $invitation)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $invitation->reporter->name ?? 'Reporter' }}</div>
                                        <small class="text-muted">{{ $invitation->reporter->email ?? '-' }}</small>
                                    </td>
                                    <td>
                                        @php
                                            $statusText = match($invitation->status) {
                                                'PENDING' => 'Pending',
                                                'ACCEPTED' => 'Accepted',
                                                'REJECTED' => 'Rejected',
                                                'CANCELED' => 'Canceled',
                                                default => $invitation->status,
                                            };
                                            $badgeClass = match($invitation->status) {
                                                'PENDING' => 'bg-warning text-dark',
                                                'ACCEPTED' => 'bg-success text-white',
                                                'REJECTED' => 'bg-secondary text-white',
                                                'CANCELED' => 'bg-danger text-white',
                                                default => 'bg-light text-dark',
                                            };
                                        @endphp
                                        <span class="badge {{ $badgeClass }} invitation_status" id="invitation_status">
                                            {{ $statusText }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if($invitation->status === 'PENDING')
                                            <button type="button"
                                                    class="btn btn-outline-warning btn-sm"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalCancelInvitation{{ $invitation->id }}">
                                                Batalkan Undangan
                                            </button>

                                            <!-- Modal Konfirmasi Batalkan Undangan -->
                                            <div class="modal fade text-start" id="modalCancelInvitation{{ $invitation->id }}" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog modal-dialog-centered">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title h6 fw-bold">Konfirmasi pembatalan undangan</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p class="mb-0">
                                                                Apakah Anda yakin ingin membatalkan undangan untuk <strong>{{ $invitation->reporter->email ?? 'Reporter' }}</strong>? Status undangan akan berubah menjadi Canceled.
                                                            </p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                                                            <form action="/projects/{{ $project->id }}/invitations/{{ $invitation->id }}/cancel" method="POST">
                                                                @csrf
                                                                <button type="submit" class="btn btn-danger btn-sm">Konfirmasi</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @else
                                            <small class="text-muted">-</small>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4 small">
                                        Belum ada riwayat undangan untuk project ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
