<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('leads');

$db = getDB();
$user       = currentUser();
$isInvestor = isRole('investor');
$isTelecall = isRole('telecall');
$isAdmin    = isRole('admin');
$investorProjectId    = $isInvestor ? getInvestorProjectId() : 0;
$telecallProjectIds   = $isTelecall ? getTelecallProjectIds() : [];

// Filters
if ($isInvestor) {
    $filterProject = $investorProjectId;
} elseif ($isTelecall) {
    // Telecall may only filter within their assigned projects
    $req = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    $filterProject = ($req && in_array($req, $telecallProjectIds)) ? $req : 0;
} else {
    $filterProject = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
}
$filterStatus  = $_GET['status'] ?? '';
$filterRegion  = isset($_GET['region_id']) ? (int)$_GET['region_id'] : 0;
$filterSource  = $_GET['source'] ?? '';
$filterSearch  = trim($_GET['q'] ?? '');

$sql = "SELECT l.*, p.name as project_name, r.name as region_name, u.name as assigned_name
        FROM leads l
        LEFT JOIN projects p ON p.id=l.project_id
        LEFT JOIN regions r ON r.id=l.region_id
        LEFT JOIN users u ON u.id=l.assigned_to
        WHERE 1=1";
$params = [];

// Telecall: show all leads from their assigned projects
if ($isTelecall && $telecallProjectIds) {
    $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
    $sql .= " AND l.project_id IN ($in)";
    $params = array_merge($params, $telecallProjectIds);
} elseif ($isTelecall) {
    $sql .= " AND 1=0"; // no projects assigned yet → show nothing
}

if ($filterProject) { $sql .= " AND l.project_id=?"; $params[] = $filterProject; }
if ($filterStatus)  { $sql .= " AND l.status=?";     $params[] = $filterStatus; }
if ($filterRegion)  { $sql .= " AND l.region_id=?";  $params[] = $filterRegion; }
if ($filterSource)  { $sql .= " AND l.source=?";     $params[] = $filterSource; }
if ($filterSearch)  { $sql .= " AND (l.name LIKE ? OR l.phone LIKE ? OR l.company LIKE ?)"; $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; }

$sql .= " ORDER BY l.created_at DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$leads = $stmt->fetchAll();

// Calculate Instagram & Social Media Leads count
$instaCount = 0;
$instaNewCount = 0;
foreach ($leads as $l) {
    if ($l['source'] === 'social_media') {
        $instaCount++;
        if (empty($l['is_viewed']) && $l['status'] === 'new') {
            $instaNewCount++;
        }
    }
}

// Projects: telecall sees only their assigned projects; investors see only theirs
if ($isTelecall && $telecallProjectIds) {
    $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
    $projStmt = $db->prepare("SELECT id,name FROM projects WHERE status='active' AND id IN ($in) ORDER BY name");
    $projStmt->execute($telecallProjectIds);
    $projects = $projStmt->fetchAll();
} else {
    $projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
}

$regions  = $db->query("SELECT id,name FROM regions WHERE status='active' ORDER BY name")->fetchAll();
$agents   = $db->query("SELECT id,name FROM users WHERE status='active' ORDER BY name")->fetchAll();

// Status counts — scoped to the user's visible leads
$countSql = "SELECT status, COUNT(*) as cnt FROM leads WHERE 1=1";
$countParams = [];
if ($isTelecall && $telecallProjectIds) {
    $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
    $countSql .= " AND project_id IN ($in)";
    $countParams = array_merge($countParams, $telecallProjectIds);
} elseif ($isTelecall) {
    $countSql .= " AND 1=0";
} elseif ($isInvestor && $investorProjectId) {
    $countSql .= " AND project_id=?";
    $countParams[] = $investorProjectId;
}
$countSql .= " GROUP BY status";
$countStmt = $db->prepare($countSql);
$countStmt->execute($countParams);
$counts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Leads';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-3">
  <div><h4 class="mb-0">Leads Management</h4><p class="text-muted small mb-0">Track and manage all client leads with automated Instagram sync</p></div>
  <?php if (!$isInvestor): ?>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#instagramModal">
      <i class="bi bi-instagram me-1"></i>Instagram Auto-Sync
    </button>
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

