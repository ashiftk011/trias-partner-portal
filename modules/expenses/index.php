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

    // 1. ADD / EDIT CATEGORY
    if ($action === 'save_category') {
        $catId    = (int)($_POST['category_id'] ?? 0);
        $catName  = trim($_POST['name'] ?? '');
        $catDesc  = trim($_POST['description'] ?? '');
        $catColor = trim($_POST['color_code'] ?? '#0d6efd');

        if (!$catName) {
            setFlash('danger', 'Category name is required.');
            redirect(BASE_URL . '/modules/expenses/index.php');
        }

        if ($catId > 0) {
            $db->prepare("UPDATE expense_categories SET name=?, description=?, color_code=? WHERE id=?")
               ->execute([$catName, $catDesc, $catColor, $catId]);
            setFlash('success', 'Expense category updated successfully.');
        } else {
            $db->prepare("INSERT INTO expense_categories (name, description, color_code) VALUES (?,?,?) ON DUPLICATE KEY UPDATE description=?, color_code=?")
               ->execute([$catName, $catDesc, $catColor, $catDesc, $catColor]);
            setFlash('success', 'Expense category created successfully.');
        }
        redirect(BASE_URL . '/modules/expenses/index.php');
    }

    // 2. DELETE CATEGORY
    if ($action === 'delete_category') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $chk = $db->prepare("SELECT COUNT(*) FROM expenses WHERE category_id=?");
        $chk->execute([$catId]);
        if ($chk->fetchColumn() > 0) {
            setFlash('danger', 'Cannot delete category that contains recorded expenses.');
        } else {
            $db->prepare("DELETE FROM expense_categories WHERE id=?")->execute([$catId]);
            setFlash('success', 'Category deleted successfully.');
        }
        redirect(BASE_URL . '/modules/expenses/index.php');
    }

    // 3. SAVE / EDIT EXPENSE
    if ($action === 'save_expense') {
        $expId          = (int)($_POST['expense_id'] ?? 0);
        $catId          = (int)($_POST['category_id'] ?? 0);
        $title          = trim($_POST['title'] ?? '');
        $vendor         = trim($_POST['vendor_name'] ?? '');
        $amount         = (float)($_POST['amount'] ?? 0);
        $allocationType = trim($_POST['allocation_type'] ?? 'general');
        $clientIds      = $_POST['client_ids'] ?? [];
        $projectIds     = $_POST['project_ids'] ?? [];
        $expDate        = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $payMode        = trim($_POST['payment_mode'] ?? 'bank_transfer');
        $accountId      = (int)($_POST['account_id'] ?? 0);
        $refNo          = trim($_POST['reference_no'] ?? '');
        $notes          = trim($_POST['description'] ?? '');

        if (!$catId || !$title || $amount <= 0 || !$expDate) {
            setFlash('danger', 'Category, Title, Amount, and Expense Date are required.');
            redirect(BASE_URL . '/modules/expenses/index.php');
        }

        // Receipt Upload
        $receiptPath = null;
        if (!empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
            $allowedExts = ['jpg','jpeg','png','pdf','webp','doc','docx'];
            $ext = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExts)) {
                $uploadDir = __DIR__ . '/../../uploads/receipts/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $filename = 'receipt_' . time() . '_' . rand(100, 999) . '.' . $ext;
                move_uploaded_file($_FILES['receipt_file']['tmp_name'], $uploadDir . $filename);
                $receiptPath = 'uploads/receipts/' . $filename;
            }
        }

        // Fetch Category Name for Ledger
        $catStmt = $db->prepare("SELECT name FROM expense_categories WHERE id=?");
        $catStmt->execute([$catId]);
        $catName = $catStmt->fetchColumn() ?: 'General Expense';

        if ($expId > 0) {
            $sql = "UPDATE expenses SET category_id=?, title=?, vendor_name=?, amount=?, expense_date=?, payment_mode=?, account_id=?, reference_no=?, description=?";
            $params = [$catId, $title, $vendor, $amount, $expDate, $payMode, $accountId ?: null, $refNo, $notes];
            if ($receiptPath) {
                $sql .= ", receipt_file=?";
                $params[] = $receiptPath;
            }
            $sql .= " WHERE id=?";
            $params[] = $expId;
            $db->prepare($sql)->execute($params);
            $savedExpId = $expId;
            setFlash('success', 'Expense record updated successfully.');
        } else {
            $stmtIns = $db->prepare("INSERT INTO expenses (category_id, title, vendor_name, amount, allocation_type, expense_date, payment_mode, account_id, reference_no, description, receipt_file, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmtIns->execute([$catId, $title, $vendor, $amount, $allocationType, $expDate, $payMode, $accountId ?: null, $refNo, $notes, $receiptPath, currentUser()['id']]);
            $savedExpId = (int)$db->lastInsertId();
            setFlash('success', 'Expense recorded successfully.');
        }

        // Save Client & Project Equal Split Allocations
        saveExpenseAllocations($savedExpId, $amount, $allocationType, is_array($clientIds) ? $clientIds : [], is_array($projectIds) ? $projectIds : []);

        // Sync with Company Account Ledger
        syncReferenceTransaction(
            $accountId ?: null,
            'expense',
            $savedExpId,
            $expDate,
            'expense',
            'credit', // Credit asset (Company Account Out)
            $amount,
            "Expense: {$title} ({$catName})",
            $notes,
            currentUser()['id']
        );

        redirect(BASE_URL . '/modules/expenses/index.php');
    }

    // 4. DELETE EXPENSE
    if ($action === 'delete_expense') {
        $expId = (int)($_POST['expense_id'] ?? 0);
        if ($expId) {
            deleteReferenceTransactions('expense', $expId);
            $db->prepare("DELETE FROM expenses WHERE id=?")->execute([$expId]);
            setFlash('success', 'Expense record deleted.');
        }
        redirect(BASE_URL . '/modules/expenses/index.php');
    }
}

