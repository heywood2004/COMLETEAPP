/**
 * Heywood's Hotel – Phase 2 SPA (no build step)
 * Uses the same PHP APIs as the classic site.
 */
const API = "../api";
let csrfToken = sessionStorage.getItem("csrf_token") || "";
let user = null;
let route = "rooms";

function setCsrf(t) {
  if (!t) return;
  csrfToken = t;
  try { sessionStorage.setItem("csrf_token", t); } catch {}
}

function kes(n) {
  return "KSh " + Number(n || 0).toLocaleString("en-KE", { maximumFractionDigits: 0 });
}

function nightsBetween(a, b) {
  const x = Date.parse(a + "T00:00:00");
  const y = Date.parse(b + "T00:00:00");
  if (!Number.isFinite(x) || !Number.isFinite(y)) return 0;
  return Math.max(0, Math.round((y - x) / 86400000));
}

function shiftDate(days) {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

function isStaff() {
  return user && ["admin", "manager", "receptionist", "accountant"].includes(user.role);
}

async function apiFetch(endpoint, options = {}) {
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
  const res = await fetch(`${API}/${endpoint.replace(/^\//, "")}`, {
    credentials: "include",
    ...options,
    headers,
  });
  const text = await res.text();
  let body;
  try {
    body = text ? JSON.parse(text) : {};
  } catch {
    throw new Error(res.ok ? "Invalid server response." : `HTTP ${res.status}`);
  }
  if (body?.data?.csrf_token) setCsrf(body.data.csrf_token);
  if (body?.csrf_token) setCsrf(body.csrf_token);
  if (!res.ok || body.success === false) {
    throw new Error(body.message || `Request failed (${res.status})`);
  }
  return body;
}

function unwrap(body) {
  return body?.data ?? body;
}

async function refreshSession() {
  try {
    const body = await apiFetch("session.php");
    const data = unwrap(body) || {};
    if (data.csrf_token) setCsrf(data.csrf_token);
    user = data.authenticated && data.user ? data.user : null;
  } catch {
    user = null;
  }
}

function go(name) {
  route = name;
  render();
}

function el(html) {
  const t = document.createElement("template");
  t.innerHTML = html.trim();
  return t.content.firstChild;
}

function shell(content) {
  const staff = isStaff();
  return `
  <div class="shell">
    <header class="topbar">
      <a href="#" class="brand" data-go="rooms">Heywood's Hotel</a>
      <nav class="nav-desktop">
        <button type="button" class="nav-link ${route === "rooms" ? "active" : ""}" data-go="rooms">Rooms</button>
        <button type="button" class="nav-link ${route === "stays" ? "active" : ""}" data-go="stays">My stays</button>
        ${staff ? `<button type="button" class="nav-link ${route === "desk" ? "active" : ""}" data-go="desk">Desk</button>` : ""}
      </nav>
      <div style="display:flex;align-items:center;gap:.5rem">
        ${user
          ? `<span class="badge">${escapeHtml((user.name || "Guest").split(" ")[0])}</span>
             <button type="button" class="btn btn-ghost" id="btnLogout">Log out</button>`
          : `<button type="button" class="btn btn-primary" data-go="login">Sign in</button>`}
      </div>
    </header>
    <main class="main">${content}</main>
    <nav class="bottom-nav">
      <button type="button" class="${route === "rooms" ? "active" : ""}" data-go="rooms"><span>⌂</span>Rooms</button>
      <button type="button" class="${route === "stays" ? "active" : ""}" data-go="stays"><span>☰</span>Stays</button>
      <button type="button" class="${route === "desk" || route === "login" ? "active" : ""}" data-go="${staff ? "desk" : "login"}"><span>✦</span>${staff ? "Desk" : "Account"}</button>
    </nav>
  </div>`;
}

function escapeHtml(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

async function render() {
  const root = document.getElementById("root");
  if (route === "login") {
    root.innerHTML = loginView();
    wireLogin();
    return;
  }
  if (route === "rooms") {
    root.innerHTML = shell(`<p class="muted">Loading rooms…</p>`);
    wireShell();
    await renderRooms();
    return;
  }
  if (route === "stays") {
    if (!user) { go("login"); return; }
    root.innerHTML = shell(`<p class="muted">Loading stays…</p>`);
    wireShell();
    await renderStays();
    return;
  }
  if (route === "desk") {
    if (!user) { go("login"); return; }
    root.innerHTML = shell(`<p class="muted">Opening desk…</p>`);
    wireShell();
    await renderDesk();
    return;
  }
  go("rooms");
}

function wireShell() {
  document.querySelectorAll("[data-go]").forEach((b) => {
    b.addEventListener("click", (e) => {
      e.preventDefault();
      go(b.getAttribute("data-go"));
    });
  });
  const lo = document.getElementById("btnLogout");
  if (lo) {
    lo.addEventListener("click", async () => {
      try { await apiFetch("logout.php", { method: "POST", body: "{}" }); } catch {}
      user = null;
      go("rooms");
    });
  }
}

function loginView() {
  return `
  <div class="auth-page">
    <form class="auth-card stack" id="loginForm">
      <div class="eyebrow">HEYWOOD'S HOTEL · PHASE 2</div>
      <h1 id="authTitle">Sign in</h1>
      <p class="muted">Same accounts and database as the website.</p>
      <label id="nameField" hidden>Full name<input name="name" minlength="2" /></label>
      <label>Email<input type="email" name="email" required autocomplete="username" /></label>
      <label>Password<input type="password" name="password" required minlength="6" /></label>
      <label style="flex-direction:row;align-items:center;gap:.5rem" id="staffField">
        <input type="checkbox" name="staff" /> Staff / front desk login
      </label>
      <p class="error" id="authError" hidden></p>
      <button class="btn btn-primary" type="submit">Continue</button>
      <button type="button" class="btn btn-ghost" id="toggleMode">Need an account? Register</button>
      <button type="button" class="btn btn-ghost" data-go="rooms">Back to rooms</button>
    </form>
  </div>`;
}

function wireLogin() {
  let mode = "login";
  const form = document.getElementById("loginForm");
  const toggle = document.getElementById("toggleMode");
  const title = document.getElementById("authTitle");
  const nameField = document.getElementById("nameField");
  const staffField = document.getElementById("staffField");
  const err = document.getElementById("authError");

  document.querySelectorAll("[data-go]").forEach((b) => {
    b.addEventListener("click", (e) => { e.preventDefault(); go(b.getAttribute("data-go")); });
  });

  toggle.addEventListener("click", () => {
    mode = mode === "login" ? "register" : "login";
    title.textContent = mode === "login" ? "Sign in" : "Create account";
    toggle.textContent = mode === "login" ? "Need an account? Register" : "Already registered? Sign in";
    nameField.hidden = mode !== "register";
    staffField.hidden = mode !== "login";
    err.hidden = true;
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    err.hidden = true;
    const fd = new FormData(form);
    const email = String(fd.get("email") || "").trim();
    const password = String(fd.get("password") || "");
    const name = String(fd.get("name") || "").trim();
    const staff = !!fd.get("staff");
    try {
      if (mode === "register") {
        await apiFetch("register.php", {
          method: "POST",
          body: JSON.stringify({ name, email, password }),
        });
      }
      const body = await apiFetch("login.php", {
        method: "POST",
        body: JSON.stringify({
          email,
          password,
          login_as: staff ? "admin" : "user",
        }),
      });
      const data = unwrap(body) || {};
      if (data.csrf_token) setCsrf(data.csrf_token);
      user = data.user || null;
      if (!user) await refreshSession();
      go(isStaff() ? "desk" : "rooms");
    } catch (ex) {
      err.textContent = ex.message || "Could not sign in.";
      err.hidden = false;
    }
  });
}

async function renderRooms() {
  const root = document.getElementById("root");
  let checkIn = shiftDate(1);
  let checkOut = shiftDate(3);
  let rooms = [];
  let error = "";
  let note = "";

  async function search() {
    error = "";
    try {
      const body = await apiFetch(
        `availability.php?check_in=${encodeURIComponent(checkIn)}&check_out=${encodeURIComponent(checkOut)}`
      );
      const data = unwrap(body) || {};
      rooms = Array.isArray(data.rooms) ? data.rooms : Array.isArray(data) ? data : [];
    } catch (ex) {
      try {
        const pub = await apiFetch("public_rooms.php");
        const data = unwrap(pub) || {};
        rooms = (Array.isArray(data.rooms) ? data.rooms : []).map((r) => ({ ...r, available: true }));
        error = ex.message;
      } catch {
        rooms = [];
        error = ex.message || "Could not load rooms.";
      }
    }
    paint();
  }

  function paint() {
    const n = nightsBetween(checkIn, checkOut);
    root.innerHTML = shell(`
      <section class="hero">
        <div class="eyebrow">Phase 2 app · same PHP API</div>
        <h1>Find a room</h1>
        <p>Date search, live availability, and bookings on your account.</p>
      </section>
      <form class="card form-row dates" id="searchForm" style="margin-bottom:1rem">
        <label>Check-in<input type="date" name="ci" value="${checkIn}" required /></label>
        <label>Check-out<input type="date" name="co" value="${checkOut}" required /></label>
        <button class="btn btn-primary" type="submit">Search</button>
      </form>
      ${error ? `<p class="error">${escapeHtml(error)}</p>` : ""}
      ${note ? `<p class="ok">${escapeHtml(note)}</p>` : ""}
      <div class="grid-2" id="roomGrid">
        ${rooms.map((room) => {
          const price = Number(room.price || room.min_price || 0);
          const free = room.available !== false && room.free !== false;
          const id = room.id || room.room_id || room.room_number;
          return `<article class="card room-card">
            <h3>${escapeHtml(room.room_type || "Room")}${room.room_number ? " · " + escapeHtml(room.room_number) : ""}</h3>
            <p class="muted">${escapeHtml(room.description || room.blurb || "Comfortable stay")}</p>
            <p>${kes(price)} / night${n > 0 && free ? ` · ${n} nights ${kes(price * n)}` : ""}</p>
            <button type="button" class="btn btn-brass" data-book="${escapeHtml(String(id))}" ${free ? "" : "disabled"}>
              ${free ? "Reserve" : "Unavailable"}
            </button>
          </article>`;
        }).join("")}
      </div>
      ${!rooms.length ? `<div class="empty">No rooms for these dates.</div>` : ""}
      <div id="modalHost"></div>
    `);
    wireShell();
    document.getElementById("searchForm").addEventListener("submit", (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      checkIn = String(fd.get("ci"));
      checkOut = String(fd.get("co"));
      search();
    });
    document.querySelectorAll("[data-book]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.getAttribute("data-book");
        const room = rooms.find((r) => String(r.id || r.room_id || r.room_number) === id);
        if (room) openBook(room);
      });
    });
  }

  function openBook(room) {
    const host = document.getElementById("modalHost");
    const n = nightsBetween(checkIn, checkOut);
    host.innerHTML = `
      <div class="modal-bg">
        <div class="card stack" style="width:min(420px,100%)">
          <h2 style="margin:0">Confirm · ${escapeHtml(room.room_number || room.room_type)}</h2>
          <p class="muted">${checkIn} → ${checkOut} · ${n} nights</p>
          ${!user ? `
            <p>Sign in to place this reservation.</p>
            <button type="button" class="btn btn-primary" data-go="login">Sign in</button>
          ` : `
            <label>Guest name<input id="guestName" value="${escapeHtml(user.name || "")}" /></label>
            <button type="button" class="btn btn-primary" id="confirmBook">Confirm booking</button>
          `}
          <button type="button" class="btn btn-ghost" id="closeModal">Close</button>
          <p class="error" id="bookErr" hidden></p>
        </div>
      </div>`;
    host.querySelector("#closeModal").onclick = () => { host.innerHTML = ""; };
    host.querySelector("[data-go]")?.addEventListener("click", () => go("login"));
    host.querySelector("#confirmBook")?.addEventListener("click", async () => {
      const guestName = document.getElementById("guestName").value.trim();
      const err = document.getElementById("bookErr");
      try {
        const body = await apiFetch("book.php", {
          method: "POST",
          body: JSON.stringify({
            room_id: room.id || room.room_id,
            room_number: room.room_number,
            guest_name: guestName,
            check_in: checkIn,
            check_out: checkOut,
          }),
        });
        const data = unwrap(body) || {};
        note = data.booking_id
          ? `Booking confirmed. Reference #${data.booking_id}.`
          : body.message || "Booking confirmed.";
        host.innerHTML = "";
        await search();
      } catch (ex) {
        err.textContent = ex.message || "Booking failed.";
        err.hidden = false;
      }
    });
  }

  await search();
}

