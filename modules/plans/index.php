<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('plans');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $projectId = (int)$_POST['project_id'];
        $regionId  = $_POST['region_id'] ? (int)$_POST['region_id'] : null;
        $name      = trim($_POST['name']);
        $price     = (float)$_POST['price'];
        $duration  = (int)$_POST['duration_months'];
        $features  = trim($_POST['features'] ?? '');
        $status    = $_POST['status'];

        if ($id > 0) {
            $db->prepare("UPDATE plans SET project_id=?,region_id=?,name=?,price=?,duration_months=?,features=?,status=? WHERE id=?")
               ->execute([$projectId,$regionId,$name,$price,$duration,$features,$status,$id]);
            setFlash('success','Plan updated.');
        } else {
            $db->prepare("INSERT INTO plans (project_id,region_id,name,price,duration_months,features,status) VALUES (?,?,?,?,?,?,?)")
               ->execute([$projectId,$regionId,$name,$price,$duration,$features,$status]);
            setFlash('success','Plan created.');
        }
    } elseif ($action === 'delete') {
        $db->prepare("DELETE FROM plans WHERE id=?")->execute([(int)$_POST['id']]);
        setFlash('success','Plan deleted.');
    }
    redirect(BASE_URL . '/modules/plans/index.php');
}

$filterProject = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$filterRegion  = isset($_GET['region_id'])  ? (int)$_GET['region_id']  : 0;

$sql = "SELECT pl.*, p.name as project_name, r.name as region_name
        FROM plans pl
        LEFT JOIN projects p ON p.id=pl.project_id
        LEFT JOIN regions r ON r.id=pl.region_id
        WHERE 1=1";
$params = [];
if ($filterProject) { $sql .= " AND pl.project_id=?"; $params[] = $filterProject; }
if ($filterRegion)  { $sql .= " AND pl.region_id=?";  $params[] = $filterRegion; }
$sql .= " ORDER BY p.name, r.name, pl.name";
$stmt = $db->prepare($sql); $stmt->execute($params);
$plans = $stmt->fetchAll();

$projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
$regions  = $db->query("SELECT id,name FROM regions WHERE status='active' ORDER BY name")->fetchAll();

$pageTitle = 'Plans';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="mb-0">Plans</h4>
    <p class="text-muted small mb-0">Manage plans by project and region</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#planModal">
    <i class="bi bi-plus-circle me-1"></i> Add Plan
  </button>
</div>

