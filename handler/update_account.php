<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!isset($_SESSION['user_id'])) {
  header('Location: ../index.php'); exit;
}

require_once __DIR__ . '/../db/connection.php';

$uid = (int)$_SESSION['user_id'];

$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email'] ?? '');
$curr     = (string)($_POST['current_password'] ?? '');
$new      = (string)($_POST['new_password'] ?? '');

try {
  if (!($conn instanceof mysqli)) throw new RuntimeException('DB unavailable');
  if ($username === '' || $email === '') throw new RuntimeException('Username and email are required.');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email.');

  // Uniqueness (exclude self)
  $q = $conn->prepare("SELECT user_id FROM users WHERE (username=? OR email=?) AND user_id <> ? LIMIT 1");
  $q->bind_param('ssi', $username, $email, $uid);
  $q->execute();
  $exists = $q->get_result()->fetch_assoc();
  $q->close();
  if ($exists) throw new RuntimeException('Username or email already in use.');

  // Update base fields
  $u = $conn->prepare("UPDATE users SET username=?, email=? WHERE user_id=?");
  $u->bind_param('ssi', $username, $email, $uid);
  $u->execute();
  $u->close();

  // Optional password change
  if ($curr !== '' || $new !== '') {
    if ($curr === '' || $new === '') throw new RuntimeException('Provide both current and new password.');
    // fetch current hash
    $s = $conn->prepare("SELECT password FROM users WHERE user_id=? LIMIT 1");
    $s->bind_param('i', $uid);
    $s->execute();
    $hash = ($s->get_result()->fetch_assoc()['password'] ?? '');
    $s->close();

    if (!$hash || !password_verify($curr, $hash)) throw new RuntimeException('Current password is incorrect.');
    if (strlen($new) < 6) throw new RuntimeException('New password must be at least 6 characters.');
    $newHash = password_hash($new, PASSWORD_DEFAULT);

    $p = $conn->prepare("UPDATE users SET password=? WHERE user_id=?");
    $p->bind_param('si', $newHash, $uid);
    $p->execute();
    $p->close();
  }

  $_SESSION['username'] = $username;
  $_SESSION['success'] = 'Account updated successfully.';
} catch (Throwable $e) {
  $_SESSION['error'] = $e->getMessage();
} finally {
  if ($conn instanceof mysqli) $conn->close();
}

header('Location: ../profile.php'); // back to profile
exit;
