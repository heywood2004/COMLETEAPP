<?php
/**
 * Printable / PDF-ready invoice for a booking
 * GET ?booking_id=123
 * Opens as HTML optimized for browser "Save as PDF" / Print
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/billing_rules.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain');
    echo 'Method not allowed';
    exit;
}

$user = requireLogin();
$bookingId = filter_var($_GET['booking_id'] ?? null, FILTER_VALIDATE_INT);
$autoPrint = isset($_GET['print']);

if (!$bookingId) {
    http_response_code(400);
    echo 'Valid booking_id required';
    exit;
}

try {
    if (($user['role'] ?? '') === 'admin') {
        $stmt = $pdo->prepare("
            SELECT b.*, r.room_number, r.room_type, r.price, r.capacity,
                   u.email AS account_email, u.name AS account_name
            FROM bookings b
            INNER JOIN rooms r ON r.id = b.room_id
            LEFT JOIN users u ON u.id = b.user_id
            WHERE b.id = ?
            LIMIT 1
        ");
        $stmt->execute([$bookingId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT b.*, r.room_number, r.room_type, r.price, r.capacity,
                   u.email AS account_email, u.name AS account_name
            FROM bookings b
            INNER JOIN rooms r ON r.id = b.room_id
            LEFT JOIN users u ON u.id = b.user_id
            WHERE b.id = ? AND b.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$bookingId, $user['id']]);
    }
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        http_response_code(404);
        echo 'Booking not found';
        exit;
    }

    // Prefer stored invoice
    $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
    $invStmt->execute([$bookingId]);
    $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

    $items = [];
    if ($invoice) {
        $it = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
        $it->execute([$invoice['id']]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC);
    }

    $settings = function_exists('getBillingSettings') ? getBillingSettings($pdo) : ['tax_rate' => 16, 'currency' => 'KES'];
    $nights = 1;
    try {
        $nights = max(1, (int)(new DateTime($booking['check_in']))->diff(new DateTime($booking['check_out']))->days);
    } catch (Throwable $e) {}

    $roomTotal = $nights * (float)$booking['price'];
    $taxRate = (float)($settings['tax_rate'] ?? 16);
    $subtotal = $invoice ? (float)$invoice['subtotal'] : $roomTotal;
    $tax = $invoice ? (float)$invoice['tax_amount'] : round($subtotal * $taxRate / 100, 2);
    $total = $invoice ? (float)$invoice['total_amount'] : $subtotal + $tax;
    $paid = $invoice ? (float)$invoice['amount_paid'] : 0;
    $balance = $invoice ? (float)$invoice['balance_due'] : $total;
    $invNo = $invoice['invoice_number'] ?? ('EST-' . $bookingId);
    $invDate = $invoice['issued_at'] ?? date('Y-m-d');
    $currency = $settings['currency'] ?? 'KES';

    if (!$items) {
        $items = [[
            'description' => "Room {$booking['room_number']} – {$booking['room_type']} ({$nights} night" . ($nights === 1 ? '' : 's') . ')',
            'quantity' => $nights,
            'unit_price' => $booking['price'],
            'total_price' => $roomTotal,
        ]];
        if ($tax > 0) {
            $items[] = [
                'description' => "Tax / VAT ({$taxRate}%)",
                'quantity' => 1,
                'unit_price' => $tax,
                'total_price' => $tax,
            ];
        }
    }

    $fmt = function ($n) use ($currency) {
        return $currency . ' ' . number_format((float)$n, 2);
    };

    $app = defined('APP_NAME') ? APP_NAME : "Heywood's Hotel";
    $guest = htmlspecialchars($booking['guest_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $status = htmlspecialchars(($booking['status'] ?? '') . ' / ' . ($booking['stay_status'] ?? ''), ENT_QUOTES, 'UTF-8');

    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="invoice-' . preg_replace('/[^A-Za-z0-9\-]/', '', $invNo) . '.html"');

    $rows = '';
    foreach ($items as $row) {
        $rows .= '<tr>'
            . '<td>' . htmlspecialchars($row['description'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td class="num">' . htmlspecialchars((string)($row['quantity'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td class="num">' . $fmt($row['unit_price'] ?? 0) . '</td>'
            . '<td class="num">' . $fmt($row['total_price'] ?? 0) . '</td>'
            . '</tr>';
    }

    $printScript = $autoPrint ? '<script>window.addEventListener("load",function(){window.print();});</script>' : '';

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice {$invNo}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #1a202c; margin: 0; padding: 24px; background: #f0f2f5; }
  .sheet { max-width: 800px; margin: 0 auto; background: #fff; padding: 36px 40px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,.08); }
  .top { display: flex; justify-content: space-between; gap: 20px; border-bottom: 2px solid #243746; padding-bottom: 16px; margin-bottom: 24px; }
  .brand h1 { margin: 0; font-size: 22px; color: #243746; }
  .brand p { margin: 4px 0 0; color: #718096; font-size: 13px; }
  .inv-meta { text-align: right; }
  .inv-meta strong { display: block; font-size: 18px; color: #243746; }
  .inv-meta span { color: #718096; font-size: 13px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
  .box { background: #f8fafc; border-radius: 8px; padding: 14px 16px; }
  .box h3 { margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #718096; }
  .box p { margin: 4px 0; font-size: 14px; }
  table { width: 100%; border-collapse: collapse; margin: 12px 0 20px; }
  th { text-align: left; font-size: 12px; text-transform: uppercase; color: #718096; border-bottom: 2px solid #e2e8f0; padding: 8px; }
  td { padding: 10px 8px; border-bottom: 1px solid #edf2f7; font-size: 14px; }
  td.num, th.num { text-align: right; }
  .totals { margin-left: auto; width: 280px; }
  .totals .row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
  .totals .grand { border-top: 2px solid #243746; margin-top: 8px; padding-top: 10px; font-size: 18px; font-weight: bold; }
  .actions { margin: 20px auto; max-width: 800px; display: flex; gap: 10px; justify-content: center; }
  .actions button, .actions a {
    appearance: none; border: none; border-radius: 8px; padding: 12px 18px; font-weight: 700;
    cursor: pointer; text-decoration: none; font-size: 14px;
  }
  .btn-print { background: #1976d2; color: #fff; }
  .btn-close { background: #e2e8f0; color: #2d3748; }
  .footer { margin-top: 28px; text-align: center; font-size: 12px; color: #a0aec0; }
  @media print {
    body { background: #fff; padding: 0; }
    .sheet { box-shadow: none; border-radius: 0; max-width: none; }
    .actions { display: none !important; }
  }
</style>
</head>
<body>
  <div class="actions">
    <button type="button" class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
    <button type="button" class="btn-close" onclick="window.close()">Close</button>
  </div>
  <div class="sheet">
    <div class="top">
      <div class="brand">
        <h1>🏨 {$app}</h1>
        <p>Room reservation invoice</p>
      </div>
      <div class="inv-meta">
        <strong>{$invNo}</strong>
        <span>Issued: {$invDate}</span>
      </div>
    </div>
    <div class="grid">
      <div class="box">
        <h3>Bill to</h3>
        <p><strong>{$guest}</strong></p>
        <p>Booking #{$bookingId}</p>
        <p>Status: {$status}</p>
      </div>
      <div class="box">
        <h3>Stay</h3>
        <p>Room <strong>{$booking['room_number']}</strong> ({$booking['room_type']})</p>
        <p>Check-in: <strong>{$booking['check_in']}</strong></p>
        <p>Check-out: <strong>{$booking['check_out']}</strong></p>
        <p>Nights: <strong>{$nights}</strong></p>
      </div>
    </div>
    <table>
      <thead>
        <tr>
          <th>Description</th>
          <th class="num">Qty</th>
          <th class="num">Unit</th>
          <th class="num">Amount</th>
        </tr>
      </thead>
      <tbody>
        {$rows}
      </tbody>
    </table>
    <div class="totals">
      <div class="row"><span>Subtotal</span><span>{$fmt($subtotal)}</span></div>
      <div class="row"><span>Tax</span><span>{$fmt($tax)}</span></div>
      <div class="row"><span>Amount paid</span><span>{$fmt($paid)}</span></div>
      <div class="row"><span>Balance due</span><span>{$fmt($balance)}</span></div>
      <div class="row grand"><span>Total</span><span>{$fmt($total)}</span></div>
    </div>
    <div class="footer">Thank you for choosing {$app}.</div>
  </div>
  {$printScript}
</body>
</html>
HTML;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo APP_DEBUG ? $e->getMessage() : 'Unable to generate invoice.';
    exit;
}
