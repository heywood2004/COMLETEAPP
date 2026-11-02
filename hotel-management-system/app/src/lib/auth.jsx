import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { apiFetch, setCsrfToken, unwrap } from "./api";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    try {
      const body = await apiFetch("session.php");
      const data = unwrap(body) || {};
      if (data.csrf_token) setCsrfToken(data.csrf_token);
      if (data.authenticated && data.user) {
        setUser(data.user);
      } else {
        setUser(null);
      }
    } catch {
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  const login = useCallback(async (email, password, loginAs = "user") => {
    const body = await apiFetch("login.php", {
      method: "POST",
      body: JSON.stringify({ email, password, login_as: loginAs }),
    });
    const data = unwrap(body) || {};
    if (data.csrf_token) setCsrfToken(data.csrf_token);
    if (data.user) setUser(data.user);
    else await refresh();
    return data;
  }, [refresh]);

  const register = useCallback(async (name, email, password) => {
    await apiFetch("register.php", {
      method: "POST",
      body: JSON.stringify({ name, email, password }),
    });
    return login(email, password, "user");
  }, [login]);

  const logout = useCallback(async () => {
    try {
      await apiFetch("logout.php", { method: "POST", body: "{}" });
    } catch {
      /* ignore */
    }
    setUser(null);
  }, []);

  const isStaff = useMemo(
    () => user && ["admin", "manager", "receptionist", "accountant"].includes(user.role),
    [user]
  );

  const value = useMemo(
    () => ({ user, loading, login, register, logout, refresh, isStaff }),
    [user, loading, login, register, logout, refresh, isStaff]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth outside AuthProvider");
  return ctx;
}
