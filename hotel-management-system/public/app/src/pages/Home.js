
import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api, kes, ymd } from "../api.js";

export function Home({ user }) {
  const [checkIn, setCheckIn] = useState(ymd(1));
  const [checkOut, setCheckOut] = useState(ymd(3));
  const [rooms, setRooms] = useState([]);
  const [nights, setNights] = useState(0);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [picked, setPicked] = useState(null);
  const [guestName, setGuestName] = useState(user?.name || "");

  async function search() {
    setBusy(true);
    setError("");
    try {
      const res = await api(
        `availability.php?check_in=${encodeURIComponent(checkIn)}&check_out=${encodeURIComponent(checkOut)}`
      );
      const list = res?.data?.rooms || res?.data || [];
      setRooms(Array.isArray(list) ? list : []);
      setNights(res?.data?.nights || 0);
    } catch (e) {
      // fallback public gallery if availability fails
      try {
        const res = await api("public_rooms.php");
        const list = res?.data?.rooms || res?.data || [];
        setRooms(Array.isArray(list) ? list : []);
        setNights(0);
      } catch (e2) {
        setError(e.message || "Could not load rooms.");
      }
    } finally {
      setBusy(false);
    }
  }

  useEffect(() => {
    search();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (user?.name) setGuestName(user.name);
  }, [user]);

  async function confirm() {
    if (!picked) return;
    setBusy(true);
    setError("");
    try {
      const roomId = picked.id || picked.room_id;
      const res = await api("book.php", {
        method: "POST",
        body: {
          room_id: roomId,
          guest_name: guestName,
          check_in: checkIn,
          check_out: checkOut,
        },
      });
      setNote(res.message || "Booking confirmed.");
      setPicked(null);
      await search();
    } catch (e) {
      setError(e.message || "Booking failed.");
    } finally {
      setBusy(false);
    }
  }

  return React.createElement(
    React.Fragment,
    null,
    React.createElement(
      "section",
      { className: "hero" },
      React.createElement("img", {
        src: "../images/hotel-hero-1.jpg",
        alt: "Hotel",
        onError: (e) => {
          e.currentTarget.style.display = "none";
        },
      }),
      React.createElement(
        "div",
        { className: "hero-body" },
        React.createElement("div", { className: "muted", style: { color: "var(--brass)" } }, "Phase 2 app"),
        React.createElement("h1", null, "Find your room"),
        React.createElement(
          "p",
          null,
          "Same hotel system — new app interface. Dates filter rooms free for the whole stay."
        )
      )
    ),
    React.createElement(
      "form",
      {
        className: "panel grid-dates",
        onSubmit: (e) => {
          e.preventDefault();
          search();
        },
      },
      React.createElement(
        "label",
        null,
        "Check-in",
        React.createElement("input", {
          type: "date",
          value: checkIn,
          onChange: (e) => setCheckIn(e.target.value),
          required: true,
        })
      ),
      React.createElement(
        "label",
        null,
        "Check-out",
        React.createElement("input", {
          type: "date",
          value: checkOut,
          onChange: (e) => setCheckOut(e.target.value),
          required: true,
        })
      ),
      React.createElement(
        "button",
        { type: "submit", className: "btn btn-primary", disabled: busy },
        busy ? "Searching…" : "Search"
      )
    ),
    error && React.createElement("p", { className: "error" }, error),
    note && React.createElement("p", { className: "ok" }, note),
    React.createElement(
      "div",
      { className: "room-grid" },
      rooms.map((room) => {
        const free = room.available !== false && room.free !== false;
        const price = Number(room.price || room.rate || 0);
        const photo =
          room.image || room.photo || room.image_url || "../images/hotel-hero-2.jpg";
        const title = `${room.room_type || room.type || "Room"} · ${room.room_number || room.number || ""}`;
        return React.createElement(
          "article",
          { className: "room-card", key: room.id || room.room_number },
          React.createElement("img", { src: photo, alt: title }),
          React.createElement(
            "div",
            { className: "body" },
            React.createElement("h3", null, title),
            React.createElement("p", { className: "muted" }, room.description || room.blurb || ""),
            React.createElement(
              "p",
              { className: "muted" },
              `${kes(price)} / night` +
                (nights > 0 ? ` · ${nights} nights ${kes(price * nights)}` : "")
            ),
            React.createElement(
              "button",
              {
                type: "button",
                className: "btn btn-brass",
                disabled: !free,
                onClick: () => setPicked(room),
              },
              free ? "Reserve" : "Unavailable"
            )
          )
        );
      })
    ),
    picked &&
      React.createElement(
        "div",
        { className: "modal-backdrop", onClick: (e) => e.target === e.currentTarget && setPicked(null) },
        React.createElement(
          "div",
          { className: "modal stack" },
          React.createElement(
            "h2",
            null,
            `Room ${picked.room_number || picked.number || ""}`
          ),
          React.createElement(
            "p",
            { className: "muted" },
            `${checkIn} → ${checkOut}`
          ),
          !user
            ? React.createElement(
                React.Fragment,
                null,
                React.createElement("p", null, "Sign in to complete the booking."),
                React.createElement(Link, { to: "/login", className: "btn btn-primary" }, "Sign in")
              )
            : React.createElement(
                React.Fragment,
                null,
                React.createElement(
                  "label",
                  null,
                  "Guest name",
                  React.createElement("input", {
                    value: guestName,
                    onChange: (e) => setGuestName(e.target.value),
                  })
                ),
                React.createElement(
                  "button",
                  {
                    type: "button",
                    className: "btn btn-primary",
                    disabled: busy,
                    onClick: confirm,
                  },
                  "Confirm booking"
                )
              ),
          React.createElement(
            "button",
            { type: "button", className: "btn btn-secondary", onClick: () => setPicked(null) },
            "Close"
          )
        )
      )
  );
}
