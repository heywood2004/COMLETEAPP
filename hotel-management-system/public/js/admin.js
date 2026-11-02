"use strict";

let currentAdmin = null;
let rooms = [];
let bookings = [];
let users = [];
let editingRoomId = null;

document.addEventListener("DOMContentLoaded", init);

async function init() {
  registerEvents();
  const ok = await checkSession();
  if (!ok) return;
  await loadAll();
}

function registerEvents() {
  document.getElementById("adminAddRoomForm")?.addEventListener("submit", saveRoom);
  document.getElementById("adminCancelForm")?.addEventListener("submit", cancelBooking);
  document.getElementById("adminSearchRoom")?.addEventListener("input", searchRooms);
  document.getElementById("adminLogout")?.addEventListener("click", logout);
  document.getElementById("cancelEditRoom")?.addEventListener("click", resetRoomForm);
}

async function checkSession() {
  try {
    const data = await apiFetch("session.php");
    const session = unwrapData(data) || {};
    if (!session.authenticated || !session.user) {
      window.location.href = "index.html";
      return false;
    }
    currentAdmin = session.user;
    const badge = document.getElementById("staffRoleBadge");
    if (badge && session.user?.role) {
      badge.textContent = "(" + session.user.role + ")";
    }
    if (session.csrf_token && typeof setCsrfToken === "function") setCsrfToken(session.csrf_token);
    if (String(currentAdmin.role).toLowerCase() !== "admin") {
      window.location.href = "user.html";
      return false;
    }
    setText("adminName", currentAdmin.name || "Administrator");
    setText("adminEmail", currentAdmin.email || "");
    setText("welcomeMessage", `Welcome, ${currentAdmin.name || "Administrator"}`);
    return true;
  } catch (e) {
    window.location.href = "index.html";
    return false;
  }
}

async function loadAll() {
  setLoading(true);
  try {
    await Promise.all([loadDashboard(), loadRooms(), loadBookings(), loadUsers()]);
  } catch (e) {
    console.error(e);
  } finally {
    setLoading(false);
  }
}

async function loadDashboard() {
  try {
    const data = await apiFetch("dashboard.php");
    const d = unwrapData(data) || {};
    setText("adminTotalRooms", d.totalRooms ?? 0);
    setText("adminAvailableRooms", d.availableRooms ?? 0);
    setText("adminBookedRooms", d.bookedRooms ?? 0);
  } catch (e) {
    setText("adminTotalRooms", 0);
    setText("adminAvailableRooms", 0);
    setText("adminBookedRooms", 0);
  }
}

async function loadRooms() {
  try {
    const data = await apiFetch("rooms.php");
    rooms = Array.isArray(unwrapData(data)) ? unwrapData(data) : [];
    renderRooms(rooms);
  } catch (e) {
    rooms = [];
    renderRooms([]);
    showMessage(e.message || "Unable to load rooms.", "error");
  }
}

function roomImage(type) {
  const map = {
    single: "hotel-hero-1.jpg", double: "hotel-hero-2.jpg", twin: "hotel-hero-3.jpg",
    deluxe: "hotel-hero-4.jpg", executive: "hotel-hero-5.jpg", presidential: "hotel-hero-6.jpg", suite: "hotel-hero-4.jpg"
  };
  return "images/" + (map[String(type || "").toLowerCase()] || "hotel-hero-1.jpg");
}

function renderRooms(list) {
  const container = document.getElementById("adminRoomContainer");
  if (!container) return;
  if (!list.length) {
    container.innerHTML = `<div class="empty-state"><div class="empty-icon">🏨</div><h3>No Rooms Found</h3></div>`;
    return;
  }
  container.innerHTML = list.map((room) => {
    const available = isRoomAvailable(room);
    const price = Number(room.price || 0);
    return `
      <article class="room-card ${available ? "available" : "booked"}">
        <img src="${roomImage(room.room_type)}" alt="Room ${escapeHtml(room.room_number)}" loading="lazy"
             onerror="this.src='images/hotel-hero-1.jpg'">
        <div class="room-card-header">
          <div>
            <span class="room-label">ROOM</span>
            <h3>Room ${escapeHtml(room.room_number)}</h3>
          </div>
          <span class="badge ${available ? "available" : "booked"}">${available ? "Available" : "Booked"}</span>
        </div>
        <div class="room-card-body">
          <p><strong>Type:</strong> ${escapeHtml(room.room_type)}</p>
          <p><strong>Price:</strong> <span class="room-price">KSh ${formatMoney(price)}</span> / night</p>
          <p><strong>Capacity:</strong> ${escapeHtml(room.capacity)} guest(s)</p>
          ${room.description ? `<p>${escapeHtml(room.description)}</p>` : ""}
          ${room.guest_name ? `<p><strong>Guest:</strong> ${escapeHtml(room.guest_name)}</p>` : ""}
        </div>
        <div class="room-card-footer">
          <button type="button" class="btn-primary" onclick="editRoom(${Number(room.id)})">✏️ Edit</button>
          <button type="button" class="btn-danger" onclick="deleteRoom(${Number(room.id)})">🗑️ Delete</button>
        </div>
      </article>`;
  }).join("");
}

async function saveRoom(e) {
  e.preventDefault();
  const roomNumber = getValue("roomNumber");
  const roomType = getValue("roomType");
  const price = getValue("roomPrice");
  const capacity = getValue("roomCapacity");
  const description = getValue("roomDescription");
  const btn = document.getElementById("addRoomButton");
  const isEdit = editingRoomId !== null;

  if (!roomNumber || !roomType || price === "" || capacity === "") {
    showMessage("Please complete all required fields.", "error");
    return;
  }

  try {
    setLoading(true);
    if (btn) { btn.disabled = true; btn.textContent = isEdit ? "Saving..." : "Adding..."; }

    const payload = {
      room_number: roomNumber,
      room_type: roomType,
      price: Number(price),
      capacity: Number(capacity),
      description
    };
    if (isEdit) payload.id = Number(editingRoomId);

    await apiFetch(isEdit ? "update_room.php" : "add_room.php", {
      method: "POST",
      body: JSON.stringify(payload)
    });

    showMessage(isEdit ? "Room updated successfully." : "Room added successfully.", "success");
    resetRoomForm();
    await Promise.all([loadRooms(), loadDashboard()]);
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
    if (btn) { btn.disabled = false; btn.textContent = editingRoomId ? "💾 Save Changes" : "➕ Add Room"; }
  }
}

function editRoom(roomId) {
  const room = rooms.find((r) => Number(r.id) === Number(roomId));
  if (!room) { showMessage("Room not found.", "error"); return; }
  editingRoomId = Number(roomId);
  setValue("editRoomId", room.id);
  setValue("roomNumber", room.room_number || "");
  setValue("roomType", room.room_type || "");
  setValue("roomPrice", room.price ?? "");
  setValue("roomCapacity", room.capacity ?? "");
  setValue("roomDescription", room.description || "");
  setText("roomFormTitle", "Edit Room");
  const btn = document.getElementById("addRoomButton");
  if (btn) btn.textContent = "💾 Save Changes";
  const cancel = document.getElementById("cancelEditRoom");
  if (cancel) cancel.style.display = "inline-flex";
  document.getElementById("adminAddRoomForm")?.scrollIntoView({ behavior: "smooth", block: "center" });
}
window.editRoom = editRoom;

