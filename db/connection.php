<?php
// connection.php
declare(strict_types=1);

$host = "127.0.0.1";
$user = "root";
$pass = "@dmin321";
$db   = "fypbetatest";

// Make mysqli throw exceptions for any error (connect + queries)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');

    // Backward-compat alias for older code
    $connection = $conn;
} catch (Throwable $e) {
    error_log('DB connect failed: ' . $e->getMessage());
    // Re-throw so callers can show a friendly flash message
    throw $e;
}
