<?php
// admin/vehicles.php
require_once __DIR__.'/_guard.php';
require_once __DIR__.'/db/connection.php';
require_once __DIR__.'/_lib.php';

$pk   = 'vehicle_id';
$cols = vehicle_columns($conn); // e.g. ['name','brand','model','year','image_url',...]

/* ---------- Search ---------- */
$q = trim($_GET['q'] ?? '');

/* Only search fields that actually exist */
$searchable = array_values(array_intersect(
  ['name','brand','model','variant'],   // desired
  $cols                                 // available
));

$whereSql = '';
$params   = [];
$types    = '';

if ($q !== '' && $searchable) {
  $like = "%$q%";
  $parts = [];
  foreach ($searchable as $f) {
    $parts[] = "$f LIKE ?";
    $params[] = $like;
    $types   .= 's';
  }
  $whereSql = 'WHERE ('.implode(' OR ', $parts).')';
}

/* ---------- Sorting ---------- */
$allowedSorts = array_intersect(['vehicle_id','brand','model','name','year'], array_merge([$pk], $cols));
$sort = $_GET['sort'] ?? $pk;
if (!in_array($sort, $allowedSorts, true)) $sort = $pk;

$dir = strtoupper($_GET['dir'] ?? 'DESC');
$dir = $dir === 'ASC' ? 'ASC' : 'DESC';

/* ---------- Pagination ---------- */
$per  = max(10, min(200, (int)($_GET['per'] ?? 50)));
$page = max(1, (int)($_GET['page'] ?? 1));
$off  = ($page-1)*$per;

/* Count total for pager */
$countSql = "SELECT COUNT(*) AS c FROM vehicle_data $whereSql";
$st = $conn->prepare($countSql);
if ($whereSql) $st->bind_param($types, ...$params);
$st->execute();
$total = (int)$st->get_result()->fetch_assoc()['c'];
$pages = max(1, (int)ceil($total / $per));

/* ---------- Listing query (pick useful columns to display) ---------- */
$displayCols = array_values(array_intersect(
  ['brand','image_url','model','name','year'],
  $cols
));
$fields = $displayCols ? implode(',', $displayCols).',' : '';

$sql = "SELECT $pk, $fields COALESCE(image_url,'') AS _img
        FROM vehicle_data
        $whereSql
        ORDER BY $sort $dir
        LIMIT ? OFFSET ?";

$st = $conn->prepare($sql);

/* bind search params + limit/offset */
$bindTypes = $types . 'ii';
$bindVals  = $params;
$bindVals[] = $per;
$bindVals[] = $off;
$st->bind_param($bindTypes, ...$bindVals);

$st->execute();
$list = $st->get_result();
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Automatch Admin — Vehicles</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
<?php include __DIR__.'/sidebar.php'; ?>

<main class="ml-64 p-6">
  <h1 class="text-2xl font-bold">Vehicle Management</h1>
  <p class="text-gray-600">Search, sort, and edit vehicles. Edit opens a modal that mirrors “View More”.</p>

  <!-- toolbar -->
  <div class="mt-4 flex flex-wrap gap-2 items-center">
    <!-- Search with suggestions -->
    <form id="searchForm" class="ml-auto w-full max-w-xl relative" method="get" autocomplete="off">
      <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
      <input type="hidden" name="dir"  value="<?= htmlspecialchars($dir)  ?>">
      <div class="flex gap-2">
        <div class="relative flex-1">
          <input id="qInput" name="q" value="<?= esc($q) ?>" placeholder="Search brand, model, name…"
            class="w-full px-3 py-2 rounded-xl bg-white border border-gray-300
                   placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
          <div id="suggestBox"
            class="hidden absolute left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden z-30">
            <!-- suggestions injected here -->
          </div>
        </div>
        <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-500">Search</button>
        <button type="button" onclick="openEditor(null)" class="px-4 py-2 rounded-xl bg-blue-600 text-white">+ Add Vehicle</button>
      </div>
    </form>
  </div>

  <!-- sort & stats -->
  <div class="mt-3 flex items-center justify-between text-sm text-gray-600">
    <div>Total: <span class="font-medium"><?= number_format($total) ?></span> • Page <?= $page ?> / <?= $pages ?></div>
    <div class="flex items-center gap-2">
      <form method="get" class="flex items-center gap-2">
        <input type="hidden" name="q" value="<?= esc($q) ?>">
        <label>Sort:
          <select name="sort" class="border rounded px-2 py-1">
            <?php foreach ($allowedSorts as $s): ?>
              <option value="<?= esc($s) ?>" <?= $s===$sort?'selected':'' ?>><?= esc(ucwords(str_replace('_',' ',$s))) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <select name="dir" class="border rounded px-2 py-1">
          <option value="ASC"  <?= $dir==='ASC'?'selected':'' ?>>ASC</option>
          <option value="DESC" <?= $dir==='DESC'?'selected':'' ?>>DESC</option>
        </select>
        <label>Per page:
          <select name="per" class="border rounded px-2 py-1">
            <?php foreach([25,50,100,150,200] as $pp): ?>
              <option value="<?= $pp ?>" <?= $pp==$per?'selected':'' ?>><?= $pp ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="px-3 py-1 border rounded">Apply</button>
      </form>
    </div>
  </div>

  <!-- list -->
  <div class="overflow-x-auto mt-3">
    <table class="min-w-full border text-sm">
      <thead class="bg-gray-100 sticky top-0">
        <tr>
          <th class="px-3 py-2 text-left">ID</th>
          <?php foreach($displayCols as $c): ?>
            <th class="px-3 py-2 text-left"><?= esc(ucwords(str_replace('_',' ',$c))) ?></th>
          <?php endforeach; ?>
          <th class="px-3 py-2 text-center">Actions</th>
        </tr>
      </thead>
      <tbody class="bg-white">
        <?php while($row=$list->fetch_assoc()): ?>
        <tr class="border-b hover:bg-gray-50">
          <td class="px-3 py-2"><?= (int)$row[$pk] ?></td>
          <?php foreach($displayCols as $c): ?>
            <td class="px-3 py-2">
              <?php if($c==='image_url' && !empty($row[$c])): ?>
                <img src="<?= esc($row[$c]) ?>" class="h-10 w-16 rounded object-cover inline-block" alt="">
              <?php else: ?>
                <?= esc((string)$row[$c]) ?>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td class="px-3 py-2 text-center whitespace-nowrap">
            <button class="text-blue-600 hover:underline" onclick="openEditor(<?= (int)$row[$pk] ?>)">Edit</button>
            <form method="post" class="inline" onsubmit="return confirm('Delete vehicle #<?= (int)$row[$pk] ?>?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="<?= $pk ?>" value="<?= (int)$row[$pk] ?>">
              <button class="text-red-600 hover:underline ml-3">Delete</button>
            </form>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <!-- pager -->
  <div class="mt-4 flex items-center justify-between">
    <div></div>
    <div class="flex gap-2">
      <?php
        $base = function($p) use($q,$sort,$dir,$per){ 
          return '?q='.urlencode($q).'&sort='.$sort.'&dir='.$dir.'&per='.$per.'&page='.$p;
        };
      ?>
      <a class="px-3 py-1 border rounded <?= $page<=1?'pointer-events-none opacity-50':'' ?>" href="<?= $base(max(1,$page-1)) ?>">Prev</a>
      <a class="px-3 py-1 border rounded <?= $page>=$pages?'pointer-events-none opacity-50':'' ?>" href="<?= $base(min($pages,$page+1)) ?>">Next</a>
    </div>
  </div>

  <!-- Editor Modal (iframe to vehicle_edit.php) -->
  <div id="editorModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 z-50">
    <div class="bg-white rounded-xl shadow-2xl w-[95vw] max-w-5xl h-[90vh] overflow-hidden flex flex-col">
      <div class="px-4 py-3 border-b flex items-center justify-between">
        <div class="font-semibold" id="editorTitle">Edit Vehicle</div>
        <button type="button" class="text-gray-500" onclick="closeEditor()">✕</button>
      </div>
      <iframe id="editorFrame" src="about:blank" class="flex-1 w-full"></iframe>
    </div>
  </div>