async function renderStays() {
  const root = document.getElementById("root");
  let error = "";
  let active = [];
  let history = [];
  try {
    const [a, h] = await Promise.all([
      apiFetch("bookings.php?scope=active"),
      apiFetch("bookings.php?scope=history"),
    ]);
    const ad = unwrap(a) || {};
    const hd = unwrap(h) || {};
    active = Array.isArray(ad.bookings) ? ad.bookings : Array.isArray(ad) ? ad : [];
    history = Array.isArray(hd.bookings) ? hd.bookings : Array.isArray(hd) ? hd : [];
  } catch (ex) {
    error = ex.message || "Could not load stays.";
  }

  function statusLabel(b) {
    if (b.status === "cancelled") return "Cancelled";
    if (b.stay_status === "checked_in") return "In house";
    if (b.stay_status === "checked_out") return "Checked out";
    return "Reserved";
  }

  function row(b, canCancel) {
    return `<div class="stay-row"><div style="flex:1">
      <h3>Room ${escapeHtml(b.room_number)} · ${escapeHtml(b.room_type || "")}</h3>
      <p class="muted">${escapeHtml(b.guest_name || "")} · ${escapeHtml(b.check_in)} → ${escapeHtml(b.check_out)}</p>
      <p>${statusLabel(b)}${b.total_amount != null ? " · " + kes(b.total_amount) : ""}</p>
      ${canCancel ? `<button type="button" class="btn btn-danger" data-cancel="${b.id}">Cancel</button>` : ""}
    </div></div>`;
  }

  root.innerHTML = shell(`
    <h1 style="color:var(--olive);margin-top:0">My stays</h1>
    <p class="muted">Shared with the classic website database.</p>
    ${error ? `<p class="error">${escapeHtml(error)}</p>` : ""}
    <h2>Coming up</h2>
    ${active.length ? active.map((b) => row(b, (b.status === "active" || b.status === "reserved") && b.stay_status !== "checked_in" && b.stay_status !== "checked_out")).join("") : `<div class="empty">No active bookings.</div>`}
    <h2>History</h2>
    ${history.length ? history.map((b) => row(b, false)).join("") : `<div class="empty">No past stays yet.</div>`}
  `);
  wireShell();
  document.querySelectorAll("[data-cancel]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      if (!confirm("Cancel this reservation?")) return;
      try {
        await apiFetch("cancel.php", {
          method: "POST",
          body: JSON.stringify({ booking_id: Number(btn.getAttribute("data-cancel")) }),
        });
        await renderStays();
      } catch (ex) {
        alert(ex.message || "Cancel failed");
      }
    });
  });
}