<!-- KPI Summary Cards for Leads -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-primary bg-opacity-10 text-primary">
          <i class="bi bi-people-fill fs-4"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-dark"><?= number_format(count($leads)) ?></div>
          <div class="text-muted small">Total Leads</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-warning bg-opacity-10 text-warning">
          <i class="bi bi-star-fill fs-4"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-warning"><?= number_format($counts['new'] ?? 0) ?></div>
          <div class="text-muted small">New Uncontacted</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <a href="?source=social_media" class="text-decoration-none">
      <div class="card border-0 shadow-sm stat-card h-100" style="border-left: 4px solid #dc2743 !important;">
        <div class="card-body d-flex align-items-center gap-3 py-3">
          <div class="stat-icon rounded-3 text-white" style="background: linear-gradient(45deg, #f09433, #dc2743, #bc1888);">
            <i class="bi bi-instagram fs-4"></i>
          </div>
          <div>
            <div class="fs-4 fw-bold text-dark d-flex align-items-center gap-1">
              <?= number_format($instaCount) ?>
              <?php if ($instaNewCount > 0): ?>
                <span class="badge bg-danger rounded-pill fs-7"><?= $instaNewCount ?> new</span>
              <?php endif; ?>
            </div>
            <div class="text-muted small">Instagram & Social Leads</div>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm stat-card">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="stat-icon rounded-3 bg-success bg-opacity-10 text-success">
          <i class="bi bi-check-circle-fill fs-4"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-success"><?= number_format($counts['converted'] ?? 0) ?></div>
          <div class="text-muted small">Converted Clients</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- New Instagram Leads Notification Banner -->
<?php if ($instaNewCount > 0): ?>
<div class="alert border-0 shadow-sm text-white d-flex flex-wrap justify-content-between align-items-center mb-3 py-2 px-3" style="background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); border-radius: 10px;">
  <div class="d-flex align-items-center gap-2">
    <i class="bi bi-instagram fs-4"></i>
    <div>
      <strong class="d-block fs-6"><?= $instaNewCount ?> New Instagram Lead<?= $instaNewCount > 1 ? 's' : '' ?> Received!</strong>
      <span class="small opacity-90">Captured automatically via Instagram & Meta Lead Ads Webhook</span>
    </div>
  </div>
  <a href="?source=social_media&status=new" class="btn btn-sm btn-light text-dark fw-bold shadow-sm">
    <i class="bi bi-eye me-1"></i>View New Instagram Leads (<?= $instaNewCount ?>)
  </a>
</div>
<?php endif; ?>

