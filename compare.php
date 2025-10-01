<?php
/******************************************************
 * Compare Cars (Tailwind-aligned UI)
 * - mysqli prepared statements
 * - up to 4 slots, duplicate-locking
 * - single main image (s4 -> s8x2 upscale)
 * - grouped specs, RM formatting
 ******************************************************/
// ---- CONFIG ----
include_once 'db/connection.php';
$conn = $connection;
// ---- AJAX: fetch single vehicle payload ----
if (isset($_GET['action']) && $_GET['action'] === 'vehicle' && isset($_GET['id'])) {
    $vehicle_id = (int) $_GET['id'];

    $sql = "SELECT v.*, vp.price, vp.currency, vc.vehicle_configuration
            FROM vehicle_data v
            LEFT JOIN vehicle_price vp ON v.vehicle_id = vp.vehicle_id
            LEFT JOIN vehicle_configurations vc ON v.vehicle_id = vc.vehicle_id
            WHERE v.vehicle_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $vehicle_id);
    $stmt->execute();
    $vehicle = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$vehicle) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Vehicle not found']);
        exit;
    }

    // upscale image resolution if pattern matches
    if (!empty($vehicle['image_url'])) {
        $vehicle['image_url'] = str_replace("s4", "s8x2", $vehicle['image_url']);
    }

    // specifications
    $specs = [];
    $stmt = $conn->prepare("SELECT category, spec_key, spec_value 
                            FROM vehicle_specifications 
                            WHERE vehicle_id = ? 
                            ORDER BY category, spec_key");
    $stmt->bind_param("i", $vehicle_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $specs[] = $row;
    }
    $stmt->close();

    $vehicle['specifications'] = $specs;

    header('Content-Type: application/json');
    echo json_encode($vehicle);
    exit;
}

// ---- SERVER: fetch list for dropdowns ----
$vehicles = [];
$listSql = "SELECT vehicle_id, name, brand, year, image_url 
            FROM vehicle_data 
            ORDER BY brand, name";
$listRes = $conn->query($listSql);
while ($row = $listRes->fetch_assoc()) {
    $vehicles[] = $row;
}
$conn->close();

