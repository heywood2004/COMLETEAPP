# Progressive Web App (Phase 1)

The public site can be installed on phones and desktops.

## What was added

- `public/manifest.webmanifest` – name, icons, standalone display, shortcuts
- `public/sw.js` – caches the app shell; API calls always go to the network
- `public/js/pwa.js` – registers the service worker and shows an Install bar when the browser allows it
- `public/icons/` – 192 / 512 icons (any + maskable)
- Meta tags on `index.html`, `user.html`, `admin.html`, `register.html`

## How to install

1. Serve over **HTTPS** (or `localhost` / `127.0.0.1`).
2. Open the guest or staff site in Chrome / Edge / Android browser.
3. Use **Install** when prompted, or browser menu → **Install app** / **Add to Home Screen**.
4. iPhone (Safari): Share → **Add to Home Screen**.

## Notes

- Bookings and payments still need a network connection.
- Cached pages may open offline; actions that call `/api/` will fail until online.
- After changing `sw.js`, bump `CACHE` version so clients refresh.

## Phase 2 (later)

A separate React (or similar) client can call the same PHP APIs while this PWA stays available.
