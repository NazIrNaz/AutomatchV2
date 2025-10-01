<?php
// admin/vehicle_edit.php — View-More-style editor + full specs (vehicle_specifications)
require_once __DIR__.'/_guard.php';
require_once __DIR__.'/db/connection.php';
require_once __DIR__.'/_lib.php';

$pk   = 'vehicle_id';
$cols = vehicle_columns($conn); // overview fields you allowed in _lib.php

// Categories to display as accordions (matches your UI)
$specCategories = [
  'powertrain','drivetrain','performance_and_efficiency','dimensions',
  'chassis','crash_safety_rating','safety','lighting','cabin','convenience'
];

// Load vehicle
$id  = isset($_GET[$pk]) ? (int)$_GET[$pk] : 0;
$row = array_fill_keys($cols, '');
if ($id > 0) {
  $stmt = $conn->prepare("SELECT $pk,".implode(',', $cols)." FROM vehicle_data WHERE $pk=? LIMIT 1");
  $stmt->bind_param("i",$id);
  $stmt->execute();
  if ($res = $stmt->get_result()) {
    if ($res->num_rows) {
      $found = $res->fetch_assoc();
      foreach($cols as $c){ $row[$c] = $found[$c] ?? ''; }
    } else { $id = 0; } // record not found -> create mode
  }
}

// Load existing specs from vehicle_specifications
$specs = array_fill_keys($specCategories, []);
if ($id > 0) {
  $stmt = $conn->prepare("
    SELECT spec_id, category, spec_key, spec_value
    FROM vehicle_specifications
    WHERE vehicle_id=?
    ORDER BY category, spec_id
  ");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $r = $stmt->get_result();
  while ($s = $r->fetch_assoc()) {
    $cat = $s['category'];
    if (!isset($specs[$cat])) $specs[$cat] = [];
    $specs[$cat][] = ['key'=>$s['spec_key'], 'value'=>$s['spec_value']];
  }
}

// Save handler
$err = ''; $saved = false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  // 1) Collect overview fields
  $data = [];
  foreach($cols as $c) { $data[$c] = $_POST[$c] ?? ''; }

  // 2) Create or update main vehicle record
  if ($id > 0) {
    // UPDATE
    $sql  = build_update_sql($cols);                 // "... SET col=?,... WHERE id=?"
    $sql  = str_replace('id=?', "$pk=?", $sql);      // ensure PK name
    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $types = str_repeat('s', count($cols)) . 'i';
      $vals  = array_values($data); $vals[] = $id;
      $stmt->bind_param($types, ...$vals);
      $saved = $stmt->execute();
    } else { $err = 'Failed to prepare update statement'; }
  } else {
    // INSERT
    $insCols = []; $vals=[];
    foreach($cols as $c) { if ($data[$c] !== '') { $insCols[]=$c; $vals[]=$data[$c]; } }
    if ($insCols) {
      $sql  = build_insert_sql($insCols);
      $stmt = $conn->prepare($sql);
      if ($stmt) {
        $types = str_repeat('s', count($insCols));
        $stmt->bind_param($types, ...$vals);
        $ok = $stmt->execute();
        if ($ok) { $id = (int)$stmt->insert_id; $saved = true; }
      } else { $err = 'Failed to prepare insert statement'; }
    } else { $err = 'Nothing to insert'; }
  }

  // 3) Specs save (replace) if main record saved
  if ($saved && $id > 0) {
    // delete old
    $del = $conn->prepare("DELETE FROM vehicle_specifications WHERE vehicle_id=?");
    $del->bind_param("i",$id);
    $del->execute();

    // insert new rows (only non-empty)
    if (isset($_POST['spec']) && is_array($_POST['spec'])) {
      $ins = $conn->prepare("
        INSERT INTO vehicle_specifications (vehicle_id, category, spec_key, spec_value)
        VALUES (?,?,?,?)
      ");
      foreach ($_POST['spec'] as $cat => $items) {
        // allow only known categories to keep UI clean
        if (!in_array($cat, $specCategories, true)) continue;
        if (!is_array($items)) continue;
        foreach ($items as $it) {
          $k = trim($it['key'] ?? '');
          $v = trim($it['value'] ?? '');
          if ($k === '' && $v === '') continue;
          $ins->bind_param("isss", $id, $cat, $k, $v);
          $ins->execute();
        }
      }
    }
  }

  if ($saved) {
    // notify parent list and show a simple message
    echo "<!DOCTYPE html><html><body><script>
      try { window.parent.postMessage('vehicleSaved','*'); } catch(e){}
      document.write('<p style=\"font-family:system-ui;padding:16px\">Saved. You can close this window.</p>');
      </script></body></html>";
    exit;
  }
}

