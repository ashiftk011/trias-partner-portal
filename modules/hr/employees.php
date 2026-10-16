<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('hr');

$db = getDB();

restorePageFilters('employees', ['status', 'department', 'type', 'q']);

$filterStatus     = $_GET['status'] ?? '';
$filterDepartment = $_GET['department'] ?? '';
$filterType       = $_GET['type'] ?? '';
$filterSearch     = trim($_GET['q'] ?? '');

$sql = "SELECT e.*, s.basic_salary, s.net_salary,
        DATEDIFF(e.contract_end_date, CURDATE()) as contract_days_left
        FROM employees e
        LEFT JOIN salary_structures s ON s.employee_id = e.id
        WHERE 1=1";
$params = [];

if ($filterStatus) {
    $sql .= " AND e.status = ?";
    $params[] = $filterStatus;
}
if ($filterDepartment) {
    $sql .= " AND e.department = ?";
    $params[] = $filterDepartment;
}
if ($filterType === 'contractors') {
    $sql .= " AND e.employment_type IN ('contract', 'freelance')";
} elseif ($filterType) {
    $sql .= " AND e.employment_type = ?";
    $params[] = $filterType;
}
if ($filterSearch) {
    $sql .= " AND (e.name LIKE ? OR e.email LIKE ? OR e.emp_number LIKE ? OR e.designation LIKE ? OR e.phone LIKE ?)";
    $params = array_merge($params, ["%$filterSearch%", "%$filterSearch%", "%$filterSearch%", "%$filterSearch%", "%$filterSearch%"]);
}
$sql .= " ORDER BY e.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

// Summary counts by status
$statusCounts = [];
$countsStmt = $db->query("SELECT status, COUNT(*) as cnt FROM employees GROUP BY status");
foreach ($countsStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = $row['cnt'];
}
$totalEmployees = array_sum($statusCounts);

// Summary counts by employment type
$typeCounts = ['full_time' => 0, 'contract' => 0, 'freelance' => 0, 'intern' => 0];
$tCountsStmt = $db->query("SELECT employment_type, COUNT(*) as cnt FROM employees GROUP BY employment_type");
foreach ($tCountsStmt->fetchAll() as $row) {
    $typeCounts[$row['employment_type']] = (int)$row['cnt'];
}
$contractorsTotal = ($typeCounts['contract'] ?? 0) + ($typeCounts['freelance'] ?? 0);

