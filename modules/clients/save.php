<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('clients');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$client = null;

if ($id) {
    $stmt = $db->prepare("SELECT * FROM clients WHERE id=?");
    $stmt->execute([$id]);
    $client = $stmt->fetch();
    if (!$client) { setFlash('danger','Client not found.'); redirect(BASE_URL . '/modules/clients/index.php'); }
}

// Generate next client code
function generateClientCode($db) {
    $row = $db->query("SELECT client_code FROM clients ORDER BY id DESC LIMIT 1")->fetch();
    if ($row && preg_match('/CLT-(\d+)/', $row['client_code'], $m)) {
        return 'CLT-' . str_pad((int)$m[1] + 1, 4, '0', STR_PAD_LEFT);
    }
    return 'CLT-0001';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'name'        => trim($_POST['name'] ?? ''),
        'email'       => trim($_POST['email'] ?? ''),
        'phone'       => trim($_POST['phone'] ?? ''),
        'alt_phone'   => trim($_POST['alt_phone'] ?? ''),
        'company'     => trim($_POST['company'] ?? ''),
        'designation' => trim($_POST['designation'] ?? ''),
        'project_id'  => (int)($_POST['project_id'] ?? 0),
        'plan_id'     => $_POST['plan_id'] ? (int)$_POST['plan_id'] : null,
        'region_id'   => $_POST['region_id'] ? (int)$_POST['region_id'] : null,
        'address'     => trim($_POST['address'] ?? ''),
        'city'        => trim($_POST['city'] ?? ''),
        'state'       => trim($_POST['state'] ?? ''),
        'pincode'     => trim($_POST['pincode'] ?? ''),
        'gst_no'      => trim($_POST['gst_no'] ?? ''),
        'pan_no'      => trim($_POST['pan_no'] ?? ''),
        'joined_date' => $_POST['joined_date'] ?: date('Y-m-d'),
        'status'      => $_POST['status'] ?? 'active',
    ];

    if (!$data['name'] || !$data['project_id']) {
        setFlash('danger', 'Client name and project are required.');
        redirect(BASE_URL . '/modules/clients/save.php' . ($id ? "?id=$id" : ''));
    }

    if ($id) {
        // Update
        $db->prepare("UPDATE clients SET name=?,email=?,phone=?,alt_phone=?,company=?,designation=?,
            project_id=?,plan_id=?,region_id=?,address=?,city=?,state=?,pincode=?,gst_no=?,pan_no=?,
            joined_date=?,status=? WHERE id=?")
           ->execute([
               $data['name'],$data['email'],$data['phone'],$data['alt_phone'],$data['company'],$data['designation'],
               $data['project_id'],$data['plan_id'],$data['region_id'],$data['address'],$data['city'],
               $data['state'],$data['pincode'],$data['gst_no'],$data['pan_no'],$data['joined_date'],$data['status'],$id
           ]);
        setFlash('success', 'Client updated successfully.');
    } else {
        // Insert
        $clientCode = generateClientCode($db);
        $db->prepare("INSERT INTO clients (client_code,name,email,phone,alt_phone,company,designation,
            project_id,plan_id,region_id,address,city,state,pincode,gst_no,pan_no,joined_date,status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $clientCode,$data['name'],$data['email'],$data['phone'],$data['alt_phone'],$data['company'],
               $data['designation'],$data['project_id'],$data['plan_id'],$data['region_id'],$data['address'],
               $data['city'],$data['state'],$data['pincode'],$data['gst_no'],$data['pan_no'],
               $data['joined_date'],$data['status']
           ]);
        $id = $db->lastInsertId();
        setFlash('success', "Client created with code $clientCode.");
    }
    redirect(BASE_URL . '/modules/clients/view.php?id=' . $id);
}

// Form data
$projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
$regions  = $db->query("SELECT id,name FROM regions WHERE status='active' ORDER BY name")->fetchAll();
$plans    = $db->query("SELECT id,project_id,name,price FROM plans WHERE status='active' ORDER BY name")->fetchAll();

$c = $client ?? [
    'name'=>'','email'=>'','phone'=>'','alt_phone'=>'','company'=>'','designation'=>'',
    'project_id'=>'','plan_id'=>'','region_id'=>'','address'=>'','city'=>'','state'=>'',
    'pincode'=>'','gst_no'=>'','pan_no'=>'','joined_date'=>date('Y-m-d'),'status'=>'active'
];

