
/** API client for existing PHP backend */
const DEFAULT_API = new URL("../../api/", window.location.href).href.replace(/\/?$/, "/");

export const API_BASE = window.__HOTEL_API__ || DEFAULT_API;

let csrfToken = sessionStorage.getItem("csrf_token") || "";

export function setCsrfToken(token) {
  if (token) {
    csrfToken = token;
    try { sessionStorage.setItem("csrf_token", token); } catch (_) {}
  }
}

export async function api(path, options = {}) {
  const method = (options.method || "GET").toUpperCase();
  const headers = {
    Accept: "application/json",
    ...(options.body && !(options.body instanceof FormData)
      ? { "Content-Type": "application/json" }
      : {}),
    ...(options.headers || {}),
  };
  if (method !== "GET" && method !== "HEAD" && csrfToken) {
    headers["X-CSRF-Token"] = csrfToken;
  }

  const res = await fetch(API_BASE + path.replace(/^\//, ""), {
    credentials: "include",
    ...options,
    method,
    headers,
    body:
      options.body && typeof options.body === "object" && !(options.body instanceof FormData)
        ? JSON.stringify(options.body)
        : options.body,
  });

  let data = null;
  try {
    data = await res.json();
  } catch (_) {
    data = { success: false, message: "Invalid server response." };
  }

  if (data?.data?.csrf_token) setCsrfToken(data.data.csrf_token);
  if (data?.csrf_token) setCsrfToken(data.csrf_token);

  if (!res.ok || data?.success === false) {
    const err = new Error(data?.message || `Request failed (${res.status})`);
    err.status = res.status;
    err.payload = data;
    throw err;
  }
  return data;
}

export function kes(n) {
  return "KSh " + Number(n || 0).toLocaleString("en-KE", { maximumFractionDigits: 0 });
}

export function ymd(offsetDays = 0) {
  const d = new Date();
  d.setDate(d.getDate() + offsetDays);
  return d.toISOString().slice(0, 10);
}
