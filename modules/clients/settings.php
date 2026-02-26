<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('clients');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/clients/index.php');

$stmt = $db->prepare("SELECT c.*,p.name as project_name FROM clients c LEFT JOIN projects p ON p.id=c.project_id WHERE c.id=?");
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) redirect(BASE_URL . '/modules/clients/index.php');

// Predefined setting keys
$settingDefs = [
    'portal_username'   => ['label' => 'Portal Username',       'type' => 'text'],
    'portal_password'   => ['label' => 'Portal Password',       'type' => 'password'],
    'api_key'           => ['label' => 'API Key',               'type' => 'text'],
    'domain'            => ['label' => 'Website Domain',        'type' => 'text'],
    'hosting_expiry'    => ['label' => 'Hosting Expiry Date',   'type' => 'date'],
    'domain_expiry'     => ['label' => 'Domain Expiry Date',    'type' => 'date'],
    'support_email'     => ['label' => 'Support Email',         'type' => 'email'],
    // Hosting Server
    'hosting_server'    => ['label' => 'Hosting Server',        'type' => 'text'],
    'server_username'   => ['label' => 'Server Username',       'type' => 'text'],
    'server_password'   => ['label' => 'Server Password',       'type' => 'password'],
    // Database Details
    'db_type'           => ['label' => 'Database Type',         'type' => 'select', 'options' => ['MySQL','SQL Server','PostgreSQL','SQLite','Oracle','MongoDB','Other']],
    'db_connection'     => ['label' => 'DB Connection String',  'type' => 'text'],
    'db_username'       => ['label' => 'DB Username',           'type' => 'text'],
    'db_password'       => ['label' => 'DB Password',           'type' => 'password'],
    // Analytics & Social
    'google_analytics'  => ['label' => 'Google Analytics ID',   'type' => 'text'],
    'facebook_page'     => ['label' => 'Facebook Page URL',     'type' => 'url'],
    'instagram_handle'  => ['label' => 'Instagram Handle',      'type' => 'text'],
    'notes'             => ['label' => 'Client Notes',          'type' => 'textarea'],
    'keywords'          => ['label' => 'Target Keywords',       'type' => 'textarea'],
    'monthly_report'    => ['label' => 'Monthly Report Link',   'type' => 'url'],
    'custom_1_key'      => ['label' => 'Custom Field 1 Key',    'type' => 'text'],
    'custom_1_value'    => ['label' => 'Custom Field 1 Value',  'type' => 'text'],
    'custom_2_key'      => ['label' => 'Custom Field 2 Key',    'type' => 'text'],
    'custom_2_value'    => ['label' => 'Custom Field 2 Value',  'type' => 'text'],
];

