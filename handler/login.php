<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json');

// Make sure nothing is echoed before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../db/connection.php'; // <-- fixed path

const ADMIN_LANDING = 'admin/';
const USER_LANDING  = 'index.php';

$response = ['success' => false, 'message' => '', 'redirect' => null];

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new RuntimeException('Invalid request method.');
  }

  $user_input = trim($_POST['user_input'] ?? '');
  $password   = (string)($_POST['password'] ?? '');

  if ($user_input === '' || $password === '') {
    throw new RuntimeException('Please fill in all fields.');
  }

  $sql = filter_var($user_input, FILTER_VALIDATE_EMAIL)
    ? "SELECT user_id, username, email, password, role FROM users WHERE email = ? LIMIT 1"
    : "SELECT user_id, username, email, password, role FROM users WHERE username = ? LIMIT 1";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $user_input);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || !password_verify($password, $user['password'])) {
    throw new RuntimeException('Incorrect password or Username/Email.');
  }

  session_regenerate_id(true);
  $_SESSION['user_id']  = (int)$user['user_id'];
  $_SESSION['username'] = $user['username'];
  $_SESSION['role']     = $user['role'];

  $response['success']  = true;
  $response['redirect'] = ($user['role'] === 'admin') ? ADMIN_LANDING : USER_LANDING;

} catch (Throwable $e) {
  $response['success'] = false;
  $response['message'] = $e->getMessage();
} finally {
  if (isset($conn) && $conn instanceof mysqli) $conn->close();
  // If anything was printed, log and clear it so the client gets pure JSON
  if (ob_get_length()) { error_log('login.php stray output: '. ob_get_contents()); ob_end_clean(); }
}

echo json_encode($response);
exit;
