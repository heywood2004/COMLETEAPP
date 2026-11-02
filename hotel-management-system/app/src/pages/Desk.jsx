import { useCallback, useEffect, useState } from "react";
import { Navigate } from "react-router-dom";
import { apiFetch, kes, unwrap } from "../lib/api";
import { useAuth } from "../lib/auth.jsx";

export default function Desk() {
  const { user, loading, isStaff } = useAuth();
  const [board, setBoard] = useState(null);
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setError("");
    try {
      // Prefer dashboard summary if present
      const body = await apiFetch("dashboard.php");
      const data = unwrap(body) || {};
      setBoard(data);
    } catch (err) {
      try {
        const b = await apiFetch("bookings.php?scope=active");
        const data = unwrap(b) || {};
        const list = Array.isArray(data.bookings) ? data.bookings : [];
        setBoard({
          bookings: list,
          stats: {
            active_bookings: list.length,
            checked_in: list.filter((x) => x.stay_status === "checked_in").length,
          },
        });
      } catch (e2) {
        setError(err.message || e2.message || "Desk data unavailable.");
      }
    }
  }, []);

  useEffect(() => {
    if (isStaff) load();
  }, [isStaff, load]);

  if (loading) return <p className="muted">Opening desk…</p>;
  if (!user) return <Navigate to="/login" replace />;
  if (!isStaff) {
    return (
      <div className="card">
        <h1>Front desk</h1>
        <p className="muted">Sign in with a staff account (admin / receptionist / manager) to use the desk.</p>
      </div>
    );
  }

  const stats = board?.stats || board || {};
  const list = board?.bookings || board?.recent_bookings || [];

  async function move(id, action) {
    try {
      await apiFetch(action === "in" ? "checkin.php" : "checkout.php", {
        method: "POST",
        body: JSON.stringify({ booking_id: id }),
      });
      await load();
    } catch (err) {
      alert(err.message || "Action failed");
    }
  }

  return (
    <>
      <h1 style={{ color: "var(--olive)", marginTop: 0 }}>Front desk</h1>
      <p className="muted">Staff tools over the same PHP APIs as admin.html.</p>
      {error ? <p className="error">{error}</p> : null}

      <div className="stat-grid">
        <div className="stat">
          <div className="label">Active</div>
          <div className="value">{stats.active_bookings ?? stats.bookings ?? list.length ?? "—"}</div>
        </div>
        <div className="stat">
          <div className="label">In house</div>
          <div className="value">{stats.checked_in ?? stats.in_house ?? "—"}</div>
        </div>
        <div className="stat">
          <div className="label">Rooms</div>
          <div className="value">{stats.rooms ?? stats.total_rooms ?? "—"}</div>
        </div>
      </div>

      <h2>Active stays</h2>
      {!list.length ? (
        <div className="empty">No active bookings on the board.</div>
      ) : (
        list.map((b) => (
          <div key={b.id} className="stay-row" style={{ alignItems: "center", justifyContent: "space-between" }}>
            <div>
              <h3>
                {b.guest_name} · {b.room_number}
              </h3>
              <p className="muted">
                {b.check_in} → {b.check_out}
                {b.total_amount != null ? ` · ${kes(b.total_amount)}` : ""}
              </p>
            </div>
            <div style={{ display: "flex", gap: "0.4rem", flexWrap: "wrap" }}>
              {b.stay_status !== "checked_in" && b.stay_status !== "checked_out" ? (
                <button type="button" className="btn btn-brass" onClick={() => move(b.id, "in")}>
                  Check in
                </button>
              ) : null}
              {b.stay_status === "checked_in" ? (
                <button type="button" className="btn btn-primary" onClick={() => move(b.id, "out")}>
                  Check out
                </button>
              ) : null}
            </div>
          </div>
        ))
      )}
    </>
  );
}
