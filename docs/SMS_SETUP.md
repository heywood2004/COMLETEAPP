# SMS Notifications Setup

## Events

| Event | Message |
|-------|---------|
| Booking created | Confirmation with room + dates |
| Booking cancelled | Cancellation notice |
| Check-in | Welcome / room number |

## Enable (Africa's Talking – recommended in Kenya)

1. Sign up: https://africastalking.com  
2. Create an app, copy **API Key** and **username**  
3. Sandbox: use username `sandbox` and test numbers from the AT dashboard  

In `api/config.php`:

```php
define('SMS_ENABLED', true);
define('SMS_PROVIDER', 'africastalking');
define('SMS_AT_USERNAME', 'sandbox');      // or your live username
define('SMS_AT_API_KEY', 'your_api_key');
define('SMS_AT_SANDBOX', true);            // false in production
define('SMS_SENDER_ID', '');               // optional approved sender
```

## Twilio

```php
define('SMS_PROVIDER', 'twilio');
define('SMS_TWILIO_SID', 'ACxxxx');
define('SMS_TWILIO_TOKEN', 'xxxx');
define('SMS_SENDER_ID', '+1234567890');  // Twilio number
```

## Phone numbers

- Guest can enter mobile on the booking form  
- Or store `users.phone` (run `database/migration_sms.sql`)  
- Check-in uses `guests.phone` when available  

Format accepted: `07XXXXXXXX`, `7XXXXXXXX`, `2547XXXXXXXX`

## Database

```bash
mysql -u root -p hotel_management_system < database/migration_sms.sql
```

## Notes

- SMS is best-effort (API still succeeds if SMS fails)  
- Logs: `logs/api.log`  
- Default `SMS_ENABLED = false` until configured  
