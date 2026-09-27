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

// Fetch all instances for this client
$instStmt = $db->prepare("SELECT * FROM client_instances WHERE client_id=? ORDER BY is_default DESC, id ASC");
$instStmt->execute([$id]);
$instances = $instStmt->fetchAll();

// Auto-initialize primary instance if client has none yet
if (empty($instances)) {
    $db->prepare("INSERT INTO client_instances (client_id, instance_name, environment, status, is_default) VALUES (?, 'Primary Instance', 'Production', 'active', 1)")
       ->execute([$id]);
    $defaultInstId = $db->lastInsertId();
    $db->prepare("UPDATE client_settings SET instance_id=? WHERE client_id=? AND (instance_id IS NULL OR instance_id=0)")
       ->execute([$defaultInstId, $id]);

    $instStmt->execute([$id]);
    $instances = $instStmt->fetchAll();
}

// Active Instance Selection
$currentInstanceId = (int)($_GET['instance_id'] ?? $_POST['instance_id'] ?? 0);
$currentInstance = null;
foreach ($instances as $inst) {
    if ((int)$inst['id'] === $currentInstanceId) {
        $currentInstance = $inst;
        break;
    }
}
if (!$currentInstance) {
    $currentInstance = $instances[0];
    $currentInstanceId = (int)$currentInstance['id'];
}

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
    // Plan Details
    'plan_type'            => ['label' => 'Plan Type',             'type' => 'select', 'options' => ['Monthly', 'Yearly']],
    'plan_start_date'      => ['label' => 'Start Date',            'type' => 'date'],
    'plan_end_date'        => ['label' => 'End Date',              'type' => 'date'],
    'api_integration_code' => ['label' => 'API Integration Code',  'type' => 'text'],
    'environment_name'     => ['label' => 'Environment Name',      'type' => 'text'],
    // Analytics & Social
    'google_analytics'  => ['label' => 'Google Analytics ID',   'type' => 'text'],
    'facebook_page'     => ['label' => 'Facebook Page URL',     'type' => 'url'],
    'instagram_handle'  => ['label' => 'Instagram Handle',      'type' => 'text'],
    'notes'             => ['label' => 'Client Notes',          'type' => 'textarea'],
    'keywords'          => ['label' => 'Target Keywords',       'type' => 'textarea'],
    'monthly_report'    => ['label' => 'Monthly Report Link',   'type' => 'url'],
    'deployment_config' => ['label' => 'Deployment Config File', 'type' => 'textarea'],
    'custom_1_key'      => ['label' => 'Custom Field 1 Key',    'type' => 'text'],
    'custom_1_value'    => ['label' => 'Custom Field 1 Value',  'type' => 'text'],
    'custom_2_key'      => ['label' => 'Custom Field 2 Key',    'type' => 'text'],
    'custom_2_value'    => ['label' => 'Custom Field 2 Value',  'type' => 'text'],
];

