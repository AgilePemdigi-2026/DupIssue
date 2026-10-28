<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Submit Issue - DupIssue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container-fluid">
    <div class="row min-vh-100">
        <!-- Sidebar Kiri sesuai Mockup -->
        <aside class="col-md-3 col-lg-2 bg-white border-end d-flex flex-column justify-content-between p-3">
            <div>
                <div class="mb-4">
                    <h5 class="fw-bold text-primary mb-0" style="color: #5b46f5 !important;">DupIssue</h5>
                    <small class="text-muted">Reporter Portal</small>
                </div>

                <div class="text-uppercase text-muted small fw-bold mb-2">Active Project</div>
                <div class="p-2 rounded bg-light border mb-3">
                    <div class="fw-semibold small">Project #{{ $projectId ?? 1 }}</div>
                    <small class="text-muted">Akses: Reporter</small>
                </div>

                <ul class="nav nav-pills flex-column gap-1">
                    <li class="nav-item">
                        <a href="{{ route('issues.index', ['project' => $projectId ?? 1]) }}" class="nav-link text-dark">
                            Daftar Issue
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('issues.create', ['project' => $projectId ?? 1]) }}" class="nav-link active" style="background-color: #5b46f5;">
                            + Submit Issue
                        </a>
                    </li>
                </ul>
            </div>

            <div class="border-top pt-3 d-flex justify-content-between align-items-center">
                <div class="small">
                    <div class="fw-semibold">{{ auth()->user()->name ?? 'Reporter' }}</div>
                    <span class="text-muted">Reporter</span>
                </div>
                @include('partials.logout-button')
            </div>
        </aside>

        <!-- Konten Utama Kanan -->
        <main class="col-md-9 col-lg-10 p-4">
            <!-- Topbar Breadcrumb -->
            <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0 small">
                        <li class="breadcrumb-item"><a href="{{ route('issues.index', ['project' => $projectId ?? 1]) }}" class="text-decoration-none">Daftar Project</a></li>
                        <li class="breadcrumb-item">Project #{{ $projectId ?? 1 }}</li>
                        <li class="breadcrumb-item active" aria-current="page">Submit Issue</li>
                    </ol>
                </nav>
                <span class="badge bg-success-subtle text-success">Online</span>
            </div>

            <!-- Judul Halaman -->
            <div class="mb-4">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="fw-bold mb-0">Submit Issue</h2>
                    <span class="badge rounded-pill" style="background-color: #ede9fe; color: #5b46f5;">
                        &bull; Live Semantic Deduplication
                    </span>
                </div>
                <p class="text-muted mt-1 mb-0">
                    Laporkan bug atau kendala teknis baru. Sistem AI Semantic Similarity secara otomatis mendeteksi issue serupa untuk mencegah duplikasi laporan.
                </p>
            </div>

            <div class="row g-4">
                <!-- PANEL KIRI: Formulir Laporan Baru -->
                <div class="col-lg-7">
                    <div class="card shadow-sm border-0">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="card-title fw-bold mb-0">Formulir Laporan Baru</h5>
                                <span class="badge bg-light text-secondary border">DRAFT AUTO-SAVED</span>
                            </div>
                            <p class="text-muted small mb-4">
                                Pastikan rincian langkah reproduksi bug ditulis dengan jelas.
                            </p>

                            @if($errors->any())
                                <div class="alert alert-danger" id="error">
                                    <ul class="mb-0 small">
                                        @foreach($errors->all() as $err)
                                            <li>{{ $err }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <form action="{{ route('issues.store', ['project' => $projectId ?? 1]) }}" method="POST" id="submit-issue-form">
                                @csrf

                                <!-- Kolom Judul Issue (5-200 Karakter) -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between">
                                        <label for="title" class="form-label fw-semibold">Judul Issue <span class="text-danger">*</span></label>
                                        <small class="text-muted"><span id="title-count">0</span> / 200 karakter (Min. 5)</small>
                                    </div>
                                    <input
                                        type="text"
                                        class="form-control @error('title') is-invalid @enderror"
                                        id="title"
                                        name="title"
                                        value="{{ old('title') }}"
                                        placeholder="Tombol Bayar tidak merespon di halaman pembayaran"
                                    >
                                    <div class="form-text">Gunakan kalimat spesifik seperti modul, komponen, atau respon sistem yang tidak diharapkan.</div>
                                    @error('title')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Kolom Deskripsi Lengkap (10-10.000 Karakter) -->
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between">
                                        <label for="description" class="form-label fw-semibold">Deskripsi Lengkap <span class="text-danger">*</span></label>
                                        <small class="text-muted"><span id="desc-count">0</span> / 10.000 karakter (Min. 10)</small>
                                    </div>
                                    <textarea
                                        class="form-control @error('description') is-invalid @enderror"
                                        id="description"
                                        name="description"
                                        rows="6"
                                        placeholder="Ketika memilih metode pembayaran transfer bank BCA dan menekan tombol Konfirmasi Bayar, animasi loading tidak muncul dan halaman tidak merespon sama sekali."
                                    >{{ old('description') }}</textarea>
                                    @error('description')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Kotak Lingkungan Otomatis Terdeteksi sesuai Mockup -->
                                <div class="p-3 bg-light rounded border d-flex justify-content-between align-items-center mb-4">
                                    <div class="small">
                                        <div class="fw-semibold">Lingkungan Otomatis Terdeteksi</div>
                                        <span class="text-muted">Web Browser Client &bull; Project ID #{{ $projectId ?? 1 }}</span>
                                    </div>
                                    <span class="badge bg-secondary-subtle text-secondary">Auto-Attached</span>
                                </div>

                                <!-- Tombol Batal & Kirim Issue -->
                                <div class="d-flex justify-content-between align-items-center">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#cancelReportModal">
                                        Batal
                                    </button>
                                    <button type="submit" class="btn btn-primary px-4" style="background-color: #5b46f5; border-color: #5b46f5;">
                                        Kirim Issue &rarr;
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Kotak Tips Pelaporan Akurat -->
                    <div class="card border-0 bg-warning-subtle mt-3">
                        <div class="card-body p-3">
                            <h6 class="fw-semibold mb-1">💡 Tips Pelaporan Akurat</h6>
                            <p class="small text-muted mb-0">
                                Jika salah satu issue di panel rekomendasi sama persis dengan masalah yang Anda temukan, Anda disarankan untuk membuka issue tersebut dan memberikan vote (<strong>+1</strong>) daripada membuat laporan duplikat baru.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- PANEL KANAN: Rekomendasi Issue Serupa -->
                <div class="col-lg-5">
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-body p-4" id="rekomendasi">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="card-title fw-bold mb-0">✨ Rekomendasi Issue Serupa</h5>
                                <span class="badge rounded-pill" style="background-color: #ede9fe; color: #5b46f5;">AI NLP (Aktif)</span>
                            </div>
                            <p class="text-muted small mb-3" id="recommendation-subtitle">
                                Berdasarkan analisis semantik judul dan deskripsi Anda pada project yang sama.
                            </p>

                            <!-- Indikator Loading saat Debounce -->
                            <div id="recommendation-loading" class="text-center py-3 d-none">
                                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                                <span class="small text-muted">Memeriksa kemiripan semantik...</span>
                            </div>

                            <!-- Daftar Kartu Rekomendasi Dinamis (Maks. 3) -->
                            <div id="recommendation-list"></div>

                            <!-- Kondisi Default / Kosong (REQ-01.8) -->
                            <div id="recommendation-empty" class="p-3 bg-light rounded border text-center">
                                <span class="badge bg-success-subtle text-success mb-1">Issue Unik Baru</span>
                                <p class="small text-muted mb-0">Belum ada issue pembanding</p>
                            </div>

                            <!-- Kondisi Gangguan Layanan NLP (REQ-01.9) -->
                            <div id="recommendation-error" class="alert alert-warning small d-none mb-0 mt-2">
                                <strong>Rekomendasi belum dapat dimuat.</strong> Formulir tetap dapat dikirimkan dan diproses secara asinkron di antrean server.
                            </div>

                            <div class="alert alert-light border small mt-3 mb-0">
                                Skor kemiripan merupakan <strong>rekomendasi sistem AI</strong>, bukan keputusan otomatis duplikat. Anda tetap memegang kendali penuh untuk mengirimkan laporan baru.
                            </div>
                        </div>
                    </div>

                    <!-- Catatan Status & Kondisi Sistem sesuai Mockup -->
                    <div class="card shadow-sm border-0">
                        <div class="card-body p-3">
                            <h6 class="text-uppercase text-muted small fw-bold mb-2">Catatan Status & Kondisi Sistem</h6>
                            <div class="small mb-2">
                                <strong>Kondisi Kosong:</strong> Jika belum ada issue pembanding dalam database project, panel menampilkan indikator <em>"Belum ada issue pembanding"</em>.
                            </div>
                            <div class="small text-muted">
                                <strong>Kondisi Layanan Gangguan:</strong> Bila rekomendasi belum dapat dimuat, formulir tetap dapat dikirimkan dengan status <code>OPEN</code> dan diproses di antrean.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- MODAL 1: Lihat Detail Rekomendasi & Konfirmasi Batal Submit untuk Buka Issue Induk (REQ-01.7 & REQ-01.13) -->
<div class="modal fade" id="recommendationDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" id="modal">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Detail Issue Rekomendasi (<span id="modal-issue-code"></span>)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <span class="badge bg-primary" id="modal-issue-status">OPEN</span>
                    <span class="badge bg-info text-dark" id="modal-issue-similarity"></span>
                </div>
                <h5 class="fw-bold" id="modal-issue-title">detail issue</h5>
                <p class="text-muted" id="modal-issue-description" style="white-space: pre-line;"></p>

                <!-- Box Konfirmasi Pembatalan Laporan Baru untuk Buka Issue (REQ-01.13) -->
                <div id="confirm-open-issue-box" class="alert alert-warning d-none mt-3 mb-0">
                    <p class="fw-semibold mb-2">Konfirmasi pembatalan laporan: Apakah Anda ingin membatalkan laporan baru dan membuka issue induk ini untuk memberikan vote?</p>
                    <a href="#" id="confirm-open-issue-btn" class="btn btn-sm btn-warning fw-semibold">Ya, Buka Issue</a>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    Kembali ke Formulir
                </button>
                <button type="button" class="btn btn-primary" id="btn-trigger-open-issue" style="background-color: #5b46f5;">
                    Batal Submit dan Buka Issue
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 2: Konfirmasi Pembuangan Isian Saat Klik Batal (REQ-01.14) -->
<div class="modal fade" id="cancelReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Konfirmasi pembuangan isian</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                Apakah Anda yakin ingin membuang isian formulir dan kembali ke halaman Daftar Issue?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kembali</button>
                <a href="{{ route('issues.index', ['project' => $projectId ?? 1]) }}" class="btn btn-danger">
                    Ya, Batalkan
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const titleInput = document.getElementById('title');
    const descInput = document.getElementById('description');
    const titleCount = document.getElementById('title-count');
    const descCount = document.getElementById('desc-count');

    const recList = document.getElementById('recommendation-list');
    const recEmpty = document.getElementById('recommendation-empty');
    const recError = document.getElementById('recommendation-error');
    const recLoading = document.getElementById('recommendation-loading');
    const recSubtitle = document.getElementById('recommendation-subtitle');

    let debounceTimer = null;
    let latestRequestId = 0; // REQ-01.5: Mencegah respons lama menimpa respons input terbaru

    function updateCounters() {
        if (titleInput && titleCount) titleCount.textContent = titleInput.value.length;
        if (descInput && descCount) descCount.textContent = descInput.value.length;
    }

    function fetchRecommendations() {
        const title = titleInput.value.trim();
        const description = descInput.value.trim();

        if (title.length < 5 && description.length < 10) {
            return;
        }

        const currentRequestId = ++latestRequestId;
        recLoading.classList.remove('d-none');
        recError.classList.add('d-none');

        fetch("{{ route('issues.recommend', ['project' => $projectId ?? 1]) }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            },
            body: JSON.stringify({ title, description })
        })
        .then(res => res.json())
        .then(data => {
            // Abaikan jika ini adalah respons dari ketikan lama (REQ-01.5)
            if (currentRequestId !== latestRequestId) return;

            recLoading.classList.add('d-none');
            recList.innerHTML = '';

            if (data.status === 'error') {
                recEmpty.classList.add('d-none');
                recError.classList.remove('d-none');
                return;
            }

            if (!data.recommendations || data.recommendations.length === 0) {
                recEmpty.classList.remove('d-none');
                recSubtitle.textContent = 'Belum ada issue pembanding';
                return;
            }

            recEmpty.classList.add('d-none');
            recSubtitle.textContent = `Ditemukan ${data.recommendations.length} rekomendasi issue yang terdeteksi mirip:`;

            data.recommendations.forEach(item => {
                const card = document.createElement('div');
                card.className = 'p-3 border rounded mb-2 bg-white';
                card.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-bold text-muted">ISS-${item.id} <span class="badge bg-secondary">${item.status}</span></span>
                        <span class="badge bg-primary">${item.similarity}% mirip</span>
                    </div>
                    <div class="fw-semibold mb-2">${item.title}</div>
                    <div class="progress mb-2" style="height: 6px;">
                        <div class="progress-bar" role="progressbar" style="width: ${item.similarity}%; background-color: #5b46f5;"></div>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary btn-view-detail"
                            data-id="${item.id}"
                            data-title="${item.title.replace(/"/g, '&quot;')}"
                            data-desc="${item.description.replace(/"/g, '&quot;')}"
                            data-status="${item.status}"
                            data-sim="${item.similarity}"
                            data-url="${item.detail_url}">
                            Lihat Detail
                        </button>
                    </div>
                `;
                recList.appendChild(card);
            });

            bindDetailButtons();
        })
        .catch(() => {
            if (currentRequestId !== latestRequestId) return;
            recLoading.classList.add('d-none');
            recEmpty.classList.add('d-none');
            recError.classList.remove('d-none');
        });
    }

    function bindDetailButtons() {
        document.querySelectorAll('.btn-view-detail').forEach(btn => {
            btn.addEventListener('click', function () {
                document.getElementById('modal-issue-code').textContent = 'ISS-' + this.dataset.id;
                document.getElementById('modal-issue-title').textContent = this.dataset.title;
                document.getElementById('modal-issue-description').textContent = this.dataset.desc;
                document.getElementById('modal-issue-status').textContent = this.dataset.status;
                document.getElementById('modal-issue-similarity').textContent = this.dataset.sim + '% mirip';
                document.getElementById('confirm-open-issue-btn').setAttribute('href', this.dataset.url);
                document.getElementById('confirm-open-issue-box').classList.add('d-none');

                const modal = new bootstrap.Modal(document.getElementById('recommendationDetailModal'));
                modal.show();
            });
        });
    }

    document.getElementById('btn-trigger-open-issue')?.addEventListener('click', function () {
        document.getElementById('confirm-open-issue-box').classList.remove('d-none');
    });

    // REQ-01.3 & TBD-01: Debounce 500 ms setelah pengetikan
    function handleTyping() {
        updateCounters();
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(fetchRecommendations, 500);
    }

    titleInput?.addEventListener('input', handleTyping);
    descInput?.addEventListener('input', handleTyping);
    updateCounters();
</script>
</body>
</html>