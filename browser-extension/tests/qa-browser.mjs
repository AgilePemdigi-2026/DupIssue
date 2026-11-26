// Real unpacked extension + local backend/NLP; GitHub document is a routed fixture.
import { chromium } from "../../.qa/browser-tools/node_modules/playwright-core/index.mjs";
import { readFile, mkdir, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import assert from "node:assert/strict";
const root = fileURLToPath(new URL("../../", import.meta.url));
const accounts = JSON.parse(
  await readFile(root + ".qa/interactive/accounts.json", "utf8"),
);
const shot = root + ".qa/screenshots/";
await mkdir(shot, { recursive: true });
const context = await chromium.launchPersistentContext(
  root + ".qa/browser-profile-" + Date.now(),
  {
    executablePath:
      root +
      ".qa/chrome-158/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing",
    headless: process.env.QA_HEADED !== "1",
    viewport: { width: 1100, height: 850 },
    args: [
      "--disable-extensions-except=" + root + ".local-extension",
      "--load-extension=" + root + ".local-extension",
      "--disable-background-networking",
      "--enable-unsafe-extension-debugging",
    ],
  },
);
const issues = [];
context.on("weberror", (e) => issues.push(e.error().message));
await context.route("**/*", async (route) => {
  const url = new URL(route.request().url());
  if (url.origin === "http://127.0.0.1:18080") return route.continue();
  if (
    url.href === "https://github.com/valentinokevin/dupissue-testing/issues/new"
  )
    return route.fulfill({
      contentType: "text/html",
      body: '<!doctype html><title>Local GitHub form fixture</title><h1>Local fixture — no GitHub request</h1><label>Title<input id="issue-title" name="issue[title]"></label><label>Description<textarea id="issue-body" name="issue[body]"></textarea></label>',
    });
  if (url.protocol === "chrome-extension:") return route.continue();
  return route.abort();
});
const checks = [];
try {
  let [worker] = context.serviceWorkers();
  worker ??= await context.waitForEvent("serviceworker");
  const extensionId = new URL(worker.url()).host;
  checks.push("Real unpacked MV3 service worker loaded");
  const popup = await context.newPage();
  await popup.setViewportSize({ width: 420, height: 800 });
  await popup.goto("chrome-extension://" + extensionId + "/popup.html");
  await popup.locator("#pairForm").waitFor();
  await popup.screenshot({ path: shot + "popup-disconnected.png" });
  const pagePromise = context.waitForEvent("page");
  await popup.locator("#connectPage").click();
  const web = await pagePromise;
  await web.waitForURL("**/login");
  await web.locator("input[name=email]").fill(accounts.reporter);
  await web.locator("input[name=password]").fill(accounts.password);
  await web.locator("button[type=submit]").click();
  await web.waitForURL("**/reporter/projects");
  await web.goto("http://127.0.0.1:18080/extension/connect");
  await web.getByRole("button", { name: "Buat Pairing Code" }).click();
  const code = await web.locator("#extension-pairing-code").inputValue();
  await web.screenshot({
    path: shot + "pairing-page.png",
    mask: [web.locator("#extension-pairing-code")],
  });
  await popup.locator("#code").fill(code);
  await popup.locator("#pairForm button").click();
  await popup.locator("#connected").waitFor();
  assert.equal(await popup.locator("#identity").textContent(), "QA Reporter");
  checks.push("Web login + CSRF pairing + real extension exchange");
  await popup.screenshot({ path: shot + "popup-connected.png" });
  const github = await context.newPage();
  await github.goto(
    "https://github.com/valentinokevin/dupissue-testing/issues/new",
  );
  await github.bringToFront();
  const browserCdp = await context.browser().newBrowserCDPSession();
  const { targetInfos } = await browserCdp.send("Target.getTargets", {
    filter: [{ type: "tab", exclude: false }],
  });
  const targetInfo = targetInfos.find((t) => t.url === github.url());
  assert.ok(targetInfo, "GitHub fixture tab target exists");
  await browserCdp.send("Extensions.triggerAction", {
    id: extensionId,
    targetId: targetInfo.targetId,
  });
  const tabId = await worker.evaluate(
    async () =>
      (await chrome.tabs.query({ active: true, lastFocusedWindow: true }))[0]
        .id,
  );
  const workspace = await context.newPage();
  await workspace.setViewportSize({ width: 430, height: 850 });
  await workspace.goto(
    "chrome-extension://" + extensionId + "/workspace.html?tab=" + tabId,
  );
  await workspace
    .locator('#project option[value="1"]')
    .waitFor({ state: "attached" });
  await workspace.locator("#project").selectOption("1");
  await workspace.locator("#enable").click();
  const started = Date.now();
  await github.locator("#issue-title").fill("Checkout payment fails");
  await github
    .locator("#issue-body")
    .fill("Clicking the payment button does not complete checkout.");
  await workspace.locator("#results .card").first().waitFor({ timeout: 22000 });
  const displayLatency = Date.now() - started;
  assert.equal(await workspace.locator("#results .card").count(), 3);
  checks.push(
    "Real model Top3 shown; display latency " + displayLatency + "ms",
  );
  assert.ok(
    displayLatency < 5000,
    "Recommendations must render promptly after inference, not wait for 15s authorization polling",
  );
  const card = workspace.locator("#results .card").first();
  await card
    .getByRole("button", { name: "Vote Issue · 0", exact: true })
    .click();
  await card.getByRole("button", { name: "Unvote · 1", exact: true }).waitFor();
  const detail = await card.locator("a").getAttribute("href");
  await web.goto(detail);
  assert.match(await web.locator("body").innerText(), /Checkout/);
  await card.getByRole("button", { name: "Unvote · 1", exact: true }).click();
  await card
    .getByRole("button", { name: "Vote Issue · 0", exact: true })
    .waitFor();
  checks.push("Vote/unvote actual backend + web detail");
  assert.equal(
    await github.locator("#issue-title").inputValue(),
    "Checkout payment fails",
  );
  assert.equal(
    await github.locator("#issue-body").inputValue(),
    "Clicking the payment button does not complete checkout.",
  );
  assert.equal(await github.locator(".card").count(), 0);
  checks.push("Draft unchanged; private results absent from GitHub DOM");
  await workspace.screenshot({
    path: shot + "recommendations.png",
    fullPage: true,
  });
  await workspace.locator("#project").selectOption("2");
  await workspace.locator("#enable").waitFor();
  assert.equal(await workspace.locator("#results .card").count(), 0);
  checks.push("Project switch clears results and consent");
  await popup.locator("#disconnect").click();
  await popup.locator("#disconnected").waitFor();
  checks.push("Actual server disconnect");
  await writeFile(
    root + ".qa/browser-result.json",
    JSON.stringify(
      {
        browser: context.browser().version(),
        checks,
        pageErrors: issues,
        github: "routed synthetic fixture",
        actualGitHub: false,
      },
      null,
      2,
    ),
  );
  console.log(JSON.stringify({ checks, pageErrors: issues }, null, 2));
} catch (error) {
  console.error(
    JSON.stringify(
      { checks, pageErrors: issues, error: String(error) },
      null,
      2,
    ),
  );
  throw error;
} finally {
  await context.close();
}
