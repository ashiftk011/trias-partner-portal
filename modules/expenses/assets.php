<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/accounting.php';
require_once __DIR__ . '/../../includes/expense_helpers.php';
requireAccess('expenses');

$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // 1. ADD / EDIT ASSET CATEGORY
    if ($action === 'save_asset_category') {
        $catId   = (int)($_POST['category_id'] ?? 0);
        $catName = trim($_POST['name'] ?? '');
        $catDesc = trim($_POST['description'] ?? '');

        if (!$catName) {
            setFlash('danger', 'Asset category name is required.');
            redirect(BASE_URL . '/modules/expenses/assets.php');
        }

        if ($catId > 0) {
            $db->prepare("UPDATE asset_categories SET name=?, description=? WHERE id=?")->execute([$catName, $catDesc, $catId]);
            setFlash('success', 'Asset category updated successfully.');
        } else {
            $db->prepare("INSERT INTO asset_categories (name, description) VALUES (?,?) ON DUPLICATE KEY UPDATE description=?")
               ->execute([$catName, $catDesc, $catDesc]);
            setFlash('success', 'Asset category added successfully.');
        }
        redirect(BASE_URL . '/modules/expenses/assets.php');
    }

    // 2. DELETE ASSET CATEGORY
    if ($action === 'delete_asset_category') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $chk = $db->prepare("SELECT COUNT(*) FROM company_assets WHERE category_id=?");
        $chk->execute([$catId]);
        if ($chk->fetchColumn() > 0) {
            setFlash('danger', 'Cannot delete category that contains recorded assets.');
        } else {
            $db->prepare("DELETE FROM asset_categories WHERE id=?")->execute([$catId]);
            setFlash('success', 'Asset category deleted.');
        }
        redirect(BASE_URL . '/modules/expenses/assets.php');
    }

    // 3. SAVE / EDIT ASSET
    if ($action === 'save_asset') {
        $assetId     = (int)($_POST['asset_id'] ?? 0);
        $assetCode   = trim($_POST['asset_code'] ?? '');
        $name        = trim($_POST['name'] ?? '');
        $categoryId  = (int)($_POST['category_id'] ?? 0) ?: null;
        $purchaseDate= trim($_POST['purchase_date'] ?? date('Y-m-d'));
        $cost        = (float)($_POST['purchase_cost'] ?? 0);
        $currentVal  = !empty($_POST['current_value']) ? (float)$_POST['current_value'] : $cost;
        $vendor      = trim($_POST['vendor_name'] ?? '');
        $invoiceNo   = trim($_POST['invoice_no'] ?? '');
        $serialNo    = trim($_POST['serial_no'] ?? '');
        $warrantyExp = !empty($_POST['warranty_expiry']) ? trim($_POST['warranty_expiry']) : null;
        $accountId   = (int)($_POST['account_id'] ?? 0) ?: null;
        $empId       = (int)($_POST['assigned_to_employee_id'] ?? 0) ?: null;
        $location    = trim($_POST['location'] ?? '');
        $status      = trim($_POST['status'] ?? 'active');
        $condition   = trim($_POST['condition_status'] ?? 'good');
        $notes       = trim($_POST['notes'] ?? '');

        if (!$assetCode || !$name || $cost < 0 || !$purchaseDate) {
            setFlash('danger', 'Asset Code, Name, Purchase Date and Valid Cost are required.');
            redirect(BASE_URL . '/modules/expenses/assets.php');
        }

        // Attachment file upload
        $filePath = null;
        if (!empty($_FILES['attachment_file']['name']) && $_FILES['attachment_file']['error'] === UPLOAD_ERR_OK) {
            $allowedExts = ['jpg','jpeg','png','pdf','webp','doc','docx'];
            $ext = strtolower(pathinfo($_FILES['attachment_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExts)) {
                $uploadDir = __DIR__ . '/../../uploads/assets/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $filename = 'asset_' . time() . '_' . rand(100, 999) . '.' . $ext;
                move_uploaded_file($_FILES['attachment_file']['tmp_name'], $uploadDir . $filename);
                $filePath = 'uploads/assets/' . $filename;
            }
        }

        if ($assetId > 0) {
            $sql = "UPDATE company_assets SET asset_code=?, name=?, category_id=?, purchase_date=?, purchase_cost=?, current_value=?, vendor_name=?, invoice_no=?, serial_no=?, warranty_expiry=?, account_id=?, assigned_to_employee_id=?, location=?, status=?, condition_status=?, notes=?";
            $params = [$assetCode, $name, $categoryId, $purchaseDate, $cost, $currentVal, $vendor, $invoiceNo, $serialNo, $warrantyExp, $accountId, $empId, $location, $status, $condition, $notes];
            if ($filePath) {
                $sql .= ", attachment_file=?";
                $params[] = $filePath;
            }
            $sql .= " WHERE id=?";
            $params[] = $assetId;
            $db->prepare($sql)->execute($params);
            $savedAssetId = $assetId;
            setFlash('success', 'Asset record updated successfully.');
        } else {
            $stmtIns = $db->prepare("
                INSERT INTO company_assets (asset_code, name, category_id, purchase_date, purchase_cost, current_value, vendor_name, invoice_no, serial_no, warranty_expiry, account_id, assigned_to_employee_id, location, status, condition_status, attachment_file, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtIns->execute([
                $assetCode, $name, $categoryId, $purchaseDate, $cost, $currentVal, $vendor, $invoiceNo, $serialNo, $warrantyExp, $accountId, $empId, $location, $status, $condition, $filePath, $notes, currentUser()['id']
            ]);
            $savedAssetId = (int)$db->lastInsertId();
            setFlash('success', 'Asset recorded successfully.');
        }

        // If paid from company account and cost > 0, sync transaction
        if ($accountId && $cost > 0) {
            syncReferenceTransaction(
                $accountId,
                'manual',
                $savedAssetId,
                $purchaseDate,
                'direct_debit',
                'credit',
                $cost,
                "Company Asset Purchase: {$name} ({$assetCode})",
                $notes,
                currentUser()['id']
            );
        }

        redirect(BASE_URL . '/modules/expenses/assets.php');
    }

    // 4. DELETE ASSET
    if ($action === 'delete_asset') {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        if ($assetId) {
            deleteReferenceTransactions('manual', $assetId);
            $db->prepare("DELETE FROM company_assets WHERE id=?")->execute([$assetId]);
            setFlash('success', 'Asset record removed.');
        }
        redirect(BASE_URL . '/modules/expenses/assets.php');
    }
}

// Fetch categories & employees
$categories = getAssetCategories();
$employees  = $db->query("SELECT id, name, emp_number, designation, department FROM employees WHERE status='active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

restorePageFilters('company_assets', ['category_id', 'status', 'condition', 'q']);

$filterCategory  = (int)($_GET['category_id'] ?? 0);
$filterStatus    = trim($_GET['status'] ?? '');
$filterCondition = trim($_GET['condition'] ?? '');
$search          = trim($_GET['q'] ?? '');

$sql = "SELECT ca.*, ac.name as category_name, emp.name as employee_name, emp.designation as employee_designation,
               u.name as created_by_name, acc.account_name
        FROM company_assets ca
        LEFT JOIN asset_categories ac ON ac.id = ca.category_id
        LEFT JOIN employees emp ON emp.id = ca.assigned_to_employee_id
        LEFT JOIN company_accounts acc ON acc.id = ca.account_id
        LEFT JOIN users u ON u.id = ca.created_by
        WHERE 1=1";
$params = [];

if ($filterCategory) {
    $sql .= " AND ca.category_id = ?";
    $params[] = $filterCategory;
}
if ($filterStatus) {
    $sql .= " AND ca.status = ?";
    $params[] = $filterStatus;
}
if ($filterCondition) {
    $sql .= " AND ca.condition_status = ?";
    $params[] = $filterCondition;
}
if ($search) {
    $sql .= " AND (ca.name LIKE ? OR ca.asset_code LIKE ? OR ca.serial_no LIKE ? OR ca.vendor_name LIKE ? OR ca.location LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%"]);
}

$sql .= " ORDER BY ca.purchase_date DESC, ca.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPI Calculations
$totalPurchaseCost = 0.00;
$totalCurrentValue = 0.00;
$activeCount = 0;
$maintenanceCount = 0;

foreach ($assets as $a) {
    $totalPurchaseCost += (float)$a['purchase_cost'];
    $totalCurrentValue += (float)($a['current_value'] ?: $a['purchase_cost']);
    if (in_array($a['status'], ['active', 'in_use'])) $activeCount++;
    if (in_array($a['status'], ['maintenance', 'damaged', 'disposed', 'sold'])) $maintenanceCount++;
}

$nextCode = generateNextAssetCode();

$pageTitle = 'Company Assets Register';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Header -->
<div class="page-header d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h4 class="mb-0"><i class="bi bi-laptop me-2 text-primary"></i>Company Asset Management</h4>
    <p class="text-muted small mb-0">Record and monitor company fixed assets, hardware, software licenses, furniture, and appliances separately from operational expenses</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#manageAssetCategoriesModal">
      <i class="bi bi-tags me-1"></i>Asset Categories
    </button>
    <button class="btn btn-outline-primary" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print Register
    </button>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAssetModal" onclick="resetAssetModal()">
      <i class="bi bi-plus-circle me-1"></i>Register Asset
    </button>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Sub Navigation Tabs -->
<ul class="nav nav-pills mb-4 gap-2 bg-white p-2 rounded-3 shadow-sm border no-print">
  <li class="nav-item">
    <a class="nav-link text-dark fw-semibold" href="<?= BASE_URL ?>/modules/expenses/index.php">
      <i class="bi bi-receipt me-1"></i>Expense Register
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link text-dark fw-semibold" href="<?= BASE_URL ?>/modules/expenses/reports.php">
      <i class="bi bi-bar-chart-steps me-1"></i>Client &amp; Project Profitability
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link active fw-semibold" href="<?= BASE_URL ?>/modules/expenses/assets.php">
      <i class="bi bi-laptop me-1"></i>Company Assets (Separate)
    </a>
  </li>
</ul>

<!-- Stat Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card h-100">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-primary bg-opacity-10 text-primary p-3">
          <i class="bi bi-cash-coin fs-3"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-primary">₹<?= number_format($totalPurchaseCost, 2) ?></div>
          <div class="text-muted small">Total Asset Cost (<?= count($assets) ?> Assets)</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card h-100">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-success bg-opacity-10 text-success p-3">
          <i class="bi bi-shield-check fs-3"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-success">₹<?= number_format($totalCurrentValue, 2) ?></div>
          <div class="text-muted small">Current Book / Est. Value</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card h-100">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-info bg-opacity-10 text-info p-3">
          <i class="bi bi-check2-circle fs-3"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-info"><?= number_format($activeCount) ?></div>
          <div class="text-muted small">Active &amp; In-Use Assets</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card h-100">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-warning bg-opacity-10 text-warning p-3">
          <i class="bi bi-tools fs-3"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-warning"><?= number_format($maintenanceCount) ?></div>
          <div class="text-muted small">Maintenance / Disposed</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-4 no-print">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3 col-6">
        <label class="form-label small text-muted mb-1">Asset Category</label>
        <select name="category_id" class="form-select form-select-sm">
          <option value="0">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $filterCategory === (int)$cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small text-muted mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="in_use" <?= $filterStatus === 'in_use' ? 'selected' : '' ?>>In Use</option>
          <option value="maintenance" <?= $filterStatus === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
          <option value="damaged" <?= $filterStatus === 'damaged' ? 'selected' : '' ?>>Damaged</option>
          <option value="disposed" <?= $filterStatus === 'disposed' ? 'selected' : '' ?>>Disposed / Sold</option>
        </select>
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small text-muted mb-1">Physical Condition</label>
        <select name="condition" class="form-select form-select-sm">
          <option value="">All Conditions</option>
          <option value="excellent" <?= $filterCondition === 'excellent' ? 'selected' : '' ?>>Excellent</option>
          <option value="good" <?= $filterCondition === 'good' ? 'selected' : '' ?>>Good</option>
          <option value="fair" <?= $filterCondition === 'fair' ? 'selected' : '' ?>>Fair</option>
          <option value="poor" <?= $filterCondition === 'poor' ? 'selected' : '' ?>>Poor</option>
        </select>
      </div>
      <div class="col-md-5 col-12">
        <label class="form-label small text-muted mb-1">Search Assets</label>
        <div class="input-group input-group-sm">
          <input type="text" name="q" class="form-control" placeholder="Search code, name, serial #, vendor..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit" class="btn btn-primary px-3"><i class="bi bi-search me-1"></i>Filter</button>
          <?php if ($filterCategory || $filterStatus || $filterCondition || $search): ?>
            <a href="<?= BASE_URL ?>/modules/expenses/assets.php?clear=1" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-circle me-1"></i>Clear</a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Assets Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable align-middle">
        <thead class="table-light">
          <tr>
            <th>Asset Code</th>
            <th>Item Name &amp; Category</th>
            <th>Purchase Date</th>
            <th class="text-end">Cost (₹)</th>
            <th class="text-end">Est. Value (₹)</th>
            <th>Serial # / Tag</th>
            <th>Assigned To &amp; Location</th>
            <th>Status</th>
            <th>Condition</th>
            <th>Doc</th>
            <th class="text-center no-print">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($assets)): ?>
            <tr>
              <td colspan="11" class="text-center text-muted py-4">No company assets recorded yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($assets as $a): 
              $isWarrantyActive = !empty($a['warranty_expiry']) && strtotime($a['warranty_expiry']) >= strtotime(date('Y-m-d'));
            ?>
              <tr>
                <td>
                  <code class="fw-bold fs-6 text-primary"><?= htmlspecialchars($a['asset_code']) ?></code>
                </td>
                <td>
                  <div class="fw-bold text-dark"><?= htmlspecialchars($a['name']) ?></div>
                  <span class="badge bg-light text-dark border"><?= htmlspecialchars($a['category_name'] ?: 'General Asset') ?></span>
                  <?php if (!empty($a['vendor_name'])): ?>
                    <small class="text-muted ms-1"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($a['vendor_name']) ?></small>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap small text-muted">
                  <?= date('d M Y', strtotime($a['purchase_date'])) ?>
                  <?php if (!empty($a['invoice_no'])): ?>
                    <div class="text-secondary small font-monospace">Inv: <?= htmlspecialchars($a['invoice_no']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-end fw-bold text-dark">₹<?= number_format($a['purchase_cost'], 2) ?></td>
                <td class="text-end fw-semibold text-success">₹<?= number_format($a['current_value'] ?: $a['purchase_cost'], 2) ?></td>
                <td>
                  <?php if (!empty($a['serial_no'])): ?>
                    <code class="text-dark d-block"><?= htmlspecialchars($a['serial_no']) ?></code>
                  <?php endif; ?>
                  <?php if (!empty($a['warranty_expiry'])): ?>
                    <span class="badge <?= $isWarrantyActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-muted' ?> border py-0" style="font-size:0.7rem;">
                      <i class="bi bi-shield-check me-1"></i>Warranty: <?= date('d M Y', strtotime($a['warranty_expiry'])) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($a['employee_name'])): ?>
                    <div class="fw-semibold text-dark"><i class="bi bi-person-fill text-primary me-1"></i><?= htmlspecialchars($a['employee_name']) ?></div>
                    <small class="text-muted d-block"><?= htmlspecialchars($a['employee_designation'] ?: '') ?></small>
                  <?php else: ?>
                    <span class="text-muted small">Unassigned / Shared</span>
                  <?php endif; ?>
                  <?php if (!empty($a['location'])): ?>
                    <small class="text-muted d-block"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($a['location']) ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                    $statusBadge = match($a['status']) {
                      'active'     => 'bg-success-subtle text-success border-success',
                      'in_use'     => 'bg-primary-subtle text-primary border-primary',
                      'maintenance'=> 'bg-warning-subtle text-warning border-warning',
                      'damaged'    => 'bg-danger-subtle text-danger border-danger',
                      'disposed'   => 'bg-secondary-subtle text-secondary border-secondary',
                      'sold'       => 'bg-dark-subtle text-dark border-dark',
                      default      => 'bg-light text-dark'
                    };
                  ?>
                  <span class="badge border <?= $statusBadge ?>"><?= strtoupper(str_replace('_',' ',$a['status'])) ?></span>
                </td>
                <td>
                  <?php
                    $condBadge = match($a['condition_status']) {
                      'excellent' => 'bg-success text-white',
                      'good'      => 'bg-info text-white',
                      'fair'      => 'bg-warning text-dark',
                      'poor'      => 'bg-danger text-white',
                      default     => 'bg-secondary text-white'
                    };
                  ?>
                  <span class="badge <?= $condBadge ?>"><?= ucfirst($a['condition_status']) ?></span>
                </td>
                <td>
                  <?php if (!empty($a['attachment_file'])): ?>
                    <a href="<?= BASE_URL . '/' . htmlspecialchars($a['attachment_file']) ?>" target="_blank" class="btn btn-sm btn-outline-info p-1 py-0" title="View Attachment / Invoice">
                      <i class="bi bi-paperclip"></i>
                    </a>
                  <?php else: ?>
                    <span class="text-muted small">-</span>
                  <?php endif; ?>
                </td>
                <td class="text-center no-print text-nowrap">
                  <button type="button" class="btn btn-sm btn-outline-primary me-1" title="Edit Asset" onclick='openEditAssetModal(<?= json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                    <i class="bi bi-pencil"></i>
                  </button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Remove asset record: <?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['asset_code']) ?>)?');">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete_asset">
                    <input type="hidden" name="asset_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Asset"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Record / Edit Asset -->
