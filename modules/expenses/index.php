<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/accounting.php';
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
        $expId     = (int)($_POST['expense_id'] ?? 0);
        $catId     = (int)($_POST['category_id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        $vendor    = trim($_POST['vendor_name'] ?? '');
        $amount    = (float)($_POST['amount'] ?? 0);
        $expDate   = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $payMode   = trim($_POST['payment_mode'] ?? 'bank_transfer');
        $accountId = (int)($_POST['account_id'] ?? 0);
        $refNo     = trim($_POST['reference_no'] ?? '');
        $notes     = trim($_POST['description'] ?? '');

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
            $stmtIns = $db->prepare("INSERT INTO expenses (category_id, title, vendor_name, amount, expense_date, payment_mode, account_id, reference_no, description, receipt_file, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmtIns->execute([$catId, $title, $vendor, $amount, $expDate, $payMode, $accountId ?: null, $refNo, $notes, $receiptPath, currentUser()['id']]);
            $savedExpId = (int)$db->lastInsertId();
            setFlash('success', 'Expense recorded successfully.');
        }

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

// Filters for Expenses list
$filterCategory = (int)($_GET['category_id'] ?? 0);
$filterFrom     = trim($_GET['from_date'] ?? '');
$filterTo       = trim($_GET['to_date'] ?? '');
$filterMode     = trim($_GET['payment_mode'] ?? '');
$search         = trim($_GET['q'] ?? '');

$sql = "SELECT e.*, ec.name as category_name, u.name as created_by_name
        FROM expenses e
        JOIN expense_categories ec ON ec.id = e.category_id
        LEFT JOIN users u ON u.id = e.created_by
        WHERE 1=1";
$params = [];

if ($filterCategory) {
    $sql .= " AND e.category_id = ?";
    $params[] = $filterCategory;
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
    $sql .= " AND (e.title LIKE ? OR e.reference_no LIKE ? OR ec.name LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

$sql .= " ORDER BY e.expense_date DESC, e.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// KPI Calculations
$totalExpenseAmount = 0;
foreach ($expenses as $ex) {
    $totalExpenseAmount += (float)$ex['amount'];
}
$totalCount = count($expenses);

$pageTitle = 'Company Expenses';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h4 class="mb-0"><i class="bi bi-credit-card-2-front me-2 text-primary"></i>Company Expense Management</h4>
    <p class="text-muted small mb-0">Record and monitor company operational expenditures, SaaS tools, rent, and utility costs</p>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#manageCategoriesModal">
      <i class="bi bi-tags me-1"></i>Expense Categories
    </button>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
      <i class="bi bi-plus-circle me-1"></i>Record Expense
    </button>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Stat Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-danger bg-opacity-10 text-danger">
          <i class="bi bi-wallet2 fs-4"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-danger">₹<?= number_format($totalExpenseAmount, 2) ?></div>
          <div class="text-muted small">Total Recorded Expenses</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-primary bg-opacity-10 text-primary">
          <i class="bi bi-receipt fs-4"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-primary"><?= number_format($totalCount) ?></div>
          <div class="text-muted small">Expense Transactions</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-info bg-opacity-10 text-info">
          <i class="bi bi-tags fs-4"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-info"><?= count($categories) ?></div>
          <div class="text-muted small">Configured Categories</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Category</label>
        <select name="category_id" class="form-select form-select-sm">
          <option value="0">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $filterCategory===$cat['id']?'selected':'' ?>><?= htmlspecialchars($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">From Date</label>
        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterFrom) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">To Date</label>
        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterTo) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">Payment Mode</label>
        <select name="payment_mode" class="form-select form-select-sm">
          <option value="">All Modes</option>
          <?php foreach (['bank_transfer'=>'Bank Transfer','cash'=>'Cash','upi'=>'UPI','cheque'=>'Cheque','card'=>'Card','other'=>'Other'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= $filterMode===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Search</label>
        <div class="input-group input-group-sm">
          <input type="text" name="q" class="form-control" placeholder="Title, ref no..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
          <?php if ($filterCategory || $filterFrom || $filterTo || $filterMode || $search): ?>
            <a href="?" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-x-circle"></i></a>
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
            <th>Expense Title / Purpose</th>
            <th>Category</th>
            <th class="text-end">Amount</th>
            <th>Payment Mode</th>
            <th>Ref / Receipt #</th>
            <th>Receipt File</th>
            <th>Logged By</th>
            <th class="text-center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($expenses)): ?>
            <tr>
              <td colspan="10" class="text-center text-muted py-4">No expense records found.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($expenses as $idx => $ex): ?>
              <tr>
                <td><?= $idx + 1 ?></td>
                <td class="fw-semibold text-nowrap"><?= date('d M Y', strtotime($ex['expense_date'])) ?></td>
                <td>
                  <div class="fw-semibold text-dark"><?= htmlspecialchars($ex['title']) ?></div>
                  <?php if (!empty($ex['description'])): ?>
                    <small class="text-muted text-truncate d-block" style="max-width:220px;"><?= htmlspecialchars($ex['description']) ?></small>
                  <?php endif; ?>
                </td>
                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($ex['category_name']) ?></span></td>
                <td class="fw-bold text-danger text-end">₹<?= number_format($ex['amount'], 2) ?></td>
                <td><span class="badge bg-light text-muted border"><?= strtoupper(str_replace('_',' ',$ex['payment_mode'])) ?></span></td>
                <td><code class="text-dark"><?= htmlspecialchars($ex['reference_no'] ?: '-') ?></code></td>
                <td>
                  <?php if (!empty($ex['receipt_file'])): ?>
                    <a href="<?= BASE_URL . '/' . htmlspecialchars($ex['receipt_file']) ?>" target="_blank" class="btn btn-sm btn-outline-info p-1 py-0" title="View Receipt">
                      <i class="bi bi-paperclip me-1"></i>Receipt
                    </a>
                  <?php else: ?>
                    <span class="text-muted small">-</span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted"><?= htmlspecialchars($ex['created_by_name'] ?: 'System') ?></td>
                <td class="text-center text-nowrap">
                  <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick='openEditExpenseModal(<?= json_encode($ex, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                    <i class="bi bi-pencil"></i>
                  </button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Delete expense entry: <?= htmlspecialchars($ex['title']) ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete_expense">
                    <input type="hidden" name="expense_id" value="<?= $ex['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
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
      <form method="POST" enctype="multipart/form-data">
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
              <input type="text" name="title" id="exp_title" class="form-control" placeholder="e.g. AWS Cloud Server / Office Rent" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Vendor / Payee Name</label>
              <input type="text" name="vendor_name" id="exp_vendor" class="form-control" placeholder="e.g. Amazon Web Services / Landlord">
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
              <label class="form-label small fw-semibold">Amount (₹) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="amount" id="exp_amount" class="form-control" placeholder="0.00" required>
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
              <textarea name="description" id="exp_desc" class="form-control" rows="2" placeholder="Optional expense details or notes..."></textarea>
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
            <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Office Supplies" required>
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
function openEditExpenseModal(ex) {
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

  document.getElementById('expModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2 text-primary"></i>Edit Expense Record';
  const modal = new bootstrap.Modal(document.getElementById('addExpenseModal'));
  modal.show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
