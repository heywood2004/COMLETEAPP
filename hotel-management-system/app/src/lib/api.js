/**
 * Talks to the existing PHP API (same contracts as public/js/utils.js).
 * Dev: Vite proxies /api → XAMPP.
 * Prod (built into public/spa): relative ../api
 */
const API_BASE = import.meta.env.DEV ? "/api" : "../api";

let csrfToken = sessionStorage.getItem("csrf_token") || "";

export function setCsrfToken(token) {
  if (token) {
    csrfToken = token;
    try {
      sessionStorage.setItem("csrf_token", token);
    } catch {
      /* ignore */
    }
  }
}

export function getCsrfToken() {
  return csrfToken || sessionStorage.getItem("csrf_token") || "";
}

export async function apiFetch(endpoint, options = {}) {
  const { timeout = 30000, ...fetchOpts } = options;
  const url =
    endpoint.startsWith("http") || endpoint.startsWith("/")
      ? endpoint.startsWith("/api")
        ? endpoint
        : `${API_BASE}/${endpoint.replace(/^\//, "")}`
      : `${API_BASE}/${endpoint}`;

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout);
  const method = (fetchOpts.method || "GET").toUpperCase();
  const headers = {
    Accept: "application/json",
    ...(fetchOpts.body && !(fetchOpts.body instanceof FormData)
      ? { "Content-Type": "application/json" }
      : {}),
    ...(fetchOpts.headers || {}),
  };
  if (method !== "GET" && method !== "HEAD" && method !== "OPTIONS") {
    const token = getCsrfToken();
    if (token) headers["X-CSRF-Token"] = token;
  }

  try {
    const res = await fetch(url, {
      credentials: "include",
      ...fetchOpts,
      headers,
      signal: controller.signal,
    });
    const text = await res.text();
    let body = null;
    try {
      body = text ? JSON.parse(text) : null;
    } catch {
      throw new Error(res.ok ? "Invalid server response." : `HTTP ${res.status}`);
    }
    if (body?.data?.csrf_token) setCsrfToken(body.data.csrf_token);
    if (body?.csrf_token) setCsrfToken(body.csrf_token);
    if (!res.ok || body?.success === false) {
      throw new Error(body?.message || `Request failed (${res.status})`);
    }
    return body;
  } finally {
    clearTimeout(timer);
  }
}

export function unwrap(body) {
  return body?.data ?? body;
}

export function kes(n) {
  return `KSh ${Number(n || 0).toLocaleString("en-KE", { maximumFractionDigits: 0 })}`;
}

export function nightsBetween(checkIn, checkOut) {
  const a = Date.parse(`${checkIn}T00:00:00`);
  const b = Date.parse(`${checkOut}T00:00:00`);
  if (!Number.isFinite(a) || !Number.isFinite(b)) return 0;
  return Math.max(0, Math.round((b - a) / 86400000));
}
