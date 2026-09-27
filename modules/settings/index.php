<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('settings');

$db = getDB();

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? 'save_settings';

    if ($action === 'send_test_email') {
        require_once __DIR__ . '/../../includes/mailer.php';
        $testEmail = trim($_POST['test_email_recipient'] ?? '');
        if (!$testEmail) {
            setFlash('danger', 'Please enter a valid recipient email address for testing.');
            redirect(BASE_URL . '/modules/settings/index.php');
        }

        $htmlBody = "<div style='font-family:sans-serif; padding:20px; border:1px solid #e2e8f0; border-radius:10px;'>
            <h3 style='color:#4f46e5; margin-top:0;'>Test Email Connection Successful!</h3>
            <p>This is an automated test email sent from <strong>" . htmlspecialchars(APP_NAME) . "</strong>.</p>
            <p>Your SMTP mail configuration is set up properly and ready to dispatch monthly employee salary slips!</p>
            <hr style='border:none; border-top:1px solid #cbd5e1; margin:20px 0;'>
            <small style='color:#64748b;'>Dispatched on: " . date('d M Y, h:i A') . "</small>
        </div>";

        $res = sendPortalEmail($testEmail, "SMTP Connection Test — " . APP_NAME, $htmlBody);
        if ($res['success']) {
            setFlash('success', $res['message']);
        } else {
            setFlash('danger', $res['message']);
        }
        redirect(BASE_URL . '/modules/settings/index.php');
    }

    $fields = [
        'app_name','company_name','company_address','company_email','company_phone','company_website',
        'smtp_host','smtp_port','smtp_username','smtp_password','smtp_encryption','smtp_from_email','smtp_from_name'
    ];
    foreach ($fields as $key) {
        $value = trim($_POST[$key] ?? '');
        $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
           ->execute([$key, $value, $value]);
    }

    // Save Payroll Email Notification setting
    $payrollEmail = isset($_POST['payroll_email_notify']) ? '1' : '0';
    $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('payroll_email_notify',?) ON DUPLICATE KEY UPDATE setting_value=?")
       ->execute([$payrollEmail, $payrollEmail]);

    // Handle App Icon upload
    if (!empty($_FILES['app_icon']['name']) && $_FILES['app_icon']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/png','image/jpeg','image/gif','image/webp','image/svg+xml','image/x-icon'];
        if (in_array($_FILES['app_icon']['type'], $allowed) || in_array(pathinfo($_FILES['app_icon']['name'], PATHINFO_EXTENSION), ['png','jpg','jpeg','gif','webp','svg','ico'])) {
            $ext = pathinfo($_FILES['app_icon']['name'], PATHINFO_EXTENSION);
            $filename = 'app_icon.' . $ext;
            $dest = __DIR__ . '/../../assets/images/' . $filename;

            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);

            move_uploaded_file($_FILES['app_icon']['tmp_name'], $dest);
            $iconPath = 'assets/images/' . $filename;
            $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('app_icon',?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$iconPath, $iconPath]);
        }
    }

    // Remove App Icon if requested
    if (!empty($_POST['remove_app_icon'])) {
        $existing = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='app_icon'")->fetchColumn();
        if ($existing) {
            $fullPath = __DIR__ . '/../../' . $existing;
            if (file_exists($fullPath)) @unlink($fullPath);
        }
        $db->exec("DELETE FROM app_settings WHERE setting_key='app_icon'");
    }

    // Handle Company Logo upload
    if (!empty($_FILES['company_logo']['name']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/png','image/jpeg','image/gif','image/webp','image/svg+xml'];
        if (in_array($_FILES['company_logo']['type'], $allowed)) {
            $ext = pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION);
            $filename = 'company_logo.' . $ext;
            $dest = __DIR__ . '/../../assets/images/' . $filename;

            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);

            move_uploaded_file($_FILES['company_logo']['tmp_name'], $dest);
            $logoPath = 'assets/images/' . $filename;
            $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('company_logo',?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$logoPath, $logoPath]);
        }
    }

    // Remove logo if requested
    if (!empty($_POST['remove_logo'])) {
        $existing = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='company_logo'")->fetchColumn();
        if ($existing) {
            $fullPath = __DIR__ . '/../../' . $existing;
            if (file_exists($fullPath)) unlink($fullPath);
        }
        $db->exec("DELETE FROM app_settings WHERE setting_key='company_logo'");
    }

    setFlash('success','Company settings saved successfully.');
    redirect(BASE_URL . '/modules/settings/index.php');
}

