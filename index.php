<?php
session_start();
require_once "db/connection.php";

// check if user is logged in
$isLoggedIn = isset($_SESSION['username']);
$username = $isLoggedIn ? $_SESSION['username'] : null;

$vehicles = [];
$sql = "SELECT vehicle_id, name, brand, year, image_url
        FROM vehicle_data
        ORDER BY RAND()
        LIMIT 5";

$result = $connection->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $vehicles[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automatch</title>
    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 text-gray-900">
<?php
include 'navbar.php'; 
?>
    <!-- Hero -->
    <section id="home" class="pt-28 pb-16 text-center bg-gradient-to-r from-blue-50 to-purple-50">
        <h1 class="text-4xl font-bold">Smarter car choices for Malaysia</h1>
        <p class="mt-4 text-gray-600 max-w-xl mx-auto">
            Automatch helps you find the right car for your budget, needs, and lifestyle.
        </p>
        <div class="mt-6 flex justify-center gap-4">
            <!-- <a href="questionnaire.php" class="px-6 py-3 rounded bg-blue-600 text-white">Start questionnaire →</a> -->
            <a href="browse.php" class="px-6 py-3 rounded border border-gray-300">Browse cars</a>
        </div>
    </section>

    <!-- How it works -->
    <section id="how" class="py-16 bg-gray-50">
        <div class="max-w-6xl mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold">How it works</h2>
                <p class="mt-2 text-gray-600">From quick questions to confident car choices — in just minutes.</p>
            </div>

            <div class="grid gap-8 md:grid-cols-3">
                <!-- Step 1 -->
                <div class="bg-white p-6 rounded-xl shadow hover:shadow-lg transition">
                    <div class="text-blue-600 text-sm font-semibold mb-2">Step 1</div>
                    <h3 class="text-xl font-semibold">Tell us your budget & needs</h3>
                    <p class="mt-2 text-gray-600">
                        Share your monthly budget, family size, driving habits, and preferred brands.
                    </p>
                </div>

                <!-- Step 2 -->
                <div class="bg-white p-6 rounded-xl shadow hover:shadow-lg transition">
                    <div class="text-blue-600 text-sm font-semibold mb-2">Step 2</div>
                    <h3 class="text-xl font-semibold">We crunch the numbers</h3>
                    <p class="mt-2 text-gray-600">
                        Our engine calculates affordability, compares real costs, and filters cars that fit.
                    </p>
                </div>

                <!-- Step 3 -->
                <div class="bg-white p-6 rounded-xl shadow hover:shadow-lg transition">
                    <div class="text-blue-600 text-sm font-semibold mb-2">Step 3</div>
                    <h3 class="text-xl font-semibold">Compare & decide</h3>
                    <p class="mt-2 text-gray-600">
                        Review side-by-side specs, pros/cons, and shortlist your top matches before buying.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Example Vehicles -->
    <section class="py-12 max-w-6xl mx-auto">
        <h2 class="text-2xl font-semibold mb-6">Example Vehicles</h2>
        <div class="grid sm:grid-cols-2 md:grid-cols-3 gap-6">
            <?php foreach ($vehicles as $v): ?>
                <div class="border rounded-xl overflow-hidden shadow hover:shadow-lg transition">
                    <img src="<?php echo htmlspecialchars(str_replace("s4", "s8x2", $v['image_url'])); ?>" alt=""
                        class="w-full h-48 object-cover">
                    <div class="p-4">
                        <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($v['brand'] . " " . $v['name']); ?>
                        </h3>
                        <p class="text-sm text-gray-500">Year: <?php echo $v['year']; ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- About -->
    <section id="about" class="py-16 bg-white">
        <div class="max-w-4xl mx-auto px-4 text-center">
            <h2 class="text-3xl font-bold">About Automatch</h2>
            <p class="mt-4 text-gray-600 leading-relaxed">
                Automatch is a car recommendation platform built to help Malaysians make smarter,
                budget-friendly car purchase decisions. <br>
                By combining affordability, lifestyle needs, and real-world ownership costs,
                we provide guidance that goes beyond just comparing price tags.
            </p>
        </div>
    </section>

    <!-- Contact -->
<section id="contact" class="py-16 bg-gray-50">
  <div class="max-w-4xl mx-auto px-4 text-center">
    <h2 class="text-3xl font-bold">Contact Us</h2>
    <p class="mt-4 text-gray-600">
      Got questions, feedback, or partnership inquiries? Reach out to us anytime.
    </p>

    <div class="mt-6">
      <a href="mailto:nazrulirzannazri@gmail.com"
         class="inline-block px-6 py-3 rounded-lg bg-blue-600 text-white font-medium hover:bg-blue-700 transition">
        📧 Email: nazrulirzannazri@gmail.com
      </a>
    </div>
  </div>
</section>

    <!-- Footer -->
    <footer id="contact" class="border-t py-6 text-center text-sm text-gray-500">
        © <?php echo date("Y"); ?> Automatch. All rights reserved.
    </footer>

    <script>
        // Mobile menu toggle
        const menuBtn = document.getElementById('menu-btn');
        const closeBtn = document.getElementById('close-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        menuBtn.addEventListener('click', () => mobileMenu.classList.remove('hidden'));
        closeBtn.addEventListener('click', () => mobileMenu.classList.add('hidden'));
    </script>
</body>

</html>