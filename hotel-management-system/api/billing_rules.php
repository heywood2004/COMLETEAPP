<?php
/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * AUTOMATED BILLING RULES ENGINE
 * ==========================================
 *
 * Rules:
 * 1. Room charge = nights × room price
 * 2. Nights = max(1, check_out - check_in) in calendar days
 * 3. On checkout, actual stay nights can be recalculated
 *    from actual_check_in → actual_check_out (or scheduled dates)
 * 4. Tax = (subtotal - discount) × tax_rate / 100
 * 5. Optional service charge %
 * 6. Optional late checkout fee (if checkout after cutoff hour)
 * 7. Optional early checkout: charge min nights or full reserved nights
 * 8. Balance = total - amount_paid
 * 9. Status: unpaid | partially_paid | paid
 */

declare(strict_types=1);

/**
 * Load all billing-related settings from hotel_settings.
 */
function getBillingSettings(PDO $pdo): array
{
    $defaults = [
        'tax_rate'              => 16.0,   // %
        'service_charge_rate'   => 0.0,    // %
        'currency'              => 'KES',
        'min_nights'            => 1,
        'early_checkout_policy' => 'actual', // actual | reserved | minimum
        'late_checkout_hour'    => 12,       // 24h clock; fee applies after this hour
        'late_checkout_fee'     => 0.0,      // flat fee in currency
        'auto_apply_tax'        => 1,
        'auto_apply_service'    => 1,
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM hotel_settings");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($defaults as $key => $val) {
            if (isset($rows[$key]) && $rows[$key] !== '' && $rows[$key] !== null) {
                if (is_numeric($val)) {
                    $defaults[$key] = is_float($val + 0) || strpos((string)$val, '.') !== false
                        ? (float) $rows[$key]
                        : (is_int($val) ? (int) $rows[$key] : (float) $rows[$key]);
                } else {
                    $defaults[$key] = $rows[$key];
                }
            }
        }
        // normalize numeric fields
        $defaults['tax_rate']            = (float) $defaults['tax_rate'];
        $defaults['service_charge_rate'] = (float) $defaults['service_charge_rate'];
        $defaults['min_nights']          = max(1, (int) $defaults['min_nights']);
        $defaults['late_checkout_hour']  = (int) $defaults['late_checkout_hour'];
        $defaults['late_checkout_fee']   = (float) $defaults['late_checkout_fee'];
        $defaults['auto_apply_tax']      = (int) $defaults['auto_apply_tax'];
        $defaults['auto_apply_service']  = (int) $defaults['auto_apply_service'];
    } catch (Throwable $e) {
        // table may not exist yet – return defaults
    }

    return $defaults;
}

/**
 * Calculate number of nights between two dates (Y-m-d or datetime).
 */
function calculateNights(string $from, string $to, int $minNights = 1): int
{
    try {
        $start = new DateTime(substr($from, 0, 10));
        $end   = new DateTime(substr($to, 0, 10));
        if ($end <= $start) {
            return $minNights;
        }
        return max($minNights, (int) $start->diff($end)->days);
    } catch (Throwable $e) {
        return $minNights;
    }
}

/**
 * Decide billable nights based on early-checkout policy.
 *
 * @param array $booking Must include check_in, check_out, optional actual_*
 * @param array $settings Billing settings
 * @param bool  $isCheckout Whether this is final checkout calculation
 */
function resolveBillableNights(array $booking, array $settings, bool $isCheckout = false): int
{
    $minNights = (int) ($settings['min_nights'] ?? 1);
    $reserved  = calculateNights(
        (string) $booking['check_in'],
        (string) $booking['check_out'],
        $minNights
    );

    if (!$isCheckout) {
        return $reserved;
    }

    $policy = strtolower((string) ($settings['early_checkout_policy'] ?? 'actual'));

    $actualFrom = $booking['actual_check_in'] ?? $booking['check_in'];
    $actualTo   = $booking['actual_check_out'] ?? date('Y-m-d H:i:s');

    $actualNights = calculateNights((string) $actualFrom, (string) $actualTo, $minNights);

    switch ($policy) {
        case 'reserved':
            // Always charge the original reserved nights
            return $reserved;

        case 'minimum':
            // Charge at least min_nights, never more than reserved unless actual > reserved
            return max($minNights, min($reserved, $actualNights));

        case 'actual':
        default:
            // Charge for actual stay (at least min_nights)
            return max($minNights, $actualNights);
    }
}

