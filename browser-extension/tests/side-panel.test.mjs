import test from "node:test";
import assert from "node:assert/strict";
import vm from "node:vm";
import { readFileSync } from "node:fs";

import {
  SafeError,
  id,
  parseRepository,
  safeDetail,
} from "../lib/contracts.mjs";

import {
  authorizeMessage,
} from "../lib/messages.mjs";

const githubUrl =
  "https://github.com/example/repo/issues/new";

const panelUrl =
  "chrome-extension://ext/workspace.html" +
  "?tab=11&mode=sidepanel&window=2";

function source(name) {
  return readFileSync(
    new URL("../" + name, import.meta.url),
    "utf8"
  ).replace(/^import .*;\n/gm, "");
}

function event() {
  const listeners = [];

  return {
    addListener(fn) {
      listeners.push(fn);
    },

    emit(...args) {
      for (const fn of listeners) fn(...args);
    },
  };
}

function plain(value) {
  return JSON.parse(JSON.stringify(value));
}

function backgroundHarness() {
  const calls = {
    reset: [],
    actions: [],
    options: [],
  };

  const tab = {
    id: 11,
    windowId: 2,
    active: true,
    url: githubUrl,
  };

  const chrome = {
    storage: { session: {} },

    runtime: {
      id: "ext",
      getURL: (path) =>
        "chrome-extension://ext/" + path,
      onConnect: event(),
      onMessage: event(),
    },

    tabs: {
      get: async () => ({ ...tab }),
      onRemoved: event(),
      onUpdated: event(),
      onActivated: event(),
    },

    sidePanel: {
      onClosed: event(),

      async setOptions(options) {
        calls.options.push(plain(options));
      },
    },
  };

  class Session {
    async init() {}
  }

  class Workspaces {
    reset(tabId) {
      calls.reset.push(tabId);
    }

    resetAll() {}

    async action(message) {
      calls.actions.push(plain(message));
      return { status: "READY" };
    }
  }

  vm.runInNewContext(source("background.mjs"), {
    chrome,
    Session,
    Workspaces,
    SafeError,
    id,
    parseRepository,
    authorizeMessage,
    URL,
    API_ORIGIN: "http://127.0.0.1:18080",
    createApi: () => ({}),
  });

  function connect(
    url = panelUrl,
    name = "workspace:11"
  ) {
    const port = {
      name,
      sender: { id: "ext", url },
      onDisconnect: event(),
      rejected: false,

      disconnect() {
        this.rejected = true;
        this.onDisconnect.emit();
      },
    };

    chrome.runtime.onConnect.emit(port);

    return port;
  }

  function send(message, url = panelUrl) {
    return new Promise((resolve) => {
      chrome.runtime.onMessage.emit(
        message,
        { id: "ext", url },
        resolve
      );
    });
  }

  return { calls, chrome, tab, connect, send };
}

test("side panel is restricted to its registered active tab", async () => {
  const h = backgroundHarness();

  const invalid = h.connect(panelUrl, "workspace:12");

  assert.equal(invalid.rejected, true);

  const port = h.connect();

  assert.equal(port.rejected, false);

  const valid = await h.send({
    action: "snapshot",
    tabId: 11,
  });

  assert.equal(valid.ok, true);
  assert.equal(h.calls.actions.length, 1);

  const forged = await h.send({
    action: "snapshot",
    tabId: 12,
  });

  assert.equal(forged.ok, false);
  assert.equal(forged.error, "INVALID_SENDER");
  assert.equal(h.calls.actions.length, 1);

  h.tab.active = false;

  const inactive = await h.send({
    action: "enable",
    tabId: 11,
    projectId: 1,
  });

  assert.equal(inactive.ok, false);
  assert.equal(inactive.error, "UNSUPPORTED_PAGE");
  assert.deepEqual(h.calls.reset, [11]);
});

test("switching tabs resets the previous panel only in its own window", () => {
  const h = backgroundHarness();

  h.connect();

  h.chrome.tabs.onActivated.emit({
    tabId: 12,
    windowId: 3,
  });

  assert.deepEqual(h.calls.reset, []);

  h.chrome.tabs.onActivated.emit({
    tabId: 12,
    windowId: 2,
  });

  assert.deepEqual(h.calls.reset, [11]);
});

test("navigation resets recommendations and disables unsupported pages", async () => {
  const h = backgroundHarness();

  h.connect();

  h.chrome.tabs.onUpdated.emit(11, {
    url: "https://github.com/example/other/issues/new",
  });

  assert.deepEqual(h.calls.reset, [11]);
  assert.equal(h.calls.options.length, 0);

  h.tab.url = "https://github.com/example/repo";

  h.chrome.tabs.onUpdated.emit(11, {
    url: h.tab.url,
  });

  assert.deepEqual(h.calls.options, [
    { tabId: 11, enabled: false },
  ]);

  const result = await h.send({
    action: "snapshot",
    tabId: 11,
  });

  assert.equal(result.ok, false);
  assert.equal(result.error, "UNSUPPORTED_PAGE");
  assert.equal(h.calls.actions.length, 0);
});

