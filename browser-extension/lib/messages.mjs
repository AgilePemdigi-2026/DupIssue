import { SafeError, id, parseRepository } from "./contracts.mjs";
const privileged = new Set([
  "session",
  "pair",
  "disconnect",
  "connectPage",
  "resolve",
  "selectProject",
  "enable",
  "disable",
  "view",
  "snapshot",
  "retry",
  "vote",
  "unvote",
]);
// Chromium may report the initial document URL after
// same-document GitHub SPA navigation. Accept only the
// same repository's Issues index as an additional sender
// route. The active tab must still be a genuine New Issue
// page, and the strict repository parser remains unchanged.
function parseSenderRepository(url) {
  const direct = parseRepository(url);
  if (direct) return direct;

  if (typeof url !== "string") return null;

  const issuesIndex =
    /^(https:\/\/github\.com\/[^/?#]+\/[^/?#]+\/issues)\/?(?=[?#]|$)/;

  if (!issuesIndex.test(url)) return null;

  // Reuse the strict parser without normalizing away
  // malformed paths, credentials or encoded separators.
  return parseRepository(
    url.replace(issuesIndex, "$1/new")
  );
}

export function authorizeMessage(message, sender, extensionId) {
  if (
    !message ||
    typeof message !== "object" ||
    typeof message.action !== "string" ||
    sender.id !== extensionId
  )
    throw new SafeError("INVALID_SENDER");
  const url = sender.url ?? "";
  let trusted = false;
  try {
    const u = new URL(url);
    trusted =
      u.protocol === "chrome-extension:" &&
      u.host === extensionId &&
      ["/popup.html", "/workspace.html"].includes(u.pathname);
  } catch {}
  if (trusted && privileged.has(message.action)) {
    if (
      [
        "resolve",
        "selectProject",
        "enable",
        "disable",
        "view",
        "snapshot",
        "retry",
        "vote",
        "unvote",
      ].includes(message.action) &&
      !id(message.tabId)
    )
      throw new SafeError("INVALID_INPUT");
    if (
      ["selectProject", "enable"].includes(message.action) &&
      !id(message.projectId)
    )
      throw new SafeError("INVALID_INPUT");
    if (["vote", "unvote"].includes(message.action) && !id(message.issueId))
      throw new SafeError("INVALID_INPUT");
    if (
      message.action === "pair" &&
      (typeof message.code !== "string" ||
        !/^dup_pair_[a-f0-9]{64}$/.test(message.code))
    )
      throw new SafeError("INVALID_PAIRING");
    return { trusted: true };
  }
  const repo = parseSenderRepository(url);
  if (
    !trusted &&
    ["captureState", "draft"].includes(message.action) &&
    sender.frameId === 0 &&
    id(sender.tab?.id) &&
    repo &&
    parseRepository(sender.tab.url) === repo
  ) {
    if (
      message.action === "draft" &&
      (typeof message.title !== "string" ||
        message.title.length > 400 ||
        typeof message.description !== "string" ||
        message.description.length > 20000)
    )
      throw new SafeError("INVALID_DRAFT");
    return { trusted: false, repository: repo, tabId: sender.tab.id };
  }
  throw new SafeError("INVALID_SENDER");
}
