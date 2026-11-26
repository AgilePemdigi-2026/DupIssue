import { API_ORIGIN } from "./config.mjs";
import { safeDetail, id } from "./lib/contracts.mjs";
const params = new URL(location.href).searchParams;
const tabId = Number(params.get("tab"));
const panelMode = params.get("mode") === "sidepanel";
const panelWindowId = Number(params.get("window"));
let panelActive = true;
let disconnected = false;
const port = id(tabId)
  ? chrome.runtime.connect({ name: "workspace:" + tabId })
  : null;
const el = (name) => document.getElementById(name);
const labels = {
  DISABLED: "Rekomendasi belum diaktifkan.",
  WAITING_DRAFT: "Isi draft pada tab GitHub yang terhubung.",
  WAITING: "Menunggu perubahan draft...",
  LOADING: "Mencari issue serupa...",
  INVALID_DRAFT:
    "Judul harus 5–200 karakter dan deskripsi 10–10.000 karakter. Form yang belum didukung tidak dikirim.",
  READY: "Rekomendasi issue serupa",
  EMPTY: "Tidak ditemukan issue serupa yang memenuhi batas ambang 75%.",
  UNAUTHORIZED_REPOSITORY:
    "Tidak ada project yang dapat Anda akses untuk repository ini.",
  UNSUPPORTED_PAGE:
    "Buka halaman New Issue GitHub, lalu buka kembali ruang rekomendasi.",
  HTTP_401: "Sesi berakhir atau dicabut. Hubungkan kembali melalui popup.",
  UNAUTHENTICATED: "Hubungkan akun melalui popup.",
  HTTP_403: "Akses tidak tersedia. Periksa membership dan repository.",
  HTTP_429: "Batas permintaan tercapai. Tunggu sebentar, lalu coba lagi.",
  HTTP_503: "Layanan rekomendasi belum tersedia.",
  NETWORK_ERROR: "Koneksi gagal. Coba kembali.",
  TIMEOUT: "Permintaan melewati batas waktu.",
  TARGET_CHANGED: "Parent issue berubah. Muat ulang rekomendasi.",
};
let busy = false,
  version = 0,
  lastRender = "";
port?.onDisconnect.addListener(() => {
  disconnected = true;
  version++;
  busy = false;
  clearPanel("Koneksi panel terputus. Buka kembali panel.");
});

function clearPanel(message) {
  version++;
  busy = false;
  lastRender = "";

  el("results").replaceChildren();
  el("project").replaceChildren();
  el("project").dataset.options = "";
  el("repository").textContent = "GitHub New Issue";
  el("enable").hidden = true;
  el("disable").hidden = true;
  el("retry").hidden = true;
  el("status").dataset.state = "DISABLED";
  el("status").textContent = message;
}

