<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * AUTHENTICATED ROOMS API
 * ==========================================
 */

require_once __DIR__ . '/auth.php';


header(
    "Content-Type: application/json; charset=UTF-8"
);


/*
|--------------------------------------------------------------------------
| REQUIRE LOGIN
|--------------------------------------------------------------------------
*/

$user = requireLogin();


try {

    /*
    |--------------------------------------------------------------------------
    | GET TODAY'S DATE
    |--------------------------------------------------------------------------
    */

    $today =
        date('Y-m-d');


    /*
    |--------------------------------------------------------------------------
    | GET ALL ROOMS
    |--------------------------------------------------------------------------
    |
    | A room is considered BOOKED when it has an
    | active booking covering today's date.
    |
    | check_in  <= today
    | AND
    | check_out > today
    |
    */

    $stmt = $pdo->prepare("
        SELECT

            r.id,

            r.room_number,

            r.room_type,

            r.price,

            r.capacity,

            r.description,

            COALESCE(r.housekeeping_status, 'ready') AS housekeeping_status,

            r.housekeeping_notes,

            CASE
                WHEN COALESCE(r.housekeeping_status, 'ready') = 'out_of_order'
                    THEN 'out_of_order'
                WHEN b.id IS NOT NULL
                    THEN 'booked'
                WHEN COALESCE(r.housekeeping_status, 'ready') IN ('dirty', 'cleaning')
                    THEN 'maintenance'
                ELSE 'available'
            END AS status,

            b.id AS booking_id,

            b.guest_name,

            b.check_in,

            b.check_out

        FROM rooms r

        LEFT JOIN bookings b

            ON b.room_id = r.id

            AND b.status = 'active'

            AND b.check_in <= ?

            AND b.check_out > ?

        ORDER BY
            r.id ASC
    ");


    $stmt->execute([
        $today,
        $today
    ]);


    $rooms =
        $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | RETURN RESPONSE
    |--------------------------------------------------------------------------
    */

    jsonResponse(

        true,

        'Rooms retrieved successfully.',

        $rooms,

        200

    );


} catch (PDOException $e) {

    jsonResponse(

        false,

        'Unable to load rooms.',

        $e->getMessage(),

        500

    );
}