'use strict';

/** Thin JSON fetch wrapper shared by every API client below. */
async function call(baseUrl, method, path, { body, token, isForm } = {}) {
  const headers = { Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  let requestBody;
  if (isForm) {
    requestBody = body; // caller supplies a FormData
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    requestBody = JSON.stringify(body);
  }

  const res = await fetch(`${baseUrl}${path}`, { method, headers, body: requestBody });
  const text = await res.text();
  let data;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = text;
  }
  return { status: res.status, ok: res.ok, data };
}

/** Admin API (Sanctum bearer, obtained via /admin/login). */
class AdminApi {
  constructor(baseUrl) {
    this.baseUrl = baseUrl;
    this.token = null;
  }

  async login(email, password) {
    const res = await call(this.baseUrl, 'POST', '/admin/login', { body: { email, password } });
    if (!res.ok) throw new Error(`admin login failed: HTTP ${res.status} ${JSON.stringify(res.data)}`);
    this.token = res.data.token;
    return res.data;
  }

  get(path) {
    return call(this.baseUrl, 'GET', path, { token: this.token });
  }

  post(path, body) {
    return call(this.baseUrl, 'POST', path, { body: body ?? {}, token: this.token });
  }

  patch(path, body) {
    return call(this.baseUrl, 'PATCH', path, { body: body ?? {}, token: this.token });
  }

  delete(path) {
    return call(this.baseUrl, 'DELETE', path, { token: this.token });
  }
}

/** Marketplace API (core-token bearer -- an end customer, not Sanctum). */
class MarketplaceApi {
  constructor(baseUrl, token) {
    this.baseUrl = baseUrl;
    this.token = token;
  }

  get(path) {
    return call(this.baseUrl, 'GET', path, { token: this.token });
  }

  post(path, body) {
    return call(this.baseUrl, 'POST', path, { body: body ?? {}, token: this.token });
  }

  postForm(path, formData) {
    return call(this.baseUrl, 'POST', path, { body: formData, token: this.token, isForm: true });
  }
}

/** The LocalCoreStub's own admin surface -- service-credential bearer, for
 * exact-balance assertions the UI/API doesn't otherwise expose. */
class CoreStubApi {
  constructor(baseUrl, serviceCredential) {
    this.baseUrl = baseUrl;
    this.token = serviceCredential;
  }

  async balance(userRef) {
    const res = await call(this.baseUrl, 'GET', `/dev/core/coins/balance?user_ref=${encodeURIComponent(userRef)}`, { token: this.token });
    if (!res.ok) throw new Error(`core-stub balance check failed: HTTP ${res.status} ${JSON.stringify(res.data)}`);
    return res.data.balance;
  }

  /** Test-arrangement only: drain a user's balance via the real dev-core
   * deduct endpoint (the same one SubmitOrder itself calls), so a later
   * submit can be shown to hit a genuine 402. */
  async deduct(userRef, amount, idempotencyKey) {
    const res = await call(this.baseUrl, 'POST', '/dev/core/coins/deduct', {
      body: { user_ref: userRef, amount, idempotency_key: idempotencyKey },
      token: this.token,
    });
    if (!res.ok) throw new Error(`core-stub deduct (arrange) failed: HTTP ${res.status} ${JSON.stringify(res.data)}`);
    return res.data.txn_ref;
  }
}

/** The mock external service's runtime control surface (not contract v1). */
class MockControl {
  constructor(baseUrl) {
    this.baseUrl = baseUrl;
  }

  setMode(mode) {
    return call(this.baseUrl, 'POST', '/admin/mode', { body: { mode } });
  }

  configure(webhookUrl) {
    return call(this.baseUrl, 'POST', '/admin/configure', { body: { webhook_url: webhookUrl } });
  }

  reset() {
    return call(this.baseUrl, 'POST', '/admin/reset', { body: {} });
  }

  health() {
    return call(this.baseUrl, 'GET', '/health');
  }

  async jobs() {
    const res = await call(this.baseUrl, 'GET', '/admin/jobs');
    if (!res.ok) throw new Error(`mock-service /admin/jobs failed: HTTP ${res.status}`);
    return res.data.jobs;
  }
}

module.exports = { AdminApi, MarketplaceApi, CoreStubApi, MockControl };
