import {
  SafeError,
  repository,
  id,
  validDraft,
  validateResponse,
} from "./contracts.mjs";
const routes = Object.freeze({
  pair: ["POST", "pair"],
  me: ["GET", "me"],
  disconnect: ["DELETE", "session"],
  resolve: ["POST", "repositories/resolve"],
  recommend: ["POST", "recommendations"],
  voteStatus: ["GET", "votes"],
  vote: ["POST", "votes"],
  unvote: ["DELETE", "votes"],
});
export function createApi(origin, fetcher = globalThis.fetch, timeout = 15000) {
  if (
    ![
      "https://dupissue.caiis.ai.id",
      "http://127.0.0.1:8000",
      "http://127.0.0.1:18080",
    ].includes(origin)
  )
    throw new SafeError("INVALID_ORIGIN");
  return async (action, input = {}, token = null, signal = null) => {
    if (!Object.hasOwn(routes, action)) throw new SafeError("INVALID_ACTION");
    let payload = {};
    if (action === "pair") {
      if (
        typeof input.pairing_code !== "string" ||
        !/^dup_pair_[a-f0-9]{64}$/.test(input.pairing_code)
      )
        throw new SafeError("INVALID_PAIRING");
      payload = { pairing_code: input.pairing_code };
    } else if (!/^dup_ext_[a-f0-9]{64}$/.test(token ?? ""))
      throw new SafeError("UNAUTHENTICATED", 401);
    if (
      ["resolve", "recommend", "voteStatus", "vote", "unvote"].includes(action)
    ) {
      if (!repository(input.repository)) throw new SafeError("INVALID_INPUT");
      payload.repository = input.repository.toLowerCase();
    }
    if (["recommend", "voteStatus", "vote", "unvote"].includes(action)) {
      if (!id(input.project_id)) throw new SafeError("INVALID_INPUT");
      payload.project_id = input.project_id;
    }
    if (action === "recommend") {
      if (!validDraft(input)) throw new SafeError("INVALID_DRAFT");
      payload.title = input.title;
      payload.description = input.description;
    }
    if (["voteStatus", "vote", "unvote"].includes(action)) {
      if (!id(input.issue_id)) throw new SafeError("INVALID_INPUT");
      payload.issue_id = input.issue_id;
    }
    const [method, path] = routes[action];
    const url = new URL("/api/extension/v1/" + path, origin);
    if (method === "GET")
      for (const [k, v] of Object.entries(payload)) url.searchParams.set(k, v);
    const controller = new AbortController();
    const abort = () => controller.abort();
    if (signal?.aborted) abort();
    signal?.addEventListener("abort", abort, { once: true });
    const timer = setTimeout(abort, timeout);
    try {
      const headers = {
        Accept: "application/json",
        "Content-Type": "application/json",
      };
      if (action !== "pair") headers.Authorization = "Bearer " + token;
      const response = await fetcher(url.href, {
        method,
        headers,
        credentials: "omit",
        cache: "no-store",
        redirect: "error",
        referrerPolicy: "no-referrer",
        signal: controller.signal,
        ...(method !== "GET" ? { body: JSON.stringify(payload) } : {}),
      });
      if (!response.ok)
        throw new SafeError("HTTP_" + response.status, response.status);
      return validateResponse(action, await response.json(), payload);
    } catch (e) {
      if (e instanceof SafeError) throw e;
      throw new SafeError(
        controller.signal.aborted ? "TIMEOUT" : "NETWORK_ERROR",
      );
    } finally {
      clearTimeout(timer);
      signal?.removeEventListener("abort", abort);
    }
  };
}
