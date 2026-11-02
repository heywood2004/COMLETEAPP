"use strict";

let currentUser = null;
let rooms = [];
let bookings = [];
let lastSearch = { checkIn: "", checkOut: "" };

document.addEventListener("DOMContentLoaded", init);

async function init() {
  setMinimumDates("searchCheckIn", "searchCheckOut");
  setMinimumDates("checkIn", "checkOut");

  // Sensible defaults: tonight → tomorrow
  const today = getToday();
  const tomorrow = addDays(today, 1);
  setValue("searchCheckIn", today);
  setValue("searchCheckOut", tomorrow);
  setValue("checkIn", today);
  setValue("checkOut", tomorrow);

  registerEvents();
  const ok = await checkSession();
  if (!ok) return;
  await loadAll();
}

function addDays(isoDate, days) {
  const d = new Date(isoDate + "T12:00:00");
  d.setDate(d.getDate() + days);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

function nightsBetween(checkIn, checkOut) {
  if (!checkIn || !checkOut || checkOut <= checkIn) return 0;
  const a = new Date(checkIn + "T12:00:00");
  const b = new Date(checkOut + "T12:00:00");
  return Math.max(1, Math.round((b - a) / 86400000));
}

function registerEvents() {
  document.getElementById("bookingForm")?.addEventListener("submit", bookRoom);
  document.getElementById("searchAvailabilityBtn")?.addEventListener("click", searchAvailability);
  document.getElementById("filterType")?.addEventListener("change", applyLocalFilters);
  document.getElementById("searchRoom")?.addEventListener("input", applyLocalFilters);

  document.getElementById("searchCheckIn")?.addEventListener("change", () => {
    const ci = document.getElementById("searchCheckIn");
    const co = document.getElementById("searchCheckOut");
    if (ci && co) {
      co.min = ci.value || getToday();
      if (co.value && co.value <= ci.value) {
        co.value = addDays(ci.value, 1);
      }
    }
    // Keep booking form dates in sync when searching
    if (ci?.value) setValue("checkIn", ci.value);
    if (co?.value) setValue("checkOut", co.value);
    updateEstimate();
  });

  document.getElementById("searchCheckOut")?.addEventListener("change", () => {
    const co = getValue("searchCheckOut");
    if (co) setValue("checkOut", co);
    updateEstimate();
  });

  document.getElementById("checkIn")?.addEventListener("change", () => {
    const ci = document.getElementById("checkIn");
    const co = document.getElementById("checkOut");
    if (ci && co) {
      co.min = ci.value || getToday();
      if (co.value && co.value <= ci.value) co.value = addDays(ci.value, 1);
    }
    updateEstimate();
  });
  document.getElementById("checkOut")?.addEventListener("change", updateEstimate);
  document.getElementById("roomNumber")?.addEventListener("change", updateEstimate);

  document.getElementById("logoutButton")?.addEventListener("click", logout);
  document.getElementById("closeInvoiceButton")?.addEventListener("click", closeInvoice);
  document.getElementById("closeInvoiceButtonBottom")?.addEventListener("click", closeInvoice);
  document.getElementById("printInvoiceButton")?.addEventListener("click", () => window.print());
  document.getElementById("downloadInvoicePdfBtn")?.addEventListener("click", () => {
    const id = document.getElementById("invoiceBookingId")?.textContent;
    if (id && id !== "—") downloadInvoicePdf(id.replace("#", "").trim());
  });
  document.getElementById("invoiceModal")?.addEventListener("click", (e) => {
    if (e.target.id === "invoiceModal") closeInvoice();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeInvoice();
  });
}

async function checkSession() {
  try {
    const data = await apiFetch("session.php");
    const session = unwrapData(data) || {};
    if (!session.authenticated || !session.user) {
      window.location.href = "index.html";
      return false;
    }
    currentUser = session.user;
    if (session.csrf_token && typeof setCsrfToken === "function") setCsrfToken(session.csrf_token);
    if (currentUser.role === "admin") {
      window.location.href = "admin.html";
      return false;
    }
    setText("welcomeMessage", `Welcome, ${currentUser.name}`);
    setText("userName", currentUser.name);
    setText("userEmail", currentUser.email);
    setText("userBadge", "Guest account");
    // Prefill guest name
    if (!getValue("guestName")) setValue("guestName", currentUser.name || "");
    return true;
  } catch (e) {
    window.location.href = "index.html";
    return false;
  }
}

async function loadAll() {
  setLoading(true);
  try {
    await Promise.all([searchAvailability(true), loadAllBookings()]);
  } finally {
    setLoading(false);
  }
}

async function searchAvailability(silent = false) {
  const checkIn = getValue("searchCheckIn") || getToday();
  const checkOut = getValue("searchCheckOut") || addDays(checkIn, 1);

  if (checkOut <= checkIn) {
    showMessage("Check-out must be after check-in.", "error");
    return;
  }

  lastSearch = { checkIn, checkOut };
  setValue("checkIn", checkIn);
  setValue("checkOut", checkOut);

  try {
    if (!silent) setLoading(true);
    const result = await apiFetch(
      `availability.php?check_in=${encodeURIComponent(checkIn)}&check_out=${encodeURIComponent(checkOut)}`
    );
    const data = unwrapData(result) || {};
    rooms = Array.isArray(data.rooms) ? data.rooms : [];

    const summary = document.getElementById("availabilitySummary");
    if (summary) {
      summary.style.display = "block";
      summary.className = "message info";
      const n = data.nights || nightsBetween(checkIn, checkOut);
      summary.textContent = rooms.length
        ? `${rooms.length} room(s) available for ${n} night(s) · ${checkIn} → ${checkOut}`
        : `No rooms available for ${checkIn} → ${checkOut}. Try different dates.`;
    }

    applyLocalFilters();
    populateRoomSelect(rooms);
    updateEstimate();
  } catch (e) {
    rooms = [];
    document.getElementById("roomTable").innerHTML =
      `<div class="empty-state"><div class="empty-icon">🏨</div><h3>Could not load rooms</h3><p>${escapeHtml(e.message)}</p></div>`;
    if (!silent) showMessage(e.message, "error");
  } finally {
    if (!silent) setLoading(false);
  }
}

function applyLocalFilters() {
  const q = (document.getElementById("searchRoom")?.value || "").trim().toLowerCase();
  const type = (document.getElementById("filterType")?.value || "").trim().toLowerCase();
  let list = rooms.slice();
  if (type) {
    list = list.filter((r) => String(r.room_type || "").toLowerCase() === type);
  }
  if (q) {
    list = list.filter(
      (r) =>
        String(r.room_number || "").toLowerCase().includes(q) ||
        String(r.room_type || "").toLowerCase().includes(q)
    );
  }
  renderRooms(list);
}

function roomImage(type) {
  const map = {
    single: "hotel-hero-1.jpg",
    double: "hotel-hero-2.jpg",
    twin: "hotel-hero-3.jpg",
    deluxe: "hotel-hero-4.jpg",
    executive: "hotel-hero-5.jpg",
    presidential: "hotel-hero-6.jpg",
    suite: "hotel-hero-4.jpg"
  };
  return "images/" + (map[String(type || "").toLowerCase()] || "hotel-hero-1.jpg");
}

function renderRooms(list) {
  const container = document.getElementById("roomTable");
  if (!container) return;
  if (!list.length) {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon">🛏️</div>
        <h3>No rooms match your search</h3>
        <p>Adjust dates or room type and search again.</p>
      </div>`;
    return;
  }

  container.innerHTML = list
    .map((room) => {
      const price = Number(room.price || 0);
      const nights = Number(room.nights || nightsBetween(lastSearch.checkIn, lastSearch.checkOut) || 1);
      const total = Number(room.estimated_total != null ? room.estimated_total : price * nights);
      return `
      <article class="room-card available">
        <img src="${roomImage(room.room_type)}" alt="Room ${escapeHtml(room.room_number)}"
             loading="lazy" onerror="this.src='images/hotel-hero-1.jpg'">
        <div class="room-card-header">
          <h3>Room ${escapeHtml(room.room_number)}</h3>
          <span class="badge available">Available</span>
        </div>
        <div class="room-card-body">
          <p><strong>Type:</strong> ${escapeHtml(room.room_type)}</p>
          <p><strong>Capacity:</strong> ${escapeHtml(room.capacity)} guest(s)</p>
          <p><strong>Rate:</strong> <span class="room-price">KSh ${formatMoney(price)}</span> / night</p>
          <p><strong>For your stay:</strong> ${nights} night(s) · <span class="room-price">KSh ${formatMoney(total)}</span></p>
          ${room.description ? `<p class="room-desc">${escapeHtml(room.description)}</p>` : ""}
        </div>
        <div class="room-card-footer">
          <button type="button" class="btn-primary" onclick="selectRoom(${Number(room.id)})">
            Select &amp; book
          </button>
        </div>
      </article>`;
    })
    .join("");
}

function populateRoomSelect(list) {
  const select = document.getElementById("roomNumber");
  if (!select) return;
  const prev = select.value;
  select.innerHTML = `<option value="">Select a room</option>`;
  list.forEach((room) => {
    const opt = document.createElement("option");
    opt.value = String(room.id);
    const price = Number(room.price || 0);
    opt.dataset.price = String(price);
    opt.textContent = `Room ${room.room_number} · ${room.room_type} · KSh ${formatMoney(price)}/night`;
    select.appendChild(opt);
  });
  if (prev && [...select.options].some((o) => o.value === prev)) {
    select.value = prev;
  }
}

function selectRoom(roomId) {
  const select = document.getElementById("roomNumber");
  if (select) select.value = String(roomId);
  // Align booking dates with search
  if (lastSearch.checkIn) setValue("checkIn", lastSearch.checkIn);
  if (lastSearch.checkOut) setValue("checkOut", lastSearch.checkOut);
  updateEstimate();
  document.getElementById("bookingSection")?.scrollIntoView({ behavior: "smooth", block: "start" });
  document.getElementById("guestName")?.focus();
  showMessage("Room selected. Confirm details below to finish booking.", "info");
}
window.selectRoom = selectRoom;

function updateEstimate() {
  const box = document.getElementById("bookingEstimate");
  const select = document.getElementById("roomNumber");
  const checkIn = getValue("checkIn");
  const checkOut = getValue("checkOut");
  const nights = nightsBetween(checkIn, checkOut);
  const opt = select?.selectedOptions?.[0];
  const price = opt ? Number(opt.dataset.price || 0) : 0;

  if (!box) return;
  if (!nights || !price) {
    box.hidden = true;
    return;
  }
  box.hidden = false;
  setText("estNights", String(nights));
  setText("estRate", `KSh ${formatMoney(price)}`);
  setText("estTotal", `KSh ${formatMoney(price * nights)}`);
}

async function bookRoom(e) {
  e.preventDefault();
  const guestName = getValue("guestName");
  const roomId = getValue("roomNumber");
  const checkIn = getValue("checkIn");
  const checkOut = getValue("checkOut");
  const btn = document.getElementById("bookButton");

  if (!guestName || !roomId || !checkIn || !checkOut) {
    showMessage("Please complete all booking fields.", "error");
    return;
  }
  if (checkOut <= checkIn) {
    showMessage("Check-out must be after check-in.", "error");
    return;
  }

  try {
    setLoading(true);
    if (btn) {
      btn.disabled = true;
      btn.textContent = "Booking...";
    }
    const result = await apiFetch("book.php", {
      method: "POST",
      body: JSON.stringify({
        room_id: Number(roomId),
        guest_name: guestName,
        phone: getValue("guestPhone"),
        check_in: checkIn,
        check_out: checkOut
      })
    });
    const data = unwrapData(result) || {};
    showMessage(
      data.booking_id
        ? `Booking confirmed! Reference #${data.booking_id}. See it under My Stays.`
        : result.message || "Booked successfully.",
      "success"
    );
    // Reset room selection but keep name/dates for convenience
    const sel = document.getElementById("roomNumber");
    if (sel) sel.value = "";
    updateEstimate();
    await Promise.all([searchAvailability(true), loadAllBookings()]);
    document.getElementById("myBookingsSection")?.scrollIntoView({ behavior: "smooth" });
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
    if (btn) {
      btn.disabled = false;
      btn.textContent = "✓ Confirm booking";
    }
  }
}

