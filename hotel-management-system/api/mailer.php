<?php
/**
 * ==========================================
 * Hotel Management System – Email helper
 * ==========================================
 * Uses PHP mail() by default. Optional SMTP via config.
 */

declare(strict_types=1);

/**
 * Send an HTML email. Returns true on success.
 */
function sendHotelMail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        apiLog('warning', 'sendHotelMail: invalid recipient', ['to' => $to]);
        return false;
    }

    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        apiLog('info', 'sendHotelMail: skipped (MAIL_ENABLED=false)', ['to' => $to, 'subject' => $subject]);
        return false;
    }

    $fromEmail = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@hotel.local';
    $fromName  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : (defined('APP_NAME') ? APP_NAME : 'Hotel');
    $replyTo   = defined('MAIL_REPLY_TO') ? MAIL_REPLY_TO : $fromEmail;

    if ($textBody === '') {
        $textBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));
    }

    $boundary = 'b_' . md5((string) microtime(true));

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: ' . encodeMailName($fromName) . " <{$fromEmail}>";
    $headers[] = "Reply-To: {$replyTo}";
    $headers[] = 'X-Mailer: HeywoodsHotel/1.0';
    $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $textBody . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= "--{$boundary}--";

    // Optional SMTP via PHP stream (simple; for production prefer PHPMailer)
    if (defined('MAIL_SMTP_HOST') && MAIL_SMTP_HOST !== '') {
        return sendMailSmtp($to, $subject, $body, $headers, $fromEmail);
    }

    $ok = @mail($to, encodeMailSubject($subject), $body, implode("\r\n", $headers));
    if (!$ok) {
        apiLog('error', 'sendHotelMail: mail() failed', ['to' => $to, 'subject' => $subject]);
    } else {
        apiLog('info', 'sendHotelMail: sent', ['to' => $to, 'subject' => $subject]);
    }
    return (bool) $ok;
}

function encodeMailName(string $name): string
{
    return '=?UTF-8?B?' . base64_encode($name) . '?=';
}

