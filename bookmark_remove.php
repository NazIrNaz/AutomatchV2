<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>true,'message'=>'Login required']); exit; }

$vehicleId = (int)($_POST['vehicle_id'] ?? 0);
if ($vehicleId <= 0) { http_response_code(400); echo json_encode(['error'=>true,'message'=>'Invalid vehicle id']); exit; }

require_once "db/connection.php";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { http_response_code(500); echo json_encode(['error'=>true,'message'=>'DB error']); exit; }

$userId = (int)$_SESSION['user_id'];

$conn->begin_transaction();
try {
    // delete if exists
    $del = $conn->prepare("DELETE FROM bookmarks WHERE user_id=? AND vehicle_id=?");
    $del->bind_param("ii", $userId, $vehicleId);
    $del->execute();
    $affected = $del->affected_rows; // 1 if it removed, 0 if nothing to remove
    $del->close();

    // update analytics aggregate safely
    $upd = $conn->prepare(
      "INSERT INTO analytics (vehicle_id, total_bookmarks, last_updated)
       VALUES (?, 0, NOW())
       ON DUPLICATE KEY UPDATE total_bookmarks = GREATEST(total_bookmarks - ?, 0), last_updated = NOW()"
    );
    $upd->bind_param("ii", $vehicleId, $affected);
    $upd->execute();
    $upd->close();

    // fresh count to return
    $c = $conn->prepare("SELECT COUNT(*) AS c FROM bookmarks WHERE vehicle_id=?");
    $c->bind_param("i", $vehicleId);
    $c->execute();
    $total = (int)$c->get_result()->fetch_assoc()['c'];
    $c->close();

    $conn->commit();
    echo json_encode(['removed' => (bool)$affected, 'total_bookmarks' => $total]);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['error'=>true,'message'=>'Failed to remove']);
}
