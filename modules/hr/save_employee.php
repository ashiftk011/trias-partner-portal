<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('hr');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$employee = null;
$salaryStruct = null;

if ($id) {
    $stmt = $db->prepare("SELECT * FROM employees WHERE id=?");
    $stmt->execute([$id]);
    $employee = $stmt->fetch();
    if (!$employee) redirect(BASE_URL . '/modules/hr/employees.php');

    $sStmt = $db->prepare("SELECT * FROM salary_structures WHERE employee_id=?");
    $sStmt->execute([$id]);
    $salaryStruct = $sStmt->fetch();
}

// Generate default emp number if adding new
if (!$employee) {
    $lastEmp = $db->query("SELECT id FROM employees ORDER BY id DESC LIMIT 1")->fetchColumn();
    $nextNum = ((int)$lastEmp) + 1;
    $defaultEmpNumber = 'EMP-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
} else {
    $defaultEmpNumber = $employee['emp_number'];
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $empNumber   = trim($_POST['emp_number'] ?? '');
    $name        = trim($_POST['name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $passwordRaw = trim($_POST['password'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $department  = trim($_POST['department'] ?? 'General');
    $employmentType    = trim($_POST['employment_type'] ?? 'full_time');
    $joiningDate = trim($_POST['joining_date'] ?? '');
    $status      = trim($_POST['status'] ?? 'active');

    $contractStartDate = trim($_POST['contract_start_date'] ?? '') ?: null;
    $contractEndDate   = trim($_POST['contract_end_date'] ?? '') ?: null;
    $contractRateType  = trim($_POST['contract_rate_type'] ?? 'monthly');
    $contractRate      = (float)($_POST['contract_rate'] ?? 0);
    $contractTerms     = trim($_POST['contract_terms'] ?? '');

    $bankName    = trim($_POST['bank_name'] ?? '');
    $bankAccNo   = trim($_POST['bank_account_no'] ?? '');
    $ifscCode    = trim($_POST['ifsc_code'] ?? '');
    $panNo       = trim($_POST['pan_no'] ?? '');
    $gstNo       = trim($_POST['gst_no'] ?? '');

    // Salary Structure inputs
    $basicSalary = (float)($_POST['basic_salary'] ?? 0);
    $hra         = (float)($_POST['hra'] ?? 0);
    $conveyance  = (float)($_POST['conveyance'] ?? 0);
    $specialAllow= (float)($_POST['special_allowance'] ?? 0);
    $pfDeduction = (float)($_POST['pf_deduction'] ?? 0);
    $tdsDeduction= (float)($_POST['tds_deduction'] ?? 0);
    $otherDeductions = (float)($_POST['other_deductions'] ?? 0);

    $gross = $basicSalary + $hra + $conveyance + $specialAllow;
    $totalDeductions = $pfDeduction + $tdsDeduction + $otherDeductions;
    $netSalary = max(0, $gross - $totalDeductions);

    // Revision Meta
    $effectiveDate = trim($_POST['effective_date'] ?? date('Y-m-d'));
    $revisionType  = trim($_POST['revision_type'] ?? ($id ? 'increment' : 'initial'));
    $revisionNotes = trim($_POST['revision_notes'] ?? '');

    if (!$empNumber || !$name || !$email || !$designation || !$joiningDate) {
        setFlash('danger', 'Employee Number, Name, Official Email, Designation, and Joining Date are required.');
    } else {
        // Unique email check
        $chk = $db->prepare("SELECT id FROM employees WHERE (email=? OR emp_number=?) AND id != ?");
        $chk->execute([$email, $empNumber, $id]);
        if ($chk->fetch()) {
            setFlash('danger', 'An employee with this email or employee number already exists.');
        } else {
            if ($id) {
                // Update employee
                if ($passwordRaw !== '') {
                    $hash = password_hash($passwordRaw, PASSWORD_BCRYPT);
                    $db->prepare("UPDATE employees SET emp_number=?, name=?, email=?, password=?, phone=?, designation=?, department=?, employment_type=?, joining_date=?, contract_start_date=?, contract_end_date=?, contract_rate_type=?, contract_rate=?, contract_terms=?, status=?, bank_name=?, bank_account_no=?, ifsc_code=?, pan_no=?, gst_no=? WHERE id=?")
                       ->execute([$empNumber, $name, $email, $hash, $phone, $designation, $department, $employmentType, $joiningDate, $contractStartDate, $contractEndDate, $contractRateType, $contractRate, $contractTerms, $status, $bankName, $bankAccNo, $ifscCode, $panNo, $gstNo, $id]);
                } else {
                    $db->prepare("UPDATE employees SET emp_number=?, name=?, email=?, phone=?, designation=?, department=?, employment_type=?, joining_date=?, contract_start_date=?, contract_end_date=?, contract_rate_type=?, contract_rate=?, contract_terms=?, status=?, bank_name=?, bank_account_no=?, ifsc_code=?, pan_no=?, gst_no=? WHERE id=?")
                       ->execute([$empNumber, $name, $email, $phone, $designation, $department, $employmentType, $joiningDate, $contractStartDate, $contractEndDate, $contractRateType, $contractRate, $contractTerms, $status, $bankName, $bankAccNo, $ifscCode, $panNo, $gstNo, $id]);
                }
                $empId = $id;
                setFlash('success', 'Employee record updated successfully.');
            } else {
                // Create employee
                $hash = password_hash($passwordRaw ?: 'password123', PASSWORD_BCRYPT);
                $db->prepare("INSERT INTO employees (emp_number, name, email, password, phone, designation, department, employment_type, joining_date, contract_start_date, contract_end_date, contract_rate_type, contract_rate, contract_terms, status, bank_name, bank_account_no, ifsc_code, pan_no, gst_no) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$empNumber, $name, $email, $hash, $phone, $designation, $department, $employmentType, $joiningDate, $contractStartDate, $contractEndDate, $contractRateType, $contractRate, $contractTerms, $status, $bankName, $bankAccNo, $ifscCode, $panNo, $gstNo]);
                $empId = $db->lastInsertId();
                setFlash('success', 'New employee added successfully.');
            }

            // Check if salary structure values changed
            $oldNet = (float)($salaryStruct['net_salary'] ?? -1);
            $hasSalaryChanged = ($oldNet !== $netSalary || !$salaryStruct);

            // Save / Update Salary Structure
            $db->prepare("INSERT INTO salary_structures (employee_id, basic_salary, hra, conveyance, special_allowance, pf_deduction, tds_deduction, other_deductions, net_salary) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE basic_salary=?, hra=?, conveyance=?, special_allowance=?, pf_deduction=?, tds_deduction=?, other_deductions=?, net_salary=?")
               ->execute([$empId, $basicSalary, $hra, $conveyance, $specialAllow, $pfDeduction, $tdsDeduction, $otherDeductions, $netSalary,
                          $basicSalary, $hra, $conveyance, $specialAllow, $pfDeduction, $tdsDeduction, $otherDeductions, $netSalary]);

            // Log Salary Revision History
            if ($hasSalaryChanged || $revisionNotes !== '') {
                $db->prepare("INSERT INTO salary_revisions (employee_id, effective_date, revision_type, basic_salary, hra, conveyance, special_allowance, pf_deduction, tds_deduction, other_deductions, net_salary, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$empId, $effectiveDate, $revisionType, $basicSalary, $hra, $conveyance, $specialAllow, $pfDeduction, $tdsDeduction, $otherDeductions, $netSalary, $revisionNotes ?: ($id ? 'Salary Revision' : 'Initial Salary Structure'), currentUser()['id']]);
            }

            redirect(BASE_URL . '/modules/hr/view_employee.php?id=' . $empId);
        }
    }
}

$pageTitle = ($id ? 'Edit Employee: ' . $employee['name'] : 'Add New Employee');
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/hr/employees.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0"><?= $id ? 'Edit Employee Record' : 'Add New Employee' ?></h4>
    <p class="text-muted small mb-0">Configure official credentials, work role, bank details, and monthly salary structure</p>
  </div>
</div>

<?php displayFlash(); ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

  <div class="row g-4">
    <!-- Basic Employee Profile -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-person me-2 text-primary"></i>Employee Profile</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Employee Code <span class="text-danger">*</span></label>
              <input type="text" name="emp_number" class="form-control font-monospace" required value="<?= htmlspecialchars($_POST['emp_number'] ?? ($employee['emp_number'] ?? $defaultEmpNumber)) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Status</label>
              <select name="status" class="form-select">
                <?php foreach (['active'=>'Active','inactive'=>'Inactive','resigned'=>'Resigned','terminated'=>'Terminated'] as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= ($_POST['status'] ?? ($employee['status'] ?? 'active')) === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-12">
              <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required placeholder="e.g. John Doe" value="<?= htmlspecialchars($_POST['name'] ?? ($employee['name'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Official Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required placeholder="john@company.com" value="<?= htmlspecialchars($_POST['email'] ?? ($employee['email'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Portal Password <?= $id ? '<small class="text-muted">(Leave blank to keep current)</small>' : '<span class="text-danger">*</span>' ?></label>
              <div class="input-group">
                <input type="password" name="password" class="form-control" <?= $id ? '' : 'required' ?> placeholder="<?= $id ? '••••••••' : 'Min 6 chars' ?>">
                <button type="button" class="btn btn-outline-secondary" onclick="togglePass(this)"><i class="bi bi-eye"></i></button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Phone Number</label>
              <input type="text" name="phone" class="form-control" placeholder="+91 9876543210" value="<?= htmlspecialchars($_POST['phone'] ?? ($employee['phone'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Joining Date <span class="text-danger">*</span></label>
              <input type="date" name="joining_date" class="form-control" required value="<?= htmlspecialchars($_POST['joining_date'] ?? ($employee['joining_date'] ?? date('Y-m-d'))) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Designation <span class="text-danger">*</span></label>
              <input type="text" name="designation" class="form-control" required placeholder="e.g. Senior Software Engineer" value="<?= htmlspecialchars($_POST['designation'] ?? ($employee['designation'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Department</label>
              <input type="text" name="department" class="form-control" placeholder="e.g. Engineering, Sales, HR" value="<?= htmlspecialchars($_POST['department'] ?? ($employee['department'] ?? 'General')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Employment Type <span class="text-danger">*</span></label>
              <select name="employment_type" id="employment_type" class="form-select fw-semibold" onchange="toggleEmploymentTypeFields()">
                <?php
                $empTypes = [
                  'full_time' => 'Full-Time (Regular)',
                  'contract'  => 'Contract Employee',
                  'freelance' => 'Freelance / Consultant',
                  'intern'    => 'Intern / Trainee'
                ];
                $currType = $_POST['employment_type'] ?? ($employee['employment_type'] ?? 'full_time');
                foreach ($empTypes as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= $currType === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Bank Details & Identification -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-bank me-2 text-primary"></i>Bank & Statutory Details</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Bank Name</label>
              <input type="text" name="bank_name" class="form-control" placeholder="e.g. HDFC Bank, ICICI Bank" value="<?= htmlspecialchars($_POST['bank_name'] ?? ($employee['bank_name'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Account Number</label>
              <input type="text" name="bank_account_no" class="form-control font-monospace" placeholder="e.g. 50100012345678" value="<?= htmlspecialchars($_POST['bank_account_no'] ?? ($employee['bank_account_no'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">IFSC Code</label>
              <input type="text" name="ifsc_code" class="form-control font-monospace text-uppercase" placeholder="e.g. HDFC0001234" value="<?= htmlspecialchars($_POST['ifsc_code'] ?? ($employee['ifsc_code'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">PAN Number</label>
              <input type="text" name="pan_no" class="form-control font-monospace text-uppercase" placeholder="e.g. ABCDE1234F" value="<?= htmlspecialchars($_POST['pan_no'] ?? ($employee['pan_no'] ?? '')) ?>">
            </div>
            <div class="col-md-12">
              <label class="form-label small fw-semibold">GST Number (GSTIN) <small class="text-muted">(Optional for Freelancers/Contractors)</small></label>
              <input type="text" name="gst_no" class="form-control font-monospace text-uppercase" placeholder="e.g. 29ABCDE1234F1Z5" value="<?= htmlspecialchars($_POST['gst_no'] ?? ($employee['gst_no'] ?? '')) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Contract & Freelance Terms (Shown dynamically when Contract / Freelance is selected) -->
    <div class="col-xl-12" id="contractTermsCard" style="<?= in_array($currType, ['contract', 'freelance']) ? '' : 'display: none;' ?>">
      <div class="card border-0 shadow-sm border-start border-4 border-warning">
        <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
          <span class="text-warning-emphasis"><i class="bi bi-file-earmark-text-fill me-2 text-warning"></i>Contract & Freelance Agreement Terms</span>
          <span class="badge bg-warning bg-opacity-25 text-dark border border-warning"><i class="bi bi-briefcase me-1"></i>Engagement Parameters</span>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Contract Start Date</label>
              <input type="date" name="contract_start_date" id="contract_start_date" class="form-control" value="<?= htmlspecialchars($_POST['contract_start_date'] ?? ($employee['contract_start_date'] ?? '')) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Contract End Date</label>
              <input type="date" name="contract_end_date" id="contract_end_date" class="form-control" value="<?= htmlspecialchars($_POST['contract_end_date'] ?? ($employee['contract_end_date'] ?? '')) ?>">
              <div class="form-text small text-muted">Leave empty if ongoing / indefinite retainer.</div>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Billing Model / Basis</label>
              <select name="contract_rate_type" id="contract_rate_type" class="form-select">
                <?php
                $rateTypes = [
                  'monthly'       => 'Monthly Retainer / Fixed Fee',
                  'hourly'        => 'Hourly Rate',
                  'fixed_project' => 'Fixed Milestone / Project Fee',
                  'daily'         => 'Daily Billing Rate'
                ];
                $currRateType = $_POST['contract_rate_type'] ?? ($employee['contract_rate_type'] ?? 'monthly');
                foreach ($rateTypes as $rVal => $rLbl): ?>
                  <option value="<?= $rVal ?>" <?= $currRateType === $rVal ? 'selected' : '' ?>><?= $rLbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Agreed Rate / Fee (₹)</label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" step="0.01" name="contract_rate" id="contract_rate" class="form-control font-monospace fw-bold" placeholder="0.00" value="<?= htmlspecialchars($_POST['contract_rate'] ?? ($employee['contract_rate'] ?? '0.00')) ?>">
              </div>
            </div>
            <div class="col-md-12">
              <label class="form-label small fw-semibold">Deliverables, Scope of Work & Agreement Terms</label>
              <textarea name="contract_terms" class="form-control" rows="2" placeholder="Summary of deliverables, weekly hours, payment terms, notice period, or SLA requirements..."><?= htmlspecialchars($_POST['contract_terms'] ?? ($employee['contract_terms'] ?? '')) ?></textarea>
            </div>
            <div class="col-md-12 d-flex justify-content-end">
              <button type="button" class="btn btn-sm btn-outline-warning" onclick="applyContractRateToSalary()">
                <i class="bi bi-arrow-down-circle me-1"></i>Copy Contract Rate into Monthly Retainer / Payout Structure
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Monthly Salary Structure & Revision Log -->
    <div class="col-xl-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
          <span><i class="bi bi-calculator me-2 text-primary"></i><span id="salaryCardTitle">Monthly Salary / Retainer Structure</span> & Increment Details</span>
          <span class="badge bg-success fs-6" id="netSalaryBadge">Net Salary: ₹0.00</span>
        </div>
        <div class="card-body">
          <div class="alert alert-info py-2 small d-none" id="contractSalaryNote">
            <i class="bi bi-info-circle me-1"></i><strong>Freelance / Contract Note:</strong> You can set their monthly retainer/fee under <strong>Basic Salary / Fee</strong>, with 0 PF and TDS (Section 194J / 194C) if applicable.
          </div>
          <div class="row g-4 mb-3">
            <!-- Allowances & Earnings -->
            <div class="col-md-6 border-end">
              <h6 class="fw-bold text-success mb-3"><i class="bi bi-plus-circle me-1"></i>Earnings & Retainer Fees</h6>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label small fw-semibold" id="basicSalaryLabel">Basic Salary / Fee (₹)</label>
                  <input type="number" step="0.01" name="basic_salary" id="basic_salary" class="form-control calc-salary font-monospace" value="<?= htmlspecialchars($_POST['basic_salary'] ?? ($salaryStruct['basic_salary'] ?? '0.00')) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">HRA (₹)</label>
                  <input type="number" step="0.01" name="hra" id="hra" class="form-control calc-salary font-monospace" value="<?= htmlspecialchars($_POST['hra'] ?? ($salaryStruct['hra'] ?? '0.00')) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Conveyance Allowance (₹)</label>
                  <input type="number" step="0.01" name="conveyance" id="conveyance" class="form-control calc-salary font-monospace" value="<?= htmlspecialchars($_POST['conveyance'] ?? ($salaryStruct['conveyance'] ?? '0.00')) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Special Allowance / Bonus (₹)</label>
                  <input type="number" step="0.01" name="special_allowance" id="special_allowance" class="form-control calc-salary font-monospace" value="<?= htmlspecialchars($_POST['special_allowance'] ?? ($salaryStruct['special_allowance'] ?? '0.00')) ?>">
                </div>
              </div>
            </div>

            <!-- Deductions -->
            <div class="col-md-6">
              <h6 class="fw-bold text-danger mb-3"><i class="bi bi-dash-circle me-1"></i>Deductions</h6>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">PF Deduction (₹)</label>
                  <input type="number" step="0.01" name="pf_deduction" id="pf_deduction" class="form-control calc-salary font-monospace" value="<?= htmlspecialchars($_POST['pf_deduction'] ?? ($salaryStruct['pf_deduction'] ?? '0.00')) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">TDS / Withholding Tax (₹)</label>
                  <input type="number" step="0.01" name="tds_deduction" id="tds_deduction" class="form-control calc-salary font-monospace" value="<?= htmlspecialchars($_POST['tds_deduction'] ?? ($salaryStruct['tds_deduction'] ?? '0.00')) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Other Deductions (₹)</label>
                  <input type="number" step="0.01" name="other_deductions" id="other_deductions" class="form-control calc-salary font-monospace" value="<?= htmlspecialchars($_POST['other_deductions'] ?? ($salaryStruct['other_deductions'] ?? '0.00')) ?>">
                </div>
              </div>
            </div>
          </div>

          <!-- Revision Meta Info -->
          <div class="border-top pt-3 mt-3 bg-light p-3 rounded">
            <h6 class="fw-bold small text-muted text-uppercase mb-2"><i class="bi bi-journal-text me-1 text-primary"></i>Increment / Revision History Log Entry</h6>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Effective Date</label>
                <input type="date" name="effective_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Revision Type</label>
                <select name="revision_type" class="form-select form-select-sm">
                  <option value="increment">Annual Increment</option>
                  <option value="revision">Salary / Retainer Revision</option>
                  <option value="promotion">Promotion Revision</option>
                  <option value="initial">Initial Engagement Structure</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Revision Reason / Notes</label>
                <input type="text" name="revision_notes" class="form-control form-control-sm" placeholder="e.g. Rate revision, Contract extension, Annual hike">
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>Save Personnel & Payment Structure</button>
    <a href="<?= BASE_URL ?>/modules/hr/employees.php" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<script>
function togglePass(btn) {
  const input = btn.previousElementSibling;
  const icon = btn.querySelector('i');
  if (input.type === 'password') { input.type = 'text'; icon.className = 'bi bi-eye-slash'; }
  else { input.type = 'password'; icon.className = 'bi bi-eye'; }
}

function toggleEmploymentTypeFields() {
  const type = document.getElementById('employment_type').value;
  const contractCard = document.getElementById('contractTermsCard');
  const contractNote = document.getElementById('contractSalaryNote');
  const basicLabel   = document.getElementById('basicSalaryLabel');
  const cardTitle    = document.getElementById('salaryCardTitle');

  if (type === 'contract' || type === 'freelance') {
    contractCard.style.display = 'block';
    contractNote.classList.remove('d-none');
    basicLabel.innerText = type === 'freelance' ? 'Consultant Retainer / Fee (₹)' : 'Contract Retainer / Fee (₹)';
    cardTitle.innerText = type === 'freelance' ? 'Monthly Freelance Retainer' : 'Monthly Contract Retainer';
  } else {
    contractCard.style.display = 'none';
    contractNote.classList.add('d-none');
    basicLabel.innerText = 'Basic Salary (₹)';
    cardTitle.innerText = 'Monthly Salary Structure';
  }
}

function applyContractRateToSalary() {
  const rate = parseFloat(document.getElementById('contract_rate').value) || 0;
  document.getElementById('basic_salary').value = rate.toFixed(2);
  document.getElementById('hra').value = '0.00';
  document.getElementById('conveyance').value = '0.00';
  document.getElementById('special_allowance').value = '0.00';
  document.getElementById('pf_deduction').value = '0.00';
  // Compute TDS 10% under 194J for professional consultants if positive
  const tds = rate > 0 ? (rate * 0.10) : 0;
  document.getElementById('tds_deduction').value = tds.toFixed(2);
  document.getElementById('other_deductions').value = '0.00';
  calculateSalary();
}

function calculateSalary() {
  const getNum = (id) => parseFloat(document.getElementById(id).value) || 0;
  const basic = getNum('basic_salary');
  const hra = getNum('hra');
  const conveyance = getNum('conveyance');
  const special = getNum('special_allowance');

  const pf = getNum('pf_deduction');
  const tds = getNum('tds_deduction');
  const other = getNum('other_deductions');

  const gross = basic + hra + conveyance + special;
  const deductions = pf + tds + other;
  const net = Math.max(0, gross - deductions);

  const type = document.getElementById('employment_type') ? document.getElementById('employment_type').value : 'full_time';
  const prefix = (type === 'contract' || type === 'freelance') ? 'Net Retainer: ₹' : 'Net Salary: ₹';

  document.getElementById('netSalaryBadge').innerText = prefix + net.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

document.querySelectorAll('.calc-salary').forEach(el => {
  el.addEventListener('input', calculateSalary);
});

document.addEventListener('DOMContentLoaded', () => {
  toggleEmploymentTypeFields();
  calculateSalary();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