<div class="modal fade" id="addAssetModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data" id="assetForm">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_asset">
        <input type="hidden" name="asset_id" id="ast_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="astModalTitle"><i class="bi bi-plus-circle me-2 text-primary"></i>Register Company Asset</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Asset Tag / Code <span class="text-danger">*</span></label>
              <input type="text" name="asset_code" id="ast_code" class="form-control font-monospace" value="<?= htmlspecialchars($nextCode) ?>" required>
            </div>
            <div class="col-md-8">
              <label class="form-label small fw-semibold">Asset Name / Model <span class="text-danger">*</span></label>
              <input type="text" name="name" id="ast_name" class="form-control" placeholder="e.g. Apple MacBook Pro 16 M3 / Dell PowerEdge Server" required>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Asset Category <span class="text-danger">*</span></label>
              <select name="category_id" id="ast_category_id" class="form-select" required>
                <option value="">-- Select Category --</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Purchase Date <span class="text-danger">*</span></label>
              <input type="date" name="purchase_date" id="ast_purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Purchase Cost (₹) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="purchase_cost" id="ast_purchase_cost" class="form-control fw-bold" placeholder="0.00" required oninput="if(!document.getElementById('ast_current_value').value) document.getElementById('ast_current_value').value=this.value;">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Current Book / Est. Value (₹)</label>
              <input type="number" step="0.01" name="current_value" id="ast_current_value" class="form-control" placeholder="Optional depreciated value">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Vendor / Store / Supplier</label>
              <input type="text" name="vendor_name" id="ast_vendor_name" class="form-control" placeholder="e.g. Apple Store, Dell Technologies, Amazon">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Purchase Invoice / Bill No</label>
              <input type="text" name="invoice_no" id="ast_invoice_no" class="form-control" placeholder="e.g. INV-2026-9901">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Hardware Serial / IMEI / License Key</label>
              <input type="text" name="serial_no" id="ast_serial_no" class="form-control" placeholder="e.g. C02XYZ123456">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Warranty Expiry Date</label>
              <input type="date" name="warranty_expiry" id="ast_warranty_expiry" class="form-control">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Paid From Company Account</label>
              <select name="account_id" id="ast_account_id" class="form-select">
                <option value="0">-- Direct / Not Linked --</option>
                <?php foreach (getCompanyAccounts('active') as $ca): ?>
                <option value="<?= $ca['id'] ?>">
                  <?= htmlspecialchars($ca['account_name']) ?> (<?= strtoupper($ca['account_type']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Assigned Employee (Custodian)</label>
              <select name="assigned_to_employee_id" id="ast_assigned_employee" class="form-select">
                <option value="0">-- Not Assigned / Shared Office Asset --</option>
                <?php foreach ($employees as $emp): ?>
                  <option value="<?= $emp['id'] ?>">
                    <?= htmlspecialchars($emp['name']) ?> (<?= htmlspecialchars($emp['emp_number']) ?> - <?= htmlspecialchars($emp['designation']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label small fw-semibold">Physical Location / Office</label>
              <input type="text" name="location" id="ast_location" class="form-control" placeholder="e.g. Head Office, Server Room, Remote">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Asset Status</label>
              <select name="status" id="ast_status" class="form-select">
                <option value="active">Active</option>
                <option value="in_use">In Use</option>
                <option value="maintenance">Under Maintenance</option>
                <option value="damaged">Damaged / Defective</option>
                <option value="disposed">Disposed / Written Off</option>
                <option value="sold">Sold</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Physical Condition</label>
              <select name="condition_status" id="ast_condition" class="form-select">
                <option value="excellent">Excellent (Like New)</option>
                <option value="good" selected>Good</option>
                <option value="fair">Fair (Normal Wear)</option>
                <option value="poor">Poor (Needs Repair)</option>
              </select>
            </div>

            <div class="col-md-12">
              <label class="form-label small fw-semibold">Invoice / Warranty / Photo Attachment</label>
              <input type="file" name="attachment_file" class="form-control" accept="image/*,.pdf">
            </div>

            <div class="col-12">
              <label class="form-label small fw-semibold">Asset Notes &amp; Specifications</label>
              <textarea name="notes" id="ast_notes" class="form-control" rows="2" placeholder="RAM, Storage, GPU specs, warranty terms, or repair notes..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Save Asset</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Manage Asset Categories -->
<div class="modal fade" id="manageAssetCategoriesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-tags me-2 text-primary"></i>Manage Asset Categories</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- Add Category Form -->
        <form method="POST" class="row g-2 align-items-end mb-4 bg-light p-3 rounded border">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="save_asset_category">
          <div class="col-md-5">
            <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Lab Equipment" required>
          </div>
          <div class="col-md-5">
            <label class="form-label small fw-semibold">Description</label>
            <input type="text" name="description" class="form-control form-control-sm" placeholder="Optional description">
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-plus me-1"></i>Add</button>
          </div>
        </form>

        <!-- Categories Table -->
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Category Name</th>
                <th>Description</th>
                <th>Total Assets</th>
                <th>Total Value</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($categories as $cat): ?>
                <tr>
                  <td class="fw-bold text-dark"><?= htmlspecialchars($cat['name']) ?></td>
                  <td class="small text-muted"><?= htmlspecialchars($cat['description'] ?: '-') ?></td>
                  <td class="small font-monospace"><?= number_format($cat['asset_count']) ?> assets</td>
                  <td class="fw-semibold text-primary">₹<?= number_format($cat['total_cost'], 2) ?></td>
                  <td class="text-center">
                    <?php if ($cat['asset_count'] == 0): ?>
                      <form method="POST" class="d-inline" onsubmit="return confirm('Delete category: <?= htmlspecialchars($cat['name']) ?>?');">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete_asset_category">
                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger p-1 py-0"><i class="bi bi-trash"></i></button>
                      </form>
                    <?php else: ?>
                      <span class="badge bg-light text-muted border">In Use</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function resetAssetModal() {
  document.getElementById('assetForm').reset();
  document.getElementById('ast_id').value = 0;
  document.getElementById('ast_code').value = '<?= htmlspecialchars($nextCode) ?>';
  document.getElementById('ast_purchase_date').value = '<?= date('Y-m-d') ?>';
  document.getElementById('ast_status').value = 'active';
  document.getElementById('ast_condition').value = 'good';
  document.getElementById('astModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2 text-primary"></i>Register Company Asset';
}

function openEditAssetModal(a) {
  document.getElementById('ast_id').value = a.id;
  document.getElementById('ast_code').value = a.asset_code;
  document.getElementById('ast_name').value = a.name;
  document.getElementById('ast_category_id').value = a.category_id || '';
  document.getElementById('ast_purchase_date').value = a.purchase_date;
  document.getElementById('ast_purchase_cost').value = a.purchase_cost;
  document.getElementById('ast_current_value').value = a.current_value || a.purchase_cost;
  document.getElementById('ast_vendor_name').value = a.vendor_name || '';
  document.getElementById('ast_invoice_no').value = a.invoice_no || '';
  document.getElementById('ast_serial_no').value = a.serial_no || '';
  document.getElementById('ast_warranty_expiry').value = a.warranty_expiry || '';
  document.getElementById('ast_account_id').value = a.account_id || 0;
  document.getElementById('ast_assigned_employee').value = a.assigned_to_employee_id || 0;
  document.getElementById('ast_location').value = a.location || '';
  document.getElementById('ast_status').value = a.status || 'active';
  document.getElementById('ast_condition').value = a.condition_status || 'good';
  document.getElementById('ast_notes').value = a.notes || '';

  document.getElementById('astModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2 text-primary"></i>Edit Company Asset';
  const modal = new bootstrap.Modal(document.getElementById('addAssetModal'));
  modal.show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
