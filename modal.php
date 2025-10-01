<!-- modal.php -->
<!-- Tailwind modals: hidden by default; opened via JS -->

<!-- Backdrop + center wrapper -->
<style>
  .modal-backdrop { background: rgba(0,0,0,.45); }
</style>

<!-- Login Modal -->
<div id="loginModal" class="fixed inset-0 z-[60] hidden">
  <div class="absolute inset-0 modal-backdrop" onclick="closeModal('loginModal')"></div>
  <div class="relative mx-auto max-w-md w-[90%] mt-24 rounded-2xl bg-white text-gray-900 shadow-lg">
    <div class="p-6">
      <div class="flex items-start justify-between">
        <h2 class="text-xl font-semibold">Login</h2>
        <button class="text-gray-500 hover:text-gray-700" onclick="closeModal('loginModal')">✕</button>
      </div>

      <div id="loginErrorMessage" class="hidden mt-3 text-red-600 text-sm"></div>

      <form id="loginForm" class="mt-4" method="POST" action="handler/login.php">
        <label class="text-sm font-medium">Email or Username</label>
        <div class="mt-1">
          <input id="login-email" name="user_input" required
                 class="w-full rounded-lg border border-gray-300 px-3 h-11 outline-none focus:border-blue-500"
                 placeholder="Enter your Email or Username">
        </div>

        <label class="text-sm font-medium mt-4">Password</label>
        <div class="mt-1">
          <input id="login-password" name="password" type="password" required
                 class="w-full rounded-lg border border-gray-300 px-3 h-11 outline-none focus:border-blue-500"
                 placeholder="Enter your Password">
        </div>

        <div class="mt-3 flex items-center justify-between text-sm">
          <label class="inline-flex items-center gap-2">
            <input type="checkbox" class="rounded border-gray-300"> Remember Me
          </label>
          <button type="button" class="text-blue-600 hover:underline" onclick="openModal('forgotPasswordModal')">
            Forgot Password?
          </button>
        </div>

        <button type="submit"
                class="mt-5 w-full h-11 rounded-lg bg-gray-900 text-white hover:bg-black transition">
          Sign In
        </button>
      </form>

      <p class="mt-3 text-center text-sm">
        Don’t have an account?
        <button class="text-blue-600 font-semibold hover:underline" onclick="switchToRegister()">Sign Up</button>
      </p>
    </div>
  </div>
</div>

<!-- Register Modal -->
<div id="registerModal" class="fixed inset-0 z-[60] hidden">
  <div class="absolute inset-0 modal-backdrop" onclick="closeModal('registerModal')"></div>
  <div class="relative mx-auto max-w-md w-[90%] mt-24 rounded-2xl bg-white text-gray-900 shadow-lg">
    <div class="p-6">
      <div class="flex items-start justify-between">
        <h2 class="text-xl font-semibold">Sign Up</h2>
        <button class="text-gray-500 hover:text-gray-700" onclick="closeModal('registerModal')">✕</button>
      </div>

      <div id="registerErrorMessage" class="hidden mt-3 text-sm"></div>

      <form id="registerForm" class="mt-4" method="POST" action="handler/register.php">
        <label class="text-sm font-medium">Name</label>
        <input id="register-name" name="username" required
               class="mt-1 w-full rounded-lg border border-gray-300 px-3 h-11 outline-none focus:border-blue-500"
               placeholder="Enter your Name">

        <label class="text-sm font-medium mt-4">Email</label>
        <input id="register-email" name="email" type="email" required
               class="mt-1 w-full rounded-lg border border-gray-300 px-3 h-11 outline-none focus:border-blue-500"
               placeholder="Enter your Email">

        <label class="text-sm font-medium mt-4">Password</label>
        <input id="register-password" name="password" type="password" required
               class="mt-1 w-full rounded-lg border border-gray-300 px-3 h-11 outline-none focus:border-blue-500"
               placeholder="Enter your Password">

        <button type="submit"
                class="mt-5 w-full h-11 rounded-lg bg-gray-900 text-white hover:bg-black transition">
          Sign Up
        </button>
      </form>

      <p class="mt-3 text-center text-sm">
        Already have an account?
        <button class="text-blue-600 font-semibold hover:underline" onclick="switchToLogin()">Log In</button>
      </p>
    </div>
  </div>
</div>

