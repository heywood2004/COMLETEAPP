<?php
/**
 * Bookings API
 * GET ?scope=active|history|all  (default active)
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');

$user = requireLogin();
$scope = strtolower(trim((string)($_GET['scope'] ?? 'active')));
if (!in_array($scope, ['active', 'history', 'all'], true)) {
    $scope = 'active';
}

try {
    if ($user['role'] === 'admin') {
        $where = match ($scope) {
            'history' => "(b.status = 'cancelled' OR b.stay_status = 'checked_out')",
            'all' => '1=1',
            default => "b.status = 'active'",
        };

        $stmt = $pdo->query("
            SELECT
                b.id,
                b.user_id,
                b.room_id,
                b.guest_id,
                b.guest_name,
                b.check_in,
                b.check_out,
                b.status,
                b.stay_status,
                b.actual_check_in,
                b.actual_check_out,
                b.special_requests,
                b.created_at,
                b.cancelled_at,
                r.room_number,
                r.room_type,
                r.price,
                r.capacity,
                u.name  AS account_name,
                u.email AS account_email,
                g.full_name AS guest_profile_name,
                g.phone AS guest_phone,
                inv.id AS invoice_id,
                inv.invoice_number,
                inv.total_amount AS invoice_total,
                inv.amount_paid AS invoice_paid,
                inv.balance_due AS invoice_balance,
                inv.status AS invoice_status
            FROM bookings b
            INNER JOIN rooms r ON r.id = b.room_id
            LEFT JOIN users u ON u.id = b.user_id
            LEFT JOIN guests g ON g.id = b.guest_id
            LEFT JOIN invoices inv ON inv.booking_id = b.id
            WHERE {$where}
            ORDER BY b.check_in DESC, b.id DESC
        ");
    } else {
        $where = match ($scope) {
            'history' => "(b.status = 'cancelled' OR b.stay_status = 'checked_out') AND b.user_id = ?",
            'all' => 'b.user_id = ?',
            default => "b.status = 'active' AND b.user_id = ?",
        };

        $stmt = $pdo->prepare("
            SELECT
                b.id,
                b.user_id,
                b.room_id,
                b.guest_id,
                b.guest_name,
                b.check_in,
                b.check_out,
                b.status,
                b.stay_status,
                b.actual_check_in,
                b.actual_check_out,
                b.created_at,
                b.cancelled_at,
                r.room_number,
                r.room_type,
                r.price,
                r.capacity,
                inv.id AS invoice_id,
                inv.invoice_number,
                inv.total_amount AS invoice_total,
                inv.amount_paid AS invoice_paid,
                inv.balance_due AS invoice_balance,
                inv.status AS invoice_status
            FROM bookings b
            INNER JOIN rooms r ON r.id = b.room_id
            LEFT JOIN invoices inv ON inv.booking_id = b.id
            WHERE {$where}
            ORDER BY b.check_in DESC, b.id DESC
        ");
        $stmt->execute([(int)$user['id']]);
    }

    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, 'Bookings retrieved successfully.', [
        'scope' => $scope,
        'count' => count($bookings),
        'bookings' => $bookings,
    ], 200);

} catch (Throwable $e) {
    handleApiException($e, 'Unable to load bookings.');
}
