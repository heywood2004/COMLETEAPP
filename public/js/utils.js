"use strict";

/**
 * Shared utilities for the Hotel Management System
 * Includes resilient API error handling
 */

const API = "../api";

/** CSRF token from session (refreshed via session.php / login) */
let csrfToken = sessionStorage.getItem("csrf_token") || "";

function setCsrfToken(token) {
  if (token) {
    csrfToken = token;
    try { sessionStorage.setItem("csrf_token", token); } catch (e) {}
  }
}

function getCsrfToken() {
  return csrfToken || sessionStorage.getItem("csrf_token") || "";
}

/** Friendly messages for common HTTP / network failures */
const HTTP_ERROR_HINTS = {
  400: "Invalid request. Please check your input.",
  401: "Please log in to continue.",
  403: "You do not have permission to do that.",
  404: "The requested resource was not found.",
  405: "This action is not supported.",
  409: "This conflicts with existing data.",
  422: "Some fields are invalid.",
  429: "Too many requests. Please wait a moment.",
  500: "Server error. Please try again later.",
  502: "Service temporarily unavailable.",
  503: "Service temporarily unavailable."
};

/**
 * apiFetch – robust fetch wrapper
 * @param {string} endpoint
 * @param {RequestInit & { timeout?: number, silent?: boolean }} options
 * @returns {Promise<object>} Parsed JSON body with success=true
 */
async function apiFetch(endpoint, options = {}) {
  const { timeout = 30000, silent = false, ...fetchOpts } = options;

  const url =
    endpoint.startsWith("http") ||
    endpoint.startsWith("../") ||
    endpoint.startsWith("/")
      ? endpoint
      : `${API}/${endpoint}`;

  const controller = typeof AbortController !== "undefined" ? new AbortController() : null;
  let timer = null;
  if (controller && timeout > 0) {
    timer = setTimeout(() => controller.abort(), timeout);
  }

  const method = (fetchOpts.method || "GET").toUpperCase();
  const headers = {
    ...(fetchOpts.body && !(fetchOpts.body instanceof FormData)
      ? { "Content-Type": "application/json" }
      : {}),
    Accept: "application/json",
    ...(fetchOpts.headers || {})
  };
  if (method !== "GET" && method !== "HEAD" && method !== "OPTIONS") {
    const token = getCsrfToken();
    if (token) headers["X-CSRF-Token"] = token;
  }

  const fetchOptions = {
    credentials: "same-origin",
    ...fetchOpts,
    signal: controller ? controller.signal : fetchOpts.signal,
    headers
  };

  let response;
  try {
    response = await fetch(url, fetchOptions);
  } catch (err) {
    if (timer) clearTimeout(timer);
    if (err && err.name === "AbortError") {
      throw makeApiError("Request timed out. Check your connection and try again.", {
        code: "TIMEOUT",
        status: 0
      });
    }
    throw makeApiError(
      "Network error. Please check your internet connection and try again.",
      { code: "NETWORK_ERROR", status: 0, cause: err }
    );
  } finally {
    if (timer) clearTimeout(timer);
  }

  // Session expired → redirect to login (except on login/register/public pages)
  if (response.status === 401) {
    const onAuthPage =
      /index\.html|register\.html|\/$/.test(window.location.pathname) ||
      window.location.pathname.endsWith("/");
    if (!onAuthPage && !silent) {
      // Soft redirect after short delay so UI can show message
      setTimeout(() => {
        window.location.href = "index.html";
      }, 1200);
    }
  }

  let data = null;
  const contentType = response.headers.get("content-type") || "";
  if (contentType.includes("application/json")) {
    try {
      data = await response.json();
    } catch (e) {
      throw makeApiError(`Invalid JSON response from server (HTTP ${response.status}).`, {
        code: "INVALID_RESPONSE",
        status: response.status
      });
    }
  } else {
    // Non-JSON (HTML error page, empty, etc.)
    let text = "";
    try {
      text = await response.text();
    } catch (e) {}
    if (!response.ok) {
      throw makeApiError(
        HTTP_ERROR_HINTS[response.status] ||
          `Request failed (HTTP ${response.status}).`,
        {
          code: "HTTP_" + response.status,
          status: response.status,
          body: text ? text.slice(0, 200) : null
        }
      );
    }
    throw makeApiError("Server returned a non-JSON response.", {
      code: "INVALID_RESPONSE",
      status: response.status
    });
  }

  if (!response.ok || data.success === false) {
    const message =
      data?.message ||
      data?.data?.message ||
      HTTP_ERROR_HINTS[response.status] ||
      `Request failed (HTTP ${response.status})`;

    throw makeApiError(message, {
      code: data?.code || "HTTP_" + response.status,
      status: response.status,
      data: data?.data ?? null
    });
  }

  return data;
}

