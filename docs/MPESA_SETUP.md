# M-Pesa STK Push Setup

## 1. Create a Daraja app

1. Go to https://developer.safaricom.co.ke
2. Create an app with **Lipa Na M-Pesa Online** product
3. Copy **Consumer Key** and **Consumer Secret**

## 2. Sandbox test credentials

| Setting | Sandbox value |
|---------|----------------|
| Shortcode | `174379` |
| Passkey | From Daraja → Lipa Na M-Pesa Online → Simulator / docs |
| Test phone | Use numbers from Safaricom sandbox simulator |

## 3. Configure `api/config.php`

```php
define('MPESA_ENV', 'sandbox');
define('MPESA_CONSUMER_KEY', 'your_key');
define('MPESA_CONSUMER_SECRET', 'your_secret');
define('MPESA_SHORTCODE', '174379');
define('MPESA_PASSKEY', 'your_passkey');
define('MPESA_CALLBACK_URL', 'https://YOUR_PUBLIC_HTTPS_DOMAIN/api/mpesa_callback.php');
```

**Important:** The callback URL must be a **public HTTPS** URL.  
Safaricom cannot reach `localhost`. Use:

- ngrok: `ngrok http 8080` → set the https URL + `/api/mpesa_callback.php`
- Or deploy to a live server

## 4. Database

```bash
mysql -u root -p hotel_management_system < database/migration_mpesa.sql
```

## 5. How staff use it

1. Admin → Hotel Operations → Billing & Payments  
2. Select booking with invoice  
3. Enter guest phone (`07XX...`) and amount  
4. Click **Send STK Push**  
5. Guest enters M-Pesa PIN  
6. Callback records payment automatically; UI can also **Check Status**

## 6. Production

1. Go live on Daraja (production app)
2. Set `MPESA_ENV` to `production`
3. Use your Paybill/Till shortcode and live passkey
4. Keep callback on HTTPS only

## Flow

```
Admin clicks STK
  → api/mpesa.php initiates STK
  → Guest phone shows M-Pesa PIN prompt
  → Safaricom POSTs result to mpesa_callback.php
  → On success: payment row + invoice balance updated
```
