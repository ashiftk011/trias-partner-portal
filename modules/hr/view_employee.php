<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('hr');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/hr/employees.php');

$stmt = $db->prepare("SELECT * FROM employees WHERE id=?");
$stmt->execute([$id]);
$employee = $stmt->fetch();
if (!$employee) redirect(BASE_URL . '/modules/hr/employees.php');

// Fetch salary structure
$sStmt = $db->prepare("SELECT * FROM salary_structures WHERE employee_id=?");
$sStmt->execute([$id]);
$struct = $sStmt->fetch();

// Fetch salary revision history
$revStmt = $db->prepare("SELECT sr.*, u.name as created_by_name FROM salary_revisions sr LEFT JOIN users u ON u.id = sr.created_by WHERE sr.employee_id=? ORDER BY sr.effective_date DESC, sr.created_at DESC");
$revStmt->execute([$id]);
$revisions = $revStmt->fetchAll();

// Fetch historical payslips / salary payments
$pStmt = $db->prepare("SELECT sp.*, u.name as processed_by_name FROM salary_payments sp LEFT JOIN users u ON u.id = sp.created_by WHERE sp.employee_id=? ORDER BY sp.year DESC, sp.month DESC");
$pStmt->execute([$id]);
$payslips = $pStmt->fetchAll();

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