// Check for contracts expiring within 30 days or already expired
$expiringStmt = $db->query("SELECT id, name, emp_number, employment_type, contract_end_date, DATEDIFF(contract_end_date, CURDATE()) as days_left 
                            FROM employees 
                            WHERE employment_type IN ('contract','freelance') 
                              AND status='active' 
                              AND contract_end_date IS NOT NULL 
                              AND contract_end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
                            ORDER BY contract_end_date ASC");
$expiringContracts = $expiringStmt->fetchAll();

// Fetch departments for filter
$deptStmt = $db->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = ($filterType === 'contractors' ? 'HR Portal — Contract & Freelance' : 'HR Portal — Employees Directory');
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">
      <?php if ($filterType === 'contractors'): ?>
        <i class="bi bi-briefcase-fill me-2 text-warning"></i>Contract & Freelance Workforce
      <?php else: ?>
        <i class="bi bi-person-badge-fill me-2 text-primary"></i>Personnel Directory
      <?php endif; ?>
    </h4>
    <p class="text-muted small mb-0">Manage full-time staff, fixed-term contractors, freelance consultants, and retainers</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/modules/hr/payroll.php" class="btn btn-outline-primary">
      <i class="bi bi-wallet2 me-1"></i>Salary & Retainers
    </a>
    <a href="<?= BASE_URL ?>/modules/hr/save_employee.php" class="btn btn-primary">
      <i class="bi bi-plus-circle me-1"></i>Add Personnel
    </a>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Expiry Alert Banner if any contracts are ending soon -->
<?php if (!empty($expiringContracts)): ?>
  <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill fs-4 text-warning"></i>
      <div>
        <div class="fw-bold text-dark">Contract Attention Required (<?= count($expiringContracts) ?> Personnel)</div>
        <div class="small text-muted">
          <?php 
          $expList = [];
          foreach (array_slice($expiringContracts, 0, 3) as $exp) {
              $badgeText = $exp['days_left'] < 0 ? 'Expired' : ($exp['days_left'] . 'd left');
              $expList[] = htmlspecialchars($exp['name']) . " (" . $badgeText . ")";
          }
          echo implode(', ', $expList);
          if (count($expiringContracts) > 3) echo " and " . (count($expiringContracts) - 3) . " more";
          ?>
        </div>
      </div>
    </div>
    <a href="?type=contractors&status=active" class="btn btn-sm btn-outline-dark fw-semibold">Review Contracts</a>
  </div>
<?php endif; ?>

<!-- Overview Cards by Employment Type -->
<div class="row g-3 mb-4">
  <div class="col-xl-3 col-sm-6">
    <div class="card border-0 shadow-sm bg-primary bg-opacity-10 h-100">
      <div class="card-body p-3 d-flex align-items-center justify-content-between">
        <div>
          <span class="text-primary small fw-semibold text-uppercase">Full-Time Staff</span>
          <h3 class="mb-0 text-primary fw-bold mt-1"><?= $typeCounts['full_time'] ?? 0 ?></h3>
        </div>
        <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center shadow-sm" style="width:48px;height:48px;">
          <i class="bi bi-person-workspace fs-4"></i>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-sm-6">
    <div class="card border-0 shadow-sm bg-warning bg-opacity-10 h-100">
      <div class="card-body p-3 d-flex align-items-center justify-content-between">
        <div>
          <span class="text-warning-emphasis small fw-semibold text-uppercase">Contract Staff</span>
          <h3 class="mb-0 text-dark fw-bold mt-1"><?= $typeCounts['contract'] ?? 0 ?></h3>
        </div>
        <div class="rounded-circle bg-white text-warning d-flex align-items-center justify-content-center shadow-sm" style="width:48px;height:48px;">
          <i class="bi bi-file-earmark-text fs-4"></i>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-sm-6">
    <div class="card border-0 shadow-sm bg-info bg-opacity-10 h-100">
      <div class="card-body p-3 d-flex align-items-center justify-content-between">
        <div>
          <span class="text-info-emphasis small fw-semibold text-uppercase">Freelance Consultants</span>
          <h3 class="mb-0 text-dark fw-bold mt-1"><?= $typeCounts['freelance'] ?? 0 ?></h3>
        </div>
        <div class="rounded-circle bg-white text-info d-flex align-items-center justify-content-center shadow-sm" style="width:48px;height:48px;">
          <i class="bi bi-laptop fs-4"></i>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-sm-6">
    <div class="card border-0 shadow-sm bg-secondary bg-opacity-10 h-100">
      <div class="card-body p-3 d-flex align-items-center justify-content-between">
        <div>
          <span class="text-secondary small fw-semibold text-uppercase">Interns & Trainees</span>
          <h3 class="mb-0 text-secondary fw-bold mt-1"><?= $typeCounts['intern'] ?? 0 ?></h3>
        </div>
        <div class="rounded-circle bg-white text-secondary d-flex align-items-center justify-content-center shadow-sm" style="width:48px;height:48px;">
          <i class="bi bi-mortarboard fs-4"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Type Filter Tabs -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div class="d-flex flex-wrap gap-2">
    <a href="?status=<?= urlencode($filterStatus) ?>&department=<?= urlencode($filterDepartment) ?>" 
       class="btn btn-sm <?= !$filterType ? 'btn-primary' : 'btn-light border' ?>">
      All Personnel <span class="badge bg-white text-dark ms-1"><?= $totalEmployees ?></span>
    </a>
    <a href="?type=full_time&status=<?= urlencode($filterStatus) ?>&department=<?= urlencode($filterDepartment) ?>" 
       class="btn btn-sm <?= $filterType==='full_time' ? 'btn-primary' : 'btn-light border' ?>">
      <i class="bi bi-person-workspace me-1"></i>Full-Time <span class="badge bg-white text-dark ms-1"><?= $typeCounts['full_time'] ?? 0 ?></span>
    </a>
    <a href="?type=contractors&status=<?= urlencode($filterStatus) ?>&department=<?= urlencode($filterDepartment) ?>" 
       class="btn btn-sm <?= $filterType==='contractors' ? 'btn-warning text-dark fw-semibold' : 'btn-light border' ?>">
      <i class="bi bi-briefcase me-1"></i>All Contractors & Freelance <span class="badge bg-dark text-white ms-1"><?= $contractorsTotal ?></span>
    </a>
    <a href="?type=contract&status=<?= urlencode($filterStatus) ?>&department=<?= urlencode($filterDepartment) ?>" 
       class="btn btn-sm <?= $filterType==='contract' ? 'btn-warning text-dark fw-semibold' : 'btn-light border' ?>">
      Contract <span class="badge bg-white text-dark ms-1"><?= $typeCounts['contract'] ?? 0 ?></span>
    </a>
    <a href="?type=freelance&status=<?= urlencode($filterStatus) ?>&department=<?= urlencode($filterDepartment) ?>" 
       class="btn btn-sm <?= $filterType==='freelance' ? 'btn-info text-white' : 'btn-light border' ?>">
      Freelance <span class="badge bg-white text-dark ms-1"><?= $typeCounts['freelance'] ?? 0 ?></span>
    </a>
    <a href="?type=intern&status=<?= urlencode($filterStatus) ?>&department=<?= urlencode($filterDepartment) ?>" 
       class="btn btn-sm <?= $filterType==='intern' ? 'btn-secondary' : 'btn-light border' ?>">
      Interns <span class="badge bg-white text-dark ms-1"><?= $typeCounts['intern'] ?? 0 ?></span>
    </a>
  </div>

  <!-- Status Badges -->
  <div class="d-flex flex-wrap gap-1">
    <a href="?type=<?= urlencode($filterType) ?>&department=<?= urlencode($filterDepartment) ?>" 
       class="badge bg-<?= !$filterStatus ? 'dark' : 'light text-dark border' ?> text-decoration-none p-2">
      All Statuses
    </a>
    <a href="?type=<?= urlencode($filterType) ?>&status=active&department=<?= urlencode($filterDepartment) ?>" 
       class="badge bg-<?= $filterStatus==='active' ? 'success' : 'light text-success border' ?> text-decoration-none p-2">
      Active (<?= $statusCounts['active']??0 ?>)
    </a>
    <a href="?type=<?= urlencode($filterType) ?>&status=inactive&department=<?= urlencode($filterDepartment) ?>" 
       class="badge bg-<?= $filterStatus==='inactive' ? 'secondary' : 'light text-secondary border' ?> text-decoration-none p-2">
      Inactive (<?= $statusCounts['inactive']??0 ?>)
    </a>
    <a href="?type=<?= urlencode($filterType) ?>&status=resigned&department=<?= urlencode($filterDepartment) ?>" 
       class="badge bg-<?= $filterStatus==='resigned' ? 'warning text-dark' : 'light text-warning border' ?> text-decoration-none p-2">
      Resigned (<?= $statusCounts['resigned']??0 ?>)
    </a>
    <a href="?type=<?= urlencode($filterType) ?>&status=terminated&department=<?= urlencode($filterDepartment) ?>" 
       class="badge bg-<?= $filterStatus==='terminated' ? 'danger' : 'light text-danger border' ?> text-decoration-none p-2">
      Terminated (<?= $statusCounts['terminated']??0 ?>)
    </a>
  </div>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="type" value="<?= htmlspecialchars($filterType) ?>">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
      <div class="col-md-5">
        <label class="form-label small text-muted mb-0">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by name, emp code, email, designation, phone..." value="<?= htmlspecialchars($filterSearch) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted mb-0">Department</label>
        <select name="department" class="form-select form-select-sm">
          <option value="">All Departments</option>
          <?php foreach ($departments as $dept): ?>
            <option value="<?= htmlspecialchars($dept) ?>" <?= $filterDepartment===$dept?'selected':'' ?>><?= htmlspecialchars($dept) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
        <?php if ($filterSearch || $filterDepartment || $filterStatus || $filterType): ?>
          <a href="?clear=1" class="btn btn-sm btn-outline-secondary ms-1">Reset All</a>
        <?php endif; ?>
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
            <th>Code</th>
            <th>Name & Contact</th>
            <th>Designation & Dept</th>
            <th>Employment Type</th>
            <th>Tenure / Contract Term</th>
            <th>Net Monthly Payout</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($employees)): ?>
            <tr>
              <td colspan="8" class="text-center text-muted py-4">No personnel records found matching current filters.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($employees as $emp): ?>
              <tr>
                <td class="fw-bold font-monospace text-primary"><?= htmlspecialchars($emp['emp_number']) ?></td>
                <td>
                  <a href="<?= BASE_URL ?>/modules/hr/view_employee.php?id=<?= $emp['id'] ?>" class="fw-semibold text-dark text-decoration-none">
                    <?= htmlspecialchars($emp['name']) ?>
                  </a>
                  <div class="small text-muted">
                    <a href="mailto:<?= htmlspecialchars($emp['email']) ?>" class="text-decoration-none text-muted me-2">
                      <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($emp['email']) ?>
                    </a>
                    <?php if ($emp['phone']): ?>
                      <span><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($emp['phone']) ?></span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="fw-semibold small"><?= htmlspecialchars($emp['designation']) ?></div>
                  <span class="badge bg-light text-secondary border font-monospace" style="font-size:0.7rem;"><?= htmlspecialchars($emp['department'] ?: 'General') ?></span>
                </td>
                <td>
                  <?= employmentTypeBadge($emp['employment_type'] ?? 'full_time') ?>
                  <?php if (!empty($emp['contract_rate_type']) && in_array($emp['employment_type'], ['contract','freelance'])): ?>
                    <div class="text-muted" style="font-size:0.7rem;">
                      <?= ucfirst(str_replace('_',' ', $emp['contract_rate_type'])) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="small">
                  <?php if (in_array($emp['employment_type'], ['contract', 'freelance'])): ?>
                    <div><span class="text-muted">Start:</span> <?= date('d M Y', strtotime($emp['contract_start_date'] ?: $emp['joining_date'])) ?></div>
                    <?php if (!empty($emp['contract_end_date'])): ?>
                      <div>
                        <span class="text-muted">End:</span> <?= date('d M Y', strtotime($emp['contract_end_date'])) ?>
                        <?php if ($emp['contract_days_left'] < 0): ?>
                          <span class="badge bg-danger ms-1" style="font-size:0.65rem;">Expired</span>
                        <?php elseif ($emp['contract_days_left'] <= 30): ?>
                          <span class="badge bg-warning text-dark ms-1" style="font-size:0.65rem;"><?= $emp['contract_days_left'] ?>d left</span>
                        <?php else: ?>
                          <span class="badge bg-success-subtle text-success border border-success-subtle ms-1" style="font-size:0.65rem;"><?= $emp['contract_days_left'] ?>d left</span>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <span class="badge bg-light text-muted border" style="font-size:0.7rem;">Ongoing Retainer</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <div class="text-nowrap"><span class="text-muted">Joined:</span> <?= date('d M Y', strtotime($emp['joining_date'])) ?></div>
                  <?php endif; ?>
                </td>
                <td class="fw-bold text-success text-nowrap">
                  <?php if ($emp['net_salary'] > 0): ?>
                    ₹<?= number_format($emp['net_salary'], 2) ?>
                    <div class="text-muted fw-normal" style="font-size:0.7rem;">
                      <?= in_array($emp['employment_type'], ['contract','freelance']) ? 'Retainer' : 'Monthly' ?>
                    </div>
                  <?php elseif ($emp['contract_rate'] > 0): ?>
                    ₹<?= number_format($emp['contract_rate'], 2) ?>
                    <div class="text-muted fw-normal" style="font-size:0.7rem;">
                      Agreed Rate
                    </div>
                  <?php else: ?>
                    <span class="text-muted fw-normal small">Not Configured</span>
                  <?php endif; ?>
                </td>
                <td><?= statusBadge($emp['status']) ?></td>
                <td class="text-center text-nowrap">
                  <a href="<?= BASE_URL ?>/modules/hr/view_employee.php?id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-info" title="View Details"><i class="bi bi-eye"></i></a>
                  <a href="<?= BASE_URL ?>/modules/hr/save_employee.php?id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Personnel"><i class="bi bi-pencil"></i></a>
                  <a href="<?= BASE_URL ?>/modules/hr/payroll.php?employee_id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-success" title="Process Payslip / Retainer"><i class="bi bi-receipt"></i></a>
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
