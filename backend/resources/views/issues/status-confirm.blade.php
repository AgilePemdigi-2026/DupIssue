@extends('layouts.app')

@section('title', 'Konfirmasi Perubahan Status — DupIssue')

@section('content')
<div class="card shadow-sm border-0">
    <div class="card-body p-4">
        <h1 class="h4">Konfirmasi Perubahan Status</h1>
        <p><strong>Issue #{{ $issue->id }}</strong> — {{ $issue->title }}</p>
        <p>Status saat ini: <strong>{{ $issue->status }}</strong></p>
        <p>Status tujuan: <strong>{{ $target }}</strong></p>

        @if ($target === 'RESOLVED')
            <div class="alert alert-warning" role="status">
                Jumlah duplikat tertaut yang akan ditutup: <strong>{{ $autoCloseCount }}</strong>.
                Semua perubahan dilakukan dalam satu transaksi.
            </div>
        @elseif (in_array($issue->status, ['RESOLVED', 'CLOSED'], true))
            <div class="alert alert-info" role="status">
                Membuka kembali issue induk tidak membuka kembali duplikat lama. Duplikat lama tetap CLOSED.
            </div>
        @endif

        @if (!empty($saveError))
            <div class="alert alert-danger" role="alert">{{ $saveError }}</div>
        @endif

        <form action="{{ route('issues.status.confirm', ['project' => $project, 'issue' => $issue]) }}" method="POST" class="d-inline-block">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit" class="btn btn-primary">{{ !empty($saveError) ? 'Coba Lagi' : 'Konfirmasi' }}</button>
        </form>
        <a class="btn btn-outline-secondary" href="{{ route('issues.show', ['project' => $project, 'issue' => $issue]) }}">Batal</a>
    </div>
</div>
@endsection