<?php displayFlash(); ?>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-md-4">
        <select name="project_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Projects</option>
          <?php foreach ($projects as $pr): ?>
          <option value="<?= $pr['id'] ?>" <?= $filterProject==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <select name="region_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Regions</option>
          <?php foreach ($regions as $r): ?>
          <option value="<?= $r['id'] ?>" <?= $filterRegion==$r['id']?'selected':'' ?>><?= htmlspecialchars($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($filterProject || $filterRegion): ?>
      <div class="col-auto"><a href="<?= BASE_URL ?>/modules/plans/index.php" class="btn btn-sm btn-outline-secondary">Clear Filters</a></div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if (empty($plans)): ?>
<div class="text-center py-5">
  <i class="bi bi-clipboard-x display-4 text-muted"></i>
  <p class="text-muted mt-3">No plans found. Click <strong>Add Plan</strong> to create one.</p>
</div>
<?php else: ?>
<div class="row g-4">
  <?php foreach ($plans as $i => $pl):
    $featureList = $pl['features'] ? array_filter(array_map('trim', preg_split('/[,\n]+/', $pl['features']))) : [];
    $isActive = $pl['status'] === 'active';
    // Cycle through card accent colors
    $colors = [
      ['#667eea','#764ba2'],
      ['#f093fb','#f5576c'],
      ['#4facfe','#00f2fe'],
      ['#43e97b','#38f9d7'],
      ['#fa709a','#fee140'],
      ['#a18cd1','#fbc2eb'],
    ];
    $colorPair = $colors[$i % count($colors)];
  ?>
  <div class="col-xl-4 col-lg-4 col-md-6">
    <div class="pricing-card <?= $isActive ? '' : 'pricing-card--inactive' ?>">
      <!-- Colored Top Bar -->
      <div class="pricing-card__ribbon" style="background:linear-gradient(135deg,<?= $colorPair[0] ?>,<?= $colorPair[1] ?>)"></div>

      <!-- Status Dot -->
      <div class="pricing-card__status">
        <span class="pricing-card__dot <?= $isActive ? 'pricing-card__dot--active' : 'pricing-card__dot--inactive' ?>"></span>
        <span class="small fw-medium <?= $isActive ? 'text-success' : 'text-muted' ?>"><?= ucfirst($pl['status']) ?></span>
      </div>

      <!-- Plan Name -->
      <h5 class="pricing-card__name"><?= htmlspecialchars($pl['name']) ?></h5>

      <!-- Price -->
      <div class="pricing-card__price">
        <span class="pricing-card__currency">₹</span>
        <span class="pricing-card__amount"><?= number_format($pl['price'], 0) ?></span>
        <?php if ($pl['duration_months']): ?>
        <span class="pricing-card__period">/ <?= $pl['duration_months'] ?> mo</span>
        <?php else: ?>
        <span class="pricing-card__period">one-time</span>
        <?php endif; ?>
      </div>

      <!-- Tags -->
      <div class="pricing-card__tags">
        <span class="pricing-card__tag pricing-card__tag--project">
          <i class="bi bi-building me-1"></i><?= htmlspecialchars($pl['project_name']) ?>
        </span>
        <span class="pricing-card__tag pricing-card__tag--region">
          <i class="bi bi-geo-alt me-1"></i><?= $pl['region_name'] ? htmlspecialchars($pl['region_name']) : 'All India' ?>
        </span>
      </div>

      <!-- Divider -->
      <hr class="pricing-card__divider">

      <!-- Features -->
      <ul class="pricing-card__features">
        <?php if (!empty($featureList)): ?>
          <?php foreach ($featureList as $feat): ?>
          <li>
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <span><?= htmlspecialchars($feat) ?></span>
          </li>
          <?php endforeach; ?>
        <?php else: ?>
          <li class="text-muted"><i class="bi bi-dash me-2"></i>No features listed</li>
        <?php endif; ?>
      </ul>

      <!-- Actions -->
      <div class="pricing-card__actions">
        <button class="btn btn-sm btn-outline-primary w-100 mb-2" onclick="editPlan(<?= htmlspecialchars(json_encode($pl)) ?>)">
          <i class="bi bi-pencil-square me-1"></i> Edit Plan
        </button>
        <form method="POST" onsubmit="return confirm('Delete this plan?')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $pl['id'] ?>">
          <button class="btn btn-sm btn-outline-danger w-100">
            <i class="bi bi-trash me-1"></i> Delete
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Plan Modal -->
<div class="modal fade" id="planModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="planId" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="planModalTitle">Add Plan</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Project *</label>
              <select name="project_id" id="plProject" class="form-select select2" required>
                <option value="">Select Project</option>
                <?php foreach ($projects as $pr): ?>
                <option value="<?= $pr['id'] ?>"><?= htmlspecialchars($pr['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Region <span class="text-muted">(blank = All India)</span></label>
              <select name="region_id" id="plRegion" class="form-select select2">
                <option value="">All India</option>
                <?php foreach ($regions as $r): ?>
                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Plan Name *</label>
              <input type="text" name="name" id="plName" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="plStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Price (₹) *</label>
              <div class="input-group">
                <span class="input-group-text">₹</span>
                <input type="number" name="price" id="plPrice" class="form-control" step="0.01" min="0" required>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Duration (months) <span class="text-muted">(0 = one-time)</span></label>
              <input type="number" name="duration_months" id="plDuration" class="form-control" value="12" min="0">
            </div>
            <div class="col-12">
              <label class="form-label">Features / Description</label>
              <textarea name="features" id="plFeatures" class="form-control" rows="3" placeholder="e.g. On-page SEO, 10 Keywords, Monthly Report"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Plan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editPlan(p) {
  document.getElementById('planId').value      = p.id;
  document.getElementById('plName').value      = p.name;
  document.getElementById('plPrice').value     = p.price;
  document.getElementById('plDuration').value  = p.duration_months;
  document.getElementById('plFeatures').value  = p.features || '';
  document.getElementById('plStatus').value    = p.status;
  $('#plProject').val(p.project_id).trigger('change');
  $('#plRegion').val(p.region_id || '').trigger('change');
  document.getElementById('planModalTitle').textContent = 'Edit Plan';
  new bootstrap.Modal(document.getElementById('planModal')).show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
