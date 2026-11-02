<?php

require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf')) {
    requireCsrf();
}


header('Content-Type: application/json');

try {

    $user = requirePermission('payments');

    $method =
        $_SERVER['REQUEST_METHOD'];


    /*
     * =====================================================
     * GET PAYMENTS
     * =====================================================
     */

    if ($method === 'GET') {

        $invoiceId =
            (int) (
                $_GET['invoice_id'] ?? 0
            );


        if ($invoiceId <= 0) {

            throw new Exception(
                'Invoice ID is required.'
            );
        }


        $stmt = $pdo->prepare("
            SELECT

                p.id,
                p.invoice_id,
                p.amount,
                p.payment_method,
                p.transaction_reference,
                p.notes,
                p.paid_at,

                u.name AS recorded_by_name

            FROM payments p

            LEFT JOIN users u
                ON u.id = p.recorded_by

            WHERE p.invoice_id = ?

            ORDER BY p.paid_at DESC,
                     p.id DESC
        ");

        $stmt->execute([
            $invoiceId
        ]);


        echo json_encode([
            'success' => true,
            'message' => 'Payments retrieved successfully.',
            'data' => $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
        ]);

        exit;
    }


    /*
     * =====================================================
     * POST PAYMENT
     * =====================================================
     */

    if ($method === 'POST') {

        if ($user['role'] !== 'admin') {

            http_response_code(403);

            echo json_encode([
                'success' => false,
                'message' => 'Administrator access required.'
            ]);

            exit;
        }


        $input = json_decode(
            file_get_contents('php://input'),
            true
        );


        $invoiceId =
            (int) (
                $input['invoice_id'] ?? 0
            );

        $amount =
            (float) (
                $input['amount'] ?? 0
            );

        $paymentMethod =
            trim(
                $input['payment_method'] ?? 'cash'
            );

        $reference =
            trim(
                $input['transaction_reference'] ?? ''
            );

        $notes =
            trim(
                $input['notes'] ?? ''
            );


        $allowedMethods = [
            'cash',
            'card',
            'mpesa',
            'bank_transfer',
            'other'
        ];


        if ($invoiceId <= 0) {

            throw new Exception(
                'Invoice ID is required.'
            );
        }


        if ($amount <= 0) {

            throw new Exception(
                'Payment amount must be greater than zero.'
            );
        }


        if (
            !in_array(
                $paymentMethod,
                $allowedMethods,
                true
            )
        ) {

            throw new Exception(
                'Invalid payment method.'
            );
        }


        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }


        /*
         * Lock invoice.
         */

        $stmt = $pdo->prepare("
            SELECT
                id,
                total_amount,
                amount_paid,
                balance_due,
                status

            FROM invoices

            WHERE id = ?

            LIMIT 1

            FOR UPDATE
        ");

        $stmt->execute([
            $invoiceId
        ]);

        $invoice = $stmt->fetch(
            PDO::FETCH_ASSOC
        );


        if (!$invoice) {

            throw new Exception(
                'Invoice not found.'
            );
        }


        if (
            $invoice['status'] === 'cancelled'
        ) {

            throw new Exception(
                'This invoice has been cancelled.'
            );
        }


        $currentBalance =
            max(
                0,
                (float) $invoice['balance_due']
            );


        if ($amount > $currentBalance) {

            throw new Exception(
                'Payment cannot exceed the outstanding balance.'
            );
        }


        /*
         * Record payment.
         */

        $stmt = $pdo->prepare("
            INSERT INTO payments
            (
                invoice_id,
                amount,
                payment_method,
                transaction_reference,
                notes,
                recorded_by
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $invoiceId,
            $amount,
            $paymentMethod,
            $reference !== ''
                ? $reference
                : null,
            $notes !== ''
                ? $notes
                : null,
            $user['id']
        ]);


        $paymentId =
            (int) $pdo->lastInsertId();


        /*
         * Recalculate invoice totals.
         */

        $newAmountPaid =
            (float) $invoice['amount_paid']
            + $amount;

        $newBalance =
            max(
                0,
                (float) $invoice['total_amount']
                - $newAmountPaid
            );


        if ($newBalance <= 0) {

            $invoiceStatus = 'paid';

        } elseif ($newAmountPaid > 0) {

            $invoiceStatus = 'partially_paid';

        } else {

            $invoiceStatus = 'unpaid';
        }


        $update = $pdo->prepare("
            UPDATE invoices

            SET
                amount_paid = ?,
                balance_due = ?,
                status = ?

            WHERE id = ?
        ");

        $update->execute([
            $newAmountPaid,
            $newBalance,
            $invoiceStatus,
            $invoiceId
        ]);


        $pdo->commit();


        echo json_encode([
            'success' => true,
            'message' => 'Payment recorded successfully.',
            'data' => [
                'payment_id' => $paymentId,
                'invoice_id' => $invoiceId,
                'amount_paid' => $newAmountPaid,
                'balance_due' => $newBalance,
                'status' => $invoiceStatus
            ]
        ]);

        exit;
    }


    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);

} catch (Throwable $error) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => $error->getMessage()
    ]);
}