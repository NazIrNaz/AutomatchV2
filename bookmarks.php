<?php
// bookmarks.php
session_start();
if (!isset($_SESSION['user_id'])) {
  header("Location: /login.php?next=" . urlencode($_SERVER['REQUEST_URI']));
  exit;
}
require_once "db/connection.php";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

$userId = (int)$_SESSION['user_id'];

/* ---------- helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function to_s8x2($url) {
  if (!$url) return '';
  $url = preg_replace('#/s4(?=[\-/])#i', '/s8x2', $url, 1);
  $url = preg_replace('#-s4(?=[.\-_/?]|$)#i', '-s8x2', $url, 1);
  $url = preg_replace('#([?&])s=4(\b|&|$)#i', '$1s=s8x2$2', $url, 1);
  return $url;
}

/* ---------- schema hints ---------- */
$vdCols = [];
$res = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='vehicle_data'");
if ($res) while ($r = $res->fetch_assoc()) $vdCols[$r['COLUMN_NAME']] = true;
$hasCol = function($c) use ($vdCols){ return isset($vdCols[$c]); };

$hasPriceTbl = (bool)$conn->query("SHOW TABLES LIKE 'vehicle_price'")->num_rows;

/* ---------- pagination ---------- */
$perPage = max(6, min(30, (int)($_GET['per'] ?? 12)));
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

/* ---------- count total ---------- */
$cnt = $conn->prepare("SELECT COUNT(*) AS c FROM bookmarks WHERE user_id=?");
$cnt->bind_param("i", $userId);
$cnt->execute();
$total = (int)$cnt->get_result()->fetch_assoc()['c'];
$cnt->close();

/* ---------- fetch bookmarks ---------- */
$select = [
  "b.bookmark_id",
  "v.vehicle_id",
  ($hasCol('brand') ? "v.brand" : "NULL AS brand"),
  ($hasCol('name')  ? "v.name"  : "NULL AS name"),
  ($hasCol('model') ? "v.model" : "NULL AS model"),
  ($hasCol('year')  ? "v.year"  : "NULL AS year"),
  ($hasCol('image_url') ? "v.image_url" : "NULL AS image_url")
];
if ($hasPriceTbl) $select[] = "vp.price, vp.currency";

$sql = "SELECT ".implode(", ", $select).", b.created_at
        FROM bookmarks b
        JOIN vehicle_data v ON v.vehicle_id = b.vehicle_id
        ".($hasPriceTbl ? "LEFT JOIN vehicle_price vp ON vp.vehicle_id = v.vehicle_id" : "")."
        WHERE b.user_id = ?
        ORDER BY b.created_at DESC
        LIMIT ? OFFSET ?";

$st = $conn->prepare($sql);
$st->bind_param("iii", $userId, $perPage, $offset);
$st->execute();
$rs = $st->get_result();
$items = [];
while ($row = $rs->fetch_assoc()) $items[] = $row;
$st->close();

/* ---------- page calc ---------- */
$pages = max(1, (int)ceil($total / $perPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Bookmarks • Automatch</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white text-slate-900">
<?php
if (file_exists(__DIR__ . '/includes/navbar.php')) include __DIR__ . '/includes/navbar.php';
elseif (file_exists(__DIR__ . '/navbar.php')) include __DIR__ . '/navbar.php';
?>
<div class="h-16 md:h-20"></div>

<main class="max-w-6xl mx-auto px-4 py-6">
  <header class="flex items-center gap-3 mb-4">
    <a href="javascript:history.back()" class="px-3 py-2 rounded-xl border border-gray-300 hover:bg-gray-50">← Back</a>
    <h1 class="text-xl font-semibold">My Bookmarks</h1>
    <span id="savedTotal" class="ml-auto text-sm text-gray-600"><?= (int)$total ?> saved</span>
  </header>

  <?php if (!$items): ?>
    <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center">
      <p class="text-slate-700">You haven’t saved any cars yet.</p>
      <a href="browse.php" class="inline-block mt-4 px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-500">
        Browse Cars
      </a>
    </div>
  <?php else: ?>
    <div id="cards" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
      <?php foreach ($items as $car):
        $title = trim(($car['brand'] ? $car['brand'].' ' : '') . ($car['name'] ?: $car['model'] ?: ''));
        $img = ($car['image_url'] ? to_s8x2($car['image_url']) : 'https://placehold.co/600x400?text=No+Image');
        $priceTxt = ($hasPriceTbl && $car['price'] !== null)
          ? (($car['currency'] ?: 'MYR') . " " . number_format((float)$car['price'], 0))
          : "—";
      ?>
      <div id="bm-<?= (int)$car['bookmark_id'] ?>" class="group rounded-2xl border border-gray-200 overflow-hidden bg-white">
        <a href="view_more.php?id=<?= (int)$car['vehicle_id'] ?>">
          <div class="aspect-[16/10] overflow-hidden bg-gray-100">
            <img src="<?= h($img) ?>"
                 class="w-full h-full object-cover group-hover:scale-[1.03] transition"
                 onerror="this.onerror=null;this.src='https://placehold.co/600x400?text=No+Image';" />
          </div>
        </a>
        <div class="p-4 space-y-2">
          <div class="flex items-center justify-between gap-3">
            <h3 class="font-semibold line-clamp-1"><?= h($title) ?></h3>
            <span class="text-sm text-gray-600"><?= h($car['year'] ?? '—') ?></span>
          </div>
          <div class="text-sm"><?= h($priceTxt) ?></div>
          <div class="flex items-center justify-between pt-2">
            <a href="view_more.php?id=<?= (int)$car['vehicle_id'] ?>"
               class="text-sm px-3 py-2 rounded-xl border border-gray-300 hover:bg-gray-50">View</a>
            <button
              class="remove-btn text-sm px-3 py-2 rounded-xl border border-red-300 text-red-600 hover:bg-red-50"
              data-vehicle="<?= (int)$car['vehicle_id'] ?>"
              data-row="bm-<?= (int)$car['bookmark_id'] ?>">
              Remove
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="mt-6 flex items-center justify-center gap-2">
        <?php for ($p=1; $p<=$pages; $p++): ?>
          <a href="?page=<?= $p ?>&per=<?= $perPage ?>"
             class="px-3 py-1 rounded-lg border <?= $p==$page ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-300 hover:bg-gray-50' ?>">
            <?= $p ?>
          </a>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</main>

<script>
// Single, robust remove handler using bookmark_remove.php
document.querySelectorAll('.remove-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const vehicleId = btn.dataset.vehicle;
    const rowId = btn.dataset.row;

    try {
      const res = await fetch('bookmark_remove.php', {
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
      if (j.error) throw new Error(j.message || 'Failed to remove');

      // Remove card
      document.getElementById(rowId)?.remove();

      // Update top "N saved"
      const savedEl = document.getElementById('savedTotal');
      if (savedEl) {
        const m = savedEl.textContent.match(/(\d+)/);
        const current = m ? parseInt(m[1], 10) : 1;
        const next = Math.max(0, current - 1);
        savedEl.textContent = `${next} saved`;
      }

      // If grid becomes empty, show empty state
      const grid = document.getElementById('cards');
      if (grid && grid.children.length === 0) {
        location.reload(); // simplest: reload to show the empty-state block
      }
    } catch (e) {
      alert(e.message || 'Unable to remove bookmark.');
    }
  });
});
</script>
</body>
</html>
