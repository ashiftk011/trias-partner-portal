<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

if (isLoggedIn()) {
    redirect(BASE_URL . '/modules/dashboard/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // Update last login
            $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

            // Role-based redirect
            if ($user['role'] === 'telecall') {
                redirect(BASE_URL . '/modules/leads/index.php');
            } elseif ($user['role'] === 'finance') {
                redirect(BASE_URL . '/modules/invoices/index.php');
            } elseif ($user['role'] === 'accounts') {
                redirect(BASE_URL . '/modules/payments/index.php');
            } else {
                redirect(BASE_URL . '/modules/dashboard/index.php');
            }
        } else {
            $error = 'Invalid email or password.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Login | <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<style>
.login-body {
  min-height: 100vh;
  min-height: 100dvh;
  background: linear-gradient(135deg, #0b0f19 0%, #1e1b4b 50%, #0f172a 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  position: relative;
  overflow-x: hidden;
}
.login-body::before {
  content: '';
  position: absolute;
  width: 320px;
  height: 320px;
  background: radial-gradient(circle, rgba(99, 102, 241, 0.25) 0%, rgba(99, 102, 241, 0) 70%);
  top: 10%;
  left: 15%;
  border-radius: 50%;
  pointer-events: none;
}
.login-body::after {
  content: '';
  position: absolute;
  width: 280px;
  height: 280px;
  background: radial-gradient(circle, rgba(236, 72, 153, 0.18) 0%, rgba(236, 72, 153, 0) 70%);
  bottom: 10%;
  right: 15%;
  border-radius: 50%;
  pointer-events: none;
}
.login-container {
  width: 100%;
  max-width: 440px;
  z-index: 1;
}
.login-card {
  background: rgba(255, 255, 255, 0.98);
  border-radius: 24px;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.1);
  overflow: hidden;
  transition: transform 0.2s ease;
}
.login-brand-icon {
  width: 52px;
  height: 52px;
  background: linear-gradient(135deg, #6366f1, #4f46e5);
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 1.6rem;
  box-shadow: 0 8px 20px rgba(99, 102, 241, 0.35);
  margin-bottom: 0.75rem;
}
.login-title {
  font-size: 1.45rem;
  font-weight: 800;
  color: #0f172a;
  letter-spacing: -0.02em;
}
.login-input-group .form-control {
  font-size: 16px !important; /* Prevents auto-zoom on iOS */
  min-height: 48px;
  border-radius: 12px;
  padding: 10px 14px;
}
.login-input-group .input-group-text {
  min-height: 48px;
  border-radius: 12px;
  background-color: #f8fafc;
  color: #64748b;
  font-size: 1.1rem;
}
.btn-login-submit {
  min-height: 48px;
  font-size: 1rem;
  border-radius: 12px;
  background: linear-gradient(135deg, #4f46e5, #4338ca);
  border: none;
  box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
  transition: all 0.2s ease;
}
.btn-login-submit:active {
  transform: scale(0.98);
}
.demo-badge-btn {
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  border-radius: 10px;
  padding: 8px 12px;
  font-size: 0.82rem;
  cursor: pointer;
  transition: all 0.15s ease;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  text-align: left;
}
.demo-badge-btn:hover, .demo-badge-btn:active {
  background: #eef2ff;
  border-color: #818cf8;
  transform: translateY(-1px);
}
@media (max-width: 480px) {
  .login-card-body {
    padding: 1.5rem !important;
  }
  .login-title {
    font-size: 1.3rem;
  }
}
</style>
</head>
<body class="login-body">
<div class="login-container">
  <div class="card login-card border-0">
    <div class="card-body login-card-body p-4 p-sm-5">
      <div class="text-center mb-4">
        <div class="login-brand-icon">
          <i class="bi bi-diagram-3-fill"></i>
        </div>
        <div class="login-title"><?= APP_NAME ?></div>
        <p class="text-muted small fw-medium mt-1 mb-0">Sign in to your account</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2 px-3 rounded-3 mb-3 d-flex align-items-center gap-2 small">
          <i class="bi bi-exclamation-circle-fill fs-5 flex-shrink-0"></i>
          <div><?= htmlspecialchars($error) ?></div>
        </div>
      <?php endif; ?>

      <form method="POST" autocomplete="off" id="loginForm">
        <div class="mb-3">
          <label class="form-label fw-semibold small text-dark mb-1">Email Address</label>
          <div class="input-group login-input-group">
            <span class="input-group-text border-end-0"><i class="bi bi-envelope"></i></span>
            <input type="email" name="email" id="emailField" class="form-control border-start-0" placeholder="you@example.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" inputmode="email" required autofocus>
          </div>
        </div>
        <div class="mb-4">
          <label class="form-label fw-semibold small text-dark mb-1">Password</label>
          <div class="input-group login-input-group">
            <span class="input-group-text border-end-0"><i class="bi bi-lock"></i></span>
            <input type="password" name="password" id="pwdField" class="form-control border-start-0 border-end-0" placeholder="••••••••" required>
            <button type="button" class="btn btn-light border border-start-0" onclick="togglePwd()" aria-label="Toggle password visibility" style="border-radius: 0 12px 12px 0;">
              <i class="bi bi-eye text-muted fs-5" id="eyeIcon"></i>
            </button>
          </div>
        </div>
        <button type="submit" class="btn btn-login-submit btn-primary w-100 fw-bold text-white mb-3">
          <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
        </button>
      </form>

      <div class="mt-3 pt-3 border-top">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-dark small fw-bold"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Quick Demo Fill</span>
          <span class="text-muted" style="font-size: 0.72rem;">Tap to auto-fill</span>
        </div>
        <div class="d-flex flex-column gap-2">
          <button type="button" class="demo-badge-btn" onclick="fillCreds('telecall@trias.com', 'password')">
            <span class="d-flex align-items-center gap-2">
              <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1">Telecall</span>
              <span class="fw-semibold text-dark">telecall@trias.com</span>
            </span>
            <i class="bi bi-arrow-right-short text-muted fs-5"></i>
          </button>
          <button type="button" class="demo-badge-btn" onclick="fillCreds('admin@trias.com', 'password')">
            <span class="d-flex align-items-center gap-2">
              <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1">Admin</span>
              <span class="fw-semibold text-dark">admin@trias.com</span>
            </span>
            <i class="bi bi-arrow-right-short text-muted fs-5"></i>
          </button>
          <button type="button" class="demo-badge-btn" onclick="fillCreds('finance@trias.com', 'password')">
            <span class="d-flex align-items-center gap-2">
              <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1">Finance</span>
              <span class="fw-semibold text-dark">finance@trias.com</span>
            </span>
            <i class="bi bi-arrow-right-short text-muted fs-5"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function togglePwd() {
  const f = document.getElementById('pwdField');
  const i = document.getElementById('eyeIcon');
  if (f.type === 'password') { f.type = 'text'; i.className = 'bi bi-eye-slash text-primary fs-5'; }
  else { f.type = 'password'; i.className = 'bi bi-eye text-muted fs-5'; }
}
function fillCreds(email, pwd) {
  const ef = document.getElementById('emailField');
  const pf = document.getElementById('pwdField');
  ef.value = email;
  pf.value = pwd;
  ef.focus();
}
</script>
</body>
</html>
