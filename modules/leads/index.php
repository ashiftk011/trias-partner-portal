<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('leads');

$db = getDB();
$isInvestor = isRole('investor');
$investorProjectId = $isInvestor ? getInvestorProjectId() : 0;

// Filters
$filterProject = $isInvestor ? $investorProjectId : (isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0);
$filterStatus  = $_GET['status'] ?? '';
$filterRegion  = isset($_GET['region_id']) ? (int)$_GET['region_id'] : 0;
$filterSearch  = trim($_GET['q'] ?? '');

$sql = "SELECT l.*, p.name as project_name, r.name as region_name, u.name as assigned_name
        FROM leads l
        LEFT JOIN projects p ON p.id=l.project_id
        LEFT JOIN regions r ON r.id=l.region_id
        LEFT JOIN users u ON u.id=l.assigned_to
        WHERE 1=1";
$params = [];

if ($filterProject) { $sql .= " AND l.project_id=?"; $params[] = $filterProject; }
if ($filterStatus)  { $sql .= " AND l.status=?";     $params[] = $filterStatus; }
if ($filterRegion)  { $sql .= " AND l.region_id=?";  $params[] = $filterRegion; }
if ($filterSearch)  { $sql .= " AND (l.name LIKE ? OR l.phone LIKE ? OR l.company LIKE ?)"; $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; }

$sql .= " ORDER BY l.created_at DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$leads = $stmt->fetchAll();

$projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
$regions  = $db->query("SELECT id,name FROM regions WHERE status='active' ORDER BY name")->fetchAll();
$agents   = $db->query("SELECT id,name FROM users WHERE role IN ('telecall','admin') AND status='active' ORDER BY name")->fetchAll();

