import { useState } from "react";
import { Link, Navigate, useNavigate } from "react-router-dom";
import { useAuth } from "../lib/auth.jsx";

export default function Login() {
  const { user, loading, login, register, isStaff } = useAuth();
  const navigate = useNavigate();
  const [mode, setMode] = useState("login");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [asStaff, setAsStaff] = useState(false);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  if (!loading && user) {
    return <Navigate to={isStaff ? "/desk" : "/"} replace />;
  }

  async function onSubmit(e) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      if (mode === "register") {
        await register(name, email, password);
      } else {
        await login(email, password, asStaff ? "admin" : "user");
      }
      navigate(asStaff ? "/desk" : "/");
    } catch (err) {
      setError(err.message || "Could not sign in.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="auth-page">
      <form className="auth-card stack" onSubmit={onSubmit}>
        <div className="eyebrow" style={{ color: "var(--brass)", fontSize: "0.75rem", letterSpacing: "0.12em" }}>
          HEYWOOD&apos;S HOTEL
        </div>
        <h1>{mode === "login" ? "Sign in" : "Create account"}</h1>
        <p className="muted">Same accounts as the website. Phase 2 app UI over your PHP API.</p>

        {mode === "register" ? (
          <label>
            Full name
            <input value={name} onChange={(e) => setName(e.target.value)} required minLength={2} />
          </label>
        ) : null}

        <label>
          Email
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoComplete="username" />
        </label>
        <label>
          Password
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            minLength={6}
            autoComplete={mode === "login" ? "current-password" : "new-password"}
          />
        </label>

        {mode === "login" ? (
          <label style={{ flexDirection: "row", alignItems: "center", gap: "0.5rem" }}>
            <input type="checkbox" checked={asStaff} onChange={(e) => setAsStaff(e.target.checked)} />
            Staff / front desk login
          </label>
        ) : null}

        {error ? <p className="error">{error}</p> : null}

        <button className="btn btn-primary" type="submit" disabled={busy}>
          {busy ? "Please wait…" : mode === "login" ? "Sign in" : "Register"}
        </button>

        <button
          type="button"
          className="btn btn-ghost"
          onClick={() => {
            setMode(mode === "login" ? "register" : "login");
            setError("");
          }}
        >
          {mode === "login" ? "Need an account? Register" : "Already registered? Sign in"}
        </button>

        <Link to="/" className="muted" style={{ textAlign: "center" }}>
          Back to rooms
        </Link>
      </form>
    </div>
  );
}
