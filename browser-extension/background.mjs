import { SafeError, id, parseRepository } from "./lib/contracts.mjs";
import { API_ORIGIN } from "./config.mjs";
import { createApi } from "./lib/api.mjs";
import { Session } from "./lib/session.mjs";
import { authorizeMessage } from "./lib/messages.mjs";
import { Workspaces } from "./lib/workspace.mjs";
const session = new Session(chrome.storage.session, createApi(API_ORIGIN));
const workspaces = new Workspaces(session, chrome.tabs);
const ready = session.init();
const windowsByTab = new Map();
const panelsByTab = new Map();
chrome.runtime.onConnect.addListener((port) => {
  const match = /^workspace:([1-9][0-9]*)$/.exec(port.name);
  if (
    !match ||
    port.sender?.id !== chrome.runtime.id ||
    !port.sender.url?.startsWith(chrome.runtime.getURL("workspace.html") + "?")
  ) {
    port.disconnect();
    return;
  }
  const tabId = Number(match[1]);
  const params = new URL(port.sender.url).searchParams;

  if (Number(params.get("tab")) !== tabId) {
    port.disconnect();
    return;
  }

  const isPanel = params.get("mode") === "sidepanel";
  const windowId = Number(params.get("window"));

  if (isPanel && !id(windowId)) {
    port.disconnect();
    return;
  }

  if (isPanel) {
    panelsByTab.set(tabId, windowId);
  }

  windowsByTab.set(tabId, (windowsByTab.get(tabId) ?? 0) + 1);
  port.onDisconnect.addListener(() => {
    if (isPanel) {
      panelsByTab.delete(tabId);
      workspaces.reset(tabId);
    }

    const count = (windowsByTab.get(tabId) ?? 1) - 1;
    if (count > 0) windowsByTab.set(tabId, count);
    else {
      windowsByTab.delete(tabId);
      workspaces.reset(tabId);
    }
  });
});
chrome.tabs.onRemoved.addListener((tabId) => {
  panelsByTab.delete(tabId);
  workspaces.reset(tabId);
});

chrome.tabs.onActivated.addListener(({ tabId, windowId }) => {
  for (const [panelTabId, panelWindowId] of panelsByTab) {
    if (
      panelWindowId === windowId &&
      panelTabId !== tabId
    ) {
      workspaces.reset(panelTabId);
    }
  }
});

chrome.tabs.onUpdated.addListener((tabId, change) => {
  if (change.url || change.status === "loading") {
    workspaces.reset(tabId);
  }

  if (
    panelsByTab.has(tabId) &&
    change.url &&
    !parseRepository(change.url)
  ) {
    chrome.sidePanel.setOptions({
      tabId,
      enabled: false,
    }).catch(() => {});
  }
});

// Available in newer Chromium versions.
// Port disconnection remains the compatibility fallback.
chrome.sidePanel?.onClosed?.addListener(({ tabId }) => {
  if (id(tabId)) workspaces.reset(tabId);
});
chrome.runtime.onMessage.addListener((message, sender, respond) => {
  (async () => {
    await ready;
    const context = authorizeMessage(message, sender, chrome.runtime.id);
    if (!context.trusted) return workspaces.content(message, context);
    switch (message.action) {
      case "session":
        return session.publicState();
      case "pair":
        workspaces.resetAll();
        return session.pair(message.code);
      case "disconnect":
        workspaces.resetAll();
        return session.disconnect();
      case "connectPage":
        await chrome.tabs.create({ url: API_ORIGIN + "/extension/connect" });
        return { opened: true };
      default: {
        const params = new URL(sender.url).searchParams;

        if (params.get("mode") === "sidepanel") {
          const panelTabId = Number(params.get("tab"));
          const panelWindowId = Number(params.get("window"));

          if (
            message.tabId !== panelTabId ||
            panelsByTab.get(panelTabId) !== panelWindowId
          ) {
            throw new SafeError("INVALID_SENDER");
          }

          const tab = await chrome.tabs.get(panelTabId);

          if (
            !tab.active ||
            tab.windowId !== panelWindowId ||
            !parseRepository(tab.url)
          ) {
            workspaces.reset(panelTabId);
            throw new SafeError("UNSUPPORTED_PAGE");
          }
        }

        return workspaces.action(message);
      }
    }
  })()
    .then((data) => respond({ ok: true, data }))
    .catch((e) =>
      respond({
        ok: false,
        error: e.code ?? "UNAVAILABLE",
        status: e.status ?? 0,
      }),
    );
  return true;
});
