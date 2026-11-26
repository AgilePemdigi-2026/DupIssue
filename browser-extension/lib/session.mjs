import { SafeError } from "./contracts.mjs";
export class Session {
  constructor(storage, api) {
    this.storage = storage;
    this.api = api;
    this.queue = Promise.resolve();
  }
  async init() {
    await this.storage.setAccessLevel({ accessLevel: "TRUSTED_CONTEXTS" });
  }
  edit(fn) {
    const task = this.queue.then(async () => {
      const state = (await this.storage.get("auth")).auth ?? { generation: 0 };
      const result = await fn(state);
      await this.storage.set({ auth: state });
      return result;
    });
    this.queue = task.catch(() => {});
    return task;
  }
  async snapshot() {
    await this.queue;
    return (await this.storage.get("auth")).auth ?? { generation: 0 };
  }
  async clear(expected) {
    return this.edit((s) => {
      if (expected === undefined || s.generation === expected) {
        s.generation++;
        delete s.token;
        delete s.user;
        delete s.expiresAt;
      }
    });
  }
  async publicState(verify = true) {
    const s = await this.snapshot();
    if (!s.token) return { connected: false, warning: s.warning ?? null };
    if (Date.parse(s.expiresAt) <= Date.now()) {
      await this.clear(s.generation);
      return { connected: false, expired: true };
    }
    if (verify) await this.call("me");
    const latest = await this.snapshot();
    return latest.token
      ? {
          connected: true,
          user: latest.user,
          expiresAt: latest.expiresAt,
          warning: latest.warning ?? null,
        }
      : { connected: false };
  }
  async call(action, input = {}, signal = null) {
    const s = await this.snapshot();
    if (!s.token || Date.parse(s.expiresAt) <= Date.now()) {
      await this.clear(s.generation);
      throw new SafeError("UNAUTHENTICATED", 401);
    }
    try {
      const response = await this.api(action, input, s.token, signal);
      if ((await this.snapshot()).generation !== s.generation)
        throw new SafeError("STALE_SESSION");
      return response;
    } catch (e) {
      if (e.status === 401) await this.clear(s.generation);
      throw e;
    }
  }
  async pair(code) {
    const generation = await this.edit((s) => {
      if (s.token) throw new SafeError("DISCONNECT_FIRST");
      delete s.warning;
      return ++s.generation;
    });
    let issued;
    try {
      issued = await this.api("pair", { pairing_code: code });
      const identity = await this.api("me", {}, issued.access_token);
      const accepted = await this.edit((s) => {
        if (s.generation !== generation) return false;
        s.token = issued.access_token;
        s.expiresAt = issued.expires_at;
        s.user = identity.user;
        return true;
      });
      if (!accepted) throw new SafeError("STALE_PAIRING");
      return this.publicState(false);
    } catch (e) {
      if (issued) {
        try {
          await this.api("disconnect", {}, issued.access_token);
        } catch {
          await this.edit((s) => {
            s.warning = "WEB_REVOCATION_REQUIRED";
          });
        }
      }
      throw e;
    }
  }
  async disconnect() {
    const s = await this.edit((s) => {
      s.generation++;
      return { ...s };
    });
    if (s.token) {
      try {
        await this.api("disconnect", {}, s.token);
      } catch (e) {
        if (e.status !== 401) throw new SafeError("REVOCATION_UNCONFIRMED");
      }
    }
    await this.clear(s.generation);
    return { connected: false, serverRevoked: true };
  }
}
