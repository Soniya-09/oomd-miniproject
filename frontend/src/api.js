// Thin client for the ScheduNova Frappe backend.
// Auth uses Frappe's session cookie; every write sends X-Frappe-CSRF-Token.

let csrfToken = null;

export class ApiError extends Error {
  constructor(message, status, type) {
    super(message);
    this.status = status;
    this.type = type;
  }
}

// Frappe packs user-facing errors into _server_messages (a JSON list of JSON strings).
function errorMessage(body, fallback) {
  if (body?._server_messages) {
    try {
      const text = JSON.parse(body._server_messages)
        .map((m) => {
          try {
            return JSON.parse(m).message;
          } catch {
            return m;
          }
        })
        .join(" ")
        .replace(/<[^>]+>/g, "")
        .trim();
      if (text) return text;
    } catch {
      /* fall through */
    }
  }
  if (body?._error_message) return body._error_message;
  if (body?.exc_type && typeof body.message === "string") return body.message;
  if (body?.exception) return body.exception.replace(/^[\w.]+:\s*/, "");
  return fallback;
}

async function request(path, { method = "GET", body, retried = false } = {}) {
  const headers = { Accept: "application/json" };
  if (body !== undefined) headers["Content-Type"] = "application/json";
  if (method !== "GET" && csrfToken) headers["X-Frappe-CSRF-Token"] = csrfToken;

  const res = await fetch(path, {
    method,
    headers,
    credentials: "same-origin",
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const text = await res.text();
  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = null;
  }

  // The CSRF token is tied to the session; fetch a fresh one and retry once.
  if (data?.exc_type === "CSRFTokenError" && !retried) {
    await refreshCsrf();
    return request(path, { method, body, retried: true });
  }

  if (!res.ok) {
    const fallback =
      res.status === 502 || res.status === 504
        ? "Can't reach the ScheduNova server. Is the backend running on port 8002?"
        : `Request failed (${res.status})`;
    throw new ApiError(errorMessage(data, fallback), res.status, data?.exc_type);
  }
  return data;
}

export async function refreshCsrf() {
  const r = await request("/api/method/schedunova.api.get_csrf_token");
  csrfToken = r.message.csrf_token;
}

export const auth = {
  async me() {
    const r = await request("/api/method/schedunova.api.me");
    return r.message;
  },
  async login(email, password) {
    await request("/api/method/login", { method: "POST", body: { usr: email, pwd: password } });
    await refreshCsrf();
  },
  async signUp(fullName, email, password) {
    const r = await request("/api/method/schedunova.api.sign_up", {
      method: "POST",
      body: { email, full_name: fullName, password },
    });
    return r.message;
  },
  async logout() {
    try {
      await request("/api/method/logout", { method: "POST" });
    } finally {
      csrfToken = null;
    }
  },
};

const RESOURCE = "/api/resource/Nova%20Task";
const FIELDS = [
  "name",
  "title",
  "priority",
  "duration_min",
  "fixed_start",
  "deadline",
  "context",
  "done",
  "raw",
  "modified",
];

export const tasks = {
  // Permissions (if_owner) mean each user only ever gets their own tasks back.
  async list() {
    const qs = new URLSearchParams({
      fields: JSON.stringify(FIELDS),
      limit_page_length: "0",
      order_by: "modified desc",
    });
    const r = await request(`${RESOURCE}?${qs}`);
    return r.data;
  },
  async create(task) {
    const r = await request(RESOURCE, { method: "POST", body: task });
    return r.data;
  },
  async update(name, patch) {
    const r = await request(`${RESOURCE}/${encodeURIComponent(name)}`, { method: "PUT", body: patch });
    return r.data;
  },
  async remove(name) {
    await request(`${RESOURCE}/${encodeURIComponent(name)}`, { method: "DELETE" });
  },
};
