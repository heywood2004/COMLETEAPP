import { Link, NavLink, Outlet } from "react-router-dom";
import { useAuth } from "../lib/auth.jsx";

export default function Shell() {
  const { user, logout, isStaff } = useAuth();

  return (
    <div className="app-shell">
      <header className="topbar">
        <Link to="/" className="brand">
          Heywood&apos;s Hotel
        </Link>
        <nav>
          <NavLink to="/" end className={({ isActive }) => `nav-link${isActive ? " active" : ""}`}>
            Rooms
          </NavLink>
          <NavLink to="/stays" className={({ isActive }) => `nav-link${isActive ? " active" : ""}`}>
            My stays
          </NavLink>
          {isStaff ? (
            <NavLink to="/desk" className={({ isActive }) => `nav-link${isActive ? " active" : ""}`}>
              Desk
            </NavLink>
          ) : null}
        </nav>
        <div style={{ display: "flex", alignItems: "center", gap: "0.5rem" }}>
          {user ? (
            <>
              <span className="badge">{user.name?.split(" ")[0] || "Guest"}</span>
              <button type="button" className="btn btn-ghost" onClick={() => logout()}>
                Log out
              </button>
            </>
          ) : (
            <Link to="/login" className="btn btn-primary" style={{ textDecoration: "none" }}>
              Sign in
            </Link>
          )}
        </div>
      </header>

      <main className="main">
        <Outlet />
      </main>

      <nav className="bottom-nav">
        <NavLink to="/" end className={({ isActive }) => (isActive ? "active" : "")}>
          <span>⌂</span>
          Rooms
        </NavLink>
        <NavLink to="/stays" className={({ isActive }) => (isActive ? "active" : "")}>
          <span>☰</span>
          Stays
        </NavLink>
        <NavLink
          to={isStaff ? "/desk" : "/login"}
          className={({ isActive }) => (isActive ? "active" : "")}
        >
          <span>✦</span>
          {isStaff ? "Desk" : "Account"}
        </NavLink>
      </nav>
    </div>
  );
}
