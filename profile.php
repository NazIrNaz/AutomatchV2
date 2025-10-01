<?php
// profile.php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

require_once __DIR__ . '/db/connection.php';

// helpers
function e($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// accept both $conn and $connection
if (!isset($conn) && isset($connection)) { $conn = $connection; }
if (!($conn instanceof mysqli)) { throw new RuntimeException('DB unavailable'); }

$uid       = (int)($_SESSION['user_id'] ?? 0);
$user      = ['username'=>'','email'=>''];
$brands    = [];
$profiles  = [];
$CAR_TYPES = ['Sedan','SUV','Hatchback','MPV','Pickup','Coupe','Wagon','Van','EV','Hybrid'];

function hasColumn(mysqli $conn, $table, $col){
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param('ss', $table, $col);
  $st->execute(); $st->store_result(); $ok = $st->num_rows > 0; $st->close(); return $ok;
}

try {
  // user
  $stmt = $conn->prepare("SELECT username, email FROM users WHERE user_id = ? LIMIT 1");
  $stmt->bind_param('i', $uid); $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc() ?: $user; $stmt->close();

  // brands
  $res = $conn->query("SELECT DISTINCT brand FROM vehicle_data ORDER BY brand");
  while ($row = $res->fetch_assoc()) $brands[] = $row['brand'];

  // profiles (limit 5), tolerate missing JSON column
  $hasCarTypes = hasColumn($conn, 'user_demographics', 'car_types');
  $sqlProfiles = "
    SELECT id, profile_name, monthly_income, monthly_expenses, existing_loans,
           family_members, children, preferred_brands, car_type
           " . ($hasCarTypes ? ", car_types" : ", '' AS car_types") . "
    FROM user_demographics
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 5";
  $stmt = $conn->prepare($sqlProfiles);
  $stmt->bind_param('i', $uid); $stmt->execute();
  $rs = $stmt->get_result();
  while ($row = $rs->fetch_assoc()) $profiles[] = $row;
  $stmt->close();

} catch (Throwable $e) {
  $_SESSION['error'] = 'Failed to load your profile. Please try again.';
  error_log('[profile] ' . $e->getMessage());
} finally {
  if ($conn instanceof mysqli) $conn->close();
}

$profileMap = [];
foreach ($profiles as $p) $profileMap[(int)$p['id']] = $p;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Profile • Automatch</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
  <?php include __DIR__ . '/navbar.php'; ?>
  <?php include __DIR__ . '/modal.php'; ?>

  <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-16">
    <header class="mb-8">
      <h1 class="text-2xl sm:text-3xl font-bold">Your Profile</h1>
      <p class="text-gray-600 mt-1">Keep your account up to date and save demographic profiles for better recommendations.</p>
    </header>

    <?php if (!empty($_SESSION['success'])): ?>
      <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800">
        <?= e($_SESSION['success']); unset($_SESSION['success']); ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
      <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">
        <?= e($_SESSION['error']); unset($_SESSION['error']); ?>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Account -->
      <section class="lg:col-span-1">
        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b">
            <h2 class="font-semibold">Account Information</h2>
            <p class="text-sm text-gray-500">Update your username, email, or password.</p>
          </div>
          <div class="p-5">
            <form action="handler/update_account.php" method="POST" class="space-y-4">
              <div>
                <label class="text-sm font-medium">Username</label>
                <input name="username" required value="<?= e($user['username'] ?? '') ?>"
                  class="mt-1 w-full h-11 rounded-lg border border-gray-300 px-3 outline-none focus:border-blue-500" />
              </div>

              <div>
                <label class="text-sm font-medium">Email</label>
                <input name="email" type="email" required value="<?= e($user['email'] ?? '') ?>"
                  class="mt-1 w-full h-11 rounded-lg border border-gray-300 px-3 outline-none focus:border-blue-500" />
              </div>

              <div class="pt-2 border-t">
                <div class="flex items-center justify-between">
                  <span class="font-medium">Security</span>
                  <span class="text-xs text-gray-500">Leave blank to keep current password</span>
                </div>
                <div class="mt-3 grid grid-cols-1 gap-4">
                  <input name="current_password" type="password" placeholder="Current password"
                    class="w-full h-11 rounded-lg border border-gray-300 px-3 outline-none focus:border-blue-500" />
                  <input name="new_password" type="password" placeholder="New password"
                    class="w-full h-11 rounded-lg border border-gray-300 px-3 outline-none focus:border-blue-500" />
                </div>
              </div>

              <div class="pt-2">
                <button class="w-full h-11 rounded-lg bg-gray-900 text-white hover:bg-black transition">Update Account</button>
              </div>
            </form>
          </div>
        </div>
      </section>

      <!-- Profiles -->
      <section class="lg:col-span-2">
        <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b flex items-center justify-between">
            <div>
              <h2 class="font-semibold">Demographics & Preferences</h2>
              <p class="text-sm text-gray-500">Save up to 5 profiles. Switch quickly to tailor recommendations.</p>
            </div>
            <span class="text-xs rounded-full px-2 py-1 bg-blue-50 text-blue-600 border border-blue-100">
              <?= count($profiles) ?>/5 saved
            </span>
          </div>

          <div class="p-5">
            <!-- Selector -->
            <div class="mb-4 flex items-center gap-3">
              <label class="text-sm font-medium shrink-0">Select Profile</label>
              <select id="profileSelect" class="h-11 rounded-lg border border-gray-300 px-3 w-full max-w-xs">
                <option value="">New profile…</option>
                <?php foreach ($profiles as $p): ?>
                  <option value="<?= (int)$p['id']; ?>">
                    #<?= (int)$p['id']; ?> — <?= e($p['profile_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button id="newProfileBtn" type="button" class="h-11 px-4 rounded-lg border hover:bg-gray-50">
                New
              </button>
            </div>

            <!-- Cards (Edit + Delete on each card) -->
            <?php if (count($profiles) > 0): ?>
              <div class="grid sm:grid-cols-2 gap-4">
                <?php foreach ($profiles as $p):
                  $brandsJson = $p['preferred_brands'] ?? '[]';
                  $brandsArr  = json_decode($brandsJson, true); if (!is_array($brandsArr)) $brandsArr = [];
                  $typesArr   = [];
                  if (!empty($p['car_types'])) {
                    $typesArr = json_decode($p['car_types'], true); if (!is_array($typesArr)) $typesArr = [];
                  } elseif (!empty($p['car_type'])) {
                    $typesArr = array_filter(array_map('trim', explode(',', $p['car_type'])));
                  }
                ?>
                  <div class="rounded-xl border bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between">
                      <div>
                        <div class="font-semibold"><?= e($p['profile_name']); ?></div>
                        <div class="text-xs text-gray-500">ID #<?= (int)$p['id']; ?></div>
                      </div>
                      <div class="flex items-center gap-3">
                        <button type="button" class="text-blue-600 hover:underline text-sm"
                                onclick='openProfileModal(<?= (int)$p["id"]; ?>)'>Edit</button>
                        <form action="handler/delete_profile.php" method="POST" onsubmit="return confirmDelete(<?= (int)$p['id']; ?>)">
                          <input type="hidden" name="profile_id" value="<?= (int)$p['id']; ?>">
                          <button class="text-red-600 hover:underline text-sm" type="submit">Delete</button>
                        </form>
                      </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                      <div class="text-gray-500">Income</div>
                      <div>RM <?= number_format((int)$p['monthly_income']); ?></div>
                      <div class="text-gray-500">Expenses</div>
                      <div>RM <?= number_format((int)$p['monthly_expenses']); ?></div>
                      <div class="text-gray-500">Family</div>
                      <div><?= (int)$p['family_members']; ?> member(s)</div>
                      <?php if (!empty($p['existing_loans'])): ?>
                        <div class="text-gray-500">Loans</div>
                        <div>RM <?= number_format((int)$p['existing_loans']); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($p['children'])): ?>
                        <div class="text-gray-500">Children</div>
                        <div><?= (int)$p['children']; ?></div>
                      <?php endif; ?>
                      <?php if (!empty($typesArr)): ?>
                        <div class="text-gray-500">Types</div>
                        <div><?= e(implode(', ', $typesArr)); ?></div>
                      <?php endif; ?>
                      <?php if (!empty($brandsArr)): ?>
                        <div class="text-gray-500">Brands</div>
                        <div class="truncate" title="<?= e(implode(', ', $brandsArr)); ?>">
                          <?= e(implode(', ', $brandsArr)); ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="text-sm text-gray-500">No profiles yet. Click <em>New</em> or choose “New profile…” from the dropdown.</p>
            <?php endif; ?>

            <?php if (count($profiles) >= 5): ?>
              <p class="mt-3 text-xs text-orange-600">You’ve reached the limit of 5 profiles. Delete one to add another.</p>
            <?php endif; ?>
          </div>
        </div>
      </section>
    </div>
  </main>

  <!-- Expose profile map for JS -->
  <script>window.PROFILE_MAP = <?= json_encode($profileMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;</script>

  <!-- Profile Modal -->
  <div id="profileModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/40" onclick="closeProfileModal()"></div>
    <div class="relative w-full max-w-2xl rounded-2xl bg-white shadow-xl">
      <div class="flex items-center justify-between px-6 py-4 border-b">
        <h3 id="modalTitle" class="text-lg font-semibold">Edit Profile</h3>
        <button type="button" class="text-gray-500 hover:text-gray-700" onclick="closeProfileModal()">✕</button>
      </div>

      <form action="handler/save_demographic.php" method="POST" id="modalForm" class="p-6 space-y-5">
        <input type="hidden" name="profile_id" id="m_profile_id" />

        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="text-sm font-medium">Profile Name</label>
            <input name="profile_name" id="m_profile_name" required
                   class="mt-1 w-full h-11 rounded-lg border border-gray-300 px-3 outline-none focus:border-blue-500"
                   placeholder="e.g., Low Budget, Family SUV" />
          </div>

          <div>
            <label class="text-sm font-medium">Preferred Car Type(s)</label>
            <div class="mt-2 grid grid-cols-2 gap-2">
              <?php foreach ($CAR_TYPES as $ct): ?>
                <label class="inline-flex items-center gap-2 text-sm">
                  <input type="checkbox" name="car_types[]" value="<?= e($ct); ?>" class="rounded border-gray-300 m_car_type">
                  <span><?= e($ct); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="mt-1 text-xs text-gray-500">Pick all that apply.</p>
          </div>
        </div>

        <div class="grid sm:grid-cols-3 gap-4">
          <div>
            <label class="text-sm font-medium">Monthly Income (RM)</label>
            <input type="text" inputmode="numeric" name="monthly_income" id="m_monthly_income" data-money
                   class="mt-1 w-full h-11 rounded-lg border border-gray-300 px-3 outline-none focus:border-blue-500"/>
          </div>
          <div>
            <label class="text-sm font-medium">Monthly Expenses (RM)</label>
            <input type="text" inputmode="numeric" name="monthly_expenses" id="m_monthly_expenses" data-money
                   class="mt-1 w-full h-11 rounded-lg border border-gray-300 px-3 outline-none focus:border-blue-500"/>
          </div>
          <div>
            <label class="text-sm font-medium">Family Members</label>
            <input type="text" inputmode="numeric" name="family_members" id="m_family_members" data-int
                   class="mt-1 w-full h-11 rounded-lg border border-gray-300 px-3 outline-none focus:border-blue-500"/>
          </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="text-sm font-medium">Existing Loans (optional)</label>
            <input type="text" inputmode="numeric" name="existing_loans" id="m_existing_loans" data-money
                   class="mt-1 w-full h-11 rounded-lg border border-gray-300 px-3 outline-none focus:border-blue-500"/>
          </div>
          <div>
            <label class="text-sm font-medium">Children (optional)</label>
            <input type="text" inputmode="numeric" name="children" id="m_children" data-int
                   class="mt-1 w-full h-11 rounded-lg border border-gray-300 px-3 outline-none focus:border-blue-500"/>
          </div>
        </div>

        <div>
          <label class="text-sm font-medium">Preferred Brands</label>
          <div class="mt-2 grid grid-cols-2 sm:grid-cols-3 gap-2" id="m_brands_container">
            <?php foreach ($brands as $brand): ?>
              <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="preferred_brands[]" value="<?= e($brand); ?>" class="rounded border-gray-300 m_brand">
                <span><?= e($brand); ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="mt-1 text-xs text-gray-500">Pick as many as you like.</p>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
          <button type="button" class="h-11 px-5 rounded-lg border hover:bg-gray-50" onclick="closeProfileModal()">Cancel</button>
          <button class="h-11 px-5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function confirmDelete(id){ return confirm('Delete this profile (ID #' + id + ')? This cannot be undone.'); }

    // modal open/close
    function openProfileModal(id){
      const overlay = document.getElementById('profileModal');
      const title   = document.getElementById('modalTitle');
      clearModal();

      if (id) { // edit existing
        const data = (window.PROFILE_MAP || {})[id];
        if (!data) return alert('Failed to load selected profile.');
        title.textContent = 'Edit Profile';
        fillModal(data);
      } else {
        title.textContent = 'New Profile';
        document.getElementById('m_profile_id').value = '';
      }
      overlay.classList.remove('hidden');
      overlay.classList.add('flex');
    }
    function closeProfileModal(){
      const overlay = document.getElementById('profileModal');
      overlay.classList.add('hidden');
      overlay.classList.remove('flex');
    }

    // dropdown + "New" btn
    const sel = document.getElementById('profileSelect');
    document.getElementById('newProfileBtn')?.addEventListener('click', ()=> openProfileModal(null));
    sel?.addEventListener('change', () => {
      const id = sel.value;
      if (!id) { openProfileModal(null); return; }
      openProfileModal(parseInt(id,10));
    });

    // helpers for modal
    function fillModal(data){
      document.getElementById('m_profile_id').value = data.id || '';
      setVal('m_profile_name', data.profile_name || '');
      setRawNumber('m_monthly_income', data.monthly_income || '');
      setRawNumber('m_monthly_expenses', data.monthly_expenses || '');
      setRawNumber('m_existing_loans', data.existing_loans || '');
      setRawNumber('m_family_members', data.family_members || '');
      setRawNumber('m_children', data.children || '');

      // brands
      const b = parseJsonSafe(data.preferred_brands, []);
      document.querySelectorAll('.m_brand').forEach(cb => cb.checked = b.includes(cb.value));

      // car types prefer JSON, fallback CSV
      let t = parseJsonSafe(data.car_types, null);
      if (!Array.isArray(t)) t = String(data.car_type || '').split(',').map(s=>s.trim()).filter(Boolean);
      document.querySelectorAll('.m_car_type').forEach(cb => cb.checked = (t || []).includes(cb.value));
    }
    function clearModal(){
      ['m_profile_id','m_profile_name','m_monthly_income','m_monthly_expenses','m_existing_loans','m_family_members','m_children']
        .forEach(id => setVal(id,''));
      document.querySelectorAll('.m_brand,.m_car_type').forEach(cb => cb.checked = false);
    }
    function setVal(id, v){ const el = document.getElementById(id); if (el) el.value = v; }
    function parseJsonSafe(s, fallback){ try { const j = (typeof s === 'string') ? JSON.parse(s || 'null') : s; return (j==null?fallback:j); } catch { return fallback; } }

    // money/int formatting (modal)
    const moneyInputs = Array.from(document.querySelectorAll('#profileModal [data-money]'));
    const intInputs   = Array.from(document.querySelectorAll('#profileModal [data-int]'));
    const formatMoney = (v) => { const n = v.replace(/[^\d]/g,''); if (!n) return ''; return Number(n).toLocaleString('en-MY'); };
    const unformat    = (v) => v.replace(/[^\d]/g,'');

    moneyInputs.forEach(el=>{ el.addEventListener('input', (e)=> { e.target.value = formatMoney(e.target.value); }); });
    intInputs.forEach(el=>{ el.addEventListener('input', (e)=> { e.target.value = unformat(e.target.value); }); });

    function setRawNumber(id, val){
      const el = document.getElementById(id); if (!el) return;
      const s = String(val ?? '');
      if (el.hasAttribute('data-money')) el.value = formatMoney(s); else el.value = unformat(s);
    }

    // strip separators on submit
    document.getElementById('modalForm')?.addEventListener('submit', function(){
      moneyInputs.concat(intInputs).forEach(el => { el.value = unformat(el.value); });
    });
  </script>
</body>
</html>
