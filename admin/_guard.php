<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: /index.php');  // adjust if your root is a subfolder
  exit;
}
