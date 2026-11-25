import test from "node:test";
import assert from "node:assert/strict";
import vm from "node:vm";
import { readFile } from "node:fs/promises";
import { Workspaces } from "../lib/workspace.mjs";
import { SafeError } from "../lib/contracts.mjs";
const draft = { title: "Login error", description: "Cannot log into account" };
test("failed access refresh cannot resurrect cached private results through snapshots", async () => {
  const f = fixture();
  await f.service.action({ action: "enable", tabId: 1, projectId: 4 });
  await f.service.content({ action: "draft", ...draft }, f.context);
  await f.advance(500);
  assert.equal(
    (await f.service.action({ action: "snapshot", tabId: 1 })).recommendations
      .length,
    1,
  );
  f.setHandler(async () => {
    throw new SafeError("NETWORK_ERROR");
  });
  await assert.rejects(
    f.service.action({ action: "view", tabId: 1 }),
    /NETWORK_ERROR/,
  );
  const state = await f.service.action({ action: "snapshot", tabId: 1 });
  assert.deepEqual(state.recommendations, []);
  assert.equal(state.status, "NETWORK_ERROR");
});
test("trusted snapshots refresh UI without extra API requests and clear after navigation", async () => {
  const f = fixture();
  await f.service.resolve(1);
  const before = f.calls.length;
  const state = await f.service.action({ action: "snapshot", tabId: 1 });
  assert.equal(state.repository, "a/b");
  assert.equal(f.calls.length, before);
  f.navigate("https://github.com/another/repo/issues/new");
  const changed = await f.service.action({ action: "snapshot", tabId: 1 });
  assert.deepEqual(changed.recommendations, []);
  assert.equal(changed.enabled, false);
});
function fixture() {
  const calls = [],
    pending = new Map();
  let clock = 0,
    next = 0,
    url = "https://github.com/a/b/issues/new";
  const timers = {
    setTimeout: (fn, ms) => {
      const id = ++next;
      pending.set(id, { fn, time: clock + ms });
      return id;
    },
    clearTimeout: (id) => pending.delete(id),
  };
  const row = {
    issue_id: 1,
    target_issue_id: 2,
    title: "<img onerror=alert(1)>",
    description: "private",
    status: "DUPLICATE",
    similarity: 0.2,
    cosine_similarity: -0.4,
    detail_url: "/projects/4/issues/2",
  };
  let handler = async (action) =>
    action === "resolve"
      ? {
          projects: [
            { id: 4, name: "Project" },
            { id: 5, name: "Other" },
          ],
        }
      : action === "recommend"
        ? { recommendations: [row] }
        : action === "me"
          ? { user: { id: 1 } }
          : {
              issue_id: 1,
              target_issue_id: 2,
              voted: action === "vote",
              vote_count: action === "vote" ? 1 : 0,
              changed: action !== "voteStatus",
            };
  const service = new Workspaces(
    {
      call: async (a, p, s) => {
        calls.push({ a, p, s });
        return handler(a, p, s);
      },
    },
    { get: async () => ({ url }) },
    timers,
  );
  return {
    service,
    calls,
    row,
    setHandler: (fn) => {
      handler = fn;
    },
    navigate: (value) => {
      url = value;
    },
    advance: async (ms) => {
      clock += ms;
      for (const [id, t] of pending)
        if (t.time <= clock) {
          pending.delete(id);
          await t.fn();
        }
    },
    context: { repository: "a/b", tabId: 1 },
  };
}
test("draft is not transmitted before explicit consent; project access rechecked on enable", async () => {
  const f = fixture();
  await f.service.resolve(1);
  assert.deepEqual(
    await f.service.content({ action: "draft", ...draft }, f.context),
    { enabled: false },
  );
  assert.equal(f.calls.filter((c) => c.a === "recommend").length, 0);
  await assert.rejects(
    f.service.action({ action: "enable", tabId: 1, projectId: 99 }),
    /UNAUTHORIZED/,
  );
  await f.service.action({ action: "enable", tabId: 1, projectId: 4 });
  const response = await f.service.content(
    { action: "draft", ...draft },
    f.context,
  );
  assert.equal(response.enabled, true);
  assert.equal(response.recommendations, undefined);
  await f.advance(499);
  assert.equal(f.calls.filter((c) => c.a === "recommend").length, 0);
  await f.advance(1);
  assert.equal(f.calls.filter((c) => c.a === "recommend").length, 1);
  assert.equal(
    (await f.service.action({ action: "view", tabId: 1 })).recommendations[0]
      .vote.target_issue_id,
    2,
  );
});
test("invalid draft cancels a scheduled request and repeat edits debounce", async () => {
  const f = fixture();
  await f.service.action({ action: "enable", tabId: 1, projectId: 4 });
  await f.service.content({ action: "draft", ...draft }, f.context);
  await f.advance(400);
  await f.service.content(
    { action: "draft", ...draft, title: "Updated title" },
    f.context,
  );
  await f.advance(499);
  assert.equal(f.calls.filter((c) => c.a === "recommend").length, 0);
  await f.service.content(
    { action: "draft", title: "", description: "" },
    f.context,
  );
  await f.advance(1000);
  assert.equal(f.calls.filter((c) => c.a === "recommend").length, 0);
});
test("project switching aborts and discards an old response", async () => {
  const f = fixture();
  await f.service.action({ action: "enable", tabId: 1, projectId: 4 });
  let complete;
  f.setHandler(async (a) =>
    a === "recommend"
      ? new Promise((resolve) => {
          complete = resolve;
        })
      : a === "resolve"
        ? { projects: [{ id: 5, name: "Other" }] }
        : {},
  );
  await f.service.content({ action: "draft", ...draft }, f.context);
  const running = f.advance(500);
  await new Promise(setImmediate);
  await f.service.action({ action: "selectProject", tabId: 1, projectId: 5 });
  complete({ recommendations: [f.row] });
  await running;
  const state = await f.service.action({ action: "view", tabId: 1 });
  assert.deepEqual(state.recommendations, []);
  assert.equal(state.enabled, false);
  assert.equal(f.calls.find((c) => c.a === "recommend").s.aborted, true);
});
test("repository navigation clears consent and private results", async () => {
  const f = fixture();
  await f.service.action({ action: "enable", tabId: 1, projectId: 4 });
  await f.service.content({ action: "draft", ...draft }, f.context);
  await f.advance(500);
  f.navigate("https://github.com/other/repo/issues/new");
  const state = await f.service.action({ action: "view", tabId: 1 });
  assert.equal(state.enabled, false);
  assert.deepEqual(state.recommendations, []);
  f.navigate("https://github.com/other/repo/issues/3");
  await assert.rejects(
    f.service.action({ action: "view", tabId: 1 }),
    /UNSUPPORTED_PAGE/,
  );
});
test("vote and unvote display authoritative state without optimistic counts", async () => {
  const f = fixture();
  await f.service.action({ action: "enable", tabId: 1, projectId: 4 });
  await f.service.content({ action: "draft", ...draft }, f.context);
  await f.advance(500);
  let state = await f.service.action({ action: "vote", tabId: 1, issueId: 1 });
  assert.equal(state.recommendations[0].vote.vote_count, 1);
  state = await f.service.action({ action: "unvote", tabId: 1, issueId: 1 });
  assert.equal(state.recommendations[0].vote.vote_count, 0);
  await assert.rejects(
    f.service.action({ action: "vote", tabId: 1, issueId: 99 }),
    /INVALID_ACTION/,
  );
});
test("revocation, network failures and rate limiting remove displayed private results", async () => {
  for (const status of [401, 403, 429, 503]) {
    const f = fixture();
    await f.service.action({ action: "enable", tabId: 1, projectId: 4 });
    f.setHandler(async () => {
      throw new SafeError("HTTP_" + status, status);
    });
    await f.service.content({ action: "draft", ...draft }, f.context);
    await f.advance(500);
    const state = f.service.states.get(1);
    assert.deepEqual(state.recommendations, []);
    assert.equal(state.status, "HTTP_" + status);
    if (status === 401 || status === 403) assert.equal(state.enabled, false);
  }
});
test("content simulation observes opt-in, dynamic fields and duplicate injection guard", async () => {
  const source = await readFile(
    new URL("../content.js", import.meta.url),
    "utf8",
  );
  const messages = [],
    listeners = {};
  let enabled = false,
    fields = true,
    tick;
  const context = vm.createContext({
    location: {
      href: "https://github.com/a/b/issues/new",
      origin: "https://github.com",
      pathname: "/a/b/issues/new",
    },
    document: {
      addEventListener: (n, fn) => {
        listeners[n] = fn;
      },
      querySelectorAll: (selector) =>
        fields
          ? [
              {
                value: selector.startsWith("input")
                  ? draft.title
                  : draft.description,
              },
            ]
          : [],
    },
    addEventListener: () => {},
    setInterval: (fn) => {
      tick = fn;
    },
    chrome: {
      runtime: {
        sendMessage: async (m) => {
          messages.push(m);
          return { ok: true, data: { enabled, consentEpoch: "1" } };
        },
      },
    },
  });
  vm.runInContext(source, context);
  await new Promise(setImmediate);
  assert.equal(
    messages.some((m) => m.action === "draft"),
    false,
  );
  enabled = true;
  await tick();
  assert.equal(messages.filter((m) => m.action === "draft").length, 1);
  await tick();
  assert.equal(messages.filter((m) => m.action === "draft").length, 1);
  fields = false;
  await listeners.input();
  assert.equal(messages.at(-1).title, "");
  const original = tick;
  vm.runInContext(source, context);
  assert.equal(tick, original);
  context.location.pathname = "/a/b/issues/1";
  await tick();
  assert.equal(messages.at(-1).action, "draft");
});
