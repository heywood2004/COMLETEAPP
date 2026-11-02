<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * AUTHENTICATED DASHBOARD API
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
    | TOTAL ROOMS
    |--------------------------------------------------------------------------
    */

    $totalStmt =
        $pdo->query("
            SELECT COUNT(*)
            FROM rooms
        ");


    $totalRooms =
        (int) $totalStmt->fetchColumn();


    /*
    |--------------------------------------------------------------------------
    | TODAY
    |--------------------------------------------------------------------------
    */

    $today =
        date('Y-m-d');


    /*
    |--------------------------------------------------------------------------
    | CURRENTLY BOOKED ROOMS
    |--------------------------------------------------------------------------
    |
    | Only active bookings that cover today's date
    | are considered currently booked.
    |
    */

    $bookedStmt =
        $pdo->prepare("
            SELECT COUNT(DISTINCT room_id)

            FROM bookings

            WHERE status = 'active'

            AND check_in <= ?

            AND check_out > ?
        ");


    $bookedStmt->execute([
        $today,
        $today
    ]);


    $bookedRooms =
        (int) $bookedStmt->fetchColumn();


    /*
    |--------------------------------------------------------------------------
    | AVAILABLE ROOMS
    |--------------------------------------------------------------------------
    */

    $availableRooms =
        $totalRooms -
        $bookedRooms;


    if ($availableRooms < 0) {

        $availableRooms = 0;

    }


    /*
    |--------------------------------------------------------------------------
    | RETURN DASHBOARD DATA
    |--------------------------------------------------------------------------
    */

    jsonResponse(

        true,

        'Dashboard statistics retrieved successfully.',

        [

            'totalRooms' =>
                $totalRooms,

            'availableRooms' =>
                $availableRooms,

            'bookedRooms' =>
                $bookedRooms

        ],

        200

    );


} catch (PDOException $e) {

    jsonResponse(

        false,

        'Could not load dashboard statistics.',

        $e->getMessage(),

        500

    );
}