/**
 * Detect late checkout and return fee amount.
 */
function resolveLateCheckoutFee(array $booking, array $settings): float
{
    $fee  = (float) ($settings['late_checkout_fee'] ?? 0);
    $hour = (int) ($settings['late_checkout_hour'] ?? 12);

    if ($fee <= 0) {
        return 0.0;
    }

    $checkoutTime = $booking['actual_check_out'] ?? date('Y-m-d H:i:s');
    try {
        $dt = new DateTime($checkoutTime);
        if ((int) $dt->format('G') >= $hour) {
            // Also only apply if checking out on or after scheduled check_out date
            $scheduledOut = new DateTime(substr((string) $booking['check_out'], 0, 10));
            $actualDay    = new DateTime($dt->format('Y-m-d'));
            if ($actualDay >= $scheduledOut) {
                return round($fee, 2);
            }
        }
    } catch (Throwable $e) {
        return 0.0;
    }

    return 0.0;
}

/**
 * Build a full billing breakdown.
 *
 * @return array{
 *   nights:int, room_rate:float, room_total:float,
 *   service_charge:float, tax_amount:float, late_fee:float,
 *   discount_amount:float, subtotal:float, total_amount:float,
 *   items:array, currency:string, tax_rate:float, service_charge_rate:float
 * }
 */
function buildBillingBreakdown(
    array $booking,
    float $roomPrice,
    array $settings,
    bool $isCheckout = false,
    float $discountAmount = 0.0
): array {
    $nights   = resolveBillableNights($booking, $settings, $isCheckout);
    $roomTotal = round($nights * $roomPrice, 2);

    $lateFee = $isCheckout ? resolveLateCheckoutFee($booking, $settings) : 0.0;

    // Subtotal before tax = room + service + late - discount
    $serviceRate = (float) ($settings['service_charge_rate'] ?? 0);
    $service     = 0.0;
    if (!empty($settings['auto_apply_service']) && $serviceRate > 0) {
        $service = round($roomTotal * ($serviceRate / 100), 2);
    }

    $discount = max(0, round($discountAmount, 2));

    $taxable = max(0, $roomTotal + $service + $lateFee - $discount);

    $taxRate = (float) ($settings['tax_rate'] ?? 0);
    $tax     = 0.0;
    if (!empty($settings['auto_apply_tax']) && $taxRate > 0) {
        $tax = round($taxable * ($taxRate / 100), 2);
    }

    $total = round($taxable + $tax, 2);

    $items = [];

    $roomNo   = $booking['room_number'] ?? '';
    $roomType = $booking['room_type'] ?? 'Room';
    $items[] = [
        'item_type'   => 'room',
        'description' => "Room {$roomNo} - {$roomType} ({$nights} night" . ($nights === 1 ? '' : 's') . ")",
        'quantity'    => $nights,
        'unit_price'  => $roomPrice,
        'total_price' => $roomTotal,
    ];

    if ($service > 0) {
        $items[] = [
            'item_type'   => 'service',
            'description' => "Service charge ({$serviceRate}%)",
            'quantity'    => 1,
            'unit_price'  => $service,
            'total_price' => $service,
        ];
    }

    if ($lateFee > 0) {
        $items[] = [
            'item_type'   => 'other',
            'description' => 'Late checkout fee',
            'quantity'    => 1,
            'unit_price'  => $lateFee,
            'total_price' => $lateFee,
        ];
    }

    if ($discount > 0) {
        $items[] = [
            'item_type'   => 'discount',
            'description' => 'Discount',
            'quantity'    => 1,
            'unit_price'  => -$discount,
            'total_price' => -$discount,
        ];
    }

    if ($tax > 0) {
        $items[] = [
            'item_type'   => 'tax',
            'description' => "Tax / VAT ({$taxRate}%)",
            'quantity'    => 1,
            'unit_price'  => $tax,
            'total_price' => $tax,
        ];
    }

    return [
        'nights'               => $nights,
        'room_rate'            => $roomPrice,
        'room_total'           => $roomTotal,
        'service_charge'       => $service,
        'tax_amount'           => $tax,
        'late_fee'             => $lateFee,
        'discount_amount'      => $discount,
        'subtotal'             => round($roomTotal + $service + $lateFee, 2),
        'total_amount'         => $total,
        'items'                => $items,
        'currency'             => (string) ($settings['currency'] ?? 'KES'),
        'tax_rate'             => $taxRate,
        'service_charge_rate'  => $serviceRate,
    ];
}

