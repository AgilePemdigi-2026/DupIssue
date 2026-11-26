// Dedicated visible Chrome for Testing profile. All GitHub navigation is local fixture data.
import { chromium } from "../../.qa/browser-tools/node_modules/playwright-core/index.mjs";
import { fileURLToPath } from "node:url";
const root = fileURLToPath(new URL("../../", import.meta.url));
const context = await chromium.launchPersistentContext(
  root + ".qa/manual-browser-profile",
  {
    executablePath:
      root +
      ".qa/chrome-158/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing",
    headless: false,
    viewport: null,
    args: [
      "--disable-extensions-except=" + root + ".local-extension",
      "--load-extension=" + root + ".local-extension",
      "--disable-background-networking",
    ],
  },
);
await context.route("**/*", async (route) => {
  const url = new URL(route.request().url());
  if (
    url.origin === "http://127.0.0.1:18080" ||
    url.protocol === "chrome-extension:"
  )
    return route.continue();
  if (
    url.href === "https://github.com/valentinokevin/dupissue-testing/issues/new"
  )
    return route.fulfill({
      contentType: "text/html",
      body: `<!doctype html><html lang="id"><title>DupIssue QA — GitHub form fixture</title><style>body{font:16px system-ui;margin:40px;max-width:760px;color:#262034}input,textarea{display:block;width:100%;padding:12px;margin:12px 0 24px;box-sizing:border-box}textarea{height:180px}.notice{padding:18px;background:#eee7f8;border-radius:12px}</style><h1>Form uji lokal</h1><p class="notice">Ini fixture lokal, bukan halaman GitHub aktual. Tidak ada issue yang dikirim ke GitHub. Klik ikon DupIssue pada toolbar lalu buka ruang rekomendasi.</p><label>Judul<input id="issue-title" name="issue[title]" placeholder="Checkout payment fails"></label><label>Deskripsi<textarea id="issue-body" name="issue[body]" placeholder="Clicking the payment button does not complete checkout."></textarea></label></html>`,
    });
  return route.abort();
});
let [worker] = context.serviceWorkers();
worker ??= await context.waitForEvent("serviceworker");
const id = new URL(worker.url()).host;
const web = await context.newPage();
await web.goto("http://127.0.0.1:18080/extension/connect");
const popup = await context.newPage();
await popup.goto("chrome-extension://" + id + "/popup.html");
const draft = await context.newPage();
await draft.goto(
  "https://github.com/valentinokevin/dupissue-testing/issues/new",
);
console.log(
  "Visible QA browser ready. Use the extension toolbar action on the fixture tab. No external GitHub requests are allowed.",
);
await new Promise((resolve) => context.on("close", resolve));