<!-- Status Summary Pills -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php
  $statusColors = [
    'new'            => 'primary',
    'contacted'      => 'info',
    'interested'     => 'warning',
    'trial'          => 'info',
    'trial_ended'    => 'danger',
    'not_interested' => 'danger',
    'follow_up'      => 'secondary',
    'converted'      => 'success'
  ];
  foreach ($statusColors as $st => $cls): ?>
  <a href="?status=<?= $st ?><?= $filterProject?"&project_id=$filterProject":'' ?><?= $filterSource?"&source=$filterSource":'' ?>" class="badge bg-<?= $cls ?> text-decoration-none p-2 fs-6">
    <?= ucwords(str_replace('_',' ',$st)) ?> <span class="ms-1"><?= $counts[$st] ?? 0 ?></span>
  </a>
  <?php endforeach; ?>
  <?php if ($filterSource): ?>
  <a href="?source=<?= urlencode($filterSource) ?>" class="badge text-white text-decoration-none p-2 fs-6 shadow-sm" style="background: linear-gradient(45deg, #f09433, #dc2743, #bc1888);">
    <i class="bi bi-instagram me-1"></i>Source: <?= htmlspecialchars($filterSource) ?>
  </a>
  <?php endif; ?>
  <?php if ($filterStatus || $filterProject || $filterRegion || $filterSource || $filterSearch): ?>
  <a href="<?= BASE_URL ?>/modules/leads/index.php" class="badge bg-light text-dark text-decoration-none p-2 fs-6 border">
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
        <?php elseif ($isTelecall && count($projects) === 1): ?>
          <input type="hidden" name="project_id" value="<?= $projects[0]['id'] ?>">
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($projects[0]['name']) ?>" disabled>
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
        <select name="source" class="form-select form-select-sm">
          <option value="">All Sources</option>
          <option value="social_media" <?= $filterSource==='social_media'?'selected':'' ?>>Instagram / Social</option>
          <option value="website" <?= $filterSource==='website'?'selected':'' ?>>Website</option>
          <option value="referral" <?= $filterSource==='referral'?'selected':'' ?>>Referral</option>
          <option value="cold_call" <?= $filterSource==='cold_call'?'selected':'' ?>>Cold Call</option>
          <option value="email" <?= $filterSource==='email'?'selected':'' ?>>Email</option>
          <option value="exhibition" <?= $filterSource==='exhibition'?'selected':'' ?>>Exhibition</option>
          <option value="other" <?= $filterSource==='other'?'selected':'' ?>>Other</option>
        </select>
      </div>
      <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (array_keys($statusColors) as $st): ?>
          <option value="<?= $st ?>" <?= $filterStatus===$st?'selected':'' ?>><?= ucwords(str_replace('_',' ',$st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
      </div>
    </form>
  </div>
</div>

<!-- Bulk Actions Bar -->
<?php if ($isAdmin): ?>
<div id="bulkActionsBar" class="d-none alert alert-danger border-0 d-flex justify-content-between align-items-center mb-3 py-3 px-4 shadow-sm" style="border-radius: 12px; background: linear-gradient(135deg, #fff5f5 0%, #ffe3e3 100%);">
  <div class="d-flex align-items-center gap-2 text-danger">
    <i class="bi bi-trash3-fill fs-5"></i>
    <div>
      <span class="fw-bold" id="selectedCount">0</span> leads selected for bulk action
      <div class="text-muted small">Only leads with "Not Interested" status can be deleted.</div>
    </div>
  </div>
  <div>
    <button type="button" class="btn btn-danger btn-sm px-3 py-2 fw-semibold" onclick="confirmBulkDelete()">
      <i class="bi bi-trash me-1"></i> Delete Selected
    </button>
  </div>
</div>

<form id="bulkDeleteForm" method="POST" action="<?= BASE_URL ?>/modules/leads/delete.php" class="d-none">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
  <input type="hidden" name="bulk" value="1">
  <div id="bulkDeleteInputs"></div>
</form>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable align-middle">
        <thead class="table-light">
          <tr>
            <?php if ($isAdmin): ?>
            <th data-orderable="false" data-searchable="false" width="40" class="text-center">
              <input type="checkbox" id="selectAllLeads" class="form-check-input">
            </th>
            <?php endif; ?>
            <th>Code</th><th>Lead</th><th>Contact</th><th>Project</th><th>Region</th><th>Source</th><th>Status</th><th>Assigned</th><th>Date</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($leads as $l): 
            $isAdLead = ($l['source'] === 'social_media');
            $isUnviewedAdLead = ($isAdLead && empty($l['is_viewed']) && $l['status'] === 'new');
          ?>
          <tr class="<?= $isUnviewedAdLead ? 'table-danger bg-opacity-10 border-start border-4 border-danger' : '' ?>">
            <?php if ($isAdmin): ?>
            <td class="text-center">
              <?php if ($l['status'] === 'not_interested'): ?>
              <input type="checkbox" value="<?= $l['id'] ?>" class="form-check-input lead-select-checkbox">
              <?php else: ?>
              <input type="checkbox" class="form-check-input" disabled title="Only 'Not Interested' leads can be deleted.">
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td>
              <small class="text-muted font-monospace"><?= htmlspecialchars($l['lead_code'] ?? '') ?></small>
            </td>
            <td>
              <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $l['id'] ?>" class="fw-semibold text-decoration-none text-dark">
                <?= htmlspecialchars($l['name']) ?>
              </a>
              <?php if ($isUnviewedAdLead): ?>
                <span class="badge ms-1 shadow-sm" style="background: linear-gradient(45deg, #f09433, #dc2743, #bc1888); color: #fff; font-size: 0.65rem;">
                  <i class="bi bi-star-fill me-1"></i>New Ad Lead
                </span>
              <?php endif; ?>
              <?php if ($l['company']): ?>
              <div class="text-muted small"><i class="bi bi-building me-1"></i><?= htmlspecialchars($l['company']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div><?= htmlspecialchars($l['phone']) ?></div>
              <?php if ($l['email']): ?><small class="text-muted"><?= htmlspecialchars($l['email']) ?></small><?php endif; ?>
            </td>
            <td><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"><?= htmlspecialchars($l['project_name'] ?? '') ?></span></td>
            <td><?= $l['region_name'] ? htmlspecialchars($l['region_name']) : '<span class="text-muted">-</span>' ?></td>
            <td>
              <?php if ($isAdLead): ?>
                <span class="badge shadow-sm" style="background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); color: #fff;">
                  <i class="bi bi-instagram me-1"></i>Instagram
                </span>
              <?php else: ?>
                <span class="badge bg-light text-dark border"><?= ucfirst(str_replace('_',' ',$l['source'])) ?></span>
              <?php endif; ?>
            </td>
            <td><?= statusBadge($l['status']) ?></td>
            <td class="small"><?= htmlspecialchars($l['assigned_name'] ?? '-') ?></td>
            <td class="text-muted small text-nowrap"><?= date('d M Y', strtotime($l['created_at'])) ?></td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $l['id'] ?>" class="btn btn-outline-info" title="View"><i class="bi bi-eye"></i></a>
                <?php if (!$isInvestor): ?>
                <button class="btn btn-outline-primary" onclick="editLead(<?= htmlspecialchars(json_encode($l)) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                <?php if ($l['status'] !== 'converted' && hasAccess('clients')): ?>
                <a href="<?= BASE_URL ?>/modules/leads/convert.php?id=<?= $l['id'] ?>" class="btn btn-outline-success" title="Convert to Client"><i class="bi bi-person-check"></i></a>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($isAdmin && $l['status'] === 'not_interested'): ?>
                <button type="button" class="btn btn-outline-danger btn-delete-lead" data-id="<?= $l['id'] ?>" data-name="<?= htmlspecialchars($l['name'], ENT_QUOTES) ?>" title="Delete"><i class="bi bi-trash"></i></button>
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
                <option value="trial">Trial</option>
                <option value="trial_ended">Trial Ended</option>
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
            
            <!-- Trial Settings Section -->
            <div class="col-12" id="lTrialFields" style="display: none;">
              <div class="card border-0 bg-light shadow-sm">
                <div class="card-header bg-white border-bottom-0 py-3 fw-semibold">
                  <i class="bi bi-clock-history text-primary me-2"></i>Trial Version Settings
                </div>
                <div class="card-body pt-0">
                  <div class="row g-3">
                    <div class="col-md-3">
                      <label class="form-label">Trial End Date *</label>
                      <input type="date" name="trial_end_date" id="lTrialEndDate" class="form-control">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Trial Login URL</label>
                      <input type="url" name="trial_login_url" id="lTrialLoginUrl" class="form-control" placeholder="https://example.com/login">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Trial Username</label>
                      <input type="text" name="trial_username" id="lTrialUsername" class="form-control" placeholder="Username">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Trial Password</label>
                      <input type="text" name="trial_password" id="lTrialPassword" class="form-control" placeholder="Password">
                    </div>
                  </div>
                </div>
              </div>
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

<!-- Modal: Instagram & Meta Leads Automation -->
<div class="modal fade" id="instagramModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-gradient text-white py-3" style="background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);">
        <h5 class="modal-title fw-bold mb-0">
          <i class="bi bi-instagram me-2"></i>Instagram & Meta Lead Ads Auto-Sync
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <div class="alert alert-info border-0 shadow-sm d-flex align-items-center gap-3 mb-4">
          <i class="bi bi-lightning-charge-fill fs-2 text-primary"></i>
          <div>
            <strong class="d-block text-dark">Automated Instant Lead Capture</strong>
            Connect Instagram Ads & Facebook Lead Forms to automatically import leads into this portal as soon as prospects submit your ad forms!
          </div>
        </div>

        <h6 class="fw-bold text-dark mb-2"><i class="bi bi-link-45deg me-1 text-danger"></i>Your Webhook Integration Endpoint</h6>
        <div class="input-group mb-4">
          <input type="text" class="form-control font-monospace bg-light" id="instaWebhookUrl" value="<?= BASE_URL ?>/api/webhooks/instagram_lead.php" readonly>
          <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('instaWebhookUrl').value); alert('Webhook URL copied to clipboard!');">
            <i class="bi bi-clipboard me-1"></i>Copy URL
          </button>
        </div>

        <ul class="nav nav-pills mb-3" id="instaPillsTab" role="tablist">
          <li class="nav-item">
            <button class="nav-link active py-1.5 px-3 small fw-semibold" id="pills-zapier-tab" data-bs-toggle="pill" data-bs-target="#pills-zapier" type="button">Zapier / Make / Pabbly</button>
          </li>
          <li class="nav-item">
            <button class="nav-link py-1.5 px-3 small fw-semibold" id="pills-meta-tab" data-bs-toggle="pill" data-bs-target="#pills-meta" type="button">Meta Developer Webhook</button>
          </li>
          <li class="nav-item">
            <button class="nav-link py-1.5 px-3 small fw-semibold" id="pills-test-tab" data-bs-toggle="pill" data-bs-target="#pills-test" type="button">Test Webhook Live</button>
          </li>
        </ul>

        <div class="tab-content border rounded p-3 bg-light" id="instaPillsTabContent">
          <!-- Zapier / Make / Pabbly Guide -->
          <div class="tab-pane fade show active" id="pills-zapier">
            <ol class="small mb-0 ps-3">
              <li class="mb-2">Create a new Zap in <strong>Zapier</strong> or Scenario in <strong>Make.com</strong> / <strong>Pabbly</strong> with trigger <em>"New Lead in Facebook / Instagram Lead Ads"</em>.</li>
              <li class="mb-2">Add an Action step: <strong>Webhook / Custom Request (POST)</strong> to URL: <code><?= BASE_URL ?>/api/webhooks/instagram_lead.php</code></li>
              <li class="mb-2">Set Payload Type to <code>JSON</code> and map fields:
                <ul class="mt-1">
                  <li><code>full_name</code> &rarr; Lead Full Name</li>
                  <li><code>phone_number</code> &rarr; Lead Phone</li>
                  <li><code>email</code> &rarr; Lead Email</li>
                  <li><code>company_name</code> &rarr; Company / Business Name</li>
                  <li><code>form_name</code> &rarr; Campaign / Ad Form Name</li>
                  <li><code>source</code> &rarr; <code>social_media</code></li>
                </ul>
              </li>
              <li>Test the Zap! Leads will stream into your portal instantly.</li>
            </ol>
          </div>

          <!-- Meta Webhook Guide -->
          <div class="tab-pane fade" id="pills-meta">
            <p class="small text-muted mb-2">For direct integration with Meta Developer App Webhooks:</p>
            <ul class="small mb-0 ps-3">
              <li class="mb-2">Set Webhook Callback URL: <code><?= BASE_URL ?>/api/webhooks/instagram_lead.php</code></li>
              <li class="mb-2">Supports Meta <code>hub.challenge</code> verification automatically for subscription handshakes.</li>
              <li>Receives lead payloads with <code>source = 'social_media'</code>.</li>
            </ul>
          </div>

          <!-- Live Test Webhook Form -->
          <div class="tab-pane fade" id="pills-test">
            <form id="instaTestForm" method="POST" action="<?= BASE_URL ?>/api/webhooks/instagram_lead.php" target="_blank">
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label small mb-1 fw-semibold">Full Name</label>
                  <input type="text" name="full_name" class="form-control form-control-sm" value="Sample Instagram Lead" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label small mb-1 fw-semibold">Phone Number</label>
                  <input type="text" name="phone_number" class="form-control form-control-sm" value="9876543210" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label small mb-1 fw-semibold">Email Address</label>
                  <input type="email" name="email" class="form-control form-control-sm" value="insta.lead@example.com">
                </div>
                <div class="col-md-6">
                  <label class="form-label small mb-1 fw-semibold">Ad / Campaign Name</label>
                  <input type="text" name="form_name" class="form-control form-control-sm" value="Instagram Promo Ad 2026">
                </div>
                <div class="col-12 mt-3">
                  <button type="submit" class="btn btn-sm btn-danger">
                    <i class="bi bi-send me-1"></i>Simulate Incoming Instagram Lead
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
      <div class="modal-footer bg-light py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
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
  document.getElementById('lTrialEndDate').value   = l.trial_end_date || '';
  document.getElementById('lTrialLoginUrl').value  = l.trial_login_url || '';
  document.getElementById('lTrialUsername').value  = l.trial_username || '';
  document.getElementById('lTrialPassword').value  = l.trial_password || '';
  
  $('#lProject').val(l.project_id).trigger('change');
  $('#lRegion').val(l.region_id || '').trigger('change');
  $('#lAssigned').val(l.assigned_to || '').trigger('change');
  loadPlans(l.project_id, l.interested_plan_id);
  
  $('#lStatus').trigger('change');
  
  document.getElementById('leadModalTitle').textContent = 'Edit Lead';
  new bootstrap.Modal(document.getElementById('leadModal')).show();
}

<?php if ($isAdmin): ?>
function updateBulkActionsBar() {
  const checkedCount = $('.lead-select-checkbox:checked').length;
  const bar = document.getElementById('bulkActionsBar');
  const countSpan = document.getElementById('selectedCount');
  
  if (bar && countSpan) {
    if (checkedCount > 0) {
      bar.classList.remove('d-none');
      countSpan.textContent = checkedCount;
    } else {
      bar.classList.add('d-none');
    }
  }
}

function confirmBulkDelete() {
  const checkboxes = document.querySelectorAll('.lead-select-checkbox:checked');
  if (checkboxes.length === 0) {
    alert('Please select at least one lead to delete.');
    return;
  }
  if (confirm(`Are you sure you want to delete the ${checkboxes.length} selected lead(s)? This action cannot be undone.`)) {
    const container = document.getElementById('bulkDeleteInputs');
    container.innerHTML = ''; // clear first
    checkboxes.forEach(cb => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'lead_ids[]';
      input.value = cb.value;
      container.appendChild(input);
    });
    document.getElementById('bulkDeleteForm').submit();
  }
}