function encodeMailSubject(string $subject): string
{
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

/**
 * Minimal SMTP sender (AUTH LOGIN). Not a full PHPMailer replacement.
 */
function sendMailSmtp(string $to, string $subject, string $body, array $headers, string $fromEmail): bool
{
    $host = MAIL_SMTP_HOST;
    $port = defined('MAIL_SMTP_PORT') ? (int) MAIL_SMTP_PORT : 587;
    $user = defined('MAIL_SMTP_USER') ? MAIL_SMTP_USER : '';
    $pass = defined('MAIL_SMTP_PASS') ? MAIL_SMTP_PASS : '';
    $secure = defined('MAIL_SMTP_SECURE') ? MAIL_SMTP_SECURE : 'tls'; // tls | ssl | ''

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host;
    $fp = @fsockopen($remote, $port, $errno, $errstr, 20);
    if (!$fp) {
        apiLog('error', 'SMTP connect failed', ['error' => $errstr, 'errno' => $errno]);
        return false;
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function (string $cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };

    try {
        $read();
        $write('EHLO localhost');
        $read();

        if ($secure === 'tls') {
            $write('STARTTLS');
            $read();
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception('STARTTLS failed');
            }
            $write('EHLO localhost');
            $read();
        }

        if ($user !== '') {
            $write('AUTH LOGIN');
            $read();
            $write(base64_encode($user));
            $read();
            $write(base64_encode($pass));
            $auth = $read();
            if (strpos($auth, '235') === false) {
                throw new Exception('SMTP auth failed: ' . trim($auth));
            }
        }

        $write("MAIL FROM:<{$fromEmail}>");
        $read();
        $write("RCPT TO:<{$to}>");
        $read();
        $write('DATA');
        $read();

        $msg  = 'Subject: ' . encodeMailSubject($subject) . "\r\n";
        $msg .= implode("\r\n", $headers) . "\r\n";
        $msg .= "\r\n" . $body . "\r\n.";
        $write($msg);
        $read();
        $write('QUIT');
        fclose($fp);
        apiLog('info', 'SMTP mail sent', ['to' => $to, 'subject' => $subject]);
        return true;
    } catch (Throwable $e) {
        apiLog('error', 'SMTP send failed', ['error' => $e->getMessage()]);
        if (is_resource($fp)) fclose($fp);
        return false;
    }
}

/**
 * Shared HTML email layout
 */
function mailLayout(string $title, string $contentHtml): string
{
    $app = defined('APP_NAME') ? APP_NAME : "Heywood's Hotel";
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>{$title}</title></head>
<body style="margin:0;padding:0;background:#eef1f5;font-family:Arial,Helvetica,sans-serif;color:#2c3e50;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f5;padding:24px 12px;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.08);">
        <tr>
          <td style="background:linear-gradient(135deg,#243746,#2c3e50);color:#fff;padding:22px 28px;">
            <div style="font-size:22px;font-weight:bold;">🏨 {$app}</div>
            <div style="font-size:13px;opacity:.85;margin-top:4px;">{$title}</div>
          </td>
        </tr>
        <tr>
          <td style="padding:28px;font-size:15px;line-height:1.6;">
            {$contentHtml}
          </td>
        </tr>
        <tr>
          <td style="padding:16px 28px;background:#f8fafc;font-size:12px;color:#718096;text-align:center;">
            &copy; {$year} {$app}. This is an automated message.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Booking confirmation email
 */
function sendBookingConfirmationEmail(array $booking, ?string $userEmail = null): bool
{
    $email = $userEmail ?: ($booking['email'] ?? '');
    if ($email === '') return false;

    $guest = htmlspecialchars($booking['guest_name'] ?? 'Guest', ENT_QUOTES, 'UTF-8');
    $id = (int) ($booking['id'] ?? $booking['booking_id'] ?? 0);
    $room = htmlspecialchars(($booking['room_number'] ?? '') . ' (' . ($booking['room_type'] ?? '') . ')', ENT_QUOTES, 'UTF-8');
    $checkIn = htmlspecialchars($booking['check_in'] ?? '', ENT_QUOTES, 'UTF-8');
    $checkOut = htmlspecialchars($booking['check_out'] ?? '', ENT_QUOTES, 'UTF-8');
    $price = number_format((float) ($booking['price'] ?? 0), 2);

    $content = "
      <p>Dear <strong>{$guest}</strong>,</p>
      <p>Your reservation at " . (defined('APP_NAME') ? APP_NAME : "Heywood's Hotel") . " is <strong>confirmed</strong>.</p>
      <table cellpadding=\"8\" cellspacing=\"0\" style=\"width:100%;border-collapse:collapse;margin:16px 0;background:#f8fafc;border-radius:8px;\">
        <tr><td style=\"color:#718096;\">Booking ID</td><td><strong>#{$id}</strong></td></tr>
        <tr><td style=\"color:#718096;\">Room</td><td><strong>{$room}</strong></td></tr>
        <tr><td style=\"color:#718096;\">Check-in</td><td><strong>{$checkIn}</strong></td></tr>
        <tr><td style=\"color:#718096;\">Check-out</td><td><strong>{$checkOut}</strong></td></tr>
        <tr><td style=\"color:#718096;\">Rate</td><td><strong>KSh {$price}</strong> / night</td></tr>
      </table>
      <p>Please arrive with a valid ID. For changes, sign in to your guest portal or contact the front desk.</p>
      <p>We look forward to welcoming you.</p>
    ";

    $html = mailLayout('Booking confirmation', $content);
    $subject = 'Booking confirmed #' . $id . ' – ' . (defined('APP_NAME') ? APP_NAME : 'Hotel');
    return sendHotelMail($email, $subject, $html);
}

/**
 * Booking cancellation email
 */
function sendBookingCancellationEmail(array $booking, ?string $userEmail = null): bool
{
    $email = $userEmail ?: ($booking['email'] ?? '');
    if ($email === '') return false;

    $guest = htmlspecialchars($booking['guest_name'] ?? 'Guest', ENT_QUOTES, 'UTF-8');
    $id = (int) ($booking['id'] ?? $booking['booking_id'] ?? 0);
    $room = htmlspecialchars(($booking['room_number'] ?? '') . ' (' . ($booking['room_type'] ?? '') . ')', ENT_QUOTES, 'UTF-8');
    $checkIn = htmlspecialchars($booking['check_in'] ?? '', ENT_QUOTES, 'UTF-8');
    $checkOut = htmlspecialchars($booking['check_out'] ?? '', ENT_QUOTES, 'UTF-8');

    $content = "
      <p>Dear <strong>{$guest}</strong>,</p>
      <p>Your reservation <strong>#{$id}</strong> has been <strong>cancelled</strong>.</p>
      <table cellpadding=\"8\" cellspacing=\"0\" style=\"width:100%;border-collapse:collapse;margin:16px 0;background:#f8fafc;border-radius:8px;\">
        <tr><td style=\"color:#718096;\">Room</td><td><strong>{$room}</strong></td></tr>
        <tr><td style=\"color:#718096;\">Was check-in</td><td><strong>{$checkIn}</strong></td></tr>
        <tr><td style=\"color:#718096;\">Was check-out</td><td><strong>{$checkOut}</strong></td></tr>
      </table>
      <p>If this was a mistake, please sign in and make a new booking, or contact the hotel.</p>
    ";

    $html = mailLayout('Booking cancelled', $content);
    $subject = 'Booking cancelled #' . $id . ' – ' . (defined('APP_NAME') ? APP_NAME : 'Hotel');
    return sendHotelMail($email, $subject, $html);
}

/**
 * Registration welcome email
 */
function sendWelcomeEmail(string $name, string $email): bool
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $app = defined('APP_NAME') ? APP_NAME : "Heywood's Hotel";
    $content = "
      <p>Dear <strong>{$safeName}</strong>,</p>
      <p>Welcome to <strong>{$app}</strong>! Your guest account is ready.</p>
      <p>You can now browse rooms, reserve stays, and manage your bookings online.</p>
      <p>If you did not create this account, please ignore this message.</p>
    ";
    $html = mailLayout('Welcome', $content);
    return sendHotelMail($email, "Welcome to {$app}", $html);
}
