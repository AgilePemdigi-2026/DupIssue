<aside id="issue-recommendations-panel"
       class="card shadow-sm h-100"
       aria-labelledby="recommendations-heading">

    <div class="card-body p-4">
        <h2 id="recommendations-heading" class="h5 mb-2">
            Rekomendasi Issue Serupa
        </h2>

        <p class="text-muted small mb-3">
            Sistem akan mencari maksimal 3 issue yang memiliki
            kemiripan dengan laporan Anda.
        </p>

        <!-- Status awal, kosong, atau gagal -->
        <div id="recommendation-status"
             class="alert alert-light border text-muted small"
             role="status"
             aria-live="polite">
            Isi judul dan deskripsi untuk melihat rekomendasi.
        </div>

        <!-- Indikator proses NLP -->
        <div id="recommendation-loading"
             class="text-center py-3 d-none"
             role="status">
            <div class="spinner-border spinner-border-sm text-primary"
                 aria-hidden="true"></div>
            <span class="ms-2 small">
                Mencari issue serupa...
            </span>
        </div>

        <!-- Kartu Top 3 akan dimasukkan oleh JavaScript -->
        <div id="recommendation-list"
             class="d-none"
             aria-label="Daftar rekomendasi issue serupa">
        </div>

        <hr class="my-3">

        <p class="small text-muted mb-0">
            Skor kemiripan merupakan rekomendasi,
            bukan keputusan duplikat.
        </p>
    </div>
</aside>


<!-- Modal Detail Issue Serupa -->
<div class="modal fade"
     id="recommendation-detail-modal"
     tabindex="-1"
     aria-labelledby="recommendation-modal-heading"
     aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h2 class="modal-title fs-5"
                    id="recommendation-modal-heading">
                    Detail Issue Serupa
                </h2>
                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Tutup">
                </button>
            </div>

            <div class="modal-body">

                <!-- Tampilan detail -->
                <section id="recommendation-detail-content">
                    <p id="recommendation-detail-id"
                       class="small text-muted mb-2"></p>

                    <h3 id="recommendation-detail-title"
                        class="h5 text-break"></h3>

                    <p>
                        <strong>Status:</strong>
                        <span id="recommendation-detail-status"></span>
                    </p>

                    <p id="recommendation-detail-parent"
                       class="alert alert-warning small d-none"></p>

                    <h4 class="h6">Deskripsi Issue</h4>

                    <p id="recommendation-detail-description"
                       class="mb-0"
                       style="white-space: pre-wrap; overflow-wrap: anywhere;"></p>
                </section>

                <!-- Konfirmasi batal submit -->
                <section id="recommendation-confirm-content"
                         class="d-none">
                    <h3 class="h5">
                        Batalkan Submit Issue?
                    </h3>

                    <p>
                        Anda akan meninggalkan formulir untuk
                        membuka issue yang dipilih.
                    </p>

                    <p class="text-danger small mb-0">
                        Draft yang belum disimpan akan ditinggalkan.
                        Tidak ada issue baru yang dibuat.
                    </p>
                </section>

            </div>

            <!-- Tombol ketika melihat detail -->
            <div id="recommendation-detail-actions"
                 class="modal-footer">
                <button type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal">
                    Kembali ke Formulir
                </button>

                <button type="button"
                        id="recommendation-cancel-submit"
                        class="btn btn-primary">
                    Batal Submit dan Buka Issue
                </button>
            </div>

            <!-- Tombol ketika konfirmasi -->
            <div id="recommendation-confirm-actions"
                 class="modal-footer d-none">
                <button type="button"
                        id="recommendation-back-to-detail"
                        class="btn btn-outline-secondary">
                    Tidak, Kembali
                </button>

                <button type="button"
                        id="recommendation-open-issue"
                        class="btn btn-primary">
                    Ya, Buka Issue
                </button>
            </div>

        </div>
    </div>
</div>
