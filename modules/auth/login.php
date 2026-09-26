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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-body">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
      <div class="card login-card border-0">
        <div class="card-body p-4 p-md-5">
          <div class="text-center mb-4">
            <div class="login-brand mb-1">
              <i class="bi bi-diagram-3-fill me-2"></i><?= APP_NAME ?>
            </div>
            <p class="text-muted small fw-medium">Sign in to your account</p>
          </div>

          <?php if ($error): ?>
            <div class="alert alert-danger py-2 rounded-3 mb-4"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <form method="POST" autocomplete="off">
            <div class="mb-3">
              <label class="form-label fw-semibold small text-dark">Email Address</label>
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-muted"></i></span>
                <input type="email" name="email" class="form-control border-start-0" placeholder="you@example.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
              </div>
            </div>
            <div class="mb-4">
              <label class="form-label fw-semibold small text-dark">Password</label>
              <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
                <input type="password" name="password" id="pwdField" class="form-control border-start-0 border-end-0" placeholder="••••••••" required>
                <button type="button" class="btn btn-light border border-start-0" onclick="togglePwd()">
                  <i class="bi bi-eye text-muted" id="eyeIcon"></i>
                </button>
              </div>
            </div>
            <button type="submit" class="btn btn-login btn-primary w-100 fw-bold text-white py-2">
              <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
            </button>
          </form>

          <div class="mt-4 p-3 demo-creds">
            <strong class="d-block mb-2 text-dark small">Demo Credentials</strong>
            <div class="d-flex flex-column gap-1">
              <div><span class="badge badge-soft-danger me-1">Admin</span> admin@trias.com / password</div>
              <div><span class="badge badge-soft-success me-1">Finance</span> finance@trias.com / password</div>
              <div><span class="badge badge-soft-primary me-1">Telecall</span> telecall@trias.com / password</div>
            </div>
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
  if (f.type === 'password') { f.type = 'text'; i.className = 'bi bi-eye-slash text-muted'; }
  else { f.type = 'password'; i.className = 'bi bi-eye text-muted'; }
}
</script>
</body>
</html>
