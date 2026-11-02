<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * AUTHENTICATED CANCEL BOOKING API
 * ==========================================
 */

require_once __DIR__ . '/auth.php';

if (function_exists('requireCsrf')) {
    requireCsrf();
}

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/sms.php';


/*
|--------------------------------------------------------------------------
| ALLOW ONLY POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    jsonResponse(
        false,
        'Method Not Allowed.',
        null,
        405
    );
}


/*
|--------------------------------------------------------------------------
| REQUIRE LOGIN
|--------------------------------------------------------------------------
*/

$user = requireLogin();


/*
|--------------------------------------------------------------------------
| GET REQUEST DATA
|--------------------------------------------------------------------------
*/

$input =
    getJsonInput();


$bookingId =
    filter_var(
        $input['booking_id'] ?? null,
        FILTER_VALIDATE_INT
    );


if (!$bookingId) {

    jsonResponse(
        false,
        'Invalid booking ID.',
        null,
        400
    );
}


try {

    /*
    |--------------------------------------------------------------------------
    | FIND BOOKING
    |--------------------------------------------------------------------------
    */

    if ($user['role'] === 'admin') {

        /*
         * ADMIN CAN CANCEL ANY BOOKING
         */

        $stmt = $pdo->prepare("
            SELECT

                b.id,

                b.user_id,

                b.room_id,

                b.guest_name,

                b.check_in,

                b.check_out,

                b.status,

                r.room_number,

                r.room_type

            FROM bookings b

            INNER JOIN rooms r
                ON r.id = b.room_id

            WHERE b.id = ?

            LIMIT 1
        ");


        $stmt->execute([
            $bookingId
        ]);

    } else {

        /*
         * USER CAN ONLY CANCEL THEIR OWN BOOKING
         */

        $stmt = $pdo->prepare("
            SELECT

                b.id,

                b.user_id,

                b.room_id,

                b.guest_name,

                b.check_in,

                b.check_out,

                b.status,

                r.room_number,

                r.room_type

            FROM bookings b

            INNER JOIN rooms r
                ON r.id = b.room_id

            WHERE b.id = ?

            AND b.user_id = ?

            LIMIT 1
        ");


        $stmt->execute([

            $bookingId,

            $user['id']

        ]);

    }


    $booking =
        $stmt->fetch();


    /*
    |--------------------------------------------------------------------------
    | CHECK BOOKING
    |--------------------------------------------------------------------------
    */

    if (!$booking) {

        jsonResponse(

            false,

            'Booking not found or you do not have permission to cancel it.',

            null,

            404

        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK STATUS
    |--------------------------------------------------------------------------
    */

    if (
        $booking['status'] ===
        'cancelled'
    ) {

        jsonResponse(

            false,

            'Booking has already been cancelled.',

            null,

            409

        );
    }


    /*
    |--------------------------------------------------------------------------
    | CANCEL BOOKING
    |--------------------------------------------------------------------------
    */

    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }


    $update =
        $pdo->prepare("
            UPDATE bookings

            SET

                status = 'cancelled',

                cancelled_at = NOW()

            WHERE id = ?
        ");


    $update->execute([
        $bookingId
    ]);


    $pdo->commit();

    // Cancellation email (best effort)
    $emailSent = false;
    try {
        $recipient = $user['email'] ?? null;
        if (!$recipient && !empty($booking['user_id'])) {
            $em = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
            $em->execute([(int) $booking['user_id']]);
            $recipient = $em->fetchColumn() ?: null;
        }
        $emailSent = sendBookingCancellationEmail([
            'id' => (int) $booking['id'],
            'guest_name' => $booking['guest_name'] ?? '',
            'room_number' => $booking['room_number'] ?? '',
            'room_type' => $booking['room_type'] ?? '',
            'check_in' => $booking['check_in'] ?? '',
            'check_out' => $booking['check_out'] ?? '',
        ], $recipient);
    } catch (Throwable $mailErr) {
        if (function_exists('apiLog')) {
            apiLog('warning', 'Cancellation email failed', ['error' => $mailErr->getMessage()]);
        }
    }

    $smsSent = false;
    try {
        $phone = '';
        if (!empty($booking['user_id'])) {
            $ph = $pdo->prepare("SELECT phone FROM users WHERE id = ? LIMIT 1");
            $ph->execute([(int) $booking['user_id']]);
            $phone = smsNormalizePhone((string)($ph->fetchColumn() ?: ''));
        }
        if ($phone === '' && !empty($user['phone'])) {
            $phone = smsNormalizePhone((string)$user['phone']);
        }
        if ($phone !== '') {
            $smsSent = sendCancellationSms([
                'id' => (int) $booking['id'],
                'room_number' => $booking['room_number'] ?? '',
            ], $phone);
        }
    } catch (Throwable $smsErr) {
        if (function_exists('apiLog')) {
            apiLog('warning', 'Cancellation SMS failed', ['error' => $smsErr->getMessage()]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RETURN SUCCESS
    |--------------------------------------------------------------------------
    */

    jsonResponse(

        true,

        ($emailSent || !empty($smsSent))
            ? 'Booking cancelled successfully. Notification(s) sent.'
            : 'Booking cancelled successfully.',

        [

            'booking_id' =>
                (int) $booking['id'],

            'user_id' =>
                $booking['user_id']
                    ? (int) $booking['user_id']
                    : null,

            'room_id' =>
                (int) $booking['room_id'],

            'room_number' =>
                $booking['room_number'],

            'room_type' =>
                $booking['room_type'],

            'guest_name' =>
                $booking['guest_name'],

            'check_in' =>
                $booking['check_in'],

            'check_out' =>
                $booking['check_out'],

            'status' =>
                'cancelled',

            'cancelled_at' =>
                date('Y-m-d H:i:s')

        ],

        200

    );


} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    jsonResponse(

        false,

        'Unable to cancel booking.',

        $e->getMessage(),

        500

    );
}