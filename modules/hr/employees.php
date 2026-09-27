<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('hr');

$db = getDB();

$filterStatus     = $_GET['status'] ?? '';
$filterDepartment = $_GET['department'] ?? '';
$filterSearch     = trim($_GET['q'] ?? '');

$sql = "SELECT e.*, s.basic_salary, s.net_salary
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
if ($filterSearch) {
    $sql .= " AND (e.name LIKE ? OR e.email LIKE ? OR e.emp_number LIKE ? OR e.designation LIKE ? OR e.phone LIKE ?)";
    $params = array_merge($params, ["%$filterSearch%", "%$filterSearch%", "%$filterSearch%", "%$filterSearch%", "%$filterSearch%"]);
}
$sql .= " ORDER BY e.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

// Fetch summary counts
$statusCounts = [];
$countsStmt = $db->query("SELECT status, COUNT(*) as cnt FROM employees GROUP BY status");
foreach ($countsStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = $row['cnt'];
}
$totalEmployees = array_sum($statusCounts);

// Fetch departments for filter
$deptStmt = $db->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'HR Portal — Employees';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-0"><i class="bi bi-person-badge-fill me-2 text-primary"></i>Employee Directory</h4>
    <p class="text-muted small mb-0">Manage employees, designations, joining dates, and salary structures</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/modules/hr/payroll.php" class="btn btn-outline-primary">
      <i class="bi bi-wallet2 me-1"></i>Salary & Payslips
    </a>
    <a href="<?= BASE_URL ?>/modules/hr/save_employee.php" class="btn btn-primary">
      <i class="bi bi-plus-circle me-1"></i>Add Employee
    </a>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Status Pills -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="?" class="badge bg-<?= !$filterStatus?'primary':'secondary' ?> text-decoration-none p-2 fs-6">
    All Employees <span><?= $totalEmployees ?></span>
  </a>
  <a href="?status=active" class="badge bg-<?= $filterStatus==='active'?'success':'secondary' ?> text-decoration-none p-2 fs-6">
    Active <span><?= $statusCounts['active']??0 ?></span>
  </a>
  <a href="?status=inactive" class="badge bg-<?= $filterStatus==='inactive'?'secondary':'light text-dark' ?> text-decoration-none p-2 fs-6">
    Inactive <span><?= $statusCounts['inactive']??0 ?></span>
  </a>
  <a href="?status=resigned" class="badge bg-<?= $filterStatus==='resigned'?'warning text-dark':'secondary' ?> text-decoration-none p-2 fs-6">
    Resigned <span><?= $statusCounts['resigned']??0 ?></span>
  </a>
  <a href="?status=terminated" class="badge bg-<?= $filterStatus==='terminated'?'danger':'secondary' ?> text-decoration-none p-2 fs-6">
    Terminated <span><?= $statusCounts['terminated']??0 ?></span>
  </a>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-5">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by name, emp code, email, designation..." value="<?= htmlspecialchars($filterSearch) ?>">
      </div>
      <div class="col-md-3">
        <select name="department" class="form-select form-select-sm">
          <option value="">All Departments</option>
          <?php foreach ($departments as $dept): ?>
            <option value="<?= htmlspecialchars($dept) ?>" <?= $filterDepartment===$dept?'selected':'' ?>><?= htmlspecialchars($dept) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
        <?php if ($filterSearch || $filterDepartment || $filterStatus): ?>
          <a href="?" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable">
        <thead class="table-light">
          <tr>
            <th>Emp No</th>
            <th>Employee Name</th>
            <th>Official Email</th>
            <th>Designation & Dept</th>
            <th>Joining Date</th>
            <th>Net Monthly Salary</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($employees)): ?>
            <tr>
              <td colspan="8" class="text-center text-muted py-4">No employee records found.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($employees as $emp): ?>
              <tr>
                <td class="fw-bold font-monospace text-primary"><?= htmlspecialchars($emp['emp_number']) ?></td>
                <td>
                  <a href="<?= BASE_URL ?>/modules/hr/view_employee.php?id=<?= $emp['id'] ?>" class="fw-semibold text-dark text-decoration-none">
                    <?= htmlspecialchars($emp['name']) ?>
                  </a>
                  <?php if ($emp['phone']): ?>
                    <div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($emp['phone']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="small">
                  <a href="mailto:<?= htmlspecialchars($emp['email']) ?>" class="text-decoration-none text-muted">
                    <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($emp['email']) ?>
                  </a>
                </td>
                <td>
                  <div class="fw-semibold small"><?= htmlspecialchars($emp['designation']) ?></div>
                  <span class="badge bg-light text-secondary border font-monospace" style="font-size:0.7rem;"><?= htmlspecialchars($emp['department'] ?: 'General') ?></span>
                </td>
                <td class="small text-nowrap"><?= date('d M Y', strtotime($emp['joining_date'])) ?></td>
                <td class="fw-bold text-success text-nowrap">
                  <?= $emp['net_salary'] > 0 ? '₹' . number_format($emp['net_salary'], 2) : '<span class="text-muted fw-normal small">Not Configured</span>' ?>
                </td>
                <td><?= statusBadge($emp['status']) ?></td>
                <td class="text-center text-nowrap">
                  <a href="<?= BASE_URL ?>/modules/hr/view_employee.php?id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-info" title="View Details"><i class="bi bi-eye"></i></a>
                  <a href="<?= BASE_URL ?>/modules/hr/save_employee.php?id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Employee"><i class="bi bi-pencil"></i></a>
                  <a href="<?= BASE_URL ?>/modules/hr/payroll.php?employee_id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-success" title="Process Payslip"><i class="bi bi-receipt"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/header.php'; ?>
