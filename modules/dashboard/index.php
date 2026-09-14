<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Dashboard';
$db = getDB();
$user = currentUser();
$isInvestor = isRole('investor');
$isTelecall = isRole('telecall');
$investorProjectId  = $isInvestor ? getInvestorProjectId() : 0;
$telecallProjectIds = $isTelecall ? getTelecallProjectIds() : [];

// Stats
$stats = [];

if (hasAccess('leads')) {
    if ($isInvestor && $investorProjectId) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE project_id=?"); $stmt->execute([$investorProjectId]); $stats['total_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE project_id=? AND status='new'"); $stmt->execute([$investorProjectId]); $stats['new_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE project_id=? AND status='converted'"); $stmt->execute([$investorProjectId]); $stats['converted_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE project_id=? AND status='follow_up'"); $stmt->execute([$investorProjectId]); $stats['follow_up_leads'] = $stmt->fetchColumn();
    } elseif ($isTelecall && $telecallProjectIds) {
        $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
        $tcWhere  = "project_id IN ($in)";
        $tcParams = $telecallProjectIds;
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE $tcWhere"); $stmt->execute($tcParams); $stats['total_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE $tcWhere AND status='new'"); $stmt->execute($tcParams); $stats['new_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE $tcWhere AND status='converted'"); $stmt->execute($tcParams); $stats['converted_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE $tcWhere AND status='follow_up'"); $stmt->execute($tcParams); $stats['follow_up_leads'] = $stmt->fetchColumn();
    } elseif ($isTelecall) {
        $stats['total_leads'] = $stats['new_leads'] = $stats['converted_leads'] = $stats['follow_up_leads'] = 0;
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
    // Fetch invoice data grouped by status
    $invoiceQuery = "SELECT status, SUM(total_amount) as total, SUM(paid_amount) as paid FROM invoices GROUP BY status";
    $invoiceResults = $db->query($invoiceQuery)->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);

    $byStatus = [];
    foreach ($invoiceResults as $status => $data) {
        $byStatus[$status] = $data[0]; // Assuming status is unique for each group
    }

    $invPending = 0;
    foreach (['pending', 'partial', 'overdue'] as $st) {
        if (isset($byStatus[$st])) {
            $invPending += ($byStatus[$st]['total'] - $byStatus[$st]['paid']);
        }
    }
    $stats['pending_invoices_amount'] = $invPending; // Renamed to reflect it's an amount

    // Keep existing counts for pending/overdue if needed, or remove if replaced by amount
    $stats['pending_invoices'] = $db->query("SELECT COUNT(*) FROM invoices WHERE status='pending'")->fetchColumn();
    $stats['overdue_invoices'] = $db->query("SELECT COUNT(*) FROM invoices WHERE status='overdue'")->fetchColumn();
    $stats['revenue_month']    = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())")->fetchColumn();
}

if (hasAccess('renewals') || hasAccess('clients')) {
    // Fetch clients expiring soon (Hosting server expiry or Plan end date <= 15 days)
    $sqlExp = "SELECT c.id, c.name as client_name, c.company, c.phone, p.name as project_name,
                      cs_host.setting_value as hosting_expiry,
                      cs_plan.setting_value as plan_setting_end_date,
                      (SELECT end_date FROM renewals WHERE client_id = c.id AND status = 'active' ORDER BY end_date DESC LIMIT 1) as renewal_end_date
               FROM clients c
               LEFT JOIN projects p ON p.id = c.project_id
               LEFT JOIN client_settings cs_host ON cs_host.client_id = c.id AND cs_host.setting_key = 'hosting_expiry'
               LEFT JOIN client_settings cs_plan ON cs_plan.client_id = c.id AND cs_plan.setting_key = 'plan_end_date'
               WHERE c.status = 'active'";

    if ($isInvestor && $investorProjectId) {
        $sqlExp .= " AND c.project_id = " . (int)$investorProjectId;
    }

    $stmtExp = $db->query($sqlExp);
    $allClientsExp = $stmtExp->fetchAll();

    $todayStr = date('Y-m-d');
    $todayTs  = strtotime($todayStr);

    $expiringClients = [];
    foreach ($allClientsExp as $c) {
        $hostExp = !empty($c['hosting_expiry']) ? $c['hosting_expiry'] : null;
        $planExp = !empty($c['plan_setting_end_date']) ? $c['plan_setting_end_date'] : (!empty($c['renewal_end_date']) ? $c['renewal_end_date'] : null);

        $hostDaysLeft = null;
        if ($hostExp) {
            $hostDaysLeft = (int)floor((strtotime($hostExp) - $todayTs) / 86400);
        }

        $planDaysLeft = null;
        if ($planExp) {
            $planDaysLeft = (int)floor((strtotime($planExp) - $todayTs) / 86400);
        }

        $isHostExpiring = ($hostDaysLeft !== null && $hostDaysLeft <= 15);
        $isPlanExpiring = ($planDaysLeft !== null && $planDaysLeft <= 15);

        if ($isHostExpiring || $isPlanExpiring) {
            $expiringClients[] = [
                'id'             => $c['id'],
                'client_name'    => $c['client_name'],
                'company'        => $c['company'],
                'phone'          => $c['phone'],
                'project_name'   => $c['project_name'],
                'hosting_expiry' => $hostExp,
                'host_days_left' => $hostDaysLeft,
                'is_host_exp'    => $isHostExpiring,
                'plan_end_date'  => $planExp,
                'plan_days_left' => $planDaysLeft,
                'is_plan_exp'    => $isPlanExpiring,
            ];
        }
    }
    $stats['expiring_soon'] = count($expiringClients);
}