// Get existing settings for the current instance
$existing = [];
$settingsResult = $db->prepare("SELECT setting_key, setting_value FROM client_settings WHERE client_id=? AND instance_id=?");
$settingsResult->execute([$id, $currentInstanceId]);
foreach ($settingsResult->fetchAll() as $row) {
    $existing[$row['setting_key']] = $row['setting_value'];
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    // Add Instance
    if ($action === 'add_instance') {
        $instName = trim($_POST['instance_name'] ?? '');
        $envName  = trim($_POST['environment'] ?? 'Production');
        $status   = trim($_POST['status'] ?? 'active');

        if ($instName !== '') {
            $db->prepare("INSERT INTO client_instances (client_id, instance_name, environment, status, is_default) VALUES (?, ?, ?, ?, 0)")
               ->execute([$id, $instName, $envName, $status]);
            $newInstanceId = $db->lastInsertId();
            setFlash('success', 'New instance "' . htmlspecialchars($instName) . '" created successfully.');
            redirect(BASE_URL . '/modules/clients/settings.php?id=' . $id . '&instance_id=' . $newInstanceId);
        } else {
            setFlash('danger', 'Instance name is required.');
            redirect(BASE_URL . '/modules/clients/settings.php?id=' . $id . '&instance_id=' . $currentInstanceId);
        }
    }

    // Edit Instance
    if ($action === 'edit_instance') {
        $targetInstId = (int)($_POST['instance_id'] ?? 0);
        $instName     = trim($_POST['instance_name'] ?? '');
        $envName      = trim($_POST['environment'] ?? 'Production');
        $status       = trim($_POST['status'] ?? 'active');

        if ($targetInstId && $instName !== '') {
            $db->prepare("UPDATE client_instances SET instance_name=?, environment=?, status=? WHERE id=? AND client_id=?")
               ->execute([$instName, $envName, $status, $targetInstId, $id]);
            setFlash('success', 'Instance details updated.');
        } else {
            setFlash('danger', 'Instance name is required.');
        }
        redirect(BASE_URL . '/modules/clients/settings.php?id=' . $id . '&instance_id=' . $targetInstId);
    }

    // Delete Instance
    if ($action === 'delete_instance') {
        $targetInstId = (int)($_POST['instance_id'] ?? 0);
        if ($targetInstId) {
            if (count($instances) <= 1) {
                setFlash('danger', 'Cannot delete the only remaining instance.');
            } else {
                $db->prepare("DELETE FROM client_instances WHERE id=? AND client_id=?")->execute([$targetInstId, $id]);
                setFlash('success', 'Instance deleted successfully.');
            }
        }
        redirect(BASE_URL . '/modules/clients/settings.php?id=' . $id);
    }

    // Export / Download Deployment Config File
    if ($action === 'download_config') {
        $format = $_POST['format'] ?? 'json';
        $clientCode = preg_replace('/[^A-Za-z0-9_\-]/', '', $client['client_code'] ?: 'client_' . $id);
        $instSlug = preg_replace('/[^A-Za-z0-9_\-]/', '', strtolower($currentInstance['instance_name']));

        $configContent = trim($_POST['config_content'] ?? '');
        if ($configContent === '') {
            $configContent = $existing['deployment_config'] ?? '';
        }

        if (trim($configContent) === '') {
            if ($format === 'xml') {
                $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><DeploymentConfig/>');
                $clientNode = $xml->addChild('Client');
                $clientNode->addChild('Id', $id);
                $clientNode->addChild('Code', htmlspecialchars($client['client_code'] ?? ''));
                $clientNode->addChild('Name', htmlspecialchars($client['name']));
                $clientNode->addChild('Company', htmlspecialchars($client['company'] ?? ''));
                $clientNode->addChild('Project', htmlspecialchars($client['project_name'] ?? ''));

                $instNode = $xml->addChild('Instance');
                $instNode->addChild('Id', $currentInstanceId);
                $instNode->addChild('Name', htmlspecialchars($currentInstance['instance_name']));
                $instNode->addChild('Environment', htmlspecialchars($currentInstance['environment']));
                $instNode->addChild('Status', htmlspecialchars($currentInstance['status']));

                $depNode = $xml->addChild('Deployment');
                $depNode->addChild('Environment', htmlspecialchars(!empty($existing['environment_name']) ? $existing['environment_name'] : $currentInstance['environment']));
                $depNode->addChild('ApiKey', htmlspecialchars($existing['api_key'] ?? ''));
                $depNode->addChild('ApiIntegrationCode', htmlspecialchars($existing['api_integration_code'] ?? ''));
                $depNode->addChild('Domain', htmlspecialchars($existing['domain'] ?? ''));
                $depNode->addChild('HostingServer', htmlspecialchars($existing['hosting_server'] ?? ''));
                $depNode->addChild('ServerUsername', htmlspecialchars($existing['server_username'] ?? ''));
                $depNode->addChild('SupportEmail', htmlspecialchars($existing['support_email'] ?? ''));

                $dbNode = $xml->addChild('Database');
                $dbNode->addChild('Type', htmlspecialchars($existing['db_type'] ?? 'MySQL'));
                $dbNode->addChild('Connection', htmlspecialchars($existing['db_connection'] ?? ''));
                $dbNode->addChild('Username', htmlspecialchars($existing['db_username'] ?? ''));
                $dbNode->addChild('Password', htmlspecialchars($existing['db_password'] ?? ''));

                $xml->addChild('GeneratedAt', date('Y-m-d H:i:s'));

                $dom = dom_import_simplexml($xml)->ownerDocument;
                $dom->formatOutput = true;
                $configContent = $dom->saveXML();
            } else {
                $data = [
                    'client' => [
                        'id' => $id,
                        'code' => $client['client_code'] ?? '',
                        'name' => $client['name'],
                        'company' => $client['company'] ?? '',
                        'project' => $client['project_name'] ?? ''
                    ],
                    'instance' => [
                        'id' => $currentInstanceId,
                        'name' => $currentInstance['instance_name'],
                        'environment' => $currentInstance['environment'],
                        'status' => $currentInstance['status']
                    ],
                    'deployment' => [
                        'environment' => !empty($existing['environment_name']) ? $existing['environment_name'] : $currentInstance['environment'],
                        'api_key' => $existing['api_key'] ?? '',
                        'api_integration_code' => $existing['api_integration_code'] ?? '',
                        'domain' => $existing['domain'] ?? '',
                        'hosting_server' => $existing['hosting_server'] ?? '',
                        'server_username' => $existing['server_username'] ?? '',
                        'support_email' => $existing['support_email'] ?? ''
                    ],
                    'database' => [
                        'type' => $existing['db_type'] ?? 'MySQL',
                        'connection' => $existing['db_connection'] ?? '',
                        'username' => $existing['db_username'] ?? '',
                        'password' => $existing['db_password'] ?? ''
                    ],
                    'generated_at' => date('Y-m-d H:i:s')
                ];
                $configContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        $ext = ($format === 'xml') ? 'xml' : 'json';
        $filename = "deployment-config-{$clientCode}-{$instSlug}.{$ext}";

        header('Content-Type: ' . ($format === 'xml' ? 'application/xml' : 'application/json') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $configContent;
        exit;
    }

    // Add deployment
    if ($action === 'add_deployment') {
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
        redirect(BASE_URL . '/modules/clients/settings.php?id=' . $id . '&instance_id=' . $currentInstanceId . '#deployments');
    }

    // Delete deployment
    if ($action === 'delete_deployment') {
        $depId = (int)($_POST['deploy_id'] ?? 0);
        if ($depId) {
            $db->prepare("DELETE FROM client_deployments WHERE id=? AND client_id=?")->execute([$depId, $id]);
            setFlash('success', 'Deployment record deleted.');
        }
        redirect(BASE_URL . '/modules/clients/settings.php?id=' . $id . '&instance_id=' . $currentInstanceId . '#deployments');
    }

    // Save settings (default action)
    $settings = $_POST['settings'] ?? [];
    foreach ($settings as $key => $value) {
        if (!array_key_exists($key, $settingDefs)) continue;
        $value = trim($value);
        if ($value !== '') {
            $db->prepare("INSERT INTO client_settings (client_id, instance_id, setting_key, setting_value) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=?")
               ->execute([$id, $currentInstanceId, $key, $value, $value]);
        } else {
            $db->prepare("DELETE FROM client_settings WHERE client_id=? AND instance_id=? AND setting_key=?")
               ->execute([$id, $currentInstanceId, $key]);
        }
    }
    setFlash('success', 'Settings for instance "' . htmlspecialchars($currentInstance['instance_name']) . '" saved successfully.');
    redirect(BASE_URL . '/modules/clients/settings.php?id=' . $id . '&instance_id=' . $currentInstanceId);
}

// Fetch deployment history
$deployStmt = $db->prepare("SELECT d.*, u.name as created_by_name FROM client_deployments d LEFT JOIN users u ON u.id=d.created_by WHERE d.client_id=? ORDER BY d.deployment_date DESC, d.created_at DESC");
$deployStmt->execute([$id]);
$deployments = $deployStmt->fetchAll();

$pageTitle = 'Client Settings: ' . $client['name'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
  <div class="d-flex align-items-center gap-3">
    <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <div>
      <h4 class="mb-0">Client Settings & Instances</h4>
      <p class="text-muted small mb-0"><?= htmlspecialchars($client['name']) ?> — <?= htmlspecialchars($client['project_name']) ?></p>
    </div>
  </div>
  <div>
    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addInstanceModal">
      <i class="bi bi-plus-lg me-1"></i>Add New Instance
    </button>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Instance Selection Bar -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3 bg-light rounded">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
      <span class="fw-semibold small text-uppercase text-muted"><i class="bi bi-layers me-1 text-primary"></i> Client Instances / Applications</span>
      <span class="badge bg-secondary bg-opacity-10 text-secondary"><?= count($instances) ?> Instance<?= count($instances) > 1 ? 's' : '' ?> Configured</span>
    </div>

    <ul class="nav nav-pills flex-nowrap overflow-auto pb-1 gap-2" id="instancePills">
      <?php foreach ($instances as $inst): 
        $isActive = ($inst['id'] === $currentInstanceId);
        $badgeClass = $inst['status'] === 'active' ? 'bg-success' : ($inst['status'] === 'maintenance' ? 'bg-warning text-dark' : 'bg-secondary');
      ?>
      <li class="nav-item">
        <a class="nav-link text-nowrap <?= $isActive ? 'active fw-semibold shadow-sm' : 'bg-white border text-dark' ?>" 
           href="<?= BASE_URL ?>/modules/clients/settings.php?id=<?= $id ?>&instance_id=<?= $inst['id'] ?>">
          <?php if ($inst['is_default']): ?>
            <i class="bi bi-star-fill text-warning me-1" title="Primary Instance"></i>
          <?php else: ?>
            <i class="bi bi-cpu me-1"></i>
          <?php endif; ?>
          <?= htmlspecialchars($inst['instance_name']) ?>
          <span class="badge ms-1 <?= $isActive ? 'bg-white text-dark' : $badgeClass ?> font-monospace" style="font-size: 0.7rem;">
            <?= htmlspecialchars($inst['environment']) ?>
          </span>
        </a>
      </li>
      <?php endforeach; ?>
      <li class="nav-item">
        <button type="button" class="nav-link border text-success bg-white text-nowrap" data-bs-toggle="modal" data-bs-target="#addInstanceModal">
          <i class="bi bi-plus-circle me-1"></i>New Instance
        </button>
      </li>
    </ul>
  </div>

  <div class="card-footer bg-white border-top-0 d-flex align-items-center justify-content-between flex-wrap gap-2 py-2">
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-primary-subtle text-primary fw-semibold"><i class="bi bi-check2-circle me-1"></i>Active Instance: <?= htmlspecialchars($currentInstance['instance_name']) ?></span>
      <span class="badge bg-outline border text-dark"><?= htmlspecialchars($currentInstance['environment']) ?> Environment</span>
      <span class="badge <?= $currentInstance['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"><?= ucfirst($currentInstance['status']) ?></span>
    </div>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editInstanceModal">
        <i class="bi bi-pencil me-1"></i>Edit Instance Info
      </button>
      <?php if (count($instances) > 1): ?>
        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete instance \'<?= htmlspecialchars($currentInstance['instance_name']) ?>\' and ALL its associated settings?')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="delete_instance">
          <input type="hidden" name="instance_id" value="<?= $currentInstanceId ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3 me-1"></i>Delete Instance</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
  <input type="hidden" name="instance_id" value="<?= $currentInstanceId ?>">

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
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-globe me-2 text-primary"></i>Domain & Support</div>
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

    <!-- Plan Details -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-box-seam me-2 text-primary"></i>Plan Details</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Plan Type</label>
            <select name="settings[plan_type]" id="plan_type" class="form-select form-select-sm">
              <option value="">-- Select --</option>
              <?php foreach ($settingDefs['plan_type']['options'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($existing['plan_type'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small fw-semibold">Start Date</label>
              <input type="date" name="settings[plan_start_date]" id="plan_start_date"
                     class="form-control form-control-sm"
                     value="<?= htmlspecialchars($existing['plan_start_date'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">End Date</label>
              <input type="date" name="settings[plan_end_date]" id="plan_end_date"
                     class="form-control form-control-sm"
                     value="<?= htmlspecialchars($existing['plan_end_date'] ?? '') ?>">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">API Integration Code</label>
            <input type="text" name="settings[api_integration_code]"
                   class="form-control form-control-sm"
                   value="<?= htmlspecialchars($existing['api_integration_code'] ?? '') ?>"
                   placeholder="e.g. API-INT-1029">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Environment Name</label>
            <input type="text" name="settings[environment_name]"
                   class="form-control form-control-sm"
                   value="<?= htmlspecialchars(!empty($existing['environment_name']) ? $existing['environment_name'] : $currentInstance['environment']) ?>"
                   placeholder="e.g. Production, Staging, Development">
          </div>
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

    <!-- Deployment Config File -->
    <div class="col-xl-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
          <span><i class="bi bi-file-earmark-code me-2 text-primary"></i>Deployment Configuration File (Instance: <?= htmlspecialchars($currentInstance['instance_name']) ?>)</span>
          <div class="d-flex gap-2 align-items-center flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="generateConfigJSON()" title="Auto-generate JSON config from existing settings">
              <i class="bi bi-filetype-json me-1"></i>Generate JSON
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="generateConfigXML()" title="Auto-generate XML config from existing settings">
              <i class="bi bi-filetype-xml me-1"></i>Generate XML
            </button>
            <label class="btn btn-sm btn-outline-info mb-0" style="cursor: pointer;" title="Upload XML or JSON config file from computer">
              <i class="bi bi-upload me-1"></i>Upload File
              <input type="file" id="configFileInput" accept=".json,.xml,.txt" style="display:none;" onchange="handleConfigFileUpload(event)">
            </label>
            <button type="button" class="btn btn-sm btn-outline-success" onclick="downloadConfigFromTextarea()" title="Download current configuration file to computer">
              <i class="bi bi-download me-1"></i>Save & Download File
            </button>
          </div>
        </div>
        <div class="card-body">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Deployment Config File Content (XML or JSON format)</label>
            <p class="text-muted small mb-2">Save custom deployment properties, server configurations, or environment settings for this instance in XML or JSON format. Click <strong>Save Settings</strong> below to store in database.</p>
            <textarea name="settings[deployment_config]" id="deployment_config" class="form-control font-monospace form-control-sm" rows="7" placeholder='JSON format:&#10;{&#10;  "instance": "<?= htmlspecialchars($currentInstance['instance_name']) ?>",&#10;  "environment": "<?= htmlspecialchars($currentInstance['environment']) ?>",&#10;  "hosting_server": "192.168.1.100"&#10;}&#10;&#10;XML format:&#10;<DeploymentConfig>&#10;  <Instance><?= htmlspecialchars($currentInstance['instance_name']) ?></Instance>&#10;  <Environment><?= htmlspecialchars($currentInstance['environment']) ?></Environment>&#10;</DeploymentConfig>'><?= htmlspecialchars($existing['deployment_config'] ?? '') ?></textarea>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Settings for <?= htmlspecialchars($currentInstance['instance_name']) ?></button>
    <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<!-- Modal: Add New Instance -->
<div class="modal fade" id="addInstanceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="add_instance">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-cpu me-2 text-primary"></i>Add New Instance / Application</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Instance Name <span class="text-danger">*</span></label>
            <input type="text" name="instance_name" class="form-control" required placeholder="e.g. Staging Server, Mobile App API, Secondary Portal">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Environment</label>
            <select name="environment" class="form-select">
              <option value="Production">Production</option>
              <option value="Staging">Staging</option>
              <option value="Development">Development</option>
              <option value="UAT">UAT</option>
              <option value="QA">QA</option>
              <option value="Mobile API">Mobile API</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Status</label>
            <select name="status" class="form-select">
              <option value="active">Active</option>
              <option value="maintenance">Maintenance</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Create Instance</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Edit Current Instance -->
<div class="modal fade" id="editInstanceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="edit_instance">
        <input type="hidden" name="instance_id" value="<?= $currentInstanceId ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil me-2 text-primary"></i>Edit Instance Info</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Instance Name <span class="text-danger">*</span></label>
            <input type="text" name="instance_name" class="form-control" required value="<?= htmlspecialchars($currentInstance['instance_name']) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Environment</label>
            <select name="environment" class="form-select">
              <?php foreach (['Production','Staging','Development','UAT','QA','Mobile API'] as $envOpt): ?>
                <option value="<?= $envOpt ?>" <?= $currentInstance['environment'] === $envOpt ? 'selected' : '' ?>><?= $envOpt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Status</label>
            <select name="status" class="form-select">
              <option value="active" <?= $currentInstance['status'] === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="maintenance" <?= $currentInstance['status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
              <option value="inactive" <?= $currentInstance['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

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

function calculateEndDate(startDateStr, planType) {
  if (!startDateStr) return '';
  const parts = startDateStr.split('-');
  if (parts.length !== 3) return '';
  
  let year = parseInt(parts[0], 10);
  let month = parseInt(parts[1], 10) - 1;
  let day = parseInt(parts[2], 10);

  let date = new Date(year, month, day);

  if (planType === 'Yearly') {
    date.setFullYear(date.getFullYear() + 1);
  } else {
    date.setMonth(date.getMonth() + 1);
  }

  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

document.addEventListener('DOMContentLoaded', function() {
  const startDateInput = document.getElementById('plan_start_date');
  const endDateInput = document.getElementById('plan_end_date');
  const planTypeSelect = document.getElementById('plan_type');

  function updateEndDate() {
    if (!startDateInput || !endDateInput) return;
    const startDateVal = startDateInput.value;
    const planTypeVal = planTypeSelect ? planTypeSelect.value : 'Monthly';
    if (startDateVal) {
      endDateInput.value = calculateEndDate(startDateVal, planTypeVal);
    }
  }

  if (startDateInput) {
    startDateInput.addEventListener('change', updateEndDate);
  }
  if (planTypeSelect) {
    planTypeSelect.addEventListener('change', function() {
      if (startDateInput && startDateInput.value) {
        updateEndDate();
      }
    });
  }
});

function generateConfigJSON() {
  const form = document.querySelector('form');
  const getVal = (name) => {
    const input = form.querySelector(`[name="settings[${name}]"]`);
    return input ? input.value.trim() : '';
  };

  const clientName = <?= json_encode($client['name']) ?>;
  const clientCode = <?= json_encode($client['client_code'] ?? '') ?>;
  const projectName = <?= json_encode($client['project_name'] ?? '') ?>;
  const instanceName = <?= json_encode($currentInstance['instance_name']) ?>;
  const instanceEnv = <?= json_encode($currentInstance['environment']) ?>;

  const data = {
    client: {
      code: clientCode,
      name: clientName,
      project: projectName
    },
    instance: {
      id: <?= (int)$currentInstanceId ?>,
      name: instanceName,
      environment: instanceEnv
    },
    deployment: {
      environment: getVal('environment_name') || instanceEnv,
      api_key: getVal('api_key'),
      api_integration_code: getVal('api_integration_code'),
      domain: getVal('domain'),
      hosting_server: getVal('hosting_server'),
      server_username: getVal('server_username'),
      server_password: getVal('server_password'),
      support_email: getVal('support_email')
    },
    database: {
      type: getVal('db_type') || 'MySQL',
      connection: getVal('db_connection'),
      username: getVal('db_username'),
      password: getVal('db_password')
    },
    generated_at: new Date().toISOString()
  };

  document.getElementById('deployment_config').value = JSON.stringify(data, null, 2);
}

function generateConfigXML() {
  const form = document.querySelector('form');
  const getVal = (name) => {
    const input = form.querySelector(`[name="settings[${name}]"]`);
    return input ? input.value.trim() : '';
  };

  const escapeXml = (str) => {
    return (str || '').replace(/[<>&'"]/g, function (c) {
      switch (c) {
        case '<': return '&lt;';
        case '>': return '&gt;';
        case '&': return '&amp;';
        case '\'': return '&apos;';
        case '"': return '&quot;';
      }
    });
  };

  const clientName = <?= json_encode($client['name']) ?>;
  const clientCode = <?= json_encode($client['client_code'] ?? '') ?>;
  const projectName = <?= json_encode($client['project_name'] ?? '') ?>;
  const instanceName = <?= json_encode($currentInstance['instance_name']) ?>;
  const instanceEnv = <?= json_encode($currentInstance['environment']) ?>;

  let xml = '<' + '?xml version="1.0" encoding="UTF-8"?>\n';
  xml += `<DeploymentConfig>\n`;
  xml += `  <Client>\n`;
  xml += `    <Code>${escapeXml(clientCode)}</Code>\n`;
  xml += `    <Name>${escapeXml(clientName)}</Name>\n`;
  xml += `    <Project>${escapeXml(projectName)}</Project>\n`;
  xml += `  </Client>\n`;
  xml += `  <Instance>\n`;
  xml += `    <Id>${<?= (int)$currentInstanceId ?>}</Id>\n`;
  xml += `    <Name>${escapeXml(instanceName)}</Name>\n`;
  xml += `    <Environment>${escapeXml(instanceEnv)}</Environment>\n`;
  xml += `  </Instance>\n`;
  xml += `  <Deployment>\n`;
  xml += `    <Environment>${escapeXml(getVal('environment_name') || instanceEnv)}</Environment>\n`;
  xml += `    <ApiKey>${escapeXml(getVal('api_key'))}</ApiKey>\n`;
  xml += `    <ApiIntegrationCode>${escapeXml(getVal('api_integration_code'))}</ApiIntegrationCode>\n`;
  xml += `    <Domain>${escapeXml(getVal('domain'))}</Domain>\n`;
  xml += `    <HostingServer>${escapeXml(getVal('hosting_server'))}</HostingServer>\n`;
  xml += `    <ServerUsername>${escapeXml(getVal('server_username'))}</ServerUsername>\n`;
  xml += `    <ServerPassword>${escapeXml(getVal('server_password'))}</ServerPassword>\n`;
  xml += `    <SupportEmail>${escapeXml(getVal('support_email'))}</SupportEmail>\n`;
  xml += `  </Deployment>\n`;
  xml += `  <Database>\n`;
  xml += `    <Type>${escapeXml(getVal('db_type') || 'MySQL')}</Type>\n`;
  xml += `    <Connection>${escapeXml(getVal('db_connection'))}</Connection>\n`;
  xml += `    <Username>${escapeXml(getVal('db_username'))}</Username>\n`;
  xml += `    <Password>${escapeXml(getVal('db_password'))}</Password>\n`;
  xml += `  </Database>\n`;
  xml += `  <GeneratedAt>${new Date().toISOString()}</GeneratedAt>\n`;
  xml += `</DeploymentConfig>`;

  document.getElementById('deployment_config').value = xml;
}

function handleConfigFileUpload(event) {
  const file = event.target.files[0];
  if (!file) return;

  const reader = new FileReader();
  reader.onload = function(e) {
    document.getElementById('deployment_config').value = e.target.result;
  };
  reader.readAsText(file);
}

function downloadConfigFromTextarea() {
  const content = document.getElementById('deployment_config').value;
  const clientCode = <?= json_encode(preg_replace('/[^A-Za-z0-9_\-]/', '', $client['client_code'] ?: 'client_' . $id)) ?>;
  const instSlug = <?= json_encode(preg_replace('/[^A-Za-z0-9_\-]/', '', strtolower($currentInstance['instance_name']))) ?>;
  
  if (!content.trim()) {
    alert('Please enter or generate deployment config content before downloading.');
    return;
  }

  const isXml = content.trim().startsWith('<') || content.includes('</DeploymentConfig>') || content.includes('<' + '?xml');
  const ext = isXml ? 'xml' : 'json';
  const mime = isXml ? 'application/xml' : 'application/json';
  
  const blob = new Blob([content], { type: mime });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `deployment-config-${clientCode}-${instSlug}.${ext}`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