let bookingScope = "active";
let historyBookings = [];

async function loadBookings(scope) {
  if (scope) bookingScope = scope;
  try {
    const data = await apiFetch("bookings.php?scope=" + encodeURIComponent(bookingScope));
    const payload = unwrapData(data) || {};
    const list = Array.isArray(payload.bookings)
      ? payload.bookings
      : (Array.isArray(payload) ? payload : (Array.isArray(unwrapData(data)) ? unwrapData(data) : []));

    if (bookingScope === "active") {
      bookings = list;
      setText("myBookingCount", bookings.length);
      renderBookings(bookings);
    } else {
      historyBookings = list;
      renderHistoryBookings(historyBookings);
    }
  } catch (e) {
    if (bookingScope === "active") {
      bookings = [];
      renderBookings([]);
      setText("myBookingCount", 0);
    } else {
      historyBookings = [];
      renderHistoryBookings([]);
    }
  }
}

async function loadAllBookings() {
  await loadBookings("active");
  await loadBookings("history");
  bookingScope = "active";
}

function renderHistoryBookings(list) {
  const tbody = document.querySelector("#historyTable tbody");
  const cards = document.getElementById("historyCards");
  const emptyMsg = !list.length;

  if (cards) {
    if (emptyMsg) {
      cards.innerHTML = `<div class="empty-state"><div class="empty-icon">📜</div><h3>No past stays yet</h3><p>Cancelled or completed bookings will appear here.</p></div>`;
    } else {
      cards.innerHTML = list.map((b) => {
        const label = b.status === "cancelled" ? "Cancelled" : (b.stay_status === "checked_out" ? "Completed" : (b.status || "Past"));
        const badgeClass = b.status === "cancelled" ? "hk-out_of_order" : "available";
        return `
        <article class="booking-card">
          <div class="booking-card-top">
            <div>
              <span class="section-label">BOOKING #${escapeHtml(b.id)}</span>
              <h3>Room ${escapeHtml(b.room_number)} · ${escapeHtml(b.room_type)}</h3>
            </div>
            <span class="badge ${badgeClass}">${escapeHtml(label)}</span>
          </div>
          <div class="booking-card-meta">
            <div><span>Guest</span><strong>${escapeHtml(b.guest_name)}</strong></div>
            <div><span>Check-in</span><strong>${escapeHtml(b.check_in)}</strong></div>
            <div><span>Check-out</span><strong>${escapeHtml(b.check_out)}</strong></div>
            <div><span>Rate</span><strong>KSh ${formatMoney(b.price)}</strong></div>
          </div>
          <div class="booking-card-actions">
            <button type="button" class="btn-primary" onclick="viewInvoice(${Number(b.id)})">📄 Invoice</button>
            <button type="button" class="btn-secondary" onclick="downloadInvoicePdf(${Number(b.id)})">⬇️ PDF</button>
          </div>
        </article>`;
      }).join("");
    }
  }

  if (tbody) {
    if (emptyMsg) {
      tbody.innerHTML = `<tr><td colspan="6">No past bookings.</td></tr>`;
    } else {
      tbody.innerHTML = list.map((b) => `
        <tr>
          <td>#${escapeHtml(b.id)}</td>
          <td>${escapeHtml(b.room_number)} <small>(${escapeHtml(b.room_type)})</small></td>
          <td>${escapeHtml(b.check_in)} → ${escapeHtml(b.check_out)}</td>
          <td>${escapeHtml(b.status)}</td>
          <td>${escapeHtml(b.stay_status || "—")}</td>
          <td>
            <div class="table-actions">
              <button type="button" class="btn-primary" style="min-height:36px;padding:8px 12px;font-size:13px;" onclick="viewInvoice(${Number(b.id)})">Invoice</button>
              <button type="button" class="btn-secondary" style="min-height:36px;padding:8px 12px;font-size:13px;" onclick="downloadInvoicePdf(${Number(b.id)})">PDF</button>
            </div>
          </td>
        </tr>`).join("");
    }
  }
}