if (panelMode) {
  chrome.tabs.onActivated.addListener((event) => {
    if (event.windowId !== panelWindowId) return;

    panelActive = event.tabId === tabId;

    clearPanel(
      panelActive
        ? "Memeriksa kembali akses project..."
        : "Kembali ke tab GitHub yang terhubung."
    );

    if (panelActive && !disconnected) run("resolve");
  });

  chrome.tabs.onUpdated.addListener((changedId, change) => {
    if (changedId !== tabId) return;

    if (change.url || change.status === "loading") {
      clearPanel("Halaman berubah. Memeriksa kembali akses...");
    }

    if (
      panelActive &&
      !disconnected &&
      (change.status === "complete" ||
        (change.url && change.status !== "loading"))
    ) {
      run("resolve");
    }
  });
}
function showError(code) {
  el("status").dataset.state = "ERROR";
  el("results").replaceChildren();
  lastRender = "";
  el("status").textContent =
    labels[code] ??
    "Permintaan tidak dapat diproses. Periksa akses dan coba lagi.";
  el("retry").hidden = false;
}
function render(state) {
  el("repository").textContent = state.repository;
  el("status").dataset.state = state.status;
  el("status").textContent =
    state.status === "READY"
      ? state.recommendations.length +
        " rekomendasi issue serupa · Threshold ≥ 75%"
      : labels[state.status] ??
        "Rekomendasi tidak tersedia. Coba lagi.";
  const options = JSON.stringify(state.projects);
  if (el("project").dataset.options !== options) {
    el("project").replaceChildren();
    const empty = document.createElement("option");
    empty.value = "";
    empty.textContent = "Pilih project";
    el("project").append(empty);
    for (const p of state.projects) {
      const o = document.createElement("option");
      o.value = String(p.id);
      o.textContent = p.name;
      el("project").append(o);
    }
    el("project").dataset.options = options;
  }
  el("project").value = state.projectId ?? "";
  el("enable").hidden = state.enabled;
  el("disable").hidden = !state.enabled;
  el("enable").disabled = !state.projectId;
  el("retry").hidden = [
    "READY",
    "EMPTY",
    "DISABLED",
    "WAITING",
    "WAITING_DRAFT",
    "LOADING",
    "INVALID_DRAFT",
  ].includes(state.status);
  const key = JSON.stringify([state.projectId, state.recommendations]);
  if (lastRender === key) return;
  lastRender = key;
  el("results").replaceChildren();
  for (const row of state.recommendations.slice(0, 3)) {
    const card = document.createElement("article");
    card.className = "card";
    const title = document.createElement("h3");
    title.textContent = row.title;
    const status = document.createElement("p");
    status.textContent =
      "#" +
      row.issue_id +
      " · " +
      row.status +
      (row.target_issue_id !== row.issue_id
        ? " · Parent #" + row.target_issue_id
        : "");
    const score = document.createElement("p");
    score.className = "score";
    score.textContent =
      "Kemiripan: " +
      new Intl.NumberFormat("id-ID", {
        style: "percent",
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(row.similarity);

    score.title =
      "Cosine signed: " + row.cosine_similarity.toFixed(4);

    const progress = document.createElement("progress");
    progress.className = "similarity-progress";
    progress.max = 1;
    progress.value = row.similarity;
    const description = document.createElement("p");
    description.textContent = row.description.slice(0, 400);
    const link = document.createElement("a");
    link.className = "button secondary detail-button";
    const href = safeDetail(
      row.detail_url,
      state.projectId,
      row.target_issue_id,
      API_ORIGIN,
    );
    if (href) {
      link.href = href;
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      link.textContent = "Lihat detail";
    } else {
      link.hidden = true;
    }
    const vote = document.createElement("button");
    vote.className = row.vote.voted
      ? "vote-button secondary"
      : "vote-button";
    vote.textContent =
      (row.vote.voted ? "Unvote" : "Vote Issue") + " · " + row.vote.vote_count;
    vote.addEventListener("click", () =>
      run(row.vote.voted ? "unvote" : "vote", { issueId: row.issue_id }),
    );
    const actions = document.createElement("div");
    actions.className = "card-actions";
    actions.append(link, vote);

    card.append(
      status,
      title,
      score,
      progress,
      description,
      actions,
    );
    el("results").append(card);
  }
}
async function run(action, extra = {}, quiet = false) {
  if (
    busy ||
    disconnected ||
    !panelActive ||
    !id(tabId)
  ) return;
  busy = true;
  const request = ++version;
  try {
    const response = await chrome.runtime.sendMessage({
      action,
      tabId,
      ...extra,
    });
    if (request !== version) return;
    if (!response?.ok) throw new Error(response?.error ?? "UNAVAILABLE");
    render(response.data);
  } catch (e) {
    if (request === version) showError(e.message);
  } finally {
    if (request === version) busy = false;
  }
}
el("enable").addEventListener("click", () =>
  run("enable", { projectId: Number(el("project").value) }),
);
el("disable").addEventListener("click", () => run("disable"));
el("project").addEventListener("change", () =>
  run("selectProject", { projectId: Number(el("project").value) }),
);
el("resolve").addEventListener("click", () => run("resolve"));
el("retry").addEventListener("click", () => run("retry"));
if (id(tabId)) {
  run("resolve");
  let refreshAt = Date.now() + 15000;
  setInterval(() => {
    const refresh = Date.now() >= refreshAt;
    if (refresh) refreshAt = Date.now() + 15000;
    run(refresh ? "view" : "snapshot", {}, true);
  }, 500);
} else showError("UNSUPPORTED_PAGE");
