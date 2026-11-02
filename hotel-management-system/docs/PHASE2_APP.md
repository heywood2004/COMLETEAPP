# Phase 2 – React SPA client

Location: `public/app/`

## What it is

A React single-page app (ES modules + import map) that talks to the **same PHP APIs** as the classic website.

| Screen | Route (hash) | APIs |
|--------|----------------|------|
| Rooms / search | `#/` | `availability.php`, `public_rooms.php`, `book.php` |
| My stays | `#/stays` | `bookings.php`, `cancel.php` |
| Front desk | `#/desk` | `bookings.php`, `checkin.php`, `checkout.php` |
| Sign in | `#/login` | `login.php`, `register.php`, `session.php` |

## Open it

- From the classic home page: **Open App**
- Direct URL: `.../public/app/` (or `.../public/app/index.html`)

Uses **HashRouter** so it works on XAMPP without rewrite rules.

## Requirements

- Browser with ES modules
- Network access to load React from `esm.sh` (first load)
- Same origin / cookies as the PHP API (`credentials: include`)
- CSRF header sent automatically after session/login

## API base

Default: `../../api/` relative to `public/app/`.

Override in the page if needed:

```html
<script>window.__HOTEL_API__ = "http://localhost/hotel-management-system-complete/api/";</script>
```

## Phase 1 vs Phase 2

- Phase 1: classic HTML + PWA install
- Phase 2: this React client
- Both share one backend

## Later upgrades

- Bundle with Vite for offline React (no CDN)
- Port remaining admin features (housekeeping, M-Pesa, reports)