$pageTitle = $id ? 'Edit Client' : 'Add Client';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/clients/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0"><?= $id ? 'Edit Client' : 'Add New Client' ?></h4>
    <p class="text-muted small mb-0"><?= $id ? htmlspecialchars($client['client_code'] ?? '') . ' — ' . htmlspecialchars($client['name']) : 'Create a new client directly' ?></p>
  </div>
</div>

<?php displayFlash(); ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

  <div class="row g-4">
    <!-- Basic Info -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-person-fill me-2 text-primary"></i>Client Information</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Client Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control form-control-sm" required value="<?= htmlspecialchars($c['name']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Company</label>
              <input type="text" name="company" class="form-control form-control-sm" value="<?= htmlspecialchars($c['company']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Phone</label>
              <input type="text" name="phone" class="form-control form-control-sm" value="<?= htmlspecialchars($c['phone']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Alt. Phone</label>
              <input type="text" name="alt_phone" class="form-control form-control-sm" value="<?= htmlspecialchars($c['alt_phone']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Email</label>
              <input type="email" name="email" class="form-control form-control-sm" value="<?= htmlspecialchars($c['email']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Designation</label>
              <input type="text" name="designation" class="form-control form-control-sm" value="<?= htmlspecialchars($c['designation']) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Project & Plan -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-diagram-3 me-2 text-primary"></i>Project & Plan</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Project <span class="text-danger">*</span></label>
              <select name="project_id" id="projectSelect" class="form-select form-select-sm" required>
                <option value="">Select Project</option>
                <?php foreach ($projects as $pr): ?>
                <option value="<?= $pr['id'] ?>" <?= ($c['project_id'] ?? '')==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Plan <span class="text-muted">(optional)</span></label>
              <select name="plan_id" id="planSelect" class="form-select form-select-sm">
                <option value="">No Plan</option>
                <?php foreach ($plans as $pl): ?>
                <option value="<?= $pl['id'] ?>" data-project="<?= $pl['project_id'] ?>" <?= ($c['plan_id'] ?? '')==$pl['id']?'selected':'' ?>>
                  <?= htmlspecialchars($pl['name']) ?> (₹<?= number_format($pl['price'],0) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Region</label>
              <select name="region_id" class="form-select form-select-sm">
                <option value="">-- Select --</option>
                <?php foreach ($regions as $r): ?>
                <option value="<?= $r['id'] ?>" <?= ($c['region_id'] ?? '')==$r['id']?'selected':'' ?>><?= htmlspecialchars($r['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Joined Date</label>
              <input type="date" name="joined_date" class="form-control form-control-sm" value="<?= htmlspecialchars($c['joined_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Status</label>
              <select name="status" class="form-select form-select-sm">
                <option value="active" <?= ($c['status'] ?? '')==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= ($c['status'] ?? '')==='inactive'?'selected':'' ?>>Inactive</option>
                <option value="suspended" <?= ($c['status'] ?? '')==='suspended'?'selected':'' ?>>Suspended</option>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Address & Tax -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-geo-alt-fill me-2 text-primary"></i>Address</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small fw-semibold">Address</label>
              <textarea name="address" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($c['address']) ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">City</label>
              <input type="text" name="city" class="form-control form-control-sm" value="<?= htmlspecialchars($c['city']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">State</label>
              <input type="text" name="state" class="form-control form-control-sm" value="<?= htmlspecialchars($c['state']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Pincode</label>
              <input type="text" name="pincode" class="form-control form-control-sm" value="<?= htmlspecialchars($c['pincode']) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Tax Info -->
    <div class="col-xl-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Tax / ID</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">GST No.</label>
              <input type="text" name="gst_no" class="form-control form-control-sm" value="<?= htmlspecialchars($c['gst_no']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">PAN No.</label>
              <input type="text" name="pan_no" class="form-control form-control-sm" value="<?= htmlspecialchars($c['pan_no']) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?= $id ? 'Update Client' : 'Create Client' ?></button>
    <a href="<?= BASE_URL ?>/modules/clients/index.php" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<script>
// Filter plans dropdown based on selected project
document.getElementById('projectSelect').addEventListener('change', function() {
  const projectId = this.value;
  const planSelect = document.getElementById('planSelect');
  const options = planSelect.querySelectorAll('option[data-project]');
  
  // Show/hide plan options based on project
  options.forEach(opt => {
    opt.style.display = (!projectId || opt.dataset.project === projectId) ? '' : 'none';
    // Deselect hidden options
    if (opt.style.display === 'none' && opt.selected) {
      opt.selected = false;
      planSelect.value = '';
    }
  });
});

// Trigger on page load to filter plans
document.getElementById('projectSelect').dispatchEvent(new Event('change'));
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
