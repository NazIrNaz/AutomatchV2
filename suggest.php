<?php
// suggest.php
header('Content-Type: application/json; charset=utf-8');

require_once "db/connection.php";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { echo json_encode([]); exit; }

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q === '' || mb_strlen($q) < 2) { echo json_encode([]); exit; }

// Build one UNION query: brand, model, name (deduped)
$sql = "
  (SELECT DISTINCT brand  AS label, 'brand'  AS type FROM vehicle_data WHERE brand  LIKE CONCAT(?, '%') AND brand <> '' LIMIT 5)
  UNION ALL
  (SELECT DISTINCT model  AS label, 'model'  AS type FROM vehicle_data WHERE model  LIKE CONCAT(?, '%') AND model <> '' LIMIT 5)
  UNION ALL
  (SELECT DISTINCT name   AS label, 'name'   AS type FROM vehicle_data WHERE name   LIKE CONCAT(?, '%') AND name <> ''  LIMIT 5)
  LIMIT 10
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $q, $q, $q);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
$seen = [];
while ($row = $res->fetch_assoc()) {
  $key = strtolower($row['type'].'|'.$row['label']);
  if (isset($seen[$key])) continue;
  $seen[$key] = true;
  $out[] = $row; // {label, type}
}
$stmt->close();
echo json_encode($out);
