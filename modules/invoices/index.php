<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('invoices');

$db = getDB();

$filterClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['q'] ?? '');

$sql = "SELECT i.*,c.name as client_name,c.client_code,c.company,p.name as project_name
        FROM invoices i
        LEFT JOIN clients c ON c.id=i.client_id
        LEFT JOIN projects p ON p.id=c.project_id
        WHERE 1=1";
$params = [];
if ($filterClient) { $sql .= " AND i.client_id=?"; $params[] = $filterClient; }
if ($filterStatus) { $sql .= " AND i.status=?";   $params[] = $filterStatus; }
if ($filterSearch) { $sql .= " AND (i.invoice_no LIKE ? OR c.name LIKE ? OR c.company LIKE ?)";
                     $params = array_merge($params, ["%$filterSearch%","%$filterSearch%","%$filterSearch%"]); }
$sql .= " ORDER BY i.invoice_date DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$invoices = $stmt->fetchAll();

// Summary — pull totals across ALL statuses
$summary = $db->query("SELECT status, COUNT(*) as cnt, SUM(total_amount) as total, SUM(paid_amount) as paid FROM invoices GROUP BY status")->fetchAll();
$byStatus = [];
foreach ($summary as $s) $byStatus[$s['status']] = $s;

// KPI values
$totalPending = array_sum(array_map(fn($r) => $r['total'] - $r['paid'], array_filter($byStatus, fn($s) => in_array($s, ['pending','partial','overdue']), ARRAY_FILTER_USE_KEY)));
$totalCollected = array_sum(array_column($summary, 'paid'));
$overdueCount = $byStatus['overdue']['cnt'] ?? 0;
$totalInvoices = array_sum(array_column($summary, 'cnt'));

$pageTitle = 'Invoices';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
  <div><h4 class="mb-0">Invoices</h4><p class="text-muted small mb-0">Client billing & payment tracking</p></div>
  <a href="<?= BASE_URL ?>/modules/invoices/save.php" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>Create Invoice
  </a>
</div>

<?php displayFlash(); ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-warning bg-opacity-10 text-warning"><i class="bi bi-clock-history fs-4"></i></div>
        <div>
          <div class="fs-4 fw-bold text-warning">₹<?= number_format($totalPending,2) ?></div>
          <div class="text-muted small">Pending / Overdue</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-success bg-opacity-10 text-success"><i class="bi bi-cash-stack fs-4"></i></div>
        <div>
          <div class="fs-4 fw-bold text-success">₹<?= number_format($totalCollected,2) ?></div>
          <div class="text-muted small">Total Collected</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-danger bg-opacity-10 text-danger"><i class="bi bi-exclamation-triangle fs-4"></i></div>
        <div>
          <div class="fs-4 fw-bold text-danger"><?= $overdueCount ?></div>
          <div class="text-muted small">Overdue Invoices</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-primary bg-opacity-10 text-primary"><i class="bi bi-receipt fs-4"></i></div>
        <div>
          <div class="fs-4 fw-bold text-primary"><?= $totalInvoices ?></div>
          <div class="text-muted small">Total Invoices</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Status Filters -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php
  $sMap = ['pending'=>'warning','paid'=>'success','partial'=>'info','overdue'=>'danger','cancelled'=>'secondary'];
  foreach ($sMap as $s => $cls): ?>
  <a href="?status=<?= $s ?>" class="badge bg-<?= $filterStatus===$s?$cls:'light text-dark' ?> text-decoration-none p-2 fs-6">
    <?= ucfirst($s) ?> <span>(<?= $byStatus[$s]['cnt'] ?? 0 ?>)</span>
  </a>
  <?php endforeach; ?>
  <?php if ($filterStatus || $filterClient || $filterSearch): ?>
  <a href="?" class="badge bg-secondary text-decoration-none p-2 fs-6"><i class="bi bi-x-circle me-1"></i>Clear</a>
  <?php endif; ?>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-5">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search invoice no, client name..." value="<?= htmlspecialchars($filterSearch) ?>">
      </div>
      <div class="col-auto">
        <button class="btn btn-sm btn-primary">Search</button>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable align-middle">
        <thead class="table-light">
          <tr>
            <th>Invoice No</th>
            <th>Client</th>
            <th>Project</th>
            <th>Date</th>
            <th>Due</th>
            <th class="text-end">Amount</th>
            <th class="text-end">Paid</th>
            <th class="text-end">Balance</th>
            <th class="text-center">Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv): ?>
          <?php $balance = $inv['total_amount'] - $inv['paid_amount']; ?>
          <tr>
            <td class="fw-semibold"><a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="text-decoration-none"><?= htmlspecialchars($inv['invoice_no']) ?></a></td>
            <td>
              <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $inv['client_id'] ?>" class="fw-semibold text-decoration-none small">
                <?= htmlspecialchars($inv['client_name']) ?>
              </a>
              <?php if ($inv['company']): ?><div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($inv['company']) ?></div><?php endif; ?>
            </td>
            <td><span class="badge bg-primary small"><?= htmlspecialchars($inv['project_name'] ?? '') ?></span></td>
            <td class="small text-nowrap"><?= date('d M Y', strtotime($inv['invoice_date'])) ?></td>
            <td class="small text-nowrap <?= $inv['status']==='overdue'?'text-danger fw-bold':'' ?>"><?= date('d M Y', strtotime($inv['due_date'])) ?></td>
            <td class="fw-semibold text-end text-nowrap">₹<?= number_format($inv['total_amount'],2) ?></td>
            <td class="text-success fw-semibold text-end text-nowrap">₹<?= number_format($inv['paid_amount'],2) ?></td>
            <td class="<?= $balance>0?'text-danger':'text-success' ?> fw-semibold text-end text-nowrap">₹<?= number_format($balance,2) ?></td>
            <td class="text-center"><?= statusBadge($inv['status']) ?></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="btn btn-outline-info" title="View"><i class="bi bi-eye"></i></a>
                <?php if (in_array($inv['status'],['pending','partial','overdue'])): ?>
                <a href="<?= BASE_URL ?>/modules/invoices/payment.php?invoice_id=<?= $inv['id'] ?>" class="btn btn-outline-success" title="Add Payment"><i class="bi bi-cash-coin"></i></a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
