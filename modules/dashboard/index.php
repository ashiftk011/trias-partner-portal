<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Dashboard';
$db = getDB();
$user = currentUser();
$isInvestor = isRole('investor');
$investorProjectId = $isInvestor ? getInvestorProjectId() : 0;

// Stats
$stats = [];

if (hasAccess('leads')) {
    if ($isInvestor && $investorProjectId) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE project_id=?"); $stmt->execute([$investorProjectId]); $stats['total_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE project_id=? AND status='new'"); $stmt->execute([$investorProjectId]); $stats['new_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE project_id=? AND status='converted'"); $stmt->execute([$investorProjectId]); $stats['converted_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE project_id=? AND status='follow_up'"); $stmt->execute([$investorProjectId]); $stats['follow_up_leads'] = $stmt->fetchColumn();
    } else {
        $stats['total_leads']     = $db->query("SELECT COUNT(*) FROM leads")->fetchColumn();
        $stats['new_leads']       = $db->query("SELECT COUNT(*) FROM leads WHERE status='new'")->fetchColumn();
        $stats['converted_leads'] = $db->query("SELECT COUNT(*) FROM leads WHERE status='converted'")->fetchColumn();
        $stats['follow_up_leads'] = $db->query("SELECT COUNT(*) FROM leads WHERE status='follow_up'")->fetchColumn();
    }
}

if (hasAccess('clients')) {
    if ($isInvestor && $investorProjectId) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE status='active' AND project_id=?");
        $stmt->execute([$investorProjectId]);
        $stats['total_clients'] = $stmt->fetchColumn();
    } else {
        $stats['total_clients']  = $db->query("SELECT COUNT(*) FROM clients WHERE status='active'")->fetchColumn();
    }
}

if (hasAccess('invoices')) {
    $stats['pending_invoices'] = $db->query("SELECT COUNT(*) FROM invoices WHERE status='pending'")->fetchColumn();
    $stats['overdue_invoices'] = $db->query("SELECT COUNT(*) FROM invoices WHERE status='overdue'")->fetchColumn();
    $stats['revenue_month']    = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())")->fetchColumn();
}

if (hasAccess('renewals')) {
    $stats['expiring_soon'] = $db->query("SELECT COUNT(*) FROM renewals WHERE status='active' AND end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)")->fetchColumn();
}

// Recent leads (for telecall + admin)
$recentLeads = [];
if (hasAccess('leads')) {
    if ($isInvestor && $investorProjectId) {
        $stmt = $db->prepare("SELECT l.*, p.name as project_name FROM leads l LEFT JOIN projects p ON p.id=l.project_id WHERE l.project_id=? ORDER BY l.created_at DESC LIMIT 5");
        $stmt->execute([$investorProjectId]);
        $recentLeads = $stmt->fetchAll();
    } else {
        $stmt = $db->query("SELECT l.*, p.name as project_name FROM leads l LEFT JOIN projects p ON p.id=l.project_id ORDER BY l.created_at DESC LIMIT 5");
        $recentLeads = $stmt->fetchAll();
    }
}

