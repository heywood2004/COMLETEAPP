# Production Security

## Features enabled

| Feature | Detail |
|---------|--------|
| CSRF | `X-CSRF-Token` header required on POST/PUT/DELETE after login |
| Login rate limit | 5 attempts / 15 minutes per IP+email |
| Session cookies | HttpOnly, SameSite=Lax, Secure when HTTPS |
| Session regenerate | On successful login |
| Security headers | nosniff, SAMEORIGIN, XSS protection |
| APP_DEBUG | Set `false` in production to hide internal errors |

## Production checklist

In `api/config.php`:

```php
define('APP_DEBUG', false);
define('CSRF_ENABLED', true);
```

- Serve over **HTTPS**
- Change default admin password
- Configure real DB credentials
- Set M-Pesa / SMS / Mail credentials only on server (not in public repos)

## Guest M-Pesa

Logged-in guests can pay invoices for **their own bookings** via STK Push from the guest portal when an invoice has a balance (typically after check-in).
