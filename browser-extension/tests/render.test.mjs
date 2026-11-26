import test from "node:test";
import assert from "node:assert/strict";
import vm from "node:vm";
import { readFile } from "node:fs/promises";
import { id, safeDetail } from "../lib/contracts.mjs";

class Element {
  constructor(tag = "div") {
    this.tag = tag;
    this.children = [];
    this.dataset = {};
    this.events = {};
    this.textContent = "";
    this.value = "";
  }
  append(...nodes) {
    this.children.push(...nodes);
  }
  replaceChildren(...nodes) {
    this.children = [...nodes];
  }
  addEventListener(name, fn) {
    this.events[name] = fn;
  }
  set innerHTML(_) {
    throw new Error("Unsafe HTML rendering");
  }
}
const flatten = (node) => [node, ...node.children.flatMap(flatten)];
test("workspace renders eligible recommendations safely with percentage, raw cosine and authoritative vote", async () => {
  const source = (
    await readFile(new URL("../workspace.mjs", import.meta.url), "utf8")
  ).replace(/^import .*;\n/gm, "");
  const elements = new Map();
  const messages = [];
  const state = {
    repository: "example/repo",
    projects: [{ id: 4, name: "<script>project</script>" }],
    projectId: 4,
    enabled: true,
    status: "READY",
    recommendations: [
      {
        issue_id: 1,
        target_issue_id: 2,
        title: "<img src=x onerror=alert(1)>",
        description: "<script>private</script>",
        status: "DUPLICATE",
        similarity: 0.9761,
        cosine_similarity: 0.9761,
        detail_url: "/projects/4/issues/2",
        vote: { voted: true, vote_count: 7 },
      },
    ],
  };
  const context = vm.createContext({
    API_ORIGIN: "http://127.0.0.1:8000",
    safeDetail,
    id,
    URL,
    location: { href: "chrome-extension://test/workspace.html?tab=1" },
    document: {
      getElementById: (key) => {
        if (!elements.has(key)) elements.set(key, new Element());
        return elements.get(key);
      },
      createElement: (tag) => new Element(tag),
    },
    setInterval: () => {},
    chrome: {
      runtime: {
        connect: () => ({ onDisconnect: { addListener: () => {} } }),
        sendMessage: async (message) => {
          messages.push(message);
          return { ok: true, data: structuredClone(state) };
        },
      },
    },
  });
  vm.runInContext(source, context);
  await new Promise(setImmediate);
  let nodes = flatten(elements.get("results"));
  assert.equal(
    nodes.find((n) => n.tag === "h3").textContent,
    state.recommendations[0].title,
  );
  const score = nodes.find((n) => n.className === "score");
  assert.equal(score.textContent, "Kemiripan: 97,61%");
  assert.equal(score.title, "Cosine signed: 0.9761");

  const progress = nodes.find((n) => n.tag === "progress");
  assert.equal(progress.max, 1);
  assert.equal(progress.value, 0.9761);

  assert.ok(
    nodes.some((n) => n.className === "card-actions")
  );

  assert.equal(
    elements.get("status").textContent,
    "1 rekomendasi issue serupa · Threshold ≥ 75%"
  );
  assert.equal(
    nodes.find((n) => n.tag === "a").href,
    "http://127.0.0.1:8000/projects/4/issues/2",
  );
  const button = nodes.find((n) => n.tag === "button");
  assert.equal(button.textContent, "Unvote · 7");
  await button.events.click();
  assert.equal(messages.at(-1).action, "unvote");
  assert.equal(messages.at(-1).issueId, 1);
  state.recommendations[0].detail_url = "javascript:alert(1)";
  await elements.get("retry").events.click();
  nodes = flatten(elements.get("results"));
  assert.equal(nodes.find((n) => n.tag === "a").href, undefined);
  assert.equal(nodes.find((n) => n.tag === "a").hidden, true);

  state.recommendations = [];
  state.status = "EMPTY";

  await elements.get("retry").events.click();

  assert.equal(elements.get("results").children.length, 0);
  assert.equal(
    elements.get("status").textContent,
    "Tidak ditemukan issue serupa yang memenuhi batas ambang 75%."
  );
});
