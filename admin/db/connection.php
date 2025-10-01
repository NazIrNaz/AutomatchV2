<?php
$host = "127.0.0.1";   
$user = "root";       
$pass = "@dmin321";            
$db   = "fypbetatest";

$connection = new mysqli($host, $user, $pass, $db);

// check connection
if ($connection->connect_error) {
    die("Database connection failed: " . $connection->connect_error);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = $connection;
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    // Don't echo; let callers handle the error
    error_log('DB connect failed: ' . $e->getMessage());
    $conn = null;
}

$connection->set_charset("utf8mb4");
?>