<?php endif; ?>

window.addEventListener('DOMContentLoaded', () => {
  // Handle status selection change
  $('#lStatus').on('change', function() {
    const status = this.value;
    const trialDiv = document.getElementById('lTrialFields');
    if (status === 'trial' || status === 'trial_ended') {
      $(trialDiv).slideDown();
      document.getElementById('lTrialEndDate').required = true;
    } else {
      $(trialDiv).slideUp();
      document.getElementById('lTrialEndDate').required = false;
    }
  });

  // Reset modal on add lead action
  $('#leadModal').on('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    if (button && button.getAttribute('data-bs-target') === '#leadModal') {
      document.getElementById('leadId').value = '0';
      document.getElementById('lName').value = '';
      document.getElementById('lPhone').value = '';
      document.getElementById('lEmail').value = '';
      document.getElementById('lCompany').value = '';
      document.getElementById('lDesignation').value = '';
      document.getElementById('lWebsite').value = '';
      document.getElementById('lAddress').value = '';
      document.getElementById('lNotes').value = '';
      document.getElementById('lStatus').value = 'new';
      document.getElementById('lTrialEndDate').value = '';
      document.getElementById('lTrialLoginUrl').value = '';
      document.getElementById('lTrialUsername').value = '';
      document.getElementById('lTrialPassword').value = '';
      $('#lProject').val('').trigger('change');
      $('#lRegion').val('').trigger('change');
      $('#lAssigned').val('').trigger('change');
      document.getElementById('leadModalTitle').textContent = 'Add Lead';
      $('#lStatus').trigger('change');
    }
  });

  // Handle Instagram test webhook simulation form submit via AJAX
  $('#instaTestForm').on('submit', function(e) {
    e.preventDefault();
    const $btn = $(this).find('button[type="submit"]');
    const origHtml = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Processing Webhook...');

    const payload = {
      full_name: $(this).find('[name="full_name"]').val(),
      phone_number: $(this).find('[name="phone_number"]').val(),
      email: $(this).find('[name="email"]').val(),
      form_name: $(this).find('[name="form_name"]').val(),
      source: 'social_media'
    };

    $.ajax({
      url: '<?= BASE_URL ?>/api/webhooks/instagram_lead.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(payload),
      success: function(res) {
        $btn.prop('disabled', false).html(origHtml);
        if (res && res.success) {
          alert('SUCCESS! Instagram lead captured automatically.\n\nLead Code: ' + res.lead_code + '\nName: ' + res.name);
          window.location.reload();
        } else {
          alert('Error: ' + (res.message || 'Unknown response from webhook.'));
        }
      },
      error: function(xhr) {
        $btn.prop('disabled', false).html(origHtml);
        const msg = xhr.responseJSON ? xhr.responseJSON.message : (xhr.responseText || 'Connection failed.');
        alert('Webhook Error: ' + msg);
      }
    });
  });

  $('#lProject').on('change', function() { loadPlans(this.value); });

  <?php if ($isAdmin): ?>
  // Checkbox select all logic
  $(document).on('change', '#selectAllLeads', function() {
    const isChecked = this.checked;
    $('.lead-select-checkbox').each(function() {
      if (!this.disabled) {
        this.checked = isChecked;
      }
    });
    updateBulkActionsBar();
  });

  $(document).on('change', '.lead-select-checkbox', function() {
    updateBulkActionsBar();
    // Update select all checkbox state
    const total = $('.lead-select-checkbox:not(:disabled)').length;
    const checked = $('.lead-select-checkbox:checked').length;
    $('#selectAllLeads').prop('checked', total === checked && total > 0);
  });

  // Handle DataTables draw event to reset/refresh selection states
  if ($.fn.DataTable) {
    $('table.datatable').on('draw.dt', function() {
      const total = $('.lead-select-checkbox:not(:disabled)').length;
      const checked = $('.lead-select-checkbox:checked').length;
      $('#selectAllLeads').prop('checked', total === checked && total > 0);
      updateBulkActionsBar();
    });
  }

  // Single delete via event delegation
  $(document).on('click', '.btn-delete-lead', function() {
    const id = $(this).data('id');
    const name = $(this).data('name');
    if (confirm(`Are you sure you want to delete the lead "${name}"? This action cannot be undone.`)) {
      document.getElementById('singleDeleteId').value = id;
      document.getElementById('singleDeleteForm').submit();
    }
  });
  <?php endif; ?>
});
</script>

<?php if ($isAdmin): ?>
<form id="singleDeleteForm" method="POST" action="<?= BASE_URL ?>/modules/leads/delete.php" class="d-none">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
  <input type="hidden" name="id" id="singleDeleteId" value="0">
</form>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