function downloadInvoicePdf(bookingId) {
  const url = "../api/invoice_pdf.php?booking_id=" + encodeURIComponent(bookingId);
  window.open(url, "_blank", "noopener,width=900,height=1000");
}
window.downloadInvoicePdf = downloadInvoicePdf;


function stayBadge(b) {
  const stay = String(b.stay_status || "not_arrived").toLowerCase();
  if (stay === "checked_in") return `<span class="badge available">Checked in</span>`;
  if (stay === "checked_out") return `<span class="badge">Checked out</span>`;
  return `<span class="badge booked">Reserved</span>`;
}

function renderBookings(list) {
  const tbody = document.querySelector("#bookingTable tbody");
  const cards = document.getElementById("bookingCards");

  if (cards) {
    if (!list.length) {
      cards.innerHTML = `
        <div class="empty-state" style="margin-bottom:12px;">
          <div class="empty-icon">📭</div>
          <h3>No active bookings</h3>
          <p>Search dates above and reserve a room to see it here.</p>
        </div>`;
    } else {
      cards.innerHTML = list
        .map((b) => {
          const canCancel = String(b.stay_status || "not_arrived") === "not_arrived";
          return `
          <article class="booking-card">
            <div class="booking-card-top">
              <div>
                <span class="section-label">BOOKING #${escapeHtml(b.id)}</span>
                <h3>Room ${escapeHtml(b.room_number)} · ${escapeHtml(b.room_type)}</h3>
              </div>
              ${stayBadge(b)}
            </div>
            <div class="booking-card-meta">
              <div><span>Guest</span><strong>${escapeHtml(b.guest_name)}</strong></div>
              <div><span>Check-in</span><strong>${escapeHtml(b.check_in)}</strong></div>
              <div><span>Check-out</span><strong>${escapeHtml(b.check_out)}</strong></div>
              <div><span>Rate</span><strong>KSh ${formatMoney(b.price)}</strong></div>
            </div>
            <div class="booking-card-actions">
              <button type="button" class="btn-primary" onclick="viewInvoice(${Number(b.id)})">📄 Invoice</button>
              <button type="button" class="btn-secondary" onclick="downloadInvoicePdf(${Number(b.id)})">⬇️ PDF</button>
              ${
                Number(b.invoice_id) && Number(b.invoice_balance) > 0
                  ? `<button type="button" class="btn-primary" onclick="payWithMpesa(${Number(b.invoice_id)}, ${Number(b.invoice_balance)})">📱 Pay M-Pesa</button>`
                  : ""
              }
              ${
                canCancel
                  ? `<button type="button" class="btn-danger" onclick="cancelBookingById(${Number(b.id)})">Cancel</button>`
                  : `<button type="button" class="btn-secondary" disabled title="Already checked in">Cancel unavailable</button>`
              }
            </div>
          </article>`;
        })
        .join("");
    }
  }

  if (!tbody) return;
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="6">You have no active bookings.</td></tr>`;
    return;
  }
  tbody.innerHTML = list
    .map((b) => {
      const canCancel = String(b.stay_status || "not_arrived") === "not_arrived";
      return `
      <tr>
        <td>#${escapeHtml(b.id)}</td>
        <td>${escapeHtml(b.room_number)} <small>(${escapeHtml(b.room_type)})</small></td>
        <td>${escapeHtml(b.check_in)} → ${escapeHtml(b.check_out)}</td>
        <td>${stayBadge(b)}</td>
        <td><span class="badge booked">${escapeHtml(b.status || "active")}</span></td>
        <td>
          <div class="table-actions">
            <button type="button" class="btn-primary" style="min-height:36px;padding:8px 12px;font-size:13px;"
              onclick="viewInvoice(${Number(b.id)})">Invoice</button>
            ${
              canCancel
                ? `<button type="button" class="btn-danger" style="min-height:36px;padding:8px 12px;font-size:13px;"
                    onclick="cancelBookingById(${Number(b.id)})">Cancel</button>`
                : ""
            }
          </div>
        </td>
      </tr>`;
    })
    .join("");
}

async function cancelBookingById(id) {
  if (!id) return;
  if (!confirm("Cancel this booking? This cannot be undone.")) return;
  try {
    setLoading(true);
    await apiFetch("cancel.php", {
      method: "POST",
      body: JSON.stringify({ booking_id: Number(id) })
    });
    showMessage("Booking cancelled successfully.", "success");
    await Promise.all([searchAvailability(true), loadAllBookings()]);
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}
window.cancelBookingById = cancelBookingById;

async function logout() {
  if (!confirm("Log out of your account?")) return;
  try {
    await apiFetch("logout.php", { method: "POST" });
  } catch (e) {}
  window.location.href = "index.html";
}

async function viewInvoice(bookingId) {
  const modal = document.getElementById("invoiceModal");
  const loading = document.getElementById("invoiceLoading");
  const error = document.getElementById("invoiceError");
  const content = document.getElementById("invoiceContent");
  const actions = document.getElementById("invoiceActions");
  if (!modal) return;

  loading.hidden = false;
  error.hidden = true;
  content.hidden = true;
  actions.hidden = true;
  modal.classList.add("active");
  modal.setAttribute("aria-hidden", "false");
  document.body.classList.add("modal-open");

  try {
    const result = await apiFetch(`invoice.php?booking_id=${encodeURIComponent(bookingId)}`);
    const inv = unwrapData(result);
    if (!inv) throw new Error("No invoice data.");

    const money = (v) => `KSh ${formatMoney(v)}`;
    setText("invoiceNumber", inv.invoice_number);
    setText("invoiceDate", inv.invoice_date);
    setText("invoiceGuestName", inv.guest_name);
    setText("invoiceBookingId", inv.booking_id);
    setText("invoiceBookingStatus", inv.booking_status || inv.stay_status || "—");
    setText("invoiceRoomNumber", inv.room_number);
    setText("invoiceRoomType", inv.room_type);
    setText("invoiceCheckIn", inv.check_in);
    setText("invoiceCheckOut", inv.check_out);
    setText("invoiceNights", `${inv.nights} night(s)`);
    setText("invoiceDescription", `Room ${inv.room_number} — ${inv.room_type}`);
    setText("invoiceRate", money(inv.price_per_night));
    setText("invoiceTableNights", inv.nights);
    setText("invoiceSubtotal", money(inv.subtotal));
    setText("invoiceSubtotalTotal", money(inv.subtotal));
    setText("invoiceTaxRate", Number(inv.tax_rate || 0).toFixed(0));
    setText("invoiceTax", money(inv.tax || inv.tax_amount));
    setText("invoiceTotal", money(inv.total || inv.total_amount));

    loading.hidden = true;
    content.hidden = false;
    actions.hidden = false;
  } catch (err) {
    loading.hidden = true;
    error.textContent = err.message || "Unable to generate invoice.";
    error.hidden = false;
  }
}
window.viewInvoice = viewInvoice;

function closeInvoice() {
  const modal = document.getElementById("invoiceModal");
  if (!modal) return;
  modal.classList.remove("active");
  modal.setAttribute("aria-hidden", "true");
  document.body.classList.remove("modal-open");
}


async function payWithMpesa(invoiceId, balance) {
  const phone = prompt("Enter M-Pesa number (07XX XXX XXX):", getValue("guestPhone") || "");
  if (!phone) return;
  const amountStr = prompt("Amount to pay (KES):", String(Math.ceil(Number(balance) || 0)));
  if (!amountStr) return;
  const amount = Number(amountStr);
  if (!(amount >= 1)) {
    showMessage("Enter a valid amount.", "error");
    return;
  }
  try {
    setLoading(true);
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
    showMessage(result.message || "STK Push sent. Enter your M-Pesa PIN on your phone.", "success");
    // poll status briefly
    if (data.transaction_id || data.checkout_request_id) {
      let tries = 0;
      const timer = setInterval(async () => {
        tries += 1;
        try {
          const q = data.transaction_id
            ? `mpesa.php?action=status&id=${encodeURIComponent(data.transaction_id)}`
            : `mpesa.php?action=status&checkout_request_id=${encodeURIComponent(data.checkout_request_id)}`;
          const st = await apiFetch(q);
          const s = unwrapData(st) || {};
          if (s.status === "success") {
            clearInterval(timer);
            showMessage("Payment received. Receipt: " + (s.mpesa_receipt || "—"), "success");
            await loadAllBookings();
          } else if (["failed", "cancelled", "timeout"].includes(String(s.status))) {
            clearInterval(timer);
            showMessage(s.result_desc || "Payment not completed.", "error");
          }
        } catch (e) {}
        if (tries >= 24) clearInterval(timer);
      }, 5000);
    }
  } catch (err) {
    showMessage(err.message, "error");
  } finally {
    setLoading(false);
  }
}
window.payWithMpesa = payWithMpesa;
