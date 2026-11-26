export class SafeError extends Error {
  constructor(code, status = 0) {
    super(code);
    this.code = code;
    this.status = status;
  }
}
export const id = (x) => Number.isSafeInteger(x) && x > 0;
export const repository = (x) =>
  typeof x === "string" &&
  /^[A-Za-z0-9-]{1,39}\/(?!\.{1,2}$)[A-Za-z0-9_.-]{1,100}$/.test(x);
export function parseRepository(input) {
  if (typeof input !== "string" || /%(?:2e|2f|5c)|\\|\/\.{1,2}\//i.test(input))
    return null;
  try {
    const u = new URL(input);
    if (u.origin !== "https://github.com" || u.username || u.password)
      return null;
    const match = /^\/([^/]+\/[^/]+)\/issues\/new\/?$/.exec(u.pathname);
    return match && repository(match[1]) ? match[1].toLowerCase() : null;
  } catch {
    return null;
  }
}
export function validDraft(p) {
  return (
    typeof p.title === "string" &&
    [...p.title.trim()].length >= 5 &&
    [...p.title.trim()].length <= 200 &&
    typeof p.description === "string" &&
    [...p.description.trim()].length >= 10 &&
    [...p.description.trim()].length <= 10000
  );
}
export function safeDetail(path, projectId, targetId, origin) {
  return path === "/projects/" + projectId + "/issues/" + targetId
    ? origin + path
    : null;
}
export function validateResponse(action, value, input = {}) {
  const bad = () => {
    throw new SafeError("INVALID_RESPONSE");
  };
  if (!value || typeof value !== "object") bad();
  if (action === "pair") {
    if (
      !/^dup_ext_[a-f0-9]{64}$/.test(value.access_token) ||
      value.token_type !== "Bearer" ||
      !Number.isFinite(Date.parse(value.expires_at)) ||
      Date.parse(value.expires_at) <= Date.now()
    )
      bad();
    return { access_token: value.access_token, expires_at: value.expires_at };
  }
  if (action === "me") {
    if (
      !id(value.user?.id) ||
      value.user.role !== "REPORTER" ||
      typeof value.user.name !== "string"
    )
      bad();
    return {
      user: {
        id: value.user.id,
        name: value.user.name.slice(0, 200),
        role: "REPORTER",
      },
    };
  }
  if (action === "disconnect") {
    if (value.disconnected !== true) bad();
    return { disconnected: true };
  }
  if (action === "resolve") {
    if (
      value.repository !== input.repository.toLowerCase() ||
      !Array.isArray(value.projects) ||
      value.projects.some((p) => !id(p.id) || typeof p.name !== "string")
    )
      bad();
    return {
      projects: value.projects.map((p) => ({
        id: p.id,
        name: p.name.slice(0, 200),
      })),
    };
  }
  if (action === "recommend") {
    if (
      !Array.isArray(value.recommendations) ||
      value.recommendations.length > 3
    )
      bad();
    return {
      recommendations: value.recommendations.map((r) => {
        if (
          !id(r.issue_id) ||
          !id(r.target_issue_id) ||
          typeof r.title !== "string" ||
          typeof r.description !== "string" ||
          typeof r.status !== "string" ||
          typeof r.similarity !== "number" ||
          r.similarity < 0 ||
          r.similarity > 1 ||
          !Number.isFinite(r.cosine_similarity) ||
          Math.abs(r.cosine_similarity) > 1.000001 ||
          r.detail_url !==
            "/projects/" + input.project_id + "/issues/" + r.target_issue_id
        )
          bad();
        return Object.fromEntries(
          [
            "issue_id",
            "target_issue_id",
            "title",
            "description",
            "status",
            "similarity",
            "cosine_similarity",
            "detail_url",
          ].map((k) => [k, r[k]]),
        );
      }),
    };
  }
  if (["voteStatus", "vote", "unvote"].includes(action)) {
    if (
      value.issue_id !== input.issue_id ||
      !id(value.target_issue_id) ||
      typeof value.voted !== "boolean" ||
      !Number.isSafeInteger(value.vote_count) ||
      value.vote_count < 0 ||
      typeof value.changed !== "boolean"
    )
      bad();
    return {
      issue_id: value.issue_id,
      target_issue_id: value.target_issue_id,
      voted: value.voted,
      vote_count: value.vote_count,
      changed: value.changed,
    };
  }
  bad();
}
