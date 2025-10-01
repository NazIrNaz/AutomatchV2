<?php
session_start();
require_once "db/connection.php";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); die("Invalid id"); }

/* ---------------- Helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function to_s8x2($url) {
  if (!$url) return '';
  $url = preg_replace('#/s4(?=[\-/])#i', '/s8x2', $url, 1);
  $url = preg_replace('#-s4(?=[.\-_/?]|$)#i', '-s8x2', $url, 1);
  $url = preg_replace('#([?&])s=4(\b|&|$)#i', '$1s=s8x2$2', $url, 1);
  return $url;
}

/* ---------------- Schema checks ---------------- */
$vdCols = [];
$res = $conn->query("
  SELECT COLUMN_NAME
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicle_data'
");
if ($res) while ($r = $res->fetch_assoc()) $vdCols[$r['COLUMN_NAME']] = true;
$hasCol = function($c) use ($vdCols){ return isset($vdCols[$c]); };
$hasPriceTbl = (bool)$conn->query("SHOW TABLES LIKE 'vehicle_price'")->num_rows;
$hasCfgTbl   = (bool)$conn->query("SHOW TABLES LIKE 'vehicle_configurations'")->num_rows;

/* ---------------- Vehicle row ---------------- */
$select = [
  "v.vehicle_id",
  ($hasCol('name')       ? "v.name"       : "NULL AS name"),
  ($hasCol('brand')      ? "v.brand"      : "NULL AS brand"),
  ($hasCol('model')      ? "v.model"      : "NULL AS model"),
  ($hasCol('year')       ? "v.year"       : "NULL AS year"),
  ($hasCol('image_url')  ? "v.image_url"  : "NULL AS image_url"),
  ($hasCol('body_type')  ? "v.body_type"  : "NULL AS body_type"),
  ($hasCol('seats')      ? "v.seats"      : "NULL AS seats"),
  ($hasCol('engine')     ? "v.engine"     : "NULL AS engine"),
  ($hasCol('drivetrain') ? "v.drivetrain" : "NULL AS drivetrain")
];
if ($hasPriceTbl) $select[] = "vp.price, vp.currency";
if ($hasCfgTbl)   $select[] = "vc.vehicle_configuration";

$sql = "SELECT ".implode(", ", $select)."
FROM vehicle_data v
".($hasPriceTbl ? "LEFT JOIN vehicle_price vp ON v.vehicle_id = vp.vehicle_id\n" : "").
  ($hasCfgTbl   ? "LEFT JOIN vehicle_configurations vc ON v.vehicle_id = vc.vehicle_id\n" : "")."
WHERE v.vehicle_id = ?
LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$car = $res->fetch_assoc();
$stmt->close();
if (!$car) { http_response_code(404); die("Car not found"); }

/* ---------------- All specs (EAV) ---------------- */
$specs = []; $flat = [];
$specStmt = $conn->prepare("
  SELECT category, spec_key, spec_value
  FROM vehicle_specifications
  WHERE vehicle_id = ?
");
$specStmt->bind_param("i", $id);
$specStmt->execute();
$specRes = $specStmt->get_result();
while ($row = $specRes->fetch_assoc()) {
  $cat = (string)$row['category'];
  $key = (string)$row['spec_key'];
  $val = (string)$row['spec_value'];
  if (!isset($specs[$cat])) $specs[$cat] = [];
  $specs[$cat][$key] = $val;
  $flat[strtolower(trim($key))] = $val;
}
$specStmt->close();

/* ---------------- Derive overview fields ---------------- */
function firstSpec($flat, $keys) {
  foreach ($keys as $k) {
    $lk = strtolower($k);
    if (isset($flat[$lk]) && $flat[$lk] !== '') return $flat[$lk];
  }
  return null;
}

$brand  = $car['brand'] ?? '';
$name   = $car['name'] ?? '';
$model  = $car['model'] ?? $name;
$year   = $car['year'] ?? '';
$body   = $car['body_type'] ?? '';
$cfg    = $hasCfgTbl ? ($car['vehicle_configuration'] ?? '') : '';

$seats = $car['seats'];
if ($seats === null) $seats = firstSpec($flat, ['seats','seating capacity','seat capacity']);

$engine = $car['engine'];
if ($engine === null) $engine = firstSpec($flat, ['engine','engine type','engine capacity','engine displacement']);

$drivetrain = $car['drivetrain'];
if ($drivetrain === null) $drivetrain = firstSpec($flat, ['driveline','drive line','drive type','drivetrain']);

$transmission = firstSpec($flat, ['transmission name','transmission','gearbox','type']);
$fuel         = firstSpec($flat, ['fuel type','fuel']);

/* ---------------- Images & Price ---------------- */
$rawImg  = $car['image_url'] ?: '';
$mainImg = $rawImg ? to_s8x2($rawImg) : 'https://placehold.co/1200x700?text=No+Image';

$priceTxt = ($hasPriceTbl && $car['price'] !== null)
  ? (($car['currency'] ?: 'MYR') . " " . number_format((float)$car['price'], 0))
  : "—";

/* ---------------- Related cars ---------------- */
$relatedSql = "
  SELECT v.vehicle_id,
         ".($hasCol('name') ? "v.name" : "NULL AS name").",
         ".($hasCol('brand') ? "v.brand" : "NULL AS brand").",
         ".($hasCol('year') ? "v.year" : "NULL AS year").",
         ".($hasCol('image_url') ? "v.image_url" : "NULL AS image_url")."
         ".($hasPriceTbl ? ", vp.price, vp.currency" : "")."
  FROM vehicle_data v
  ".($hasPriceTbl ? "LEFT JOIN vehicle_price vp ON v.vehicle_id = vp.vehicle_id\n" : "")."
  WHERE v.vehicle_id <> ?
    AND v.brand = ?
";
$types = "is";
$params = [$id, $brand];
if ($hasCol('body_type') && $body !== '') { $relatedSql .= " AND v.body_type = ? "; $types .= "s"; $params[] = $body; }
$relatedSql .= " ORDER BY ".($hasCol('created_at') ? "v.created_at" : "v.vehicle_id")." DESC LIMIT 6";
$st = $conn->prepare($relatedSql);
$st->bind_param($types, ...$params);
$st->execute();
$rel = $st->get_result();
$related = [];
while ($x = $rel->fetch_assoc()) $related[] = $x;
$st->close();

/* ---------------- Analytics visit ---------------- */
function analytics_visit(mysqli $conn, int $vehicleId): void {
  $sql = "INSERT INTO analytics (vehicle_id, total_visits, last_updated)
          VALUES (?, 1, NOW())
          ON DUPLICATE KEY UPDATE total_visits = total_visits + 1, last_updated = NOW()";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("i", $vehicleId);
  $stmt->execute();
  $stmt->close();
}
if ($id > 0) analytics_visit($conn, $id);

/* ---------------- Bookmark state & count ---------------- */
$bmCount = 0; $isBookmarked = false;
$cnt = $conn->prepare("SELECT COUNT(*) AS c FROM bookmarks WHERE vehicle_id=?");
$cnt->bind_param("i", $id);
$cnt->execute();
$bmCount = (int)$cnt->get_result()->fetch_assoc()['c'];
$cnt->close();

if (isset($_SESSION['user_id'])) {
  $chk = $conn->prepare("SELECT 1 FROM bookmarks WHERE user_id=? AND vehicle_id=? LIMIT 1");
  $chk->bind_param("ii", $_SESSION['user_id'], $id);
  $chk->execute();
  $isBookmarked = (bool)$chk->get_result()->fetch_row();
  $chk->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?=h(trim(($brand ? $brand.' ' : '').$name))?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white text-slate-900">
<?php
if (file_exists(__DIR__ . '/includes/navbar.php')) include __DIR__ . '/includes/navbar.php';
elseif (file_exists(__DIR__ . '/navbar.php')) include __DIR__ . '/navbar.php';
?>
<div class="h-16 md:h-20"></div>

<header class="sticky top-16 md:top-20 z-20 bg-white/90 backdrop-blur border-b border-gray-200">
  <div class="max-w-6xl mx-auto px-4 py-4 flex items-center gap-4">
    <a href="browse.php" class="px-3 py-2 rounded-xl border border-gray-300 hover:bg-gray-50">← Back</a>
    <h1 class="text-xl font-semibold"><?=h(trim(($brand ? $brand.' ' : '').$name))?></h1>
    <span class="ml-auto text-sm text-gray-600"><?=h($year ?: '—')?></span>
  </div>
</header>

<main class="max-w-6xl mx-auto px-4 py-6 space-y-6">
  <div class="rounded-2xl overflow-hidden border border-gray-200">
    <img src="<?=h($mainImg)?>" alt="<?=h(trim(($brand ? $brand.' ' : '').$name))?>"
         class="w-full object-cover"
         onerror="this.onerror=null;this.src='https://placehold.co/1200x700?text=No+Image';">
  </div>

  <div class="grid md:grid-cols-3 gap-6">
    <section class="md:col-span-2 space-y-4">
      <div class="rounded-2xl border border-gray-200 p-4 bg-white">
        <h2 class="font-semibold mb-3">Overview</h2>
        <div class="grid sm:grid-cols-2 gap-3 text-sm">
          <div><span class="text-gray-500">Brand:</span> <?=h($brand ?: '—')?></div>
          <div><span class="text-gray-500">Model:</span> <?=h($model ?: '—')?></div>
          <div><span class="text-gray-500">Body Type:</span> <?=h($body ?: '—')?></div>
          <div><span class="text-gray-500">Configuration:</span> <?=h($cfg ?: '—')?></div>
          <div><span class="text-gray-500">Transmission:</span> <?=h($transmission ?: '—')?></div>
          <div><span class="text-gray-500">Fuel:</span> <?=h($fuel ?: '—')?></div>
          <div><span class="text-gray-500">Seats:</span> <?=h($seats ?: '—')?></div>
          <div><span class="text-gray-500">Engine:</span> <?=h($engine ?: '—')?></div>
          <div><span class="text-gray-500">Drivetrain:</span> <?=h($drivetrain ?: '—')?></div>
          <div><span class="text-gray-500">Year:</span> <?=h($year ?: '—')?></div>
        </div>
      </div>

      <?php if (!empty($specs)): ?>
      <div class="rounded-2xl border border-gray-200 p-4 bg-white">
        <h2 class="font-semibold mb-3">Specifications</h2>
        <div class="space-y-4">
          <?php foreach ($specs as $cat => $kv): ?>
            <details class="group rounded-xl border border-gray-200">
              <summary class="cursor-pointer select-none px-3 py-2 flex items-center justify-between">
                <span class="font-medium"><?=h($cat ?: 'General')?></span>
                <span class="text-gray-400 group-open:hidden">▾</span>
                <span class="text-gray-400 hidden group-open:inline">▴</span>
              </summary>
              <div class="px-3 pb-3">
                <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                  <?php foreach ($kv as $k => $v): ?>
                    <div class="flex items-baseline gap-2">
                      <dt class="text-gray-500 min-w-[140px]"><?=h($k)?></dt>
                      <dd class="text-slate-900"><?=h($v)?></dd>
                    </div>
                  <?php endforeach; ?>
                </dl>
              </div>
            </details>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </section>

    <aside class="space-y-4">
      <div class="rounded-2xl border border-gray-200 p-4 bg-white">
        <div class="text-gray-500 text-sm">Starting from</div>
        <div class="text-3xl font-semibold mt-1"><?=h($priceTxt)?></div>
        <a href="browse.php" class="mt-4 block text-center px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-500">Browse More</a>
      </div>

      <div class="rounded-2xl border border-gray-200 p-4 bg-white">
        <h3 class="font-semibold mb-3">Key Specs</h3>
        <ul class="space-y-2 text-sm">
          <li>Transmission: <?=h($transmission ?: '—')?></li>
          <li>Fuel: <?=h($fuel ?: '—')?></li>
          <li>Seats: <?=h($seats ?: '—')?></li>
          <li>Engine: <?=h($engine ?: '—')?></li>
          <li>Drivetrain: <?=h($drivetrain ?: '—')?></li>
        </ul>

        <!-- Bookmark action -->
        <div class="mt-5 flex items-center justify-between">
          <div class="text-sm text-gray-600">
            <span id="bmCount" class="font-medium"><?= (int)$bmCount ?></span>
            saved
          </div>
          <?php if (isset($_SESSION['user_id'])): ?>
            <button id="bmBtn" data-id="<?= (int)$id ?>"
              class="px-4 py-2 rounded-xl border text-sm transition
                     <?= $isBookmarked
                          ? 'bg-indigo-600 text-white border-indigo-600 hover:bg-indigo-500'
                          : 'bg-white text-slate-900 border-gray-300 hover:bg-gray-50' ?>">
              <?= $isBookmarked ? 'Bookmarked ✓' : 'Bookmark' ?>
            </button>
          <?php else: ?>
            <a href="login.php"
               class="px-4 py-2 rounded-xl border text-sm transition bg-white text-slate-900 border-gray-300 hover:bg-gray-50">
              Login to bookmark
            </a>
          <?php endif; ?>
        </div>
      </div>
    </aside>
  </div>

  <?php if ($related): ?>
  <section class="space-y-3">
    <h2 class="font-semibold">Related Cars</h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
      <?php foreach ($related as $r):
        $raw = $r['image_url'] ?: '';
        $rimg = $raw ? to_s8x2($raw) : 'https://placehold.co/600x400?text=No+Image';
        $rtitle = trim(($r['brand'] ? $r['brand'].' ' : '').($r['name'] ?: ''));
        $rprice = ($hasPriceTbl && isset($r['price']) && $r['price'] !== null)
          ? (($r['currency'] ?: 'MYR') . " " . number_format((float)$r['price'], 0))
          : "—";
      ?>
      <a href="view_more.php?id=<?=intval($r['vehicle_id'])?>" class="group rounded-2xl border border-gray-200 overflow-hidden bg-white hover:shadow-md transition">
        <div class="aspect-[16/10] overflow-hidden bg-gray-100">
          <img src="<?=h($rimg)?>" class="w-full h-full object-cover group-hover:scale-[1.03] transition"
               onerror="this.onerror=null;this.src='https://placehold.co/600x400?text=No+Image';" />
        </div>
        <div class="p-4">
          <div class="flex items-center justify-between">
            <h3 class="font-semibold line-clamp-1"><?=h($rtitle)?></h3>
            <span class="text-sm text-gray-600"><?=h($r['year'] ?? '—')?></span>
          </div>
          <div class="text-sm mt-1"><?=h($rprice)?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</main>

<script>
(function(){
  const btn = document.getElementById('bmBtn');
  if (!btn) return; // user not logged in

  const countEl = document.getElementById('bmCount');

  function setUI(bookmarked) {
    btn.textContent = bookmarked ? 'Bookmarked ✓' : 'Bookmark';
    btn.className =
      'px-4 py-2 rounded-xl border text-sm transition ' +
      (bookmarked
        ? 'bg-indigo-600 text-white border-indigo-600 hover:bg-indigo-500'
        : 'bg-white text-slate-900 border-gray-300 hover:bg-gray-50');
  }

  btn.addEventListener('click', async () => {
    const vehicleId = btn.dataset.id;
    try {
      const res = await fetch('bookmark.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ vehicle_id: vehicleId })
      });

      const ct = res.headers.get('content-type') || '';
      const text = await res.text();
      if (!ct.includes('application/json')) {
        throw new Error('Server returned non-JSON: ' + text.slice(0,120));
      }
      const j = JSON.parse(text);
      if (j.error) throw new Error(j.message || 'Failed');

      setUI(j.bookmarked === true);
      if (typeof j.total_bookmarks === 'number' && countEl) {
        countEl.textContent = j.total_bookmarks;
      } else if (countEl) {
        let n = parseInt(countEl.textContent || '0', 10);
        countEl.textContent = (j.bookmarked === true) ? (n+1) : Math.max(n-1, 0);
      }
    } catch (e) {
      alert(e.message || 'Bookmark failed');
    }
  });
})();
</script>
</body>
</html>
