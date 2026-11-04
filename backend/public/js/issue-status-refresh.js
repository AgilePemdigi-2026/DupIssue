/* REQ-02.7/02.8: read-only, same-origin status refresh. No mutation endpoints. */
(() => {
    "use strict";

    function setup() {
        const section = document.getElementById("issue-status-detail");
        const refresh = document.getElementById("issue-status-refresh");
        const retry = document.getElementById("issue-status-retry");
        const feedback = document.getElementById("issue-status-feedback");
        const stale = document.getElementById("issue-status-stale");
        const history = document.getElementById("issue-status-history-list");
        if (!section || !refresh || !retry || !feedback || !stale || !history) return;

        const url = section.dataset.readUrl;
        if (!url) return;
        let inFlight = false;

        function renderHistory(items) {
            if (!Array.isArray(items)) throw new Error("Invalid history response");
            const fragment = document.createDocumentFragment();
            if (!items.length) {
                const empty = document.createElement("li");
                empty.className = "list-group-item text-muted";
                empty.textContent = "Belum ada riwayat perubahan status.";
                fragment.append(empty);
            }
            for (const item of items.slice(0, 20)) {
                if (!item || typeof item.action !== "string") {
                    throw new Error("Invalid history entry");
                }
                const entry = document.createElement("li");
                entry.className = "list-group-item";
                const action = document.createElement("strong");
                action.textContent = item.action;
                entry.append(action, document.createTextNode(` · ${String(item.created_at ?? "")}`));
                if (item.reason) {
                    const reason = document.createElement("p");
                    reason.className = "mb-0";
                    reason.textContent = String(item.reason);
                    entry.append(reason);
                }
                fragment.append(entry);
            }
            history.replaceChildren(fragment);
        }

        async function reload() {
            if (inFlight) return;
            inFlight = true;
            refresh.disabled = true;
            retry.disabled = true;
            feedback.className = "small text-muted mb-1";
            feedback.textContent = "Memuat status terbaru...";
            try {
                const response = await fetch(url, {
                    method: "GET",
                    credentials: "same-origin",
                    headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" },
                    cache: "no-store"
                });
                if (response.status === 401 || response.status === 403 || response.status === 404) {
                    throw new Error("Issue tidak tersedia");
                }
                if (!response.ok) throw new Error("Refresh request failed");
                const data = await response.json();
                if (typeof data.status !== "string" ||
                    typeof data.detection_status !== "string" ||
                    !Number.isSafeInteger(data.vote_count) ||
                    !Array.isArray(data.history)) {
                    throw new Error("Invalid status payload");
                }
                // Build/validate the history before updating the view so malformed
                // responses do not leave half-updated status fields.
                renderHistory(data.history);
                document.getElementById("issue-status-value").textContent = data.status;
                document.getElementById("issue-detection-status-value").textContent = data.detection_status;
                document.getElementById("issue-vote-count-value").textContent = String(data.vote_count);
                feedback.className = "small text-success mb-1";
                feedback.textContent = "Status telah diperbarui.";
                stale.classList.add("d-none");
                retry.classList.add("d-none");
            } catch (err) {
                // Keep the old values visible but explicitly mark them as stale.
                feedback.className = "small text-danger mb-1";
                feedback.textContent = err.message === "Issue tidak tersedia"
                    ? "Issue tidak tersedia" : "Gagal memuat status";
                stale.classList.remove("d-none");
                retry.classList.remove("d-none");
            } finally {
                inFlight = false;
                refresh.disabled = false;
                retry.disabled = false;
            }
        }
        refresh.addEventListener("click", reload);
        retry.addEventListener("click", reload);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", setup);
    } else {
        setup();
    }
})();
