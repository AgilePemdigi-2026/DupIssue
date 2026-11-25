import test from "node:test";
import assert from "node:assert/strict";
import { authorizeMessage } from "../lib/messages.mjs";
import { parseRepository } from "../lib/contracts.mjs";

const extensionId = "synthetic-extension";

const issuesUrl =
  "https://github.com/example/repo/issues";

const newIssueUrl = issuesUrl + "/new";

const normalSender = {
  id: extensionId,
  frameId: 0,
  url: newIssueUrl,
  tab: {
    id: 11,
    url: newIssueUrl,
  },
};

const spaSender = {
  ...normalSender,
  url: issuesUrl,
};

const capture = {
  action: "captureState",
};

const draft = {
  action: "draft",
  title: "Checkout payment fails",
  description: "Clicking payment does not complete checkout.",
};

const expectedContext = {
  trusted: false,
  repository: "example/repo",
  tabId: 11,
};

function authorize(message, sender) {
  return authorizeMessage(message, sender, extensionId);
}

function rejectSender(message, sender) {
  assert.throws(
    () => authorize(message, sender),
    (error) => error.code === "INVALID_SENDER"
  );
}

test("normal New Issue sender remains authorized", () => {
  assert.deepEqual(
    authorize(capture, normalSender),
    expectedContext
  );

  assert.deepEqual(
    authorize(draft, normalSender),
    expectedContext
  );
});

test("SPA captureState accepts same-repository stale Issues URL", () => {
  assert.deepEqual(
    authorize(capture, spaSender),
    expectedContext
  );
});

test("SPA draft accepts same-repository stale Issues URL", () => {
  assert.deepEqual(
    authorize(draft, spaSender),
    expectedContext
  );
});

test("SPA sender from another repository is rejected", () => {
  const sender = {
    ...spaSender,
    url: "https://github.com/example/other/issues",
  };

  rejectSender(capture, sender);
  rejectSender(draft, sender);
});

test("SPA sender from a non-GitHub origin is rejected", () => {
  rejectSender(capture, {
    ...spaSender,
    url: "https://github.com.evil/example/repo/issues",
  });
});

test("SPA sender using HTTP is rejected", () => {
  rejectSender(capture, {
    ...spaSender,
    url: "http://github.com/example/repo/issues",
  });
});

test("SPA sender from an iframe is rejected", () => {
  rejectSender(capture, {
    ...spaSender,
    frameId: 1,
  });
});

test("SPA sender with invalid extension identity is rejected", () => {
  rejectSender(capture, {
    ...spaSender,
    id: "different-extension",
  });
});

test("missing sender URL fails closed", () => {
  rejectSender(capture, {
    ...spaSender,
    url: undefined,
  });
});

test("missing tab URL fails closed", () => {
  rejectSender(capture, {
    ...spaSender,
    tab: { id: 11 },
  });
});

test("invalid tab ID fails closed", () => {
  rejectSender(capture, {
    ...spaSender,
    tab: {
      id: -1,
      url: newIssueUrl,
    },
  });
});

test("SPA sender cannot authorize an inactive New Issue route", () => {
  for (const url of [
    issuesUrl,
    issuesUrl + "/1",
    "https://github.com/example/repo",
    newIssueUrl + "/extra",
  ]) {
    const sender = {
      ...spaSender,
      tab: {
        id: 11,
        url,
      },
    };

    rejectSender(capture, sender);
    rejectSender(draft, sender);
  }
});

test("malformed or ambiguous SPA sender URLs are rejected", () => {
  for (const url of [
    "https://evil@github.com/example/repo/issues",
    "https://github.com/example%2frepo/issues",
    "https://github.com/example/../repo/issues",
    "https://github.com/example/repo/issues/extra",
    "javascript:alert(1)",
    "not-a-url",
  ]) {
    rejectSender(capture, {
      ...spaSender,
      url,
    });
  }
});

test("SPA sender cannot perform privileged extension actions", () => {
  for (const action of [
    "session",
    "pair",
    "disconnect",
    "resolve",
    "selectProject",
    "enable",
    "snapshot",
    "vote",
    "unvote",
  ]) {
    rejectSender({
      action,
      tabId: 11,
      projectId: 1,
      issueId: 1,
    }, spaSender);
  }
});

test("invalid draft payload remains rejected", () => {
  assert.throws(
    () => authorize({
      ...draft,
      title: "x".repeat(401),
    }, normalSender),
    (error) => error.code === "INVALID_DRAFT"
  );

  assert.throws(
    () => authorize({
      ...draft,
      description: "x".repeat(20001),
    }, normalSender),
    (error) => error.code === "INVALID_DRAFT"
  );
});

test("main repository parser remains restricted to New Issue", () => {
  assert.equal(parseRepository(issuesUrl), null);

  assert.equal(
    parseRepository(issuesUrl + "/1"),
    null
  );

  assert.equal(
    parseRepository(newIssueUrl),
    "example/repo"
  );
});
