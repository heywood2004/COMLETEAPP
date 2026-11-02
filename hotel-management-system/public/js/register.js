"use strict";

document.addEventListener("DOMContentLoaded", function () {
  const form = document.getElementById("registerForm");
  const nameEl = document.getElementById("registerName");
  const emailEl = document.getElementById("registerEmail");
  const passEl = document.getElementById("registerPassword");
  const confirmEl = document.getElementById("registerConfirmPassword");
  const btn = document.getElementById("registerButton");
  const msg = document.getElementById("registerMessage");

  function showMsg(text, type) {
    msg.textContent = text;
    msg.className = "register-message " + type;
  }

  function togglePwd(input, button) {
    if (input.type === "password") {
      input.type = "text";
      button.textContent = "🙈";
    } else {
      input.type = "password";
      button.textContent = "👁";
    }
  }

  document.getElementById("registerPasswordToggle")?.addEventListener("click", () =>
    togglePwd(passEl, document.getElementById("registerPasswordToggle"))
  );
  document.getElementById("registerConfirmPasswordToggle")?.addEventListener("click", () =>
    togglePwd(confirmEl, document.getElementById("registerConfirmPasswordToggle"))
  );

  form?.addEventListener("submit", async (e) => {
    e.preventDefault();
    const name = nameEl.value.trim();
    const email = emailEl.value.trim().toLowerCase();
    const password = passEl.value;
    const confirm = confirmEl.value;

    if (name.length < 2) { showMsg("Name must be at least 2 characters.", "error"); return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showMsg("Please enter a valid email.", "error"); return; }
    if (password.length < 8) { showMsg("Password must be at least 8 characters.", "error"); return; }
    if (password !== confirm) { showMsg("Passwords do not match.", "error"); return; }

    try {
      btn.disabled = true;
      btn.textContent = "Creating Account...";
      const result = await apiFetch("register.php", {
        method: "POST",
        body: JSON.stringify({ name, email, password })
      });
      showMsg(result.message || "Registration successful. Redirecting...", "success");
      form.reset();
      setTimeout(() => { window.location.href = "index.html"; }, 1200);
    } catch (err) {
      showMsg(err.message || "Registration failed.", "error");
    } finally {
      btn.disabled = false;
      btn.textContent = "Create Account";
    }
  });
});
