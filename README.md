# Heywood's Hotel – Management System

Fully functional hotel reservation and operations system.

## Quick Start

### 1. Database

```bash
mysql -u root -p < database/schema.sql
```

Default admin:
- **Email:** `admin@hotel.com`
- **Password:** `Admin@12345`

### 2. Configure

Edit `api/config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'hotel_management_system');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');
```

### 3. Run with PHP built-in server (easiest for testing)

From the project root:

```bash
cd hotel-management-system
php -S localhost:8080 -t public
```

Then open: **http://localhost:8080**

> Note: With the built-in server, API is reached via `../api/` from public pages.
> Make sure `api/` sits next to `public/` (as in this structure).

### 4. Apache / XAMPP / WAMP

1. Copy the whole `hotel-management-system` folder into `htdocs` (or your web root).
2. Point the browser to:  
   `http://localhost/hotel-management-system/public/`
3. Ensure `mod_rewrite` is enabled if using the root `index.php` front controller.

Alternatively set the document root to the `public/` folder and keep `api/` one level above.

## Features

### User
- Register / Login
- Browse & search rooms (with live availability)
- Book a room
- View / cancel own bookings
- View & print invoice

### Admin
- Login as administrator
- Dashboard stats (total / available / booked rooms)
- Add / Edit / Delete rooms
- User management (change role, delete)
- View all active bookings
- Cancel any booking

### Backend APIs (ready)
- Guests, Check-in, Check-out, Payments, Media upload
  (UI for these can be extended from the existing endpoints)

## Project Structure

```
hotel-management-system/
├── api/                 # PHP endpoints
├── public/              # Frontend (HTML, CSS, JS, images)
│   ├── index.html       # Login
│   ├── register.html
│   ├── user.html
│   ├── admin.html
│   ├── css/style.css
│   ├── js/
│   └── images/
├── database/schema.sql
├── uploads/
└── index.php            # Optional front controller
```

## Bugs Fixed in This Build

1. **Duplicate `requireLogin` / `requireAdmin`** – removed from `config.php`, kept only in `auth.php`
2. **Session inconsistency** – login now sets both `$_SESSION['user']` and `$_SESSION['user_id']`
3. **`invoice.php` used mysqli** – fully rewritten to PDO
4. **Broken require paths** – all API files now use `__DIR__ . '/config.php'`
5. **Frontend missing** – complete multi-page UI added and wired to the APIs
6. **Password min length** – registration enforces 8 characters (matches API)

## Default Credentials

| Role  | Email            | Password     |
|-------|------------------|--------------|
| Admin | admin@hotel.com  | Admin@12345  |

Create normal users via the Register page.

## Security Notes

- Passwords are bcrypt-hashed
- Session cookies are HttpOnly
- All dynamic output is escaped on the frontend
- Server validates roles on every protected endpoint
- Overlapping bookings are blocked with row locks (`FOR UPDATE`)

## Next Extensions (optional)

- Full Hotel Operations UI (guests, check-in/out, billing panels)
- Media manager UI
- CSRF tokens
- Email notifications
