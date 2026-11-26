import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";

const source = readFileSync(
  new URL("../content.js", import.meta.url),
  "utf8",
);

const title = "Checkout payment fails";
const description =
  "Clicking the payment button does not complete checkout.";

function element(value, tagName) {
  return {
    value,
    tagName,
    type: tagName === "INPUT" ? "text" : undefined,
    getClientRects: () => [{ width: 100, height: 20 }],
  };
}

async function capture(layout, enabled = true) {
  const messages = [];

  const titleElement = element(title, "INPUT");
  const bodyElement = element(description, "TEXTAREA");

  const selectors = {
    legacy: {
      'input[name="issue[title]"]': titleElement,
      'textarea[name="issue[body]"]': bodyElement,
    },
    modern: {
      'main input[aria-label="Add a title"]':
        titleElement,
      'main textarea[aria-label="Markdown value"]':
        bodyElement,
    },
  }[layout];

  const context = {
    location: {
      origin: "https://github.com",
      pathname: "/valentinokevin/dupissue-testing/issues/new",
      href: "https://github.com/valentinokevin/dupissue-testing/issues/new",
    },

    document: {
      querySelectorAll(selector) {
        return [
          ...new Set(
            selector
              .split(",")
              .map((part) => selectors[part.trim()])
              .filter(Boolean),
          ),
        ];
      },

      addEventListener() {},
    },

    chrome: {
      runtime: {
        async sendMessage(message) {
          messages.push(
            JSON.parse(JSON.stringify(message)),
          );

          if (message.action === "captureState") {
            return {
              ok: true,
              data: {
                enabled,
                consentEpoch: 1,
              },
            };
          }

          return { ok: true };
        },
      },
    },

    addEventListener() {},
    setInterval() {},
  };

  vm.runInNewContext(source, context);

  await new Promise((resolve) =>
    setImmediate(resolve),
  );

  return messages.filter(
    (message) => message.action === "draft",
  );
}

test("legacy GitHub form captures valid draft", async () => {
  const drafts = await capture("legacy");

  assert.equal(drafts.length, 1);
  assert.equal(drafts[0].title, title);
  assert.equal(drafts[0].description, description);
});

test("modern GitHub form captures valid draft", async () => {
  const drafts = await capture("modern");

  assert.equal(drafts.length, 1);
  assert.equal(drafts[0].title, title);
  assert.equal(drafts[0].description, description);
});

test("draft is not transmitted without consent", async () => {
  const drafts = await capture("modern", false);

  assert.equal(drafts.length, 0);
});
