<?php

/**
 * ==========================================
 * HOTEL MANAGEMENT SYSTEM
 * Front Controller / Router
 * ==========================================
 */

require_once __DIR__ . '/api/config.php';

/*
|--------------------------------------------------------------------------
| REQUEST INFORMATION
|--------------------------------------------------------------------------
*/

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

/*
|--------------------------------------------------------------------------
| REMOVE PROJECT FOLDER (IF NOT INSTALLED IN ROOT)
|--------------------------------------------------------------------------
|
| Example:
| http://localhost/Hotel-Management-System/
|
| becomes:
|
| /
|
*/

$projectFolder = '';
// Auto-detect common project folder names
foreach (['/Hotel-Management-System', '/hotel-management-system', '/hotel'] as $folder) {
    if (strpos($requestUri, $folder) === 0) {
        $projectFolder = $folder;
        break;
    }
}

if (strpos($requestUri, $projectFolder) === 0) {
    $requestUri = substr($requestUri, strlen($projectFolder));
}

if ($requestUri === '') {
    $requestUri = '/';
}

/*
|--------------------------------------------------------------------------
| API ROUTES
|--------------------------------------------------------------------------
*/

switch ($requestUri) {

    case '/api/public_rooms':
        require __DIR__ . '/api/public_rooms.php';
        exit;

    case '/api/availability':
        require __DIR__ . '/api/availability.php';
        exit;

    case '/api/rooms':
        require __DIR__ . '/api/rooms.php';
        exit;

    case '/api/book':
        require __DIR__ . '/api/book.php';
        exit;

    case '/api/cancel':
        require __DIR__ . '/api/cancel.php';
        exit;

    case '/api/login':
        require __DIR__ . '/api/login.php';
        exit;

    case '/api/logout':
        require __DIR__ . '/api/logout.php';
        exit;

    case '/api/register':
        require __DIR__ . '/api/register.php';
        exit;

    case '/api/session':
        require __DIR__ . '/api/session.php';
        exit;

    case '/api/bookings':
        require __DIR__ . '/api/bookings.php';
        exit;

    case '/api/users':
        require __DIR__ . '/api/users.php';
        exit;

    case '/api/dashboard':
        require __DIR__ . '/api/dashboard.php';
        exit;

    case '/api/add_room':
        require __DIR__ . '/api/add_room.php';
        exit;

    case '/api/update_room':
        require __DIR__ . '/api/update_room.php';
        exit;

    case '/api/delete_room':
        require __DIR__ . '/api/delete_room.php';
        exit;

    case '/api/update_user':
        require __DIR__ . '/api/update_user.php';
        exit;

    case '/api/delete_user':
        require __DIR__ . '/api/delete_user.php';
        exit;

    case '/api/guests':
        require __DIR__ . '/api/guests.php';
        exit;

    case '/api/checkin':
        require __DIR__ . '/api/checkin.php';
        exit;

    case '/api/checkout':
        require __DIR__ . '/api/checkout.php';
        exit;

    case '/api/invoice_pdf':
        require __DIR__ . '/api/invoice_pdf.php';
        exit;

    case '/api/invoice':
        require __DIR__ . '/api/invoice.php';
        exit;

    case '/api/payments':
        require __DIR__ . '/api/payments.php';
        exit;

    case '/api/media':
        require __DIR__ . '/api/media.php';
        exit;

    case '/api/inventory':
        require __DIR__ . '/api/inventory.php';
        exit;

    case '/api/housekeeping':
        require __DIR__ . '/api/housekeeping.php';
        exit;

    case '/api/walkin':
        require __DIR__ . '/api/walkin.php';
        exit;

    case '/api/night_audit':
        require __DIR__ . '/api/night_audit.php';
        exit;

    case '/api/reports':
        require __DIR__ . '/api/reports.php';
        exit;

    case '/api/mpesa':
        require __DIR__ . '/api/mpesa.php';
        exit;

    case '/api/mpesa_callback':
        require __DIR__ . '/api/mpesa_callback.php';
        exit;

    case '/api/settings':
        require __DIR__ . '/api/settings.php';
        exit;

    case '/api/upload_media':
        require __DIR__ . '/api/upload_media.php';
        exit;
}

/*
|--------------------------------------------------------------------------
| STATIC FILES
|--------------------------------------------------------------------------
*/

$publicDir = __DIR__ . '/public';

if ($requestUri === '/') {
    $file = $publicDir . '/index.html';
} else {
    $file = realpath($publicDir . $requestUri);
}

/*
|--------------------------------------------------------------------------
| SECURITY CHECK
|--------------------------------------------------------------------------
*/

if ($file !== false &&
    file_exists($file) &&
    strpos($file, realpath($publicDir)) === 0) {

    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    $mimeTypes = [
        'html'  => 'text/html',
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'json'  => 'application/json',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'webp'  => 'image/webp',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2'
    ];

    header(
        'Content-Type: ' .
        ($mimeTypes[$extension] ?? 'application/octet-stream')
    );

    readfile($file);
    exit;
}

/*
|--------------------------------------------------------------------------
| 404 PAGE
|--------------------------------------------------------------------------
*/

http_response_code(404);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>404 - Page Not Found</title>
    <style>
        body {
            font-family: Arial;
            background: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,.2);
            text-align: center;
        }
        h1 {
            font-size: 60px;
            margin: 0;
            color: #e74c3c;
        }
        p { color: #555; }
        a {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 20px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        a:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class="box">
        <h1>404</h1>
        <p>The page you requested does not exist.</p>
        <a href="/">Return Home</a>
    </div>
</body>
</html>
