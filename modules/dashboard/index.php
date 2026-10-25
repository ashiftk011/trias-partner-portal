<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Dashboard';
$db = getDB();
$user = currentUser();
$isInvestor = isRole('investor');
$isTelecall = isRole('telecall');
$isCsm      = isRole('csm');
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
    } elseif ($isCsm) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE created_by=?"); $stmt->execute([$user['id']]); $stats['total_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE created_by=? AND status='new'"); $stmt->execute([$user['id']]); $stats['new_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE created_by=? AND status='converted'"); $stmt->execute([$user['id']]); $stats['converted_leads'] = $stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leads WHERE created_by=? AND status='follow_up'"); $stmt->execute([$user['id']]); $stats['follow_up_leads'] = $stmt->fetchColumn();
    } else {
        $stats['total_leads']     = $db->query("SELECT COUNT(*) FROM leads")->fetchColumn();
        $stats['new_leads']       = $db->query("SELECT COUNT(*) FROM leads WHERE status='new'")->fetchColumn();
        $stats['converted_leads'] = $db->query("SELECT COUNT(*) FROM leads WHERE status='converted'")->fetchColumn();
        $stats['follow_up_leads'] = $db->query("SELECT COUNT(*) FROM leads WHERE status='follow_up'")->fetchColumn();
    }

    // Upcoming & Due Follow-ups (Scoped per role)
    $upcomingFollowups = [];
    $stats['followups_today'] = 0;
    $stats['followups_overdue'] = 0;
    $stats['followups_upcoming'] = 0;

    $todayStr = date('Y-m-d');
    $todayTs  = strtotime($todayStr);

    $fuSql = "SELECT 
                l.id as lead_id,
                l.lead_code,
                l.name as lead_name,
                l.company,
                l.phone,
                l.status as lead_status,
                l.project_id,
                p.name as project_name,
                l.assigned_to,
                u_assigned.name as assigned_name,
                lr.id as response_id,
                lr.response_type,
                lr.response as last_response,
                COALESCE(l.next_followup, lr.next_followup) as next_followup,
                lr.created_at as response_date,
                u_resp.name as response_by_name
            FROM (
                SELECT lead_id, MAX(id) as max_resp_id
                FROM lead_responses
                WHERE next_followup IS NOT NULL
                GROUP BY lead_id
            ) latest_fu
            JOIN lead_responses lr ON lr.id = latest_fu.max_resp_id
            JOIN leads l ON l.id = lr.lead_id
            LEFT JOIN projects p ON p.id = l.project_id
            LEFT JOIN users u_assigned ON u_assigned.id = l.assigned_to
            LEFT JOIN users u_resp ON u_resp.id = lr.response_by
            WHERE l.status NOT IN ('converted', 'false_lead', 'not_interested')";
    $fuParams = [];

    if ($isInvestor && $investorProjectId) {
        $fuSql .= " AND l.project_id = ?";
        $fuParams[] = $investorProjectId;
    } elseif ($isTelecall && $telecallProjectIds) {
        $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
        $fuSql .= " AND l.project_id IN ($in)";
        $fuParams = array_merge($fuParams, $telecallProjectIds);
    } elseif ($isTelecall) {
        $fuSql .= " AND 1=0";
    } elseif ($isCsm) {
        $fuSql .= " AND l.created_by = ?";
        $fuParams[] = $user['id'];
    }

    $fuSql .= " ORDER BY COALESCE(l.next_followup, lr.next_followup) ASC, lr.id DESC";

    $fuStmt = $db->prepare($fuSql);
    $fuStmt->execute($fuParams);
    $allFu = $fuStmt->fetchAll();

    foreach ($allFu as $fu) {
        $fDate = $fu['next_followup'];
        if (!$fDate) continue;
        $fTs   = strtotime($fDate);
        $diffDays = (int)round(($fTs - $todayTs) / 86400);

        if ($diffDays < 0) {
            $urgency = 'overdue';
            $urgencyLabel = 'Overdue (' . abs($diffDays) . 'd ago)';
            $badgeClass = 'bg-danger text-white';
            $stats['followups_overdue']++;
        } elseif ($diffDays === 0) {
            $urgency = 'today';
            $urgencyLabel = 'Due Today';
            $badgeClass = 'bg-warning text-dark';
            $stats['followups_today']++;
        } elseif ($diffDays === 1) {
            $urgency = 'upcoming';
            $urgencyLabel = 'Tomorrow';
            $badgeClass = 'bg-info text-dark';
            $stats['followups_upcoming']++;
        } else {
            $urgency = 'upcoming';
            $urgencyLabel = 'In ' . $diffDays . ' days';
            $badgeClass = 'bg-primary-subtle text-primary border border-primary-subtle';
            $stats['followups_upcoming']++;
        }

        $fu['days_diff']     = $diffDays;
        $fu['urgency']       = $urgency;
        $fu['urgency_label'] = $urgencyLabel;
        $fu['badge_class']   = $badgeClass;
        $upcomingFollowups[] = $fu;
    }
} else {
    $upcomingFollowups = [];
    $stats['followups_today'] = $stats['followups_overdue'] = $stats['followups_upcoming'] = 0;
}