</main>

<script>
function openEditor(id){
  const modal = document.getElementById('editorModal');
  const frame = document.getElementById('editorFrame');
  const title = document.getElementById('editorTitle');
  title.textContent = id ? ('Edit Vehicle #'+id) : 'Create Vehicle';
  frame.src = 'vehicle_edit.php' + (id ? ('?vehicle_id='+id) : '');
  modal.classList.remove('hidden'); modal.classList.add('flex');
}
function closeEditor(){
  const modal = document.getElementById('editorModal');
  document.getElementById('editorFrame').src = 'about:blank';
  modal.classList.add('hidden'); modal.classList.remove('flex');
}
window.addEventListener('message', (e) => {
  if (e?.data === 'vehicleSaved') window.location.reload();
});

/* -------- Suggestions JS (uses suggest.php) -------- */
(() => {
  const qInput = document.getElementById('qInput');
  const box = document.getElementById('suggestBox');
  const form = document.getElementById('searchForm');

  let timer = null, idx = -1, items = [];

  function hide(){ box.classList.add('hidden'); box.innerHTML=''; items=[]; idx=-1; }

  function esc(s){
    s = (s ?? '').toString();
    return s
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function render(list){
    if (!list.length) return hide();
    box.innerHTML = list.map((s,i)=>`
      <button type="button" data-i="${i}" class="w-full text-left px-3 py-2 hover:bg-gray-50 flex items-center gap-2">
        <span class="text-slate-900">${esc(s.label)}</span>
        <span class="text-xs text-gray-500 uppercase">${esc(s.type)}</span>
      </button>`).join('');
    box.classList.remove('hidden');
    [...box.querySelectorAll('button')].forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const i = Number(btn.dataset.i);
        qInput.value = items[i].label;
        hide(); form.submit();
      });
    });
  }

  function fetchSuggest(q){
    if (!q || q.length < 2) return hide();
    fetch('suggest.php?q=' + encodeURIComponent(q))
      .then(r => r.ok ? r.json() : [])
      .then(data => { items = Array.isArray(data) ? data : []; render(items); })
      .catch(hide);
  }

  qInput.addEventListener('input', ()=>{
    clearTimeout(timer);
    timer = setTimeout(()=>fetchSuggest(qInput.value.trim()), 150);
  });

  qInput.addEventListener('keydown', (e)=>{
    const visible = !box.classList.contains('hidden');
    const keys = ['ArrowDown','ArrowUp','Enter','Escape','Tab'];
    if (keys.includes(e.key)) e.preventDefault();

    if (!visible) { if (e.key === 'Enter') form.submit(); return; }

    const buttons = [...box.querySelectorAll('button')];
    if (e.key === 'ArrowDown') {
      idx = (idx + 1) % buttons.length; buttons[idx].focus();
    } else if (e.key === 'ArrowUp') {
      idx = (idx - 1 + buttons.length) % buttons.length; buttons[idx].focus();
    } else if (e.key === 'Enter') {
      if (idx >= 0 && buttons[idx]) buttons[idx].click();
      else form.submit();
    } else if (e.key === 'Escape' || e.key === 'Tab') {
      hide();
    }
  });

  document.addEventListener('click', (e)=>{ if(!box.contains(e.target) && e.target!==qInput) hide(); });
})();
</script>
</body></html>
