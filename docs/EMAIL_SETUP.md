# Email Notifications Setup

## What is sent

| Event | Recipient | Template |
|-------|-----------|----------|
| New booking | Guest account email | Booking confirmation |
| Cancel booking | Guest account email | Cancellation notice |
| Register | New user | Welcome message |

Emails are **best-effort**: booking/cancel still succeeds if mail fails.

## Enable

In `api/config.php`:

```php
define('MAIL_ENABLED', true);
define('MAIL_FROM', 'noreply@yourdomain.com');
define('MAIL_FROM_NAME', "Heywood's Hotel");
define('MAIL_REPLY_TO', 'front.desk@yourdomain.com');
```

### Option A – PHP `mail()`

Leave `MAIL_SMTP_HOST` empty. Requires a working sendmail/postfix on the server.

### Option B – SMTP (Gmail, etc.)

```php
define('MAIL_SMTP_HOST', 'smtp.gmail.com');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_USER', 'you@gmail.com');
define('MAIL_SMTP_PASS', 'your-app-password');
define('MAIL_SMTP_SECURE', 'tls');
```

For Gmail, use an [App Password](https://support.google.com/accounts/answer/185833).

## Local development

On XAMPP/WAMP, `mail()` often does nothing. Options:

1. Use SMTP (Mailtrap, Gmail app password, etc.)
2. Or leave `MAIL_ENABLED` false and check `logs/api.log` for "skipped" lines

## Test

1. Set `MAIL_ENABLED` true + valid SMTP or mail server  
2. Register a user with a real inbox  
3. Book a room → check for confirmation email  
4. Cancel → check for cancellation email  

## Files

- `api/mailer.php` – send helpers + HTML templates  
- Hooked from: `book.php`, `cancel.php`, `register.php`