<!-- Forgot Password Modal -->
<div id="forgotPasswordModal" class="fixed inset-0 z-[60] hidden">
  <div class="absolute inset-0 modal-backdrop" onclick="closeModal('forgotPasswordModal')"></div>
  <div class="relative mx-auto max-w-md w-[90%] mt-24 rounded-2xl bg-white text-gray-900 shadow-lg">
    <div class="p-6">
      <div class="flex items-start justify-between">
        <h2 class="text-xl font-semibold">Forgot Password</h2>
        <button class="text-gray-500 hover:text-gray-700" onclick="closeModal('forgotPasswordModal')">✕</button>
      </div>

      <div id="forgotPasswordMessage" class="hidden mt-3 text-sm text-center"></div>

      <form id="forgotPasswordForm" class="mt-4">
        <label class="text-sm font-medium">Enter your Email or Username</label>
        <input id="forgot-password-input" name="user_input" required
               class="mt-1 w-full rounded-lg border border-gray-300 px-3 h-11 outline-none focus:border-blue-500"
               placeholder="Enter your email or username">

        <button type="submit"
                class="mt-5 w-full h-11 rounded-lg bg-gray-900 text-white hover:bg-black transition">
          Submit
        </button>
      </form>
    </div>
  </div>
</div>

<script>
/* If your site lives in a subfolder, set this once */
const APP_ROOT = ''; // e.g. '/AutomatchV2/' (leading + trailing slash optional)

function openModal(id){ document.getElementById(id)?.classList.remove('hidden'); }
function closeModal(id){ document.getElementById(id)?.classList.add('hidden'); }

function switchToRegister(){ closeModal('loginModal'); setTimeout(()=>openModal('registerModal'), 150); }
function switchToLogin(){ closeModal('registerModal'); setTimeout(()=>openModal('loginModal'), 150); }

window.addEventListener('click', (e) => {
  ['loginModal','registerModal','forgotPasswordModal'].forEach(id => {
    const el = document.getElementById(id);
    if (el && e.target === el) closeModal(id);
  });
});

// LOGIN (fetch JSON; fallback to form submit if server returns HTML)
document.getElementById('loginForm')?.addEventListener('submit', function (e) {
  e.preventDefault();
  fetch((APP_ROOT||'') + 'handler/login.php', { method: 'POST', body: new FormData(this) })
    .then(r => r.json().catch(()=>({ success:false, message:'Non-JSON response' })))
    .then(data => {
      if (data.success) window.location.href = data.redirect || ((APP_ROOT||'') + 'index.php');
      else {
        const msg = document.getElementById('loginErrorMessage');
        msg.textContent = data.message || 'Login failed';
        msg.classList.remove('hidden');
      }
    })
    .catch(() => this.submit());
});

// REGISTER
document.getElementById('registerForm')?.addEventListener('submit', function (e) {
  e.preventDefault();
  fetch((APP_ROOT||'') + 'handler/register.php', { method: 'POST', body: new FormData(this) })
    .then(r => r.json().catch(()=>({ success:false, message:'Non-JSON response' })))
    .then(data => {
      const box = document.getElementById('registerErrorMessage');
      box.classList.remove('hidden');
      box.textContent = data.message || (data.success ? 'Registered!' : 'Error');
      box.style.color = data.success ? 'green' : 'red';
      if (data.success) setTimeout(()=> window.location.href = (APP_ROOT||'') + 'index.php', 1200);
    })
    .catch(() => this.submit());
});

// FORGOT PASSWORD
function openForgotPasswordModal(){ openModal('forgotPasswordModal'); }
document.getElementById('forgotPasswordForm')?.addEventListener('submit', function (e) {
  e.preventDefault();
  const userInput = document.getElementById('forgot-password-input').value;
  const messageBox = document.getElementById('forgotPasswordMessage');

  fetch((APP_ROOT||'') + 'connection/forgot-password.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_input: userInput })
  })
  .then(r => r.json())
  .then(data => {
    messageBox.classList.remove('hidden');
    messageBox.textContent = data.message || '';
    messageBox.style.color = data.success ? 'green' : 'red';
    if (data.success) setTimeout(()=> closeModal('forgotPasswordModal'), 1500);
  })
  .catch(() => {
    messageBox.classList.remove('hidden');
    messageBox.style.color = 'red';
    messageBox.textContent = 'An error occurred. Please try again.';
  });
});
</script>
