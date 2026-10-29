@extends('layouts.app')

@section('title', 'Undangan Bergabung Project - DupIssue')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="h4 fw-bold mb-1">Undangan Bergabung Project</h3>
                <p class="text-muted small mb-0">Tanggapi undangan dari Maintainer untuk mendapatkan akses pelaporan issue pada project.</p>
            </div>
            <a href="/projects" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Project
            </a>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h5 class="h6 fw-bold mb-0">
                    <i class="bi bi-envelope-open text-primary me-2"></i> Daftar Undangan Masuk
                </h5>
                <span class="badge bg-primary-subtle text-primary">{{ $invitations->count() }} Menunggu Respon</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small">
                            <tr>
                                <th>Nama Project</th>
                                <th>Pengundang (Maintainer)</th>
                                <th>Tanggal Undangan</th>
                                <th>Deskripsi Project</th>
                                <th class="text-end">Tanggapan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($invitations as $invitation)
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark">{{ $invitation->project->name }}</div>
                                        <span class="badge bg-warning text-dark small invitation_status" id="invitation_status">Pending</span>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold">{{ $invitation->inviter->name ?? 'Maintainer' }}</div>
                                        <div class="text-muted small">{{ $invitation->inviter->email }}</div>
                                    </td>
                                    <td>
                                        <small class="text-muted">{{ $invitation->created_at->format('d M Y, H:i') }}</small>
                                    </td>
                                    <td>
                                        <small class="text-secondary text-truncate d-inline-block" style="max-width: 250px;">
                                            {{ $invitation->project->description ?: '-' }}
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-2">
                                            <form action="/invitations/{{ $invitation->id }}/respond" method="POST">
                                                @csrf
                                                <input type="hidden" name="action" value="accept">
                                                <button type="submit" class="btn btn-success btn-sm">
                                                    <i class="bi bi-check-lg me-1"></i> Terima
                                                </button>
                                            </form>

                                            <form action="/invitations/{{ $invitation->id }}/respond" method="POST">
                                                @csrf
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                                    <i class="bi bi-x-lg me-1"></i> Tolak
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-5 small">
                                        <i class="bi bi-inbox display-6 text-muted mb-2 d-block"></i>
                                        Tidak ada undangan yang menunggu respon saat ini.
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
