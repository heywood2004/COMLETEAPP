/* Heywood's Hotel – service worker (cache static shell; network for API) */
const CACHE = "heywood-hotel-v1";
const PRECACHE = [
  "./",
  "./index.html",
  "./user.html",
  "./admin.html",
  "./register.html",
  "./css/style.css",
  "./js/utils.js",
  "./js/user.js",
  "./js/admin.js",
  "./js/register.js",
  "./js/pwa.js",
  "./manifest.webmanifest",
  "./icons/icon-192.png",
  "./icons/icon-512.png"
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  const req = event.request;
  if (req.method !== "GET") return;

  const url = new URL(req.url);

  if (url.pathname.includes("/api/") || url.pathname.endsWith(".php")) {
    event.respondWith(
      fetch(req).catch(() =>
        new Response(JSON.stringify({ success: false, message: "You are offline. Try again when connected." }), {
          status: 503,
          headers: { "Content-Type": "application/json" }
        })
      )
    );
    return;
  }

  event.respondWith(
    caches.match(req).then((cached) => {
      const network = fetch(req)
        .then((res) => {
          if (res && res.ok && res.type === "basic") {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
          }
          return res;
        })
        .catch(() => cached);
      return cached || network;
    })
  );
});