// Fetch categories
$categories = $db->query("SELECT ec.*, COUNT(e.id) as expense_count, COALESCE(SUM(e.amount),0) as total_spent FROM expense_categories ec LEFT JOIN expenses e ON e.category_id=ec.id GROUP BY ec.id ORDER BY ec.name")->fetchAll();

// Fetch clients & projects for dropdowns and filters
$allClients = $db->query("SELECT c.id, c.name, c.company, c.client_code, p.name as project_name FROM clients c LEFT JOIN projects p ON p.id = c.project_id ORDER BY c.name ASC")->fetchAll();
$allProjects = $db->query("SELECT id, name FROM projects ORDER BY name ASC")->fetchAll();

restorePageFilters('expenses', ['category_id', 'allocation_type', 'client_id', 'project_id', 'from_date', 'to_date', 'payment_mode', 'q']);

// Filters for Expenses list
$filterCategory   = (int)($_GET['category_id'] ?? 0);
$filterAllocation = trim($_GET['allocation_type'] ?? '');
$filterClient     = (int)($_GET['client_id'] ?? 0);
$filterProject    = (int)($_GET['project_id'] ?? 0);
$filterFrom       = trim($_GET['from_date'] ?? '');
$filterTo         = trim($_GET['to_date'] ?? '');
$filterMode       = trim($_GET['payment_mode'] ?? '');
$search           = trim($_GET['q'] ?? '');

$sql = "SELECT e.*, ec.name as category_name, ec.color_code as category_color, u.name as created_by_name,
               ca.account_name, ca.account_type
        FROM expenses e
        JOIN expense_categories ec ON ec.id = e.category_id
        LEFT JOIN company_accounts ca ON ca.id = e.account_id
        LEFT JOIN users u ON u.id = e.created_by
        WHERE 1=1";
$params = [];

