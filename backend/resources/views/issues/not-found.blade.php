@extends('layouts.app')

@section('title', 'Issue tidak tersedia — DupIssue')

@section('content')
<div class="card shadow-sm border-0">
    <div class="card-body p-4">
        <h1 class="h4">Issue tidak tersedia</h1>
        <p>Issue yang diminta tidak ditemukan atau tidak dapat diakses dari project ini.</p>
        <a class="btn btn-primary" href="{{ route('issues.index', ['project' => $project]) }}">Kembali ke Daftar</a>
    </div>
</div>
@endsection