function makeApiError(message, extra = {}) {
  const err = new Error(message);
  err.name = "ApiError";
  err.code = extra.code || "ERROR";
  err.status = extra.status ?? 0;
  err.data = extra.data ?? null;
  if (extra.cause) err.cause = extra.cause;
  return err;
}

/**
 * Show a user-friendly message for any thrown value
 */
function handleClientError(err, fallback = "Something went wrong.") {
  const message =
    (err && err.message) ||
    (typeof err === "string" ? err : fallback);
  showMessage(message, "error");
  if (typeof console !== "undefined") {
    console.warn("[API]", err?.code || "", message, err);
  }
  return message;
}

function unwrapData(result) {
  return result?.data ?? result ?? null;
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

const escapeHTML = escapeHtml;

function formatMoney(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) return "0";
  return number.toLocaleString("en-KE", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  });
}

function getToday() {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function setMinimumDates(checkInId = "checkIn", checkOutId = "checkOut") {
  const today = getToday();
  const checkIn = document.getElementById(checkInId);
  const checkOut = document.getElementById(checkOutId);
  if (checkIn) checkIn.min = today;
  if (checkOut) checkOut.min = checkIn?.value || today;
}

function setText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value ?? "";
}

function getValue(id) {
  const el = document.getElementById(id);
  return el ? el.value.trim() : "";
}

function setValue(id, value) {
  const el = document.getElementById(id);
  if (el) el.value = value ?? "";
}

function setLoading(isLoading) {
  const loading =
    document.getElementById("loading") ||
    document.getElementById("loadingOverlay");
  if (!loading) return;
  loading.style.display = isLoading ? "flex" : "none";
  loading.setAttribute("aria-hidden", String(!isLoading));
}

function showMessage(message, type = "info") {
  const box =
    document.getElementById("messageBox") ||
    document.getElementById("systemMessage") ||
    document.getElementById("loginMessage") ||
    document.getElementById("registerMessage");
  if (!box) {
    console.log(`${type}: ${message}`);
    return;
  }
  box.textContent = message;
  box.className = `message ${type}`;
  box.style.display = "block";
  box.setAttribute("role", "alert");
  clearTimeout(showMessage.timer);
  showMessage.timer = setTimeout(() => {
    box.style.display = "none";
  }, type === "error" ? 7000 : 5000);
}

function isRoomAvailable(room) {
  return String(room?.status || "").toLowerCase() === "available";
}

window.apiFetch = apiFetch;
window.setCsrfToken = setCsrfToken;
window.getCsrfToken = getCsrfToken;
window.handleClientError = handleClientError;
window.unwrapData = unwrapData;
window.escapeHtml = escapeHtml;
window.escapeHTML = escapeHtml;
window.formatMoney = formatMoney;
window.getToday = getToday;
window.setMinimumDates = setMinimumDates;
window.setText = setText;
window.getValue = getValue;
window.setValue = setValue;
window.setLoading = setLoading;
window.showMessage = showMessage;
window.isRoomAvailable = isRoomAvailable;
