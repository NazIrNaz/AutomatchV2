<?php
require_once __DIR__.'/_guard.php';
require_once __DIR__.'/db/connection.php';
require_once __DIR__.'/_lib.php';

// users list + profile counts
$users = $conn->query("
  SELECT u.user_id, u.username, u.email,
         (SELECT COUNT(*) FROM user_demographics d WHERE d.user_id=u.user_id) AS profiles
  FROM users u ORDER BY u.user_id DESC
");

// optional: fetch profiles for a selected user
$selectedId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$profiles = null;
if ($selectedId>0){
  $stmt = $conn->prepare("SELECT * FROM user_demographics WHERE user_id=? ORDER BY created_at DESC");
  $stmt->bind_param("i",$selectedId);
  $stmt->execute();
  $profiles = $stmt->get_result();
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Automatch Admin — Users</title>
<script src="https://cdn.tailwindcss.com"></script>
</head><body class="bg-gray-50 text-gray-900">
<?php include __DIR__.'/sidebar.php'; ?>
<main class="ml-64 p-6">
  <h1 class="text-2xl font-bold">Users & Profiles</h1>
  <p class="text-gray-600">View users and their saved demographics profiles.</p>

  <div class="overflow-x-auto mt-6">
    <table class="min-w-full border text-sm">
      <thead class="bg-gray-100">
        <tr>
          <th class="px-4 py-2 text-left">User</th>
          <th class="px-4 py-2 text-left">Email</th>
          <th class="px-4 py-2 text-center">Demograhpic Profiles</th>
          <th class="px-4 py-2 text-center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php while($u=$users->fetch_assoc()): ?>
        <tr class="border-b">
          <td class="px-4 py-2 font-medium"><?= esc($u['username']) ?></td>
          <td class="px-4 py-2"><?= esc($u['email']) ?></td>
          <td class="px-4 py-2 text-center"><?= (int)$u['profiles'] ?></td>
          <td class="px-4 py-2 text-center">
            <a class="text-blue-600 hover:underline" href="?user_id=<?= (int)$u['user_id'] ?>">View profiles</a>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>

  <?php if($profiles): ?>
    <h2 class="text-xl font-semibold mt-8">Profiles for User #<?= (int)$selectedId ?></h2>
    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
      <?php while($p=$profiles->fetch_assoc()): ?>
        <div class="rounded-xl border bg-white p-4 shadow-sm">
          <div class="flex items-center justify-between">
            <div class="font-medium"><?= esc($p['profile_name']); ?></div>
            <div class="text-xs text-gray-500"><?= esc($p['created_at']); ?></div>
          </div>
          <dl class="text-sm mt-3 space-y-2">
            <div class="flex justify-between"><dt class="text-gray-500">Income</dt><dd>RM <?= number_format((int)$p['monthly_income']); ?></dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Expenses</dt><dd>RM <?= number_format((int)$p['monthly_expenses']); ?></dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Loans</dt><dd>RM <?= number_format((int)$p['existing_loans']); ?></dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Family</dt><dd><?= (int)$p['family_members']; ?> (kids: <?= (int)$p['children']; ?>)</dd></div>
            <div class="flex justify-between"><dt class="text-gray-500">Car Type</dt><dd><?= esc($p['car_type']); ?></dd></div>
            <div><dt class="text-gray-500">Preferred Brands</dt><dd class="mt-1"><?= esc($p['preferred_brands']); ?></dd></div>
          </dl>
        </div>
      <?php endwhile; ?>
    </div>
  <?php endif; ?>

</main>
</body></html>
