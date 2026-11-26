// No private API response or extension-owned UI is injected into the GitHub DOM.
(() => {
  if (globalThis.__dupIssueCapture) return;
  globalThis.__dupIssueCapture = true;
  let last = "",
    epoch = null,
    busy = false,
    page = location.href;
  function supportedPage() {
    return (
      location.origin === "https://github.com" &&
      /^\/[^/]+\/[^/]+\/issues\/new\/?$/.test(location.pathname)
    );
  }
  function draft() {
    const titles = document.querySelectorAll(
      'input[name="issue[title]"], input#issue_title, input#issue-title, main input[aria-label="Add a title"]',
    );
    const bodies = document.querySelectorAll(
      'textarea[name="issue[body]"], textarea#issue_body, textarea#issue-body, main textarea[aria-label="Markdown value"]',
    );
    // Ambiguous or structured forms fail closed; never scrape arbitrary inputs.
    if (titles.length !== 1 || bodies.length !== 1)
      return { title: "", description: "" };
    if (titles[0].value.length > 400 || bodies[0].value.length > 20000)
      return { title: "", description: "" };
    return { title: titles[0].value, description: bodies[0].value };
  }
  async function inspect() {
    if (busy) return;
    if (location.href !== page) {
      page = location.href;
      last = "";
      epoch = null;
    }
    if (!supportedPage()) {
      last = "";
      epoch = null;
      return;
    }
    busy = true;
    try {
      const before = location.href;
      const response = await chrome.runtime.sendMessage({
        action: "captureState",
      });
      if (before !== location.href) return;
      if (!response?.ok || !response.data.enabled) {
        last = "";
        epoch = null;
        return;
      }
      if (epoch !== response.data.consentEpoch) {
        epoch = response.data.consentEpoch;
        last = "";
      }
      const value = draft(),
        key = JSON.stringify(value);
      if (key !== last) {
        last = key;
        const sent = await chrome.runtime.sendMessage({
          action: "draft",
          ...value,
        });
        if (!sent?.ok) last = "";
      }
    } catch {
      last = "";
      epoch = null;
    } finally {
      busy = false;
    }
  }
  document.addEventListener("input", inspect, { passive: true });
  document.addEventListener("turbo:load", inspect);
  globalThis.addEventListener("popstate", inspect);
  // Detect SPA navigation and form replacement without monkey-patching page APIs.
  setInterval(inspect, 500);
  inspect();
})();
