
import { api, setCsrfToken } from "./api.js";

export async function loadSession() {
  const res = await api("session.php");
  const user = res?.data?.user ?? null;
  if (res?.data?.csrf_token) setCsrfToken(res.data.csrf_token);
  return user;
}

export async function login(email, password) {
  const res = await api("login.php", {
    method: "POST",
    body: { email, password },
  });
  if (res?.data?.csrf_token) setCsrfToken(res.data.csrf_token);
  return res?.data?.user || res?.data;
}

export async function register(payload) {
  return api("register.php", { method: "POST", body: payload });
}

export async function logout() {
  try {
    await api("logout.php", { method: "POST", body: {} });
  } catch (_) {}
}