$pageTitle = 'Employee Profile: ' . $employee['name'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
  <div class="d-flex align-items-center gap-3">
    <a href="<?= BASE_URL ?>/modules/hr/employees.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <div>
      <h4 class="mb-0"><?= htmlspecialchars($employee['name']) ?> <small class="text-muted fs-6 font-monospace">(<?= htmlspecialchars($employee['emp_number']) ?>)</small></h4>
      <p class="text-muted small mb-0"><?= htmlspecialchars($employee['designation']) ?> — <?= htmlspecialchars($employee['department'] ?: 'General') ?></p>
    </div>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?= statusBadge($employee['status']) ?>
    <a href="<?= BASE_URL ?>/modules/hr/save_employee.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit / Revise Salary</a>
    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#verifySalaryModal">
      <i class="bi bi-receipt me-1"></i>Verify & Process Salary
    </button>
  </div>
</div>

<?php displayFlash(); ?>

<div class="row g-4">
  <!-- Employee Basic Details & Bank Info -->
  <div class="col-xl-4">
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-person me-2 text-primary"></i>Personal & Work Info</div>
      <div class="card-body">
        <?php
        $info = [
          'Emp Code'     => $employee['emp_number'],
          'Official Email' => $employee['email'],
          'Phone'        => $employee['phone'] ?: '-',
          'Department'   => $employee['department'] ?: 'General',
          'Designation'  => $employee['designation'],
          'Joining Date' => date('d M Y', strtotime($employee['joining_date'])),
          'Status'       => ucfirst($employee['status']),
        ];
        foreach ($info as $label => $val): ?>
        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small"><?= $label ?></span>
          <span class="fw-semibold small text-end text-break"><?= htmlspecialchars($val) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-bank me-2 text-primary"></i>Bank & Statutory Details</div>
      <div class="card-body">
        <?php
        $bankInfo = [
          'Bank Name'      => $employee['bank_name'] ?: '-',
          'Account Number' => $employee['bank_account_no'] ?: '-',
          'IFSC Code'      => $employee['ifsc_code'] ?: '-',
          'PAN Number'     => $employee['pan_no'] ?: '-',
        ];
        foreach ($bankInfo as $label => $val): ?>
        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small"><?= $label ?></span>
          <span class="fw-semibold small font-monospace text-end"><?= htmlspecialchars($val) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Salary Structure, Revision History & Historical Payslips -->
  <div class="col-xl-8">
    <!-- Monthly Salary Breakdown -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calculator me-2 text-primary"></i>Current Configured Salary Structure</span>
        <a href="<?= BASE_URL ?>/modules/hr/save_employee.php?id=<?= $id ?>" class="btn btn-sm btn-link text-decoration-none p-0"><i class="bi bi-pencil"></i> Modify / Add Increment</a>
      </div>
      <div class="card-body">
        <?php if ($struct): ?>
          <?php
          $gross = ($struct['basic_salary'] + $struct['hra'] + $struct['conveyance'] + $struct['special_allowance']);
          $deductions = ($struct['pf_deduction'] + $struct['tds_deduction'] + $struct['other_deductions']);
          ?>
          <div class="row g-4">
            <div class="col-md-6 border-end">
              <h6 class="fw-bold text-success mb-3"><i class="bi bi-plus-circle me-1"></i>Monthly Earnings</h6>
              <div class="d-flex justify-content-between border-bottom py-1 small"><span>Basic Salary</span><span class="fw-semibold">₹<?= number_format($struct['basic_salary'], 2) ?></span></div>
              <div class="d-flex justify-content-between border-bottom py-1 small"><span>HRA</span><span class="fw-semibold">₹<?= number_format($struct['hra'], 2) ?></span></div>
              <div class="d-flex justify-content-between border-bottom py-1 small"><span>Conveyance</span><span class="fw-semibold">₹<?= number_format($struct['conveyance'], 2) ?></span></div>
              <div class="d-flex justify-content-between border-bottom py-1 small"><span>Special Allowance</span><span class="fw-semibold">₹<?= number_format($struct['special_allowance'], 2) ?></span></div>
              <div class="d-flex justify-content-between pt-2 fw-bold text-dark"><span>Gross Monthly Earnings</span><span>₹<?= number_format($gross, 2) ?></span></div>
            </div>
            <div class="col-md-6">
              <h6 class="fw-bold text-danger mb-3"><i class="bi bi-dash-circle me-1"></i>Monthly Deductions</h6>
              <div class="d-flex justify-content-between border-bottom py-1 small"><span>PF Deduction</span><span class="fw-semibold">₹<?= number_format($struct['pf_deduction'], 2) ?></span></div>
              <div class="d-flex justify-content-between border-bottom py-1 small"><span>TDS / Income Tax</span><span class="fw-semibold">₹<?= number_format($struct['tds_deduction'], 2) ?></span></div>
              <div class="d-flex justify-content-between border-bottom py-1 small"><span>Other Deductions</span><span class="fw-semibold">₹<?= number_format($struct['other_deductions'], 2) ?></span></div>
              <div class="d-flex justify-content-between pt-2 fw-bold text-danger"><span>Total Deductions</span><span>₹<?= number_format($deductions, 2) ?></span></div>
            </div>
          </div>
          <div class="alert alert-success d-flex justify-content-between align-items-center mt-3 mb-0 py-2">
            <span class="fw-semibold">Net Take-Home Monthly Salary</span>
            <span class="fs-5 fw-bold">₹<?= number_format($struct['net_salary'], 2) ?></span>
          </div>
        <?php else: ?>
          <div class="text-muted text-center py-3">Salary structure not configured yet. <a href="<?= BASE_URL ?>/modules/hr/save_employee.php?id=<?= $id ?>">Click here to configure</a></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Salary Increment & Revision History -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Salary Revision & Increment History</span>
        <a href="<?= BASE_URL ?>/modules/hr/save_employee.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary py-0" style="font-size:0.75rem;"><i class="bi bi-plus"></i> New Increment</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($revisions)): ?>
          <div class="text-center text-muted py-3">No revision history logged yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th>Effective Date</th>
                  <th>Type</th>
                  <th>Basic Salary</th>
                  <th>Net Monthly</th>
                  <th>Revision Notes</th>
                  <th>Logged By</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($revisions as $rev): 
                  $typeBadge = $rev['revision_type'] === 'increment' ? 'bg-success' : ($rev['revision_type'] === 'promotion' ? 'bg-primary' : 'bg-secondary');
                ?>
                <tr>
                  <td class="fw-semibold text-nowrap"><?= date('d M Y', strtotime($rev['effective_date'])) ?></td>
                  <td><span class="badge <?= $typeBadge ?>"><?= ucfirst($rev['revision_type']) ?></span></td>
                  <td class="small">₹<?= number_format($rev['basic_salary'], 2) ?></td>
                  <td class="fw-bold text-success">₹<?= number_format($rev['net_salary'], 2) ?></td>
                  <td class="small text-muted"><?= htmlspecialchars($rev['notes'] ?: '—') ?></td>
                  <td class="small"><?= htmlspecialchars($rev['created_by_name'] ?: 'System') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Salary Slips History -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt me-2 text-primary"></i>Salary Slips & Disbursement Records</span>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#verifySalaryModal">
          <i class="bi bi-plus-circle me-1"></i>Generate Salary Slip
        </button>
      </div>
      <div class="card-body p-0">
        <?php if (empty($payslips)): ?>
          <div class="text-center text-muted py-4">
            <i class="bi bi-receipt fs-1 opacity-25"></i>
            <p class="mt-2 mb-0">No salary slips generated for this employee yet.</p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th>Slip No</th>
                  <th>Month & Year</th>
                  <th>Payment Date</th>
                  <th>Gross Salary</th>
                  <th>Deductions</th>
                  <th>Net Paid</th>
                  <th>Status</th>
                  <th class="text-center">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($payslips as $ps): ?>
                <tr>
                  <td class="fw-bold font-monospace text-primary"><?= htmlspecialchars($ps['slip_number']) ?></td>
                  <td class="fw-semibold"><?= $monthNames[$ps['month']] ?? $ps['month'] ?> <?= $ps['year'] ?></td>
                  <td class="small"><?= date('d M Y', strtotime($ps['payment_date'])) ?></td>
                  <td class="small">₹<?= number_format($ps['gross_salary'], 2) ?></td>
                  <td class="small text-danger">₹<?= number_format($ps['total_deductions'], 2) ?></td>
                  <td class="fw-bold text-success">₹<?= number_format($ps['net_salary'], 2) ?></td>
                  <td><?= statusBadge($ps['status']) ?></td>
                  <td class="text-center">
                    <a href="<?= BASE_URL ?>/modules/hr/payslip.php?id=<?= $ps['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="View & Download Salary Slip">
                      <i class="bi bi-download me-1"></i>Payslip
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Verify & Process Salary for this Employee -->
<div class="modal fade" id="verifySalaryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" action="<?= BASE_URL ?>/modules/hr/payroll.php">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="employee_id" value="<?= $id ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-receipt me-2 text-primary"></i>Verify & Generate Salary Slip for <?= htmlspecialchars($employee['name']) ?></h5>
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

            <!-- Editable Salary Components for Verification / Bonus Adjustment -->
            <div class="col-12 border-top pt-3 mt-2">
              <h6 class="fw-bold small text-muted text-uppercase mb-2"><i class="bi bi-sliders me-1 text-primary"></i>Verify / Adjust Earnings & Deductions for this Disbursement</h6>
              <div class="row g-2 bg-light p-3 rounded border">
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">Basic (₹)</label>
                  <input type="number" step="0.01" name="basic_salary" id="v_basic" class="form-control form-control-sm v-calc" value="<?= (float)($struct['basic_salary'] ?? 0) ?>">
                </div>
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">HRA (₹)</label>
                  <input type="number" step="0.01" name="hra" id="v_hra" class="form-control form-control-sm v-calc" value="<?= (float)($struct['hra'] ?? 0) ?>">
                </div>
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">Conveyance (₹)</label>
                  <input type="number" step="0.01" name="conveyance" id="v_conveyance" class="form-control form-control-sm v-calc" value="<?= (float)($struct['conveyance'] ?? 0) ?>">
                </div>
                <div class="col-md-3">
                  <label class="form-label micro-label fw-semibold">Special / Bonus (₹)</label>
                  <input type="number" step="0.01" name="special_allowance" id="v_special" class="form-control form-control-sm v-calc" value="<?= (float)($struct['special_allowance'] ?? 0) ?>">
                </div>
                <div class="col-md-4 mt-2">
                  <label class="form-label micro-label fw-semibold text-danger">PF Deduction (₹)</label>
                  <input type="number" step="0.01" name="pf_deduction" id="v_pf" class="form-control form-control-sm v-calc" value="<?= (float)($struct['pf_deduction'] ?? 0) ?>">
                </div>
                <div class="col-md-4 mt-2">
                  <label class="form-label micro-label fw-semibold text-danger">TDS / Income Tax (₹)</label>
                  <input type="number" step="0.01" name="tds_deduction" id="v_tds" class="form-control form-control-sm v-calc" value="<?= (float)($struct['tds_deduction'] ?? 0) ?>">
                </div>
                <div class="col-md-4 mt-2">
                  <label class="form-label micro-label fw-semibold text-danger">Other Deductions (₹)</label>
                  <input type="number" step="0.01" name="other_deductions" id="v_other" class="form-control form-control-sm v-calc" value="<?= (float)($struct['other_deductions'] ?? 0) ?>">
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
            <span class="small fw-semibold"><i class="bi bi-check-circle me-1"></i>Verified Net Disbursement:</span>
            <span class="fs-5 fw-bold" id="verifyNetSalaryPreview">₹0.00</span>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Confirm & Generate Payslip</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function recalculateVerifySalary() {
  const getVal = (id) => parseFloat(document.getElementById(id).value) || 0;
  const basic = getVal('v_basic');
  const hra = getVal('v_hra');
  const conveyance = getVal('v_conveyance');
  const special = getVal('v_special');

  const pf = getVal('v_pf');
  const tds = getVal('v_tds');
  const other = getVal('v_other');

  const gross = basic + hra + conveyance + special;
  const ded = pf + tds + other;
  const net = Math.max(0, gross - ded);

  document.getElementById('verifyNetSalaryPreview').innerText = '₹' + net.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

document.querySelectorAll('.v-calc').forEach(el => {
  el.addEventListener('input', recalculateVerifySalary);
});

document.addEventListener('DOMContentLoaded', recalculateVerifySalary);
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