/**
 * Derive invoice payment status.
 */
function resolveInvoiceStatus(float $total, float $amountPaid): string
{
    if ($amountPaid <= 0) {
        return 'unpaid';
    }
    if ($amountPaid + 0.001 >= $total) {
        return 'paid';
    }
    return 'partially_paid';
}

/**
 * Generate a unique invoice number.
 */
function generateInvoiceNumber(int $bookingId): string
{
    return 'INV-' . date('YmdHis') . '-' . $bookingId;
}

/**
 * Create or update invoice + line items for a booking.
 *
 * @return array Invoice row data including id
 */
function upsertInvoiceForBooking(
    PDO $pdo,
    array $booking,
    float $roomPrice,
    array $settings,
    bool $isCheckout = false,
    float $discountAmount = 0.0
): array {
    $breakdown = buildBillingBreakdown($booking, $roomPrice, $settings, $isCheckout, $discountAmount);
    $bookingId = (int) $booking['id'];
    $guestId   = !empty($booking['guest_id']) ? (int) $booking['guest_id'] : null;

    $existing = $pdo->prepare("SELECT * FROM invoices WHERE booking_id = ? LIMIT 1 FOR UPDATE");
    $existing->execute([$bookingId]);
    $invoice = $existing->fetch(PDO::FETCH_ASSOC);

    $amountPaid = $invoice ? (float) $invoice['amount_paid'] : 0.0;
    $total      = $breakdown['total_amount'];
    $balance    = max(0, round($total - $amountPaid, 2));
    $status     = resolveInvoiceStatus($total, $amountPaid);

    if ($invoice) {
        $upd = $pdo->prepare("
            UPDATE invoices SET
                guest_id = COALESCE(?, guest_id),
                subtotal = ?,
                tax_amount = ?,
                discount_amount = ?,
                total_amount = ?,
                amount_paid = ?,
                balance_due = ?,
                status = ?
            WHERE id = ?
        ");
        $upd->execute([
            $guestId,
            $breakdown['subtotal'],
            $breakdown['tax_amount'],
            $breakdown['discount_amount'],
            $total,
            $amountPaid,
            $balance,
            $status,
            $invoice['id']
        ]);
        $invoiceId = (int) $invoice['id'];
        $invoiceNumber = $invoice['invoice_number'];

        // Replace line items
        $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invoiceId]);
    } else {
        $invoiceNumber = generateInvoiceNumber($bookingId);
        $ins = $pdo->prepare("
            INSERT INTO invoices (
                invoice_number, booking_id, guest_id,
                subtotal, tax_amount, discount_amount,
                total_amount, amount_paid, balance_due, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $invoiceNumber,
            $bookingId,
            $guestId,
            $breakdown['subtotal'],
            $breakdown['tax_amount'],
            $breakdown['discount_amount'],
            $total,
            $amountPaid,
            $balance,
            $status
        ]);
        $invoiceId = (int) $pdo->lastInsertId();
    }

    $itemStmt = $pdo->prepare("
        INSERT INTO invoice_items (
            invoice_id, item_type, description, quantity, unit_price, total_price
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($breakdown['items'] as $item) {
        $itemStmt->execute([
            $invoiceId,
            $item['item_type'],
            $item['description'],
            $item['quantity'],
            $item['unit_price'],
            $item['total_price']
        ]);
    }

    return array_merge($breakdown, [
        'id'             => $invoiceId,
        'invoice_id'     => $invoiceId,
        'invoice_number' => $invoiceNumber,
        'amount_paid'    => $amountPaid,
        'balance_due'    => $balance,
        'status'         => $status,
        'booking_id'     => $bookingId,
        'guest_id'       => $guestId,
    ]);
}
