<?php
// /admin/api_health.php
require_once __DIR__.'/_guard.php';
require_once __DIR__.'/_lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode(api_status_info("http://127.0.0.1:5000/health", 2));