test("native side panel close event clears workspace state", () => {
  const h = backgroundHarness();

  h.connect();

  h.chrome.sidePanel.onClosed.emit({
    tabId: 11,
    windowId: 2,
  });

  assert.deepEqual(h.calls.reset, [11]);
});

test("port disconnection clears state and blocks old panel requests", async () => {
  const h = backgroundHarness();
  const port = h.connect();

  port.onDisconnect.emit();

  assert.ok(h.calls.reset.includes(11));

  const result = await h.send({
    action: "snapshot",
    tabId: 11,
  });

  assert.equal(result.ok, false);
  assert.equal(result.error, "INVALID_SENDER");
  assert.equal(h.calls.actions.length, 0);
});

async function popupHarness(url) {
  const elements = new Map();
  const calls = { options: [], opened: [] };

  const chrome = {
    runtime: {
      async sendMessage() {
        return {
          ok: true,
          data: {
            connected: true,
            user: { name: "Synthetic Reporter" },
          },
        };
      },
    },

    tabs: {
      async query() {
        return [{ id: 11, windowId: 2, url }];
      },
    },

    sidePanel: {
      async setOptions(options) {
        calls.options.push(plain(options));
      },

      open(options) {
        calls.opened.push(plain(options));
        return Promise.resolve();
      },
    },
  };

  const document = {
    getElementById(key) {
      if (!elements.has(key)) {
        elements.set(key, {});
      }
      return elements.get(key);
    },

    querySelectorAll() {
      return [];
    },
  };

  vm.runInNewContext(source("popup.mjs"), {
    chrome,
    document,
    id,
    parseRepository,
  });

  await new Promise(setImmediate);

  return { elements, calls };
}

test("popup prepares the correct side panel and opens directly from click", async () => {
  const h = await popupHarness(githubUrl);

  assert.deepEqual(h.calls.options, [
    { enabled: false },
    {
      tabId: 11,
      path: "workspace.html?tab=11&mode=sidepanel&window=2",
      enabled: true,
    },
  ]);

  const click = h.elements.get("workspace").onclick();

  // Opening must happen synchronously in the click handler.
  assert.deepEqual(h.calls.opened, [{ tabId: 11 }]);

  await click;
});

test("popup refuses to open panel outside GitHub New Issue", async () => {
  const h = await popupHarness(
    "https://github.com/example/repo"
  );

  await h.elements.get("workspace").onclick();

  assert.deepEqual(h.calls.opened, []);

  assert.match(
    h.elements.get("status").textContent,
    /New Issue GitHub/
  );
});

test("late workspace response cannot render after switching tabs", async () => {
  class Element {
    constructor() {
      this.children = [];
      this.dataset = {};
      this.textContent = "";
      this.events = {};
    }

    replaceChildren(...nodes) {
      this.children = nodes;
    }

    append(...nodes) {
      this.children.push(...nodes);
    }

    addEventListener(name, fn) {
      this.events[name] = fn;
    }
  }

  const elements = new Map();
  const activated = event();
  const updated = event();
  const disconnected = event();

  let finish;

  const pending = new Promise((resolve) => {
    finish = resolve;
  });

  const document = {
    getElementById(key) {
      if (!elements.has(key)) {
        elements.set(key, new Element());
      }
      return elements.get(key);
    },

    createElement() {
      return new Element();
    },
  };

  vm.runInNewContext(source("workspace.mjs"), {
    API_ORIGIN: "http://127.0.0.1:18080",
    safeDetail,
    id,
    URL,
    document,
    location: { href: panelUrl },
    setInterval: () => {},

    chrome: {
      tabs: {
        onActivated: activated,
        onUpdated: updated,
      },

      runtime: {
        connect: () => ({
          onDisconnect: disconnected,
        }),

        sendMessage: () => pending,
      },
    },
  });

  activated.emit({
    tabId: 12,
    windowId: 2,
  });

  finish({
    ok: true,
    data: {
      repository: "example/repo",
      projects: [{ id: 1, name: "Private Project" }],
      projectId: 1,
      enabled: true,
      status: "READY",
      recommendations: [
        {
          issue_id: 1,
          target_issue_id: 1,
          title: "Private recommendation",
          description: "Private description",
          status: "OPEN",
          similarity: 0.99,
          cosine_similarity: 0.99,
          detail_url: "/projects/1/issues/1",
          vote: { voted: false, vote_count: 0 },
        },
      ],
    },
  });

  await new Promise(setImmediate);

  assert.equal(
    elements.get("results").children.length,
    0
  );

  assert.equal(
    elements.get("project").children.length,
    0
  );

  assert.equal(
    elements.get("status").textContent,
    "Kembali ke tab GitHub yang terhubung."
  );
});