// Status counts
$counts = $db->query("SELECT status, COUNT(*) as cnt FROM leads GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Leads';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-3">
  <div><h4 class="mb-0">Leads</h4><p class="text-muted small mb-0">Track and manage all leads</p></div>
  <?php if (!$isInvestor): ?>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#csvModal">
      <i class="bi bi-file-earmark-arrow-up me-1"></i>Import CSV
    </button>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#leadModal">
      <i class="bi bi-plus-circle me-1"></i> Add Lead
    </button>
  </div>
  <?php endif; ?>
</div>

<?php displayFlash(); ?>

<!-- Status Summary Pills -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php
  $statusColors = ['new'=>'primary','contacted'=>'info','interested'=>'warning','not_interested'=>'danger','follow_up'=>'secondary','converted'=>'success'];
  foreach ($statusColors as $st => $cls): ?>
  <a href="?status=<?= $st ?><?= $filterProject?"&project_id=$filterProject":'' ?>" class="badge bg-<?= $cls ?> text-decoration-none p-2 fs-6">
    <?= ucfirst(str_replace('_',' ',$st)) ?> <span class="ms-1"><?= $counts[$st] ?? 0 ?></span>
  </a>
  <?php endforeach; ?>
  <?php if ($filterStatus || $filterProject || $filterRegion || $filterSearch): ?>
  <a href="<?= BASE_URL ?>/modules/leads/index.php" class="badge bg-light text-dark text-decoration-none p-2 fs-6">
    <i class="bi bi-x-circle me-1"></i>Clear Filters
  </a>
  <?php endif; ?>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, phone, company..." value="<?= htmlspecialchars($filterSearch) ?>">
      </div>
      <div class="col-md-2">
        <?php if ($isInvestor): ?>
          <input type="hidden" name="project_id" value="<?= $investorProjectId ?>">
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($projects[array_search($investorProjectId, array_column($projects, 'id'))]['name'] ?? 'My Project') ?>" disabled>
        <?php else: ?>
        <select name="project_id" class="form-select form-select-sm">
          <option value="">All Projects</option>
          <?php foreach ($projects as $pr): ?>
          <option value="<?= $pr['id'] ?>" <?= $filterProject==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
      <div class="col-md-2">
        <select name="region_id" class="form-select form-select-sm">
          <option value="">All Regions</option>
          <?php foreach ($regions as $r): ?>
          <option value="<?= $r['id'] ?>" <?= $filterRegion==$r['id']?'selected':'' ?>><?= htmlspecialchars($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (array_keys($statusColors) as $st): ?>
          <option value="<?= $st ?>" <?= $filterStatus===$st?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable">
        <thead class="table-light">
          <tr><th>Code</th><th>Lead</th><th>Contact</th><th>Project</th><th>Region</th><th>Source</th><th>Status</th><th>Assigned</th><th>Date</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($leads as $l): ?>
          <tr>
            <td><small class="text-muted"><?= htmlspecialchars($l['lead_code'] ?? '') ?></small></td>
            <td>
              <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $l['id'] ?>" class="fw-semibold text-decoration-none">
                <?= htmlspecialchars($l['name']) ?>
              </a>
              <?php if ($l['company']): ?>
              <div class="text-muted small"><i class="bi bi-building"></i> <?= htmlspecialchars($l['company']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div><?= htmlspecialchars($l['phone']) ?></div>
              <?php if ($l['email']): ?><small class="text-muted"><?= htmlspecialchars($l['email']) ?></small><?php endif; ?>
            </td>
            <td><span class="badge bg-primary"><?= htmlspecialchars($l['project_name'] ?? '') ?></span></td>
            <td><?= $l['region_name'] ? htmlspecialchars($l['region_name']) : '<span class="text-muted">-</span>' ?></td>
            <td><small><?= ucfirst(str_replace('_',' ',$l['source'])) ?></small></td>
            <td><?= statusBadge($l['status']) ?></td>
            <td class="small"><?= htmlspecialchars($l['assigned_name'] ?? '-') ?></td>
            <td class="text-muted small"><?= date('d M Y', strtotime($l['created_at'])) ?></td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $l['id'] ?>" class="btn btn-outline-info" title="View"><i class="bi bi-eye"></i></a>
                <?php if (!$isInvestor): ?>
                <button class="btn btn-outline-primary" onclick="editLead(<?= htmlspecialchars(json_encode($l)) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                <?php if ($l['status'] !== 'converted' && hasAccess('clients')): ?>
                <a href="<?= BASE_URL ?>/modules/leads/convert.php?id=<?= $l['id'] ?>" class="btn btn-outline-success" title="Convert to Client"><i class="bi bi-person-check"></i></a>
                <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Lead Modal -->
<div class="modal fade" id="leadModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST" action="<?= BASE_URL ?>/modules/leads/save.php">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="id" id="leadId" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="leadModalTitle">Add Lead</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Full Name *</label>
              <input type="text" name="name" id="lName" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Phone *</label>
              <input type="text" name="phone" id="lPhone" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Email</label>
              <input type="email" name="email" id="lEmail" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Company</label>
              <input type="text" name="company" id="lCompany" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Designation</label>
              <input type="text" name="designation" id="lDesignation" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Project *</label>
              <select name="project_id" id="lProject" class="form-select select2" required>
                <option value="">Select Project</option>
                <?php foreach ($projects as $pr): ?>
                <option value="<?= $pr['id'] ?>"><?= htmlspecialchars($pr['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Region</label>
              <select name="region_id" id="lRegion" class="form-select select2">
                <option value="">Select Region</option>
                <?php foreach ($regions as $r): ?>
                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Source</label>
              <select name="source" id="lSource" class="form-select">
                <option value="website">Website</option>
                <option value="referral">Referral</option>
                <option value="social_media">Social Media</option>
                <option value="cold_call">Cold Call</option>
                <option value="email">Email</option>
                <option value="exhibition">Exhibition</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="lStatus" class="form-select">
                <option value="new">New</option>
                <option value="contacted">Contacted</option>
                <option value="interested">Interested</option>
                <option value="not_interested">Not Interested</option>
                <option value="follow_up">Follow Up</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Assign To</label>
              <select name="assigned_to" id="lAssigned" class="form-select select2">
                <option value="">Unassigned</option>
                <?php foreach ($agents as $ag): ?>
                <option value="<?= $ag['id'] ?>"><?= htmlspecialchars($ag['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Interested Plan</label>
              <select name="interested_plan_id" id="lPlan" class="form-select select2">
                <option value="">Not specified</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Website</label>
              <input type="url" name="website" id="lWebsite" class="form-control" placeholder="https://example.com">
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <textarea name="address" id="lAddress" class="form-control" rows="2" placeholder="Street, City, State, Pincode"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" id="lNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Lead</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="csvModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="<?= BASE_URL ?>/modules/leads/import.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="modal-header">
          <h5 class="modal-title">Import Leads via CSV</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-info small">
            <strong>Required columns:</strong> <code>name, phone, project_code</code><br>
            <strong>Optional columns:</strong> <code>email, company, designation, website, address, region_name, source, status, notes</code><br>
            First row must be headers.
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">CSV File <span class="text-danger">*</span></label>
            <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
          </div>
          <a href="<?= BASE_URL ?>/modules/leads/sample.csv" class="small text-decoration-none"><i class="bi bi-download me-1"></i>Download sample CSV</a>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload & Import</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const allPlans = <?= json_encode($db->query("SELECT id,project_id,name,price FROM plans WHERE status='active' ORDER BY name")->fetchAll()) ?>;

function loadPlans(projectId, selectedId = '') {
  const sel = document.getElementById('lPlan');
  sel.innerHTML = '<option value="">Not specified</option>';
  allPlans.filter(p => p.project_id == projectId).forEach(p => {
    const opt = new Option(`${p.name} (₹${parseFloat(p.price).toLocaleString()})`, p.id, p.id == selectedId, p.id == selectedId);
    sel.add(opt);
  });
  $(sel).trigger('change');
}

$('#lProject').on('change', function() { loadPlans(this.value); });

function editLead(l) {
  document.getElementById('leadId').value         = l.id;
  document.getElementById('lName').value          = l.name;
  document.getElementById('lPhone').value         = l.phone;
  document.getElementById('lEmail').value         = l.email || '';
  document.getElementById('lCompany').value       = l.company || '';
  document.getElementById('lDesignation').value   = l.designation || '';
  document.getElementById('lWebsite').value       = l.website || '';
  document.getElementById('lAddress').value       = l.address || '';
  document.getElementById('lSource').value        = l.source;
  document.getElementById('lStatus').value        = l.status;
  document.getElementById('lNotes').value         = l.notes || '';
  $('#lProject').val(l.project_id).trigger('change');
  $('#lRegion').val(l.region_id || '').trigger('change');
  $('#lAssigned').val(l.assigned_to || '').trigger('change');
  loadPlans(l.project_id, l.interested_plan_id);
  document.getElementById('leadModalTitle').textContent = 'Edit Lead';
  new bootstrap.Modal(document.getElementById('leadModal')).show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
