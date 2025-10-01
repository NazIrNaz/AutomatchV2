<?php
require_once __DIR__.'/_guard.php';
require_once __DIR__.'/db/connection.php';
require_once __DIR__.'/_lib.php';

function table_exists($conn, $table) {
  $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
  $stmt->bind_param("s", $table);
  $stmt->execute();
  return (int)$stmt->get_result()->fetch_assoc()['c'] > 0;
}
function column_exists($conn, $table, $col) {
  $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
  $stmt->bind_param("ss", $table, $col);
  $stmt->execute();
  return (int)$stmt->get_result()->fetch_assoc()['c'] > 0;
}

/* ---------------- KPI counts ---------------- */
$counts=['users'=>0,'vehicles'=>0,'bookmarks'=>0];
if(table_exists($conn,'users')) {
  if($r=$conn->query("SELECT COUNT(*) c FROM users")) $counts['users']=(int)$r->fetch_assoc()['c'];
}
if(table_exists($conn,'vehicle_data')) {
  if($r=$conn->query("SELECT COUNT(*) c FROM vehicle_data")) $counts['vehicles']=(int)$r->fetch_assoc()['c'];
}
if(table_exists($conn,'bookmarks')) {
  if($r=$conn->query("SELECT COUNT(*) c FROM bookmarks")) $counts['bookmarks']=(int)$r->fetch_assoc()['c'];
}

/* ---------------- Trends & lists (best effort) ---------------- */
$charts = [
  'users7'     => ['labels'=>[], 'data'=>[], 'enabled'=>false],
  'vehicles30' => ['labels'=>[], 'data'=>[], 'enabled'=>false],
  'brandsTop'  => ['labels'=>[], 'data'=>[], 'enabled'=>false],
];

$recentVehicles = [];
$recentBookmarks = [];

/* Users 7d chart (needs users.created_at) */
if (table_exists($conn,'users') && column_exists($conn,'users','created_at')) {
  $sql = "SELECT DATE(created_at) d, COUNT(*) c
          FROM users
          WHERE created_at >= (CURDATE() - INTERVAL 6 DAY)
          GROUP BY DATE(created_at)
          ORDER BY d";
  $res = $conn->query($sql);
  $map = [];
  while($row=$res->fetch_assoc()) $map[$row['d']] = (int)$row['c'];
  // fill empty days
  for ($i=6; $i>=0; $i--){
    $d = date('Y-m-d', strtotime("-$i day"));
    $charts['users7']['labels'][] = date('M j', strtotime($d));
    $charts['users7']['data'][]   = $map[$d] ?? 0;
  }
  $charts['users7']['enabled'] = true;
}

/* Vehicles 30d chart (needs vehicle_data.created_at; if missing, skip) */
if (table_exists($conn,'vehicle_data') && column_exists($conn,'vehicle_data','created_at')) {
  $sql = "SELECT DATE(created_at) d, COUNT(*) c
          FROM vehicle_data
          WHERE created_at >= (CURDATE() - INTERVAL 29 DAY)
          GROUP BY DATE(created_at)
          ORDER BY d";
  $res = $conn->query($sql);
  $map = [];
  while($row=$res->fetch_assoc()) $map[$row['d']] = (int)$row['c'];
  for ($i=29; $i>=0; $i--){
    $d = date('Y-m-d', strtotime("-$i day"));
    $charts['vehicles30']['labels'][] = date('M j', strtotime($d));
    $charts['vehicles30']['data'][]   = $map[$d] ?? 0;
  }
  $charts['vehicles30']['enabled'] = true;
}

/* Top brands (group by brand if column exists) */
if (table_exists($conn,'vehicle_data') && column_exists($conn,'vehicle_data','brand')) {
  $res = $conn->query("SELECT brand, COUNT(*) c FROM vehicle_data WHERE brand IS NOT NULL AND brand<>'' GROUP BY brand ORDER BY c DESC LIMIT 8");
  while($row=$res->fetch_assoc()){
    $charts['brandsTop']['labels'][] = $row['brand'];
    $charts['brandsTop']['data'][]   = (int)$row['c'];
  }
  $charts['brandsTop']['enabled'] = count($charts['brandsTop']['labels'])>0;
}

