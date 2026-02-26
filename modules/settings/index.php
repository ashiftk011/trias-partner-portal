<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('settings');

$db = getDB();

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fields = ['company_name','company_address','company_email','company_phone'];
    foreach ($fields as $key) {
        $value = trim($_POST[$key] ?? '');
        $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
           ->execute([$key, $value, $value]);
    }

    // Handle logo upload
    if (!empty($_FILES['company_logo']['name']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/png','image/jpeg','image/gif','image/webp','image/svg+xml'];
        if (in_array($_FILES['company_logo']['type'], $allowed)) {
            $ext = pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION);
            $filename = 'company_logo.' . $ext;
            $dest = __DIR__ . '/../../assets/images/' . $filename;

            // Create directory if needed
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);

            move_uploaded_file($_FILES['company_logo']['tmp_name'], $dest);
            $logoPath = 'assets/images/' . $filename;
            $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('company_logo',?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$logoPath, $logoPath]);
        } else {
            setFlash('error','Invalid file type. Only PNG, JPEG, GIF, WebP, and SVG are allowed.');
            redirect(BASE_URL . '/modules/settings/index.php');
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
          </div>
        </div>
      </div>
    </div>

    <!-- Logo -->
    <div class="col-xl-5">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3">
          <i class="bi bi-image me-2 text-primary"></i>Company Logo
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
