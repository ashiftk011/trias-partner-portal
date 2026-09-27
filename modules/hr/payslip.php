<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('hr');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) die("Invalid Salary Slip ID");

$sql = "SELECT sp.*, e.name as employee_name, e.emp_number, e.email, e.phone, e.designation, e.department, e.joining_date, e.bank_name, e.bank_account_no, e.ifsc_code, e.pan_no
        FROM salary_payments sp
        JOIN employees e ON e.id = sp.employee_id
        WHERE sp.id = ?";
$stmt = $db->prepare($sql);
$stmt->execute([$id]);
$slip = $stmt->fetch();

if (!$slip) die("Salary Slip record not found.");

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

function numberToWords(float $number): string {
    $no = floor($number);
    $point = round($number - $no, 2) * 100;
    $hundred = null;
    $digits_1 = strlen($no);
    $i = 0;
    $str = array();
    $words = array(
        '0' => '', '1' => 'One', '2' => 'Two',
        '3' => 'Three', '4' => 'Four', '5' => 'Five', '6' => 'Six',
        '7' => 'Seven', '8' => 'Eight', '9' => 'Nine',
        '10' => 'Ten', '11' => 'Eleven', '12' => 'Twelve',
        '13' => 'Thirteen', '14' => 'Fourteen',
        '15' => 'Fifteen', '16' => 'Sixteen', '17' => 'Seventeen',
        '18' => 'Eighteen', '19' => 'Nineteen', '20' => 'Twenty',
        '30' => 'Thirty', '40' => 'Forty', '50' => 'Fifty',
        '60' => 'Sixty', '70' => 'Seventy', '80' => 'Eighty',
        '90' => 'Ninety'
    );
    $digits = array('', 'Hundred', 'Thousand', 'Lakh', 'Crore');
    while ($i < $digits_1) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += ($divider == 10) ? 1 : 2;
        if ($number) {
            $plural = (($counter = count($str)) && $number > 9) ? 's' : null;
            $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
            $str [] = ($number < 21) ? $words[$number] . " " . $digits[$counter] . $plural . " " . $hundred
                : $words[floor($number / 10) * 10] . " " . $words[$number % 10] . " " . $digits[$counter] . $plural . " " . $hundred;
        } else $str[] = null;
    }
    $str = array_reverse($str);
    $result = implode('', $str);
    $points = ($point) ? "and " . ($words[$point / 10] . " " . $words[$point % 10]) . " Paise" : '';
    return ($result ? $result . "Rupees " : "") . $points . " Only";
}
$companySettings = [];
$cStmt = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'company_%'");
while ($row = $cStmt->fetch()) {
    $companySettings[$row['setting_key']] = $row['setting_value'];
}

