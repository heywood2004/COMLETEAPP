<?php
/**
 * M-Pesa STK Push callback
 * Safaricom will POST the result here. No session/auth required.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

// Always acknowledge quickly so Safaricom does not retry endlessly
function ack(): void
{
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}

if (!is_array($payload)) {
    ack();
    exit;
}

try {
    $body = $payload['Body']['stkCallback'] ?? null;
    if (!$body) {
        ack();
        exit;
    }

    $merchantId = $body['MerchantRequestID'] ?? null;
    $checkoutId = $body['CheckoutRequestID'] ?? null;
    $resultCode = isset($body['ResultCode']) ? (int) $body['ResultCode'] : null;
    $resultDesc = $body['ResultDesc'] ?? '';

    $receipt = null;
    $amount  = null;
    $phone   = null;

    if ($resultCode === 0 && !empty($body['CallbackMetadata']['Item'])) {
        foreach ($body['CallbackMetadata']['Item'] as $item) {
            $name = $item['Name'] ?? '';
            $val  = $item['Value'] ?? null;
            if ($name === 'MpesaReceiptNumber') $receipt = (string) $val;
            if ($name === 'Amount') $amount = (float) $val;
            if ($name === 'PhoneNumber') $phone = (string) $val;
        }
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT * FROM mpesa_transactions
        WHERE checkout_request_id = ?
           OR merchant_request_id = ?
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$checkoutId, $merchantId]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        $pdo->commit();
        ack();
        exit;
    }

    // Already finalized
    if (in_array($tx['status'], ['success', 'failed', 'cancelled'], true)) {
        $pdo->commit();
        ack();
        exit;
    }

    $status = 'failed';
    if ($resultCode === 0) {
        $status = 'success';
    } elseif ($resultCode === 1032) {
        $status = 'cancelled'; // user cancelled
    } elseif ($resultCode === 1037) {
        $status = 'timeout';
    }

    $upd = $pdo->prepare("
        UPDATE mpesa_transactions SET
            status = ?,
            result_code = ?,
            result_desc = ?,
            mpesa_receipt = ?,
            raw_callback = ?
        WHERE id = ?
    ");
    $upd->execute([
        $status,
        $resultCode,
        $resultDesc,
        $receipt,
        $raw,
        $tx['id']
    ]);

    // On success, record payment against invoice
    if ($status === 'success' && !empty($tx['invoice_id'])) {
        $payAmount = $amount !== null ? $amount : (float) $tx['amount'];

        $invStmt = $pdo->prepare("
            SELECT id, total_amount, amount_paid, balance_due, status
            FROM invoices WHERE id = ? LIMIT 1 FOR UPDATE
        ");
        $invStmt->execute([$tx['invoice_id']]);
        $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

        if ($invoice && $invoice['status'] !== 'cancelled') {
            // Avoid duplicate payment if callback is repeated
            $dup = $pdo->prepare("
                SELECT id FROM payments
                WHERE invoice_id = ?
                  AND transaction_reference = ?
                LIMIT 1
            ");
            $dup->execute([$tx['invoice_id'], $receipt ?: ('MPESA-TX-' . $tx['id'])]);

            if (!$dup->fetch()) {
                $payIns = $pdo->prepare("
                    INSERT INTO payments (
                        invoice_id, amount, payment_method,
                        transaction_reference, notes, recorded_by
                    ) VALUES (?, ?, 'mpesa', ?, ?, ?)
                ");
                $payIns->execute([
                    $tx['invoice_id'],
                    $payAmount,
                    $receipt ?: ('MPESA-TX-' . $tx['id']),
                    'M-Pesa STK Push',
                    $tx['recorded_by']
                ]);

                $newPaid = (float) $invoice['amount_paid'] + $payAmount;
                $total   = (float) $invoice['total_amount'];
                $balance = max(0, round($total - $newPaid, 2));
                $invStatus = $balance <= 0 ? 'paid' : ($newPaid > 0 ? 'partially_paid' : 'unpaid');

                $pdo->prepare("
                    UPDATE invoices SET
                        amount_paid = ?, balance_due = ?, status = ?
                    WHERE id = ?
                ")->execute([$newPaid, $balance, $invStatus, $invoice['id']]);
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('M-Pesa callback error: ' . $e->getMessage());
}

ack();
