# Staff Roles

| Role | Access |
|------|--------|
| **admin** | Full access |
| **manager** | Full operations (same as admin matrix) |
| **receptionist** | Bookings, walk-in, check-in/out, guests, invoices, housekeeping, night audit |
| **accountant** | Payments, M-Pesa, invoices, reports, night audit |
| **user** | Guest portal only |

## Assign a role

Update in admin **User Management**, or SQL:

```sql
UPDATE users SET role = 'receptionist' WHERE email = 'desk@hotel.com';
```

## Login

Choose **Staff / Admin** on the login screen for any staff role.

## Night audit

Admin dashboard → **Night Audit** → pick business date → **Run Audit**.
