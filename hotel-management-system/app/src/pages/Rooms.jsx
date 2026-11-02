import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { apiFetch, kes, nightsBetween, unwrap } from "../lib/api";
import { useAuth } from "../lib/auth.jsx";

function shiftDate(days) {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

export default function Rooms() {
  const { user } = useAuth();
  const [checkIn, setCheckIn] = useState(shiftDate(1));
  const [checkOut, setCheckOut] = useState(shiftDate(3));
  const [rooms, setRooms] = useState([]);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [picked, setPicked] = useState(null);
  const [guestName, setGuestName] = useState("");

  const nights = useMemo(() => nightsBetween(checkIn, checkOut), [checkIn, checkOut]);

  async function search() {
    setBusy(true);
    setError("");
    try {
      const body = await apiFetch(
        `availability.php?check_in=${encodeURIComponent(checkIn)}&check_out=${encodeURIComponent(checkOut)}`
      );
      const data = unwrap(body) || {};
      const list = Array.isArray(data.rooms) ? data.rooms : Array.isArray(data) ? data : [];
      setRooms(list);
    } catch (err) {
      // Fallback: public gallery if availability requires login and fails
      try {
        const pub = await apiFetch("public_rooms.php");
        const data = unwrap(pub) || {};
        const list = Array.isArray(data.rooms) ? data.rooms : [];
        setRooms(list.map((r) => ({ ...r, available: true })));
        setError(err.message);
      } catch {
        setError(err.message || "Could not load rooms.");
        setRooms([]);
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

  async function confirmBook() {
    if (!picked) return;
    setBusy(true);
    setError("");
    setNote("");
    try {
      const body = await apiFetch("book.php", {
        method: "POST",
        body: JSON.stringify({
          room_id: picked.id || picked.room_id,
          room_number: picked.room_number,
          guest_name: guestName,
          check_in: checkIn,
          check_out: checkOut,
        }),
      });
      const data = unwrap(body) || {};
      setNote(
        data.booking_id
          ? `Booking confirmed. Reference #${data.booking_id}.`
          : body.message || "Booking confirmed."
      );
      setPicked(null);
      await search();
    } catch (err) {
      setError(err.message || "Booking failed.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <section className="hero">
        <div className="eyebrow">Nairobi · Phase 2 app</div>
        <h1>Find a room</h1>
        <p>Same engine as the website — date search, live availability, and bookings on your account.</p>
      </section>

      <form
        className="card form-row dates"
        onSubmit={(e) => {
          e.preventDefault();
          search();
        }}
        style={{ marginBottom: "1rem" }}
      >
        <label>
          Check-in
          <input type="date" value={checkIn} onChange={(e) => setCheckIn(e.target.value)} required />
        </label>
        <label>
          Check-out
          <input type="date" value={checkOut} onChange={(e) => setCheckOut(e.target.value)} required />
        </label>
        <button className="btn btn-primary" type="submit" disabled={busy}>
          {busy ? "Searching…" : "Search"}
        </button>
      </form>

      {error ? <p className="error">{error}</p> : null}
      {note ? <p className="ok">{note}</p> : null}

      <div className="grid-2">
        {rooms.map((room) => {
          const id = room.id || room.room_id || room.room_number;
          const price = Number(room.price || room.min_price || 0);
          const free = room.available !== false && room.free !== false;
          return (
            <article key={id} className="card room-card">
              {room.image || room.photo ? (
                <img src={room.image || room.photo} alt="" />
              ) : (
                <div style={{ height: 120, borderRadius: 12, background: "var(--line)" }} />
              )}
              <h3>
                {room.room_type || "Room"} {room.room_number ? `· ${room.room_number}` : ""}
              </h3>
              <p className="muted">{room.description || room.blurb || "Comfortable stay"}</p>
              <p>
                {kes(price)} / night
                {nights > 0 && free ? ` · ${nights} nights ${kes(price * nights)}` : null}
              </p>
              <button
                type="button"
                className="btn btn-brass"
                disabled={!free}
                onClick={() => setPicked(room)}
              >
                {free ? "Reserve" : "Unavailable"}
              </button>
            </article>
          );
        })}
      </div>

      {!rooms.length && !busy ? (
        <div className="empty">No rooms for these dates. Try different dates or sign in.</div>
      ) : null}

      {picked ? (
        <div
          style={{
            position: "fixed",
            inset: 0,
            background: "rgba(26,28,24,0.45)",
            display: "grid",
            placeItems: "center",
            padding: "1rem",
            zIndex: 50,
          }}
        >
          <div className="card stack" style={{ width: "min(420px, 100%)" }}>
            <h2 style={{ margin: 0 }}>
              Confirm · {picked.room_number || picked.room_type}
            </h2>
            <p className="muted">
              {checkIn} → {checkOut} · {nights} nights
            </p>
            {!user ? (
              <>
                <p>Sign in to place this reservation on your account.</p>
                <Link className="btn btn-primary" to="/login" style={{ textAlign: "center", textDecoration: "none" }}>
                  Sign in
                </Link>
              </>
            ) : (
              <>
                <label>
                  Guest name
                  <input value={guestName} onChange={(e) => setGuestName(e.target.value)} required />
                </label>
                <button className="btn btn-primary" type="button" disabled={busy} onClick={confirmBook}>
                  Confirm booking
                </button>
              </>
            )}
            <button type="button" className="btn btn-ghost" onClick={() => setPicked(null)}>
              Close
            </button>
          </div>
        </div>
      ) : null}
    </>
  );
}
