# Phase 2 – App client

Modern app UI that uses the **same PHP APIs** as the classic website.

## Ready to open (no Node required)

Built SPA (vanilla modules, same features):

```
public/spa/index.html
```

URL on XAMPP:

```
http://localhost/hotel-management-system-complete/public/spa/
```

### Screens

| Screen | APIs |
|--------|------|
| Sign in / register | `login.php`, `register.php`, `session.php`, `logout.php` |
| Rooms + book | `availability.php`, `public_rooms.php`, `book.php` |
| My stays | `bookings.php`, `cancel.php` |
| Front desk | `dashboard.php`, `checkin.php`, `checkout.php` |

## React source (optional, for developers)

```
app/   → Vite + React source (npm run dev / npm run build)
```

```bash
cd app
npm install
npm run dev
```

Build output target: `public/spa` (may overwrite the ready SPA).

## Requirements

1. Apache + MySQL running  
2. Database imported  
3. Classic API working (`public/` login works)  
4. Then open `public/spa/`

Phase 1 (PWA) and Phase 2 (SPA) can both stay installed.
