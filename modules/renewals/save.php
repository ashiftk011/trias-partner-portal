<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('renewals');

$db = getDB();

// Pre-fill from client or renewal
$preClientId  = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$renewFromId  = isset($_GET['renew'])     ? (int)$_GET['renew']     : 0;
$preRenewal   = null;
$preClient    = null;

if ($renewFromId) {
    $stmt = $db->prepare("SELECT rn.*,c.name as client_name,c.client_code,pl.price FROM renewals rn LEFT JOIN clients c ON c.id=rn.client_id LEFT JOIN plans pl ON pl.id=rn.plan_id WHERE rn.id=?");
    $stmt->execute([$renewFromId]);
    $preRenewal = $stmt->fetch();
    if ($preRenewal) $preClientId = $preRenewal['client_id'];
}
if ($preClientId) {
    $stmt = $db->prepare("SELECT * FROM clients WHERE id=?");
    $stmt->execute([$preClientId]);
    $preClient = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $clientId  = (int)$_POST['client_id'];
    $planId    = (int)$_POST['plan_id'];
    $startDate = $_POST['start_date'];
    $endDate   = $_POST['end_date'];
    $amount    = (float)$_POST['amount'];
    $status    = $_POST['status'];
    $notes     = trim($_POST['notes'] ?? '');
    $userId    = currentUser()['id'];

    if (!$clientId || !$planId || !$startDate || !$endDate) {
        setFlash('error','All required fields must be filled.');
        redirect($_SERVER['REQUEST_URI']);
    }

    $db->prepare("INSERT INTO renewals (client_id,plan_id,start_date,end_date,amount,status,notes,renewed_by) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$clientId,$planId,$startDate,$endDate,$amount,$status,$notes,$userId]);

    // Update client plan
    $db->prepare("UPDATE clients SET plan_id=? WHERE id=?")->execute([$planId,$clientId]);

    // Mark old active renewals as expired
    if ($renewFromId) {
        $db->prepare("UPDATE renewals SET status='expired' WHERE id=?")->execute([$renewFromId]);
    }

    setFlash('success','Renewal added successfully.');
    redirect(BASE_URL . '/modules/clients/view.php?id=' . $clientId);
}

$clients = $db->query("SELECT id,name,client_code,company,plan_id FROM clients WHERE status='active' ORDER BY name")->fetchAll();
$plans   = $db->query("SELECT pl.*,p.name as project_name FROM plans pl LEFT JOIN projects p ON p.id=pl.project_id WHERE pl.status='active' ORDER BY p.name,pl.name")->fetchAll();

$pageTitle = 'Add Renewal';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/renewals/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0"><?= $renewFromId ? 'Renew Subscription' : 'Add Renewal' ?></h4>
    <?php if ($preClient): ?><p class="text-muted small mb-0">Client: <?= htmlspecialchars($preClient['name']) ?></p><?php endif; ?>
  </div>
</div>

<?php displayFlash(); ?>

<div class="row justify-content-center">
  <div class="col-xl-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-arrow-repeat me-2 text-primary"></i>Renewal Details</div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <div class="mb-3">
            <label class="form-label">Client *</label>
            <select name="client_id" id="rnClient" class="form-select select2" required>
              <option value="">Select Client</option>
              <?php foreach ($clients as $cl): ?>
              <option value="<?= $cl['id'] ?>" data-plan="<?= $cl['plan_id'] ?>" <?= $cl['id']==$preClientId?'selected':'' ?>>
                <?= htmlspecialchars($cl['client_code'].' - '.$cl['name'].($cl['company']?' ('.$cl['company'].')':'')) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Plan *</label>
            <select name="plan_id" id="rnPlan" class="form-select select2" required>
              <option value="">Select Plan</option>
              <?php foreach ($plans as $pl): ?>
              <option value="<?= $pl['id'] ?>" data-price="<?= $pl['price'] ?>" data-duration="<?= $pl['duration_months'] ?>"
                      <?= ($preRenewal && $preRenewal['plan_id']==$pl['id']) || (!$preRenewal && $preClient && $preClient['plan_id']==$pl['id']) ? 'selected':'' ?>>
                <?= htmlspecialchars($pl['project_name'].' — '.$pl['name'].' (₹'.number_format($pl['price'],2).')') ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Start Date *</label>
              <input type="date" name="start_date" id="rnStart" class="form-control"
                     value="<?= $preRenewal ? date('Y-m-d', strtotime($preRenewal['end_date'].'+1 day')) : date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">End Date *</label>
              <input type="date" name="end_date" id="rnEnd" class="form-control" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Amount (₹) *</label>
            <div class="input-group">
              <span class="input-group-text">₹</span>
              <input type="number" name="amount" id="rnAmount" class="form-control" step="0.01" min="0" required
                     value="<?= $preRenewal ? $preRenewal['price'] : '' ?>">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="active">Active</option>
              <option value="expired">Expired</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="mb-4">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Renewal</button>
            <a href="<?= BASE_URL ?>/modules/renewals/index.php" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
$('#rnPlan').on('change', function() {
  const opt = this.options[this.selectedIndex];
  const price = opt.dataset.price;
  const duration = parseInt(opt.dataset.duration) || 0;
  if (price) $('#rnAmount').val(price);
  if (duration > 0) {
    const start = new Date($('#rnStart').val());
    if (!isNaN(start)) {
      start.setMonth(start.getMonth() + duration);
      start.setDate(start.getDate() - 1);
      $('#rnEnd').val(start.toISOString().split('T')[0]);
    }
  }
});
$('#rnStart').on('change', function() { $('#rnPlan').trigger('change'); });
if ($('#rnPlan').val()) $('#rnPlan').trigger('change');
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