if (hasAccess('clients')) {
    if ($isInvestor && $investorProjectId) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE status='active' AND project_id=?");
        $stmt->execute([$investorProjectId]);
        $stats['total_clients'] = $stmt->fetchColumn();
    } elseif ($isCsm) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE status='active' AND created_by=?");
        $stmt->execute([$user['id']]);
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
    // Fetch clients expiring soon (Hosting server expiry or Plan end date <= 15 days across all instances)
    $sqlExp = "SELECT c.id, c.name as client_name, c.company, c.phone, p.name as project_name,
                      (SELECT MIN(setting_value) FROM client_settings WHERE client_id = c.id AND setting_key = 'hosting_expiry' AND setting_value IS NOT NULL AND setting_value != '') as hosting_expiry,
                      (SELECT MIN(setting_value) FROM client_settings WHERE client_id = c.id AND setting_key = 'plan_end_date' AND setting_value IS NOT NULL AND setting_value != '') as plan_setting_end_date,
                      (SELECT end_date FROM renewals WHERE client_id = c.id AND status = 'active' ORDER BY end_date DESC LIMIT 1) as renewal_end_date
               FROM clients c
               LEFT JOIN projects p ON p.id = c.project_id
               WHERE c.status = 'active'";

    if ($isInvestor && $investorProjectId) {
        $sqlExp .= " AND c.project_id = " . (int)$investorProjectId;
    } elseif ($isCsm) {
        $sqlExp .= " AND c.created_by = " . (int)$user['id'];
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
    } elseif ($isCsm) {
        $stmt = $db->prepare("SELECT l.*, p.name as project_name FROM leads l LEFT JOIN projects p ON p.id=l.project_id WHERE l.created_by=? ORDER BY l.created_at DESC LIMIT 5");
        $stmt->execute([$user['id']]);
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

// Quotations (for CSM and users with quotations access)
$recentQuotations = [];
if (hasAccess('quotations')) {
    if ($isCsm) {
        $qStmt = $db->prepare("SELECT COUNT(*) FROM quotations WHERE created_by=?");
        $qStmt->execute([$user['id']]);
        $stats['total_quotations'] = (int)$qStmt->fetchColumn();

        $qStmt = $db->prepare("SELECT COUNT(*) FROM quotations WHERE created_by=? AND status='accepted'");
        $qStmt->execute([$user['id']]);
        $stats['accepted_quotations'] = (int)$qStmt->fetchColumn();

        $qStmt = $db->prepare("SELECT COUNT(*) FROM quotations WHERE created_by=? AND status='sent'");
        $qStmt->execute([$user['id']]);
        $stats['sent_quotations'] = (int)$qStmt->fetchColumn();

        $rqStmt = $db->prepare("SELECT q.*, COALESCE(c.name, l.name) as contact_name FROM quotations q LEFT JOIN clients c ON c.id=q.client_id LEFT JOIN leads l ON l.id=q.lead_id WHERE q.created_by=? ORDER BY q.created_at DESC LIMIT 5");
        $rqStmt->execute([$user['id']]);
        $recentQuotations = $rqStmt->fetchAll();
    } else {
        $stats['total_quotations'] = (int)$db->query("SELECT COUNT(*) FROM quotations")->fetchColumn();
        $stats['accepted_quotations'] = (int)$db->query("SELECT COUNT(*) FROM quotations WHERE status='accepted'")->fetchColumn();
        $stats['sent_quotations'] = (int)$db->query("SELECT COUNT(*) FROM quotations WHERE status='sent'")->fetchColumn();

        $recentQuotations = $db->query("SELECT q.*, COALESCE(c.name, l.name) as contact_name FROM quotations q LEFT JOIN clients c ON c.id=q.client_id LEFT JOIN leads l ON l.id=q.lead_id ORDER BY q.created_at DESC LIMIT 5")->fetchAll();
    }
}

// Outreach Stats
if (hasAccess('outreach')) {
    if ($isTelecall && $telecallProjectIds) {
        $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
        $stmt = $db->prepare("SELECT COUNT(*) FROM email_outreaches WHERE project_id IN ($in)");
        $stmt->execute($telecallProjectIds);
        $stats['total_outreach'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM email_outreaches WHERE project_id IN ($in) AND status='replied'");
        $stmt->execute($telecallProjectIds);
        $stats['replied_outreach'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM email_outreaches WHERE project_id IN ($in) AND response_type IN ('meeting_scheduled', 'demo_requested')");
        $stmt->execute($telecallProjectIds);
        $stats['meetings_outreach'] = (int)$stmt->fetchColumn();
    } else {
        $stats['total_outreach'] = (int)$db->query("SELECT COUNT(*) FROM email_outreaches")->fetchColumn();
        $stats['replied_outreach'] = (int)$db->query("SELECT COUNT(*) FROM email_outreaches WHERE status='replied'")->fetchColumn();
        $stats['meetings_outreach'] = (int)$db->query("SELECT COUNT(*) FROM email_outreaches WHERE response_type IN ('meeting_scheduled', 'demo_requested')")->fetchColumn();
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 mb-md-4">
  <div>
    <h4 class="mb-0 fs-4 fw-bold">Dashboard</h4>
    <p class="text-muted small mb-0">Welcome back, <?= htmlspecialchars($user['name']) ?>!</p>
  </div>
  <?php if ($isTelecall): ?>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/modules/leads/index.php" class="btn btn-primary btn-sm">
      <i class="bi bi-funnel me-1"></i>View Leads (<?= number_format($stats['total_leads']) ?>)
    </a>
    <?php if (($stats['followups_today'] ?? 0) > 0): ?>
    <a href="#telecallerFollowupsSection" class="btn btn-warning btn-sm fw-bold shadow-sm">
      <i class="bi bi-bell-fill me-1"></i>Due Today (<?= $stats['followups_today'] ?>)
    </a>
    <?php endif; ?>
    <?php if (($stats['followups_overdue'] ?? 0) > 0): ?>
    <a href="#telecallerFollowupsSection" class="btn btn-danger btn-sm fw-bold shadow-sm">
      <i class="bi bi-exclamation-triangle-fill me-1"></i>Overdue (<?= $stats['followups_overdue'] ?>)
    </a>
    <?php endif; ?>
    <?php if (($stats['new_leads'] ?? 0) > 0): ?>
    <a href="<?= BASE_URL ?>/modules/leads/index.php?status=new" class="btn btn-outline-warning btn-sm fw-bold">
      <i class="bi bi-telephone-outbound me-1"></i>Call New Leads (<?= $stats['new_leads'] ?>)
    </a>
    <?php endif; ?>
  </div>
  <?php elseif (isRole('admin')): ?>
  <div class="d-flex align-items-center dashboard-quick-actions w-100 w-sm-auto">
    <a href="<?= BASE_URL ?>/modules/leads/index.php" class="btn btn-sm btn-outline-primary dashboard-quick-action-btn">
      <i class="bi bi-funnel"></i>Leads
    </a>
    <a href="<?= BASE_URL ?>/modules/expenses/index.php" class="btn btn-sm btn-outline-danger dashboard-quick-action-btn">
      <i class="bi bi-credit-card-2-front"></i>Expenses
    </a>
    <a href="<?= BASE_URL ?>/modules/invoices/create.php" class="btn btn-sm btn-outline-success dashboard-quick-action-btn">
      <i class="bi bi-file-earmark-plus"></i>Invoice
    </a>
    <a href="<?= BASE_URL ?>/modules/clients/index.php" class="btn btn-sm btn-outline-secondary dashboard-quick-action-btn">
      <i class="bi bi-people"></i>Clients
    </a>
  </div>
  <?php endif; ?>
</div>

<?php displayFlash(); ?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">

<?php if ($isTelecall): ?>
  <!-- Telecaller Specific 4-Card Responsive Grid -->
  <div class="col-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-primary-subtle rounded-3 p-3">
          <i class="bi bi-funnel-fill fs-3 text-primary"></i>
        </div>
        <div>
          <div class="stat-value fw-bold fs-3"><?= number_format($stats['total_leads']) ?></div>
          <div class="stat-label text-muted small">Total Leads</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= BASE_URL ?>/modules/leads/index.php?status=new" class="text-decoration-none">
      <div class="stat-card card border-0 shadow-sm h-100 border-start border-4 border-warning">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="stat-icon bg-warning-subtle rounded-3 p-3">
            <i class="bi bi-star-fill fs-3 text-warning"></i>
          </div>
          <div>
            <div class="stat-value fw-bold fs-3 text-warning"><?= number_format($stats['new_leads']) ?></div>
            <div class="stat-label text-muted small">New Uncontacted</div>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="#telecallerFollowupsSection" class="text-decoration-none">
      <div class="stat-card card border-0 shadow-sm h-100 border-start border-4 border-info">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="stat-icon bg-info-subtle rounded-3 p-3">
            <i class="bi bi-clock-history fs-3 text-info"></i>
          </div>
          <div>
            <div class="stat-value fw-bold fs-3 text-info"><?= number_format($stats['follow_up_leads']) ?></div>
            <div class="stat-label text-muted small">Follow-up Needed</div>
            <div class="mt-1 d-flex flex-wrap gap-1">
              <?php if (!empty($stats['followups_today'])): ?>
                <span class="badge bg-warning text-dark fw-bold" title="Due Today"><i class="bi bi-bell-fill me-1"></i><?= $stats['followups_today'] ?> Today</span>
              <?php endif; ?>
              <?php if (!empty($stats['followups_overdue'])): ?>
                <span class="badge bg-danger fw-bold" title="Overdue Follow-ups"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= $stats['followups_overdue'] ?> Overdue</span>
              <?php endif; ?>
              <?php if (empty($stats['followups_today']) && empty($stats['followups_overdue']) && !empty($upcomingFollowups)): ?>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= count($upcomingFollowups) ?> Scheduled</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= BASE_URL ?>/modules/leads/index.php?status=converted" class="text-decoration-none">
      <div class="stat-card card border-0 shadow-sm h-100 border-start border-4 border-success">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="stat-icon bg-success-subtle rounded-3 p-3">
            <i class="bi bi-check-circle-fill fs-3 text-success"></i>
          </div>
          <div>
            <div class="stat-value fw-bold fs-3 text-success"><?= number_format($stats['converted_leads']) ?></div>
            <div class="stat-label text-muted small">Converted Clients</div>
            <?php if ($stats['total_leads'] > 0): ?>
            <div class="mt-1"><span class="badge bg-success"><?= round($stats['converted_leads']/$stats['total_leads']*100) ?>% Rate</span></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </a>
  </div>
<?php elseif (hasAccess('leads')): ?>
  <div class="col-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-2 gap-sm-3 p-3">
        <div class="stat-icon bg-primary-subtle rounded-3 p-2 p-sm-3 flex-shrink-0" style="width: 44px; height: 44px;">
          <i class="bi bi-funnel-fill fs-4 fs-sm-3 text-primary"></i>
        </div>
        <div class="min-w-0">
          <div class="stat-value fw-bold fs-4 fs-sm-3 text-truncate"><?= number_format($stats['total_leads']) ?></div>
          <div class="stat-label text-muted small text-truncate" style="font-size: 0.72rem;">Total Leads</div>
          <div class="mt-1 d-flex flex-wrap gap-1">
            <span class="badge bg-primary" style="font-size: 0.68rem;"><?= $stats['new_leads'] ?> New</span>
            <span class="badge bg-warning text-dark" style="font-size: 0.68rem;"><?= $stats['follow_up_leads'] ?> Follow-up</span>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-2 gap-sm-3 p-3">
        <div class="stat-icon bg-success-subtle rounded-3 p-2 p-sm-3 flex-shrink-0" style="width: 44px; height: 44px;">
          <i class="bi bi-check-circle-fill fs-4 fs-sm-3 text-success"></i>
        </div>
        <div class="min-w-0">
          <div class="stat-value fw-bold fs-4 fs-sm-3 text-truncate"><?= number_format($stats['converted_leads']) ?></div>
          <div class="stat-label text-muted small text-truncate" style="font-size: 0.72rem;">Leads Converted</div>
          <?php if ($stats['total_leads'] > 0): ?>
          <div class="mt-1"><span class="badge bg-success" style="font-size: 0.68rem;"><?= round($stats['converted_leads']/$stats['total_leads']*100) ?>% Rate</span></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (hasAccess('clients')): ?>
  <div class="col-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-2 gap-sm-3 p-3">
        <div class="stat-icon bg-info-subtle rounded-3 p-2 p-sm-3 flex-shrink-0" style="width: 44px; height: 44px;">
          <i class="bi bi-people-fill fs-4 fs-sm-3 text-info"></i>
        </div>
        <div class="min-w-0">
          <div class="stat-value fw-bold fs-4 fs-sm-3 text-truncate"><?= number_format($stats['total_clients']) ?></div>
          <div class="stat-label text-muted small text-truncate" style="font-size: 0.72rem;">Active Clients</div>
          <?php if (isset($stats['expiring_soon'])): ?>
          <div class="mt-1"><span class="badge bg-warning text-dark" style="font-size: 0.68rem;"><?= $stats['expiring_soon'] ?> Expiring (15d)</span></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (hasAccess('invoices')): ?>
  <div class="col-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-2 gap-sm-3 p-3">
        <div class="stat-icon bg-warning-subtle rounded-3 p-2 p-sm-3 flex-shrink-0" style="width: 44px; height: 44px;">
          <i class="bi bi-currency-rupee fs-4 fs-sm-3 text-warning"></i>
        </div>
        <div class="min-w-0">
          <div class="stat-value fw-bold fs-4 fs-sm-3 text-truncate">₹<?= number_format($stats['revenue_month']) ?></div>
          <div class="stat-label text-muted small text-truncate" style="font-size: 0.72rem;">Revenue (Month)</div>
          <div class="mt-1 d-flex flex-wrap gap-1">
            <span class="badge bg-warning text-dark" style="font-size: 0.68rem;"><?= $stats['pending_invoices'] ?> Pending</span>
            <?php if ($stats['overdue_invoices'] > 0): ?>
            <span class="badge bg-danger" style="font-size: 0.68rem;"><?= $stats['overdue_invoices'] ?> Overdue</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php elseif (hasAccess('quotations')): ?>
  <div class="col-6 col-xl-3">
    <div class="stat-card card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-2 gap-sm-3 p-3">
        <div class="stat-icon bg-warning-subtle rounded-3 p-2 p-sm-3 flex-shrink-0" style="width: 44px; height: 44px;">
          <i class="bi bi-calculator fs-4 fs-sm-3 text-warning"></i>
        </div>
        <div class="min-w-0">
          <div class="stat-value fw-bold fs-4 fs-sm-3 text-truncate"><?= number_format($stats['total_quotations'] ?? 0) ?></div>
          <div class="stat-label text-muted small text-truncate" style="font-size: 0.72rem;"><?= $isCsm ? 'My Quotations' : 'Quotations' ?></div>
          <div class="mt-1 d-flex flex-wrap gap-1">
            <span class="badge bg-success" style="font-size: 0.68rem;"><?= $stats['accepted_quotations'] ?? 0 ?> Accepted</span>
            <span class="badge bg-info text-dark" style="font-size: 0.68rem;"><?= $stats['sent_quotations'] ?? 0 ?> Sent</span>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (hasAccess('outreach')): ?>
  <div class="col-6 col-xl-3">
    <a href="<?= BASE_URL ?>/modules/outreach/index.php" class="text-decoration-none">
      <div class="stat-card card border-0 shadow-sm h-100">
        <div class="card-body d-flex align-items-center gap-2 gap-sm-3 p-3">
          <div class="stat-icon bg-primary-subtle rounded-3 p-2 p-sm-3 flex-shrink-0" style="width: 44px; height: 44px;">
            <i class="bi bi-envelope-at-fill fs-4 fs-sm-3 text-primary"></i>
          </div>
          <div class="min-w-0">
            <div class="stat-value fw-bold fs-4 fs-sm-3 text-dark text-truncate"><?= number_format($stats['total_outreach'] ?? 0) ?></div>
            <div class="stat-label text-muted small text-truncate" style="font-size: 0.72rem;">Cold Outreach</div>
            <div class="mt-1 d-flex flex-wrap gap-1">
              <span class="badge bg-info text-white" style="font-size: 0.68rem;"><?= $stats['replied_outreach'] ?? 0 ?> Replied</span>
              <span class="badge bg-success" style="font-size: 0.68rem;"><?= $stats['meetings_outreach'] ?? 0 ?> Demos</span>
            </div>
          </div>
        </div>
      </div>
    </a>
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
    <!-- Desktop Table View -->
    <div class="table-responsive d-none d-md-block">
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

    <!-- Mobile Card View -->
    <div class="d-md-none p-3">
      <?php foreach ($expiringClients as $ec): 
        $isUrgent = ($ec['host_days_left'] !== null && $ec['host_days_left'] <= 0) || ($ec['plan_days_left'] !== null && $ec['plan_days_left'] <= 0);
      ?>
      <div class="border rounded-3 p-3 mb-3 bg-white shadow-sm border-start border-4 <?= $isUrgent ? 'border-danger' : 'border-warning' ?>">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
          <div class="min-w-0">
            <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $ec['id'] ?>" class="fw-bold text-dark text-decoration-none d-block text-truncate">
              <?= htmlspecialchars($ec['client_name']) ?>
            </a>
            <?php if ($ec['company']): ?>
              <div class="text-muted small text-truncate"><i class="bi bi-building me-1"></i><?= htmlspecialchars($ec['company']) ?></div>
            <?php endif; ?>
          </div>
          <span class="badge bg-light text-dark border flex-shrink-0"><?= htmlspecialchars($ec['project_name'] ?? 'N/A') ?></span>
        </div>

        <div class="bg-light p-2 rounded-2 mb-2 small border">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="text-muted"><i class="bi bi-hdd-network me-1"></i>Hosting:</span>
            <?php if ($ec['hosting_expiry']): ?>
              <div class="text-end">
                <span class="fw-semibold"><?= date('d M Y', strtotime($ec['hosting_expiry'])) ?></span>
                <?php if ($ec['host_days_left'] < 0): ?>
                  <span class="badge bg-danger ms-1">Expired</span>
                <?php elseif ($ec['host_days_left'] === 0): ?>
                  <span class="badge bg-danger ms-1">Today</span>
                <?php elseif ($ec['is_host_exp']): ?>
                  <span class="badge bg-warning text-dark ms-1"><?= $ec['host_days_left'] ?>d</span>
                <?php else: ?>
                  <span class="badge bg-secondary ms-1"><?= $ec['host_days_left'] ?>d</span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <span class="text-muted">Not set</span>
            <?php endif; ?>
          </div>

          <div class="d-flex justify-content-between align-items-center">
            <span class="text-muted"><i class="bi bi-calendar-check me-1"></i>Plan End:</span>
            <?php if ($ec['plan_end_date']): ?>
              <div class="text-end">
                <span class="fw-semibold"><?= date('d M Y', strtotime($ec['plan_end_date'])) ?></span>
                <?php if ($ec['plan_days_left'] < 0): ?>
                  <span class="badge bg-danger ms-1">Expired</span>
                <?php elseif ($ec['plan_days_left'] === 0): ?>
                  <span class="badge bg-danger ms-1">Today</span>
                <?php elseif ($ec['is_plan_exp']): ?>
                  <span class="badge bg-warning text-dark ms-1"><?= $ec['plan_days_left'] ?>d</span>
                <?php else: ?>
                  <span class="badge bg-secondary ms-1"><?= $ec['plan_days_left'] ?>d</span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <span class="text-muted">Not set</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="d-flex gap-2">
          <a href="<?= BASE_URL ?>/modules/clients/settings.php?id=<?= $ec['id'] ?>" class="btn btn-outline-primary btn-sm flex-grow-1">
            <i class="bi bi-gear me-1"></i>Settings
          </a>
          <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $ec['id'] ?>" class="btn btn-outline-secondary btn-sm flex-grow-1">
            <i class="bi bi-eye me-1"></i>View
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Upcoming & Due Follow-ups Section -->
<?php if ($isTelecall || !empty($upcomingFollowups)): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-4 border-primary" id="telecallerFollowupsSection">
  <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3 gap-2">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="mb-0 fw-bold text-dark">
          <i class="bi bi-calendar2-check-fill text-primary me-2"></i>Upcoming & Due Follow-ups
        </h6>
        <span class="badge bg-primary text-white rounded-pill px-2"><?= count($upcomingFollowups) ?> Total</span>
      </div>
      <p class="text-muted small mb-0 mt-1">Scheduled calls and interactions with active leads</p>
    </div>

    <!-- Filter Pills (All / Due Today / Overdue / Upcoming) -->
    <div class="d-flex flex-wrap align-items-center gap-2 w-100 w-md-auto justify-content-between justify-content-md-end">
      <div class="followup-filter-scroll w-100 w-md-auto pb-1 pb-md-0">
        <div class="btn-group btn-group-sm p-1 bg-light rounded-pill border text-nowrap" role="group">
          <button type="button" class="btn btn-sm rounded-pill px-3 py-1 fw-semibold btn-primary active followup-filter-btn" data-filter="all" onclick="filterFollowups('all', this)">
            All (<?= count($upcomingFollowups) ?>)
          </button>
          <button type="button" class="btn btn-sm rounded-pill px-3 py-1 fw-semibold text-dark followup-filter-btn <?= ($stats['followups_today'] > 0) ? 'text-warning-emphasis' : '' ?>" data-filter="today" onclick="filterFollowups('today', this)">
            Due Today (<?= $stats['followups_today'] ?>)
          </button>
          <button type="button" class="btn btn-sm rounded-pill px-3 py-1 fw-semibold text-dark followup-filter-btn <?= ($stats['followups_overdue'] > 0) ? 'text-danger' : '' ?>" data-filter="overdue" onclick="filterFollowups('overdue', this)">
            Overdue (<?= $stats['followups_overdue'] ?>)
          </button>
          <button type="button" class="btn btn-sm rounded-pill px-3 py-1 fw-semibold text-dark followup-filter-btn" data-filter="upcoming" onclick="filterFollowups('upcoming', this)">
            Upcoming (<?= $stats['followups_upcoming'] ?>)
          </button>
        </div>
      </div>

      <a href="<?= BASE_URL ?>/modules/leads/index.php?status=follow_up" class="btn btn-outline-secondary btn-sm rounded-pill text-nowrap">
        <i class="bi bi-funnel me-1"></i>All Follow-up Leads
      </a>
    </div>
  </div>

  <div class="card-body p-0">
    <?php if (empty($upcomingFollowups)): ?>
      <div class="text-center py-5">
        <div class="avatar avatar-lg bg-light text-muted rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
          <i class="bi bi-calendar-check fs-2 text-primary"></i>
        </div>
        <h6 class="fw-semibold text-dark mb-1">No Scheduled Follow-ups</h6>
        <p class="text-muted small mb-3">You don't have any follow-ups scheduled right now. Great job keeping in touch!</p>
        <?php if (($stats['new_leads'] ?? 0) > 0): ?>
          <a href="<?= BASE_URL ?>/modules/leads/index.php?status=new" class="btn btn-primary btn-sm">
            <i class="bi bi-telephone-outbound me-1"></i>Call New Leads (<?= $stats['new_leads'] ?>)
          </a>
        <?php else: ?>
          <a href="<?= BASE_URL ?>/modules/leads/index.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-funnel me-1"></i>Browse Leads
          </a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <!-- Desktop Table View -->
      <div class="table-responsive d-none d-md-block">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width: 25%;">Lead / Contact</th>
              <th style="width: 15%;">Project</th>
              <th style="width: 18%;">Scheduled Date</th>
              <th style="width: 27%;">Last Response / Discussion</th>
              <th class="text-end" style="width: 15%;">Quick Actions</th>
            </tr>
          </thead>
          <tbody id="followupsTableBody">
            <?php foreach ($upcomingFollowups as $fu): 
              $cleanPhone = preg_replace('/\D/', '', $fu['phone'] ?? '');
              $respTypeIcon = match($fu['response_type'] ?? '') {
                'call' => 'bi-telephone-fill text-primary',
                'whatsapp' => 'bi-whatsapp text-success',
                'email' => 'bi-envelope-fill text-info',
                'meeting' => 'bi-calendar-event-fill text-warning',
                default => 'bi-chat-dots-fill text-secondary',
              };
              $rowHighlight = '';
              if ($fu['urgency'] === 'today') {
                $rowHighlight = 'table-warning bg-opacity-25';
              } elseif ($fu['urgency'] === 'overdue') {
                $rowHighlight = 'table-danger bg-opacity-10';
              }
            ?>
            <tr class="followup-item-row <?= $rowHighlight ?>" data-urgency="<?= $fu['urgency'] ?>">
              <td>
                <div>
                  <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $fu['lead_id'] ?>" class="fw-bold text-decoration-none text-dark">
                    <?= htmlspecialchars($fu['lead_name']) ?>
                  </a>
                  <?php if (!empty($fu['company'])): ?>
                    <div class="text-muted small"><i class="bi bi-building me-1"></i><?= htmlspecialchars($fu['company']) ?></div>
                  <?php endif; ?>
                  <div class="mt-1 font-monospace text-muted" style="font-size: 0.72rem;">
                    <?= htmlspecialchars($fu['lead_code'] ?? '') ?>
                  </div>
                </div>
              </td>
              <td>
                <span class="badge bg-light text-dark border">
                  <?= htmlspecialchars($fu['project_name'] ?? 'General') ?>
                </span>
              </td>
              <td>
                <div class="fw-bold text-dark" style="font-size: 0.88rem;">
                  <?= date('d M Y', strtotime($fu['next_followup'])) ?>
                </div>
                <div class="mt-1">
                  <span class="badge <?= $fu['badge_class'] ?> rounded-pill px-2 py-1">
                    <?php if ($fu['urgency'] === 'today'): ?>
                      <i class="bi bi-bell-fill me-1"></i>
                    <?php elseif ($fu['urgency'] === 'overdue'): ?>
                      <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <?php else: ?>
                      <i class="bi bi-clock me-1"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($fu['urgency_label']) ?>
                  </span>
                </div>
              </td>
              <td>
                <div class="d-flex align-items-start gap-2">
                  <i class="bi <?= $respTypeIcon ?> fs-6 mt-1 flex-shrink-0"></i>
                  <div style="max-width: 320px;">
                    <div class="text-dark small text-truncate" title="<?= htmlspecialchars($fu['last_response'] ?? '') ?>">
                      <?= htmlspecialchars($fu['last_response'] ?? 'Follow-up scheduled') ?>
                    </div>
                    <div class="text-muted" style="font-size: 0.72rem;">
                      By <?= htmlspecialchars($fu['response_by_name'] ?? 'Agent') ?> &bull; <?= date('d M, h:i A', strtotime($fu['response_date'])) ?>
                    </div>
                  </div>
                </div>
              </td>
              <td class="text-end">
                <div class="btn-group btn-group-sm shadow-sm">
                  <a href="tel:<?= htmlspecialchars($fu['phone']) ?>" class="btn btn-outline-success" title="Direct Phone Call">
                    <i class="bi bi-telephone-fill"></i>
                  </a>
                  <?php if ($cleanPhone): ?>
                  <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" class="btn btn-outline-success" title="WhatsApp Message">
                    <i class="bi bi-whatsapp"></i>
                  </a>
                  <?php endif; ?>
                  <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $fu['lead_id'] ?>" class="btn btn-primary" title="View Lead & Follow Up">
                    <i class="bi bi-arrow-right-circle me-1"></i>Follow Up
                  </a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Mobile Card View -->
      <div class="d-md-none p-3" id="followupsMobileList">
        <?php foreach ($upcomingFollowups as $fu): 
          $cleanPhone = preg_replace('/\D/', '', $fu['phone'] ?? '');
          $borderClass = match($fu['urgency']) {
            'today' => 'border-start border-4 border-warning',
            'overdue' => 'border-start border-4 border-danger',
            default => 'border-start border-4 border-info',
          };
        ?>
        <div class="lead-mobile-card followup-item-mobile <?= $borderClass ?>" data-urgency="<?= $fu['urgency'] ?>">
          <div class="lead-card-header">
            <span class="badge bg-light text-dark border"><?= htmlspecialchars($fu['project_name'] ?? 'General') ?></span>
            <span class="badge <?= $fu['badge_class'] ?> rounded-pill">
              <?= htmlspecialchars($fu['urgency_label']) ?>
            </span>
          </div>
          <div>
            <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $fu['lead_id'] ?>" class="lead-card-title">
              <?= htmlspecialchars($fu['lead_name']) ?>
            </a>
            <?php if (!empty($fu['company'])): ?>
              <div class="text-muted small mt-1"><i class="bi bi-building me-1"></i><?= htmlspecialchars($fu['company']) ?></div>
            <?php endif; ?>
          </div>

          <div class="lead-card-phone-row mt-2">
            <div class="fw-semibold text-dark">
              <i class="bi bi-telephone me-1 text-success"></i><?= htmlspecialchars($fu['phone']) ?>
            </div>
            <div class="fw-bold text-dark small">
              <i class="bi bi-calendar-event me-1 text-primary"></i><?= date('d M Y', strtotime($fu['next_followup'])) ?>
            </div>
          </div>

          <?php if (!empty($fu['last_response'])): ?>
          <div class="bg-light p-2 rounded-2 mb-2 text-dark small border">
            <i class="bi bi-chat-left-quote me-1 text-muted"></i>
            <?= htmlspecialchars(mb_strimwidth($fu['last_response'], 0, 95, '...')) ?>
          </div>
          <?php endif; ?>

          <div class="lead-card-actions">
            <a href="tel:<?= htmlspecialchars($fu['phone']) ?>" class="btn btn-call btn-sm flex-grow-1">
              <i class="bi bi-telephone-fill me-1"></i>Call
            </a>
            <?php if ($cleanPhone): ?>
            <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" class="btn btn-whatsapp btn-sm px-3" title="WhatsApp">
              <i class="bi bi-whatsapp"></i>
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $fu['lead_id'] ?>" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-arrow-right-circle me-1"></i>Follow Up
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Empty state when active filter has 0 items -->
      <div id="followupsFilterEmpty" class="text-center py-4 d-none">
        <i class="bi bi-check2-circle fs-2 text-success"></i>
        <p class="text-muted small mt-2 mb-0" id="followupsFilterEmptyText">No follow-ups match this filter.</p>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- Recent Leads -->
  <?php if (hasAccess('leads') && $recentLeads): ?>
  <div class="<?= $isTelecall ? 'col-12' : 'col-xl-7' ?>">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-funnel me-2 text-primary"></i>Recent Leads</h6>
        <a href="<?= BASE_URL ?>/modules/leads/index.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <!-- Desktop Table View -->
        <div class="table-responsive d-none d-md-block">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr><th>Lead</th><th>Project</th><th>Phone</th><th>Status</th><th>Date</th><th class="text-end">Quick Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentLeads as $lead): 
                $cleanPhone = preg_replace('/\D/', '', $lead['phone'] ?? '');
              ?>
              <tr>
                <td>
                  <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $lead['id'] ?>" class="fw-semibold text-decoration-none">
                    <?= htmlspecialchars($lead['name']) ?>
                  </a>
                  <?php if ($lead['company']): ?>
                  <div class="text-muted small"><?= htmlspecialchars($lead['company']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($lead['project_name'] ?? '') ?></span></td>
                <td>
                  <a href="tel:<?= htmlspecialchars($lead['phone']) ?>" class="text-decoration-none text-dark fw-medium small">
                    <i class="bi bi-telephone me-1 text-success"></i><?= htmlspecialchars($lead['phone']) ?>
                  </a>
                </td>
                <td><?= statusBadge($lead['status']) ?></td>
                <td class="text-muted small"><?= date('d M', strtotime($lead['created_at'])) ?></td>
                <td class="text-end">
                  <div class="btn-group btn-group-sm">
                    <a href="tel:<?= htmlspecialchars($lead['phone']) ?>" class="btn btn-outline-success" title="Direct Call">
                      <i class="bi bi-telephone-fill"></i>
                    </a>
                    <?php if ($cleanPhone): ?>
                    <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" class="btn btn-outline-success" title="WhatsApp Message">
                      <i class="bi bi-whatsapp"></i>
                    </a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $lead['id'] ?>" class="btn btn-outline-primary" title="View Lead">
                      <i class="bi bi-eye"></i>
                    </a>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Mobile Card View -->
        <div class="d-md-none p-3">
          <?php foreach ($recentLeads as $lead): 
            $cleanPhone = preg_replace('/\D/', '', $lead['phone'] ?? '');
          ?>
          <div class="lead-mobile-card">
            <div class="lead-card-header">
              <span class="badge bg-light text-dark border"><?= htmlspecialchars($lead['project_name'] ?? '') ?></span>
              <div><?= statusBadge($lead['status']) ?></div>
            </div>
            <div>
              <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $lead['id'] ?>" class="lead-card-title">
                <?= htmlspecialchars($lead['name']) ?>
              </a>
              <?php if (!empty($lead['company'])): ?>
                <div class="text-muted small"><i class="bi bi-building me-1"></i><?= htmlspecialchars($lead['company']) ?></div>
              <?php endif; ?>
            </div>
            <div class="lead-card-phone-row mt-2">
              <div class="fw-semibold text-dark">
                <i class="bi bi-phone me-1 text-muted"></i><?= htmlspecialchars($lead['phone']) ?>
              </div>
              <small class="text-muted"><?= date('d M', strtotime($lead['created_at'])) ?></small>
            </div>
            <div class="lead-card-actions">
              <a href="tel:<?= htmlspecialchars($lead['phone']) ?>" class="btn btn-call btn-sm flex-grow-1">
                <i class="bi bi-telephone-fill me-1"></i>Call
              </a>
              <?php if ($cleanPhone): ?>
              <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" class="btn btn-whatsapp btn-sm px-3">
                <i class="bi bi-whatsapp"></i>
              </a>
              <?php endif; ?>
              <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $lead['id'] ?>" class="btn btn-outline-primary btn-sm px-3">
                <i class="bi bi-eye"></i>
              </a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recent Payments (or Recent Quotations for CSM) -->
  <?php if ((hasAccess('invoices') || hasAccess('payments')) && $recentPayments): ?>
  <div class="col-xl-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-cash-stack me-2 text-success"></i>Recent Payments</h6>
        <a href="<?= BASE_URL ?>/modules/payments/index.php" class="btn btn-sm btn-outline-success">View All</a>
      </div>
      <div class="card-body p-0">
        <!-- Desktop Table View -->
        <div class="table-responsive d-none d-md-block">
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

        <!-- Mobile List View -->
        <div class="d-md-none p-3">
          <?php foreach ($recentPayments as $p): ?>
          <div class="d-flex align-items-center justify-content-between py-2 border-bottom last-border-0">
            <div class="min-w-0 pe-2">
              <div class="fw-semibold text-dark text-truncate small"><?= htmlspecialchars($p['client_name']) ?></div>
              <div class="text-muted font-monospace" style="font-size: 0.72rem;">
                <i class="bi bi-receipt me-1"></i><?= htmlspecialchars($p['invoice_no'] ?: 'Direct') ?> &bull; <?= date('d M Y', strtotime($p['payment_date'])) ?>
              </div>
            </div>
            <div class="text-end flex-shrink-0">
              <span class="fw-bold text-success fs-6">₹<?= number_format($p['amount'], 2) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php elseif (hasAccess('quotations') && !empty($recentQuotations)): ?>
  <div class="col-xl-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-calculator me-2 text-warning"></i><?= $isCsm ? 'My Recent Quotations' : 'Recent Quotations' ?></h6>
        <a href="<?= BASE_URL ?>/modules/quotations/index.php" class="btn btn-sm btn-outline-warning">View All</a>
      </div>
      <div class="card-body p-0">
        <!-- Desktop Table View -->
        <div class="table-responsive d-none d-md-block">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Quotation</th><th>Contact</th><th>Amount</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentQuotations as $q): ?>
              <tr>
                <td>
                  <a href="<?= BASE_URL ?>/modules/quotations/view.php?id=<?= $q['id'] ?>" class="fw-semibold text-decoration-none">
                    <?= htmlspecialchars($q['quotation_no']) ?>
                  </a>
                  <div class="text-muted small"><?= htmlspecialchars($q['title']) ?></div>
                </td>
                <td class="small"><?= htmlspecialchars($q['contact_name'] ?? 'N/A') ?></td>
                <td class="text-primary fw-semibold small">₹<?= number_format($q['total_amount'], 2) ?></td>
                <td><?= statusBadge($q['status']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Mobile List View -->
        <div class="d-md-none p-3">
          <?php foreach ($recentQuotations as $q): ?>
          <div class="d-flex align-items-center justify-content-between py-2 border-bottom last-border-0">
            <div class="min-w-0 pe-2">
              <a href="<?= BASE_URL ?>/modules/quotations/view.php?id=<?= $q['id'] ?>" class="fw-semibold text-dark text-decoration-none small d-block text-truncate">
                <?= htmlspecialchars($q['quotation_no']) ?> — <?= htmlspecialchars($q['title']) ?>
              </a>
              <div class="text-muted" style="font-size: 0.72rem;">
                <?= htmlspecialchars($q['contact_name'] ?? 'N/A') ?>
              </div>
            </div>
            <div class="text-end flex-shrink-0">
              <div class="fw-bold text-primary small">₹<?= number_format($q['total_amount'], 2) ?></div>
              <div class="mt-1"><?= statusBadge($q['status']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function filterFollowups(type, btn) {
  // Update active pill button
  document.querySelectorAll('.followup-filter-btn').forEach(b => {
    b.classList.remove('btn-primary', 'active', 'text-white');
    b.classList.add('text-dark');
  });
  if (btn) {
    btn.classList.add('btn-primary', 'active', 'text-white');
    btn.classList.remove('text-dark');
  }

  // Filter desktop rows
  let visibleCount = 0;
  document.querySelectorAll('.followup-item-row').forEach(row => {
    const urgency = row.getAttribute('data-urgency');
    if (type === 'all' || urgency === type) {
      row.style.display = '';
      visibleCount++;
    } else {
      row.style.display = 'none';
    }
  });

  // Filter mobile cards
  document.querySelectorAll('.followup-item-mobile').forEach(card => {
    const urgency = card.getAttribute('data-urgency');
    if (type === 'all' || urgency === type) {
      card.style.display = '';
    } else {
      card.style.display = 'none';
    }
  });

  // Handle empty state
  const emptyWrap = document.getElementById('followupsFilterEmpty');
  const emptyText = document.getElementById('followupsFilterEmptyText');
  if (emptyWrap) {
    if (visibleCount === 0) {
      emptyWrap.classList.remove('d-none');
      if (type === 'today') {
        emptyText.textContent = 'No follow-ups due today. You are all caught up!';
      } else if (type === 'overdue') {
        emptyText.textContent = 'Awesome! No overdue follow-ups.';
      } else if (type === 'upcoming') {
        emptyText.textContent = 'No future follow-ups scheduled yet.';
      } else {
        emptyText.textContent = 'No follow-ups match this filter.';
      }
    } else {
      emptyWrap.classList.add('d-none');
    }
  }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
