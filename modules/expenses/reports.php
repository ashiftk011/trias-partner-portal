<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/accounting.php';
require_once __DIR__ . '/../../includes/expense_helpers.php';
requireAccess('expenses');

$db = getDB();

restorePageFilters('expense_reports', ['view', 'from_date', 'to_date', 'project_id', 'status_filter']);

$activeTab     = trim($_GET['view'] ?? 'client'); // 'client' or 'project'
$fromDate      = trim($_GET['from_date'] ?? '');
$toDate        = trim($_GET['to_date'] ?? '');
$filterProject = (int)($_GET['project_id'] ?? 0);
$statusFilter  = trim($_GET['status_filter'] ?? ''); // 'profit', 'loss', ''

$allProjects = $db->query("SELECT id, name FROM projects ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Data based on active tab
$clientFinancials = [];
$projectFinancials = [];

if ($activeTab === 'project') {
    $projectFinancials = getAllProjectsFinancials($fromDate, $toDate);
    if ($statusFilter) {
        $projectFinancials = array_values(array_filter($projectFinancials, fn($p) => $p['profit_status'] === $statusFilter));
    }
} else {
    $clientFinancials = getAllClientsFinancials($fromDate, $toDate, $filterProject);
    if ($statusFilter) {
        $clientFinancials = array_values(array_filter($clientFinancials, fn($c) => $c['profit_status'] === $statusFilter));
    }
}

// KPI Aggregates for Client View
$totalClientInvoiced = 0.00;
$totalClientCollected = 0.00;
$totalClientExpenses = 0.00;
$totalClientProfit = 0.00;
$profitableClientsCount = 0;
$lossClientsCount = 0;

if ($activeTab === 'client') {
    foreach ($clientFinancials as $c) {
        $totalClientInvoiced += $c['total_invoiced'];
        $totalClientCollected += $c['total_collected'];
        $totalClientExpenses += $c['total_expenses'];
        $totalClientProfit += $c['profit_invoiced'];
        if ($c['profit_status'] === 'profit') $profitableClientsCount++;
        if ($c['profit_status'] === 'loss') $lossClientsCount++;
    }
}

// KPI Aggregates for Project View
$totalProjectRevenue = 0.00;
$totalProjectCosts = 0.00;
$totalProjectProfit = 0.00;
$profitableProjectsCount = 0;
$lossProjectsCount = 0;

if ($activeTab === 'project') {
    foreach ($projectFinancials as $p) {
        $totalProjectRevenue += $p['total_revenue'];
        $totalProjectCosts += $p['total_expenses'];
        $totalProjectProfit += $p['net_profit'];
        if ($p['profit_status'] === 'profit') $profitableProjectsCount++;
        if ($p['profit_status'] === 'loss') $lossProjectsCount++;
    }
}

$pageTitle = ($activeTab === 'project' ? 'Project' : 'Client') . ' Profitability & P&L Reports';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 no-print">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Client &amp; Project Profitability Reports</h4>
    <p class="text-muted small mb-0">Evaluate client and project revenue against direct allocated costs to identify profitability and loss</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/modules/expenses/index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Expenses Register
    </a>
    <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print Report
    </button>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Sub Navigation Tabs -->
<ul class="nav nav-pills mb-4 gap-2 bg-white p-2 rounded-3 shadow-sm border no-print expense-nav-scroll">
  <li class="nav-item text-nowrap">
    <a class="nav-link text-dark fw-semibold" href="<?= BASE_URL ?>/modules/expenses/index.php">
      <i class="bi bi-receipt me-1"></i>Expense Register
    </a>
  </li>
  <li class="nav-item text-nowrap">
    <a class="nav-link active fw-semibold" href="<?= BASE_URL ?>/modules/expenses/reports.php">
      <i class="bi bi-bar-chart-steps me-1"></i>Client &amp; Project Profitability
    </a>
  </li>
  <li class="nav-item text-nowrap">
    <a class="nav-link text-dark fw-semibold" href="<?= BASE_URL ?>/modules/expenses/assets.php">
      <i class="bi bi-laptop me-1"></i>Company Assets (Separate)
    </a>
  </li>
</ul>

<!-- View Selector (Client vs Project) -->
<div class="card border-0 shadow-sm mb-4 no-print">
  <div class="card-header bg-white p-2 border-bottom-0">
    <ul class="nav nav-pills nav-fill gap-2">
      <li class="nav-item">
        <a class="nav-link fw-bold py-2 <?= $activeTab === 'client' ? 'active' : '' ?>" 
           href="?view=client&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>&project_id=<?= $filterProject ?>">
          <i class="bi bi-people-fill me-2"></i>Client Profitability &amp; Cost Analysis
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link fw-bold py-2 <?= $activeTab === 'project' ? 'active' : '' ?>" 
           href="?view=project&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>">
          <i class="bi bi-folder2-open me-2"></i>Project Profitability &amp; Margin Report
        </a>
      </li>
    </ul>
  </div>

  <!-- Filter Bar -->
  <div class="card-body bg-light border-top py-3">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="view" value="<?= htmlspecialchars($activeTab) ?>">

      <div class="col-md-3 col-6">
        <label class="form-label small fw-semibold mb-1">From Date</label>
        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
      </div>
      <div class="col-md-3 col-6">
        <label class="form-label small fw-semibold mb-1">To Date</label>
        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
      </div>

      <?php if ($activeTab === 'client'): ?>
      <div class="col-md-3 col-6">
        <label class="form-label small fw-semibold mb-1">Filter by Project</label>
        <select name="project_id" class="form-select form-select-sm">
          <option value="0">All Projects</option>
          <?php foreach ($allProjects as $pr): ?>
            <option value="<?= $pr['id'] ?>" <?= $filterProject === (int)$pr['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pr['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="col-md-<?= $activeTab === 'client' ? '2' : '4' ?> col-6">
        <label class="form-label small fw-semibold mb-1">Profitability Status</label>
        <select name="status_filter" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="profit" <?= $statusFilter === 'profit' ? 'selected' : '' ?>>Profitable Only</option>
          <option value="loss" <?= $statusFilter === 'loss' ? 'selected' : '' ?>>Loss-Making Only</option>
        </select>
      </div>

      <div class="col-md-1 col-12 d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-primary w-100" title="Apply Filter"><i class="bi bi-filter"></i> Apply</button>
        <?php if ($fromDate || $toDate || $filterProject || $statusFilter): ?>
          <a href="?view=<?= htmlspecialchars($activeTab) ?>&clear=1" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-circle"></i></a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if ($activeTab === 'client'): ?>
  <!-- ================= CLIENT PROFITABILITY VIEW ================= -->

  <!-- KPI Summary Cards -->
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm stat-card h-100">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <div class="stat-icon rounded-3 bg-primary bg-opacity-10 text-primary p-3">
            <i class="bi bi-receipt-cutoff fs-3"></i>
          </div>
          <div>
            <div class="fs-4 fw-bold text-primary">₹<?= number_format($totalClientInvoiced, 2) ?></div>
            <div class="text-muted small">Total Invoiced Revenue</div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm stat-card h-100">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <div class="stat-icon rounded-3 bg-danger bg-opacity-10 text-danger p-3">
            <i class="bi bi-hdd-network fs-3"></i>
          </div>
          <div>
            <div class="fs-4 fw-bold text-danger">₹<?= number_format($totalClientExpenses, 2) ?></div>
            <div class="text-muted small">Total Client Direct Costs (Servers, etc)</div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm stat-card h-100">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <div class="stat-icon rounded-3 <?= $totalClientProfit >= 0 ? 'bg-success text-success' : 'bg-danger text-danger' ?> bg-opacity-10 p-3">
            <i class="bi <?= $totalClientProfit >= 0 ? 'bi-graph-up-arrow' : 'bi-graph-down-arrow' ?> fs-3"></i>
          </div>
          <div>
            <div class="fs-4 fw-bold <?= $totalClientProfit >= 0 ? 'text-success' : 'text-danger' ?>">
              ₹<?= number_format($totalClientProfit, 2) ?>
            </div>
            <div class="text-muted small">Net Invoiced Profit / Loss</div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm stat-card h-100">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <div class="stat-icon rounded-3 bg-info bg-opacity-10 text-info p-3">
            <i class="bi bi-speedometer2 fs-3"></i>
          </div>
          <div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-success-subtle text-success border fs-6"><?= $profitableClientsCount ?> Profit</span>
              <span class="badge bg-danger-subtle text-danger border fs-6"><?= $lossClientsCount ?> Loss</span>
            </div>
            <div class="text-muted small mt-1">Client Health Ratio (<?= count($clientFinancials) ?> Total)</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Client Table -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-0">
      <h6 class="mb-0 fw-bold"><i class="bi bi-table me-2 text-primary"></i>Client-wise Earnings vs Expenses &amp; Profit/Loss</h6>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 datatable align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Client Name / Code</th>
              <th>Project</th>
              <th class="text-end">Invoiced (₹)</th>
              <th class="text-end">Collected (₹)</th>
              <th class="text-end">Direct Cost (₹)</th>
              <th class="text-end">Net Profit / Loss (₹)</th>
              <th class="text-center">Margin</th>
              <th class="text-center">Status</th>
              <th class="text-center no-print">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($clientFinancials)): ?>
              <tr>
                <td colspan="10" class="text-center text-muted py-4">No client records found matching the criteria.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($clientFinancials as $idx => $c): ?>
                <tr>
                  <td><?= $idx + 1 ?></td>
                  <td>
                    <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $c['id'] ?>" class="fw-bold text-decoration-none text-dark">
                      <?= htmlspecialchars($c['name']) ?>
                    </a>
                    <?php if ($c['company']): ?>
                      <div class="small text-muted"><i class="bi bi-building me-1"></i><?= htmlspecialchars($c['company']) ?></div>
                    <?php endif; ?>
                    <?php if ($c['client_code']): ?>
                      <code class="small text-secondary"><?= htmlspecialchars($c['client_code']) ?></code>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($c['project_name'] ?: 'None') ?></span>
                  </td>
                  <td class="text-end fw-semibold text-primary">₹<?= number_format($c['total_invoiced'], 2) ?></td>
                  <td class="text-end fw-semibold text-success">₹<?= number_format($c['total_collected'], 2) ?></td>
                  <td class="text-end fw-semibold text-danger">
                    ₹<?= number_format($c['total_expenses'], 2) ?>
                    <?php if ($c['expense_count'] > 0): ?>
                      <span class="badge bg-danger-subtle text-danger border rounded-pill ms-1"><?= $c['expense_count'] ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end fw-bold fs-6 <?= $c['profit_invoiced'] >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $c['profit_invoiced'] >= 0 ? '+' : '' ?>₹<?= number_format($c['profit_invoiced'], 2) ?>
                  </td>
                  <td class="text-center">
                    <span class="badge <?= $c['margin_pct'] >= 40 ? 'bg-success' : ($c['margin_pct'] > 0 ? 'bg-primary' : 'bg-danger') ?>">
                      <?= $c['margin_pct'] ?>%
                    </span>
                  </td>
                  <td class="text-center">
                    <?php if ($c['profit_status'] === 'profit'): ?>
                      <span class="badge bg-success-subtle text-success border border-success border-opacity-50">
                        <i class="bi bi-check-circle-fill me-1"></i>Profitable
                      </span>
                    <?php elseif ($c['profit_status'] === 'loss'): ?>
                      <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-50">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Loss Making
                      </span>
                    <?php else: ?>
                      <span class="badge bg-light text-muted border">Break-Even</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center no-print text-nowrap">
                    <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $c['id'] ?>#profitability" class="btn btn-sm btn-outline-primary" title="View Full Client Breakdown">
                      <i class="bi bi-journal-text me-1"></i>Breakdown
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- ================= PROJECT PROFITABILITY VIEW ================= -->

  <!-- KPI Summary Cards -->
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm stat-card h-100">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <div class="stat-icon rounded-3 bg-primary bg-opacity-10 text-primary p-3">
            <i class="bi bi-folder-check fs-3"></i>
          </div>
          <div>
            <div class="fs-4 fw-bold text-primary">₹<?= number_format($totalProjectRevenue, 2) ?></div>
            <div class="text-muted small">Total Project Revenue</div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm stat-card h-100">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <div class="stat-icon rounded-3 bg-danger bg-opacity-10 text-danger p-3">
            <i class="bi bi-cash-stack fs-3"></i>
          </div>
          <div>
            <div class="fs-4 fw-bold text-danger">₹<?= number_format($totalProjectCosts, 2) ?></div>
            <div class="text-muted small">Total Project Cost (Direct + Client)</div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm stat-card h-100">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <div class="stat-icon rounded-3 <?= $totalProjectProfit >= 0 ? 'bg-success text-success' : 'bg-danger text-danger' ?> bg-opacity-10 p-3">
            <i class="bi <?= $totalProjectProfit >= 0 ? 'bi-graph-up-arrow' : 'bi-graph-down-arrow' ?> fs-3"></i>
          </div>
          <div>
            <div class="fs-4 fw-bold <?= $totalProjectProfit >= 0 ? 'text-success' : 'text-danger' ?>">
              ₹<?= number_format($totalProjectProfit, 2) ?>
            </div>
            <div class="text-muted small">Net Project Profit / Loss</div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card border-0 shadow-sm stat-card h-100">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <div class="stat-icon rounded-3 bg-info bg-opacity-10 text-info p-3">
            <i class="bi bi-kanban fs-3"></i>
          </div>
          <div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-success-subtle text-success border fs-6"><?= $profitableProjectsCount ?> Profit</span>
              <span class="badge bg-danger-subtle text-danger border fs-6"><?= $lossProjectsCount ?> Loss</span>
            </div>
            <div class="text-muted small mt-1">Projects Health (<?= count($projectFinancials) ?> Total)</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Projects Table -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-0">
      <h6 class="mb-0 fw-bold"><i class="bi bi-table me-2 text-primary"></i>Project Profitability, Direct Overhead &amp; Client Cost Analysis</h6>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 datatable align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Project Name</th>
              <th>Status</th>
              <th class="text-center">Clients</th>
              <th class="text-end">Total Revenue (₹)</th>
              <th class="text-end">Direct Project Cost (₹)</th>
              <th class="text-end">Client Costs (₹)</th>
              <th class="text-end">Total Expenses (₹)</th>
              <th class="text-end">Net Profit / Loss (₹)</th>
              <th class="text-center">Margin</th>
              <th class="text-center">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($projectFinancials)): ?>
              <tr>
                <td colspan="11" class="text-center text-muted py-4">No project records found matching criteria.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($projectFinancials as $idx => $p): ?>
                <tr>
                  <td><?= $idx + 1 ?></td>
                  <td class="fw-bold text-dark">
                    <?= htmlspecialchars($p['name']) ?>
                    <?php if (!empty($p['description'])): ?>
                      <small class="text-muted text-truncate d-block" style="max-width:220px;"><?= htmlspecialchars($p['description']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td><?= statusBadge($p['status']) ?></td>
                  <td class="text-center">
                    <a href="?view=client&project_id=<?= $p['id'] ?>" class="badge bg-info-subtle text-info border text-decoration-none">
                      <?= number_format($p['client_count']) ?> Clients
                    </a>
                  </td>
                  <td class="text-end fw-semibold text-primary">₹<?= number_format($p['total_revenue'], 2) ?></td>
                  <td class="text-end fw-semibold text-danger">₹<?= number_format($p['direct_expenses'], 2) ?></td>
                  <td class="text-end fw-semibold text-danger">₹<?= number_format($p['client_expenses'], 2) ?></td>
                  <td class="text-end fw-bold text-danger">₹<?= number_format($p['total_expenses'], 2) ?></td>
                  <td class="text-end fw-bold fs-6 <?= $p['net_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $p['net_profit'] >= 0 ? '+' : '' ?>₹<?= number_format($p['net_profit'], 2) ?>
                  </td>
                  <td class="text-center">
                    <span class="badge <?= $p['margin_pct'] >= 40 ? 'bg-success' : ($p['margin_pct'] > 0 ? 'bg-primary' : 'bg-danger') ?>">
                      <?= $p['margin_pct'] ?>%
                    </span>
                  </td>
                  <td class="text-center">
                    <?php if ($p['profit_status'] === 'profit'): ?>
                      <span class="badge bg-success-subtle text-success border border-success border-opacity-50">
                        <i class="bi bi-check-circle-fill me-1"></i>Profitable
                      </span>
                    <?php elseif ($p['profit_status'] === 'loss'): ?>
                      <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-50">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Loss Making
                      </span>
                    <?php else: ?>
                      <span class="badge bg-light text-muted border">Break-Even</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
