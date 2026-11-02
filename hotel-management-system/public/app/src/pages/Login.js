
import React, { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { login, register } from "../auth.js";

export function Login({ setUser }) {
  const navigate = useNavigate();
  const [mode, setMode] = useState("login");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [name, setName] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function onSubmit(e) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      if (mode === "register") {
        await register({ name, email, password, confirm_password: password });
      }
      const user = await login(email, password);
      setUser(user);
      const role = (user?.role || "").toLowerCase();
      navigate(role === "user" || role === "guest" ? "/stays" : "/desk");
    } catch (err) {
      setError(err.message || "Authentication failed.");
    } finally {
      setBusy(false);
    }
  }

  return React.createElement(
    "div",
    { className: "auth-page" },
    React.createElement(
      "form",
      { className: "auth-card stack", onSubmit },
      React.createElement("h1", null, mode === "login" ? "Sign in" : "Create account"),
      React.createElement(
        "p",
        { className: "muted" },
        "Uses the same PHP login API as the website."
      ),
      mode === "register" &&
        React.createElement(
          "label",
          null,
          "Name",
          React.createElement("input", {
            value: name,
            onChange: (e) => setName(e.target.value),
            required: true,
          })
        ),
      React.createElement(
        "label",
        null,
        "Email",
        React.createElement("input", {
          type: "email",
          value: email,
          onChange: (e) => setEmail(e.target.value),
          required: true,
        })
      ),
      React.createElement(
        "label",
        null,
        "Password",
        React.createElement("input", {
          type: "password",
          value: password,
          onChange: (e) => setPassword(e.target.value),
          required: true,
        })
      ),
      error && React.createElement("p", { className: "error" }, error),
      React.createElement(
        "button",
        { type: "submit", className: "btn btn-primary", disabled: busy },
        busy ? "Please wait…" : mode === "login" ? "Sign in" : "Register"
      ),
      React.createElement(
        "button",
        {
          type: "button",
          className: "btn btn-secondary",
          onClick: () => setMode(mode === "login" ? "register" : "login"),
        },
        mode === "login" ? "Need an account?" : "Have an account?"
      ),
      React.createElement(Link, { to: "/" }, "Back to rooms")
    )
  );
}
