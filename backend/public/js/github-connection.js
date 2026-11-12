(() => {
    'use strict';

    function initializeGithubConnection() {
        const panel = document.getElementById('github-panel');

        if (!panel) {
            return;
        }

        const form = document.getElementById(
            'github-connection-form'
        );
        const repoInput = document.getElementById('github-repo');
        const patInput = document.getElementById('github-pat');
        const testButton = document.getElementById(
            'github-test-btn'
        );
        const saveButton = document.getElementById(
            'github-save-btn'
        );
        const feedback = document.getElementById(
            'github-feedback'
        );

        if (
            !form || !repoInput || !patInput ||
            !testButton || !saveButton || !feedback
        ) {
            return;
        }

        const csrfToken = form.querySelector(
            'input[name="_token"]'
        )?.value;

        const endpoints = {
            test: panel.dataset.testUrl,
            save: panel.dataset.saveUrl,
        };

        let busy = false;

        function showFeedback(message, type = 'info') {
            feedback.className = `alert alert-${type} mt-3`;
            feedback.textContent = message;
            feedback.setAttribute(
                'role',
                type === 'danger' ? 'alert' : 'status'
            );
        }

        function clearFeedback() {
            feedback.className = 'alert d-none mt-3';
            feedback.textContent = '';
        }

        function updateButtons() {
            const hasCredentials =
                repoInput.value.trim().length > 0 &&
                patInput.value.trim().length > 0;

            testButton.disabled = busy || !hasCredentials;
            saveButton.disabled = busy || !hasCredentials;
        }

        function getErrorMessage(data, status) {
            if (data && typeof data.errors === 'object'
                && data.errors !== null) {
                for (const messages of Object.values(data.errors)) {
                    if (Array.isArray(messages)
                        && typeof messages[0] === 'string') {
                        return messages[0];
                    }
                }
            }

            if (data && typeof data.message === 'string'
                && data.message.length > 0) {
                return data.message;
            }

            return `Permintaan gagal (HTTP ${status}).`;
        }

        async function sendConnectionRequest(action, button) {
            if (busy) {
                return;
            }

            const repo = repoInput.value.trim();
            const pat = patInput.value.trim();
            const endpoint = endpoints[action];

            if (!repo || !pat) {
                showFeedback(
                    'Isi repository dan Personal Access Token.',
                    'warning'
                );
                return;
            }

            if (!csrfToken || !endpoint) {
                showFeedback(
                    'Konfigurasi halaman tidak lengkap. Muat ulang halaman.',
                    'danger'
                );
                return;
            }

            busy = true;
            updateButtons();

            const originalHtml = button.innerHTML;

            button.textContent = action === 'test'
                ? 'Menguji koneksi...'
                : 'Menyimpan koneksi...';

            showFeedback(
                action === 'test'
                    ? 'Menghubungi GitHub untuk menguji koneksi...'
                    : 'Memvalidasi dan menyimpan koneksi GitHub...',
                'info'
            );

            try {
                const response = await fetch(endpoint, {
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
                        repo_full_name: repo,
                        pat: pat,
                    }),
                });

                const data = await response.json().catch(
                    () => ({})
                );

                if (!response.ok || data.success !== true) {
                    throw new Error(
                        getErrorMessage(data, response.status)
                    );
                }

                if (action === 'save') {
                    // PAT tidak lagi diperlukan di browser
                    // setelah berhasil disimpan oleh backend.
                    patInput.value = '';

                    // Informasikan bahwa konfigurasi tersimpan
                    // agar tombol Ambil Issue dapat diaktifkan.
                    repoInput.value = repo;
                    panel.dataset.connectedRepo = repo;
                    panel.dispatchEvent(
                        new CustomEvent('github:connection-saved', {
                            detail: { repo },
                        })
                    );

                    showFeedback(
                        'Koneksi GitHub berhasil disimpan. ' +
                        'Token tersimpan secara terenkripsi.',
                        'success'
                    );
                } else {
                    showFeedback(
                        'Koneksi ke repository GitHub berhasil diuji.',
                        'success'
                    );
                }
            } catch (error) {
                showFeedback(
                    error instanceof Error
                        ? error.message
                        : 'Terjadi kesalahan saat menghubungi server.',
                    'danger'
                );
            } finally {
                button.innerHTML = originalHtml;
                busy = false;
                updateButtons();
            }
        }

        // Prevent accidental form submission.
        form.addEventListener('submit', (event) => {
            event.preventDefault();
        });

        repoInput.addEventListener('input', () => {
            clearFeedback();
            updateButtons();
        });

        patInput.addEventListener('input', () => {
            clearFeedback();
            updateButtons();
        });

        testButton.addEventListener('click', () => {
            sendConnectionRequest('test', testButton);
        });

        saveButton.addEventListener('click', () => {
            sendConnectionRequest('save', saveButton);
        });

        updateButtons();
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initializeGithubConnection
        );
    } else {
        initializeGithubConnection();
    }
})();
