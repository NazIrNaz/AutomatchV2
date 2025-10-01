<?php
// bookmark.php  — toggle (add/remove) a bookmark for the current user
// Always returns JSON, no stray output.

session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);   // don't leak notices into JSON
ob_start();                     // catch any accidental output

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  $out = json_encode(['error' => true, 'message' => 'Login required']);
  ob_end_clean();
  echo $out;
  exit;
}

$vehicleId = (int)($_POST['vehicle_id'] ?? 0);
if ($vehicleId <= 0) {
  http_response_code(400);
  $out = json_encode(['error' => true, 'message' => 'Invalid vehicle id']);
  ob_end_clean();
  echo $out;
  exit;
}

require_once "db/connection.php";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
  http_response_code(500);
  $out = json_encode(['error' => true, 'message' => 'DB error']);
  ob_end_clean();
  echo $out;
  exit;
}

function toggle_bookmark(mysqli $conn, int $userId, int $vehicleId): array {
  $conn->begin_transaction();
  try {
    // check
    $q = $conn->prepare("SELECT 1 FROM bookmarks WHERE user_id=? AND vehicle_id=? LIMIT 1");
    $q->bind_param("ii", $userId, $vehicleId);
    $q->execute();
    $has = (bool)$q->get_result()->fetch_row();
    $q->close();

    if ($has) {
      // remove
      $del = $conn->prepare("DELETE FROM bookmarks WHERE user_id=? AND vehicle_id=?");
      $del->bind_param("ii", $userId, $vehicleId);
      $del->execute();
      $del->close();

      // decrement aggregate
      $dec = $conn->prepare(
        "INSERT INTO analytics (vehicle_id, total_bookmarks, last_updated)
         VALUES (?, 0, NOW())
         ON DUPLICATE KEY UPDATE
           total_bookmarks = GREATEST(total_bookmarks - 1, 0),
           last_updated = NOW()"
      );
      $dec->bind_param("i", $vehicleId);
      $dec->execute();
      $dec->close();

      // fresh count
      $c = $conn->prepare("SELECT COUNT(*) AS c FROM bookmarks WHERE vehicle_id=?");
      $c->bind_param("i", $vehicleId);
      $c->execute();
      $total = (int)$c->get_result()->fetch_assoc()['c'];
      $c->close();

      $conn->commit();
      return ['bookmarked' => false, 'total_bookmarks' => $total];
    } else {
      // add
      $ins = $conn->prepare("INSERT INTO bookmarks (user_id, vehicle_id) VALUES (?, ?)");
      $ins->bind_param("ii", $userId, $vehicleId);
      $ins->execute();
      $ins->close();

      // increment aggregate
      $inc = $conn->prepare(
        "INSERT INTO analytics (vehicle_id, total_bookmarks, last_updated)
         VALUES (?, 1, NOW())
         ON DUPLICATE KEY UPDATE
           total_bookmarks = total_bookmarks + 1,
           last_updated = NOW()"
      );
      $inc->bind_param("i", $vehicleId);
      $inc->execute();
      $inc->close();

      // fresh count
      $c = $conn->prepare("SELECT COUNT(*) AS c FROM bookmarks WHERE vehicle_id=?");
      $c->bind_param("i", $vehicleId);
      $c->execute();
      $total = (int)$c->get_result()->fetch_assoc()['c'];
      $c->close();

      $conn->commit();
      return ['bookmarked' => true, 'total_bookmarks' => $total];
    }
  } catch (Throwable $e) {
    $conn->rollback();
    return ['error' => true, 'message' => $e->getMessage()];
  }
}

// Build JSON safely, then flush only JSON
$out = json_encode(toggle_bookmark($conn, (int)$_SESSION['user_id'], $vehicleId));
ob_end_clean();
echo $out;
exit;
