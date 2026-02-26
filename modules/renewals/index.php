<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('renewals');

$db = getDB();
$filterClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$filterStatus = $_GET['status'] ?? '';

$sql = "SELECT rn.*,pl.name as plan_name,pl.price as plan_price,pl.duration_months,c.name as client_name,c.client_code,c.company,p.name as project_name,u.name as renewed_by_name
        FROM renewals rn
        LEFT JOIN plans pl ON pl.id=rn.plan_id
        LEFT JOIN clients c ON c.id=rn.client_id
        LEFT JOIN projects p ON p.id=c.project_id
        LEFT JOIN users u ON u.id=rn.renewed_by
        WHERE 1=1";
$params = [];
if ($filterClient) { $sql .= " AND rn.client_id=?"; $params[] = $filterClient; }
if ($filterStatus) { $sql .= " AND rn.status=?";    $params[] = $filterStatus; }
$sql .= " ORDER BY rn.end_date ASC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$renewals = $stmt->fetchAll();

$plans   = $db->query("SELECT pl.*,p.name as project_name FROM plans pl LEFT JOIN projects p ON p.id=pl.project_id WHERE pl.status='active' ORDER BY p.name,pl.name")->fetchAll();
$clients = $db->query("SELECT id,name,client_code,company FROM clients WHERE status='active' ORDER BY name")->fetchAll();

// Expiring soon (within 30 days)
$expiring = $db->query("SELECT COUNT(*) FROM renewals WHERE status='active' AND end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)")->fetchColumn();
$expired  = $db->query("SELECT COUNT(*) FROM renewals WHERE status='active' AND end_date < NOW()")->fetchColumn();

$pageTitle = 'Renewals';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="mb-0">Renewals</h4>
    <p class="text-muted small mb-0">Client subscription renewals</p>
  </div>
  <a href="<?= BASE_URL ?>/modules/renewals/save.php" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>Add Renewal
  </a>
</div>

<?php displayFlash(); ?>

<!-- Alert Bars -->
<?php if ($expiring > 0 || $expired > 0): ?>
<div class="row g-2 mb-3">
  <?php if ($expiring > 0): ?>
  <div class="col-auto">
    <a href="?status=active&expiring=1" class="alert alert-warning py-2 px-3 mb-0 d-flex align-items-center gap-2 text-decoration-none">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <strong><?= $expiring ?></strong> renewal(s) expiring in 30 days
    </a>
  </div>
  <?php endif; ?>
  <?php if ($expired > 0): ?>
  <div class="col-auto">
    <div class="alert alert-danger py-2 px-3 mb-0 d-flex align-items-center gap-2">
      <i class="bi bi-x-circle-fill"></i>
      <strong><?= $expired ?></strong> renewal(s) have expired (still marked Active)
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filter -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <select name="client_id" class="form-select form-select-sm select2">
          <option value="">All Clients</option>
          <?php foreach ($clients as $cl): ?>
          <option value="<?= $cl['id'] ?>" <?= $filterClient==$cl['id']?'selected':'' ?>><?= htmlspecialchars($cl['client_code'].' - '.$cl['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active" <?= $filterStatus==='active'?'selected':'' ?>>Active</option>
          <option value="expired" <?= $filterStatus==='expired'?'selected':'' ?>>Expired</option>
          <option value="cancelled" <?= $filterStatus==='cancelled'?'selected':'' ?>>Cancelled</option>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-sm btn-primary">Filter</button>
        <?php if ($filterClient || $filterStatus): ?><a href="?" class="btn btn-sm btn-outline-secondary ms-1">Clear</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable">
        <thead class="table-light">
          <tr><th>Client</th><th>Project</th><th>Plan</th><th>Start</th><th>End</th><th>Days Left</th><th>Amount</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($renewals as $rn): ?>
          <?php
          $daysLeft = (int)floor((strtotime($rn['end_date']) - time()) / 86400);
          $daysClass = $daysLeft < 0 ? 'danger' : ($daysLeft <= 30 ? 'warning' : 'success');
          ?>
          <tr>
            <td>
              <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $rn['client_id'] ?>" class="fw-semibold text-decoration-none">
                <?= htmlspecialchars($rn['client_name']) ?>
              </a>
              <div class="text-muted small"><?= htmlspecialchars($rn['client_code']) ?></div>
            </td>
            <td><span class="badge bg-primary small"><?= htmlspecialchars($rn['project_name']) ?></span></td>
            <td class="small"><?= htmlspecialchars($rn['plan_name']) ?></td>
            <td class="small"><?= date('d M Y', strtotime($rn['start_date'])) ?></td>
            <td class="small"><?= date('d M Y', strtotime($rn['end_date'])) ?></td>
            <td>
              <?php if ($rn['status']==='active'): ?>
              <span class="badge bg-<?= $daysClass ?>"><?= $daysLeft < 0 ? 'Expired' : $daysLeft . ' days' ?></span>
              <?php else: ?><span class="text-muted small">-</span><?php endif; ?>
            </td>
            <td class="fw-semibold">₹<?= number_format($rn['amount'],2) ?></td>
            <td><?= statusBadge($rn['status']) ?></td>
            <td>
              <div class="btn-group btn-group-sm">
                <?php if ($rn['status']==='active' && hasAccess('renewals')): ?>
                <a href="<?= BASE_URL ?>/modules/renewals/save.php?renew=<?= $rn['id'] ?>" class="btn btn-outline-primary" title="Renew"><i class="bi bi-arrow-repeat"></i></a>
                <?php endif; ?>
                <?php if (hasAccess('invoices')): ?>
                <a href="<?= BASE_URL ?>/modules/invoices/save.php?client_id=<?= $rn['client_id'] ?>&renewal_id=<?= $rn['id'] ?>" class="btn btn-outline-success" title="Create Invoice"><i class="bi bi-receipt"></i></a>
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
