# Phase 2 – React app (SPA)

Modern guest/staff shell that calls the **same PHP APIs** as the classic site and PWA.

## Location

```
hotel-management-system/
  app/                 ← Vite + React + TypeScript source
  public/app/          ← production build (after npm run build)
```

## URLs (XAMPP)

| Client | URL |
|--------|-----|
| Classic + PWA home | `/hotel-management-system-complete/public/` |
| React app | `/hotel-management-system-complete/public/app/` |
| API | `/hotel-management-system-complete/api/` |

The React build uses **HashRouter** so routes work without Apache rewrite rules:
`.../public/app/#/stays`

## Develop

```bash
cd app
npm install
npm run dev
```

Optional: set proxy target in `vite.config.ts` to your local PHP path.

Or point the UI at PHP directly:

```bash
VITE_API_BASE=http://localhost/hotel-management-system-complete/api npm run dev
```

(Browsers may block cross-origin cookies unless API sends CORS + credentials — prefer same-origin deploy.)

## Build for XAMPP

```bash
cd app
npm install
npm run build
```

Output: `public/app/` (index.html + assets). API base defaults to `../../api`.

## Features in this phase

- Sign in / register (session + CSRF via existing APIs)
- Date-range availability search
- Book a room
- My stays + cancel
- Desk entry for staff (links to full `admin.html` for night audit, inventory, etc.)

## Roadmap after Phase 2

- Port more desk tools into React
- Share one design system with the PWA
- Optional: Capacitor/React Native wrapper for store builds