// Image preview fallback
$img = trim($row['image_url'] ?? '');
if ($img === '') $img = 'https://via.placeholder.com/800x500?text=No+Image';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $id? "Edit Vehicle #$id":"Create Vehicle"; ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-white text-gray-900">
  <div class="p-5 border-b">
    <div class="text-xl font-semibold"><?= $id ? "Edit Vehicle #$id" : "Create Vehicle" ?></div>
    <?php if($err): ?><div class="mt-2 text-sm text-red-600"><?= esc($err) ?></div><?php endif; ?>
  </div>

  <form method="post" class="p-5">
    <div class="grid lg:grid-cols-2 gap-6">
      <!-- Left: Big image & URL -->
      <div>
        <div class="rounded-xl border overflow-hidden">
          <img src="<?= esc($img) ?>" alt="" class="w-full h-72 object-cover" id="previewImg">
        </div>
        <label class="text-sm block mt-3">
          <span class="text-gray-600">Image URL</span>
          <input name="image_url" value="<?= esc($row['image_url']) ?>" class="mt-1 w-full border rounded-lg px-3 py-2"
                 oninput="document.getElementById('previewImg').src=this.value||'https://via.placeholder.com/800x500?text=No+Image'">
        </label>
      </div>

      <!-- Right: Overview fields -->
      <div class="rounded-xl border p-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <?php foreach($cols as $c):
                if ($c==='image_url') continue; // handled on the left
                $label = ucwords(str_replace('_',' ',$c));
                $type  = in_array($c,['year','seats','price']) ? 'number' : 'text';
          ?>
            <label class="text-sm">
              <span class="text-gray-600"><?= esc($label) ?></span>
              <input type="<?= $type ?>" step="<?= $c==='price'?'0.01':'1' ?>"
                     name="<?= esc($c) ?>" value="<?= esc($row[$c]) ?>"
                     class="mt-1 w-full border rounded-lg px-3 py-2" />
            </label>
          <?php endforeach; ?>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" onclick="history.back()" class="px-3 py-2 rounded-lg border">Cancel</button>
          <button class="px-3 py-2 rounded-lg bg-gray-900 text-white">Save</button>
        </div>
      </div>
    </div>

    <!-- Specifications accordions -->
    <div class="mt-8">
      <h2 class="text-lg font-semibold mb-3">Specifications</h2>

      <?php foreach ($specCategories as $cat):
        $items = $specs[$cat];
        $label = ucwords(str_replace('_',' ',$cat));
      ?>
      <details class="rounded-xl border bg-white mb-3" <?= empty($items) ? '' : 'open' ?>>
        <summary class="cursor-pointer select-none px-4 py-3 font-medium"><?= esc($label) ?></summary>
        <div class="p-4 border-t space-y-2" id="wrap_<?= esc($cat) ?>">
          <?php if(!$items) $items=[['key'=>'','value'=>'']]; ?>
          <?php foreach($items as $idx=>$it): ?>
            <div class="flex gap-2 items-center">
              <input name="spec[<?= esc($cat) ?>][<?= $idx ?>][key]"   value="<?= esc($it['key']) ?>"
                     placeholder="Key (e.g., Engine Tech)" class="flex-1 border rounded-lg px-3 py-2 text-sm">
              <input name="spec[<?= esc($cat) ?>][<?= $idx ?>][value]" value="<?= esc($it['value']) ?>"
                     placeholder="Value (e.g., 16-valve DOHC…)" class="flex-1 border rounded-lg px-3 py-2 text-sm">
              <button type="button" class="px-2 py-2 text-sm text-red-600" onclick="this.parentElement.remove()">Remove</button>
            </div>
          <?php endforeach; ?>
          <div>
            <button type="button" class="mt-2 text-sm text-blue-600" onclick="addRow('<?= esc($cat) ?>')">+ Add row</button>
          </div>
        </div>
      </details>
      <?php endforeach; ?>
    </div>
  </form>

<script>
function addRow(cat){
  const wrap = document.getElementById('wrap_'+cat);
  const idx  = wrap.querySelectorAll('input[name^="spec['+cat+']"]').length / 2; // two inputs per row
  const row = document.createElement('div');
  row.className = 'flex gap-2 items-center';
  row.innerHTML = `
    <input name="spec[${cat}][${idx}][key]" placeholder="Key" class="flex-1 border rounded-lg px-3 py-2 text-sm">
    <input name="spec[${cat}][${idx}][value]" placeholder="Value" class="flex-1 border rounded-lg px-3 py-2 text-sm">
    <button type="button" class="px-2 py-2 text-sm text-red-600" onclick="this.parentElement.remove()">Remove</button>
  `;
  wrap.insertBefore(row, wrap.lastElementChild);
}
</script>
</body></html>
