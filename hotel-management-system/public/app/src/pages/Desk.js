
import React, { useEffect, useState } from "react";
import { Navigate } from "react-router-dom";
import { api } from "../api.js";

const STAFF = new Set(["admin", "manager", "receptionist", "accountant"]);

export function Desk({ user }) {
  const [rows, setRows] = useState([]);
  const [error, setError] = useState("");
  const [msg, setMsg] = useState("");

  async function load() {
    try {
      const res = await api("bookings.php?scope=all");
      setRows(res?.data?.bookings || []);
      setError("");
    } catch (e) {
      setError(e.message || "Unable to load desk data.");
    }
  }

  useEffect(() => {
    if (user && STAFF.has((user.role || "").toLowerCase())) load();
  }, [user]);

  if (!user) return React.createElement(Navigate, { to: "/login", replace: true });
  if (!STAFF.has((user.role || "").toLowerCase())) {
    return React.createElement(
      "div",
      { className: "panel" },
      React.createElement("h1", null, "Front desk"),
      React.createElement(
        "p",
        { className: "muted" },
        "Staff accounts only. Sign in with a receptionist, manager, or admin user."
      )
    );
  }

  const inHouse = rows.filter((b) => b.stay_status === "checked_in");
  const arrivals = rows.filter(
    (b) => (b.status === "active" || b.status === "reserved") && (b.stay_status === "reserved" || !b.stay_status || b.stay_status === "booked")
  );

  async function move(id, action) {
    setMsg("");
    try {
      const endpoint = action === "check_in" ? "checkin.php" : "checkout.php";
      await api(endpoint, { method: "POST", body: { booking_id: id } });
      setMsg(action === "check_in" ? "Checked in." : "Checked out.");
      await load();
    } catch (e) {
      setError(e.message);
    }
  }

  return React.createElement(
    React.Fragment,
    null,
    React.createElement("h1", null, "Front desk"),
    React.createElement("p", { className: "muted" }, `Signed in as ${user.name} (${user.role})`),
    React.createElement(
      "div",
      { className: "stats" },
      React.createElement(
        "div",
        { className: "stat" },
        React.createElement("span", null, "In house"),
        React.createElement("strong", null, String(inHouse.length))
      ),
      React.createElement(
        "div",
        { className: "stat" },
        React.createElement("span", null, "Arrivals"),
        React.createElement("strong", null, String(arrivals.length))
      ),
      React.createElement(
        "div",
        { className: "stat" },
        React.createElement("span", null, "Bookings"),
        React.createElement("strong", null, String(rows.length))
      )
    ),
    error && React.createElement("p", { className: "error" }, error),
    msg && React.createElement("p", { className: "ok" }, msg),
    React.createElement("h2", { style: { marginTop: 20 } }, "Arrivals / reserved"),
    React.createElement(
      "div",
      { className: "stay-list" },
      arrivals.map((b) =>
        React.createElement(
          "div",
          { className: "panel row", key: b.id, style: { marginTop: 0 } },
          React.createElement(
            "div",
            { style: { flex: 1 } },
            React.createElement("strong", null, `${b.guest_name} · Room ${b.room_number}`),
            React.createElement("div", { className: "muted" }, `${b.check_in} → ${b.check_out}`)
          ),
          React.createElement(
            "button",
            {
              type: "button",
              className: "btn btn-brass",
              onClick: () => move(b.id, "check_in"),
            },
            "Check in"
          )
        )
      )
    ),
    React.createElement("h2", { style: { marginTop: 20 } }, "In house"),
    React.createElement(
      "div",
      { className: "stay-list" },
      inHouse.map((b) =>
        React.createElement(
          "div",
          { className: "panel row", key: b.id, style: { marginTop: 0 } },
          React.createElement(
            "div",
            { style: { flex: 1 } },
            React.createElement("strong", null, `${b.guest_name} · Room ${b.room_number}`),
            React.createElement("div", { className: "muted" }, `${b.check_in} → ${b.check_out}`)
          ),
          React.createElement(
            "button",
            {
              type: "button",
              className: "btn btn-primary",
              onClick: () => move(b.id, "check_out"),
            },
            "Check out"
          )
        )
      )
    )
  );
}
