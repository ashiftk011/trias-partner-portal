<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('hr');

$db = getDB();

$filterMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$filterYear  = (int)($_GET['year'] ?? date('Y'));
$filterEmpId = (int)($_GET['employee_id'] ?? 0);

// Fetch all active employees for processing modal dropdown
$empListStmt = $db->query("SELECT e.*, s.basic_salary, s.hra, s.conveyance, s.special_allowance, s.pf_deduction, s.tds_deduction, s.other_deductions, s.net_salary FROM employees e LEFT JOIN salary_structures s ON s.employee_id=e.id WHERE e.status='active' ORDER BY e.name");
$allEmployees = $empListStmt->fetchAll();

// Handle POST: Process Salary Slip (Single, Batch, Edit, or Rollout)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? 'single';

    // 1. ROLL OUT SALARY SLIPS (Single or Month Bulk)
    if ($action === 'rollout') {
        $slipId = (int)($_POST['slip_id'] ?? 0);
        $month  = (int)($_POST['rollout_month'] ?? 0);
        $year   = (int)($_POST['rollout_year'] ?? 0);

        $slipsToRollout = [];
        if ($slipId > 0) {
            $stmt = $db->prepare("SELECT sp.*, e.name as emp_name, e.email as emp_email, e.emp_number FROM salary_payments sp JOIN employees e ON e.id=sp.employee_id WHERE sp.id=?");
            $stmt->execute([$slipId]);
            $item = $stmt->fetch();
            if ($item) $slipsToRollout[] = $item;
        } elseif ($month > 0 && $year > 0) {
            $stmt = $db->prepare("SELECT sp.*, e.name as emp_name, e.email as emp_email, e.emp_number FROM salary_payments sp JOIN employees e ON e.id=sp.employee_id WHERE sp.month=? AND sp.year=? AND sp.status='draft'");
            $stmt->execute([$month, $year]);
            $slipsToRollout = $stmt->fetchAll();
        }

        if (empty($slipsToRollout)) {
            setFlash('danger', 'No draft salary slips found to roll out.');
            redirect(BASE_URL . '/modules/hr/payroll.php');
        }

        $emailNotifySetting = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='payroll_email_notify'")->fetchColumn();
        $isEmailEnabled = ($emailNotifySetting !== false) ? ($emailNotifySetting === '1') : true;

        $rolledCount = 0;
        $emailsSent = 0;
        foreach ($slipsToRollout as $slip) {
            // Update status to 'paid'
            $db->prepare("UPDATE salary_payments SET status='paid' WHERE id=?")->execute([$slip['id']]);
            $rolledCount++;

            // Dispatch Email if enabled
            if ($isEmailEnabled && !empty($slip['emp_email'])) {
                require_once __DIR__ . '/../../includes/mailer.php';
                $mName = $monthNames[$slip['month']] ?? $slip['month'];
                $to = $slip['emp_email'];
                $subject = "Official Payslip Released — " . $mName . " " . $slip['year'];

                $htmlBody = "
                <div style='font-family: Arial, sans-serif; max-width:600px; margin:0 auto; border:1px solid #e2e8f0; border-radius:10px; padding:25px; background-color:#ffffff;'>
                  <div style='border-bottom:2px solid #6366f1; padding-bottom:15px; margin-bottom:20px;'>
                    <h2 style='color:#4f46e5; margin:0; font-size:20px;'>Salary Disbursement Advice</h2>
                    <p style='color:#64748b; margin:4px 0 0 0; font-size:13px;'>Official Monthly Payslip Notification</p>
                  </div>
                  <p style='font-size:14px; color:#1e293b;'>Dear <strong>" . htmlspecialchars($slip['emp_name']) . "</strong>,</p>
                  <p style='font-size:14px; color:#475569;'>Your salary slip for <strong>" . htmlspecialchars($mName) . " " . $slip['year'] . "</strong> has been officially processed and disbursed into your bank account.</p>

                  <div style='background-color:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:15px; margin:20px 0;'>
                    <table style='width:100%; font-size:13px; color:#334155; border-collapse:collapse;'>
                      <tr><td style='padding:4px 0; color:#64748b;'>Payslip Number:</td><td style='padding:4px 0; font-weight:bold; text-align:right; font-family:monospace;'>" . htmlspecialchars($slip['slip_number']) . "</td></tr>
                      <tr><td style='padding:4px 0; color:#64748b;'>Employee Code:</td><td style='padding:4px 0; font-weight:bold; text-align:right;'>" . htmlspecialchars($slip['emp_number']) . "</td></tr>
                      <tr><td style='padding:4px 0; color:#64748b;'>Payment Date:</td><td style='padding:4px 0; font-weight:bold; text-align:right;'>" . date('d M Y', strtotime($slip['payment_date'])) . "</td></tr>
                      <tr><td style='padding:4px 0; color:#64748b;'>Payment Mode:</td><td style='padding:4px 0; font-weight:bold; text-align:right; text-transform:uppercase;'>" . htmlspecialchars(str_replace('_',' ',$slip['payment_mode'])) . "</td></tr>
                      <tr style='border-top:1px solid #cbd5e1;'><td style='padding:8px 0 0 0; font-weight:bold; color:#1e293b;'>Net Disbursed Salary:</td><td style='padding:8px 0 0 0; font-weight:bold; color:#16a34a; font-size:16px; text-align:right;'>₹" . number_format($slip['net_salary'], 2) . "</td></tr>
                    </table>
                  </div>

                  <p style='font-size:13px; color:#64748b;'>You can download your printable payslip anytime by logging into the partner portal.</p>
                  <hr style='border:none; border-top:1px solid #e2e8f0; margin:20px 0;'>
                  <small style='color:#94a3b8; font-size:11px;'>This is an automated salary notification email generated by HR & Payroll Services.</small>
                </div>";

                $res = sendPortalEmail($to, $subject, $htmlBody);
                if ($res['success']) {
                    $emailsSent++;
                }
            }
        }

        $msg = "Successfully rolled out {$rolledCount} salary slip(s)! Payment recorded for accounts.";
        if ($isEmailEnabled) {
            $msg .= " Email notifications sent to {$emailsSent} employee(s).";
        } else {
            $msg .= " (Email notifications disabled in settings).";
        }

        setFlash('success', $msg);
        redirect(BASE_URL . '/modules/hr/payroll.php' . ($month ? "?month={$month}&year={$year}" : ''));
    }

    // 2. EDIT DRAFT SALARY SLIP
    if ($action === 'edit') {
        $slipId = (int)($_POST['slip_id'] ?? 0);
        $chk = $db->prepare("SELECT * FROM salary_payments WHERE id=?");
        $chk->execute([$slipId]);
        $existing = $chk->fetch();

        if (!$existing || $existing['status'] === 'paid') {
            setFlash('danger', 'Paid / Rolled Out salary slips cannot be edited.');
            redirect(BASE_URL . '/modules/hr/payroll.php');
        }

        $basicSalary = (float)($_POST['basic_salary'] ?? 0);
        $hra         = (float)($_POST['hra'] ?? 0);
        $conveyance  = (float)($_POST['conveyance'] ?? 0);
        $special     = (float)($_POST['special_allowance'] ?? 0);
        $gross       = $basicSalary + $hra + $conveyance + $special;

        $pf          = (float)($_POST['pf_deduction'] ?? 0);
        $tds         = (float)($_POST['tds_deduction'] ?? 0);
        $other       = (float)($_POST['other_deductions'] ?? 0);
        $deductions  = $pf + $tds + $other;
        $net         = max(0, $gross - $deductions);

        $payDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $payMode = trim($_POST['payment_mode'] ?? 'bank_transfer');
        $txRef   = trim($_POST['transaction_ref'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');

        $db->prepare("UPDATE salary_payments SET payment_date=?, basic_salary=?, hra=?, conveyance=?, special_allowance=?, gross_salary=?, pf_deduction=?, tds_deduction=?, other_deductions=?, total_deductions=?, net_salary=?, payment_mode=?, transaction_ref=?, notes=? WHERE id=?")
           ->execute([$payDate, $basicSalary, $hra, $conveyance, $special, $gross, $pf, $tds, $other, $deductions, $net, $payMode, $txRef, $notes, $slipId]);

        setFlash('success', 'Draft salary slip updated successfully.');
        redirect(BASE_URL . '/modules/hr/payroll.php');
    }

    // 3. BATCH GENERATION (Initial Draft State)
    if ($action === 'batch') {
        $empIds   = $_POST['emp_ids'] ?? [];
        $month    = (int)($_POST['month'] ?? date('n'));
        $year     = (int)($_POST['year'] ?? date('Y'));
        $payDate  = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $payMode  = trim($_POST['payment_mode'] ?? 'bank_transfer');
        $notes    = trim($_POST['notes'] ?? 'Batch Generated Payroll');

        if (empty($empIds) || !is_array($empIds)) {
            setFlash('danger', 'Please select at least one employee for batch salary generation.');
            redirect(BASE_URL . '/modules/hr/payroll.php');
        }

        $processedCount = 0;
        foreach ($empIds as $rawId) {
            $eId = (int)$rawId;
            if (!$eId) continue;

            $eStmt = $db->prepare("SELECT e.*, s.basic_salary, s.hra, s.conveyance, s.special_allowance, s.pf_deduction, s.tds_deduction, s.other_deductions, s.net_salary FROM employees e LEFT JOIN salary_structures s ON s.employee_id=e.id WHERE e.id=?");
            $eStmt->execute([$eId]);
            $empData = $eStmt->fetch();
            if (!$empData) continue;

            $basicSalary = (float)($empData['basic_salary'] ?? 0);
            $hra         = (float)($empData['hra'] ?? 0);
            $conveyance  = (float)($empData['conveyance'] ?? 0);
            $special     = (float)($empData['special_allowance'] ?? 0);
            $gross       = $basicSalary + $hra + $conveyance + $special;

            $pf          = (float)($empData['pf_deduction'] ?? 0);
            $tds         = (float)($empData['tds_deduction'] ?? 0);
            $other       = (float)($empData['other_deductions'] ?? 0);
            $deductions  = $pf + $tds + $other;
            $net         = max(0, $gross - $deductions);

            // Check existing slip
            $chk = $db->prepare("SELECT id, status FROM salary_payments WHERE employee_id=? AND month=? AND year=?");
            $chk->execute([$eId, $month, $year]);
            $existing = $chk->fetch();

            if ($existing) {
                // If existing and already paid, don't overwrite paid record
                if ($existing['status'] === 'paid') continue;
                $db->prepare("UPDATE salary_payments SET payment_date=?, basic_salary=?, hra=?, conveyance=?, special_allowance=?, gross_salary=?, pf_deduction=?, tds_deduction=?, other_deductions=?, total_deductions=?, net_salary=?, payment_mode=?, notes=?, created_by=? WHERE id=?")
                   ->execute([$payDate, $basicSalary, $hra, $conveyance, $special, $gross, $pf, $tds, $other, $deductions, $net, $payMode, $notes, currentUser()['id'], $existing['id']]);
            } else {
                $cleanEmpNum = preg_replace('/[^A-Za-z0-9]/', '', $empData['emp_number']);
                $slipNum = 'SLIP-' . $year . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . $cleanEmpNum;
                $dupCheck = $db->prepare("SELECT COUNT(*) FROM salary_payments WHERE slip_number=?");
                $dupCheck->execute([$slipNum]);
                if ($dupCheck->fetchColumn() > 0) {
                    $slipNum .= '-' . rand(10, 99);
                }

                $db->prepare("INSERT INTO salary_payments (slip_number, employee_id, month, year, payment_date, basic_salary, hra, conveyance, special_allowance, gross_salary, pf_deduction, tds_deduction, other_deductions, total_deductions, net_salary, payment_mode, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$slipNum, $eId, $month, $year, $payDate, $basicSalary, $hra, $conveyance, $special, $gross, $pf, $tds, $other, $deductions, $net, $payMode, 'draft', $notes, currentUser()['id']]);
            }
            $processedCount++;
        }

        setFlash('success', "Batch salary slips generated as Draft for {$processedCount} employee(s). You can review/edit before roll out.");
        redirect(BASE_URL . "/modules/hr/payroll.php?month={$month}&year={$year}");
    }

    // 4. SINGLE GENERATION (Initial Draft State)
    $empId      = (int)($_POST['employee_id'] ?? 0);
    $month      = (int)($_POST['month'] ?? date('n'));
    $year       = (int)($_POST['year'] ?? date('Y'));
    $payDate    = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $payMode    = trim($_POST['payment_mode'] ?? 'bank_transfer');
    $txRef      = trim($_POST['transaction_ref'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');

    if (!$empId || !$month || !$year || !$payDate) {
        setFlash('danger', 'Employee, Month, Year, and Payment Date are required.');
        redirect(BASE_URL . '/modules/hr/payroll.php');
    }

    // Fetch employee & salary structure
    $eStmt = $db->prepare("SELECT e.*, s.basic_salary, s.hra, s.conveyance, s.special_allowance, s.pf_deduction, s.tds_deduction, s.other_deductions, s.net_salary FROM employees e LEFT JOIN salary_structures s ON s.employee_id=e.id WHERE e.id=?");
    $eStmt->execute([$empId]);
    $empData = $eStmt->fetch();

    if (!$empData) {
        setFlash('danger', 'Invalid employee selection.');
        redirect(BASE_URL . '/modules/hr/payroll.php');
    }

    $basicSalary = (isset($_POST['basic_salary']) && $_POST['basic_salary'] !== '') ? (float)$_POST['basic_salary'] : (float)($empData['basic_salary'] ?? 0);
    $hra         = (isset($_POST['hra']) && $_POST['hra'] !== '') ? (float)$_POST['hra'] : (float)($empData['hra'] ?? 0);
    $conveyance  = (isset($_POST['conveyance']) && $_POST['conveyance'] !== '') ? (float)$_POST['conveyance'] : (float)($empData['conveyance'] ?? 0);
    $special     = (isset($_POST['special_allowance']) && $_POST['special_allowance'] !== '') ? (float)$_POST['special_allowance'] : (float)($empData['special_allowance'] ?? 0);
    $gross       = $basicSalary + $hra + $conveyance + $special;

    $pf          = (isset($_POST['pf_deduction']) && $_POST['pf_deduction'] !== '') ? (float)$_POST['pf_deduction'] : (float)($empData['pf_deduction'] ?? 0);
    $tds         = (isset($_POST['tds_deduction']) && $_POST['tds_deduction'] !== '') ? (float)$_POST['tds_deduction'] : (float)($empData['tds_deduction'] ?? 0);
    $other       = (isset($_POST['other_deductions']) && $_POST['other_deductions'] !== '') ? (float)$_POST['other_deductions'] : (float)($empData['other_deductions'] ?? 0);
    $deductions  = $pf + $tds + $other;
    $net         = max(0, $gross - $deductions);

    // Check existing slip
    $chk = $db->prepare("SELECT id, status FROM salary_payments WHERE employee_id=? AND month=? AND year=?");
    $chk->execute([$empId, $month, $year]);
    $existing = $chk->fetch();

    if ($existing) {
        if ($existing['status'] === 'paid') {
            setFlash('danger', 'A paid / rolled out salary slip already exists for this employee for the selected period.');
            redirect(BASE_URL . '/modules/hr/payroll.php');
        }
        $db->prepare("UPDATE salary_payments SET payment_date=?, basic_salary=?, hra=?, conveyance=?, special_allowance=?, gross_salary=?, pf_deduction=?, tds_deduction=?, other_deductions=?, total_deductions=?, net_salary=?, payment_mode=?, transaction_ref=?, notes=?, created_by=? WHERE id=?")
           ->execute([$payDate, $basicSalary, $hra, $conveyance, $special, $gross, $pf, $tds, $other, $deductions, $net, $payMode, $txRef, $notes, currentUser()['id'], $existing['id']]);
        $slipId = $existing['id'];
        setFlash('success', 'Draft salary slip updated successfully.');
    } else {
        $cleanEmpNum = preg_replace('/[^A-Za-z0-9]/', '', $empData['emp_number']);
        $slipNum = 'SLIP-' . $year . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . $cleanEmpNum;

        $dupCheck = $db->prepare("SELECT COUNT(*) FROM salary_payments WHERE slip_number=?");
        $dupCheck->execute([$slipNum]);
        if ($dupCheck->fetchColumn() > 0) {
            $slipNum .= '-' . rand(10, 99);
        }

        $db->prepare("INSERT INTO salary_payments (slip_number, employee_id, month, year, payment_date, basic_salary, hra, conveyance, special_allowance, gross_salary, pf_deduction, tds_deduction, other_deductions, total_deductions, net_salary, payment_mode, transaction_ref, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$slipNum, $empId, $month, $year, $payDate, $basicSalary, $hra, $conveyance, $special, $gross, $pf, $tds, $other, $deductions, $net, $payMode, $txRef, 'draft', $notes, currentUser()['id']]);
        $slipId = $db->lastInsertId();
        setFlash('success', 'Salary slip generated as Draft. You can review and edit before rollout.');
    }

    redirect(BASE_URL . '/modules/hr/payroll.php');
}

// Fetch payments query
$sql = "SELECT sp.*, e.name as employee_name, e.emp_number, e.designation, e.department, u.name as created_by_name
        FROM salary_payments sp
        JOIN employees e ON e.id = sp.employee_id
        LEFT JOIN users u ON u.id = sp.created_by
        WHERE 1=1";
$params = [];

if ($filterMonth) {
    $sql .= " AND sp.month = ?";
    $params[] = $filterMonth;
}
if ($filterYear) {
    $sql .= " AND sp.year = ?";
    $params[] = $filterYear;
}
if ($filterEmpId) {
    $sql .= " AND sp.employee_id = ?";
    $params[] = $filterEmpId;
}
$sql .= " ORDER BY sp.year DESC, sp.month DESC, sp.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Monthly totals
$totalGross = 0;
$totalDeductions = 0;
$totalNetPaid = 0;
foreach ($payments as $p) {
    $totalGross += $p['gross_salary'];
    $totalDeductions += $p['total_deductions'];
    $totalNetPaid += $p['net_salary'];
}

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

$pageTitle = 'HR Portal — Salary & Payroll';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-0"><i class="bi bi-wallet2 me-2 text-primary"></i>Salary & Payroll Management</h4>
    <p class="text-muted small mb-0">Generate monthly salary slips, record salary disbursements, and view payroll history</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= BASE_URL ?>/modules/hr/employees.php" class="btn btn-outline-secondary">
      <i class="bi bi-person-badge me-1"></i>Employee Directory
    </a>
    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#batchProcessSalaryModal">
      <i class="bi bi-stack me-1"></i>Batch Generate Slips
    </button>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#processSalaryModal">
      <i class="bi bi-plus-circle me-1"></i>Single Salary Slip
    </button>
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#bulkRolloutModal">
      <i class="bi bi-rocket-takeoff me-1"></i>Roll Out Payroll
    </button>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Payroll Overview Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card border-0 shadow-sm bg-primary bg-opacity-10">
      <div class="card-body p-3">
        <span class="text-primary small fw-semibold text-uppercase">Total Gross Salary</span>
        <h3 class="mb-0 text-primary fw-bold">₹<?= number_format($totalGross, 2) ?></h3>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm bg-danger bg-opacity-10">
      <div class="card-body p-3">
        <span class="text-danger small fw-semibold text-uppercase">Total Deductions (PF/TDS)</span>
        <h3 class="mb-0 text-danger fw-bold">₹<?= number_format($totalDeductions, 2) ?></h3>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm bg-success bg-opacity-10">
      <div class="card-body p-3">
        <span class="text-success small fw-semibold text-uppercase">Total Net Disbursements</span>
        <h3 class="mb-0 text-success fw-bold">₹<?= number_format($totalNetPaid, 2) ?></h3>
      </div>
    </div>
  </div>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Month</label>
        <select name="month" class="form-select form-select-sm">
          <option value="0">All Months</option>
          <?php foreach ($monthNames as $mNum => $mName): ?>
            <option value="<?= $mNum ?>" <?= $filterMonth===$mNum?'selected':'' ?>><?= $mName ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Year</label>
        <select name="year" class="form-select form-select-sm">
          <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
            <option value="<?= $y ?>" <?= $filterYear===$y?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label small fw-semibold mb-1">Employee Filter</label>
        <select name="employee_id" class="form-select form-select-sm">
          <option value="0">All Employees</option>
          <?php foreach ($allEmployees as $empOpt): ?>
            <option value="<?= $empOpt['id'] ?>" <?= $filterEmpId===$empOpt['id']?'selected':'' ?>><?= htmlspecialchars($empOpt['name']) ?> (<?= htmlspecialchars($empOpt['emp_number']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
        <?php if ($filterMonth || $filterEmpId): ?>
          <a href="?" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Salary Payments Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable align-middle">
        <thead class="table-light">
          <tr>
            <th>Slip Number</th>
            <th>Employee</th>
            <th>Month / Year</th>
            <th>Payment Date</th>
            <th>Gross Salary</th>
            <th>Deductions</th>
            <th>Net Paid</th>
            <th>Status</th>
            <th>Payment Mode</th>
            <th class="text-center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
            <tr>
              <td colspan="10" class="text-center text-muted py-4">No salary payments or slips recorded for selected period.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($payments as $p): ?>
              <tr>
                <td class="fw-bold font-monospace text-primary"><?= htmlspecialchars($p['slip_number']) ?></td>
                <td>
                  <div class="fw-semibold text-dark"><?= htmlspecialchars($p['employee_name']) ?></div>
                  <div class="small text-muted font-monospace"><?= htmlspecialchars($p['emp_number']) ?> — <?= htmlspecialchars($p['designation']) ?></div>
                </td>
                <td class="fw-semibold text-nowrap"><?= $monthNames[$p['month']] ?? $p['month'] ?> <?= $p['year'] ?></td>
                <td class="small text-nowrap"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                <td class="small">₹<?= number_format($p['gross_salary'], 2) ?></td>
                <td class="small text-danger">₹<?= number_format($p['total_deductions'], 2) ?></td>
                <td class="fw-bold text-success">₹<?= number_format($p['net_salary'], 2) ?></td>
                <td>
                  <?php if ($p['status'] === 'draft'): ?>
                    <span class="badge bg-warning text-dark border"><i class="bi bi-pencil-square me-1"></i>Draft</span>
                  <?php else: ?>
                    <span class="badge bg-success border"><i class="bi bi-check-circle me-1"></i>Paid & Rolled Out</span>
                  <?php endif; ?>
                </td>
                <td class="small text-uppercase">
                  <span class="badge bg-light text-dark border"><?= str_replace('_', ' ', $p['payment_mode']) ?></span>
                </td>
                <td class="text-center text-nowrap">
                  <?php if ($p['status'] === 'draft'): ?>
                    <button type="button" class="btn btn-sm btn-outline-warning text-dark me-1" onclick='openEditPayslipModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit Breakdown (Draft)">
                      <i class="bi bi-pencil"></i> Edit
                    </button>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Roll out salary slip <?= htmlspecialchars($p['slip_number']) ?> for <?= htmlspecialchars($p['employee_name']) ?>? This will record payment and dispatch email.');">
                      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                      <input type="hidden" name="action" value="rollout">
                      <input type="hidden" name="slip_id" value="<?= $p['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-success me-1" title="Roll Out & Mark Paid">
                        <i class="bi bi-rocket-takeoff me-1"></i>Roll Out
                      </button>
                    </form>
                    <a href="<?= BASE_URL ?>/modules/hr/payslip.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Preview Slip">
                      <i class="bi bi-eye"></i>
                    </a>
                  <?php else: ?>
                    <a href="<?= BASE_URL ?>/modules/hr/payslip.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Print/Download Payslip">
                      <i class="bi bi-printer me-1"></i>Payslip
                    </a>
                    <span class="badge bg-light text-success border"><i class="bi bi-lock-fill me-1"></i>Locked</span>
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

<!-- Modal: Process Salary Slip -->
<div class="modal fade" id="processSalaryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-receipt me-2 text-primary"></i>Generate Salary Slip</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-12">
              <label class="form-label small fw-semibold">Select Employee <span class="text-danger">*</span></label>
              <select name="employee_id" id="modal_employee_id" class="form-select" required onchange="updateEmpSalaryPreview(this)">
                <option value="">-- Select Active Employee --</option>
                <?php foreach ($allEmployees as $empOpt): ?>
                  <option value="<?= $empOpt['id'] ?>"
                          data-basic="<?= (float)($empOpt['basic_salary'] ?? 0) ?>"
                          data-hra="<?= (float)($empOpt['hra'] ?? 0) ?>"
                          data-conveyance="<?= (float)($empOpt['conveyance'] ?? 0) ?>"
                          data-special="<?= (float)($empOpt['special_allowance'] ?? 0) ?>"
                          data-pf="<?= (float)($empOpt['pf_deduction'] ?? 0) ?>"
                          data-tds="<?= (float)($empOpt['tds_deduction'] ?? 0) ?>"
                          data-other="<?= (float)($empOpt['other_deductions'] ?? 0) ?>"
                          data-net="<?= (float)($empOpt['net_salary'] ?? 0) ?>"
                          <?= $filterEmpId===$empOpt['id']?'selected':'' ?>>
                    <?= htmlspecialchars($empOpt['name']) ?> (<?= htmlspecialchars($empOpt['emp_number']) ?>) — <?= htmlspecialchars($empOpt['designation']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Salary Month <span class="text-danger">*</span></label>
              <select name="month" class="form-select" required>
                <?php foreach ($monthNames as $mNum => $mName): ?>
                  <option value="<?= $mNum ?>" <?= date('n')===$mNum?'selected':'' ?>><?= $mName ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Salary Year <span class="text-danger">*</span></label>
              <select name="year" class="form-select" required>
                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                  <option value="<?= $y ?>"><?= $y ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Payment Date <span class="text-danger">*</span></label>
              <input type="date" name="payment_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>

            <!-- Salary Structure Components (Auto-filled on employee select) -->
            <div class="col-12 border-top pt-3 mt-2">
              <h6 class="fw-bold small text-muted text-uppercase mb-2"><i class="bi bi-sliders me-1 text-primary"></i>Payslip Earnings & Deductions Breakdown</h6>
              <div class="row g-2 bg-light p-3 rounded border">
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">Basic (₹)</label>
                  <input type="number" step="0.01" name="basic_salary" id="m_basic" class="form-control form-control-sm m-calc" value="0">
                </div>
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">HRA (₹)</label>
                  <input type="number" step="0.01" name="hra" id="m_hra" class="form-control form-control-sm m-calc" value="0">
                </div>
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">Conveyance (₹)</label>
                  <input type="number" step="0.01" name="conveyance" id="m_conveyance" class="form-control form-control-sm m-calc" value="0">
                </div>
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">Special Allow. (₹)</label>
                  <input type="number" step="0.01" name="special_allowance" id="m_special" class="form-control form-control-sm m-calc" value="0">
                </div>
                <div class="col-md-4 mt-2">
                  <label class="form-label micro-label fw-semibold text-danger">PF Deduction (₹)</label>
                  <input type="number" step="0.01" name="pf_deduction" id="m_pf" class="form-control form-control-sm m-calc" value="0">
                </div>
                <div class="col-md-4 mt-2">
                  <label class="form-label micro-label fw-semibold text-danger">TDS / Income Tax (₹)</label>
                  <input type="number" step="0.01" name="tds_deduction" id="m_tds" class="form-control form-control-sm m-calc" value="0">
                </div>
                <div class="col-md-4 mt-2">
                  <label class="form-label micro-label fw-semibold text-danger">Other Deductions (₹)</label>
                  <input type="number" step="0.01" name="other_deductions" id="m_other" class="form-control form-control-sm m-calc" value="0">
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="bank_transfer">Direct Bank Transfer</option>
                <option value="cheque">Cheque</option>
                <option value="cash">Cash</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Transaction Reference / UTR</label>
              <input type="text" name="transaction_ref" class="form-control" placeholder="e.g. UTR-9876543210">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Additional Notes</label>
              <input type="text" name="notes" class="form-control" placeholder="Optional notes for this salary slip">
            </div>
          </div>
          <div class="alert alert-success mt-3 mb-0 d-flex justify-content-between align-items-center py-2">
            <span class="small fw-semibold"><i class="bi bi-check-circle me-1"></i>Net Disbursed Amount:</span>
            <span class="fs-5 fw-bold" id="modalNetSalaryPreview">₹0.00</span>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Generate Payslip</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Batch Process Salary Slips -->
<div class="modal fade" id="batchProcessSalaryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="batch">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-stack me-2 text-primary"></i>Batch Generate Salary Slips</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Salary Month <span class="text-danger">*</span></label>
              <select name="month" class="form-select" required>
                <?php foreach ($monthNames as $mNum => $mName): ?>
                  <option value="<?= $mNum ?>" <?= date('n')===$mNum?'selected':'' ?>><?= $mName ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Salary Year <span class="text-danger">*</span></label>
              <select name="year" class="form-select" required>
                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                  <option value="<?= $y ?>"><?= $y ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Payment Date <span class="text-danger">*</span></label>
              <input type="date" name="payment_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="bank_transfer">Direct Bank Transfer</option>
                <option value="cheque">Cheque</option>
                <option value="cash">Cash</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Notes / Description</label>
              <input type="text" name="notes" class="form-control" value="Batch Payroll Generation" placeholder="Optional notes for this batch">
            </div>

            <!-- Employee Selection Checkboxes -->
            <div class="col-12 border-top pt-3 mt-2">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold small text-muted text-uppercase mb-0"><i class="bi bi-people me-1 text-primary"></i>Select Employees to Include</h6>
                <div class="form-check me-2">
                  <input class="form-check-input" type="checkbox" id="selectAllEmployees" checked onchange="toggleSelectAllEmployees(this)">
                  <label class="form-check-input-label small fw-bold" for="selectAllEmployees">Select All Active</label>
                </div>
              </div>

              <div class="bg-light p-3 rounded border" style="max-height: 250px; overflow-y: auto;">
                <?php foreach ($allEmployees as $emp): ?>
                  <div class="form-check py-1 border-bottom">
                    <input class="form-check-input emp-batch-chk" type="checkbox" name="emp_ids[]" value="<?= $emp['id'] ?>" id="batch_emp_<?= $emp['id'] ?>" checked>
                    <label class="form-check-label d-flex justify-content-between align-items-center w-100 pe-2" for="batch_emp_<?= $emp['id'] ?>">
                      <span>
                        <strong class="text-dark"><?= htmlspecialchars($emp['name']) ?></strong>
                        <span class="text-muted font-monospace small"> (<?= htmlspecialchars($emp['emp_number']) ?>)</span>
                        <span class="badge bg-light text-muted border ms-2"><?= htmlspecialchars($emp['designation']) ?></span>
                      </span>
                      <span class="fw-bold text-success small">Net: ₹<?= number_format((float)($emp['net_salary'] ?? 0), 2) ?></span>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-stack me-1"></i>Generate Payslips for Selected</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Edit Draft Salary Slip -->
<div class="modal fade" id="editPayslipModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="slip_id" id="edit_slip_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Draft Salary Slip (<span id="edit_slip_number"></span>)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info py-2 small">
            <i class="bi bi-info-circle me-1"></i>You are editing a Draft salary slip for <strong id="edit_emp_name"></strong>. Changes will be saved prior to rollout.
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Payment Date <span class="text-danger">*</span></label>
              <input type="date" name="payment_date" id="edit_payment_date" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Payment Mode</label>
              <select name="payment_mode" id="edit_payment_mode" class="form-select">
                <option value="bank_transfer">Direct Bank Transfer</option>
                <option value="cheque">Cheque</option>
                <option value="cash">Cash</option>
              </select>
            </div>

            <!-- Earnings & Deductions -->
            <div class="col-12 border-top pt-3 mt-2">
              <h6 class="fw-bold small text-muted text-uppercase mb-2"><i class="bi bi-sliders me-1 text-primary"></i>Earnings & Deductions Breakdown</h6>
              <div class="row g-2 bg-light p-3 rounded border">
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">Basic (₹)</label>
                  <input type="number" step="0.01" name="basic_salary" id="edit_basic" class="form-control form-control-sm e-calc">
                </div>
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">HRA (₹)</label>
                  <input type="number" step="0.01" name="hra" id="edit_hra" class="form-control form-control-sm e-calc">
                </div>
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">Conveyance (₹)</label>
                  <input type="number" step="0.01" name="conveyance" id="edit_conveyance" class="form-control form-control-sm e-calc">
                </div>
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">Special / Bonus (₹)</label>
                  <input type="number" step="0.01" name="special_allowance" id="edit_special" class="form-control form-control-sm e-calc">
                </div>
                <div class="col-md-4 mt-2">
                  <label class="form-label micro-label fw-semibold text-danger">PF Deduction (₹)</label>
                  <input type="number" step="0.01" name="pf_deduction" id="edit_pf" class="form-control form-control-sm e-calc">
                </div>
                <div class="col-md-4 mt-2">
                  <label class="form-label micro-label fw-semibold text-danger">TDS / Income Tax (₹)</label>
                  <input type="number" step="0.01" name="tds_deduction" id="edit_tds" class="form-control form-control-sm e-calc">
                </div>
                <div class="col-md-4 mt-2">
                  <label class="form-label micro-label fw-semibold text-danger">Other Deductions (₹)</label>
                  <input type="number" step="0.01" name="other_deductions" id="edit_other" class="form-control form-control-sm e-calc">
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Transaction Reference / UTR</label>
              <input type="text" name="transaction_ref" id="edit_transaction_ref" class="form-control" placeholder="e.g. UTR-9876543210">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Additional Notes</label>
              <input type="text" name="notes" id="edit_notes" class="form-control" placeholder="Optional notes for this salary slip">
            </div>
          </div>
          <div class="alert alert-success mt-3 mb-0 d-flex justify-content-between align-items-center py-2">
            <span class="small fw-semibold"><i class="bi bi-check-circle me-1"></i>Updated Net Take-Home:</span>
            <span class="fs-5 fw-bold" id="editNetSalaryPreview">₹0.00</span>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Draft Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Bulk Rollout Month Payroll -->
<div class="modal fade" id="bulkRolloutModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="rollout">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-rocket-takeoff me-2 text-success"></i>Roll Out Payroll Batch</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-3">Rolling out payroll will convert all Draft salary slips for the selected month to <strong>Paid</strong>, lock the amounts for accounting records, and send email notifications to employees (if enabled).</p>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label small fw-semibold">Rollout Month <span class="text-danger">*</span></label>
              <select name="rollout_month" class="form-select" required>
                <?php foreach ($monthNames as $mNum => $mName): ?>
                  <option value="<?= $mNum ?>" <?= (date('n') === $mNum) ? 'selected' : '' ?>><?= $mName ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Rollout Year <span class="text-danger">*</span></label>
              <select name="rollout_year" class="form-select" required>
                <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                  <option value="<?= $y ?>"><?= $y ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-rocket-takeoff me-1"></i>Confirm & Roll Out</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEditPayslipModal(slip) {
  document.getElementById('edit_slip_id').value = slip.id;
  document.getElementById('edit_slip_number').innerText = slip.slip_number;
  document.getElementById('edit_emp_name').innerText = slip.employee_name || 'Employee';
  document.getElementById('edit_payment_date').value = slip.payment_date;
  document.getElementById('edit_payment_mode').value = slip.payment_mode || 'bank_transfer';
  document.getElementById('edit_transaction_ref').value = slip.transaction_ref || '';
  document.getElementById('edit_notes').value = slip.notes || '';

  document.getElementById('edit_basic').value = slip.basic_salary;
  document.getElementById('edit_hra').value = slip.hra;
  document.getElementById('edit_conveyance').value = slip.conveyance;
  document.getElementById('edit_special').value = slip.special_allowance;
  document.getElementById('edit_pf').value = slip.pf_deduction;
  document.getElementById('edit_tds').value = slip.tds_deduction;
  document.getElementById('edit_other').value = slip.other_deductions;

  recalculateEditSalary();
  const modal = new bootstrap.Modal(document.getElementById('editPayslipModal'));
  modal.show();
}

function recalculateEditSalary() {
  const getVal = (id) => parseFloat(document.getElementById(id).value) || 0;
  const basic = getVal('edit_basic');
  const hra = getVal('edit_hra');
  const conveyance = getVal('edit_conveyance');
  const special = getVal('edit_special');

  const pf = getVal('edit_pf');
  const tds = getVal('edit_tds');
  const other = getVal('edit_other');

  const gross = basic + hra + conveyance + special;
  const ded = pf + tds + other;
  const net = Math.max(0, gross - ded);

  document.getElementById('editNetSalaryPreview').innerText = '₹' + net.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

document.querySelectorAll('.e-calc').forEach(el => {
  el.addEventListener('input', recalculateEditSalary);
});

function toggleSelectAllEmployees(masterChk) {
  document.querySelectorAll('.emp-batch-chk').forEach(chk => {
    chk.checked = masterChk.checked;
  });
}

function recalculateModalSalary() {
  const getVal = (id) => parseFloat(document.getElementById(id).value) || 0;
  const basic = getVal('m_basic');
  const hra = getVal('m_hra');
  const conveyance = getVal('m_conveyance');
  const special = getVal('m_special');

  const pf = getVal('m_pf');
  const tds = getVal('m_tds');
  const other = getVal('m_other');

  const gross = basic + hra + conveyance + special;
  const ded = pf + tds + other;
  const net = Math.max(0, gross - ded);

  document.getElementById('modalNetSalaryPreview').innerText = '₹' + net.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function updateEmpSalaryPreview(selectEl) {
  const selectedOption = selectEl.options[selectEl.selectedIndex];
  if (!selectedOption || !selectedOption.value) {
    document.getElementById('m_basic').value = 0;
    document.getElementById('m_hra').value = 0;
    document.getElementById('m_conveyance').value = 0;
    document.getElementById('m_special').value = 0;
    document.getElementById('m_pf').value = 0;
    document.getElementById('m_tds').value = 0;
    document.getElementById('m_other').value = 0;
  } else {
    document.getElementById('m_basic').value = selectedOption.getAttribute('data-basic') || 0;
    document.getElementById('m_hra').value = selectedOption.getAttribute('data-hra') || 0;
    document.getElementById('m_conveyance').value = selectedOption.getAttribute('data-conveyance') || 0;
    document.getElementById('m_special').value = selectedOption.getAttribute('data-special') || 0;
    document.getElementById('m_pf').value = selectedOption.getAttribute('data-pf') || 0;
    document.getElementById('m_tds').value = selectedOption.getAttribute('data-tds') || 0;
    document.getElementById('m_other').value = selectedOption.getAttribute('data-other') || 0;
  }
  recalculateModalSalary();
}

document.querySelectorAll('.m-calc').forEach(el => {
  el.addEventListener('input', recalculateModalSalary);
});

document.addEventListener('DOMContentLoaded', function() {
  const empSelect = document.getElementById('modal_employee_id');
  if (empSelect && empSelect.value) {
    updateEmpSalaryPreview(empSelect);
  }
  
  <?php if ($filterEmpId): ?>
  const modalEl = document.getElementById('processSalaryModal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
  <?php endif; ?>
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
