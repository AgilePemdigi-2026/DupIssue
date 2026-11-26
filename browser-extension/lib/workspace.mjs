import { SafeError, parseRepository, validDraft } from "./contracts.mjs";

// Drafts and results are ephemeral. Worker restart deliberately resets consent.
export class Workspaces {
  constructor(session, tabs, timers = globalThis) {
    this.session = session;
    this.tabs = tabs;
    this.timers = timers;
    this.states = new Map();
  }
  reset(tabId) {
    const old = this.states.get(tabId);
    if (old) {
      this.timers.clearTimeout(old.timer);
      old.abort?.abort();
      old.revision++;
    }
    this.states.delete(tabId);
  }
  resetAll() {
    for (const key of this.states.keys()) this.reset(key);
  }
  async current(tabId) {
    const repository = parseRepository((await this.tabs.get(tabId)).url);
    if (!repository) {
      this.reset(tabId);
      throw new SafeError("UNSUPPORTED_PAGE");
    }
    let state = this.states.get(tabId);
    if (state?.repository !== repository) {
      this.reset(tabId);
      state = null;
    }
    if (!state) {
      state = {
        repository,
        projects: [],
        projectId: null,
        enabled: false,
        revision: 0,
        status: "DISABLED",
        recommendations: [],
      };
      this.states.set(tabId, state);
    }
    return state;
  }
  invalidate(s, status = "DISABLED") {
    this.timers.clearTimeout(s.timer);
    s.abort?.abort();
    s.revision++;
    s.recommendations = [];
    s.status = status;
  }
  public(s) {
    return {
      repository: s.repository,
      projects: s.projects,
      projectId: s.projectId,
      enabled: s.enabled,
      status: s.status,
      recommendations: s.recommendations,
    };
  }
  async resolve(tabId) {
    const s = await this.current(tabId);
    this.invalidate(s);
    s.enabled = false;
    s.draft = null;
    const revision = s.revision;
    const result = await this.session.call("resolve", {
      repository: s.repository,
    });
    if (this.states.get(tabId) !== s || revision !== s.revision)
      throw new SafeError("STALE_REQUEST");
    s.projects = result.projects;
    s.projectId = result.projects.length === 1 ? result.projects[0].id : null;
    s.status = s.projects.length ? "DISABLED" : "UNAUTHORIZED_REPOSITORY";
    return this.public(s);
  }
  async action(message) {
    const { action, tabId } = message;
    if (action === "resolve") return this.resolve(tabId);
    const s = await this.current(tabId);
    // Trusted UI reads only the state already obtained from authorized API calls.
    // The separate view action still revalidates access and votes every 15 seconds.
    if (action === "snapshot") return this.public(s);
    if (action === "disable") {
      this.invalidate(s);
      s.enabled = false;
      s.draft = null;
      return this.public(s);
    }
    try {
      await this.session.call("me");
      if (this.states.get(tabId) !== s) throw new SafeError("STALE_REQUEST");
      if (action === "view") {
        const revision = s.revision;
        if (s.projectId) {
          const access = await this.session.call("resolve", {
            repository: s.repository,
          });
          if (!access.projects.some((p) => p.id === s.projectId)) {
            this.invalidate(s, "UNAUTHORIZED_REPOSITORY");
            s.enabled = false;
            s.draft = null;
            throw new SafeError("UNAUTHORIZED_REPOSITORY");
          }
          if (s.status === "READY") {
            const rows = await Promise.all(
              s.recommendations.map(async (r) => ({
                ...r,
                vote: await this.session.call("voteStatus", {
                  repository: s.repository,
                  project_id: s.projectId,
                  issue_id: r.issue_id,
                }),
              })),
            );
            if (s.revision !== revision || this.states.get(tabId) !== s)
              throw new SafeError("STALE_REQUEST");
            if (
              rows.some((r) => r.vote.target_issue_id !== r.target_issue_id)
            ) {
              this.invalidate(s, "TARGET_CHANGED");
              throw new SafeError("TARGET_CHANGED");
            }
            s.recommendations = rows;
          }
        }
        if (s.revision !== revision || this.states.get(tabId) !== s)
          throw new SafeError("STALE_REQUEST");
        return this.public(s);
      }
      if (action === "selectProject" || action === "enable") {
        this.invalidate(s);
        s.enabled = false;
        s.draft = null;
        const revision = s.revision;
        const access = await this.session.call("resolve", {
          repository: s.repository,
        });
        if (this.states.get(tabId) !== s || s.revision !== revision)
          throw new SafeError("STALE_REQUEST");
        s.projects = access.projects;
        if (!s.projects.some((p) => p.id === message.projectId))
          throw new SafeError("UNAUTHORIZED_REPOSITORY");
        s.projectId = message.projectId;
        s.enabled = action === "enable";
        s.consentEpoch = crypto.randomUUID();
        s.status = s.enabled ? "WAITING_DRAFT" : "DISABLED";
      } else if (action === "retry") {
        if (s.enabled && s.draft) this.schedule(tabId, s, s.draft);
      } else if (action === "vote" || action === "unvote") {
        const item = s.recommendations.find(
          (r) => r.issue_id === message.issueId,
        );
        if (!s.enabled || !item || s.voting)
          throw new SafeError("INVALID_ACTION");
        s.voting = true;
        const revision = s.revision;
        try {
          const vote = await this.session.call(action, {
            repository: s.repository,
            project_id: s.projectId,
            issue_id: item.issue_id,
          });
          if (revision !== s.revision || this.states.get(tabId) !== s)
            throw new SafeError("STALE_REQUEST");
          if (vote.target_issue_id !== item.target_issue_id) {
            this.invalidate(s, "TARGET_CHANGED");
            throw new SafeError("TARGET_CHANGED");
          }
          item.vote = vote;
        } finally {
          s.voting = false;
        }
      } else throw new SafeError("INVALID_ACTION");
      return this.public(s);
    } catch (e) {
      if ([401, 403, 404].includes(e.status)) {
        this.invalidate(s, e.code);
        s.enabled = false;
        s.draft = null;
      } else if (
        action === "view" &&
        e.code !== "STALE_REQUEST" &&
        this.states.get(tabId) === s
      ) {
        this.invalidate(s, e.code ?? "UNAVAILABLE");
      }
      throw e;
    }
  }
  async content(message, context) {
    const s = await this.current(context.tabId);
    if (s.repository !== context.repository || !s.enabled)
      return { enabled: false };
    if (message.action === "draft")
      this.schedule(context.tabId, s, {
        title: message.title,
        description: message.description,
      });
    // Never return projects, identity, recommendations or credentials to GitHub.
    return { enabled: s.enabled, consentEpoch: s.consentEpoch };
  }
  schedule(tabId, s, draft) {
    this.invalidate(s, validDraft(draft) ? "WAITING" : "INVALID_DRAFT");
    s.draft = draft;
    if (!validDraft(draft)) return;
    const revision = s.revision;
    s.timer = this.timers.setTimeout(
      () => this.recommend(tabId, s, draft, revision),
      500,
    );
  }
  async recommend(tabId, s, draft, revision) {
    const fresh = () =>
      this.states.get(tabId) === s && s.revision === revision && s.enabled;
    if (!fresh()) return;
    s.abort = new AbortController();
    s.status = "LOADING";
    try {
      if ((await this.current(tabId)) !== s || !fresh()) return;
      const input = {
        repository: s.repository,
        project_id: s.projectId,
        ...draft,
      };
      const result = await this.session.call(
        "recommend",
        input,
        s.abort.signal,
      );
      if (!fresh()) return;
      const rows = await Promise.all(
        result.recommendations.map(async (r) => ({
          ...r,
          vote: await this.session.call(
            "voteStatus",
            { ...input, issue_id: r.issue_id },
            s.abort.signal,
          ),
        })),
      );
      if (!fresh()) return;
      if (rows.some((r) => r.vote.target_issue_id !== r.target_issue_id))
        throw new SafeError("TARGET_CHANGED");
      s.recommendations = rows;
      s.status = rows.length ? "READY" : "EMPTY";
    } catch (e) {
      if (fresh()) {
        this.invalidate(s, e.code ?? "UNAVAILABLE");
        if ([401, 403, 404].includes(e.status)) {
          s.enabled = false;
          s.draft = null;
        }
      }
    }
  }
}
