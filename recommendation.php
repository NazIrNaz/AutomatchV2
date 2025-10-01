<?php
// ---------------------------------------------------------
// recommendations.php — Automatch (Tailwind · Light Theme)
// ---------------------------------------------------------
session_start();

/**
 * Ensure the Flask API (appRec.py) is running locally.
 * - Tries GET /health (127.0.0.1:5000)
 * - If down, spawns the API in background (Windows or *nix)
 * - Re-checks after a short sleep
 */
function ensureApiUp(): array
{
    $healthUrl = "http://127.0.0.1:5000/health";

    $try = function () use ($healthUrl) {
        $ch = curl_init($healthUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && $response);
    };

    if ($try())
        return [true, "API running"];

    $appPath = escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . "appRec.py");
    $isWindows = stripos(PHP_OS, "WIN") === 0;

    if ($isWindows) {
        // Typical Laragon/XAMPP on Windows
        $cmd = 'cmd /c "start /B "" python ' . $appPath . '"';
        pclose(popen($cmd, "r"));
    } else {
        // *nix deployment
        exec('nohup python3 ' . $appPath . ' > /dev/null 2>&1 &');
    }

    usleep(500000); // 0.5s
    return [$try(), $try() ? "API started" : "API failed to start"];
}
list($apiOk, $apiMsg) = ensureApiUp();

