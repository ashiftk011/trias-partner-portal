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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background: linear-gradient(135deg, #1a237e 0%, #0d47a1 50%, #1565c0 100%); min-height: 100vh; display: flex; align-items: center; }
  .login-card { border: none; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
  .login-brand { font-size: 1.6rem; font-weight: 700; color: #1a237e; }
  .login-brand i { color: #1565c0; }
  .btn-login { background: linear-gradient(135deg, #1a237e, #1565c0); border: none; }
  .btn-login:hover { opacity: .9; }
  .form-control:focus { border-color: #1565c0; box-shadow: 0 0 0 .2rem rgba(21,101,192,.25); }
  .demo-creds { font-size: .78rem; }
</style>
</head>
<body>
<div class="container">
  <div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
      <div class="card login-card">
        <div class="card-body p-4 p-md-5">
          <div class="text-center mb-4">
            <div class="login-brand mb-1">
              <i class="bi bi-diagram-3-fill"></i> <?= APP_NAME ?>
            </div>
            <p class="text-muted small">Sign in to your account</p>
          </div>

          <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <form method="POST" autocomplete="off">
            <div class="mb-3">
              <label class="form-label fw-semibold">Email Address</label>
              <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                <input type="email" name="email" class="form-control" placeholder="you@example.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
              </div>
            </div>
            <div class="mb-4">
              <label class="form-label fw-semibold">Password</label>
              <div class="input-group">
                <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" id="pwdField" class="form-control" placeholder="••••••••" required>
                <button type="button" class="btn btn-light border" onclick="togglePwd()">
                  <i class="bi bi-eye" id="eyeIcon"></i>
                </button>
              </div>
            </div>
            <button type="submit" class="btn btn-login btn-primary w-100 fw-semibold py-2">
              <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
            </button>
          </form>

          <div class="mt-4 p-3 bg-light rounded demo-creds">
            <strong class="d-block mb-1 text-muted">Demo Credentials</strong>
            <div><span class="badge bg-danger me-1">Admin</span> admin@trias.com / password</div>
            <div><span class="badge bg-success me-1">Finance</span> finance@trias.com / password</div>
            <div><span class="badge bg-primary me-1">Telecall</span> telecall@trias.com / password</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function togglePwd() {
  const f = document.getElementById('pwdField');
  const i = document.getElementById('eyeIcon');
  if (f.type === 'password') { f.type = 'text'; i.className = 'bi bi-eye-slash'; }
  else { f.type = 'password'; i.className = 'bi bi-eye'; }
}
</script>
</body>
</html>
