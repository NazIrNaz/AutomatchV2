<?php
// admin/suggest.php
require_once __DIR__.'/_guard.php';
require_once __DIR__.'/db/connection.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$q = trim($_GET['q'] ?? '');
if ($q === '' || mb_strlen($q) < 2) { echo json_encode([]); exit; }

$like = "%$q%";

/* We only suggest from columns that exist */
$cols = [];
$res = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vehicle_data'");
while ($r = $res->fetch_assoc()) $cols[] = strtolower($r['COLUMN_NAME']);

$out = [];
$limitEach = 5;

if (in_array('brand',$cols,true)) {
  $st = $conn->prepare("SELECT DISTINCT brand AS v FROM vehicle_data WHERE brand LIKE ? ORDER BY brand LIMIT ?");
  $st->bind_param("si",$like,$limitEach);
  $st->execute();
  $rs = $st->get_result();
  while($row=$rs->fetch_assoc()) $out[] = ['label'=>$row['v'], 'type'=>'brand'];
}

if (in_array('model',$cols,true)) {
  $st = $conn->prepare("SELECT DISTINCT model AS v FROM vehicle_data WHERE model LIKE ? ORDER BY model LIMIT ?");
  $st->bind_param("si",$like,$limitEach);
  $st->execute();
  $rs = $st->get_result();
  while($row=$rs->fetch_assoc()) $out[] = ['label'=>$row['v'], 'type'=>'model'];
}

if (in_array('name',$cols,true)) {
  $st = $conn->prepare("SELECT name AS v FROM vehicle_data WHERE name LIKE ? ORDER BY name LIMIT ?");
  $st->bind_param("si",$like,$limitEach);
  $st->execute();
  $rs = $st->get_result();
  while($row=$rs->fetch_assoc()) $out[] = ['label'=>$row['v'], 'type'=>'name'];
}

echo json_encode($out);