// Recent leads (scoped per role)
$recentLeads = [];
if (hasAccess('leads')) {
    if ($isInvestor && $investorProjectId) {
        $stmt = $db->prepare("SELECT l.*, p.name as project_name FROM leads l LEFT JOIN projects p ON p.id=l.project_id WHERE l.project_id=? ORDER BY l.created_at DESC LIMIT 5");
        $stmt->execute([$investorProjectId]);
        $recentLeads = $stmt->fetchAll();
    } elseif ($isTelecall && $telecallProjectIds) {
        $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
        $stmt = $db->prepare("SELECT l.*, p.name as project_name FROM leads l LEFT JOIN projects p ON p.id=l.project_id WHERE l.project_id IN ($in) ORDER BY l.created_at DESC LIMIT 5");
        $stmt->execute($telecallProjectIds);
        $recentLeads = $stmt->fetchAll();
    } else {
        $stmt = $db->query("SELECT l.*, p.name as project_name FROM leads l LEFT JOIN projects p ON p.id=l.project_id ORDER BY l.created_at DESC LIMIT 5");
        $recentLeads = $stmt->fetchAll();
    }
}

// Recent payments (for finance, accounts + admin)
$recentPayments = [];
if (hasAccess('invoices') || hasAccess('payments')) {
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
          <div class="mt-1"><span class="badge bg-warning text-dark"><?= $stats['expiring_soon'] ?> Expiring (15d)</span></div>
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

<!-- Clients Expiring Soon (Hosting Server & Plan End Date <= 15 Days) -->
<?php if ((hasAccess('clients') || hasAccess('renewals')) && !empty($expiringClients)): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-4 border-warning">
  <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
    <h6 class="mb-0 fw-semibold text-dark">
      <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Clients Expiring Soon (Hosting & Plan ≤ 15 Days)
    </h6>
    <span class="badge bg-warning text-dark font-monospace px-2 py-1"><?= count($expiringClients) ?> Client(s)</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Client Name</th>
            <th>Project</th>
            <th>Hosting Server Expiry</th>
            <th>Plan End Date</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expiringClients as $ec): ?>
          <tr>
            <td>
              <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $ec['id'] ?>" class="fw-semibold text-decoration-none">
                <?= htmlspecialchars($ec['client_name']) ?>
              </a>
              <?php if ($ec['company']): ?>
              <div class="text-muted small"><?= htmlspecialchars($ec['company']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-light text-dark border"><?= htmlspecialchars($ec['project_name'] ?? 'N/A') ?></span>
            </td>
            <td>
              <?php if ($ec['hosting_expiry']): ?>
                <div class="fw-semibold small"><?= date('d M Y', strtotime($ec['hosting_expiry'])) ?></div>
                <?php if ($ec['host_days_left'] < 0): ?>
                  <span class="badge bg-danger">Expired (<?= abs($ec['host_days_left']) ?>d ago)</span>
                <?php elseif ($ec['host_days_left'] === 0): ?>
                  <span class="badge bg-danger">Expires Today</span>
                <?php elseif ($ec['is_host_exp']): ?>
                  <span class="badge bg-warning text-dark"><?= $ec['host_days_left'] ?> days left</span>
                <?php else: ?>
                  <span class="badge bg-secondary"><?= $ec['host_days_left'] ?> days left</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted small">Not set</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($ec['plan_end_date']): ?>
                <div class="fw-semibold small"><?= date('d M Y', strtotime($ec['plan_end_date'])) ?></div>
                <?php if ($ec['plan_days_left'] < 0): ?>
                  <span class="badge bg-danger">Expired (<?= abs($ec['plan_days_left']) ?>d ago)</span>
                <?php elseif ($ec['plan_days_left'] === 0): ?>
                  <span class="badge bg-danger">Expires Today</span>
                <?php elseif ($ec['is_plan_exp']): ?>
                  <span class="badge bg-warning text-dark"><?= $ec['plan_days_left'] ?> days left</span>
                <?php else: ?>
                  <span class="badge bg-secondary"><?= $ec['plan_days_left'] ?> days left</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted small">Not set</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/modules/clients/settings.php?id=<?= $ec['id'] ?>" class="btn btn-outline-primary" title="Settings">
                  <i class="bi bi-gear me-1"></i> Settings
                </a>
                <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $ec['id'] ?>" class="btn btn-outline-secondary" title="View Details">
                  <i class="bi bi-eye"></i> View
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

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
  <?php if ((hasAccess('invoices') || hasAccess('payments')) && $recentPayments): ?>
  <div class="col-xl-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-cash-stack me-2 text-success"></i>Recent Payments</h6>
        <a href="<?= BASE_URL ?>/modules/payments/index.php" class="btn btn-sm btn-outline-success">View All</a>
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