/* Recent vehicles (best effort: order by created_at if exists, else ID desc) */
if (table_exists($conn,'vehicle_data')) {
  if (column_exists($conn,'vehicle_data','created_at')) {
    $res = $conn->query("SELECT vehicle_id, COALESCE(name, CONCAT(brand,' ',model)) AS label, brand, model, image_url, created_at
                         FROM vehicle_data ORDER BY created_at DESC LIMIT 8");
  } else {
    $res = $conn->query("SELECT vehicle_id, COALESCE(name, CONCAT(brand,' ',model)) AS label, brand, model, image_url
                         FROM vehicle_data ORDER BY vehicle_id DESC LIMIT 8");
  }
  while($row=$res->fetch_assoc()) $recentVehicles[]=$row;
}

/* Recent bookmarks (if table exists) */
if (table_exists($conn,'bookmarks') && table_exists($conn,'users') && table_exists($conn,'vehicle_data')) {
  $createdCol = column_exists($conn,'bookmarks','created_at') ? 'b.created_at' : 'NOW()';
  $res = $conn->query("SELECT $createdCol AS created_at, u.username,
                              COALESCE(v.name, CONCAT(v.brand,' ',v.model)) AS label,
                              v.vehicle_id
                       FROM bookmarks b
                       JOIN users u ON u.user_id=b.user_id
                       JOIN vehicle_data v ON v.vehicle_id=b.vehicle_id
                       ORDER BY $createdCol DESC
                       LIMIT 10");
  while($row=$res->fetch_assoc()) $recentBookmarks[]=$row;
}

/* API + DB status */
$apiLabel = api_status_ping("http://127.0.0.1:5000/health", 2);
$apiOk    = (strpos($apiLabel, 'Online') !== false);
$dbOk     = true; // if you can run queries above, DB is up
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Automatch Admin — Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="bg-gray-50 text-gray-900">
<?php include __DIR__.'/sidebar.php'; ?>

<main class="ml-64 p-6">
  <div class="flex items-start justify-between">
    <div>
      <h1 class="text-2xl font-bold">Dashboard</h1>
      <p class="text-gray-600 mt-1">Quick overview of your platform.</p>
    </div>
    <div class="hidden md:flex items-center gap-2">
      <a href="users.php" class="px-3 py-2 rounded-lg border bg-white">Manage Users</a>
      <a href="vehicles.php" class="px-3 py-2 rounded-lg bg-indigo-600 text-white">+ Add Vehicle</a>
    </div>
  </div>

  <!-- KPI cards -->
  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
    <div class="rounded-xl border bg-white p-5 shadow-sm">
      <div class="text-sm text-gray-500">Total Users</div>
      <div class="mt-1 text-3xl font-semibold"><?= number_format($counts['users']); ?></div>
    </div>
    <div class="rounded-xl border bg-white p-5 shadow-sm">
      <div class="text-sm text-gray-500">Vehicles in DB</div>
      <div class="mt-1 text-3xl font-semibold"><?= number_format($counts['vehicles']); ?></div>
    </div>
    <div class="rounded-xl border bg-white p-5 shadow-sm">
      <div class="text-sm text-gray-500">Total Bookmarks</div>
      <div class="mt-1 text-3xl font-semibold"><?= number_format($counts['bookmarks']); ?></div>
    </div>
    <div class="rounded-xl border bg-white p-5 shadow-sm">
      <div class="text-sm text-gray-500">API / DB</div>
      <div class="mt-2 flex items-center gap-3">
        <div class="inline-flex items-center gap-1">
          <span id="apiDot" class="h-2.5 w-2.5 rounded-full <?= $apiOk ? 'bg-green-500' : 'bg-red-500' ?>"></span>
          <span id="apiLabel" class="font-medium"><?= htmlspecialchars($apiLabel, ENT_QUOTES, 'UTF-8'); ?></span>
          <span id="apiLatency" class="text-xs text-gray-500"></span>
        </div>
        <span class="text-gray-300">|</span>
        <div class="inline-flex items-center gap-1">
          <span class="h-2.5 w-2.5 rounded-full <?= $dbOk ? 'bg-green-500' : 'bg-red-500' ?>"></span>
          <span class="font-medium"><?= $dbOk ? 'DB Online' : 'DB Error' ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="grid lg:grid-cols-3 gap-4 mt-6">
    <div class="lg:col-span-2 rounded-xl border bg-white p-5">
      <div class="flex items-center justify-between">
        <h2 class="font-semibold">New Users (last 7 days)</h2>
      </div>
      <?php if($charts['users7']['enabled']): ?>
        <canvas id="users7" height="120"></canvas>
      <?php else: ?>
        <p class="text-sm text-gray-500">No timestamp column <code>users.created_at</code>, chart hidden.</p>
      <?php endif; ?>
    </div>

    <div class="rounded-xl border bg-white p-5">
      <h2 class="font-semibold">Total Registered Cars in Database</h2>
      <?php if($charts['brandsTop']['enabled']): ?>
        <canvas id="brandsTop" height="120"></canvas>
      <?php else: ?>
        <p class="text-sm text-gray-500">Not enough brand data to plot.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid lg:grid-cols-3 gap-4 mt-4">
    <div class="rounded-xl border bg-white p-5">
      <h2 class="font-semibold">Vehicles Added (last 30 days)</h2>
      <?php if($charts['vehicles30']['enabled']): ?>
        <canvas id="vehicles30" height="120"></canvas>
      <?php else: ?>
        <p class="text-sm text-gray-500">No timestamp column <code>vehicle_data.created_at</code>, chart hidden.</p>
      <?php endif; ?>
    </div>

    <div class="rounded-xl border bg-white p-5 lg:col-span-2">
      <h2 class="font-semibold mb-2">Recent Vehicles</h2>
      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <?php foreach($recentVehicles as $v): ?>
          <div class="rounded-lg border bg-white p-3 flex gap-3">
            <img src="<?= esc($v['image_url'] ?? '') ?>" class="h-14 w-20 object-cover rounded" alt="">
            <div class="min-w-0">
              <div class="font-medium truncate"><?= esc($v['label'] ?? '') ?></div>
              <div class="text-sm text-gray-500 truncate"><?= esc(($v['brand'] ?? '').' '.($v['model'] ?? '')) ?></div>
              <div class="text-xs text-gray-400">#<?= (int)$v['vehicle_id'] ?></div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if(!$recentVehicles): ?><p class="text-sm text-gray-500">No vehicles yet.</p><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent bookmarks -->
  <div class="rounded-xl border bg-white p-5 mt-4">
    <h2 class="font-semibold mb-2">Recent Bookmarks</h2>
    <?php if($recentBookmarks): ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-100">
            <tr>
              <th class="px-3 py-2 text-left">Time</th>
              <th class="px-3 py-2 text-left">User</th>
              <th class="px-3 py-2 text-left">Vehicle</th>
              <th class="px-3 py-2 text-left">ID</th>
            </tr>
          </thead>
          <tbody class="bg-white">
            <?php foreach($recentBookmarks as $b): ?>
              <tr class="border-b">
                <td class="px-3 py-2"><?= esc($b['created_at'] ?? '') ?></td>
                <td class="px-3 py-2"><?= esc($b['username'] ?? '') ?></td>
                <td class="px-3 py-2"><?= esc($b['label'] ?? '') ?></td>
                <td class="px-3 py-2">#<?= (int)($b['vehicle_id'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-sm text-gray-500">No bookmarks recorded.</p>
    <?php endif; ?>
  </div>
</main>

<!-- Live API status updater (polls /admin/api_health.php) -->
<script>
const HEALTH_URL = 'api_health.php'; // returns { ok, label, latency_ms }
let inFlight = false;
function setApiUI(ok, label, latency) {
  const dot   = document.getElementById('apiDot');
  const lab   = document.getElementById('apiLabel');
  const laten = document.getElementById('apiLatency');
  dot.className = 'h-2.5 w-2.5 rounded-full ' + (ok ? 'bg-green-500' : 'bg-red-500');
  lab.textContent = label || (ok ? '🟢 Online' : '🔴 Offline');
  laten.textContent = (latency != null) ? `(${latency} ms)` : '';
}
async function checkHealth(){
  if (inFlight) return; inFlight = true;
  try {
    const ctrl = new AbortController();
    const t = setTimeout(()=>ctrl.abort(), 2500);
    const res = await fetch(HEALTH_URL, { signal: ctrl.signal, cache: 'no-store' });
    clearTimeout(t);
    const j = await res.json();
    setApiUI(!!j.ok, j.label, j.latency_ms);
  } catch (e) {
    setApiUI(false, '🔴 Offline', null);
  } finally { inFlight = false; }
}
checkHealth(); setInterval(checkHealth, 5000);
document.addEventListener('visibilitychange', ()=>{ if(!document.hidden) checkHealth(); });

/* -------- Charts -------- */
function makeLine(id, labels, data, title){
  const el = document.getElementById(id); if(!el) return;
  new Chart(el, {
    type: 'line',
    data: { labels: labels, datasets: [{ label: title, data: data, tension: 0.3, fill: false }] },
    options: { responsive:true, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true } } }
  });
}
function makeBar(id, labels, data, title){
  const el = document.getElementById(id); if(!el) return;
  new Chart(el, {
    type: 'bar',
    data: { labels: labels, datasets: [{ label: title, data: data }] },
    options: { responsive:true, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } }
  });
}
<?php if($charts['users7']['enabled']): ?>
makeLine('users7', <?= json_encode($charts['users7']['labels']) ?>, <?= json_encode($charts['users7']['data']) ?>, 'Users');
<?php endif; ?>
<?php if($charts['vehicles30']['enabled']): ?>
makeLine('vehicles30', <?= json_encode($charts['vehicles30']['labels']) ?>, <?= json_encode($charts['vehicles30']['data']) ?>, 'Vehicles');
<?php endif; ?>
<?php if($charts['brandsTop']['enabled']): ?>
makeBar('brandsTop', <?= json_encode($charts['brandsTop']['labels']) ?>, <?= json_encode($charts['brandsTop']['data']) ?>, 'Vehicles');
<?php endif; ?>
</script>
</body>
</html>
