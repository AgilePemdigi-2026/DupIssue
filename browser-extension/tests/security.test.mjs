import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { Session } from "../lib/session.mjs";
import { createApi } from "../lib/api.mjs";
import { authorizeMessage } from "../lib/messages.mjs";
import {
  parseRepository,
  validateResponse,
  SafeError,
} from "../lib/contracts.mjs";
const token = "dup_ext_" + "a".repeat(64),
  code = "dup_pair_" + "b".repeat(64);
const issued = {
  access_token: token,
  expires_at: new Date(Date.now() + 3600000).toISOString(),
};
const identity = { user: { id: 1, name: "Reporter", role: "REPORTER" } };
function storage(initial = {}) {
  let state = structuredClone(initial);
  return {
    get: async () => structuredClone(state),
    set: async (v) => {
      state = structuredClone(v);
    },
    setAccessLevel: async (v) =>
      assert.equal(v.accessLevel, "TRUSTED_CONTEXTS"),
  };
}
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((a, b) => {
    resolve = a;
    reject = b;
  });
  return { promise, resolve, reject };
};
test("manifest grants only required capabilities and packages executable assets", async () => {
  const manifest = JSON.parse(
    await readFile(new URL("../manifest.json", import.meta.url)),
  );
  assert.equal(manifest.manifest_version, 3);
  assert.deepEqual(
    manifest.permissions,
    ["storage", "activeTab", "sidePanel"]
  );

  assert.equal(manifest.minimum_chrome_version, "116");
  assert.equal(manifest.side_panel.default_path, "workspace.html");
  assert.deepEqual(manifest.host_permissions, [
    "https://dupissue.caiis.ai.id/*",
  ]);
  assert.equal(manifest.web_accessible_resources, undefined);
  assert.equal(manifest.externally_connectable, undefined);
  for (const path of [
    manifest.background.service_worker,
    manifest.action.default_popup,
    ...Object.values(manifest.icons),
  ])
    assert.ok((await readFile(new URL("../" + path, import.meta.url))).length);
});
test("parser confines activity to genuine GitHub new issue routes", () => {
  assert.equal(
    parseRepository("https://github.com/Owner/Repo/issues/new?template=bug.md"),
    "owner/repo",
  );
  for (const url of [
    "http://github.com/a/b/issues/new",
    "https://github.com.evil/a/b/issues/new",
    "https://evil@github.com/a/b/issues/new",
    "https://github.com/a/../b/issues/new",
    "https://github.com/a/%2e%2e/issues/new",
    "https://github.com/a/b/issues/1",
    "javascript:alert(1)",
    "https://github.com/a/b/issues/new/extra",
  ])
    assert.equal(parseRepository(url), null, url);
});
test("content messages cannot read credentials or use privileged actions", () => {
  const sender = {
    id: "ext",
    frameId: 0,
    url: "https://github.com/a/b/issues/new",
    tab: { id: 1, url: "https://github.com/a/b/issues/new" },
  };
  assert.equal(
    authorizeMessage({ action: "captureState" }, sender, "ext").trusted,
    false,
  );
  for (const action of [
    "session",
    "pair",
    "disconnect",
    "vote",
    "resolve",
    "snapshot",
    "fetch",
  ])
    assert.throws(() => authorizeMessage({ action }, sender, "ext"));
  assert.throws(() =>
    authorizeMessage(
      { action: "draft", title: "ok", description: "ok" },
      { ...sender, frameId: 1 },
      "ext",
    ),
  );
  assert.throws(() =>
    authorizeMessage(
      { action: "captureState" },
      { ...sender, tab: { id: 1, url: "https://github.com/c/d/issues/new" } },
      "ext",
    ),
  );
  assert.throws(() =>
    authorizeMessage(
      { action: "session" },
      { id: "other", url: "chrome-extension://ext/popup.html" },
      "ext",
    ),
  );
  assert.throws(() =>
    authorizeMessage(
      { action: "vote", tabId: 1, issueId: -1 },
      { id: "ext", url: "chrome-extension://ext/workspace.html" },
      "ext",
    ),
  );
});
test("API allowlist uses bearer headers and rejects redirects and injected payloads", async () => {
  let captured;
  const api = createApi("http://127.0.0.1:8000", async (url, options) => {
    captured = { url, options };
    return {
      ok: true,
      json: async () => ({
        repository: "a/b",
        projects: [{ id: 2, name: "Project", secret: "hidden" }],
      }),
    };
  });
  assert.deepEqual(
    await api(
      "resolve",
      { repository: "A/B", url: "https://evil", token: "injected" },
      token,
    ),
    { projects: [{ id: 2, name: "Project" }] },
  );
  assert.equal(captured.options.headers.Authorization, "Bearer " + token);
  assert.equal(captured.options.redirect, "error");
  assert.equal(captured.options.credentials, "omit");
  assert.deepEqual(JSON.parse(captured.options.body), { repository: "a/b" });
  assert.ok(!captured.url.includes(token));
  await assert.rejects(
    api("fetch", { url: "https://evil" }, token),
    /INVALID_ACTION/,
  );
  assert.throws(() => createApi("https://evil"), /INVALID_ORIGIN/);
});
test("API strips raw error data and handles timeout", async () => {
  for (const status of [401, 403, 404, 409, 422, 429, 503]) {
    const api = createApi("http://127.0.0.1:8000", async () => ({
      ok: false,
      status,
      json: async () => ({ secret: token }),
    }));
    await assert.rejects(
      api("me", {}, token),
      (e) => e.message === "HTTP_" + status && !e.message.includes(token),
    );
  }
  const api = createApi(
    "http://127.0.0.1:8000",
    (_, o) =>
      new Promise((_, reject) =>
        o.signal.addEventListener("abort", () => reject(new Error(token))),
      ),
    5,
  );
  await assert.rejects(api("me", {}, token), /TIMEOUT/);
});
test("response contracts reject forged target links, excessive candidates and invalid vote counts", () => {
  const row = {
    issue_id: 1,
    target_issue_id: 2,
    title: "Title",
    description: "Description",
    status: "OPEN",
    similarity: 0.2,
    cosine_similarity: -0.6,
    detail_url: "/projects/3/issues/2",
  };
  assert.equal(
    validateResponse("recommend", { recommendations: [row] }, { project_id: 3 })
      .recommendations[0].cosine_similarity,
    -0.6,
  );
  assert.throws(() =>
    validateResponse(
      "recommend",
      { recommendations: [{ ...row, detail_url: "https://evil" }] },
      { project_id: 3 },
    ),
  );
  assert.throws(() =>
    validateResponse(
      "recommend",
      { recommendations: Array(4).fill(row) },
      { project_id: 3 },
    ),
  );
  assert.throws(() =>
    validateResponse(
      "vote",
      {
        issue_id: 1,
        target_issue_id: 2,
        voted: false,
        vote_count: -1,
        changed: false,
      },
      { issue_id: 1 },
    ),
  );
  assert.throws(() =>
    validateResponse("me", { user: { ...identity.user, role: "MAINTAINER" } }),
  );
});
test("pairing restores only verified identity and does not expose token in public state", async () => {
  const store = storage();
  const api = async (action) => (action === "pair" ? issued : identity);
  const session = new Session(store, api);
  await session.init();
  const state = await session.pair(code);
  assert.equal(state.connected, true);
  assert.ok(!JSON.stringify(state).includes(token));
  assert.equal((await new Session(store, api).publicState()).user.id, 1);
});
test("disconnect invalidates an in-flight pairing and revokes its eventual token", async () => {
  const pending = deferred(),
    actions = [];
  const session = new Session(storage(), async (action) => {
    actions.push(action);
    return action === "pair" ? pending.promise : identity;
  });
  const pairing = session.pair(code);
  await session.snapshot();
  await session.disconnect();
  pending.resolve(issued);
  await assert.rejects(pairing, /STALE_PAIRING/);
  assert.ok(actions.includes("disconnect"));
  assert.equal((await session.publicState(false)).connected, false);
});
test("new pairing wins over stale concurrent response", async () => {
  const pending = deferred();
  let count = 0;
  const session = new Session(storage(), async (action) =>
    action === "pair"
      ? ++count === 1
        ? pending.promise
        : { ...issued, access_token: "dup_ext_" + "c".repeat(64) }
      : identity,
  );
  const first = session.pair(code);
  await session.snapshot();
  await session.pair(code);
  pending.resolve(issued);
  await assert.rejects(first, /STALE_PAIRING/);
  assert.equal((await session.snapshot()).token, "dup_ext_" + "c".repeat(64));
});
test("failed revocation retains credential for retry and reports no fake success", async () => {
  let fail = true;
  const session = new Session(
    storage({
      auth: {
        generation: 1,
        token,
        expiresAt: issued.expires_at,
        user: identity.user,
      },
    }),
    async () => {
      if (fail) throw new SafeError("NETWORK_ERROR");
      return { disconnected: true };
    },
  );
  await assert.rejects(session.disconnect(), /REVOCATION_UNCONFIRMED/);
  assert.equal((await session.snapshot()).token, token);
  fail = false;
  assert.equal((await session.disconnect()).serverRevoked, true);
  assert.equal((await session.snapshot()).token, undefined);
});
test("401 clears credentials but a stale request cannot clear a newer session", async () => {
  const pending = deferred();
  const store = storage({
    auth: {
      generation: 1,
      token,
      expiresAt: issued.expires_at,
      user: identity.user,
    },
  });
  const session = new Session(store, () => pending.promise);
  const request = session.call("me");
  await session.snapshot();
  await session.edit((s) => {
    s.generation++;
    s.token = "dup_ext_" + "c".repeat(64);
  });
  pending.reject(new SafeError("HTTP_401", 401));
  await assert.rejects(request);
  assert.equal((await session.snapshot()).generation, 2);
  const invalid = new Session(store, async () => {
    throw new SafeError("HTTP_401", 401);
  });
  await assert.rejects(invalid.call("me"));
  assert.equal((await invalid.snapshot()).token, undefined);
});