function resetRoomForm() {
  editingRoomId = null;
  document.getElementById("adminAddRoomForm")?.reset();
  setValue("editRoomId", "");
  setText("roomFormTitle", "Add New Room");
  const btn = document.getElementById("addRoomButton");
  if (btn) btn.textContent = "➕ Add Room";
  const cancel = document.getElementById("cancelEditRoom");
  if (cancel) cancel.style.display = "none";
}

async function deleteRoom(roomId) {
  const room = rooms.find((r) => Number(r.id) === Number(roomId));
  if (!room) return;
  if (!confirm(`Delete Room ${room.room_number}?`)) return;
  try {
    setLoading(true);
    await apiFetch("delete_room.php", {
      method: "POST",
      body: JSON.stringify({ id: Number(roomId) })
    });
    showMessage("Room deleted successfully.", "success");
    await Promise.all([loadRooms(), loadDashboard(), loadBookings()]);
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}
window.deleteRoom = deleteRoom;

async function loadUsers() {
  try {
    const data = await apiFetch("users.php");
    users = Array.isArray(unwrapData(data)) ? unwrapData(data) : [];
    renderUsers(users);
  } catch (e) {
    users = [];
    renderUsers([]);
  }
}

function renderUsers(list) {
  const tbody = document.querySelector("#usersTable tbody");
  if (!tbody) return;
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="7">No users found.</td></tr>`;
    return;
  }
  tbody.innerHTML = list.map((user) => {
    const isMe = currentAdmin && Number(user.id) === Number(currentAdmin.id);
    const isAdmin = String(user.role || "").toLowerCase() === "admin";
    return `
      <tr>
        <td>${escapeHtml(user.id)}</td>
        <td>${escapeHtml(user.name)}</td>
        <td>${escapeHtml(user.email)}</td>
        <td><span class="badge ${isAdmin ? "booked" : "available"}">${escapeHtml(user.role)}</span></td>
        <td>${Number(user.booking_count || 0)}</td>
        <td>${escapeHtml(user.created_at || "-")}</td>
        <td>
          ${isMe
            ? `<span class="current-account">Current account</span>`
            : `<div class="table-actions">
                 <button type="button" class="btn-primary" style="min-height:36px;padding:8px 12px;font-size:13px;"
                   onclick="changeUserRole(${Number(user.id)}, '${isAdmin ? "user" : "admin"}')">
                   ${isAdmin ? "Make User" : "Make Admin"}
                 </button>
                 <button type="button" class="btn-danger" style="min-height:36px;padding:8px 12px;font-size:13px;"
                   onclick="deleteUser(${Number(user.id)})">Delete</button>
               </div>`}
        </td>
      </tr>`;
  }).join("");
}

async function changeUserRole(userId, newRole) {
  const msg = newRole === "admin" ? "Make this user an administrator?" : "Remove administrator privileges?";
  if (!confirm(msg)) return;
  try {
    setLoading(true);
    await apiFetch("update_user.php", {
      method: "POST",
      body: JSON.stringify({ user_id: Number(userId), role: newRole })
    });
    showMessage("User role updated.", "success");
    await loadUsers();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}
window.changeUserRole = changeUserRole;

async function deleteUser(userId) {
  if (!confirm("Delete this user account? Booking history will be preserved.")) return;
  try {
    setLoading(true);
    await apiFetch("delete_user.php", {
      method: "POST",
      body: JSON.stringify({ user_id: Number(userId) })
    });
    showMessage("User deleted.", "success");
    await loadUsers();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}
window.deleteUser = deleteUser;

async function loadBookings() {
  try {
    const data = await apiFetch("bookings.php?scope=active");
    const payload = unwrapData(data) || {};
    bookings = Array.isArray(payload.bookings)
      ? payload.bookings
      : (Array.isArray(payload) ? payload : (Array.isArray(unwrapData(data)) ? unwrapData(data) : []));
    renderBookings(bookings);
    populateCancelSelect(bookings);
  } catch (e) {
    bookings = [];
    renderBookings([]);
  }
}

function renderBookings(list) {
  const tbody = document.querySelector("#adminBookingTable tbody");
  if (!tbody) return;
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="8">No active bookings.</td></tr>`;
    return;
  }
  tbody.innerHTML = list.map((b) => {
    let account = "-";
    if (b.account_name || b.account_email) {
      account = `${escapeHtml(b.account_name || "")}${b.account_email ? `<br><small>${escapeHtml(b.account_email)}</small>` : ""}`;
    }
    return `
      <tr>
        <td>${escapeHtml(b.id)}</td>
        <td>${escapeHtml(b.guest_name)}</td>
        <td>${escapeHtml(b.room_number)}</td>
        <td>${escapeHtml(b.room_type)}</td>
        <td>${escapeHtml(b.check_in)}</td>
        <td>${escapeHtml(b.check_out)}</td>
        <td>${account}</td>
        <td><span class="badge booked">${escapeHtml(b.status || "Active")}</span></td>
      </tr>`;
  }).join("");
}

function populateCancelSelect(list) {
  const select = document.getElementById("adminCancelBooking");
  if (!select) return;
  select.innerHTML = `<option value="">Select an active booking</option>`;
  list.forEach((b) => {
    const opt = document.createElement("option");
    opt.value = String(b.id);
    opt.textContent = `#${b.id} - ${b.guest_name || "Guest"} - Room ${b.room_number || "-"}`;
    select.appendChild(opt);
  });
}

async function cancelBooking(e) {
  e.preventDefault();
  const id = getValue("adminCancelBooking");
  if (!id) { showMessage("Please select a booking.", "error"); return; }
  if (!confirm("Cancel this booking?")) return;
  const btn = document.getElementById("adminCancelButton");
  try {
    setLoading(true);
    if (btn) { btn.disabled = true; btn.textContent = "Cancelling..."; }
    await apiFetch("cancel.php", {
      method: "POST",
      body: JSON.stringify({ booking_id: Number(id) })
    });
    showMessage("Booking cancelled successfully.", "success");
    document.getElementById("adminCancelForm")?.reset();
    await loadAll();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
    if (btn) { btn.disabled = false; btn.textContent = "✕ Cancel Booking"; }
  }
}

function searchRooms() {
  const q = (document.getElementById("adminSearchRoom")?.value || "").trim().toLowerCase();
  if (!q) { renderRooms(rooms); return; }
  renderRooms(rooms.filter((r) =>
    String(r.room_number || "").toLowerCase().includes(q) ||
    String(r.room_type || "").toLowerCase().includes(q) ||
    String(r.status || "").toLowerCase().includes(q)
  ));
}

async function logout() {
  if (!confirm("Logout?")) return;
  try { await apiFetch("logout.php", { method: "POST" }); } catch (e) {}
  window.location.href = "index.html";
}

// Override showMessage for systemMessage element
function showMessage(message, type = "info") {
  const box = document.getElementById("systemMessage") || document.getElementById("messageBox");
  if (!box) { console.log(type, message); return; }
  box.textContent = message;
  box.className = `system-message message ${type} show`;
  box.style.display = "block";
  clearTimeout(showMessage.timer);
  showMessage.timer = setTimeout(() => {
    box.style.display = "none";
    box.classList.remove("show");
  }, 5000);
}
window.showMessage = showMessage;

// =====================================================
// HOTEL OPERATIONS
// =====================================================

let guests = [];