// Load current settings
$settings = [];
foreach ($db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$pageTitle = 'Company Settings';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="mb-0"><i class="bi bi-gear-fill me-2"></i>Company Settings</h4>
    <p class="text-muted small mb-0">Configure company details shown on invoices</p>
  </div>
</div>

<?php displayFlash(); ?>

<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

  <div class="row g-4">
    <!-- Company Info -->
    <div class="col-xl-7">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3">
          <i class="bi bi-building me-2 text-primary"></i>Company Information
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Company Name *</label>
              <input type="text" name="company_name" class="form-control"
                     value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>"
                     placeholder="e.g. Trias Digital Solutions Pvt Ltd" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Address</label>
              <textarea name="company_address" class="form-control" rows="3"
                        placeholder="Full company address..."><?= htmlspecialchars($settings['company_address'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email" name="company_email" class="form-control"
                       value="<?= htmlspecialchars($settings['company_email'] ?? '') ?>"
                       placeholder="billing@company.com">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                <input type="text" name="company_phone" class="form-control"
                       value="<?= htmlspecialchars($settings['company_phone'] ?? '') ?>"
                       placeholder="+91 9876543210">
              </div>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Website</label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-globe2"></i></span>
                <input type="url" name="company_website" class="form-control"
                       value="<?= htmlspecialchars($settings['company_website'] ?? '') ?>"
                       placeholder="https://www.yourcompany.com">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- HR & Payroll Email Settings Card -->
      <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white fw-semibold py-3">
          <i class="bi bi-envelope-paper me-2 text-primary"></i>HR & Payroll Email Notifications
        </div>
        <div class="card-body">
          <div class="form-check form-switch py-1">
            <input class="form-check-input" type="checkbox" name="payroll_email_notify" id="payroll_email_notify" value="1" <?= ($settings['payroll_email_notify'] ?? '1') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="payroll_email_notify">
              Automatically Email Payslips to Employees on Roll Out
            </label>
            <div class="form-text text-muted mt-1">
              When enabled, rolling out monthly payslips will dispatch an automated email with salary breakdown and disbursement details to each employee's official email address.
            </div>
          </div>
        </div>
      </div>

      <!-- SMTP Server Email Configurations Card -->
      <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
          <span><i class="bi bi-send me-2 text-primary"></i>SMTP Server Configuration (Email Dispatcher)</span>
          <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#testEmailModal">
            <i class="bi bi-send-check me-1"></i>Send Test Email
          </button>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold small">SMTP Host / Server</label>
              <input type="text" name="smtp_host" class="form-control" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="e.g. smtp.gmail.com or mail.yourdomain.com">
              <div class="form-text micro-text">Leave blank to use system native mail handler.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">SMTP Port</label>
              <input type="number" name="smtp_port" class="form-control" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>" placeholder="587">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">SMTP Username / Email</label>
              <input type="text" name="smtp_username" class="form-control" value="<?= htmlspecialchars($settings['smtp_username'] ?? '') ?>" placeholder="e.g. payroll@yourdomain.com">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">SMTP Password / App Key</label>
              <input type="password" name="smtp_password" class="form-control" value="<?= htmlspecialchars($settings['smtp_password'] ?? '') ?>" placeholder="••••••••••••">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Encryption</label>
              <select name="smtp_encryption" class="form-select">
                <option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Port 587)</option>
                <option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                <option value="none" <?= ($settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Sender Email Address</label>
              <input type="email" name="smtp_from_email" class="form-control" value="<?= htmlspecialchars($settings['smtp_from_email'] ?? '') ?>" placeholder="no-reply@yourdomain.com">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Sender Display Name</label>
              <input type="text" name="smtp_from_name" class="form-control" value="<?= htmlspecialchars($settings['smtp_from_name'] ?? '') ?>" placeholder="e.g. Trias HR Portal">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Column: Portal Branding & Company Logo -->
    <div class="col-xl-5">
      <!-- App Name & App Icon Branding Card -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3">
          <i class="bi bi-diagram-3 me-2 text-primary"></i>Portal & Sidebar Branding
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">App / Portal Display Name</label>
            <input type="text" name="app_name" class="form-control"
                   value="<?= htmlspecialchars($settings['app_name'] ?? '') ?>"
                   placeholder="e.g. Trias Partner Portal">
            <div class="form-text">Displayed in sidebar header and browser title bar.</div>
          </div>

          <div class="border-top pt-3 mt-2">
            <label class="form-label fw-semibold">App Icon / Sidebar Logo</label>
            <?php if (!empty($settings['app_icon'])): ?>
            <div class="d-flex align-items-center gap-3 mb-2 bg-light p-2 rounded border">
              <img src="<?= BASE_URL . '/' . htmlspecialchars($settings['app_icon']) ?>"
                   alt="App Icon" style="height:38px; width:38px; object-fit:contain;" class="rounded">
              <div>
                <div class="small fw-semibold text-dark">Current App Icon</div>
                <label class="form-check form-check-inline m-0">
                  <input type="checkbox" name="remove_app_icon" value="1" class="form-check-input">
                  <span class="form-check-label text-danger small">Remove icon</span>
                </label>
              </div>
            </div>
            <?php endif; ?>
            <input type="file" name="app_icon" class="form-control" accept="image/*">
            <div class="form-text">Icon displayed on top left of sidebar menu. Recommended: 64x64 PNG or SVG.</div>
          </div>
        </div>
      </div>

      <!-- Company Legal Logo -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3">
          <i class="bi bi-building me-2 text-primary"></i>Company Invoice / Payslip Logo
        </div>
        <div class="card-body text-center">
          <?php if (!empty($settings['company_logo'])): ?>
          <div class="mb-3">
            <img src="<?= BASE_URL . '/' . htmlspecialchars($settings['company_logo']) ?>"
                 alt="Company Logo" class="img-fluid rounded"
                 style="max-height:150px; object-fit:contain;">
          </div>
          <div class="mb-3">
            <label class="form-check form-check-inline">
              <input type="checkbox" name="remove_logo" value="1" class="form-check-input">
              <span class="form-check-label text-danger small">Remove current logo</span>
            </label>
          </div>
          <?php else: ?>
          <div class="py-4 text-muted">
            <i class="bi bi-image display-4 d-block mb-2 opacity-25"></i>
            No logo uploaded
          </div>
          <?php endif; ?>

          <div>
            <label class="form-label fw-semibold"><?= !empty($settings['company_logo']) ? 'Replace Logo' : 'Upload Logo' ?></label>
            <input type="file" name="company_logo" class="form-control" accept="image/*">
            <div class="form-text">PNG, JPEG, SVG recommended. Used as invoice watermark.</div>
          </div>
        </div>
      </div>

      <!-- Preview -->
      <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white fw-semibold py-3">
          <i class="bi bi-eye me-2 text-primary"></i>Invoice Header Preview
        </div>
        <div class="card-body">
          <div class="d-flex align-items-start gap-3">
            <?php if (!empty($settings['company_logo'])): ?>
            <img src="<?= BASE_URL . '/' . htmlspecialchars($settings['company_logo']) ?>"
                 alt="Logo" style="height:50px; object-fit:contain;">
            <?php endif; ?>
            <div>
              <div class="fw-bold"><?= htmlspecialchars($settings['company_name'] ?? APP_NAME) ?></div>
              <?php if (!empty($settings['company_address'])): ?>
              <div class="small text-muted"><?= nl2br(htmlspecialchars($settings['company_address'])) ?></div>
              <?php endif; ?>
              <?php if (!empty($settings['company_email'])): ?>
              <div class="small text-muted"><?= htmlspecialchars($settings['company_email']) ?></div>
              <?php endif; ?>
              <?php if (!empty($settings['company_phone'])): ?>
              <div class="small text-muted"><?= htmlspecialchars($settings['company_phone']) ?></div>
              <?php endif; ?>
              <?php if (!empty($settings['company_website'])): ?>
              <div class="small text-muted"><i class="bi bi-globe2 me-1"></i><?= htmlspecialchars($settings['company_website']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Settings</button>
  </div>
</form>

<!-- Modal: Send Test Email -->
<div class="modal fade" id="testEmailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="send_test_email">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-send-check me-2 text-primary"></i>Send Test Email Connection</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-3">Send a test message to verify your SMTP server host, authentication, and port configuration.</p>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Recipient Email Address <span class="text-danger">*</span></label>
            <input type="email" name="test_email_recipient" class="form-control" required placeholder="e.g. admin@yourdomain.com" value="<?= htmlspecialchars($settings['company_email'] ?? currentUser()['email']) ?>">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Send Test Email</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
