(() => {
    'use strict';

    function initializeGithubPreview() {
        const panel = document.getElementById('github-panel');
        const form = document.getElementById('github-connection-form');
        const repoInput = document.getElementById('github-repo');
        const previewButton = document.getElementById('github-preview-btn');
        const importButton = document.getElementById('github-import-btn');
        const section = document.getElementById('github-preview-section');
        const feedback = document.getElementById('github-feedback');
        const rowsElement = document.getElementById('github-preview-rows');

        if (
            !panel || !form || !repoInput || !previewButton ||
            !importButton || !section || !feedback || !rowsElement
        ) {
            return;
        }

        const csrfToken = form.querySelector(
            'input[name="_token"]'
        )?.value;

        const previewUrl = panel.dataset.previewUrl;
        const importUrl = panel.dataset.importUrl;

        const counts = {
            total: document.getElementById('github-total-count'),
            valid: document.getElementById('github-valid-count'),
            invalid: document.getElementById('github-invalid-count'),
            imported: document.getElementById('github-imported-count'),
        };

        let busy = false;
        let hasValidPreview = false;

        function connectedRepo() {
            return (panel.dataset.connectedRepo || '').trim();
        }

        function canPreview() {
            const stored = connectedRepo();

            return stored.length > 0 &&
                repoInput.value.trim().toLowerCase() ===
                    stored.toLowerCase();
        }

        function updateButton() {
            previewButton.disabled = busy || !canPreview();
            importButton.disabled =
                busy || !hasValidPreview || !canPreview();
        }

        function showFeedback(message, type = 'info') {
            feedback.className = `alert alert-${type} mt-3`;
            feedback.textContent = message;
            feedback.setAttribute(
                'role',
                type === 'danger' ? 'alert' : 'status'
            );
        }

        function hidePreview() {
            section.classList.add('d-none');
            rowsElement.replaceChildren();

            for (const element of Object.values(counts)) {
                if (element) {
                    element.textContent = '0';
                }
            }

            hasValidPreview = false;
            importButton.disabled = true;
        }

        function getErrorMessage(data, status) {
            if (
                data &&
                data.errors &&
                typeof data.errors === 'object'
            ) {
                for (const messages of Object.values(data.errors)) {
                    if (
                        Array.isArray(messages) &&
                        typeof messages[0] === 'string'
                    ) {
                        return messages[0];
                    }
                }
            }

            if (
                data &&
                typeof data.message === 'string' &&
                data.message.length > 0
            ) {
                return data.message;
            }

            return `Gagal mengambil issue GitHub (HTTP ${status}).`;
        }

        function addCell(rowElement, value) {
            const cell = document.createElement('td');
            cell.textContent = String(value ?? '—');
            rowElement.appendChild(cell);
            return cell;
        }

        function addSourceLink(rowElement, sourceUrl) {
            const cell = addCell(rowElement, '—');

            if (typeof sourceUrl !== 'string') {
                return;
            }

            try {
                const url = new URL(sourceUrl);

                if (
                    url.origin !== 'https://github.com' ||
                    !/^\/[A-Za-z0-9-]+\/[A-Za-z0-9._-]+\/issues\/[0-9]+$/
                        .test(url.pathname)
                ) {
                    return;
                }

                const link = document.createElement('a');
                link.href = url.href;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = 'Lihat GitHub';

                cell.replaceChildren(link);
            } catch {
                // Ignore invalid external URLs.
            }
        }

        function renderPreview(preview) {
            const rows = preview.rows;

            if (!Array.isArray(rows)) {
                throw new Error('Format hasil preview tidak valid.');
            }

            counts.total.textContent = String(
                preview.total_rows ?? 0
            );
            counts.valid.textContent = String(
                preview.valid_count ?? 0
            );
            counts.invalid.textContent = String(
                preview.invalid_count ?? 0
            );
            counts.imported.textContent = String(
                preview.already_imported_count ?? 0
            );

            const fragment = document.createDocumentFragment();

            if (rows.length === 0) {
                const tr = document.createElement('tr');
                const td = document.createElement('td');

                td.colSpan = 5;
                td.className = 'text-center text-muted py-4';
                td.textContent =
                    'Belum ada issue yang ditemukan di repository ini.';

                tr.appendChild(td);
                fragment.appendChild(tr);
            }

            for (const row of rows) {
                const tr = document.createElement('tr');
                const data = row.data || {};

                const number = data.github_number ?? '—';
                const title = data.title || '(Tanpa judul)';
                const status = String(row.status || 'INVALID');

                addCell(tr, row.record_number ?? '—');
                addCell(tr, `#${number} — ${title}`);

                const statusCell = document.createElement('td');
                const badge = document.createElement('span');

                badge.className = 'badge ' + (
                    status === 'VALID'
                        ? 'bg-success'
                        : status === 'ALREADY_IMPORTED'
                            ? 'bg-secondary'
                            : 'bg-danger'
                );

                badge.textContent = status;
                statusCell.appendChild(badge);
                tr.appendChild(statusCell);

                addCell(
                    tr,
                    row.reason || (
                        status === 'VALID'
                            ? 'Siap diimpor'
                            : 'Tidak dapat diimpor'
                    )
                );

                addSourceLink(tr, data.source_url);
                fragment.appendChild(tr);
            }

            rowsElement.replaceChildren(fragment);
            section.classList.remove('d-none');

            // Impor hanya dapat dilakukan jika ada issue VALID.
            hasValidPreview =
                Number(preview.valid_count || 0) > 0;

            updateButton();
        }

        async function fetchPreview() {
            if (busy || !canPreview()) {
                return;
            }

            if (!csrfToken || !previewUrl) {
                showFeedback(
                    'Konfigurasi halaman tidak lengkap. Muat ulang halaman.',
                    'danger'
                );
                return;
            }

            busy = true;
            updateButton();
            hidePreview();

            const originalHtml = previewButton.innerHTML;
            previewButton.textContent = 'Mengambil issue...';

            showFeedback(
                'Mengambil dan memvalidasi issue dari GitHub...',
                'info'
            );

            try {
                const response = await fetch(previewUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({}),
                });

                const data = await response.json().catch(
                    () => ({})
                );

                if (!response.ok || data.success !== true) {
                    throw new Error(
                        getErrorMessage(data, response.status)
                    );
                }

                if (!canPreview()) {
                    throw new Error(
                        'Repository berubah. Ambil ulang pratinjau.'
                    );
                }

                renderPreview(data.preview);

                const validCount = Number(
                    data.preview.valid_count || 0
                );

                showFeedback(
                    validCount > 0
                        ? `Preview berhasil: ${validCount} issue baru valid.`
                        : 'Preview berhasil, tetapi tidak ada issue baru yang valid.',
                    validCount > 0 ? 'success' : 'warning'
                );
            } catch (error) {
                hidePreview();

                showFeedback(
                    error instanceof Error
                        ? error.message
                        : 'Terjadi kesalahan saat mengambil issue.',
                    'danger'
                );
            } finally {
                previewButton.innerHTML = originalHtml;
                busy = false;
                updateButton();
            }
        }


        async function confirmImport() {
            if (busy || !hasValidPreview || !canPreview()) {
                return;
            }

            if (!csrfToken || !importUrl) {
                showFeedback(
                    'Konfigurasi halaman tidak lengkap. Muat ulang halaman.',
                    'danger'
                );
                return;
            }

            const approved = window.confirm(
                'Impor issue GitHub yang berstatus VALID ke DupIssue? '
                + 'Issue INVALID dan ALREADY_IMPORTED akan dilewati.'
            );

            if (!approved) {
                return;
            }

            busy = true;
            updateButton();

            const originalHtml = importButton.innerHTML;
            importButton.textContent = 'Mengimpor issue...';

            showFeedback(
                'Memverifikasi ulang GitHub dan menyimpan issue...',
                'info'
            );

            try {
                const response = await fetch(importUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        confirmed: true,
                    }),
                });

                const data = await response.json().catch(
                    () => ({})
                );

                if (!response.ok || data.success !== true) {
                    // Session atau data preview tidak lagi valid.
                    // Pengguna harus mengambil preview baru.
                    if (
                        response.status === 409 ||
                        response.status === 422
                    ) {
                        hidePreview();
                    }

                    throw new Error(
                        getErrorMessage(data, response.status)
                    );
                }

                const importedCount = Number(
                    data.result?.imported_count ?? 0
                );

                // Preview sudah digunakan oleh proses impor.
                hidePreview();

                showFeedback(
                    `Impor berhasil: ${importedCount} issue GitHub `
                    + 'tersimpan. Klik Ambil Issue untuk '
                    + 'memeriksa status terbaru.',
                    'success'
                );
            } catch (error) {
                showFeedback(
                    error instanceof Error
                        ? error.message
                        : 'Terjadi kesalahan saat mengimpor issue.',
                    'danger'
                );
            } finally {
                importButton.innerHTML = originalHtml;
                busy = false;
                updateButton();
            }
        }

        repoInput.addEventListener('input', () => {
            hidePreview();
            updateButton();
        });

        panel.addEventListener(
            'github:connection-saved',
            (event) => {
                if (typeof event.detail?.repo === 'string') {
                    panel.dataset.connectedRepo = event.detail.repo;
                    repoInput.value = event.detail.repo;
                }

                hidePreview();
                updateButton();
            }
        );

        previewButton.addEventListener(
            'click',
            fetchPreview
        );

        importButton.addEventListener(
            'click',
            confirmImport
        );

        hidePreview();
        updateButton();
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initializeGithubPreview
        );
    } else {
        initializeGithubPreview();
    }
})();
