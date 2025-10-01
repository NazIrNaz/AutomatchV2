<?php
session_start();

/* ===========================
   DB CONNECTION
=========================== */
$host = "127.0.0.1";
$user = "root";
$pass = "@dmin321";
$db = "fypbetatest";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error)
    die("DB connection failed: " . $conn->connect_error);

/* ===========================
   HELPERS
=========================== */
function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function simpleList($conn, $sql, $types = "", $params = [])
{
    $out = [];
    $stmt = $conn->prepare($sql);
    if ($types)
        $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_row())
        $out[] = $x[0];
    $stmt->close();
    return $out;
}

/* Detect columns in vehicle_data to avoid referencing missing ones */
$vdCols = [];
$res = $conn->query("
  SELECT COLUMN_NAME
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicle_data'
");
if ($res)
    while ($r = $res->fetch_assoc())
        $vdCols[$r['COLUMN_NAME']] = true;
$hasCol = function ($c) use ($vdCols) {
    return isset($vdCols[$c]); };

/* Optional tables */
$hasPriceTbl = (bool) $conn->query("SHOW TABLES LIKE 'vehicle_price'")->num_rows;
$hasCfgTbl = (bool) $conn->query("SHOW TABLES LIKE 'vehicle_configurations'")->num_rows;

/* ===========================
   INPUTS (SAFE)
=========================== */
$q = isset($_GET['q']) ? trim($_GET['q']) : "";
$brand = isset($_GET['brand']) ? trim($_GET['brand']) : "";
$bodyType = isset($_GET['body_type']) ? trim($_GET['body_type']) : "";
$cfg = isset($_GET['config']) ? trim($_GET['config']) : "";

$yearMin = (isset($_GET['year_min']) && $_GET['year_min'] !== '') ? intval($_GET['year_min']) : null;
$yearMax = (isset($_GET['year_max']) && $_GET['year_max'] !== '') ? intval($_GET['year_max']) : null;

$priceMin = ($hasPriceTbl && isset($_GET['price_min']) && $_GET['price_min'] !== '') ? floatval($_GET['price_min']) : null;
$priceMax = ($hasPriceTbl && isset($_GET['price_max']) && $_GET['price_max'] !== '') ? floatval($_GET['price_max']) : null;

$sort = isset($_GET['sort']) ? $_GET['sort'] : "newest"; // newest|price_asc|price_desc|year_desc|year_asc

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

/* ===========================
   SQL BUILD
=========================== */

/* Select list (only add columns that exist) */
$selectPieces = [
    "v.vehicle_id",
    ($hasCol('name') ? "v.name" : "NULL AS name"),
    ($hasCol('brand') ? "v.brand" : "NULL AS brand"),
    ($hasCol('model') ? "v.model" : "NULL AS model"),
    ($hasCol('year') ? "v.year" : "NULL AS year"),
    ($hasCol('image_url') ? "v.image_url" : "NULL AS image_url"),
    ($hasCol('body_type') ? "v.body_type" : "NULL AS body_type")
];
if ($hasPriceTbl)
    $selectPieces[] = "vp.price, vp.currency";
if ($hasCfgTbl)
    $selectPieces[] = "vc.vehicle_configuration";

/* Pull a nice spec badge from vehicle_specifications, prioritizing
   driveline/drive type/drivetrain; if missing, try transmission name/type */
$selectPieces[] = "
  COALESCE(drive_specs.spec_value, trans_specs.spec_value) AS spec_badge
";

/* FROM / JOINS */
$sqlFrom = " FROM vehicle_data v ";
if ($hasPriceTbl)
    $sqlFrom .= " LEFT JOIN vehicle_price vp ON v.vehicle_id = vp.vehicle_id ";
if ($hasCfgTbl)
    $sqlFrom .= " LEFT JOIN vehicle_configurations vc ON v.vehicle_id = vc.vehicle_id ";

/* Join EAV subqueries for spec badge */
$sqlFrom .= "
LEFT JOIN (
  SELECT vs.vehicle_id,
         MAX(vs.spec_value) AS spec_value
  FROM vehicle_specifications vs
  WHERE LOWER(vs.spec_key) IN ('driveline','drive line','drive type','drivetrain')
  GROUP BY vs.vehicle_id
) AS drive_specs ON drive_specs.vehicle_id = v.vehicle_id

LEFT JOIN (
  SELECT vs.vehicle_id,
         MAX(vs.spec_value) AS spec_value
  FROM vehicle_specifications vs
  WHERE LOWER(vs.spec_key) IN ('transmission name','transmission','type')
  GROUP BY vs.vehicle_id
) AS trans_specs ON trans_specs.vehicle_id = v.vehicle_id
";

/* WHERE + bindings */
$where = " WHERE 1=1 ";
$types = "";
$params = [];

/* Search in name/brand/model if present */
if ($q !== "") {
    $chunks = [];
    if ($hasCol('name'))
        $chunks[] = "v.name  LIKE CONCAT('%', ?, '%')";
    if ($hasCol('brand'))
        $chunks[] = "v.brand LIKE CONCAT('%', ?, '%')";
    if ($hasCol('model'))
        $chunks[] = "v.model LIKE CONCAT('%', ?, '%')";
    if ($chunks) {
        $where .= " AND (" . implode(" OR ", $chunks) . ")";
        if ($hasCol('name')) {
            $types .= "s";
            $params[] = $q;
        }
        if ($hasCol('brand')) {
            $types .= "s";
            $params[] = $q;
        }
        if ($hasCol('model')) {
            $types .= "s";
            $params[] = $q;
        }
    }
}

if ($brand !== "" && $hasCol('brand')) {
    $where .= " AND v.brand = ?";
    $types .= "s";
    $params[] = $brand;
}
if ($bodyType !== "" && $hasCol('body_type')) {
    $where .= " AND v.body_type = ?";
    $types .= "s";
    $params[] = $bodyType;
}
if ($cfg !== "" && $hasCfgTbl) {
    $where .= " AND vc.vehicle_configuration = ?";
    $types .= "s";
    $params[] = $cfg;
}
if ($yearMin !== null && $hasCol('year')) {
    $where .= " AND v.year >= ?";
    $types .= "i";
    $params[] = $yearMin;
}
if ($yearMax !== null && $hasCol('year')) {
    $where .= " AND v.year <= ?";
    $types .= "i";
    $params[] = $yearMax;
}
if ($hasPriceTbl && $priceMin !== null) {
    $where .= " AND vp.price >= ?";
    $types .= "d";
    $params[] = $priceMin;
}
if ($hasPriceTbl && $priceMax !== null) {
    $where .= " AND vp.price <= ?";
    $types .= "d";
    $params[] = $priceMax;
}

/* ORDER */
$order = " ORDER BY ";
if ($sort === "price_asc" && $hasPriceTbl) {
    $order .= " (vp.price IS NULL), vp.price ASC ";
} elseif ($sort === "price_desc" && $hasPriceTbl) {
    $order .= " (vp.price IS NULL), vp.price DESC ";
} elseif ($sort === "year_desc" && $hasCol('year')) {
    $order .= " v.year DESC ";
} elseif ($sort === "year_asc" && $hasCol('year')) {
    $order .= " v.year ASC ";
} else {
    $order .= ($hasCol('created_at') ? "v.created_at DESC" : "v.vehicle_id DESC");
}

/* COUNT */
$countSql = "SELECT COUNT(DISTINCT v.vehicle_id) " . $sqlFrom . $where;
$stmt = $conn->prepare($countSql);
if ($types)
    $stmt->bind_param($types, ...$params);
$stmt->execute();
$stmt->bind_result($totalRows);
$stmt->fetch();
$stmt->close();
$totalPages = max(1, ceil($totalRows / $perPage));

/* DATA */
$select = "SELECT DISTINCT " . implode(", ", $selectPieces);
$dataSql = $select . $sqlFrom . $where . $order . " LIMIT ? OFFSET ?";
$stmt = $conn->prepare($dataSql);
if ($types) {
    $types2 = $types . "ii";
    $params2 = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($types2, ...$params2);
} else {
    $stmt->bind_param("ii", $perPage, $offset);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc())
    $rows[] = $row;
$stmt->close();

/* Filter lists */
$brands = $hasCol('brand') ? simpleList($conn, "SELECT DISTINCT brand FROM vehicle_data WHERE brand IS NOT NULL AND brand<>'' ORDER BY brand") : [];
$bodies = $hasCol('body_type') ? simpleList($conn, "SELECT DISTINCT body_type FROM vehicle_data WHERE body_type IS NOT NULL AND body_type<>'' ORDER BY body_type") : [];
$configs = $hasCfgTbl ? simpleList($conn, "SELECT DISTINCT vehicle_configuration FROM vehicle_configurations WHERE vehicle_configuration IS NOT NULL AND vehicle_configuration<>'' ORDER BY vehicle_configuration") : [];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Browse Cars</title>

    <!-- Tailwind (light theme) -->
    <script src="https://cdn.tailwindcss.com"></script>

</head>

<body class="bg-white text-slate-900">

    <?php
    // Keep your existing navbar if present
    if (file_exists(__DIR__ . '/includes/navbar.php')) {
        include __DIR__ . '/includes/navbar.php';
    } elseif (file_exists(__DIR__ . '/navbar.php')) {
        include __DIR__ . '/navbar.php';
    }
    ?>
    <div class="h-16"></div>
    <header class="sticky top-0 z-20 bg-white/90 backdrop-blur border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 py-4 flex items-center gap-4">
            <h1 class="text-lg sm:text-xl font-semibold">Automatch • Browse Cars</h1>

            <!-- Search with suggestions -->
            <form id="searchForm" class="ml-auto w-full max-w-xl relative" method="get" autocomplete="off">
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input id="qInput" name="q" value="<?= h($q ?? '') ?>" placeholder="Search brand, model…"
                            class="w-full px-3 py-2 rounded-xl bg-white border border-gray-300
                   placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                        <!-- Suggestion dropdown -->
                        <div id="suggestBox"
                            class="hidden absolute left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden z-30">
                            <!-- suggestions injected here -->
                        </div>
                    </div>
                    <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-500">Search</button>
                </div>
            </form>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6 grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Filters -->
        <aside class="lg:col-span-3 space-y-4">
            <form class="space-y-4 rounded-2xl border border-gray-200 p-4 bg-white" method="get">
                <input type="hidden" name="q" value="<?= h($q) ?>" />

                <?php if ($brands): ?>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Brand</label>
                        <select name="brand" class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2">
                            <option value="">Any</option>
                            <?php foreach ($brands as $b): ?>
                                <option value="<?= h($b) ?>" <?= $brand === $b ? 'selected' : '' ?>><?= h($b) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Year (Min)</label>
                        <input type="number" name="year_min" value="<?= $yearMin !== null ? $yearMin : '' ?>"
                            class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2" />
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Year (Max)</label>
                        <input type="number" name="year_max" value="<?= $yearMax !== null ? $yearMax : '' ?>"
                            class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2" />
                    </div>
                </div>

                <?php if ($hasPriceTbl): ?>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Price Min (MYR)</label>
                            <input type="number" step="0.01" name="price_min" value="<?= $priceMin !== null ? $priceMin : '' ?>"
                                class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2" />
                        </div>
                        <div>
                            <label class="block text-sm text-gray-600 mb-1">Price Max (MYR)</label>
                            <input type="number" step="0.01" name="price_max" value="<?= $priceMax !== null ? $priceMax : '' ?>"
                                class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2" />
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($bodies): ?>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Body Type</label>
                        <select name="body_type" class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2">
                            <option value="">Any</option>
                            <?php foreach ($bodies as $x): ?>
                                <option value="<?= h($x) ?>" <?= $bodyType === $x ? 'selected' : '' ?>><?= h($x) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($configs): ?>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Configuration</label>
                        <select name="config" class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2">
                            <option value="">Any</option>
                            <?php foreach ($configs as $x): ?>
                                <option value="<?= h($x) ?>" <?= $cfg === $x ? 'selected' : '' ?>><?= h($x) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="block text-sm text-gray-600 mb-1">Sort By</label>
                    <select name="sort" class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2">
                        <option value="newest" <?= $sort === "newest" ? "selected" : "" ?>>Newest</option>
                        <?php if ($hasPriceTbl): ?>
                            <option value="price_asc" <?= $sort === "price_asc" ? "selected" : "" ?>>Price: Low → High</option>
                            <option value="price_desc" <?= $sort === "price_desc" ? "selected" : "" ?>>Price: High → Low</option>
                        <?php endif; ?>
                        <option value="year_desc" <?= $sort === "year_desc" ? "selected" : "" ?>>Year: New → Old</option>
                        <option value="year_asc" <?= $sort === "year_asc" ? "selected" : "" ?>>Year: Old → New</option>
                    </select>
                </div>

                <div class="flex gap-2 pt-1">
                    <button
                        class="flex-1 px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-500">Apply</button>
                    <a href="browse.php" class="px-4 py-2 rounded-xl border border-gray-300 hover:bg-gray-50">Reset</a>
                </div>
            </form>
            <p class="text-sm text-gray-600"><?= number_format($totalRows) ?> results</p>
        </aside>

        <!-- Grid -->
        <section class="lg:col-span-9">
            <?php if (!$rows): ?>
                <div class="p-10 border border-gray-200 rounded-2xl text-center text-gray-600 bg-white">No cars found with
                    current filters.</div>
            <?php else: ?>
                <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-5">
                    <?php foreach ($rows as $r):
                        $img = $r['image_url'] ?: 'https://placehold.co/600x400?text=No+Image';
                        $price = ($hasPriceTbl && $r['price'] !== null) ? number_format((float) $r['price'], 0) : null;
                        $currency = $hasPriceTbl ? ($r['currency'] ?: "MYR") : "";
                        $title = trim(($r['brand'] ?: '') . ' ' . ($r['name'] ?: ''));
                        $subtitle = $r['vehicle_configuration'] ?? ($r['body_type'] ?? '');
                        $badge = $r['spec_badge'] ?? '';
                        ?>
                        <a href="view_more.php?id=<?= intval($r['vehicle_id']) ?>"
                            class="group rounded-2xl border border-gray-200 overflow-hidden bg-white hover:shadow-md transition">
                            <div class="aspect-[16/10] overflow-hidden bg-gray-100">
                                <img src="<?= h($img) ?>" alt="<?= h($title) ?>"
                                    class="w-full h-full object-cover group-hover:scale-[1.03] transition" />
                            </div>
                            <div class="p-4 space-y-1">
                                <div class="flex items-center justify-between">
                                    <h3 class="font-semibold line-clamp-1"><?= h($title) ?></h3>
                                    <span class="text-sm text-gray-500"><?= h($r['year'] ?: '—') ?></span>
                                </div>
                                <p class="text-sm text-gray-500 line-clamp-1"><?= h($subtitle ?: '—') ?></p>

                                <div class="flex items-center gap-2 pt-1">
                                    <?php if ($badge): ?>
                                        <span
                                            class="text-xs px-2 py-1 rounded-full bg-gray-100 border border-gray-200 text-gray-700"><?= h($badge) ?></span>
                                    <?php endif; ?>
                                </div>

                                <p class="pt-2 text-lg font-medium">
                                    <?= $price === null ? "—" : ($currency . " " . $price) ?>
                                </p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <div class="mt-8 flex items-center justify-center gap-2">
                    <?php
                    $qs = $_GET;
                    unset($qs['page']);
                    function linkWithPage($p, $qs)
                    {
                        $qs['page'] = $p;
                        return "?" . http_build_query($qs);
                    }
                    $prev = max(1, $page - 1);
                    $next = min($totalPages, $page + 1);
                    ?>
                    <a class="px-3 py-2 rounded-xl border border-gray-300 hover:bg-gray-50 <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>"
                        href="<?= h(linkWithPage($prev, $qs)) ?>">Prev</a>

                    <span class="text-sm text-gray-600 px-2">Page <?= $page ?> / <?= $totalPages ?></span>

                    <a class="px-3 py-2 rounded-xl border border-gray-300 hover:bg-gray-50 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : '' ?>"
                        href="<?= h(linkWithPage($next, $qs)) ?>">Next</a>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Suggestions JS -->
    <script>
        (() => {
            const qInput = document.getElementById('qInput');
            const box = document.getElementById('suggestBox');
            const form = document.getElementById('searchForm');

            let timer = null;
            let idx = -1;
            let items = [];

            function hide() {
                box.classList.add('hidden');
                box.innerHTML = '';
                items = [];
                idx = -1;
            }
            function escapeHtml(s) {
                return (s ?? '').toString()
                    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;').replaceAll("'", "&#039;");
            }
            function render(list) {
                if (!list.length) return hide();
                box.innerHTML = list.map((s, i) => `
      <button type="button" data-i="${i}"
        class="w-full text-left px-3 py-2 hover:bg-gray-50 flex items-center gap-2">
        <span class="text-slate-900">${escapeHtml(s.label)}</span>
        <span class="text-xs text-gray-500 uppercase">${escapeHtml(s.type)}</span>
      </button>
    `).join('');
                box.classList.remove('hidden');
                [...box.querySelectorAll('button')].forEach(btn => {
                    btn.addEventListener('click', () => {
                        const i = Number(btn.dataset.i);
                        qInput.value = items[i].label;
                        hide();
                        form.submit();
                    });
                });
            }
            function fetchSuggest(q) {
                if (q.length < 2) { hide(); return; }
                fetch('suggest.php?q=' + encodeURIComponent(q))
                    .then(r => r.ok ? r.json() : [])
                    .then(data => { items = Array.isArray(data) ? data : []; render(items); })
                    .catch(() => hide());
            }

            qInput.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => fetchSuggest(qInput.value.trim()), 150);
            });

            qInput.addEventListener('keydown', (e) => {
                const visible = !box.classList.contains('hidden');
                if (!visible) return;

                const buttons = [...box.querySelectorAll('button')];
                if (['ArrowDown', 'ArrowUp', 'Enter', 'Escape', 'Tab'].includes(e.key)) e.preventDefault();

                if (e.key === 'ArrowDown') {
                    idx = (idx + 1) % buttons.length;
                    buttons[idx].focus();
                } else if (e.key === 'ArrowUp') {
                    idx = (idx - 1 + buttons.length) % buttons.length;
                    buttons[idx].focus();
                } else if (e.key === 'Enter') {
                    if (idx >= 0 && buttons[idx]) buttons[idx].click();
                    else form.submit();
                } else if (e.key === 'Escape' || e.key === 'Tab') {
                    hide();
                }
            });

            document.addEventListener('click', (e) => {
                if (!box.contains(e.target) && e.target !== qInput) hide();
            });
        })();
    </script>

</body>

</html>