// --- Load user's saved profiles (if you have sessions/users set up) ---
$profiles = [];
$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($user_id > 0) {
    // Adjust to your DB layer as needed (this page keeps UI self-contained)
    $host = "127.0.0.1";
    $user = "root";
    $pass = "@dmin321";
    $db = "fypbetatest";
    if ($conn = @new mysqli($host, $user, $pass, $db)) {
        $sql = "SELECT id, profile_name, monthly_income, monthly_expenses, existing_loans,
                       family_members, children, preferred_brands, car_type, created_at
                FROM user_demographics
                WHERE user_id = ?
                ORDER BY created_at DESC, id DESC";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc())
                $profiles[] = $row;
            $stmt->close();
        }
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Automatch · Recommendations</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <!-- Tailwind CDN (swap to your compiled CSS in production) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .badge-toggle {
            @apply inline-flex items-center rounded-full px-4 py-1.5 text-sm font-medium border transition cursor-pointer;
        }

        .badge-toggle:not(.active) {
            @apply bg-slate-100 border-slate-300 text-slate-700 hover:bg-slate-200;
        }

        .badge-toggle.active {
            @apply bg-blue-600 border-blue-600 text-white shadow-sm;
        }

        .badge {
            @apply inline-flex items-center rounded-full bg-slate-100 border border-slate-200 text-slate-700 text-xs px-2 py-0.5;
        }

        .btn {
            @apply inline-flex items-center justify-center rounded-lg px-4 py-2 font-medium;
        }

        .btn-primary {
            @apply bg-blue-600 text-white border border-blue-600 hover:bg-blue-500 shadow-sm;
        }

        .btn-outline {
            @apply border border-slate-300 text-slate-800 hover:bg-slate-50;
        }

        .btn-ghost {
            @apply border border-transparent text-slate-700 hover:bg-slate-100;
        }

        .card {
            @apply rounded-2xl border border-slate-200 bg-white;
        }

        .focus-ring {
            @apply focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500;
        }

        .skeleton {
            @apply animate-pulse bg-slate-100 rounded-xl;
        }

        .grid-auto {
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        }

        .page--collapsed {
            grid-template-columns: 1fr !important;
        }

        .chip {
            @apply inline-flex items-center gap-2 rounded-full px-3 py-1 border text-sm;
        }

        .chip-on {
            @apply bg-blue-50 border-blue-200 text-blue-700;
        }

        .chip-off {
            @apply bg-slate-50 border-slate-200 text-slate-700;
        }
    </style>
</head>

<body class="bg-slate-50 text-slate-900">
    <!-- Toasts -->
    <div id="toasts" class="fixed right-4 bottom-4 z-[60] space-y-2" aria-live="polite"></div>

    <!-- Navbar -->
    <?php include 'navbar.php'; ?>
    <div class="h-16"></div>
    <!-- Page -->
    <main id="page" class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8
           grid grid-cols-1 xl:grid-cols-[400px_1fr] gap-10">

        <!-- Sidebar (sticky, collapsible) -->
        <aside class="card p-6 h-fit xl:sticky xl:top-6 self-start" aria-labelledby="prefsTitle">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 id="prefsTitle" class="text-xl font-semibold tracking-tight">Your preferences</h2>
                    <p class="text-xs text-slate-500 mt-1">Leave “Max monthly” blank to auto-use ~20% of disposable
                        income.</p>
                </div>
                <div class="flex items-center gap-2">
                    <span id="apiBadge"
                        class="badge <?= $apiOk ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-amber-50 border-amber-200 text-amber-700' ?>"><?= htmlspecialchars($apiMsg) ?></span>
                    <button id="toggleSidebar" class="btn btn-ghost focus-ring hidden xl:inline-flex"
                        title="Hide filters">Hide</button>
                </div>
            </div>

            <!-- Profile quick-fill -->
            <div class="mb-4">
                <label class="text-xs text-slate-600">Load profile</label>
                <div class="flex gap-2 mt-1">
                    <select id="profile_select"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 focus-ring">
                        <option value="">— Choose a profile —</option>
                    </select>
                    <button id="btnUseProfile" class="btn btn-outline focus-ring">Use</button>
                    <button id="btnClearProfile" class="btn btn-outline focus-ring">Clear</button>
                </div>
                <div id="profileSummary" class="mt-2 hidden text-xs text-slate-600"></div>
                <script>window.__profiles = <?= json_encode($profiles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
            </div>

            <!-- Inputs -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-xs text-slate-600">Monthly income (MYR)</label>
                    <input id="monthly_income" type="number"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus-ring text-[15px]" />
                </div>
                <div>
                    <label class="text-xs text-slate-600">Monthly expenses (MYR)</label>
                    <input id="monthly_expenses" type="number"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus-ring text-[15px]" />
                </div>
                <div>
                    <label class="text-xs text-slate-600">Family size</label>
                    <input id="family_size" type="number" min="1"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus-ring text-[15px]" />
                </div>

                <!-- Preferred brands (chips) -->
                <div>
                    <label class="text-xs text-slate-600">Preferred brands</label>
                    <div class="mt-1 flex flex-wrap gap-2" id="brandChips" aria-live="polite"></div>
                    <input id="brandInput" type="text" placeholder="Type brand • Enter"
                        class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 focus-ring text-[15px]" />
                </div>

                <!-- Vehicle config (checkbox chips) -->
                <div class="md:col-span-2">
                    <label class="text-xs text-slate-600">Vehicle configuration</label>
                    <div id="configChips" class="mt-2 flex flex-wrap gap-2">
                        <!-- chips inserted by JS -->
                    </div>
                </div>

                <div>
                    <label class="text-xs text-slate-600">Max monthly payment (MYR) <span
                            class="text-slate-500">(optional)</span></label>
                    <input id="max_monthly_payment" type="number" placeholder="Auto if blank"
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus-ring text-[15px]" />
                </div>
            </div>

            <!-- Advanced financing -->
            <details class="mt-4">
                <summary class="text-[15px] font-medium cursor-pointer select-none">Advanced financing</summary>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                    <div>
                        <label class="text-xs text-slate-600">Down payment (%)</label>
                        <input id="down_payment" type="number" min="0" max="100"
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus-ring text-[15px]" />
                    </div>
                    <div>
                        <label class="text-xs text-slate-600">Loan tenure (years)</label>
                        <input id="loan_tenure" type="number" min="1" max="12"
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus-ring text-[15px]" />
                    </div>
                    <div>
                        <label class="text-xs text-slate-600">Interest rate (% p.a.)</label>
                        <input id="interest_rate" type="number" step="0.01"
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus-ring text-[15px]" />
                    </div>
                </div>
            </details>

            <div class="flex flex-wrap items-center gap-3 mt-5">
                <button id="btnRecommend"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-white font-medium shadow hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1">
                    <svg id="spinner" class="hidden h-5 w-5 animate-spin mr-2" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" d="M4 12a8 8 0 018-8v4" stroke="currentColor" stroke-width="4"
                            stroke-linecap="round"></path>
                    </svg>
                    Get recommendations
                </button>
                <button id="btnReset"
                    class="inline-flex items-center gap-2 rounded-lg bg-slate-100 px-5 py-2.5 text-slate-700 font-medium border border-slate-300 hover:bg-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-1">Reset</button>
                <span id="status" class="text-sm text-slate-600" aria-live="    polite"></span>
            </div>

            <div id="errorBox"
                class="hidden mt-4 rounded-lg border border-red-200 bg-red-50 text-red-700 px-3 py-2 text-sm"></div>
        </aside>

        <!-- Show-sidebar button (when collapsed) -->
        <button id="showSidebar" class="fixed z-40 bottom-24 right-5 xl:right-10 btn btn-primary focus-ring hidden">
            Show filters
        </button>

        <!-- Results -->
        <section class="card p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold tracking-tight">Results</h2>
                    <p class="text-sm text-slate-600 -mt-0.5">Best match first, then close alternatives.</p>
                </div>
                <div class="flex gap-2">
                   <div class="flex items-center gap-2">
  <button class="badge-toggle active" data-filter="preferredBrand">
    Preferred brands
  </button>
  <button class="badge-toggle" data-filter="config">
    Config match
  </button>
</div>
                    <div class="flex items-center gap-2">
                        <label for="sortBy" class="text-sm text-slate-600">Sort</label>
                        <select id="sortBy" class="rounded-lg border border-slate-300 px-3 py-2 focus-ring text-sm">
                            <option value="best">Best match</option>
                            <option value="priceAsc">Price · Low–High</option>
                            <option value="priceDesc">Price · High–Low</option>
                            <option value="monthlyAsc">Monthly · Low–High</option>
                            <option value="monthlyDesc">Monthly · High–Low</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Hero -->
            <div id="topSuggestion" class="mt-5"></div>

            <!-- Preferred -->
            <div class="mt-8 flex items-center gap-2">
                <h3 class="font-semibold text-[17px]">Preferred matches</h3>
                <span id="prefCount" class="badge">0</span>
            </div>
            <div id="preferredList" class="mt-3 grid gap-5 grid-auto"></div>

            <!-- Alternatives -->
            <div class="mt-10 flex items-center gap-2">
                <h3 class="font-semibold text-[17px]">Other strong alternatives</h3>
                <span id="altCount" class="badge">0</span>
            </div>
            <div id="altList" class="mt-3 grid gap-5 grid-auto"></div>
        </section>
    </main>

    <!-- Compare drawer -->
    <div id="compareBar" class="fixed left-0 right-0 bottom-0 z-50 hidden">
        <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8">
            <div class="card p-3 flex items-center justify-between shadow-lg">
                <div class="flex items-center gap-3" id="compareSlots"></div>
                <div class="flex items-center gap-2">
                    <button id="btnClearCompare" class="btn btn-outline focus-ring">Clear</button>
                    <button id="btnCompare" class="btn btn-primary focus-ring">Compare (0/3)</button>
                </div>
            </div>
        </div>
    </div>

    <footer class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8 py-10 text-slate-500 text-sm">
        © <?= date('Y') ?> Automatch
    </footer>

    <script>
        // ---------- Utilities ----------
        const $ = (id) => document.getElementById(id);
        const toastBox = document.getElementById("toasts");
        function toast(msg, type = "info") {
            const colors = { info: "bg-slate-900 text-white", error: "bg-red-600 text-white", success: "bg-emerald-600 text-white" };
            const el = document.createElement("div");
            el.className = `rounded-lg px-3 py-2 shadow ${colors[type]} transition opacity-0`;
            el.textContent = msg;
            toastBox.appendChild(el);
            requestAnimationFrame(() => { el.classList.remove("opacity-0"); });
            setTimeout(() => { el.classList.add("opacity-0"); setTimeout(() => el.remove(), 200); }, 2600);
        }
        function setApiBadge(ok) {
            const badge = $("apiBadge");
            badge.textContent = ok ? "API running" : "API offline";
            badge.className = "badge " + (ok ? "bg-emerald-50 border-emerald-200 text-emerald-700" : "bg-red-50 border-red-200 text-red-700");
        }

        // ---------- Sidebar collapse ----------
        const pageEl = document.getElementById("page");
        const aside = document.querySelector("aside");
        const showBtn = document.getElementById("showSidebar");
        const hideBtn = document.getElementById("toggleSidebar");
        function collapseSidebar() {
            aside.classList.add("hidden");
            pageEl.classList.add("page--collapsed");
            showBtn.classList.remove("hidden");
        }
        function expandSidebar() {
            aside.classList.remove("hidden");
            pageEl.classList.remove("page--collapsed");
            showBtn.classList.add("hidden");
        }
        hideBtn?.addEventListener("click", collapseSidebar);
        showBtn?.addEventListener("click", expandSidebar);

        // ---------- Profiles ----------
        (function initProfiles() {
            const sel = $("profile_select");
            (window.__profiles || []).forEach(p => {
                const opt = document.createElement("option");
                opt.value = String(p.id);
                opt.textContent = `${p.profile_name} (ID #${p.id})`;
                sel.appendChild(opt);
            });
        })();

        function parseBrandsString(s) {
            if (!s) return [];
            s = String(s).trim();
            if (s.startsWith("[") && s.endsWith("]")) {
                try {
                    const arr = JSON.parse(s.replace(/'/g, '"'));
                    if (Array.isArray(arr)) return arr.map(x => String(x).trim()).filter(Boolean);
                } catch { }
            }
            return s.split(",").map(x => x.trim()).filter(Boolean);
        }

        function applyProfileSummary(p) {
            const el = $("profileSummary");
            el.innerHTML = `
        <div class="rounded-lg bg-slate-50 border border-slate-200 px-3 py-2">
          <div class="text-slate-800 font-medium">${p.profile_name}</div>
          <div class="text-slate-600 mt-1 text-[12px]">Income: RM ${Number(p.monthly_income).toLocaleString()} • Family: ${p.family_members} • Types: ${p.car_type || "-"}</div>
        </div>`;
            el.classList.remove("hidden");
        }

        // ---------- Brand chips ----------
        function chipInput(containerId, inputId) {
            const cont = $(containerId), input = $(inputId);
            let values = [];
            function render() {
                cont.innerHTML = "";
                values.forEach((v, i) => {
                    const chip = document.createElement("span");
                    chip.className = "inline-flex items-center gap-1 bg-slate-100 text-slate-800 rounded-full px-2.5 py-1 text-xs border border-slate-200";
                    chip.innerHTML = `<span>${v}</span><button aria-label="Remove ${v}" data-i="${i}" class="rounded-full p-0.5 hover:bg-slate-200">✕</button>`;
                    cont.appendChild(chip);
                });
            }
            function add(val) {
                const v = String(val || "").trim();
                if (!v) return;
                if (!values.includes(v)) { values.push(v); render(); persist(); }
                input.value = "";
            }
            function remove(i) { values.splice(i, 1); render(); persist(); }
            cont.addEventListener("click", (e) => {
                const i = e.target?.dataset?.i;
                if (i !== undefined) remove(Number(i));
            });
            input.addEventListener("keydown", (e) => {
                if (e.key === "Enter") { e.preventDefault(); add(input.value); }
                if (e.key === "Backspace" && input.value === "" && values.length) { values.pop(); render(); persist(); }
            });
            function set(list) { values = (list || []).map(String); render(); }
            function get() { return values.slice(); }
            function persist() { saveInputs(); }
            return { add, remove, set, get };
        }
        const brandChips = chipInput("brandChips", "brandInput");

        // ---------- Config chips (checkbox style) ----------
        const CONFIG_OPTIONS = ["SUV", "Sedan", "Hatchback", "MPV", "Pickup"];
        function initConfigChips() {
            const box = $("configChips"); box.innerHTML = "";
            CONFIG_OPTIONS.forEach(name => {
                const id = "cfg_" + name.toLowerCase();
                const btn = document.createElement("button");
                btn.type = "button";
                btn.className = "chip chip-off focus-ring";
                btn.dataset.value = name;
                btn.innerHTML = `<span>${name}</span>`;
                btn.addEventListener("click", () => {
                    btn.classList.toggle("chip-on");
                    btn.classList.toggle("chip-off");
                    saveInputs();
                });
                box.appendChild(btn);
            });
        }
        function getSelectedConfigs() {
            return [...document.querySelectorAll("#configChips .chip-on")].map(b => b.dataset.value);
        }
        function setSelectedConfigs(list) {
            const set = new Set((list || []).map(String));
            document.querySelectorAll("#configChips .chip").forEach(b => {
                const on = set.has(b.dataset.value);
                b.classList.toggle("chip-on", on);
                b.classList.toggle("chip-off", !on);
            });
        }

        // ---------- Defaults & persistence ----------
        const defaults = {
            monthly_income: 8000, monthly_expenses: 2000, family_size: 4,
            down_payment: 10, loan_tenure: 7, interest_rate: 3.5,
            max_monthly_payment: "",
            preferred_brands: ["Honda", "Toyota"],
            vehicle_config: ["SUV"]
        };

        function loadInputs() {
            initConfigChips();
            const saved = JSON.parse(localStorage.getItem("am_prefs") || "{}");
            const v = { ...defaults, ...saved };
            $("monthly_income").value = v.monthly_income;
            $("monthly_expenses").value = v.monthly_expenses;
            $("family_size").value = v.family_size;
            $("down_payment").value = v.down_payment;
            $("loan_tenure").value = v.loan_tenure;
            $("interest_rate").value = v.interest_rate;
            $("max_monthly_payment").value = v.max_monthly_payment || "";
            brandChips.set(v.preferred_brands || defaults.preferred_brands);
            setSelectedConfigs(v.vehicle_config || defaults.vehicle_config);
        }
        function saveInputs() {
            const data = collectPayload();
            localStorage.setItem("am_prefs", JSON.stringify({
                monthly_income: data.monthly_income,
                monthly_expenses: data.monthly_expenses,
                family_size: data.family_size,
                down_payment: data.down_payment,
                loan_tenure: data.loan_tenure,
                interest_rate: data.interest_rate,
                max_monthly_payment: data.max_monthly_payment || "",
                preferred_brands: brandChips.get(),
                vehicle_config: getSelectedConfigs(),
            }));
        }
        loadInputs();

        function applyProfile(p) {
            $("monthly_income").value = Number(p.monthly_income || 0);
            $("monthly_expenses").value = Number(p.monthly_expenses || 0);
            $("family_size").value = Number(p.family_members || 1);
            brandChips.set(parseBrandsString(p.preferred_brands || ""));
            setSelectedConfigs((p.car_type || "").split(",").map(x => x.trim()).filter(Boolean));
            applyProfileSummary(p);
            saveInputs();
        }
        $("btnUseProfile").addEventListener("click", () => {
            const id = $("profile_select").value;
            const arr = (window.__profiles || []);
            const p = arr.find(x => String(x.id) === String(id));
            if (!p) return toast("Choose a profile first", "info");
            applyProfile(p);
        });
        $("btnClearProfile").addEventListener("click", () => {
            $("profile_select").value = "";
            $("profileSummary").classList.add("hidden");
            localStorage.removeItem("am_prefs");
            loadInputs();
            toast("Cleared to defaults", "success");
        });

        // ---------- Payload ----------
        function collectPayload() {
            return {
                monthly_income: Number($("monthly_income").value || 0),
                monthly_expenses: Number($("monthly_expenses").value || 0),
                family_size: Number($("family_size").value || 1),
                preferred_brands: brandChips.get(),
                vehicle_configuration: getSelectedConfigs().join(","),
                down_payment: Number($("down_payment").value || 0),
                loan_tenure: Number($("loan_tenure").value || 0),
                interest_rate: Number($("interest_rate").value || 0),
                max_monthly_payment: $("max_monthly_payment").value ? Number($("max_monthly_payment").value) : null
            };
        }

        // ---------- Skeletons ----------
        function skeletonHero() {
            return `
        <div class="card p-6">
          <div class="grid gap-6 xl:grid-cols-[1.25fr_1fr]">
            <div class="skeleton h-80"></div>
            <div>
              <div class="skeleton h-7 w-2/3 mb-3"></div>
              <div class="skeleton h-4 w-1/3 mb-5"></div>
              <div class="skeleton h-4 w-1/2 mb-2"></div>
              <div class="skeleton h-4 w-1/3 mb-2"></div>
              <div class="skeleton h-20 w-full mt-4"></div>
            </div>
          </div>
        </div>`;
        }
        function skeletonCards(n = 9) {
            return Array.from({ length: n }).map(() => `
        <div class="card p-4">
          <div class="skeleton h-48 w-full"></div>
          <div class="skeleton h-4 w-1/2 mt-4"></div>
          <div class="skeleton h-3 w-1/3 mt-2"></div>
        </div>`).join("");
        }

        // ---------- Rendering ----------
        let rawData = null; // last response
        const compareSet = new Map(); // id -> item

        function scoreBars(sd) {
            const mk = (label, val) => `
        <div class="text-xs text-slate-600">${label}</div>
        <div class="h-2 w-full bg-slate-100 rounded"><div class="h-2 bg-blue-600 rounded" style="width:${Math.round((val || 0) * 100)}%"></div></div>`;
            return `
        <div class="grid grid-cols-2 gap-4 mt-4">
          ${mk("Financial", sd?.financial_score)}
          ${mk("Practical", sd?.practical_needs_score)}
          ${mk("Brand", sd?.brand_preference_score)}
          ${mk("Features", sd?.feature_score)}
        </div>`;
        }

        function hero(top) {
            if (!top) return "";
            const img = top.image_url
                ? `<img src="${top.image_url}" class="w-full h-80 object-cover rounded-2xl border border-slate-200 bg-slate-50" alt="">`
                : `<div class="h-80 rounded-2xl bg-slate-100 border"></div>`;
            return `
        <div class="card p-6">
          <div class="grid gap-6 xl:grid-cols-[1.25fr_1fr]">
            <div>${img}</div>
            <div>
              <div class="text-2xl font-semibold tracking-tight">${top.name}</div>
              <div class="text-sm text-slate-600">${top.vehicle_configuration || ""}</div>
              <div class="mt-3 space-y-1">
                <div class="text-lg"><span class="font-semibold">${top.price}</span></div>
                <div class="text-slate-700">Monthly: <span class="font-semibold">${top.monthly_payment}</span></div>
              </div>
              ${scoreBars(top.score_details)}
              <div class="mt-4 text-[15px] leading-6 text-slate-800 whitespace-pre-line">${top.reasoning || ""}</div>
              <div class="mt-5 flex flex-wrap gap-2">
                ${top.details_url ? `<a href="${top.details_url}" target="_blank" class="btn btn-outline focus-ring">View details</a>` : ""}
                <button class="btn btn-primary focus-ring" data-compare="${top.vehicle_id}">Add to compare</button>
              </div>
            </div>
          </div>
          ${(Array.isArray(top.why_not_other_cars) && top.why_not_other_cars.length) ? `
            <details class="mt-6">
              <summary class="font-medium cursor-pointer">Why not other close options?</summary>
              <ul class="mt-2 space-y-1 text-sm text-slate-800">
                ${top.why_not_other_cars.map(o => `<li>• ${o.reasoning}</li>`).join("")}
              </ul>
            </details>` : ""}
        </div>`;
        }

        function card(item) {
            const img = item.image_url
                ? `<img src="${item.image_url}" class="w-full h-48 object-cover rounded-2xl border border-slate-200 bg-slate-50" alt="">`
                : `<div class="h-48 rounded-2xl bg-slate-100 border"></div>`;
            return `
        <div class="card p-4">
          ${img}
          <div class="mt-3">
            <div class="font-semibold leading-tight text-[15px]">${item.name || "Unnamed model"}</div>
            <div class="text-sm text-slate-600">${item.vehicle_configuration || ""}</div>
            <div class="mt-2 text-sm flex items-center gap-2">
              <span class="font-medium">${item.price || ""}</span>
              <span class="text-slate-500">•</span>
              <span class="text-slate-700">${item.monthly_payment || ""}</span>
            </div>
            <div class="mt-3 flex gap-3">
              ${item.details_url ? `<a href="${item.details_url}" target="_blank" class="text-blue-600 hover:underline text-sm">View</a>` : ""}
              <button class="text-sm text-slate-700 hover:underline" data-compare="${item.vehicle_id}">Compare</button>
            </div>
          </div>
        </div>`;
        }

        // ---------- Sorting & filtering ----------
        function applySortAndFilters(src) {
            const sort = $("sortBy").value;
            const brandSet = new Set(brandChips.get().map(x => x.toLowerCase()));
            const configs = new Set(getSelectedConfigs().map(x => x.toLowerCase()));
            const activeFilters = [...document.querySelectorAll('[data-filter].active')].map(b => b.dataset.filter);

            function passFilters(x) {
                let ok = true;
                if (activeFilters.includes("preferredBrand")) ok = ok && brandSet.has(String(x.name || x.brand || "").toLowerCase().split(" ")[0]);
                if (activeFilters.includes("config")) ok = ok && configs.has(String(x.vehicle_configuration || "").toLowerCase());
                return ok;
            }

            function cmp(a, b, field, dir = 1) {
                const av = Number(String(a[field] || "").replace(/[^0-9.]/g, ""));
                const bv = Number(String(b[field] || "").replace(/[^0-9.]/g, ""));
                return (av - bv) * dir;
            }

            let pref = (src.preferred || []).slice();
            let alt = (src.alternatives || []).slice();

            pref = pref.filter(passFilters);
            alt = alt.filter(passFilters);

            switch (sort) {
                case "priceAsc": pref.sort((a, b) => cmp(a, b, "price", 1)); alt.sort((a, b) => cmp(a, b, "price", 1)); break;
                case "priceDesc": pref.sort((a, b) => cmp(a, b, "price", -1)); alt.sort((a, b) => cmp(a, b, "price", -1)); break;
                case "monthlyAsc": pref.sort((a, b) => cmp(a, b, "monthly_payment", 1)); alt.sort((a, b) => cmp(a, b, "monthly_payment", 1)); break;
                case "monthlyDesc": pref.sort((a, b) => cmp(a, b, "monthly_payment", -1)); alt.sort((a, b) => cmp(a, b, "monthly_payment", -1)); break;
                default: break; // best
            }
            return { preferred: pref, alternatives: alt, top_suggestion: src.top_suggestion };
        }

        function renderAll(data) {
            rawData = data;

            $("topSuggestion").innerHTML = hero(data.top_suggestion);
            const filtered = applySortAndFilters(data);

            $("prefCount").textContent = filtered.preferred.length;
            $("preferredList").innerHTML = filtered.preferred.map(card).join("") || `<div class="text-sm text-slate-600">No preferred matches.</div>`;

            $("altCount").textContent = filtered.alternatives.length;
            $("altList").innerHTML = filtered.alternatives.map(card).join("") || `<div class="text-sm text-slate-600">No alternatives.</div>`;

            // wire compare buttons
            document.querySelectorAll("[data-compare]").forEach(btn => {
                btn.addEventListener("click", () => {
                    const id = btn.getAttribute("data-compare");
                    const all = [...(rawData.preferred || []), ...(rawData.alternatives || []), rawData.top_suggestion || {}];
                    const item = all.find(x => String(x.vehicle_id) === String(id));
                    if (!item) return;
                    if (compareSet.has(id)) { compareSet.delete(id); toast("Removed from compare", "info"); }
                    else {
                        if (compareSet.size >= 3) return toast("Compare up to 3 cars", "error");
                        compareSet.set(id, item);
                        toast("Added to compare", "success");
                    }
                    renderCompareBar();
                });
            });
        }

        function renderCompareBar() {
            const bar = $("compareBar"), slots = $("compareSlots"), btn = $("btnCompare"), clr = $("btnClearCompare");
            const items = [...compareSet.values()];
            if (!items.length) { bar.classList.add("hidden"); return; }
            bar.classList.remove("hidden");
            slots.innerHTML = items.map(i => `
        <div class="flex items-center gap-2 border border-slate-200 rounded-lg px-2 py-1">
          <img src="${i.image_url || ''}" class="w-10 h-7 object-cover rounded border">
          <div class="text-xs">
            <div class="font-medium line-clamp-1 max-w-[160px]">${i.name}</div>
            <div class="text-slate-600">${i.monthly_payment}</div>
          </div>
          <button class="text-slate-500 hover:text-slate-700" data-remove="${i.vehicle_id}">✕</button>
        </div>`).join("");
            btn.textContent = `Compare (${items.length}/3)`;
            slots.querySelectorAll("[data-remove]").forEach(b => {
                b.addEventListener("click", () => { compareSet.delete(b.getAttribute("data-remove")); renderCompareBar(); });
            });
            $("btnClearCompare").onclick = () => { compareSet.clear(); renderCompareBar(); };
            btn.onclick = () => {
                const lines = items.map(i => `• ${i.name} — ${i.price} · ${i.monthly_payment}`).join("\n");
                alert("Compare:\n\n" + lines);
            };
        }

        // ---------- Fetch flow ----------
        function showError(msg) {
            $("errorBox").textContent = msg || "Something went wrong.";
            $("errorBox").classList.remove("hidden");
            toast(msg || "Something went wrong", "error");
        }
        function clearError() { $("errorBox").classList.add("hidden"); }
        function setBusy(flag) {
            $("spinner").classList.toggle("hidden", !flag);
            $("btnRecommend").disabled = !!flag;
            $("status").textContent = flag ? "Crunching numbers…" : "";
            if (flag) {
                $("topSuggestion").innerHTML = skeletonHero();
                $("preferredList").innerHTML = skeletonCards(9);
                $("altList").innerHTML = skeletonCards(9);
            }
        }

        async function getRecommendations() {
            clearError();
            setBusy(true);
            try {
                const payload = collectPayload();
                saveInputs();
                const res = await fetch("http://127.0.0.1:5000/recommend", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(payload)
                });

                setApiBadge(res.ok);

                const data = await res.json();
                if (!res.ok) {
                    showError(data?.error || "Failed to get recommendations.");
                    $("topSuggestion").innerHTML = "";
                    $("preferredList").innerHTML = "";
                    $("altList").innerHTML = "";
                    return;
                }
                renderAll(data);
            } catch (e) {
                setApiBadge(false);
                showError("Unable to reach the recommendation API.");
            } finally {
                setBusy(false);
            }
        }

        // ---------- Events ----------
        $("btnRecommend").addEventListener("click", getRecommendations);
        $("btnReset").addEventListener("click", () => { localStorage.removeItem("am_prefs"); loadInputs(); toast("Reset to defaults", "success"); });
        $("sortBy").addEventListener("change", () => { if (rawData) renderAll(rawData); });

        document.querySelectorAll("[data-filter]").forEach(b => {
            b.addEventListener("click", () => {
                b.classList.toggle("active");
                b.classList.toggle("bg-slate-100");
                b.classList.toggle("bg-blue-50");
                b.classList.toggle("border-blue-200");
                if (rawData) renderAll(rawData);
            });
        });

        // Prefill one balanced set on first load
        // getRecommendations();
    </script>
</body>

</html>