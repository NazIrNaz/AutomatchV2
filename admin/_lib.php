<?php
// admin/_lib.php
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function api_status_info($url="http://127.0.0.1:5000/health", $timeout=2){
  $start = microtime(true);
  $ch = curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>$timeout,
    CURLOPT_CONNECTTIMEOUT=>$timeout,
  ]);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);

  $ok = $res && (stripos($res,'ok')!==false || stripos($res,'healthy')!==false);
  return [
    'ok'         => $ok,
    'label'      => $ok ? '🟢 Online' : '🔴 Offline',
    'latency_ms' => (int)round((microtime(true)-$start)*1000),
    'error'      => $ok ? null : ($err ?: 'no-ok-in-body'),
  ];
}

// Backward compatible: keep api_status_ping()
function api_status_ping($url="http://127.0.0.1:5000/health", $timeout=2){
  $i = api_status_info($url,$timeout);
  return $i['label'];
}

/** Return available vehicle columns (intersects with allowed list) */
function vehicle_columns($conn){
  static $cache=null;
  if($cache!==null) return $cache;
  $allowed = ['name','brand','model','variant','year','body_type','fuel_type','transmission','seats','price','image_url'];
  $cols=[];
  $db = $conn->query("SELECT DATABASE() AS d")->fetch_assoc()['d'];
  $q = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='vehicle_data'");
  $q->bind_param("s",$db);
  if($q->execute()){
    $r=$q->get_result();
    while($row=$r->fetch_assoc()){
      $c=strtolower($row['COLUMN_NAME']);
      if(in_array($c,$allowed)) $cols[]=$c;
    }
  }
  $cache = $cols;
  return $cache;
}

function build_insert_sql($cols){
  $fields = implode(',', $cols);
  $place  = implode(',', array_fill(0,count($cols),'?'));
  return "INSERT INTO vehicle_data ($fields) VALUES ($place)";
}

function build_update_sql($cols){
  $pairs = implode(',', array_map(function($c){ return "$c=?"; }, $cols));
  return "UPDATE vehicle_data SET $pairs WHERE id=?";
}