function registerOpsEvents() {
  document.querySelectorAll(".ops-tab").forEach((btn) => {
    btn.addEventListener("click", () => {
      document.querySelectorAll(".ops-tab").forEach((b) => {
        b.classList.remove("active", "btn-primary");
        b.classList.add("btn-secondary");
      });
      btn.classList.add("active", "btn-primary");
      btn.classList.remove("btn-secondary");

      const tab = btn.dataset.tab;
      document.querySelectorAll(".ops-panel").forEach((p) => {
        p.style.display = "none";
      });
      const panel = document.getElementById("panel-" + tab);
      if (panel) panel.style.display = "block";
    });
  });

  document.getElementById("showGuestFormBtn")?.addEventListener("click", () => {
    document.getElementById("guestForm").style.display = "grid";
    document.getElementById("guestId").value = "";
    document.getElementById("guestForm").reset();
  });

  document.getElementById("cancelGuestFormBtn")?.addEventListener("click", () => {
    document.getElementById("guestForm").style.display = "none";
    document.getElementById("guestForm").reset();
  });

  document.getElementById("guestForm")?.addEventListener("submit", saveGuest);
  document.getElementById("billingBookingSelect")?.addEventListener("change", onBillingSelect);
  document.getElementById("paymentForm")?.addEventListener("submit", recordPayment);
}

// Hook into existing init
const _origRegisterEvents = registerEvents;
registerEvents = function () {
  _origRegisterEvents();
  registerOpsEvents();
};

const _origLoadAll = loadAll;
loadAll = async function () {
  setLoading(true);
  try {
    await Promise.all([
      loadDashboard(),
      loadRooms(),
      loadBookings(),
      loadUsers(),
      loadGuests()
    ]);
    renderOperations();
  } catch (e) {
    console.error(e);
  } finally {
    setLoading(false);
  }
};

async function loadGuests() {
  try {
    const data = await apiFetch("guests.php");
    guests = Array.isArray(unwrapData(data)) ? unwrapData(data) : [];
  } catch (e) {
    guests = [];
  }
}

function stayStatus(b) {
  return String(b.stay_status || "not_arrived").toLowerCase();
}

function renderOperations() {
  const awaiting = bookings.filter((b) => stayStatus(b) === "not_arrived");
  const inhouse = bookings.filter((b) => stayStatus(b) === "checked_in");

  setText("opsAwaitingCheckin", awaiting.length);
  setText("opsInHouse", inhouse.length);
  setText("opsGuestCount", guests.length);

  renderCheckinTable(awaiting);
  renderInhouseTable(inhouse);
  renderCheckoutTable(inhouse);
  renderGuestsTable(guests);
  populateBillingSelect(bookings);
}

function renderCheckinTable(list) {
  const tbody = document.querySelector("#opsCheckinTable tbody");
  if (!tbody) return;
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="6">No guests awaiting check-in.</td></tr>`;
    return;
  }
  tbody.innerHTML = list.map((b) => `
    <tr>
      <td>#${escapeHtml(b.id)}</td>
      <td>${escapeHtml(b.guest_name)}</td>
      <td>${escapeHtml(b.room_number)} (${escapeHtml(b.room_type)})</td>
      <td>${escapeHtml(b.check_in)}</td>
      <td>${escapeHtml(b.check_out)}</td>
      <td>
        <button type="button" class="btn-primary" style="min-height:36px;padding:8px 12px;font-size:13px;"
          onclick="doCheckIn(${Number(b.id)})">✓ Check In</button>
      </td>
    </tr>`).join("");
}

function renderInhouseTable(list) {
  const tbody = document.querySelector("#opsInhouseTable tbody");
  if (!tbody) return;
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="7">No guests currently in-house.</td></tr>`;
    return;
  }
  tbody.innerHTML = list.map((b) => `
    <tr>
      <td>#${escapeHtml(b.id)}</td>
      <td>${escapeHtml(b.guest_profile_name || b.guest_name)}</td>
      <td>${escapeHtml(b.room_number)}</td>
      <td>${escapeHtml(b.actual_check_in || "—")}</td>
      <td>${escapeHtml(b.check_out)}</td>
      <td>${b.invoice_number ? escapeHtml(b.invoice_number) : "—"}</td>
      <td>
        <button type="button" class="btn-danger" style="min-height:36px;padding:8px 12px;font-size:13px;"
          onclick="doCheckOut(${Number(b.id)})">Check Out</button>
      </td>
    </tr>`).join("");
}

function renderCheckoutTable(list) {
  const tbody = document.querySelector("#opsCheckoutTable tbody");
  if (!tbody) return;
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="7">No guests ready for check-out.</td></tr>`;
    return;
  }
  tbody.innerHTML = list.map((b) => {
    const balance = Number(b.invoice_balance ?? 0);
    return `
    <tr>
      <td>#${escapeHtml(b.id)}</td>
      <td>${escapeHtml(b.guest_profile_name || b.guest_name)}</td>
      <td>${escapeHtml(b.room_number)}</td>
      <td>${escapeHtml(b.actual_check_in || "—")}</td>
      <td>${escapeHtml(b.check_out)}</td>
      <td>KSh ${formatMoney(balance)}</td>
      <td>
        <button type="button" class="btn-danger" style="min-height:36px;padding:8px 12px;font-size:13px;"
          onclick="doCheckOut(${Number(b.id)})">✓ Check Out</button>
      </td>
    </tr>`;
  }).join("");
}

async function doCheckIn(bookingId) {
  if (!confirm("Check in this guest? An invoice will be created automatically.")) return;
  try {
    setLoading(true);
    const result = await apiFetch("checkin.php", {
      method: "POST",
      body: JSON.stringify({ booking_id: Number(bookingId) })
    });
    showMessage(result.message || "Guest checked in successfully.", "success");
    await loadAll();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}
window.doCheckIn = doCheckIn;

async function doCheckOut(bookingId) {
  if (!confirm("Check out this guest? The invoice will be finalized.")) return;
  try {
    setLoading(true);
    const result = await apiFetch("checkout.php", {
      method: "POST",
      body: JSON.stringify({ booking_id: Number(bookingId) })
    });
    const data = unwrapData(result) || {};
    const msg = data.balance_due > 0
      ? `Checked out. Outstanding balance: KSh ${formatMoney(data.balance_due)}`
      : (result.message || "Guest checked out successfully.");
    showMessage(msg, "success");
    await loadAll();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}
window.doCheckOut = doCheckOut;

function renderGuestsTable(list) {
  const tbody = document.querySelector("#opsGuestsTable tbody");
  if (!tbody) return;
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="7">No guest profiles yet.</td></tr>`;
    return;
  }
  tbody.innerHTML = list.map((g) => `
    <tr>
      <td>${escapeHtml(g.id)}</td>
      <td>${escapeHtml(g.full_name)}</td>
      <td>${escapeHtml(g.phone || "—")}</td>
      <td>${escapeHtml(g.email || "—")}</td>
      <td>${escapeHtml(g.id_number || "—")}</td>
      <td>${Number(g.booking_count || 0)}</td>
      <td>
        <div class="table-actions">
          <button type="button" class="btn-primary" style="min-height:36px;padding:8px 12px;font-size:13px;"
            onclick="editGuest(${Number(g.id)})">Edit</button>
          <button type="button" class="btn-danger" style="min-height:36px;padding:8px 12px;font-size:13px;"
            onclick="deleteGuest(${Number(g.id)})">Delete</button>
        </div>
      </td>
    </tr>`).join("");
}

