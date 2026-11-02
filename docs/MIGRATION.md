# Migration Notes – Clean Project Structure

## What Was Done

A clean multi-page structure was created:

```
hotel-management-system/
├── public/
│   ├── index.html          ← Login / landing (from original combined page)
│   ├── register.html
│   ├── user.html
│   ├── admin.html
│   ├── css/style.css
│   ├── js/
│   │   ├── utils.js        ← NEW shared helpers
│   │   ├── register.js
│   │   ├── user.js
│   │   └── admin.js
│   └── images/
├── api/                    ← PHP endpoints go here
├── database/
├── uploads/media/
└── docs/
```

## Recommended File Placement

| Original File              | New Location                          |
|----------------------------|---------------------------------------|
| Main index HTML            | `public/index.html`                   |
| Admin Dashboard HTML       | `public/admin.html`                   |
| User Dashboard HTML        | `public/user.html`                    |
| Register HTML              | `public/register.html`                |
| style.css                  | `public/css/style.css`                |
| register.js                | `public/js/register.js`               |
| user.js                    | `public/js/user.js`                   |
| admin.js                   | `public/js/admin.js`                  |
| script.js (combined)       | Keep as reference → port features into admin.js |

## Immediate Next Actions

1. **Copy the HTML files** into `public/` and update asset paths:
   - CSS → `css/style.css`
   - JS → `js/utils.js` + page-specific JS
   - Images → `images/...`

2. **Update script tags** in every HTML:
   ```html
   <script src="js/utils.js"></script>
   <script src="js/user.js"></script>   <!-- or admin.js / register.js -->
   ```

3. **Port Hotel Operations** from the old `script.js` into `admin.js`
   (Guests, Check-in/out, Billing, Media Manager).

4. **Fix the invoice modal** in `user.html` — move it inside `<body>`.

5. **Create `database/schema.sql`** and the PHP API files.

## Shared Utilities (utils.js)

Already extracted:
- `apiFetch`
- `unwrapData`
- `escapeHtml` / `escapeHTML`
- `formatMoney`
- `getToday` / `setMinimumDates`
- `setText` / `getValue` / `setValue`
- `setLoading`
- `showMessage`
- `isRoomAvailable`

All page scripts should import / rely on these instead of re-implementing them.

## Path Conventions

- Frontend pages live in `public/`
- API is reached via `../api/` from JS (or configure a reverse proxy)
- Uploaded media stored in `uploads/media/` and served as `/uploads/media/...`