// Recent payments (for finance + admin)
$recentPayments = [];
if (hasAccess('invoices')) {
    $stmt = $db->query("SELECT py.*, c.name as client_name, i.invoice_no FROM payments py LEFT JOIN clients c ON c.id=py.client_id LEFT JOIN invoices i ON i.id=py.invoice_id ORDER BY py.created_at DESC LIMIT 5");
    $recentPayments = $stmt->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center mb-4">
  <div>
    <h4 class="mb-0">Dashboard</h4>
    <p class="text-muted small mb-0">Welcome back, <?= htmlspecialchars($user['name']) ?>!</p>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">

<?php if (hasAccess('leads')): ?>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-primary-subtle rounded-3 p-3">
          <i class="bi bi-funnel-fill fs-3 text-primary"></i>
        </div>
        <div>
          <div class="stat-value fw-bold fs-3"><?= number_format($stats['total_leads']) ?></div>
          <div class="stat-label text-muted small">Total Leads</div>
          <div class="mt-1">
            <span class="badge bg-primary"><?= $stats['new_leads'] ?> New</span>
            <span class="badge bg-warning text-dark"><?= $stats['follow_up_leads'] ?> Follow-up</span>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-success-subtle rounded-3 p-3">
          <i class="bi bi-check-circle-fill fs-3 text-success"></i>
        </div>
        <div>
          <div class="stat-value fw-bold fs-3"><?= number_format($stats['converted_leads']) ?></div>
          <div class="stat-label text-muted small">Leads Converted</div>
          <?php if ($stats['total_leads'] > 0): ?>
          <div class="mt-1"><span class="badge bg-success"><?= round($stats['converted_leads']/$stats['total_leads']*100) ?>% Rate</span></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (hasAccess('clients')): ?>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-info-subtle rounded-3 p-3">
          <i class="bi bi-people-fill fs-3 text-info"></i>
        </div>
        <div>
          <div class="stat-value fw-bold fs-3"><?= number_format($stats['total_clients']) ?></div>
          <div class="stat-label text-muted small">Active Clients</div>
          <?php if (isset($stats['expiring_soon'])): ?>
          <div class="mt-1"><span class="badge bg-warning text-dark"><?= $stats['expiring_soon'] ?> Expiring</span></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (hasAccess('invoices')): ?>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-warning-subtle rounded-3 p-3">
          <i class="bi bi-currency-rupee fs-3 text-warning"></i>
        </div>
        <div>
          <div class="stat-value fw-bold fs-3">₹<?= number_format($stats['revenue_month']) ?></div>
          <div class="stat-label text-muted small">Revenue (This Month)</div>
          <div class="mt-1">
            <span class="badge bg-warning text-dark"><?= $stats['pending_invoices'] ?> Pending</span>
            <?php if ($stats['overdue_invoices'] > 0): ?>
            <span class="badge bg-danger"><?= $stats['overdue_invoices'] ?> Overdue</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

</div>

<div class="row g-4">
  <!-- Recent Leads -->
  <?php if (hasAccess('leads') && $recentLeads): ?>
  <div class="col-xl-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-funnel me-2 text-primary"></i>Recent Leads</h6>
        <a href="<?= BASE_URL ?>/modules/leads/index.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Lead</th><th>Project</th><th>Phone</th><th>Status</th><th>Date</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentLeads as $lead): ?>
              <tr>
                <td>
                  <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $lead['id'] ?>" class="fw-semibold text-decoration-none">
                    <?= htmlspecialchars($lead['name']) ?>
                  </a>
                  <?php if ($lead['company']): ?>
                  <div class="text-muted small"><?= htmlspecialchars($lead['company']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="badge bg-light text-dark"><?= htmlspecialchars($lead['project_name'] ?? '') ?></span></td>
                <td><?= htmlspecialchars($lead['phone']) ?></td>
                <td><?= statusBadge($lead['status']) ?></td>
                <td class="text-muted small"><?= date('d M', strtotime($lead['created_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recent Payments -->
  <?php if (hasAccess('invoices') && $recentPayments): ?>
  <div class="col-xl-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-cash-stack me-2 text-success"></i>Recent Payments</h6>
        <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="btn btn-sm btn-outline-success">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Client</th><th>Invoice</th><th>Amount</th><th>Date</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentPayments as $p): ?>
              <tr>
                <td class="fw-semibold small"><?= htmlspecialchars($p['client_name']) ?></td>
                <td><small class="text-muted"><?= htmlspecialchars($p['invoice_no']) ?></small></td>
                <td class="text-success fw-semibold">₹<?= number_format($p['amount'], 2) ?></td>
                <td class="text-muted small"><?= date('d M', strtotime($p['payment_date'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
