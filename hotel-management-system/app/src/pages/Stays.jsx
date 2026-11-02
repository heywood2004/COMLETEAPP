import { useCallback, useEffect, useState } from "react";
import { Link, Navigate } from "react-router-dom";
import { apiFetch, kes, unwrap } from "../lib/api";
import { useAuth } from "../lib/auth.jsx";

function statusLabel(b) {
  if (b.status === "cancelled") return "Cancelled";
  if (b.stay_status === "checked_in") return "In house";
  if (b.stay_status === "checked_out") return "Checked out";
  return "Reserved";
}

export default function Stays() {
  const { user, loading } = useAuth();
  const [active, setActive] = useState([]);
  const [history, setHistory] = useState([]);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setBusy(true);
    setError("");
    try {
      const [a, h] = await Promise.all([
        apiFetch("bookings.php?scope=active"),
        apiFetch("bookings.php?scope=history"),
      ]);
      const ad = unwrap(a) || {};
      const hd = unwrap(h) || {};
      setActive(Array.isArray(ad.bookings) ? ad.bookings : Array.isArray(ad) ? ad : []);
      setHistory(Array.isArray(hd.bookings) ? hd.bookings : Array.isArray(hd) ? hd : []);
    } catch (err) {
      setError(err.message || "Could not load stays.");
    } finally {
      setBusy(false);
    }
  }, []);

  useEffect(() => {
    if (user) load();
  }, [user, load]);

  if (loading) return <p className="muted">Loading…</p>;
  if (!user) return <Navigate to="/login" replace />;

  async function cancel(id) {
    if (!confirm("Cancel this reservation?")) return;
    try {
      await apiFetch("cancel.php", {
        method: "POST",
        body: JSON.stringify({ booking_id: id }),
      });
      await load();
    } catch (err) {
      alert(err.message || "Cancel failed");
    }
  }

  return (
    <>
      <h1 style={{ color: "var(--olive)", marginTop: 0 }}>My stays</h1>
      <p className="muted">Active and past bookings from the same database as the website.</p>
      {error ? <p className="error">{error}</p> : null}
      {busy ? <p className="muted">Refreshing…</p> : null}

      <h2>Coming up</h2>
      {!active.length ? (
        <div className="empty">
          No active bookings. <Link to="/">Find a room</Link>
        </div>
      ) : (
        active.map((b) => (
          <div key={b.id} className="stay-row">
            <div style={{ flex: 1 }}>
              <h3>
                Room {b.room_number} · {b.room_type}
              </h3>
              <p className="muted">
                {b.guest_name} · {b.check_in} → {b.check_out}
              </p>
              <p>
                {statusLabel(b)}
                {b.total_amount != null ? ` · ${kes(b.total_amount)}` : ""}
              </p>
              {(b.status === "active" || b.status === "reserved") &&
              b.stay_status !== "checked_in" &&
              b.stay_status !== "checked_out" ? (
                <button type="button" className="btn btn-danger" onClick={() => cancel(b.id)}>
                  Cancel
                </button>
              ) : null}
            </div>
          </div>
        ))
      )}

      <h2>History</h2>
      {!history.length ? (
        <div className="empty">Cancelled and completed stays appear here.</div>
      ) : (
        history.map((b) => (
          <div key={b.id} className="stay-row">
            <div>
              <h3>
                Room {b.room_number} · {b.room_type}
              </h3>
              <p className="muted">
                {b.check_in} → {b.check_out} · {statusLabel(b)}
              </p>
            </div>
          </div>
        ))
      )}
    </>
  );
}