// Get existing settings
$existing = [];
$settingsResult = $db->prepare("SELECT setting_key, setting_value FROM client_settings WHERE client_id=?");
$settingsResult->execute([$id]);
foreach ($settingsResult->fetchAll() as $row) {
    $existing[$row['setting_key']] = $row['setting_value'];
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Add deployment
    if (isset($_POST['action']) && $_POST['action'] === 'add_deployment') {
        $depDate   = trim($_POST['deployment_date'] ?? '');
        $depBranch = trim($_POST['branch'] ?? '');
        $depNotes  = trim($_POST['deploy_notes'] ?? '');
        if ($depDate && $depBranch) {
            $db->prepare("INSERT INTO client_deployments (client_id, deployment_date, branch, notes, created_by) VALUES (?,?,?,?,?)")
               ->execute([$id, $depDate, $depBranch, $depNotes, currentUser()['id']]);
            setFlash('success', 'Deployment record added.');
        } else {
            setFlash('danger', 'Deployment date and branch are required.');
        }
        redirect(BASE_URL . '/modules/clients/settings.php?id=' . $id . '#deployments');
    }

    // Delete deployment
    if (isset($_POST['action']) && $_POST['action'] === 'delete_deployment') {
        $depId = (int)($_POST['deploy_id'] ?? 0);
        if ($depId) {
            $db->prepare("DELETE FROM client_deployments WHERE id=? AND client_id=?")->execute([$depId, $id]);
            setFlash('success', 'Deployment record deleted.');
        }
        redirect(BASE_URL . '/modules/clients/settings.php?id=' . $id . '#deployments');
    }

    // Save settings (default)
    $settings = $_POST['settings'] ?? [];
    foreach ($settings as $key => $value) {
        if (!array_key_exists($key, $settingDefs)) continue;
        $value = trim($value);
        if ($value !== '') {
            $db->prepare("INSERT INTO client_settings (client_id,setting_key,setting_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$id,$key,$value,$value]);
        } else {
            $db->prepare("DELETE FROM client_settings WHERE client_id=? AND setting_key=?")->execute([$id,$key]);
        }
    }
    setFlash('success','Settings saved successfully.');
    redirect(BASE_URL . '/modules/clients/settings.php?id=' . $id);
}

// Fetch deployment history
$deployStmt = $db->prepare("SELECT d.*, u.name as created_by_name FROM client_deployments d LEFT JOIN users u ON u.id=d.created_by WHERE d.client_id=? ORDER BY d.deployment_date DESC, d.created_at DESC");
$deployStmt->execute([$id]);
$deployments = $deployStmt->fetchAll();

$pageTitle = 'Client Settings: ' . $client['name'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0">Client Settings</h4>
    <p class="text-muted small mb-0"><?= htmlspecialchars($client['name']) ?> — <?= htmlspecialchars($client['project_name']) ?></p>
  </div>
</div>

<?php displayFlash(); ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
  <div class="row g-4">
    <!-- Portal Access -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-shield-lock me-2 text-primary"></i>Portal Access</div>
        <div class="card-body">
          <?php foreach (['portal_username','portal_password','api_key'] as $key):
            $def = $settingDefs[$key]; ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold"><?= $def['label'] ?></label>
            <div class="input-group">
              <input type="<?= $def['type'] ?>" name="settings[<?= $key ?>]"
                     class="form-control form-control-sm"
                     value="<?= htmlspecialchars($existing[$key] ?? '') ?>">
              <?php if ($def['type']==='password'): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleVis(this)"><i class="bi bi-eye"></i></button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Domain & Hosting -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-globe me-2 text-primary"></i>Domain</div>
        <div class="card-body">
          <?php foreach (['domain','domain_expiry','support_email'] as $key):
            $def = $settingDefs[$key]; ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold"><?= $def['label'] ?></label>
            <input type="<?= $def['type'] ?>" name="settings[<?= $key ?>]"
                   class="form-control form-control-sm"
                   value="<?= htmlspecialchars($existing[$key] ?? '') ?>">
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Hosting Server -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-hdd-rack me-2 text-primary"></i>Hosting Server</div>
        <div class="card-body">
          <?php foreach (['hosting_server','server_username','server_password','hosting_expiry'] as $key):
            $def = $settingDefs[$key]; ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold"><?= $def['label'] ?></label>
            <div class="input-group">
              <input type="<?= $def['type'] ?>" name="settings[<?= $key ?>]"
                     class="form-control form-control-sm"
                     value="<?= htmlspecialchars($existing[$key] ?? '') ?>"
                     placeholder="<?= $key === 'hosting_server' ? 'e.g. 192.168.1.100 or server.example.com' : '' ?>">
              <?php if ($def['type']==='password'): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleVis(this)"><i class="bi bi-eye"></i></button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Database Details -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-database me-2 text-primary"></i>Database Details</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Database Type</label>
            <select name="settings[db_type]" class="form-select form-select-sm">
              <option value="">-- Select --</option>
              <?php foreach ($settingDefs['db_type']['options'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($existing['db_type'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php foreach (['db_connection','db_username','db_password'] as $key):
            $def = $settingDefs[$key]; ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold"><?= $def['label'] ?></label>
            <div class="input-group">
              <input type="<?= $def['type'] ?>" name="settings[<?= $key ?>]"
                     class="form-control form-control-sm"
                     value="<?= htmlspecialchars($existing[$key] ?? '') ?>"
                     placeholder="<?= $key === 'db_connection' ? 'e.g. Server=host;Database=dbname;' : '' ?>">
              <?php if ($def['type']==='password'): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleVis(this)"><i class="bi bi-eye"></i></button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Social & Analytics -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-bar-chart me-2 text-primary"></i>Analytics & Social</div>
        <div class="card-body">
          <?php foreach (['google_analytics','facebook_page','instagram_handle','monthly_report'] as $key):
            $def = $settingDefs[$key]; ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold"><?= $def['label'] ?></label>
            <input type="<?= $def['type'] ?>" name="settings[<?= $key ?>]"
                   class="form-control form-control-sm"
                   value="<?= htmlspecialchars($existing[$key] ?? '') ?>">
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Notes & Custom -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-journals me-2 text-primary"></i>Notes & Custom Fields</div>
        <div class="card-body">
          <?php foreach (['notes','keywords'] as $key):
            $def = $settingDefs[$key]; ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold"><?= $def['label'] ?></label>
            <textarea name="settings[<?= $key ?>]" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($existing[$key] ?? '') ?></textarea>
          </div>
          <?php endforeach; ?>
          <hr>
          <div class="row g-2">
            <?php foreach (['custom_1_key','custom_1_value','custom_2_key','custom_2_value'] as $key):
              $def = $settingDefs[$key]; ?>
            <div class="col-6">
              <label class="form-label small"><?= $def['label'] ?></label>
              <input type="text" name="settings[<?= $key ?>]" class="form-control form-control-sm"
                     value="<?= htmlspecialchars($existing[$key] ?? '') ?>">
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Settings</button>
    <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<!-- Deployment History -->
<div class="mt-5" id="deployments">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold py-3 d-flex align-items-center justify-content-between">
      <span><i class="bi bi-rocket-takeoff me-2 text-primary"></i>Deployment History</span>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addDeployForm">
        <i class="bi bi-plus-circle me-1"></i>Add Deployment
      </button>
    </div>

    <!-- Add Deployment Form (collapsible) -->
    <div class="collapse" id="addDeployForm">
      <div class="card-body border-bottom bg-light">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="add_deployment">
          <div class="row g-3 align-items-end">
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Deployment Date <span class="text-danger">*</span></label>
              <input type="date" name="deployment_date" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Branch <span class="text-danger">*</span></label>
              <input type="text" name="branch" class="form-control form-control-sm" required placeholder="e.g. main, release/v2.1, hotfix/login">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Notes</label>
              <input type="text" name="deploy_notes" class="form-control form-control-sm" placeholder="Optional description of changes">
            </div>
            <div class="col-md-2">
              <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-check-circle me-1"></i>Save</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="card-body p-0">
      <?php if (empty($deployments)): ?>
        <div class="text-center text-muted py-4">
          <i class="bi bi-rocket-takeoff fs-1 opacity-25"></i>
          <p class="mt-2 mb-0">No deployments recorded yet.</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>Branch</th>
                <th>Notes</th>
                <th>Added By</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php $n = count($deployments); foreach ($deployments as $i => $dep): ?>
              <tr>
                <td class="text-muted small"><?= $n - $i ?></td>
                <td class="fw-semibold text-nowrap"><?= date('d M Y', strtotime($dep['deployment_date'])) ?></td>
                <td>
                  <span class="badge bg-dark bg-opacity-10 text-dark font-monospace"><?= htmlspecialchars($dep['branch']) ?></span>
                </td>
                <td class="small text-muted"><?= htmlspecialchars($dep['notes'] ?? '—') ?></td>
                <td class="small"><?= htmlspecialchars($dep['created_by_name'] ?? 'System') ?></td>
                <td class="text-center">
                  <form method="POST" class="d-inline" onsubmit="return confirm('Delete this deployment record?')">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete_deployment">
                    <input type="hidden" name="deploy_id" value="<?= $dep['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash3"></i></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function toggleVis(btn) {
  const input = btn.previousElementSibling;
  const icon = btn.querySelector('i');
  if (input.type === 'password') { input.type = 'text'; icon.className = 'bi bi-eye-slash'; }
  else { input.type = 'password'; icon.className = 'bi bi-eye'; }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

