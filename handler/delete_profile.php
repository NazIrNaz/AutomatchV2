<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../index.php'); exit; }
require_once __DIR__ . '/../db/connection.php';
if (!isset($conn) && isset($connection)) $conn = $connection;

try {
  if (!($conn instanceof mysqli)) throw new RuntimeException('DB unavailable');

  $uid = (int)$_SESSION['user_id'];
  $pid = (int)($_POST['profile_id'] ?? 0);
  if ($pid > 0) {
    $stmt = $conn->prepare("DELETE FROM user_demographics WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $pid, $uid);
    $stmt->execute(); $stmt->close();
    $_SESSION['success'] = 'Profile deleted.';
  }
} catch (Throwable $e) {
  error_log('[delete_profile] ' . $e->getMessage());
  $_SESSION['error'] = 'Failed to delete profile.';
} finally {
  if ($conn instanceof mysqli) $conn->close();
}
header('Location: ../profile.php');