async function renderDesk() {
  const root = document.getElementById("root");
  if (!isStaff()) {
    root.innerHTML = shell(`
      <div class="card">
        <h1>Front desk</h1>
        <p class="muted">Sign in with a staff account (admin / receptionist / manager).</p>
      </div>`);
    wireShell();
    return;
  }

  let error = "";
  let list = [];
  let stats = {};
  try {
    const body = await apiFetch("dashboard.php");
    const data = unwrap(body) || {};
    stats = data.stats || data || {};
    list = data.bookings || data.recent_bookings || [];
  } catch (ex) {
    try {
      const b = await apiFetch("bookings.php?scope=active");
      const data = unwrap(b) || {};
      list = Array.isArray(data.bookings) ? data.bookings : [];
      stats = {
        active_bookings: list.length,
        checked_in: list.filter((x) => x.stay_status === "checked_in").length,
      };
    } catch (e2) {
      error = ex.message || e2.message || "Desk unavailable.";
    }
  }

  root.innerHTML = shell(`
    <h1 style="color:var(--olive);margin-top:0">Front desk</h1>
    <p class="muted">Staff tools over the same PHP APIs as admin.html.</p>
    ${error ? `<p class="error">${escapeHtml(error)}</p>` : ""}
    <div class="stat-grid">
      <div class="stat"><div class="label">Active</div><div class="value">${stats.active_bookings ?? stats.bookings ?? list.length ?? "—"}</div></div>
      <div class="stat"><div class="label">In house</div><div class="value">${stats.checked_in ?? stats.in_house ?? "—"}</div></div>
      <div class="stat"><div class="label">Rooms</div><div class="value">${stats.rooms ?? stats.total_rooms ?? "—"}</div></div>
    </div>
    <h2>Active stays</h2>
    ${!list.length ? `<div class="empty">No active bookings.</div>` : list.map((b) => `
      <div class="stay-row" style="align-items:center;justify-content:space-between">
        <div>
          <h3>${escapeHtml(b.guest_name)} · ${escapeHtml(b.room_number)}</h3>
          <p class="muted">${escapeHtml(b.check_in)} → ${escapeHtml(b.check_out)}</p>
        </div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap">
          ${b.stay_status !== "checked_in" && b.stay_status !== "checked_out"
            ? `<button type="button" class="btn btn-brass" data-in="${b.id}">Check in</button>` : ""}
          ${b.stay_status === "checked_in"
            ? `<button type="button" class="btn btn-primary" data-out="${b.id}">Check out</button>` : ""}
        </div>
      </div>`).join("")}
  `);
  wireShell();
  document.querySelectorAll("[data-in]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      try {
        await apiFetch("checkin.php", { method: "POST", body: JSON.stringify({ booking_id: Number(btn.getAttribute("data-in")) }) });
        await renderDesk();
      } catch (ex) { alert(ex.message); }
    });
  });
  document.querySelectorAll("[data-out]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      try {
        await apiFetch("checkout.php", { method: "POST", body: JSON.stringify({ booking_id: Number(btn.getAttribute("data-out")) }) });
        await renderDesk();
      } catch (ex) { alert(ex.message); }
    });
  });
}

await refreshSession();
render();
