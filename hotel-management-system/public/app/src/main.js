
import React, { useEffect, useState } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter, HashRouter, Routes, Route, Navigate } from "react-router-dom";
import { Shell } from "./components/Shell.js";
import { Home } from "./pages/Home.js";
import { Stays } from "./pages/Stays.js";
import { Login } from "./pages/Login.js";
import { Desk } from "./pages/Desk.js";
import { loadSession } from "./auth.js";

function App() {
  const [user, setUser] = useState(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    loadSession()
      .then(setUser)
      .catch(() => setUser(null))
      .finally(() => setReady(true));
  }, []);

  if (!ready) {
    return React.createElement("div", { className: "auth-page muted" }, "Loading…");
  }

  // HashRouter works on static XAMPP paths without server rewrite rules
  return React.createElement(
    HashRouter,
    null,
    React.createElement(
      Routes,
      null,
      React.createElement(
        Route,
        {
          path: "/login",
          element: React.createElement(Login, { setUser }),
        }
      ),
      React.createElement(
        Route,
        {
          path: "/*",
          element: React.createElement(
            Shell,
            { user, setUser },
            React.createElement(
              Routes,
              null,
              React.createElement(Route, {
                path: "/",
                element: React.createElement(Home, { user }),
              }),
              React.createElement(Route, {
                path: "/stays",
                element: React.createElement(Stays, { user }),
              }),
              React.createElement(Route, {
                path: "/desk",
                element: React.createElement(Desk, { user }),
              }),
              React.createElement(Route, {
                path: "*",
                element: React.createElement(Navigate, { to: "/", replace: true }),
              })
            )
          ),
        }
      )
    )
  );
}

createRoot(document.getElementById("root")).render(React.createElement(App));