$cName    = $companySettings['company_name'] ?? APP_NAME;
$cAddress = $companySettings['company_address'] ?? '';
$cEmail   = $companySettings['company_email'] ?? '';
$cPhone   = $companySettings['company_phone'] ?? '';
$cWeb     = $companySettings['company_website'] ?? '';
$cLogo    = $companySettings['company_logo'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Payslip — <?= htmlspecialchars($slip['employee_name']) ?> (<?= $monthNames[$slip['month']] ?> <?= $slip['year'] ?>)</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body { background: #f8fafc; font-family: 'Inter', system-ui, sans-serif; color: #1e293b; padding: 20px 0; }
    .payslip-box { max-width: 850px; background: #ffffff; border-radius: 12px; border: 1px solid #cbd5e1; box-shadow: 0 10px 25px rgba(0,0,0,0.08); padding: 40px; margin: 0 auto; }
    .header-logo { font-size: 1.5rem; font-weight: 800; color: #4f46e5; }
    .table-payslip th { background-color: #f1f5f9; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #cbd5e1; }
    .table-payslip td { font-size: 0.875rem; padding: 8px 12px; }
    .net-box { background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; }
    @media print {
      body { background: #ffffff; padding: 0; }
      .no-print { display: none !important; }
      .payslip-box { border: none; box-shadow: none; padding: 0; margin: 0; width: 100%; max-width: 100%; }
    }
  </style>
</head>
<body>

<div class="container">
  <!-- Control Action Buttons -->
  <div class="d-flex justify-content-between align-items-center mb-4 no-print" style="max-width: 850px; margin: 0 auto;">
    <a href="<?= BASE_URL ?>/modules/hr/payroll.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Payroll</a>
    <div class="d-flex gap-2">
      <button onclick="window.print()" class="btn btn-sm btn-primary px-3"><i class="bi bi-printer me-1"></i>Print / Save PDF</button>
    </div>
  </div>

  <div class="payslip-box">
    <!-- Payslip Top Header (Salary Slip Left, Company Info Right) -->
    <div class="d-flex justify-content-between align-items-start border-bottom pb-4 mb-4 flex-wrap gap-3">
      <!-- LEFT SIDE: Salary Slip Title & Period -->
      <div class="text-start">
        <h4 class="fw-extrabold text-primary mb-1" style="font-weight:800; letter-spacing:-0.02em;">SALARY SLIP</h4>
        <div class="badge bg-primary text-uppercase font-monospace px-3 py-2 fs-6 mb-1"><?= $monthNames[$slip['month']] ?> <?= $slip['year'] ?></div>
        <div class="small text-muted font-monospace mt-1">Slip No: <strong><?= htmlspecialchars($slip['slip_number']) ?></strong></div>
      </div>

      <!-- RIGHT SIDE: Reduced Company Logo, Name & Address -->
      <div class="text-end ms-auto">
        <div class="d-flex flex-column align-items-end">
          <?php if (!empty($cLogo)): ?>
            <img src="<?= BASE_URL . '/' . htmlspecialchars($cLogo) ?>" alt="Company Logo" style="max-height: 42px; max-width: 150px; object-fit: contain;" class="mb-2 rounded">
          <?php endif; ?>
          <h5 class="fw-bold text-dark mb-1" style="font-weight:800;"><?= htmlspecialchars($cName) ?></h5>
          <?php if (!empty($cAddress)): ?>
            <div class="small text-muted mb-1 text-end" style="font-size:0.8rem; line-height:1.3; max-width:320px;"><?= nl2br(htmlspecialchars($cAddress)) ?></div>
          <?php endif; ?>
          <div class="small text-muted text-end" style="font-size:0.78rem;">
            <?php if (!empty($cEmail)): ?><span class="ms-2"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($cEmail) ?></span><?php endif; ?>
            <?php if (!empty($cPhone)): ?><span class="ms-2"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($cPhone) ?></span><?php endif; ?>
            <?php if (!empty($cWeb)): ?><span class="ms-2"><i class="bi bi-globe me-1"></i><?= htmlspecialchars($cWeb) ?></span><?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Employee & Bank Info Table -->
    <div class="bg-light rounded p-3 mb-4 border">
      <div class="row g-3">
        <div class="col-md-6 border-end">
          <div class="d-flex justify-content-between py-1 small">
            <span class="text-muted">Employee Name:</span>
            <span class="fw-bold"><?= htmlspecialchars($slip['employee_name']) ?></span>
          </div>
          <div class="d-flex justify-content-between py-1 small">
            <span class="text-muted">Employee Code:</span>
            <span class="fw-bold font-monospace"><?= htmlspecialchars($slip['emp_number']) ?></span>
          </div>
          <div class="d-flex justify-content-between py-1 small">
            <span class="text-muted">Designation:</span>
            <span class="fw-semibold"><?= htmlspecialchars($slip['designation']) ?></span>
          </div>
          <div class="d-flex justify-content-between py-1 small">
            <span class="text-muted">Department:</span>
            <span class="fw-semibold"><?= htmlspecialchars($slip['department'] ?: 'General') ?></span>
          </div>
          <div class="d-flex justify-content-between py-1 small">
            <span class="text-muted">Joining Date:</span>
            <span><?= date('d M Y', strtotime($slip['joining_date'])) ?></span>
          </div>
        </div>

        <div class="col-md-6">
          <div class="d-flex justify-content-between py-1 small">
            <span class="text-muted">Payment Date:</span>
            <span class="fw-bold"><?= date('d M Y', strtotime($slip['payment_date'])) ?></span>
          </div>
          <div class="d-flex justify-content-between py-1 small">
            <span class="text-muted">Payment Mode:</span>
            <span class="text-uppercase fw-semibold"><?= str_replace('_', ' ', $slip['payment_mode']) ?></span>
          </div>
          <div class="d-flex justify-content-between py-1 small">
            <span class="text-muted">Transaction Ref:</span>
            <span class="font-monospace"><?= htmlspecialchars($slip['transaction_ref'] ?: 'N/A') ?></span>
          </div>
          <div class="d-flex justify-content-between py-1 small">
            <span class="text-muted">Bank & A/C:</span>
            <span class="font-monospace"><?= htmlspecialchars($slip['bank_name'] ?: 'N/A') ?> (<?= htmlspecialchars($slip['bank_account_no'] ?: 'N/A') ?>)</span>
          </div>
          <div class="d-flex justify-content-between py-1 small">
            <span class="text-muted">PAN Number:</span>
            <span class="font-monospace"><?= htmlspecialchars($slip['pan_no'] ?: 'N/A') ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Salary Breakdown Table -->
    <div class="row g-4 mb-4">
      <div class="col-6">
        <h6 class="fw-bold text-success border-bottom pb-2 mb-2"><i class="bi bi-plus-circle me-1"></i>Earnings & Allowances</h6>
        <table class="table table-sm table-payslip align-middle mb-0">
          <thead>
            <tr>
              <th>Component</th>
              <th class="text-end">Amount (₹)</th>
            </tr>
          </thead>
          <tbody>
            <tr><td>Basic Salary</td><td class="text-end">₹<?= number_format($slip['basic_salary'], 2) ?></td></tr>
            <tr><td>HRA</td><td class="text-end">₹<?= number_format($slip['hra'], 2) ?></td></tr>
            <tr><td>Conveyance Allowance</td><td class="text-end">₹<?= number_format($slip['conveyance'], 2) ?></td></tr>
            <tr><td>Special Allowance</td><td class="text-end">₹<?= number_format($slip['special_allowance'], 2) ?></td></tr>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr>
              <td>Gross Monthly Earnings</td>
              <td class="text-end text-success">₹<?= number_format($slip['gross_salary'], 2) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="col-6">
        <h6 class="fw-bold text-danger border-bottom pb-2 mb-2"><i class="bi bi-dash-circle me-1"></i>Deductions</h6>
        <table class="table table-sm table-payslip align-middle mb-0">
          <thead>
            <tr>
              <th>Deduction</th>
              <th class="text-end">Amount (₹)</th>
            </tr>
          </thead>
          <tbody>
            <tr><td>PF Deduction</td><td class="text-end">₹<?= number_format($slip['pf_deduction'], 2) ?></td></tr>
            <tr><td>TDS / Income Tax</td><td class="text-end">₹<?= number_format($slip['tds_deduction'], 2) ?></td></tr>
            <tr><td>Other Deductions</td><td class="text-end">₹<?= number_format($slip['other_deductions'], 2) ?></td></tr>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr>
              <td>Total Deductions</td>
              <td class="text-end text-danger">₹<?= number_format($slip['total_deductions'], 2) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Net Payable Box -->
    <div class="net-box mb-4">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <span class="text-uppercase small fw-bold text-success">Net Salary Disbursed</span>
          <div class="small text-muted italic mt-1">Amount in Words: <strong><?= numberToWords($slip['net_salary']) ?></strong></div>
        </div>
        <div class="text-end">
          <h2 class="fw-bold text-success mb-0">₹<?= number_format($slip['net_salary'], 2) ?></h2>
        </div>
      </div>
    </div>

    <?php if ($slip['notes']): ?>
      <div class="small text-muted mb-4"><strong>Notes:</strong> <?= htmlspecialchars($slip['notes']) ?></div>
    <?php endif; ?>

    <!-- Signature Footer -->
    <div class="row pt-5 mt-4 text-center text-muted small">
      <div class="col-6">
        <div class="border-top pt-2 mx-auto style-sig" style="width: 200px;">Employee Signature</div>
      </div>
      <div class="col-6">
        <div class="border-top pt-2 mx-auto style-sig" style="width: 200px;">Authorized HR Signatory</div>
      </div>
    </div>

    <div class="text-center text-muted small mt-4 pt-3 border-top">
      This is a computer-generated salary slip and does not require a physical signature if verified digitally.
    </div>
  </div>
</div>

</body>
</html>
