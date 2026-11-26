import { id, parseRepository } from "./lib/contracts.mjs";
const $ = (id) => document.getElementById(id);

let panelTabId = null;

async function preparePanel() {
  panelTabId = null;

  if (!chrome.sidePanel?.open) return;

  const [tab] = await chrome.tabs.query({
    active: true,
    currentWindow: true,
  });

  await chrome.sidePanel.setOptions({
    enabled: false,
  });

  if (
    !id(tab?.id) ||
    !id(tab?.windowId) ||
    !parseRepository(tab.url)
  ) return;

  await chrome.sidePanel.setOptions({
    tabId: tab.id,
    path:
      "workspace.html?tab=" + tab.id +
      "&mode=sidepanel&window=" + tab.windowId,
    enabled: true,
  });

  panelTabId = tab.id;
}
const messages = {
  HTTP_401: "Kode atau sesi tidak valid / telah berakhir.",
  HTTP_429: "Terlalu banyak permintaan. Tunggu sebelum mencoba lagi.",
  NETWORK_ERROR: "Tidak dapat terhubung ke DupIssue.",
  TIMEOUT: "Permintaan melewati batas waktu.",
  INVALID_PAIRING: "Format kode pairing tidak valid.",
  REVOCATION_UNCONFIRMED:
    "Pencabutan server belum terkonfirmasi. Coba Disconnect lagi atau cabut dari website.",
  STALE_PAIRING: "Pairing lama dibatalkan.",
  DISCONNECT_FIRST: "Putuskan sesi yang sedang aktif terlebih dahulu.",
};
async function send(action, extra = {}) {
  const r = await chrome.runtime.sendMessage({ action, ...extra });
  if (!r?.ok)
    throw new Error(
      messages[r?.error] ?? "Permintaan belum berhasil. Coba lagi.",
    );
  return r.data;
}
async function render(state) {
  $("disconnected").hidden = state.connected;
  $("connected").hidden = !state.connected;
  $("identity").textContent = state.user?.name ?? "";
  $("status").textContent = state.warning
    ? "Cabut sesi yang tidak dikenali melalui website DupIssue."
    : state.connected
      ? "Terhubung sebagai Reporter"
      : "Belum terhubung";
}
async function run(fn) {
  document.querySelectorAll("button").forEach((b) => (b.disabled = true));
  try {
    await fn();
  } catch (e) {
    $("status").textContent = e.message;
  } finally {
    document.querySelectorAll("button").forEach((b) => (b.disabled = false));
  }
}
$("connectPage").onclick = () => run(() => send("connectPage"));
$("pairForm").onsubmit = (e) => {
  e.preventDefault();
  const code = $("code").value.trim();
  $("code").value = "";
  run(async () => render(await send("pair", { code })));
};
$("disconnect").onclick = () =>
  run(async () => render(await send("disconnect")));
$("workspace").onclick = () => run(() => {
  if (!chrome.sidePanel?.open) {
    throw new Error("Browser belum mendukung Side Panel.");
  }

  if (!id(panelTabId)) {
    throw new Error(
      "Buka halaman New Issue GitHub terlebih dahulu."
    );
  }

  // Call directly from the click handler to preserve user activation.
  return chrome.sidePanel.open({ tabId: panelTabId });
});

run(async () => {
  await render(await send("session"));
  await preparePanel();
});
