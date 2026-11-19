(() => {
    'use strict';

    function initializeGithubApp() {
        const operations = document.getElementById(
            'github-app-operations'
        );

        if (!operations) {
            return;
        }

        const form = document.getElementById(
            'github-app-operations-form'
        );
        const previewButton = document.getElementById(
            'github-app-preview-btn'
        );
        const importButton = document.getElementById(
            'github-app-import-btn'
        );
        const section = document.getElementById(
            'github-app-preview-section'
        );
        const feedback = document.getElementById(
            'github-app-feedback'
        );
        const rowsElement = document.getElementById(
            'github-app-preview-rows'
        );

        const counts = {
            total: document.getElementById(
                'github-app-total-count'
            ),
            valid: document.getElementById(
                'github-app-valid-count'
            ),
            invalid: document.getElementById(
                'github-app-invalid-count'
            ),
            imported: document.getElementById(
                'github-app-imported-count'
            ),
        };

        if (
            !form ||
            !previewButton ||
            !importButton ||
            !section ||
            !feedback ||
            !rowsElement ||
            Object.values(counts).some((item) => !item)
        ) {
            return;
        }

        const csrfToken = form.querySelector(
            'input[name="_token"]'
        )?.value;

        const previewUrl = operations.dataset.previewUrl;
        const importUrl = operations.dataset.importUrl;

        let busy = false;
        let hasValidPreview = false;

        function updateButtons() {
            previewButton.disabled =
                busy || !csrfToken || !previewUrl;

            importButton.disabled =
                busy ||
                !hasValidPreview ||
                !csrfToken ||
                !importUrl;
        }

        function showFeedback(message, type = 'info') {
            feedback.className =
                `alert alert-${type} mt-3`;

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
                element.textContent = '0';
            }

            hasValidPreview = false;
            updateButtons();
        }

        function getErrorMessage(data, status) {
            if (
                data &&
                typeof data.errors === 'object' &&
                data.errors !== null
            ) {
                for (const messages of Object.values(
                    data.errors
                )) {
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

            return `Permintaan gagal (HTTP ${status}).`;
        }

        async function sendRequest(url, payload = {}) {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json().catch(
                () => ({})
            );

            if (!response.ok || data.success !== true) {
                const error = new Error(
                    getErrorMessage(data, response.status)
                );

                error.status = response.status;

                throw error;
            }

            return data;
        }

        function addCell(row, value) {
            const cell = document.createElement('td');

            cell.textContent = String(value ?? '—');
            row.appendChild(cell);

            return cell;
        }

        function addSourceLink(row, sourceUrl) {
            const cell = addCell(row, '—');

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
                // URL tidak valid, tampilkan tanda kosong.
            }
        }

        function renderPreview(preview) {
            if (
                !preview ||
                !Array.isArray(preview.rows)
            ) {
                throw new Error(
                    'Format hasil preview GitHub App tidak valid.'
                );
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

            const fragment =
                document.createDocumentFragment();

            if (preview.rows.length === 0) {
                const row = document.createElement('tr');
                const cell = document.createElement('td');

                cell.colSpan = 5;
                cell.className =
                    'text-center text-muted py-4';

                cell.textContent =
                    'Belum ada issue dalam repository ini.';

                row.appendChild(cell);
                fragment.appendChild(row);
            }

            for (const issue of preview.rows) {
                const row = document.createElement('tr');
                const data = issue.data || {};
                const status = String(
                    issue.status || 'INVALID'
                );

                addCell(
                    row,
                    issue.record_number ?? '—'
                );

                addCell(
                    row,
                    `#${data.github_number ?? '—'} — ` +
                    (data.title || '(Tanpa judul)')
                );

                const statusCell =
                    document.createElement('td');
                const badge =
                    document.createElement('span');

                badge.className = 'badge ' + (
                    status === 'VALID'
                        ? 'bg-success'
                        : status === 'ALREADY_IMPORTED'
                            ? 'bg-secondary'
                            : 'bg-danger'
                );

                badge.textContent = status;
                statusCell.appendChild(badge);
                row.appendChild(statusCell);

                addCell(
                    row,
                    issue.reason ||
                    (
                        status === 'VALID'
                            ? 'Siap diimpor'
                            : 'Tidak dapat diimpor'
                    )
                );

                addSourceLink(row, data.source_url);

                fragment.appendChild(row);
            }

            rowsElement.replaceChildren(fragment);
            section.classList.remove('d-none');

            hasValidPreview =
                Number(preview.valid_count || 0) > 0;

            updateButtons();
        }

        async function fetchPreview() {
            if (busy || previewButton.disabled) {
                return;
            }

            busy = true;
            hidePreview();

            const originalHtml = previewButton.innerHTML;
            previewButton.textContent = 'Mengambil issue...';

            showFeedback(
                'Mengambil dan memvalidasi issue melalui GitHub App...',
                'info'
            );

            try {
                const data = await sendRequest(previewUrl);

                renderPreview(data.preview);

                const valid = Number(
                    data.preview.valid_count || 0
                );

                showFeedback(
                    valid > 0
                        ? `Preview berhasil: ${valid} issue baru valid.`
                        : 'Preview berhasil, tetapi tidak ada issue baru yang valid.',
                    valid > 0 ? 'success' : 'warning'
                );
            } catch (error) {
                hidePreview();

                showFeedback(
                    error instanceof Error
                        ? error.message
                        : 'Gagal mengambil issue GitHub App.',
                    'danger'
                );
            } finally {
                previewButton.innerHTML = originalHtml;
                busy = false;
                updateButtons();
            }
        }

        async function confirmImport() {
            if (
                busy ||
                !hasValidPreview ||
                importButton.disabled
            ) {
                return;
            }

            const approved = window.confirm(
                'Impor issue GitHub App yang berstatus VALID ' +
                'ke DupIssue? Issue invalid dan yang sudah ' +
                'diimpor akan dilewati.'
            );

            if (!approved) {
                return;
            }

            busy = true;
            updateButtons();

            const originalHtml = importButton.innerHTML;
            importButton.textContent = 'Mengimpor issue...';

            showFeedback(
                'Memverifikasi ulang issue dan menyimpan hasil impor...',
                'info'
            );

            try {
                const data = await sendRequest(
                    importUrl,
                    { confirmed: true }
                );

                const imported = Number(
                    data.result?.imported_count ?? 0
                );

                hidePreview();

                showFeedback(
                    `Impor berhasil: ${imported} issue tersimpan. ` +
                    'Klik Ambil Issue untuk memeriksa status terbaru.',
                    'success'
                );
            } catch (error) {
                // Jika preview tidak lagi valid, wajib ambil ulang.
                if (
                    error?.status === 409 ||
                    error?.status === 422
                ) {
                    hidePreview();
                }

                showFeedback(
                    error instanceof Error
                        ? error.message
                        : 'Gagal mengimpor issue GitHub App.',
                    'danger'
                );
            } finally {
                importButton.innerHTML = originalHtml;
                busy = false;
                updateButtons();
            }
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
        });

        previewButton.addEventListener(
            'click',
            fetchPreview
        );

        importButton.addEventListener(
            'click',
            confirmImport
        );

        hidePreview();
        updateButtons();
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initializeGithubApp
        );
    } else {
        initializeGithubApp();
    }
})();
