<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Issue - DupIssue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0">Daftar Issue (Project #{{ $projectId }})</h3>
        <div class="d-flex gap-2">
            <a href="{{ route('issues.create', ['project' => $projectId]) }}" class="btn btn-primary btn-sm" style="background-color: #5b46f5;">
                + Submit Issue
            </a>
            @include('partials.logout-button')
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            @if($issues->isEmpty())
                <p class="text-muted mb-0">Issue tidak tersedia.</p>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Judul Issue</th>
                                <th>Status</th>
                                <th>Deteksi NLP</th>
                                <th>Vote</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($issues as $item)
                                <tr>
                                    <td>ISS-{{ $item->id }}</td>
                                    <td>{{ $item->title }}</td>
                                    <td><span class="badge bg-primary">{{ $item->status }}</span></td>
                                    <td><span class="badge bg-warning text-dark">{{ $item->detection_status }}</span></td>
                                    <td>{{ $item->vote_count }} vote</td>
                                    <td>
                                        <a href="{{ route('issues.show', ['project' => $projectId, 'issue' => $item->id]) }}" class="btn btn-sm btn-outline-secondary">
                                            Lihat
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
</body>
</html>