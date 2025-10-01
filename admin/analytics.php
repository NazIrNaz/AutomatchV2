<?php
require_once __DIR__.'/_guard.php';
require_once __DIR__.'/db/connection.php';
require_once __DIR__.'/_lib.php';

$top = $conn->query("
  SELECT v.vehicle_id, COALESCE(v.name, CONCAT(v.brand,' ',v.model)) AS label,
         COALESCE(a.total_visits,0) AS visits,
         COALESCE(a.total_bookmarks,0) AS bookmarks
  FROM vehicle_data v
  LEFT JOIN analytics a ON a.vehicle_id=v.vehicle_id
  ORDER BY visits DESC, bookmarks DESC
  LIMIT 10
");

$recent = $conn->query("
  SELECT b.created_at, u.username, v.vehicle_id AS vehicle_id,
         COALESCE(v.name, CONCAT(v.brand,' ',v.model)) AS label
  FROM bookmarks b
  JOIN users u ON u.user_id=b.user_id
  JOIN vehicle_data v ON v.vehicle_id=b.vehicle_id
  ORDER BY b.created_at DESC
  LIMIT 20
");

$summary = ['visits'=>0,'bookmarks'=>0];
if($r=$conn->query("SELECT COALESCE(SUM(total_visits),0) v, COALESCE(SUM(total_bookmarks),0) b FROM analytics")){
  $row=$r->fetch_assoc(); $summary['visits']=$row['v']; $summary['bookmarks']=$row['b'];
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Automatch Admin — Analytics</title>
<script src="https://cdn.tailwindcss.com"></script>
</head><body class="bg-gray-50 text-gray-900">
<?php include __DIR__.'/sidebar.php'; ?>
<main class="ml-64 p-6">
  <h1 class="text-2xl font-bold">Analytics</h1>
  <p class="text-gray-600">Traffic & engagement from your <code>analytics</code> and <code>bookmarks</code> tables.</p>

  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-6">
    <div class="rounded-xl border bg-white p-5 shadow-sm">
      <div class="text-sm text-gray-500">Total Visits</div>
      <div class="mt-1 text-3xl font-semibold"><?= number_format($summary['visits']) ?></div>
    </div>
    <div class="rounded-xl border bg-white p-5 shadow-sm">
      <div class="text-sm text-gray-500">Total Bookmarks</div>
      <div class="mt-1 text-3xl font-semibold"><?= number_format($summary['bookmarks']) ?></div>
    </div>
  </div>

  <div class="grid lg:grid-cols-2 gap-6 mt-6">
    <div class="rounded-xl border bg-white p-5 shadow-sm">
      <h2 class="font-semibold mb-3">Top Vehicles by Visits</h2>
      <div class="overflow-x-auto">
        <table class="min-w-full border text-sm">
          <thead class="bg-gray-100">
            <tr><th class="px-3 py-2 text-left">Vehicle</th><th class="px-3 py-2 text-center">Visits</th><th class="px-3 py-2 text-center">Bookmarks</th></tr>
          </thead>
          <tbody>
            <?php while($t=$top->fetch_assoc()): ?>
              <tr class="border-b">
                <td class="px-3 py-2"><?= esc($t['label']) ?> <span class="text-xs text-gray-500">#<?= (int)$t['vehicle_id'] ?></span></td>
                <td class="px-3 py-2 text-center"><?= (int)$t['visits'] ?></td>
                <td class="px-3 py-2 text-center"><?= (int)$t['bookmarks'] ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="rounded-xl border bg-white p-5 shadow-sm">
      <h2 class="font-semibold mb-3">Recent Bookmarks</h2>
      <ul class="divide-y">
        <?php while($r=$recent->fetch_assoc()): ?>
          <li class="py-2 text-sm">
            <span class="text-gray-500"><?= esc($r['created_at']) ?></span>
            — <span class="font-medium"><?= esc($r['username']) ?></span>
            saved <span class="font-medium"><?= esc($r['label']) ?></span> (#<?= (int)$r['vehicle_id'] ?>)
          </li>
        <?php endwhile; ?>
      </ul>
    </div>
  </div>
</main>
</body></html>
