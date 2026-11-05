(() => {
    "use strict";

    function initializeRecommendations() {
        const form = document.getElementById("issue-submit-form");
        const titleInput = document.getElementById("title");
        const descriptionInput = document.getElementById("description");
        const status = document.getElementById("recommendation-status");
        const loading = document.getElementById("recommendation-loading");
        const list = document.getElementById("recommendation-list");

        if (!form || !titleInput || !descriptionInput ||
            !status || !loading || !list) {
            return;
        }

        const endpoint = form.dataset.recommendationsUrl;
        const csrfToken = form.querySelector('input[name="_token"]')?.value;

        if (!endpoint || !csrfToken) {
            status.textContent = "Rekomendasi belum dapat dimuat";
            return;
        }

        let debounceTimer = null;
        let activeController = null;
        let revision = 0;

        function showMessage(message) {
            loading.classList.add("d-none");
            list.classList.add("d-none");
            list.replaceChildren();

            status.textContent = message;
            status.classList.remove("d-none");
        }

        function showLoading() {
            status.classList.add("d-none");
            list.classList.add("d-none");
            list.replaceChildren();
            loading.classList.remove("d-none");
        }

        function validDraft(title, description) {
            const titleLength = Array.from(title).length;
            const descriptionLength = Array.from(description).length;

            return titleLength >= 5 &&
                titleLength <= 200 &&
                descriptionLength >= 10 &&
                descriptionLength <= 10000;
        }

        function openRecommendationDetail(issue) {
            const modalElement = document.getElementById(
                "recommendation-detail-modal"
            );

            if (!modalElement || !window.bootstrap?.Modal) {
                showMessage("Detail issue belum dapat dibuka");
                return;
            }

            // Semua data ditampilkan sebagai teks aman.
            document.getElementById(
                "recommendation-detail-id"
            ).textContent = `Issue #${issue.issue_id}`;

            document.getElementById(
                "recommendation-detail-title"
            ).textContent = issue.title ?? "";

            document.getElementById(
                "recommendation-detail-status"
            ).textContent = issue.status ?? "";

            document.getElementById(
                "recommendation-detail-description"
            ).textContent = issue.description ?? "";

            const parentInfo = document.getElementById(
                "recommendation-detail-parent"
            );

            const isDuplicate = String(issue.issue_id) !==
                String(issue.target_issue_id);

            if (isDuplicate) {
                parentInfo.textContent =
                    `Issue ini merupakan duplikat dari Issue #${issue.target_issue_id}.`;
                parentInfo.classList.remove("d-none");
            } else {
                parentInfo.textContent = "";
                parentInfo.classList.add("d-none");
            }

            const detailContent = document.getElementById(
                "recommendation-detail-content"
            );

            const confirmContent = document.getElementById(
                "recommendation-confirm-content"
            );

            const detailActions = document.getElementById(
                "recommendation-detail-actions"
            );

            const confirmActions = document.getElementById(
                "recommendation-confirm-actions"
            );

            function showConfirmation(confirming) {
                detailContent.classList.toggle("d-none", confirming);
                detailActions.classList.toggle("d-none", confirming);

                confirmContent.classList.toggle("d-none", !confirming);
                confirmActions.classList.toggle("d-none", !confirming);
            }

            // Setiap modal dibuka, mulai dari tampilan detail.
            showConfirmation(false);

            document.getElementById(
                "recommendation-cancel-submit"
            ).onclick = () => {
                showConfirmation(true);
            };

            document.getElementById(
                "recommendation-back-to-detail"
            ).onclick = () => {
                showConfirmation(false);
            };

            document.getElementById(
                "recommendation-open-issue"
            ).onclick = () => {
                try {
                    const target = new URL(
                        issue.detail_url,
                        window.location.origin
                    );

                    const recommendationUrl = new URL(
                        endpoint,
                        window.location.origin
                    );

                    const issuePathPrefix =
                        recommendationUrl.pathname.replace(
                            /recommendations\/?$/,
                            ""
                        );

                    // Hanya izinkan halaman issue pada project yang sama.
                    if (
                        target.origin !== window.location.origin ||
                        !target.pathname.startsWith(issuePathPrefix) ||
                        !/^\/projects\/\d+\/issues\/\d+\/?$/.test(
                            target.pathname
                        )
                    ) {
                        throw new Error("Invalid issue URL");
                    }

                    // Navigasi hanya setelah konfirmasi eksplisit.
                    // Tidak melakukan submit formulir.
                    window.location.assign(target.href);
                } catch (error) {
                    window.alert("Halaman issue tidak dapat dibuka.");
                }
            };

            window.bootstrap.Modal
                .getOrCreateInstance(modalElement)
                .show();
        }

        function renderRecommendations(recommendations) {
            if (!Array.isArray(recommendations)) {
                throw new Error("Invalid recommendation response");
            }

            if (recommendations.length === 0) {
                showMessage("Belum ada issue pembanding");
                return;
            }

            const fragment = document.createDocumentFragment();

            recommendations.slice(0, 3).forEach((issue) => {
                if (typeof issue.title !== "string" ||
                    typeof issue.similarity !== "number" ||
                    !Number.isFinite(issue.similarity) ||
                    typeof issue.cosine_similarity !== "number" ||
                    !Number.isFinite(issue.cosine_similarity)) {
                    throw new Error("Invalid recommendation item");
                }

                const percentage = Math.max(
                    0,
                    Math.min(100, issue.similarity * 100)
                );

                const formattedPercentage = new Intl.NumberFormat(
                    "id-ID",
                    { maximumFractionDigits: 2 }
                ).format(percentage);

                const card = document.createElement("article");
                card.className = "border rounded-3 p-3 mb-3 bg-white";

                const header = document.createElement("div");
                header.className =
                    "d-flex justify-content-between align-items-center gap-2 mb-2";

                const issueId = document.createElement("span");
                issueId.className = "small text-muted";
                issueId.textContent = `Issue #${issue.issue_id}`;

                const badge = document.createElement("span");
                badge.className = "badge text-bg-secondary";
                badge.textContent = String(issue.status);

                header.append(issueId, badge);

                const title = document.createElement("h3");
                title.className = "h6 mb-2 text-break";
                title.textContent = issue.title;

                card.append(header, title);

                if (issue.target_issue_id !== issue.issue_id) {
                    const parent = document.createElement("p");
                    parent.className = "small text-muted mb-2";
                    parent.textContent =
                        `Duplikat dari #${issue.target_issue_id}`;
                    card.append(parent);
                }

                const score = document.createElement("div");
                score.className =
                    "d-flex justify-content-between small mb-1";

                const scoreLabel = document.createElement("span");
                scoreLabel.textContent = "Sinyal kemiripan positif";

                const scoreValue = document.createElement("strong");
                scoreValue.textContent = `${formattedPercentage}%`;

                score.append(scoreLabel, scoreValue);

                const progress = document.createElement("div");
                progress.className = "progress";
                progress.style.height = "8px";

                const bar = document.createElement("div");
                bar.className = "progress-bar";
                bar.style.width = `${percentage}%`;
                bar.setAttribute("role", "progressbar");
                bar.setAttribute("aria-valuemin", "0");
                bar.setAttribute("aria-valuemax", "100");
                bar.setAttribute(
                    "aria-valuenow",
                    String(percentage)
                );
                bar.setAttribute(
                    "aria-label",
                    `Kemiripan ${formattedPercentage} persen`
                );

                progress.append(bar);

                const detailButton = document.createElement("button");
                detailButton.type = "button";
                detailButton.className =
                    "btn btn-sm btn-outline-primary mt-3";
                detailButton.textContent = "Lihat Detail";

                detailButton.addEventListener("click", () => {
                    openRecommendationDetail(issue);
                });

                const rawScore = document.createElement("p");
                rawScore.className = "small text-muted mt-2 mb-0";
                const signedCosine = new Intl.NumberFormat(
                    "id-ID",
                    { maximumFractionDigits: 4 }
                ).format(issue.cosine_similarity);
                rawScore.textContent = `Cosine mentah: ${signedCosine}`;

                card.append(score, progress, rawScore, detailButton);
                fragment.append(card);

            });

            loading.classList.add("d-none");
            status.classList.add("d-none");

            list.replaceChildren(fragment);
            list.classList.remove("d-none");
        }

        async function requestRecommendations(
            title,
            description,
            requestRevision
        ) {
            if (requestRevision !== revision) return;

            const controller = new AbortController();
            activeController = controller;
            showLoading();

            try {
                const response = await fetch(endpoint, {
                    method: "POST",
                    credentials: "same-origin",
                    signal: controller.signal,
                    headers: {
                        "Accept": "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken,
                        "X-Requested-With": "XMLHttpRequest"
                    },
                    body: JSON.stringify({ title, description })
                });

                if (requestRevision !== revision) return;

                if ([401, 403, 419].includes(response.status)) {
                    showMessage(
                        "Sesi atau akses tidak tersedia. Periksa kembali akun Anda."
                    );
                    return;
                }

                if (response.status === 429) {
                    showMessage(
                        "Permintaan terlalu sering. Coba lagi sebentar."
                    );
                    return;
                }

                if (response.status === 422) {
                    showMessage(
                        "Periksa kembali panjang judul dan deskripsi."
                    );
                    return;
                }

                if (!response.ok) {
                    showMessage("Rekomendasi belum dapat dimuat");
                    return;
                }

                const data = await response.json();

                if (requestRevision !== revision) return;

                renderRecommendations(data.recommendations);
            } catch (error) {
                if (error.name === "AbortError") return;

                if (requestRevision === revision) {
                    showMessage("Rekomendasi belum dapat dimuat");
                }
            } finally {
                if (activeController === controller) {
                    activeController = null;
                }
            }
        }

        function handleInput() {
            // Batalkan timer dan request dari draft sebelumnya.
            revision += 1;
            window.clearTimeout(debounceTimer);

            if (activeController) {
                activeController.abort();
                activeController = null;
            }

            const title = titleInput.value.trim();
            const description = descriptionInput.value.trim();

            if (!validDraft(title, description)) {
                showMessage(
                    "Isi judul dan deskripsi untuk melihat rekomendasi."
                );
                return;
            }

            showMessage("Menunggu rekomendasi...");

            const requestRevision = revision;

            debounceTimer = window.setTimeout(() => {
                requestRecommendations(
                    title,
                    description,
                    requestRevision
                );
            }, 500);
        }

        titleInput.addEventListener("input", handleInput);
        descriptionInput.addEventListener("input", handleInput);

        // Submit Issue tetap berjalan normal, tanpa menunggu NLP.
        form.addEventListener("submit", () => {
            revision += 1;
            window.clearTimeout(debounceTimer);
            activeController?.abort();
        });

        // Mendukung old() ketika kembali dari validasi Laravel.
        if (titleInput.value || descriptionInput.value) {
            handleInput();
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener(
            "DOMContentLoaded",
            initializeRecommendations
        );
    } else {
        initializeRecommendations();
    }
})();
