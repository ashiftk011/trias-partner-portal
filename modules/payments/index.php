<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/currencies.php';
requireAccess('payments');

$db = getDB();

$fromDate    = trim($_GET['from_date'] ?? '');
$toDate      = trim($_GET['to_date'] ?? '');
$paymentMode = trim($_GET['payment_mode'] ?? '');
$search      = trim($_GET['q'] ?? '');

$sql = "SELECT py.*, c.name as client_name, c.client_code, c.company, i.invoice_no, i.total_amount as invoice_total, i.currency, u.name as recorder_name
        FROM payments py
        LEFT JOIN clients c ON c.id = py.client_id
        LEFT JOIN invoices i ON i.id = py.invoice_id
        LEFT JOIN users u ON u.id = py.created_by
        WHERE 1=1";
$params = [];

if ($fromDate) {
    $sql .= " AND py.payment_date >= ?";
    $params[] = $fromDate;
}
if ($toDate) {
    $sql .= " AND py.payment_date <= ?";
    $params[] = $toDate;
}
if ($paymentMode) {
    $sql .= " AND py.payment_mode = ?";
    $params[] = $paymentMode;
}
if ($search) {
    $sql .= " AND (i.invoice_no LIKE ? OR c.name LIKE ? OR c.company LIKE ? OR py.transaction_id LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}

$sql .= " ORDER BY py.payment_date DESC, py.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// KPI Calculations
$totalCount = count($payments);
$totalAmount = 0;
foreach ($payments as $p) {
    $totalAmount += (float)$p['amount'];
}
$avgAmount = $totalCount > 0 ? ($totalAmount / $totalCount) : 0;

$pageTitle = 'Payments Report';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
  <div>
    <h4 class="mb-0">Payments Report</h4>
    <p class="text-muted small mb-0">Track payment collections with custom date filters and CSV export</p>
  </div>
  <div>
    <?php
    $exportParams = http_build_query([
        'from_date'    => $fromDate,
        'to_date'      => $toDate,
        'payment_mode' => $paymentMode,
        'q'            => $search,
    ]);
    ?>
    <a href="<?= BASE_URL ?>/modules/payments/export.php?<?= $exportParams ?>" class="btn btn-success">
      <i class="bi bi-file-earmark-excel me-1"></i> Download CSV Report
    </a>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-success bg-opacity-10 text-success">
          <i class="bi bi-cash-stack fs-4"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-success">₹<?= number_format($totalAmount, 2) ?></div>
          <div class="text-muted small">Total Paid Amount</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-primary bg-opacity-10 text-primary">
          <i class="bi bi-receipt-cutoff fs-4"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-primary"><?= number_format($totalCount) ?></div>
          <div class="text-muted small">Total Payments Recorded</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-info bg-opacity-10 text-info">
          <i class="bi bi-calculator fs-4"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-info">₹<?= number_format($avgAmount, 2) ?></div>
          <div class="text-muted small">Average Payment Amount</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">From Date</label>
        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">To Date</label>
        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">Payment Mode</label>
        <select name="payment_mode" class="form-select form-select-sm">
          <option value="">All Modes</option>
          <?php foreach (['upi'=>'UPI','neft'=>'NEFT','rtgs'=>'RTGS','cash'=>'Cash','cheque'=>'Cheque','card'=>'Card','other'=>'Other'] as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= $paymentMode===$val?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Invoice no, client name, txn id..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-md-1 d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-primary w-100" title="Apply Filter"><i class="bi bi-filter"></i></button>
        <?php if ($fromDate || $toDate || $paymentMode || $search): ?>
        <a href="?" class="btn btn-sm btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-circle"></i></a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Payments Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Payment Date</th>
            <th>Invoice No</th>
            <th>Client Name</th>
            <th class="text-end">Invoice Total</th>
            <th class="text-end">Paid Amount</th>
            <th>Payment Mode</th>
            <th>Transaction ID / Ref</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-4">
              <i class="bi bi-inbox fs-3 d-block mb-2 text-secondary"></i>
              No payment records found for the selected criteria.
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($payments as $idx => $p): ?>
          <tr>
            <td><?= $idx + 1 ?></td>
            <td class="fw-semibold text-nowrap"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
            <td>
              <?php if (!empty($p['invoice_id'])): ?>
              <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $p['invoice_id'] ?>" class="fw-semibold text-decoration-none">
                <?= htmlspecialchars($p['invoice_no'] ?? ('#' . $p['invoice_id'])) ?>
              </a>
              <?php else: ?>
              <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($p['client_name'] ?? 'N/A') ?></div>
              <?php if (!empty($p['company'])): ?>
              <small class="text-muted"><?= htmlspecialchars($p['company']) ?></small>
              <?php endif; ?>
            </td>
            <td class="fw-semibold text-end text-nowrap">
              <?= htmlspecialchars(currencySymbol($p['currency'] ?? 'INR')) ?><?= number_format($p['invoice_total'] ?? 0, 2) ?>
            </td>
            <td class="fw-bold text-success text-end text-nowrap">
              <?= htmlspecialchars(currencySymbol($p['currency'] ?? 'INR')) ?><?= number_format($p['amount'], 2) ?>
            </td>
            <td>
              <span class="badge bg-light text-dark border me-1"><?= strtoupper(htmlspecialchars($p['payment_mode'])) ?></span>
            </td>
            <td>
              <code class="text-dark"><?= htmlspecialchars($p['transaction_id'] ?: '-') ?></code>
            </td>
            <td class="small text-muted">
              <?= htmlspecialchars($p['notes'] ?: '-') ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