function editGuest(id) {
  const g = guests.find((x) => Number(x.id) === Number(id));
  if (!g) return;
  document.getElementById("guestForm").style.display = "grid";
  setValue("guestId", g.id);
  setValue("guestFullName", g.full_name || "");
  setValue("guestPhone", g.phone || "");
  setValue("guestEmail", g.email || "");
  setValue("guestIdNumber", g.id_number || "");
  setValue("guestNationality", g.nationality || "");
  setValue("guestAddress", g.address || "");
  setValue("guestNotes", g.notes || "");
  document.getElementById("guestForm").scrollIntoView({ behavior: "smooth", block: "center" });
}
window.editGuest = editGuest;

async function saveGuest(e) {
  e.preventDefault();
  const id = getValue("guestId");
  const payload = {
    full_name: getValue("guestFullName"),
    phone: getValue("guestPhone"),
    email: getValue("guestEmail"),
    id_number: getValue("guestIdNumber"),
    nationality: getValue("guestNationality"),
    address: getValue("guestAddress"),
    notes: getValue("guestNotes")
  };
  if (!payload.full_name) {
    showMessage("Guest full name is required.", "error");
    return;
  }

  try {
    setLoading(true);
    if (id) {
      payload.id = Number(id);
      await apiFetch("guests.php", {
        method: "PUT",
        body: JSON.stringify(payload)
      });
      showMessage("Guest updated successfully.", "success");
    } else {
      await apiFetch("guests.php", {
        method: "POST",
        body: JSON.stringify(payload)
      });
      showMessage("Guest created successfully.", "success");
    }
    document.getElementById("guestForm").style.display = "none";
    document.getElementById("guestForm").reset();
    await loadGuests();
    renderOperations();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}

async function deleteGuest(id) {
  if (!confirm("Delete this guest profile? Linked bookings will keep history.")) return;
  try {
    setLoading(true);
    await apiFetch("guests.php", {
      method: "DELETE",
      body: JSON.stringify({ id: Number(id) })
    });
    showMessage("Guest deleted.", "success");
    await loadGuests();
    renderOperations();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}
window.deleteGuest = deleteGuest;

function populateBillingSelect(list) {
  const select = document.getElementById("billingBookingSelect");
  if (!select) return;
  const withInvoice = list.filter((b) => b.invoice_id || stayStatus(b) === "checked_in");
  select.innerHTML = `<option value="">Select booking with invoice</option>`;
  withInvoice.forEach((b) => {
    const opt = document.createElement("option");
    opt.value = String(b.id);
    const bal = Number(b.invoice_balance ?? 0);
    opt.textContent = `#${b.id} - ${b.guest_name} - Room ${b.room_number} - Bal: KSh ${formatMoney(bal)}`;
    select.appendChild(opt);
  });
}

async function onBillingSelect() {
  const bookingId = getValue("billingBookingSelect");
  const summary = document.getElementById("billingSummary");
  if (!bookingId) {
    if (summary) summary.style.display = "none";
    return;
  }

  const b = bookings.find((x) => Number(x.id) === Number(bookingId));
  if (!b) return;

  try {
    setLoading(true);
    // Prefer live invoice data
    let inv = null;
    try {
      const result = await apiFetch(`invoice.php?booking_id=${encodeURIComponent(bookingId)}`);
      inv = unwrapData(result);
    } catch (e) {
      inv = null;
    }

    const total = Number(inv?.total ?? inv?.total_amount ?? b.invoice_total ?? 0);
    const paid = Number(inv?.amount_paid ?? b.invoice_paid ?? 0);
    const balance = Number(inv?.balance_due ?? b.invoice_balance ?? Math.max(0, total - paid));
    const invId = inv?.invoice_id || b.invoice_id;

    setText("billInvoiceNumber", inv?.invoice_number || b.invoice_number || "—");
    setText("billGuestName", inv?.guest_name || b.guest_name || "—");
    setText("billRoom", inv ? `${inv.room_number} (${inv.room_type})` : `${b.room_number}`);
    setText("billStatus", inv?.status || b.invoice_status || "—");
    setText("billTotal", formatMoney(total));
    setText("billPaid", formatMoney(paid));
    setText("billBalance", formatMoney(balance));
    setValue("paymentInvoiceId", invId || "");
    setValue("paymentAmount", balance > 0 ? balance : "");

    if (summary) summary.style.display = "block";

    if (invId) {
      await loadPayments(invId);
    } else {
      const tbody = document.querySelector("#opsPaymentsTable tbody");
      if (tbody) tbody.innerHTML = `<tr><td colspan="5">No invoice yet. Check in the guest first.</td></tr>`;
    }
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}

async function loadPayments(invoiceId) {
  const tbody = document.querySelector("#opsPaymentsTable tbody");
  if (!tbody) return;
  try {
    const result = await apiFetch(`payments.php?invoice_id=${encodeURIComponent(invoiceId)}`);
    const list = Array.isArray(unwrapData(result)) ? unwrapData(result) : [];
    if (!list.length) {
      tbody.innerHTML = `<tr><td colspan="5">No payments recorded yet.</td></tr>`;
      return;
    }
    tbody.innerHTML = list.map((p) => `
      <tr>
        <td>${escapeHtml(p.paid_at || "—")}</td>
        <td>KSh ${formatMoney(p.amount)}</td>
        <td>${escapeHtml(p.payment_method || "—")}</td>
        <td>${escapeHtml(p.transaction_reference || "—")}</td>
        <td>${escapeHtml(p.recorded_by_name || "—")}</td>
      </tr>`).join("");
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="5">${escapeHtml(e.message)}</td></tr>`;
  }
}

async function recordPayment(e) {
  e.preventDefault();
  const invoiceId = getValue("paymentInvoiceId");
  const amount = Number(getValue("paymentAmount"));
  const method = getValue("paymentMethod");
  const reference = getValue("paymentRef");
  const notes = getValue("paymentNotes");

  if (!invoiceId) {
    showMessage("No invoice selected. Check in the guest first.", "error");
    return;
  }
  if (!(amount > 0)) {
    showMessage("Enter a valid payment amount.", "error");
    return;
  }

  try {
    setLoading(true);
    const result = await apiFetch("payments.php", {
      method: "POST",
      body: JSON.stringify({
        invoice_id: Number(invoiceId),
        amount,
        payment_method: method,
        transaction_reference: reference,
        notes
      })
    });
    showMessage(result.message || "Payment recorded.", "success");
    document.getElementById("paymentForm")?.reset();
    setValue("paymentInvoiceId", invoiceId);
    await loadBookings();
    renderOperations();
    // re-select current booking
    const select = document.getElementById("billingBookingSelect");
    if (select) {
      // find booking that has this invoice
      const b = bookings.find((x) => Number(x.invoice_id) === Number(invoiceId));
      if (b) select.value = String(b.id);
    }
    await onBillingSelect();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}

// =====================================================
// BILLING RULES SETTINGS
// =====================================================

async function loadBillingRules() {
  try {
    const result = await apiFetch("settings.php");
    const s = unwrapData(result) || {};
    setValue("ruleTaxRate", s.tax_rate ?? 16);
    setValue("ruleServiceRate", s.service_charge_rate ?? 0);
    setValue("ruleMinNights", s.min_nights ?? 1);
    setValue("ruleEarlyPolicy", s.early_checkout_policy || "actual");
    setValue("ruleLateHour", s.late_checkout_hour ?? 12);
    setValue("ruleLateFee", s.late_checkout_fee ?? 0);
    setValue("ruleCurrency", s.currency || "KES");
    const autoTax = document.getElementById("ruleAutoTax");
    const autoSvc = document.getElementById("ruleAutoService");
    if (autoTax) autoTax.checked = Number(s.auto_apply_tax ?? 1) === 1;
    if (autoSvc) autoSvc.checked = Number(s.auto_apply_service ?? 1) === 1;
  } catch (e) {
    console.warn("Could not load billing rules:", e.message);
  }
}

async function saveBillingRules(e) {
  e.preventDefault();
  const payload = {
    tax_rate: Number(getValue("ruleTaxRate") || 0),
    service_charge_rate: Number(getValue("ruleServiceRate") || 0),
    min_nights: Number(getValue("ruleMinNights") || 1),
    early_checkout_policy: getValue("ruleEarlyPolicy") || "actual",
    late_checkout_hour: Number(getValue("ruleLateHour") || 12),
    late_checkout_fee: Number(getValue("ruleLateFee") || 0),
    currency: getValue("ruleCurrency") || "KES",
    auto_apply_tax: document.getElementById("ruleAutoTax")?.checked ? 1 : 0,
    auto_apply_service: document.getElementById("ruleAutoService")?.checked ? 1 : 0
  };

  try {
    setLoading(true);
    const result = await apiFetch("settings.php", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    showMessage(result.message || "Billing rules saved.", "success");
    await loadBillingRules();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}

// Wire events after ops events
const _origRegisterOpsEvents = typeof registerOpsEvents === "function" ? registerOpsEvents : null;
if (_origRegisterOpsEvents) {
  registerOpsEvents = function () {
    _origRegisterOpsEvents();
    document.getElementById("billingRulesForm")?.addEventListener("submit", saveBillingRules);
  };
} else {
  document.addEventListener("DOMContentLoaded", () => {
    document.getElementById("billingRulesForm")?.addEventListener("submit", saveBillingRules);
  });
}

// Load rules with operations data
const _origRenderOperations = typeof renderOperations === "function" ? renderOperations : null;
if (_origRenderOperations) {
  renderOperations = function () {
    _origRenderOperations();
    loadBillingRules();
  };
}

// =====================================================
// M-PESA STK PUSH
// =====================================================

let lastMpesaTx = null;
let mpesaPollTimer = null;

function registerMpesaEvents() {
  document.getElementById("mpesaStkBtn")?.addEventListener("click", sendMpesaStk);
  document.getElementById("mpesaStatusBtn")?.addEventListener("click", checkMpesaStatus);
}

const _prevRegisterOps = typeof registerOpsEvents === "function" ? registerOpsEvents : null;
registerOpsEvents = function () {
  if (_prevRegisterOps) _prevRegisterOps();
  registerMpesaEvents();
  document.getElementById("billingRulesForm")?.addEventListener("submit", saveBillingRules);
};

// When billing summary loads, prefill M-Pesa amount with balance
const _prevOnBilling = typeof onBillingSelect === "function" ? onBillingSelect : null;
if (_prevOnBilling) {
  onBillingSelect = async function () {
    await _prevOnBilling();
    const balanceText = document.getElementById("billBalance")?.textContent || "0";
    const balance = Number(String(balanceText).replace(/,/g, "")) || 0;
    if (balance > 0) setValue("mpesaAmount", Math.ceil(balance));
    lastMpesaTx = null;
    const statusBtn = document.getElementById("mpesaStatusBtn");
    if (statusBtn) statusBtn.style.display = "none";
    const msg = document.getElementById("mpesaStatusMsg");
    if (msg) { msg.style.display = "none"; msg.textContent = ""; }
  };
}

async function sendMpesaStk() {
  const invoiceId = getValue("paymentInvoiceId");
  const phone = getValue("mpesaPhone");
  const amount = Number(getValue("mpesaAmount"));
  const btn = document.getElementById("mpesaStkBtn");
  const msg = document.getElementById("mpesaStatusMsg");

  if (!invoiceId) {
    showMessage("Select a booking with an invoice first.", "error");
    return;
  }
  if (!phone) {
    showMessage("Enter the guest M-Pesa phone number.", "error");
    return;
  }
  if (!(amount >= 1)) {
    showMessage("Enter a valid amount (min KSh 1).", "error");
    return;
  }

  try {
    setLoading(true);
    if (btn) { btn.disabled = true; btn.textContent = "Sending..."; }
    const result = await apiFetch("mpesa.php", {
      method: "POST",
      body: JSON.stringify({
        action: "stk",
        invoice_id: Number(invoiceId),
        phone,
        amount
      })
    });
    const data = unwrapData(result) || {};
    lastMpesaTx = data;
    showMessage(result.message || "STK Push sent.", "success");
    if (msg) {
      msg.style.display = "block";
      msg.style.color = "#2e7d32";
      msg.textContent = data.customer_message || "Ask the guest to enter their M-Pesa PIN.";
    }
    const statusBtn = document.getElementById("mpesaStatusBtn");
    if (statusBtn) statusBtn.style.display = "inline-flex";

    // Poll every 5s for up to ~2 minutes
    if (mpesaPollTimer) clearInterval(mpesaPollTimer);
    let tries = 0;
    mpesaPollTimer = setInterval(async () => {
      tries += 1;
      const done = await checkMpesaStatus(true);
      if (done || tries >= 24) {
        clearInterval(mpesaPollTimer);
        mpesaPollTimer = null;
      }
    }, 5000);
  } catch (err) {
    showMessage(err.message, "error");
    if (msg) {
      msg.style.display = "block";
      msg.style.color = "#c62828";
      msg.textContent = err.message;
    }
  } finally {
    setLoading(false);
    if (btn) { btn.disabled = false; btn.textContent = "📲 Send STK Push"; }
  }
}

async function checkMpesaStatus(silent = false) {
  if (!lastMpesaTx || (!lastMpesaTx.transaction_id && !lastMpesaTx.checkout_request_id)) {
    if (!silent) showMessage("No STK transaction to check.", "error");
    return true;
  }
  try {
    const q = lastMpesaTx.transaction_id
      ? `mpesa.php?action=status&id=${encodeURIComponent(lastMpesaTx.transaction_id)}`
      : `mpesa.php?action=status&checkout_request_id=${encodeURIComponent(lastMpesaTx.checkout_request_id)}`;
    const result = await apiFetch(q);
    const data = unwrapData(result) || {};
    const msg = document.getElementById("mpesaStatusMsg");
    const status = String(data.status || "pending");

    if (msg) {
      msg.style.display = "block";
      if (status === "success") {
        msg.style.color = "#2e7d32";
        msg.textContent = `Payment successful. Receipt: ${data.mpesa_receipt || "—"}`;
        showMessage("M-Pesa payment confirmed.", "success");
        await loadBookings();
        renderOperations();
        await onBillingSelect();
        return true;
      }
      if (status === "failed" || status === "cancelled" || status === "timeout") {
        msg.style.color = "#c62828";
        msg.textContent = data.result_desc || `Payment ${status}.`;
        if (!silent) showMessage(data.result_desc || `Payment ${status}.`, "error");
        return true;
      }
      msg.style.color = "#1565c0";
      msg.textContent = "Waiting for guest to complete M-Pesa PIN...";
    }
    return false;
  } catch (err) {
    if (!silent) showMessage(err.message, "error");
    return false;
  }
}
window.checkMpesaStatus = checkMpesaStatus;

// =====================================================
// WALK-IN BOOKING
// =====================================================

function registerWalkInEvents() {
  setMinimumDates("walkInCheckIn", "walkInCheckOut");
  document.getElementById("walkInCheckIn")?.addEventListener("change", () => {
    const ci = document.getElementById("walkInCheckIn");
    const co = document.getElementById("walkInCheckOut");
    if (ci && co) {
      co.min = ci.value || getToday();
      if (co.value && co.value <= ci.value) co.value = "";
    }
  });
  document.getElementById("walkInForm")?.addEventListener("submit", submitWalkIn);
  document.getElementById("loadReportBtn")?.addEventListener("click", loadReport);

  // Default report range = this month
  const from = document.getElementById("reportFrom");
  const to = document.getElementById("reportTo");
  if (from && !from.value) {
    const now = new Date();
    from.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-01`;
  }
  if (to && !to.value) to.value = getToday();
}

const _prevOpsForWalkIn = typeof registerOpsEvents === "function" ? registerOpsEvents : null;
registerOpsEvents = function () {
  if (_prevOpsForWalkIn) _prevOpsForWalkIn();
  registerWalkInEvents();
};

const _prevLoadAllForWalkIn = typeof loadAll === "function" ? loadAll : null;
if (_prevLoadAllForWalkIn) {
  // populate walk-in room select when rooms load
  const _prevRenderRooms = typeof renderRooms === "function" ? renderRooms : null;
  if (_prevRenderRooms) {
    renderRooms = function (list) {
      _prevRenderRooms(list);
      populateWalkInRooms(list);
    };
  }
}

function populateWalkInRooms(list) {
  const select = document.getElementById("walkInRoom");
  if (!select) return;
  const current = select.value;
  select.innerHTML = `<option value="">Select available room</option>`;
  (list || []).filter(isRoomAvailable).forEach((room) => {
    const opt = document.createElement("option");
    opt.value = String(room.id);
    opt.textContent = `Room ${room.room_number} — ${room.room_type} — KSh ${formatMoney(room.price)}`;
    select.appendChild(opt);
  });
  if (current) select.value = current;
}

async function submitWalkIn(e) {
  e.preventDefault();
  const payload = {
    guest_name: getValue("walkInGuestName"),
    phone: getValue("walkInPhone"),
    email: getValue("walkInEmail"),
    id_number: getValue("walkInIdNumber"),
    room_id: Number(getValue("walkInRoom")),
    check_in: getValue("walkInCheckIn"),
    check_out: getValue("walkInCheckOut"),
    notes: getValue("walkInNotes"),
    auto_checkin: !!document.getElementById("walkInAutoCheckin")?.checked
  };

  if (!payload.guest_name || !payload.room_id || !payload.check_in || !payload.check_out) {
    showMessage("Please complete all required walk-in fields.", "error");
    return;
  }
  if (payload.check_out <= payload.check_in) {
    showMessage("Check-out must be after check-in.", "error");
    return;
  }

  const btn = document.getElementById("walkInSubmitBtn");
  try {
    setLoading(true);
    if (btn) { btn.disabled = true; btn.textContent = "Creating..."; }
    const result = await apiFetch("walkin.php", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    const data = unwrapData(result) || {};
    let msg = result.message || "Walk-in booking created.";
    if (data.booking_id) msg += ` Booking #${data.booking_id}.`;
    if (data.invoice_number) msg += ` Invoice ${data.invoice_number}.`;
    showMessage(msg, "success");
    document.getElementById("walkInForm")?.reset();
    setMinimumDates("walkInCheckIn", "walkInCheckOut");
    await loadAll();
    if (typeof loadReport === "function") loadReport();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
    if (btn) { btn.disabled = false; btn.textContent = "📝 Create Walk-in Booking"; }
  }
}

// =====================================================
// REPORTS
// =====================================================

async function loadReport() {
  const from = getValue("reportFrom") || `${new Date().getFullYear()}-${String(new Date().getMonth()+1).padStart(2,"0")}-01`;
  const to = getValue("reportTo") || getToday();
  try {
    setLoading(true);
    const result = await apiFetch(`reports.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`);
    const data = unwrapData(result) || {};
    renderReport(data);
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}

function renderReport(data) {
  const today = data.today || {};
  const period = data.period || {};

  setText("rptOccupancy", `${today.occupancy_pct ?? 0}%`);
  setText("rptAvailable", today.available_rooms ?? 0);
  setText("rptInHouse", today.in_house ?? 0);
  setText("rptArrivals", today.arrivals ?? 0);
  setText("rptDepartures", today.departures ?? 0);
  setText("rptCollected", formatMoney(period.payments_collected ?? 0));
  setText("rptBookings", period.bookings ?? 0);
  setText("rptCancels", period.cancellations ?? 0);
  setText("rptPayCount", period.payment_count ?? 0);
  setText("rptInvoiced", formatMoney(period.invoiced_total ?? 0));
  setText("rptOutstanding", formatMoney(period.outstanding_all ?? 0));

  const byMethod = data.by_payment_method || [];
  const methodBox = document.getElementById("rptByMethod");
  if (methodBox) {
    if (!byMethod.length) {
      methodBox.innerHTML = `<p style="color:#718096;">No payments in this range.</p>`;
    } else {
      methodBox.innerHTML = byMethod.map((m) =>
        `<p><strong>${escapeHtml(m.payment_method)}:</strong> KSh ${formatMoney(m.total)}</p>`
      ).join("");
    }
  }

  const occBody = document.querySelector("#rptOccupancyTable tbody");
  if (occBody) {
    const days = data.occupancy_by_day || [];
    if (!days.length) {
      occBody.innerHTML = `<tr><td colspan="4">No data.</td></tr>`;
    } else {
      occBody.innerHTML = days.map((d) => {
        const pct = Number(d.occupancy_pct || 0);
        return `<tr>
          <td>${escapeHtml(d.date)}</td>
          <td>${escapeHtml(d.booked_rooms)}</td>
          <td>${pct}%</td>
          <td>
            <div style="background:#e5eaf0;border-radius:6px;height:10px;width:100%;max-width:180px;overflow:hidden;">
              <div style="height:100%;width:${Math.min(100, pct)}%;background:linear-gradient(90deg,#1976d2,#42a5f5);border-radius:6px;"></div>
            </div>
          </td>
        </tr>`;
      }).join("");
    }
  }

  const topBody = document.querySelector("#rptTopTypesTable tbody");
  if (topBody) {
    const types = data.top_room_types || [];
    if (!types.length) {
      topBody.innerHTML = `<tr><td colspan="2">No bookings in this range.</td></tr>`;
    } else {
      topBody.innerHTML = types.map((t) =>
        `<tr><td>${escapeHtml(t.room_type)}</td><td>${escapeHtml(t.bookings)}</td></tr>`
      ).join("");
    }
  }
}

// Auto-load report after full load
const _prevLoadAllReport = typeof loadAll === "function" ? loadAll : null;
if (_prevLoadAllReport) {
  loadAll = async function () {
    await _prevLoadAllReport();
    try { await loadReport(); } catch (e) {}
  };
}

// =====================================================
// HOUSEKEEPING
// =====================================================

let hkRooms = [];
let hkFilter = "";

function registerHousekeepingEvents() {
  document.querySelectorAll(".hk-filter").forEach((btn) => {
    btn.addEventListener("click", () => {
      document.querySelectorAll(".hk-filter").forEach((b) => b.classList.remove("active", "btn-primary"));
      document.querySelectorAll(".hk-filter").forEach((b) => b.classList.add("btn-secondary"));
      btn.classList.add("active", "btn-primary");
      btn.classList.remove("btn-secondary");
      hkFilter = btn.dataset.hk || "";
      loadHousekeeping();
    });
  });
  document.getElementById("refreshHkBtn")?.addEventListener("click", loadHousekeeping);
}

const _prevOpsHk = typeof registerOpsEvents === "function" ? registerOpsEvents : null;
registerOpsEvents = function () {
  if (_prevOpsHk) _prevOpsHk();
  registerHousekeepingEvents();
};

const _prevLoadAllHk = typeof loadAll === "function" ? loadAll : null;
if (_prevLoadAllHk) {
  loadAll = async function () {
    await _prevLoadAllHk();
    try { await loadHousekeeping(); } catch (e) {}
  };
}

async function loadHousekeeping() {
  try {
    const q = hkFilter ? `housekeeping.php?status=${encodeURIComponent(hkFilter)}` : "housekeeping.php";
    const result = await apiFetch(q);
    const data = unwrapData(result) || {};
    hkRooms = Array.isArray(data.rooms) ? data.rooms : [];
    const counts = data.counts || {};
    setText("hkReady", counts.ready ?? 0);
    setText("hkDirty", counts.dirty ?? 0);
    setText("hkCleaning", counts.cleaning ?? 0);
    setText("hkOoo", counts.out_of_order ?? 0);
    renderHousekeepingTable(hkRooms);
  } catch (e) {
    console.warn("Housekeeping load failed:", e.message);
    const tbody = document.querySelector("#housekeepingTable tbody");
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="8">${escapeHtml(e.message)}. Run migration_housekeeping.sql if columns are missing.</td></tr>`;
    }
  }
}

function hkLabel(status) {
  const map = {
    ready: "Ready",
    dirty: "Dirty",
    cleaning: "Cleaning",
    out_of_order: "Out of Order"
  };
  return map[status] || status || "Ready";
}

function renderHousekeepingTable(list) {
  const tbody = document.querySelector("#housekeepingTable tbody");
  if (!tbody) return;
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="8">No rooms match this filter.</td></tr>`;
    return;
  }
  tbody.innerHTML = list.map((r) => {
    const hk = r.housekeeping_status || "ready";
    const occ = r.occupancy_status || "available";
    const guest = r.guest_name
      ? `${escapeHtml(r.guest_name)}${r.stay_status ? ` <small>(${escapeHtml(r.stay_status)})</small>` : ""}`
      : "—";
    return `
      <tr>
        <td><strong>${escapeHtml(r.room_number)}</strong></td>
        <td>${escapeHtml(r.room_type)}</td>
        <td><span class="badge ${occ === "booked" ? "booked" : "available"}">${escapeHtml(occ)}</span></td>
        <td>${guest}</td>
        <td><span class="badge hk-${escapeHtml(hk)}">${escapeHtml(hkLabel(hk))}</span></td>
        <td>${escapeHtml(r.housekeeping_notes || "—")}</td>
        <td>${escapeHtml(r.housekeeping_updated_at || "—")}</td>
        <td>
          <div class="hk-actions">
            <button type="button" class="btn-primary" onclick="setHkStatus(${Number(r.id)}, 'ready')">Ready</button>
            <button type="button" class="btn-secondary" onclick="setHkStatus(${Number(r.id)}, 'dirty')">Dirty</button>
            <button type="button" class="btn-secondary" onclick="setHkStatus(${Number(r.id)}, 'cleaning')">Cleaning</button>
            <button type="button" class="btn-danger" onclick="setHkStatus(${Number(r.id)}, 'out_of_order')">OOO</button>
          </div>
        </td>
      </tr>`;
  }).join("");
}

async function setHkStatus(roomId, status) {
  let notes = "";
  if (status === "out_of_order") {
    notes = prompt("Out of order reason (optional):", "") || "";
  } else if (status === "cleaning") {
    notes = prompt("Cleaning notes (optional):", "") || "";
  }
  try {
    setLoading(true);
    const result = await apiFetch("housekeeping.php", {
      method: "POST",
      body: JSON.stringify({ room_id: Number(roomId), status, notes })
    });
    showMessage(result.message || "Status updated.", "success");
    await Promise.all([loadHousekeeping(), loadRooms(), loadDashboard()]);
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}
window.setHkStatus = setHkStatus;

// =====================================================
// INVENTORY (linked to housekeeping)
// =====================================================

let inventoryItems = [];

function registerInventoryEvents() {
  document.getElementById("showInvFormBtn")?.addEventListener("click", () => {
    document.getElementById("inventoryForm").style.display = "grid";
  });
  document.getElementById("cancelInvFormBtn")?.addEventListener("click", () => {
    document.getElementById("inventoryForm").style.display = "none";
    document.getElementById("inventoryForm")?.reset();
  });
  document.getElementById("inventoryForm")?.addEventListener("submit", createInventoryItem);
  document.getElementById("stockAdjustForm")?.addEventListener("submit", adjustStock);
  document.getElementById("refreshInvBtn")?.addEventListener("click", loadInventory);
}

const _prevOpsInv = typeof registerOpsEvents === "function" ? registerOpsEvents : null;
registerOpsEvents = function () {
  if (_prevOpsInv) _prevOpsInv();
  registerInventoryEvents();
};

const _prevLoadAllInv = typeof loadAll === "function" ? loadAll : null;
if (_prevLoadAllInv) {
  loadAll = async function () {
    await _prevLoadAllInv();
    try { await loadInventory(); } catch (e) {}
  };
}

async function loadInventory() {
  try {
    const [listRes, txnRes] = await Promise.all([
      apiFetch("inventory.php"),
      apiFetch("inventory.php?action=transactions")
    ]);
    const data = unwrapData(listRes) || {};
    inventoryItems = Array.isArray(data.items) ? data.items : [];
    renderInventoryTable(inventoryItems);
    populateStockItemSelect(inventoryItems);

    const low = data.low_stock || [];
    const alert = document.getElementById("invLowStockAlert");
    if (alert) {
      if (low.length) {
        alert.style.display = "block";
        alert.className = "message error";
        alert.textContent = `Low stock: ${low.map((i) => i.name).join(", ")}`;
      } else {
        alert.style.display = "none";
      }
    }

    const txns = Array.isArray(unwrapData(txnRes)) ? unwrapData(txnRes) : [];
    renderInvTransactions(txns);
  } catch (e) {
    const tbody = document.querySelector("#inventoryTable tbody");
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="8">${escapeHtml(e.message)}. Run migration_inventory.sql if needed.</td></tr>`;
    }
  }
}

function renderInventoryTable(list) {
  const tbody = document.querySelector("#inventoryTable tbody");
  if (!tbody) return;
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="8">No inventory items yet.</td></tr>`;
    return;
  }
  tbody.innerHTML = list.map((i) => {
    const low = i.low_stock || Number(i.quantity) <= Number(i.reorder_level);
    return `
      <tr>
        <td>${escapeHtml(i.name)}</td>
        <td>${escapeHtml(i.category)}</td>
        <td><strong>${escapeHtml(i.quantity)}</strong></td>
        <td>${escapeHtml(i.unit)}</td>
        <td>${escapeHtml(i.reorder_level)}</td>
        <td>KSh ${formatMoney(i.unit_cost)}</td>
        <td>${low
          ? '<span class="badge hk-out_of_order">Low stock</span>'
          : '<span class="badge hk-ready">OK</span>'}</td>
        <td>
          <button type="button" class="btn-danger" style="min-height:32px;padding:6px 10px;font-size:12px;"
            onclick="deactivateInventoryItem(${Number(i.id)})">Deactivate</button>
        </td>
      </tr>`;
  }).join("");
}

function populateStockItemSelect(list) {
  const select = document.getElementById("stockItem");
  if (!select) return;
  const cur = select.value;
  select.innerHTML = `<option value="">Select item</option>`;
  list.forEach((i) => {
    const opt = document.createElement("option");
    opt.value = String(i.id);
    opt.textContent = `${i.name} (${i.quantity} ${i.unit})`;
    select.appendChild(opt);
  });
  if (cur) select.value = cur;
}

function renderInvTransactions(list) {
  const tbody = document.querySelector("#invTxnTable tbody");
  if (!tbody) return;
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="8">No movements yet.</td></tr>`;
    return;
  }
  tbody.innerHTML = list.map((t) => `
    <tr>
      <td>${escapeHtml(t.created_at || "—")}</td>
      <td>${escapeHtml(t.item_name)}</td>
      <td>${escapeHtml(t.txn_type)}</td>
      <td>${escapeHtml(t.quantity)}</td>
      <td>${escapeHtml(t.balance_after)}</td>
      <td>${escapeHtml(t.room_number || "—")}</td>
      <td>${escapeHtml(t.recorded_by_name || "—")}</td>
      <td>${escapeHtml(t.notes || t.reference || "—")}</td>
    </tr>`).join("");
}

async function createInventoryItem(e) {
  e.preventDefault();
  const payload = {
    action: "create",
    name: getValue("invName"),
    category: getValue("invCategory"),
    unit: getValue("invUnit") || "pcs",
    quantity: Number(getValue("invQty") || 0),
    reorder_level: Number(getValue("invReorder") || 0),
    unit_cost: Number(getValue("invCost") || 0)
  };
  try {
    setLoading(true);
    await apiFetch("inventory.php", { method: "POST", body: JSON.stringify(payload) });
    showMessage("Inventory item created.", "success");
    document.getElementById("inventoryForm")?.reset();
    document.getElementById("inventoryForm").style.display = "none";
    await loadInventory();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}

async function adjustStock(e) {
  e.preventDefault();
  const payload = {
    action: getValue("stockAction"),
    item_id: Number(getValue("stockItem")),
    quantity: Number(getValue("stockQty")),
    notes: getValue("stockNotes")
  };
  if (!payload.item_id || !(payload.quantity > 0)) {
    showMessage("Select an item and enter quantity.", "error");
    return;
  }
  try {
    setLoading(true);
    const result = await apiFetch("inventory.php", {
      method: "POST",
      body: JSON.stringify(payload)
    });
    showMessage(result.message || "Stock updated.", "success");
    document.getElementById("stockAdjustForm")?.reset();
    await loadInventory();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}

async function deactivateInventoryItem(id) {
  if (!confirm("Deactivate this inventory item?")) return;
  try {
    setLoading(true);
    await apiFetch("inventory.php", {
      method: "DELETE",
      body: JSON.stringify({ id: Number(id) })
    });
    showMessage("Item deactivated.", "success");
    await loadInventory();
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}
window.deactivateInventoryItem = deactivateInventoryItem;


// =====================================================
// NIGHT AUDIT
// =====================================================
async function loadNightAudit() {
  const date = getValue("auditDate") || getToday();
  try {
    setLoading(true);
    const data = await apiFetch("night_audit.php?date=" + encodeURIComponent(date));
    const audit = unwrapData(data) || {};
    const s = audit.summary || {};
    setText("audOccupancy", (s.occupancy_pct ?? "—") + "%");
    setText("audArrivals", s.expected_arrivals ?? "—");
    setText("audDepartures", s.expected_departures ?? "—");
    setText("audInHouse", s.in_house ?? "—");
    setText("audCollected", "KSh " + formatMoney(s.payments_total ?? 0));
    setText("audOpen", "KSh " + formatMoney(s.open_balance_total ?? 0));

    const by = s.payments_by_method || {};
    const byEl = document.getElementById("audByMethod");
    if (byEl) {
      const keys = Object.keys(by);
      byEl.innerHTML = keys.length
        ? keys.map((k) => `<p><strong>${escapeHtml(k)}:</strong> KSh ${formatMoney(by[k])}</p>`).join("")
        : "<p style=\"color:#718096\">No payments this date.</p>";
    }
    const hk = audit.housekeeping || {};
    const hkEl = document.getElementById("audHk");
    if (hkEl) {
      const keys = Object.keys(hk);
      hkEl.innerHTML = keys.length
        ? keys.map((k) => `<p><strong>${escapeHtml(k)}:</strong> ${escapeHtml(hk[k])}</p>`).join("")
        : "<p style=\"color:#718096\">—</p>";
    }

    const fill = (tableId, rows, cols) => {
      const tbody = document.querySelector("#" + tableId + " tbody");
      if (!tbody) return;
      if (!rows || !rows.length) {
        tbody.innerHTML = `<tr><td colspan="${cols}">None</td></tr>`;
        return;
      }
      tbody.innerHTML = rows.map((r) => `
        <tr>
          <td>#${escapeHtml(r.id)}</td>
          <td>${escapeHtml(r.guest_name)}</td>
          <td>${escapeHtml(r.room_number)} <small>(${escapeHtml(r.room_type || "")})</small></td>
          <td>${escapeHtml(r.check_in)} → ${escapeHtml(r.check_out)}</td>
          <td>${escapeHtml(r.stay_status || r.status || "—")}</td>
        </tr>`).join("");
    };
    fill("audArrivalsTable", audit.arrivals, 5);
    fill("audDeparturesTable", audit.departures, 5);

    const openBody = document.querySelector("#audOpenTable tbody");
    if (openBody) {
      const rows = audit.open_balances || [];
      openBody.innerHTML = rows.length
        ? rows.map((r) => `
          <tr>
            <td>${escapeHtml(r.invoice_number || r.id)}</td>
            <td>${escapeHtml(r.guest_name)}</td>
            <td>${escapeHtml(r.room_number)}</td>
            <td>KSh ${formatMoney(r.total_amount)}</td>
            <td>KSh ${formatMoney(r.amount_paid)}</td>
            <td><strong>KSh ${formatMoney(r.balance_due)}</strong></td>
          </tr>`).join("")
        : `<tr><td colspan="6">No open balances.</td></tr>`;
    }

    setText("audMeta", `Generated ${audit.generated_at || ""} by ${audit.generated_by || "staff"}`);
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}

document.getElementById("loadNightAuditBtn")?.addEventListener("click", loadNightAudit);
document.getElementById("printNightAuditBtn")?.addEventListener("click", () => window.print());
document.addEventListener("DOMContentLoaded", () => {
  const el = document.getElementById("auditDate");
  if (el && !el.value) el.value = getToday();
});
