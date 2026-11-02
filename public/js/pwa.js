/**
 * PWA bootstrap: register service worker + optional install prompt
 */
(function () {
  "use strict";

  if (!("serviceWorker" in navigator)) return;

  const isStandalone =
    window.matchMedia("(display-mode: standalone)").matches ||
    window.navigator.standalone === true;

  window.addEventListener("load", function () {
    navigator.serviceWorker.register("./sw.js", { scope: "./" }).catch(function () {});
  });

  let deferredPrompt = null;

  window.addEventListener("beforeinstallprompt", function (e) {
    e.preventDefault();
    deferredPrompt = e;
    if (isStandalone) return;
    showInstallBar();
  });

  function showInstallBar() {
    if (document.getElementById("pwaInstallBar")) return;
    if (sessionStorage.getItem("pwaInstallDismissed") === "1") return;

    const bar = document.createElement("div");
    bar.id = "pwaInstallBar";
    bar.className = "pwa-install-bar";
    bar.innerHTML =
      '<div class="pwa-install-text">' +
      "<strong>Install Heywood's Hotel</strong>" +
      "<span>Add to your home screen for faster access.</span>" +
      "</div>" +
      '<div class="pwa-install-actions">' +
      '<button type="button" class="btn btn-primary" id="pwaInstallBtn">Install</button>' +
      '<button type="button" class="btn btn-secondary" id="pwaInstallDismiss">Not now</button>' +
      "</div>";
    document.body.appendChild(bar);

    document.getElementById("pwaInstallBtn").addEventListener("click", function () {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () {
        deferredPrompt = null;
        bar.remove();
      });
    });

    document.getElementById("pwaInstallDismiss").addEventListener("click", function () {
      sessionStorage.setItem("pwaInstallDismissed", "1");
      bar.remove();
    });
  }
})();
