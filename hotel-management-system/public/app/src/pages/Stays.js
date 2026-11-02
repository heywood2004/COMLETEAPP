
import React, { useEffect, useState } from "react";
import { Link, Navigate } from "react-router-dom";
import { api, kes } from "../api.js";

function statusLabel(s) {
  if (s === "checked_in") return "In house";
  if (s === "checked_out") return "Checked out";
  if (s === "cancelled") return "Cancelled";
  return "Reserved";
}

export function Stays({ user }) {
  const [rows, setRows] = useState([]);
  const [error, setError] = useState("");

  async function load() {
    try {
      const active = await api("bookings.php?scope=active");
      const history = await api("bookings.php?scope=history");
      const a = active?.data?.bookings || [];
      const h = history?.data?.bookings || [];
      setRows([...a, ...h]);
      setError("");
    } catch (e) {
      setError(e.message || "Could not load stays.");
    }
  }

  useEffect(() => {
    if (user) load();
  }, [user]);

  if (!user) return React.createElement(Navigate, { to: "/login", replace: true });

  const active = rows.filter((r) => r.status === "active" || r.status === "reserved" || r.stay_status === "checked_in");
  // backend uses status active/cancelled and stay_status
  const listActive = rows.filter((r) => (r.status || "") !== "cancelled" && (r.stay_status || "") !== "checked_out");
  const listPast = rows.filter((r) => (r.status || "") === "cancelled" || (r.stay_status || "") === "checked_out");

  async function cancel(id) {
    await api("cancel.php", { method: "POST", body: { booking_id: id } });
    await load();
  }

  function Section(title, items) {
    return React.createElement(
      "section",
      { style: { marginTop: 20 } },
      React.createElement("h2", null, title),
      !items.length && React.createElement("p", { className: "muted" }, "Nothing here yet."),
      React.createElement(
        "div",
        { className: "stay-list" },
        items.map((b) =>
          React.createElement(
            "article",
            { className: "stay-card", key: b.id },
            React.createElement("img", {
              src: "../images/hotel-hero-3.jpg",
              alt: "",
            }),
            React.createElement(
              "div",
              null,
              React.createElement(
                "strong",
                null,
                `Room ${b.room_number || ""} · ${b.room_type || ""}`
              ),
              React.createElement(
                "p",
                { className: "muted" },
                `${b.guest_name || ""} · ${b.check_in} → ${b.check_out}`
              ),
              React.createElement(
                "p",
                { className: "muted" },
                `${statusLabel(b.stay_status || b.status)}` +
                  (b.total_amount ? ` · ${kes(b.total_amount)}` : "")
              ),
              (b.status === "active" || b.status === "reserved") &&
                (b.stay_status === "reserved" || !b.stay_status || b.stay_status === "booked") &&
                React.createElement(
                  "button",
                  {
                    type: "button",
                    className: "btn btn-danger",
                    style: { marginTop: 6, padding: "6px 12px" },
                    onClick: () => cancel(b.id).catch((e) => alert(e.message)),
                  },
                  "Cancel"
                )
            )
          )
        )
      )
    );
  }

  return React.createElement(
    React.Fragment,
    null,
    React.createElement("h1", null, "My stays"),
    React.createElement("p", { className: "muted" }, "Live data from your PHP bookings API."),
    error && React.createElement("p", { className: "error" }, error),
    Section("Active", listActive),
    Section("History", listPast),
    React.createElement(
      "div",
      { style: { marginTop: 16 } },
      React.createElement(Link, { to: "/", className: "btn btn-primary" }, "Browse rooms")
    )
  );
}
