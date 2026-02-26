<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('leads');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect(BASE_URL . '/modules/leads/index.php'); }

$stmt = $db->prepare("SELECT l.*,p.name as project_name,pl.name as plan_name,pl.price as plan_price,pl.duration_months FROM leads l LEFT JOIN projects p ON p.id=l.project_id LEFT JOIN plans pl ON pl.id=l.interested_plan_id WHERE l.id=?");
$stmt->execute([$id]);
$lead = $stmt->fetch();
if (!$lead || $lead['status'] === 'converted') {
    setFlash('error', $lead ? 'Lead is already converted.' : 'Lead not found.');
    redirect(BASE_URL . '/modules/leads/index.php');
}

$plans   = $db->query("SELECT pl.*,p.name as project_name,r.name as region_name FROM plans pl LEFT JOIN projects p ON p.id=pl.project_id LEFT JOIN regions r ON r.id=pl.region_id WHERE pl.status='active' ORDER BY p.name,pl.name")->fetchAll();
$regions = $db->query("SELECT id,name FROM regions WHERE status='active' ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name       = trim($_POST['name']);
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone']);
    $company    = trim($_POST['company'] ?? '');
    $designation= trim($_POST['designation'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $city       = trim($_POST['city'] ?? '');
    $state      = trim($_POST['state'] ?? '');
    $pincode    = trim($_POST['pincode'] ?? '');
    $gst        = trim($_POST['gst_no'] ?? '');
    $pan        = trim($_POST['pan_no'] ?? '');
    $projectId  = (int)$_POST['project_id'];
    $planId     = $_POST['plan_id'] ? (int)$_POST['plan_id'] : null;
    $regionId   = $_POST['region_id'] ? (int)$_POST['region_id'] : null;
    $joinedDate = $_POST['joined_date'] ?: date('Y-m-d');

    // Create client
    $clientCode = generateCode('CL', 'clients', 'client_code');
    $db->prepare("INSERT INTO clients (client_code,lead_id,project_id,plan_id,region_id,name,email,phone,company,designation,address,city,state,pincode,gst_no,pan_no,joined_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$clientCode,$id,$projectId,$planId,$regionId,$name,$email,$phone,$company,$designation,$address,$city,$state,$pincode,$gst,$pan,$joinedDate]);

    $clientId = $db->lastInsertId();

    // Create initial renewal if plan has duration
    if ($planId) {
        $planInfo = $db->prepare("SELECT * FROM plans WHERE id=?");
        $planInfo->execute([$planId]);
        $planInfo = $planInfo->fetch();

        if ($planInfo && $planInfo['duration_months'] > 0) {
            $endDate = date('Y-m-d', strtotime("+{$planInfo['duration_months']} months", strtotime($joinedDate)));
            $db->prepare("INSERT INTO renewals (client_id,plan_id,start_date,end_date,amount,status,renewed_by) VALUES (?,?,?,?,?,?,?)")
               ->execute([$clientId,$planId,$joinedDate,$endDate,$planInfo['price'],'active',currentUser()['id']]);
        }
    }

    // Mark lead as converted
    $db->prepare("UPDATE leads SET status='converted' WHERE id=?")->execute([$id]);

    setFlash('success', "Lead converted to client successfully! Client Code: {$clientCode}");
    redirect(BASE_URL . '/modules/clients/view.php?id=' . $clientId);
}

$pageTitle = 'Convert Lead to Client';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0">Convert Lead to Client</h4>
    <p class="text-muted small mb-0">Lead: <?= htmlspecialchars($lead['name']) ?> | <?= htmlspecialchars($lead['project_name']) ?></p>
  </div>
</div>

<!-- Lead Summary -->
<div class="alert alert-info d-flex gap-3 mb-4">
  <i class="bi bi-info-circle-fill fs-4"></i>
  <div>
    <strong>Converting:</strong> <?= htmlspecialchars($lead['name']) ?> (<?= htmlspecialchars($lead['phone']) ?>)
    <?php if ($lead['plan_name']): ?>
    | Interested in: <strong><?= htmlspecialchars($lead['plan_name']) ?></strong> @ ₹<?= number_format($lead['plan_price'],2) ?>
    <?php endif; ?>
  </div>
</div>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
  <div class="row g-4">
    <!-- Client Info -->
    <div class="col-xl-8">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-person-badge me-2 text-primary"></i>Client Information</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name *</label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($lead['name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone *</label>
              <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($lead['phone']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($lead['email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Company</label>
              <input type="text" name="company" class="form-control" value="<?= htmlspecialchars($lead['company'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Designation</label>
              <input type="text" name="designation" class="form-control" value="<?= htmlspecialchars($lead['designation'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Joining Date</label>
              <input type="date" name="joined_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-geo-alt me-2 text-primary"></i>Address & Tax Details</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Address</label>
              <textarea name="address" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">City</label>
              <input type="text" name="city" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">State</label>
              <input type="text" name="state" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Pincode</label>
              <input type="text" name="pincode" class="form-control" maxlength="10">
            </div>
            <div class="col-md-6">
              <label class="form-label">GST Number</label>
              <input type="text" name="gst_no" class="form-control" placeholder="22AAAAA0000A1Z5">
            </div>
            <div class="col-md-6">
              <label class="form-label">PAN Number</label>
              <input type="text" name="pan_no" class="form-control" placeholder="AAAAA0000A">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Plan & Project -->
    <div class="col-xl-4">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-clipboard-check me-2 text-primary"></i>Plan & Project</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Project *</label>
            <select name="project_id" id="convProject" class="form-select select2" required>
              <?php foreach ($db->query("SELECT id,name FROM projects WHERE status='active'") as $pr): ?>
              <option value="<?= $pr['id'] ?>" <?= $pr['id']==$lead['project_id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Region</label>
            <select name="region_id" id="convRegion" class="form-select select2">
              <option value="">Select Region</option>
              <?php foreach ($regions as $r): ?>
              <option value="<?= $r['id'] ?>" <?= $r['id']==$lead['region_id']?'selected':'' ?>><?= htmlspecialchars($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Select Plan</label>
            <select name="plan_id" id="convPlan" class="form-select select2">
              <option value="">-- No Plan --</option>
              <?php foreach ($plans as $pl): ?>
              <option value="<?= $pl['id'] ?>" <?= $pl['id']==$lead['interested_plan_id']?'selected':'' ?>>
                <?= htmlspecialchars($pl['name']) ?> (₹<?= number_format($pl['price'],2) ?>)
                <?php if ($pl['region_name']): ?>- <?= htmlspecialchars($pl['region_name']) ?><?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Plan Summary -->
          <div id="planSummary" class="bg-light rounded p-3 d-none">
            <h6 class="fw-semibold mb-2"><i class="bi bi-clipboard-check me-1"></i>Plan Summary</h6>
            <div id="planDetails"></div>
          </div>
        </div>
      </div>

      <div class="d-grid gap-2">
        <button type="submit" class="btn btn-success btn-lg">
          <i class="bi bi-person-check me-2"></i>Convert to Client
        </button>
        <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </div>
  </div>
</form>

<script>
const allPlansData = <?= json_encode($plans) ?>;

$('#convPlan').on('change', function() {
  const planId = this.value;
  const plan = allPlansData.find(p => p.id == planId);
  const summary = document.getElementById('planSummary');
  if (plan) {
    summary.classList.remove('d-none');
    document.getElementById('planDetails').innerHTML = `
      <div class="small"><strong>Price:</strong> ₹${parseFloat(plan.price).toLocaleString('en-IN', {minimumFractionDigits:2})}</div>
      <div class="small"><strong>Duration:</strong> ${plan.duration_months > 0 ? plan.duration_months + ' months' : 'One-time'}</div>
      ${plan.features ? `<div class="small mt-1 text-muted">${plan.features}</div>` : ''}
      ${plan.duration_months > 0 ? '<div class="small text-success mt-1"><i class="bi bi-info-circle"></i> Renewal will be auto-created</div>' : ''}
    `;
  } else {
    summary.classList.add('d-none');
  }
});

// Trigger on load if plan pre-selected
if ($('#convPlan').val()) $('#convPlan').trigger('change');
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
