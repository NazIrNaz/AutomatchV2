<?php
// navbar.php
if (session_status() !== PHP_SESSION_ACTIVE)
  session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/modal.php';

$isLoggedIn = isset($_SESSION['username']);
$username = $isLoggedIn ? $_SESSION['username'] : null;

// Optional active helper
$currentPage = $currentPage ?? basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
function is_active($href)
{
  global $currentPage;
  $h = basename($href);
  return ($h === $currentPage) ? 'text-blue-600 font-semibold' : 'hover:text-blue-600';
}
?>
<!-- Navbar -->
<header class="fixed top-0 inset-x-0 z-50 bg-white shadow-sm">
  <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex h-16 items-center justify-between">
    <a href="index.php" class="flex items-center gap-2 font-semibold text-lg">
      🚗 Automatch <span class="ml-2 px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-600">MY</span>
    </a>

    <!-- Desktop links -->
    <div class="hidden md:flex items-center gap-6">
      <a href="index.php#home" class="<?= is_active('index.php'); ?>">Home</a>
      <a href="index.php#how" class="hover:text-blue-600">How it works</a>
      <a href="browse.php" class="block py-2">Browse Cars</a>
      <a href="recommendation.php" class="hover:text-blue-600">Recommendations</a>
      <a href="compare.php" class="<?= is_active('compare.php'); ?>">Compare</a>
      <a href="index.php#about" class="hover:text-blue-600">About</a>
      <a href="index.php#contact" class="hover:text-blue-600">Contact</a>
    </div>

    <!-- Right side -->
    <div class="hidden md:flex items-center gap-3">
      <?php if ($isLoggedIn): ?>
        <!-- Account dropdown -->
        <div class="relative" x-data="{ open:false }">
          <button class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border hover:bg-gray-50"
            onclick="this.nextElementSibling.classList.toggle('hidden')">
            <span class="font-medium">👤 <?= htmlspecialchars($username ?? 'User'); ?></span>
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
              <path
                d="M5.23 7.21a.75.75 0 011.06.02L10 11.187l3.71-3.955a.75.75 0 111.08 1.04l-4.24 4.52a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" />
            </svg>
          </button>
          <div class="absolute right-0 mt-2 w-48 rounded-lg border bg-white shadow hidden">
            <!-- <a href="recommendations.php" class="block px-3 py-2 text-sm hover:bg-gray-50">Recommendations</a> -->
            <a href="bookmarks.php" class="block px-3 py-2 text-sm hover:bg-gray-50">Bookmarks</a>
            <a href="profile.php" class="block px-3 py-2 text-sm hover:bg-gray-50">Profile</a>
            <div class="my-1 border-t"></div>
            <a href="logout.php" class="block px-3 py-2 text-sm text-red-600 hover:bg-gray-50">Logout</a>
          </div>
        </div>
      <?php else: ?>
        <!-- These open the Tailwind modals -->
        <button type="button" class="px-3 py-1 rounded hover:bg-gray-100" onclick="openModal('loginModal')">Login</button>
        <button type="button" class="px-3 py-1 rounded bg-blue-600 text-white"
          onclick="openModal('registerModal')">Register</button>
      <?php endif; ?>
    </div>

    <!-- Mobile menu button -->
    <button id="menu-btn" class="md:hidden p-2">☰</button>
  </nav>
</header>

<!-- Mobile Menu -->
<div id="mobile-menu" class="fixed inset-0 bg-black/50 hidden">
  <div class="bg-white w-64 h-full p-4">
    <button id="close-btn" class="mb-4">✕ Close</button>
    <a href="index.php#home" class="block py-2">Home</a>
    <a href="index.php#how" class="block py-2">How it works</a>
    <a href="recommendation.php" class="block py-2">Recommendations</a>
    <a href="browse.php" class="block py-2">Browse Cars</a>
    <a href="compare.php" class="block py-2">Compare</a>
    <a href="index.php#about" class="block py-2">About</a>
    <a href="index.php#contact" class="block py-2">Contact</a>
    <div class="mt-4 border-t pt-3">
      <?php if ($isLoggedIn): ?>
        <!-- <a href="recommendation.php" class="block py-2">Account → Recommendations</a> -->
        <a href="profile.php" class="block py-2">Account → Profile</a>
        <a href="logout.php" class="block py-2 text-red-600">Logout</a>
      <?php else: ?>
        <button class="block w-full text-left py-2" onclick="openModal('loginModal')">Login</button>
        <button class="block w-full text-left py-2" onclick="openModal('registerModal')">Register</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  // simple mobile menu toggle
  document.getElementById('menu-btn')?.addEventListener('click', () => document.getElementById('mobile-menu').classList.remove('hidden'));
  document.getElementById('close-btn')?.addEventListener('click', () => document.getElementById('mobile-menu').classList.add('hidden'));

  // close account dropdown when clicking outside (basic)
  document.addEventListener('click', (e) => {
    const dropdowns = document.querySelectorAll('header .relative .absolute');
    dropdowns.forEach(dd => {
      const btn = dd.previousElementSibling;
      if (!dd.contains(e.target) && !btn.contains(e.target)) dd.classList.add('hidden');
    });
  });
</script>