// helper sanitize
function e($v)
{
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

// page active flag (optional, for nav highlight)
$currentPage = 'compare';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Compare Cars — Automatch</title>

    <!-- Tailwind CDN (same as homepage) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Select2 (we’ll lightly reskin it) -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">

    <!-- Optional: your shared navbar include -->
    <?php /* include 'nav.php';  // if you have a shared nav, include it here */ ?>

    <style>
        /* Select2 light Tailwind-ish skin */
        .select2-container .select2-selection--single {
            height: auto !important;
            min-height: 2.5rem;
            border: 1px solid rgb(229 231 235);
            /* gray-200 */
            border-radius: .5rem;
            /* rounded-lg */
            padding: .5rem .75rem;
        }

        .select2-container .select2-selection__rendered {
            line-height: 1.25rem !important;
            color: rgb(17 24 39);
            /* gray-900 */
        }

        .select2-dropdown {
            border: 1px solid rgb(229 231 235);
            /* gray-200 */
            border-radius: .5rem;
            overflow: hidden;
        }

        .select2-results__option--highlighted {
            background: rgb(219 234 254) !important;
            /* blue-100 */
            color: rgb(17 24 39) !important;
        }
    </style>
    <?php include __DIR__ . '/navbar.php'; ?>
    <?php include __DIR__ . '/modal.php'; ?>
</head>

<body class="bg-gray-50 text-gray-900">
    <!-- If you included nav.php above, you don’t need another header here -->

    <main class="pt-24"> <!-- space for fixed navbar height -->
        <div class="max-w-7xl mx-auto px-4">
            <a href="index.php#recommendations"
                class="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900">
                <span class="inline-block rounded-lg border border-gray-300 px-2 py-1">&larr;</span>
                Back
            </a>

            <h1 class="text-3xl font-bold mt-4">Compare Cars</h1>
            <p class="text-gray-600 mt-1">Select up to 4 cars and compare key specs side by side.</p>

            <!-- Picker Card -->
            <section class="mt-6 rounded-2xl border bg-white shadow-sm p-5">
                <div class="flex items-center justify-between gap-2 flex-wrap">
                    <h2 class="text-lg font-semibold">Select cars</h2>
                    <button id="clearAll" class="text-sm rounded-lg border px-3 py-2 hover:bg-gray-50">Clear
                        all</button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 mt-4">
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <div>
                            <label class="text-sm text-gray-600">Slot <?= $i ?></label>
                            <div class="mt-1 flex gap-2">
                                <select class="car-dropdown w-full"></select>
                                <button class="clear-slot text-sm rounded-lg border px-3 py-2 hover:bg-gray-50"
                                    data-slot="<?= $i ?>">Clear</button>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </section>

            <!-- Comparison Table -->
            <section class="mt-6 overflow-x-auto rounded-2xl border bg-white shadow-sm">
                <table class="min-w-full text-left">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="p-4 text-sm font-semibold text-gray-700 min-w-[160px]">Feature</th>
                            <th class="p-4 text-sm font-semibold text-gray-700" id="carName1">Car 1</th>
                            <th class="p-4 text-sm font-semibold text-gray-700" id="carName2">Car 2</th>
                            <th class="p-4 text-sm font-semibold text-gray-700" id="carName3">Car 3</th>
                            <th class="p-4 text-sm font-semibold text-gray-700" id="carName4">Car 4</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr>
                            <td class="p-4 font-medium text-gray-700">Image</td>
                            <td class="p-4" id="image1"></td>
                            <td class="p-4" id="image2"></td>
                            <td class="p-4" id="image3"></td>
                            <td class="p-4" id="image4"></td>
                        </tr>
                        <tr>
                            <td class="p-4 font-medium text-gray-700">Brand</td>
                            <td class="p-4" id="brand1"></td>
                            <td class="p-4" id="brand2"></td>
                            <td class="p-4" id="brand3"></td>
                            <td class="p-4" id="brand4"></td>
                        </tr>
                        <tr>
                            <td class="p-4 font-medium text-gray-700">Model</td>
                            <td class="p-4" id="model1"></td>
                            <td class="p-4" id="model2"></td>
                            <td class="p-4" id="model3"></td>
                            <td class="p-4" id="model4"></td>
                        </tr>
                        <tr>
                            <td class="p-4 font-medium text-gray-700">Year</td>
                            <td class="p-4" id="year1"></td>
                            <td class="p-4" id="year2"></td>
                            <td class="p-4" id="year3"></td>
                            <td class="p-4" id="year4"></td>
                        </tr>
                        <tr>
                            <td class="p-4 font-medium text-gray-700">Price</td>
                            <td class="p-4 font-semibold" id="price1"></td>
                            <td class="p-4 font-semibold" id="price2"></td>
                            <td class="p-4 font-semibold" id="price3"></td>
                            <td class="p-4 font-semibold" id="price4"></td>
                        </tr>
                        <tr>
                            <td class="p-4 font-medium text-gray-700">Configuration</td>
                            <td class="p-4" id="config1"></td>
                            <td class="p-4" id="config2"></td>
                            <td class="p-4" id="config3"></td>
                            <td class="p-4" id="config4"></td>
                        </tr>
                        <tr>
                            <td class="p-4 align-top font-medium text-gray-700">Specifications</td>
                            <td class="p-4" id="specs1"></td>
                            <td class="p-4" id="specs2"></td>
                            <td class="p-4" id="specs3"></td>
                            <td class="p-4" id="specs4"></td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="5" class="p-4 text-center text-sm text-gray-500">
                                Tip: Use “Clear” on any slot to try different combinations.
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </section>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js"></script>
    <script>
        (function () {
            // preload vehicle list into each select (so Select2 can search)
            const vehicleOptions = [
                <?php foreach ($vehicles as $v):
                    $label = $v['brand'] . ' — ' . $v['name'] . ' (' . $v['year'] . ')';
                    ?>
          { id: <?= (int) $v['vehicle_id'] ?>, text: <?= json_encode($label) ?> },
                <?php endforeach; ?>
            ];

            const fallbackImg = "https://via.placeholder.com/320x180?text=No+Image";
            const money = (v) => {
                if (v === null || v === undefined || v === "") return "";
                const n = Number(v);
                if (Number.isNaN(n)) return v;
                return n.toLocaleString('en-MY', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
            };

            function escapeHtml(s) {
                if (s === null || s === undefined) return '';
                return String(s)
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            }

            function resetSlotUI(n) {
                document.getElementById('carName' + n).textContent = 'Car ' + n;
                ['image', 'brand', 'model', 'year', 'price', 'config', 'specs'].forEach(k => {
                    document.getElementById(k + n).innerHTML = '';
                });
            }

            function setMainImage(slot, url) {
                // apply s4 -> s8x2 just in case
                const upscaled = (url || '').replace('s4', 's8x2');
                const safe = upscaled && upscaled.trim() ? upscaled : fallbackImg;
                document.getElementById('image' + slot).innerHTML =
                    '<img src="' + safe + '" class="w-40 h-auto rounded-lg border border-gray-200 object-cover" onerror="this.src=\'' + fallbackImg + '\'">';
            }

            function buildSpecs(specs) {
                if (!Array.isArray(specs) || specs.length === 0) {
                    return '<div class="text-gray-500">No specifications found.</div>';
                }
                const grouped = {};
                for (const s of specs) {
                    const cat = s.category || 'General';
                    (grouped[cat] ||= []).push(s);
                }
                const order = ["Performance", "Dimensions", "Safety", "Comfort", "General"];
                const cats = Object.keys(grouped).sort((a, b) => {
                    const ai = order.indexOf(a), bi = order.indexOf(b);
                    if (ai === -1 && bi === -1) return a.localeCompare(b);
                    if (ai === -1) return 1;
                    if (bi === -1) return -1;
                    return ai - bi;
                });

                let html = '';
                for (const cat of cats) {
                    html += '<div class="mb-3">';
                    html += '<div class="text-blue-600 font-semibold border-b border-dashed border-gray-200 pb-1 mb-2">' + escapeHtml(cat) + '</div>';
                    html += '<ul class="space-y-1 text-sm">';
                    for (const row of grouped[cat]) {
                        html += '<li><span class="font-medium">' + escapeHtml(row.spec_key) + ':</span> ' + escapeHtml(row.spec_value) + '</li>';
                    }
                    html += '</ul></div>';
                }
                return html;
            }

            function updateOptionLocks() {
                const selects = document.querySelectorAll('.car-dropdown');
                const chosen = new Set();
                selects.forEach(sel => { const v = $(sel).val(); if (v) chosen.add(v); });

                selects.forEach(sel => {
                    const current = $(sel).val();
                    // Rebuild the list so disabled items are hidden
                    $(sel).empty();
                    $(sel).append(new Option('— Search & Select a Car —', ''));
                    vehicleOptions.forEach(opt => {
                        const disabled = chosen.has(String(opt.id)) && String(opt.id) !== String(current || '');
                        const o = new Option(opt.text, opt.id, false, String(opt.id) === String(current || ''));
                        if (disabled) o.disabled = true;
                        $(sel).append(o);
                    });
                });

                $('.car-dropdown').each(function () {
                    $(this).select2('destroy');
                });
                $('.car-dropdown').select2({ width: '100%', placeholder: '— Search & Select a Car —' });
            }

            function fetchAndFill(slot, id) {
                if (!id) {
                    resetSlotUI(slot);
                    updateOptionLocks();
                    return;
                }

                document.getElementById('carName' + slot).innerHTML = '<em class="text-gray-500">Loading…</em>';
                ['image', 'brand', 'model', 'year', 'price', 'config', 'specs'].forEach(k => {
                    document.getElementById(k + slot).innerHTML = '<span class="text-gray-500">Loading…</span>';
                });

                $.getJSON('?action=vehicle&id=' + encodeURIComponent(id), function (car) {
                    if (car && !car.error) {
                        const title = (car.brand ?? '') + ' — ' + (car.name ?? '') + (car.year ? ' (' + car.year + ')' : '');
                        document.getElementById('carName' + slot).textContent = title || ('Car ' + slot);

                        setMainImage(slot, car.image_url || '');
                        document.getElementById('brand' + slot).textContent = car.brand ?? '';
                        document.getElementById('model' + slot).textContent = car.name ?? '';
                        document.getElementById('year' + slot).textContent = car.year ?? '';

                        let priceText = '';
                        if (car.price !== null && car.price !== undefined && car.price !== '') {
                            const cur = (car.currency ? String(car.currency).toUpperCase() : 'RM');
                            priceText = (cur === 'RM') ? ('RM ' + money(car.price)) : (cur + ' ' + money(car.price));
                        } else {
                            priceText = '<span class="text-gray-500">—</span>';
                        }
                        document.getElementById('price' + slot).innerHTML = priceText;

                        document.getElementById('config' + slot).textContent = car.vehicle_configuration || '';
                        document.getElementById('specs' + slot).innerHTML = buildSpecs(car.specifications || []);
                    } else {
                        resetSlotUI(slot);
                    }
                    updateOptionLocks();
                }).fail(function () {
                    resetSlotUI(slot);
                    updateOptionLocks();
                });
            }

            // INIT
            $(document).ready(function () {
                // Seed all selects with the same list
                document.querySelectorAll('.car-dropdown').forEach(sel => {
                    $(sel).append(new Option('— Search & Select a Car —', ''));
                    vehicleOptions.forEach(opt => $(sel).append(new Option(opt.text, opt.id)));
                });

                $('.car-dropdown').select2({ width: '100%', placeholder: '— Search & Select a Car —' });

                $('.car-dropdown').on('change', function () {
                    const selects = Array.from(document.querySelectorAll('.car-dropdown'));
                    const slot = selects.indexOf(this) + 1; // 1..4
                    fetchAndFill(slot, $(this).val());
                });

                $('.clear-slot').on('click', function () {
                    const slot = Number(this.dataset.slot);
                    const selects = document.querySelectorAll('.car-dropdown');
                    $(selects[slot - 1]).val('').trigger('change');
                });

                $('#clearAll').on('click', function () {
                    const selects = document.querySelectorAll('.car-dropdown');
                    selects.forEach(sel => $(sel).val('').trigger('change'));
                });

                // initial lock state
                updateOptionLocks();
            });
        })();
    </script>
</body>

</html>