if ($filterCategory) {
    $sql .= " AND e.category_id = ?";
    $params[] = $filterCategory;
}
if ($filterAllocation) {
    if ($filterAllocation === 'general') {
        $sql .= " AND e.allocation_type = 'general'";
    } elseif ($filterAllocation === 'client') {
        $sql .= " AND (e.allocation_type IN ('client', 'both') OR e.id IN (SELECT expense_id FROM expense_clients))";
    } elseif ($filterAllocation === 'project') {
        $sql .= " AND (e.allocation_type IN ('project', 'both') OR e.id IN (SELECT expense_id FROM expense_projects))";
    }
}
if ($filterClient) {
    $sql .= " AND e.id IN (SELECT expense_id FROM expense_clients WHERE client_id = ?)";
    $params[] = $filterClient;
}
if ($filterProject) {
    $sql .= " AND (e.id IN (SELECT expense_id FROM expense_projects WHERE project_id = ?) OR e.id IN (SELECT ec2.expense_id FROM expense_clients ec2 JOIN clients cl ON cl.id = ec2.client_id WHERE cl.project_id = ?))";
    $params[] = $filterProject;
    $params[] = $filterProject;
}
if ($filterFrom) {
    $sql .= " AND e.expense_date >= ?";
    $params[] = $filterFrom;
}
if ($filterTo) {
    $sql .= " AND e.expense_date <= ?";
    $params[] = $filterTo;
}
if ($filterMode) {
    $sql .= " AND e.payment_mode = ?";
    $params[] = $filterMode;
}
if ($search) {
    $sql .= " AND (e.title LIKE ? OR e.vendor_name LIKE ? OR e.reference_no LIKE ? OR ec.name LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}

$sql .= " ORDER BY e.expense_date DESC, e.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch batch allocations for all expenses listed
$expenseIds = array_column($expenses, 'id');
$allocationsMap = getBatchExpenseAllocations($expenseIds);

// KPI Calculations
$totalExpenseAmount = 0.00;
$totalClientAllocated = 0.00;
$totalProjectAllocated = 0.00;
$totalGeneralOverhead = 0.00;

foreach ($expenses as $ex) {
    $amt = (float)$ex['amount'];
    $totalExpenseAmount += $amt;
    $alloc = $allocationsMap[$ex['id']] ?? null;

    if (!empty($alloc['clients']) && !empty($alloc['projects'])) {
        $totalClientAllocated += $amt / 2;
        $totalProjectAllocated += $amt / 2;
    } elseif (!empty($alloc['clients'])) {
        $totalClientAllocated += $amt;
    } elseif (!empty($alloc['projects'])) {
        $totalProjectAllocated += $amt;
    } else {
        $totalGeneralOverhead += $amt;
    }
}
$totalCount = count($expenses);

$pageTitle = 'Company Expenses';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Header & Nav Tabs -->
<div class="page-header d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h4 class="mb-0"><i class="bi bi-credit-card-2-front me-2 text-primary"></i>Company Expense Management</h4>
    <p class="text-muted small mb-0">Record and monitor client-wise, project-wise, and general overhead expenditures with equal split allocation</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/modules/expenses/reports.php" class="btn btn-outline-info">
      <i class="bi bi-graph-up-arrow me-1"></i>Client &amp; Project P&amp;L
    </a>
    <a href="<?= BASE_URL ?>/modules/expenses/assets.php" class="btn btn-outline-secondary">
      <i class="bi bi-box-seam me-1"></i>Company Assets
    </a>
    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#manageCategoriesModal">
      <i class="bi bi-tags me-1"></i>Categories
    </button>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal" onclick="resetExpenseModal()">
      <i class="bi bi-plus-circle me-1"></i>Record Expense
    </button>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Sub Navigation Tabs -->
<ul class="nav nav-pills mb-4 gap-2 bg-white p-2 rounded-3 shadow-sm border">
  <li class="nav-item">
    <a class="nav-link active fw-semibold" href="<?= BASE_URL ?>/modules/expenses/index.php">
      <i class="bi bi-receipt me-1"></i>Expense Register
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link text-dark fw-semibold" href="<?= BASE_URL ?>/modules/expenses/reports.php">
      <i class="bi bi-bar-chart-steps me-1"></i>Client &amp; Project Profitability
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link text-dark fw-semibold" href="<?= BASE_URL ?>/modules/expenses/assets.php">
      <i class="bi bi-laptop me-1"></i>Company Assets (Separate)
    </a>
  </li>
</ul>

<!-- Stat Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card h-100">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-danger bg-opacity-10 text-danger p-3">
          <i class="bi bi-wallet2 fs-3"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-danger">₹<?= number_format($totalExpenseAmount, 2) ?></div>
          <div class="text-muted small">Total Filtered Spend (<?= number_format($totalCount) ?> entries)</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card h-100">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-info bg-opacity-10 text-info p-3">
          <i class="bi bi-person-check fs-3"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-info">₹<?= number_format($totalClientAllocated, 2) ?></div>
          <div class="text-muted small">Client-Specific Cost (Servers, APIs)</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card h-100">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-primary bg-opacity-10 text-primary p-3">
          <i class="bi bi-folder2-open fs-3"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-primary">₹<?= number_format($totalProjectAllocated, 2) ?></div>
          <div class="text-muted small">Project-Specific Costs</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card h-100">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-secondary bg-opacity-10 text-secondary p-3">
          <i class="bi bi-building fs-3"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-secondary">₹<?= number_format($totalGeneralOverhead, 2) ?></div>
          <div class="text-muted small">General Overhead &amp; Utilities</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-2 col-6">
        <label class="form-label small text-muted mb-1">Allocation Scope</label>
        <select name="allocation_type" class="form-select form-select-sm">
          <option value="">All Scopes</option>
          <option value="general" <?= $filterAllocation==='general'?'selected':'' ?>>General Overhead</option>
          <option value="client" <?= $filterAllocation==='client'?'selected':'' ?>>Client Specific</option>
          <option value="project" <?= $filterAllocation==='project'?'selected':'' ?>>Project Specific</option>
        </select>
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small text-muted mb-1">Filter Client</label>
        <select name="client_id" class="form-select form-select-sm">
          <option value="0">All Clients</option>
          <?php foreach ($allClients as $cl): ?>
            <option value="<?= $cl['id'] ?>" <?= $filterClient===$cl['id']?'selected':'' ?>>
              <?= htmlspecialchars($cl['name']) ?><?= $cl['company'] ? ' (' . htmlspecialchars($cl['company']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small text-muted mb-1">Filter Project</label>
        <select name="project_id" class="form-select form-select-sm">
          <option value="0">All Projects</option>
          <?php foreach ($allProjects as $pr): ?>
            <option value="<?= $pr['id'] ?>" <?= $filterProject===$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small text-muted mb-1">Category</label>
        <select name="category_id" class="form-select form-select-sm">
          <option value="0">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $filterCategory===$cat['id']?'selected':'' ?>><?= htmlspecialchars($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small text-muted mb-1">From Date</label>
        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterFrom) ?>">
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small text-muted mb-1">To Date</label>
        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterTo) ?>">
      </div>
      <div class="col-md-3 col-6">
        <label class="form-label small text-muted mb-1">Payment Mode</label>
        <select name="payment_mode" class="form-select form-select-sm">
          <option value="">All Modes</option>
          <?php foreach (['bank_transfer'=>'Bank Transfer','cash'=>'Cash','upi'=>'UPI','cheque'=>'Cheque','card'=>'Card','other'=>'Other'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= $filterMode===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-9 col-12">
        <label class="form-label small text-muted mb-1">Search Keywords</label>
        <div class="input-group input-group-sm">
          <input type="text" name="q" class="form-control" placeholder="Search title, vendor, ref no..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit" class="btn btn-primary px-3"><i class="bi bi-search me-1"></i>Filter</button>
          <?php if ($filterCategory || $filterAllocation || $filterClient || $filterProject || $filterFrom || $filterTo || $filterMode || $search): ?>
            <a href="<?= BASE_URL ?>/modules/expenses/index.php?clear=1" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-circle me-1"></i>Clear</a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Expenses Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Date</th>
            <th>Expense Item / Purpose</th>
            <th>Category</th>
            <th>Allocation &amp; Split</th>
            <th class="text-end">Total Amount</th>
            <th>Paid Via / Account</th>
            <th>Ref #</th>
            <th>Receipt</th>
            <th class="text-center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($expenses)): ?>
            <tr>
              <td colspan="10" class="text-center text-muted py-4">No expense records found.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($expenses as $idx => $ex): 
              $alloc = $allocationsMap[$ex['id']] ?? ['clients'=>[],'projects'=>[],'summary'=>'General / Overhead'];
              $hasCl = !empty($alloc['clients']);
              $hasPr = !empty($alloc['projects']);
            ?>
              <tr>
                <td><?= $idx + 1 ?></td>
                <td class="fw-semibold text-nowrap"><?= date('d M Y', strtotime($ex['expense_date'])) ?></td>
                <td>
                  <div class="fw-bold text-dark"><?= htmlspecialchars($ex['title']) ?></div>
                  <?php if (!empty($ex['vendor_name'])): ?>
                    <small class="text-muted d-block"><i class="bi bi-shop me-1"></i><?= htmlspecialchars($ex['vendor_name']) ?></small>
                  <?php endif; ?>
                  <?php if (!empty($ex['description'])): ?>
                    <small class="text-muted text-truncate d-block" style="max-width:240px;"><?= htmlspecialchars($ex['description']) ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge border" style="background-color: <?= htmlspecialchars($ex['category_color'] ?: '#0d6efd') ?>15; color: <?= htmlspecialchars($ex['category_color'] ?: '#0d6efd') ?>; border-color: <?= htmlspecialchars($ex['category_color'] ?: '#0d6efd') ?>40 !important;">
                    <?= htmlspecialchars($ex['category_name']) ?>
                  </span>
                </td>
                <td>
                  <?php if ($hasCl && $hasPr): ?>
                    <div class="d-flex flex-column gap-1">
                      <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25" title="<?= htmlspecialchars($alloc['summary']) ?>">
                        <i class="bi bi-people-fill me-1"></i><?= count($alloc['clients']) ?> Clients (₹<?= number_format($alloc['clients'][0]['split_amount'] ?? 0, 2) ?> ea)
                      </span>
                      <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
                        <i class="bi bi-folder-fill me-1"></i><?= count($alloc['projects']) ?> Projects (₹<?= number_format($alloc['projects'][0]['split_amount'] ?? 0, 2) ?> ea)
                      </span>
                    </div>
                  <?php elseif ($hasCl): ?>
                    <div>
                      <span class="badge bg-info bg-opacity-15 text-info-emphasis border border-info border-opacity-50" title="Split equally across selected clients">
                        <i class="bi bi-person-badge-fill me-1"></i><?= count($alloc['clients']) ?> Client<?= count($alloc['clients']) > 1 ? 's' : '' ?>: ₹<?= number_format($alloc['clients'][0]['split_amount'] ?? 0, 2) ?> each
                      </span>
                      <div class="small text-muted text-truncate mt-1" style="max-width: 220px;" title="<?= htmlspecialchars(implode(', ', array_map(fn($c)=>$c['client_company'] ?: $c['client_name'], $alloc['clients']))) ?>">
                        <?= htmlspecialchars(implode(', ', array_map(fn($c)=>$c['client_company'] ?: $c['client_name'], array_slice($alloc['clients'], 0, 2)))) ?><?= count($alloc['clients']) > 2 ? ' +' . (count($alloc['clients']) - 2) . ' more' : '' ?>
                      </div>
                    </div>
                  <?php elseif ($hasPr): ?>
                    <div>
                      <span class="badge bg-primary bg-opacity-15 text-primary-emphasis border border-primary border-opacity-50" title="Split equally across selected projects">
                        <i class="bi bi-folder2 me-1"></i><?= count($alloc['projects']) ?> Project<?= count($alloc['projects']) > 1 ? 's' : '' ?>: ₹<?= number_format($alloc['projects'][0]['split_amount'] ?? 0, 2) ?> each
                      </span>
                      <div class="small text-muted text-truncate mt-1" style="max-width: 220px;" title="<?= htmlspecialchars(implode(', ', array_map(fn($p)=>$p['project_name'], $alloc['projects']))) ?>">
                        <?= htmlspecialchars(implode(', ', array_map(fn($p)=>$p['project_name'], $alloc['projects']))) ?>
                      </div>
                    </div>
                  <?php else: ?>
                    <span class="badge bg-light text-secondary border">
                      <i class="bi bi-building me-1"></i>General Overhead
                    </span>
                  <?php endif; ?>
                </td>
                <td class="fw-bold text-danger text-end fs-6">₹<?= number_format($ex['amount'], 2) ?></td>
                <td>
                  <span class="badge bg-light text-dark border"><?= strtoupper(str_replace('_',' ',$ex['payment_mode'])) ?></span>
                  <?php if (!empty($ex['account_name'])): ?>
                    <div class="small text-muted text-truncate mt-1" style="max-width:140px;">
                      <i class="bi bi-bank me-1"></i><?= htmlspecialchars($ex['account_name']) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td><code class="text-dark"><?= htmlspecialchars($ex['reference_no'] ?: '-') ?></code></td>
                <td>
                  <?php if (!empty($ex['receipt_file'])): ?>
                    <a href="<?= BASE_URL . '/' . htmlspecialchars($ex['receipt_file']) ?>" target="_blank" class="btn btn-sm btn-outline-info p-1 py-0" title="View Uploaded Receipt">
                      <i class="bi bi-paperclip me-1"></i>Receipt
                    </a>
                  <?php else: ?>
                    <span class="text-muted small">-</span>
                  <?php endif; ?>
                </td>
                <td class="text-center text-nowrap">
                  <button type="button" class="btn btn-sm btn-outline-primary me-1" title="Edit Expense" onclick='openEditExpenseModal(<?= json_encode($ex, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($alloc, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                    <i class="bi bi-pencil"></i>
                  </button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Delete expense record: <?= htmlspecialchars($ex['title']) ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete_expense">
                    <input type="hidden" name="expense_id" value="<?= $ex['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Expense"><i class="bi bi-trash"></i></button>
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

<!-- Modal: Record / Edit Expense -->
<div class="modal fade" id="addExpenseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data" id="expenseForm">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_expense">
        <input type="hidden" name="expense_id" id="exp_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="expModalTitle"><i class="bi bi-plus-circle me-2 text-primary"></i>Record Company Expense</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Expense Title / Item <span class="text-danger">*</span></label>
              <input type="text" name="title" id="exp_title" class="form-control" placeholder="e.g. AWS Cloud Server / Office Rent / Figma" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Vendor / Payee Name</label>
              <input type="text" name="vendor_name" id="exp_vendor" class="form-control" placeholder="e.g. Amazon Web Services / DigitalOcean / Landlord">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Expense Category <span class="text-danger">*</span></label>
              <select name="category_id" id="exp_category_id" class="form-select" required>
                <option value="">-- Select Category --</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Total Amount (₹) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="amount" id="exp_amount" class="form-control fw-bold text-danger" placeholder="0.00" required oninput="calculateSplitPreview()">
            </div>

            <!-- Allocation Target (Client / Project / General) -->
            <div class="col-12">
              <div class="bg-light p-3 rounded-3 border">
                <label class="form-label small fw-bold text-dark mb-2">
                  <i class="bi bi-diagram-3-fill me-1 text-primary"></i>Expense Allocation &amp; Cost Center:
                </label>
                <div class="d-flex flex-wrap gap-3 mb-3">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="allocation_type" id="alloc_general" value="general" checked onchange="toggleAllocationFields()">
                    <label class="form-check-label small fw-semibold" for="alloc_general">
                      General Overhead (Other Expense)
                    </label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="allocation_type" id="alloc_client" value="client" onchange="toggleAllocationFields()">
                    <label class="form-check-label small fw-semibold" for="alloc_client">
                      Specific Client(s) <span class="badge bg-info-subtle text-info border ms-1">Equal Split</span>
                    </label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="allocation_type" id="alloc_project" value="project" onchange="toggleAllocationFields()">
                    <label class="form-check-label small fw-semibold" for="alloc_project">
                      Specific Project(s) <span class="badge bg-primary-subtle text-primary border ms-1">Equal Split</span>
                    </label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="allocation_type" id="alloc_both" value="both" onchange="toggleAllocationFields()">
                    <label class="form-check-label small fw-semibold" for="alloc_both">
                      Both Clients &amp; Projects
                    </label>
                  </div>
                </div>

                <!-- Client Multi-Select Box -->
                <div id="clientSelectWrapper" class="mb-3" style="display: none;">
                  <label class="form-label small fw-semibold">Select Target Client(s) <span class="text-danger">*</span></label>
                  <select name="client_ids[]" id="exp_client_ids" class="form-select select2" multiple data-placeholder="Choose 1 or more clients..." onchange="calculateSplitPreview()">
                    <?php foreach ($allClients as $cl): ?>
                      <option value="<?= $cl['id'] ?>">
                        <?= htmlspecialchars($cl['name']) ?><?= $cl['company'] ? ' — ' . htmlspecialchars($cl['company']) : '' ?><?= $cl['project_name'] ? ' (' . htmlspecialchars($cl['project_name']) . ')' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted d-block mt-1">
                    <i class="bi bi-info-circle me-1"></i>If a server or license is shared, select all applicable clients. Cost splits equally among them for client P&amp;L calculation.
                  </small>
                </div>

                <!-- Project Multi-Select Box -->
                <div id="projectSelectWrapper" class="mb-2" style="display: none;">
                  <label class="form-label small fw-semibold">Select Target Project(s) <span class="text-danger">*</span></label>
                  <select name="project_ids[]" id="exp_project_ids" class="form-select select2" multiple data-placeholder="Choose 1 or more projects..." onchange="calculateSplitPreview()">
                    <?php foreach ($allProjects as $pr): ?>
                      <option value="<?= $pr['id'] ?>"><?= htmlspecialchars($pr['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted d-block mt-1">
                    <i class="bi bi-info-circle me-1"></i>Cost will be split equally across selected projects for project profitability reporting.
                  </small>
                </div>

                <!-- Live Equal Split Calculation Notice -->
                <div id="splitPreviewAlert" class="alert alert-info py-2 px-3 small mb-0 mt-2" style="display: none;">
                  <i class="bi bi-calculator me-1"></i><span id="splitPreviewText"></span>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Expense Date <span class="text-danger">*</span></label>
              <input type="date" name="expense_date" id="exp_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Payment Mode</label>
              <select name="payment_mode" id="exp_payment_mode" class="form-select">
                <option value="bank_transfer">Direct Bank Transfer</option>
                <option value="upi">UPI / GPay / PhonePe</option>
                <option value="card">Corporate Credit/Debit Card</option>
                <option value="cheque">Cheque</option>
                <option value="cash">Cash</option>
                <option value="other">Other</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Paid From Company Account</label>
              <select name="account_id" id="exp_account_id" class="form-select">
                <option value="0">-- Direct / Default Account --</option>
                <?php foreach (getCompanyAccounts('active') as $ca): ?>
                <option value="<?= $ca['id'] ?>">
                  <?= htmlspecialchars($ca['account_name']) ?> (<?= strtoupper($ca['account_type']) ?>) — Current Bal: ₹<?= number_format($ca['current_balance'], 2) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Transaction Reference / Receipt No</label>
              <input type="text" name="reference_no" id="exp_ref" class="form-control" placeholder="e.g. UTR-123456 / INV-9988">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Receipt Attachment (PDF / Image)</label>
              <input type="file" name="receipt_file" class="form-control" accept="image/*,.pdf">
            </div>

            <div class="col-12">
              <label class="form-label small fw-semibold">Description / Notes</label>
              <textarea name="description" id="exp_desc" class="form-control" rows="2" placeholder="Optional details, server specifications, renewal period..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Save Expense Record</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Manage Expense Categories -->
<div class="modal fade" id="manageCategoriesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-tags me-2 text-primary"></i>Manage Expense Categories</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- Create Category Form -->
        <form method="POST" class="row g-2 align-items-end mb-4 bg-light p-3 rounded border">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="save_category">
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Category Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Cloud Hosting &amp; Servers" required>
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Description</label>
            <input type="text" name="description" class="form-control form-control-sm" placeholder="Optional category details">
          </div>
          <div class="col-md-2">
            <label class="form-label small fw-semibold">Badge Color</label>
            <input type="color" name="color_code" class="form-control form-control-sm form-control-color w-100" value="#0d6efd" title="Choose Category Badge Color">
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
                <th>Recorded Expenses</th>
                <th>Total Spend</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($categories as $cat): ?>
                <tr>
                  <td class="fw-bold text-dark">
                    <span class="badge me-1" style="background-color: <?= htmlspecialchars($cat['color_code'] ?: '#0d6efd') ?>; color: #fff;">
                      <?= htmlspecialchars($cat['name']) ?>
                    </span>
                  </td>
                  <td class="small text-muted"><?= htmlspecialchars($cat['description'] ?: '-') ?></td>
                  <td class="small font-monospace"><?= number_format($cat['expense_count']) ?> entries</td>
                  <td class="fw-semibold text-danger">₹<?= number_format($cat['total_spent'], 2) ?></td>
                  <td class="text-center">
                    <?php if ($cat['expense_count'] == 0): ?>
                      <form method="POST" class="d-inline" onsubmit="return confirm('Delete category: <?= htmlspecialchars($cat['name']) ?>?');">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete_category">
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
function toggleAllocationFields() {
  const isClient  = document.getElementById('alloc_client').checked;
  const isProject = document.getElementById('alloc_project').checked;
  const isBoth    = document.getElementById('alloc_both').checked;

  document.getElementById('clientSelectWrapper').style.display  = (isClient || isBoth) ? 'block' : 'none';
  document.getElementById('projectSelectWrapper').style.display = (isProject || isBoth) ? 'block' : 'none';

  calculateSplitPreview();
}

function calculateSplitPreview() {
  const amt = parseFloat(document.getElementById('exp_amount').value) || 0;
  const isClient  = document.getElementById('alloc_client').checked;
  const isProject = document.getElementById('alloc_project').checked;
  const isBoth    = document.getElementById('alloc_both').checked;

  const clientSelect = $('#exp_client_ids');
  const projectSelect = $('#exp_project_ids');
  const selClients = clientSelect.val() || [];
  const selProjects = projectSelect.val() || [];

  const previewBox = document.getElementById('splitPreviewAlert');
  const previewText = document.getElementById('splitPreviewText');

  if (amt <= 0) {
    previewBox.style.display = 'none';
    return;
  }

  let messages = [];

  if (isClient || isBoth) {
    if (selClients.length > 0) {
      const perClient = (amt / selClients.length).toFixed(2);
      messages.push(`<strong>₹${amt.toFixed(2)}</strong> split equally among <strong>${selClients.length} clients</strong> = <strong>₹${perClient}</strong> allocated per client.`);
    } else {
      messages.push('Please select at least one client.');
    }
  }

  if (isProject || isBoth) {
    if (selProjects.length > 0) {
      const perProj = (amt / selProjects.length).toFixed(2);
      messages.push(`<strong>₹${amt.toFixed(2)}</strong> split equally among <strong>${selProjects.length} projects</strong> = <strong>₹${perProj}</strong> allocated per project.`);
    } else {
      messages.push('Please select at least one project.');
    }
  }

  if (messages.length > 0) {
    previewText.innerHTML = messages.join('<br>');
    previewBox.style.display = 'block';
  } else {
    previewBox.style.display = 'none';
  }
}

function resetExpenseModal() {
  document.getElementById('expenseForm').reset();
  document.getElementById('exp_id').value = 0;
  document.getElementById('exp_date').value = '<?= date('Y-m-d') ?>';
  document.getElementById('alloc_general').checked = true;

  $('#exp_client_ids').val([]).trigger('change');
  $('#exp_project_ids').val([]).trigger('change');
  toggleAllocationFields();

  document.getElementById('expModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2 text-primary"></i>Record Company Expense';
}

function openEditExpenseModal(ex, alloc) {
  document.getElementById('exp_id').value = ex.id;
  document.getElementById('exp_title').value = ex.title;
  document.getElementById('exp_vendor').value = ex.vendor_name || '';
  document.getElementById('exp_category_id').value = ex.category_id;
  document.getElementById('exp_amount').value = ex.amount;
  document.getElementById('exp_date').value = ex.expense_date;
  document.getElementById('exp_payment_mode').value = ex.payment_mode || 'bank_transfer';
  document.getElementById('exp_account_id').value = ex.account_id || 0;
  document.getElementById('exp_ref').value = ex.reference_no || '';
  document.getElementById('exp_desc').value = ex.description || '';

  // Set Allocation Type
  const allocType = ex.allocation_type || 'general';
  if (allocType === 'client') {
    document.getElementById('alloc_client').checked = true;
  } else if (allocType === 'project') {
    document.getElementById('alloc_project').checked = true;
  } else if (allocType === 'both') {
    document.getElementById('alloc_both').checked = true;
  } else {
    document.getElementById('alloc_general').checked = true;
  }

  toggleAllocationFields();

  // Populate Select2 selections
  if (alloc) {
    if (alloc.client_ids && alloc.client_ids.length > 0) {
      $('#exp_client_ids').val(alloc.client_ids.map(String)).trigger('change');
    } else {
      $('#exp_client_ids').val([]).trigger('change');
    }
    if (alloc.project_ids && alloc.project_ids.length > 0) {
      $('#exp_project_ids').val(alloc.project_ids.map(String)).trigger('change');
    } else {
      $('#exp_project_ids').val([]).trigger('change');
    }
  }

  calculateSplitPreview();

  document.getElementById('expModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2 text-primary"></i>Edit Expense Record';
  const modal = new bootstrap.Modal(document.getElementById('addExpenseModal'));
  modal.show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
