<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../index.php'); exit; }
require_once __DIR__ . '/../db/connection.php';
if (!isset($conn) && isset($connection)) $conn = $connection;

function hasColumn(mysqli $conn, $table, $col){
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param('ss', $table, $col);
  $st->execute(); $st->store_result(); $ok = $st->num_rows > 0; $st->close(); return $ok;
}

try {
  if (!($conn instanceof mysqli)) throw new RuntimeException('DB unavailable');

  $uid   = (int)$_SESSION['user_id'];
  $pid   = (int)($_POST['profile_id'] ?? 0);
  $name  = trim($_POST['profile_name'] ?? '');
  $income= (int)($_POST['monthly_income'] ?? 0);
  $exp   = (int)($_POST['monthly_expenses'] ?? 0);
  $loans = (int)($_POST['existing_loans'] ?? 0);
  $fam   = (int)($_POST['family_members'] ?? 0);
  $child = (int)($_POST['children'] ?? 0);

  $brandsArr = $_POST['preferred_brands'] ?? [];
  if (!is_array($brandsArr)) $brandsArr = [];
  $brandsJson = json_encode(array_values(array_unique($brandsArr)));

  $typesArr = $_POST['car_types'] ?? [];
  if (!is_array($typesArr)) $typesArr = [];
  $typesArr = array_values(array_unique(array_filter(array_map('trim',$typesArr))));
  $typesCsv = implode(',', $typesArr);
  $typesJson= json_encode($typesArr);

  $hasCarTypes = hasColumn($conn, 'user_demographics', 'car_types');

  if ($pid > 0) {
    $sql = "UPDATE user_demographics
            SET profile_name=?, monthly_income=?, monthly_expenses=?, existing_loans=?, family_members=?, children=?, preferred_brands=?, car_type=?"
            . ($hasCarTypes ? ", car_types=?" : "") .
            " WHERE id=? AND user_id=? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($hasCarTypes) {
      $stmt->bind_param('siiiiisssii', $name,$income,$exp,$loans,$fam,$child,$brandsJson,$typesCsv,$typesJson,$pid,$uid);
    } else {
      $stmt->bind_param('siiiiisssii', $name,$income,$exp,$loans,$fam,$child,$brandsJson,$typesCsv/* JSON slot */,/* dummy */$typesJson,$pid,$uid);
    }
    $stmt->execute(); $stmt->close();
    $_SESSION['success'] = 'Profile updated.';
  } else {
    $sql = "INSERT INTO user_demographics
            (user_id, profile_name, monthly_income, monthly_expenses, existing_loans, family_members, children, preferred_brands, car_type"
            . ($hasCarTypes ? ", car_types" : "") . ")
            VALUES (?,?,?,?,?,?,?,?,?" . ($hasCarTypes ? ",?" : "") . ")";
    $stmt = $conn->prepare($sql);
    if ($hasCarTypes) {
      $stmt->bind_param('isiiiiisss', $uid,$name,$income,$exp,$loans,$fam,$child,$brandsJson,$typesCsv,$typesJson);
    } else {
      $stmt->bind_param('isiiiiisss', $uid,$name,$income,$exp,$loans,$fam,$child,$brandsJson,$typesCsv,/* dummy */$typesJson);
    }
    $stmt->execute(); $stmt->close();
    $_SESSION['success'] = 'Profile saved.';
  }
} catch (Throwable $e) {
  error_log('[save_demographic] ' . $e->getMessage());
  $_SESSION['error'] = 'Failed to save profile.';
} finally {
  if ($conn instanceof mysqli) $conn->close();
}
header('Location: ../profile.php');
