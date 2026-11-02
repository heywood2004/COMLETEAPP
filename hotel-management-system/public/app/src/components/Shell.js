
import React from "react";
import { Link, NavLink, useNavigate } from "react-router-dom";
import { logout } from "../auth.js";

export function Shell({ user, setUser, children }) {
  const navigate = useNavigate();
  async function onLogout() {
    await logout();
    setUser(null);
    navigate("/login");
  }
  return React.createElement(
    "div",
    { className: "app-shell" },
    React.createElement(
      "header",
      { className: "topbar" },
      React.createElement(Link, { to: "/", className: "brand" }, "Heywood's Hotel"),
      React.createElement(
        "nav",
        { className: "nav-desktop" },
        React.createElement(NavLink, { to: "/", end: true }, "Rooms"),
        React.createElement(NavLink, { to: "/stays" }, "Stays"),
        React.createElement(NavLink, { to: "/desk" }, "Desk")
      ),
      user
        ? React.createElement(
            "button",
            { type: "button", className: "btn btn-secondary", onClick: onLogout },
            "Logout"
          )
        : React.createElement(Link, { to: "/login", className: "btn btn-primary" }, "Sign in")
    ),
    React.createElement("main", { className: "main" }, children),
    React.createElement(
      "nav",
      { className: "tabbar" },
      React.createElement(NavLink, { to: "/", end: true }, "Rooms"),
      React.createElement(NavLink, { to: "/stays" }, "Stays"),
      React.createElement(NavLink, { to: "/desk" }, "Desk")
    )
  );
}
