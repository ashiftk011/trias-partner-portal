<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('leads');

$db = getDB();
$user       = currentUser();
$isInvestor = isRole('investor');
$isTelecall = isRole('telecall');
$isAdmin    = isRole('admin');
$isCsm      = isRole('csm');
$investorProjectId    = $isInvestor ? getInvestorProjectId() : 0;
$telecallProjectIds   = $isTelecall ? getTelecallProjectIds() : [];

// Persist & restore filters across requests/edits
restorePageFilters('leads', ['project_id', 'status', 'region_id', 'source', 'assigned_to', 'q']);

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
$filterStatus   = $_GET['status'] ?? '';
$filterRegion   = isset($_GET['region_id']) ? (int)$_GET['region_id'] : 0;
$filterSource   = $_GET['source'] ?? '';
$filterAssigned = $_GET['assigned_to'] ?? '';
$filterSearch   = trim($_GET['q'] ?? '');

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
} elseif ($isCsm) {
    $sql .= " AND l.created_by = ?";
    $params[] = $user['id'];
}

if ($filterProject) { $sql .= " AND l.project_id=?"; $params[] = $filterProject; }
if ($filterStatus)  { $sql .= " AND l.status=?";     $params[] = $filterStatus; }
if ($filterRegion)  { $sql .= " AND l.region_id=?";  $params[] = $filterRegion; }
if ($filterSource)  { $sql .= " AND l.source=?";     $params[] = $filterSource; }
if ($filterAssigned === 'unassigned' || $filterAssigned === '0') {
    $sql .= " AND (l.assigned_to IS NULL OR l.assigned_to = 0)";
} elseif (is_numeric($filterAssigned) && (int)$filterAssigned > 0) {
    $sql .= " AND l.assigned_to=?";
    $params[] = (int)$filterAssigned;
}
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
} elseif ($isCsm) {
    $countSql .= " AND created_by=?";
    $countParams[] = $user['id'];
}
$countSql .= " GROUP BY status";
$countStmt = $db->prepare($countSql);
$countStmt->execute($countParams);
$counts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Leads';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h4 class="mb-0">Leads Management</h4>
    <p class="text-muted small mb-0">Track and manage all client leads with automated Instagram sync</p>
  </div>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <!-- View Switcher (Cards vs Table) -->
    <div class="btn-group btn-group-sm border rounded-3 p-1 bg-white shadow-sm" id="leadsViewToggleGroup">
      <button type="button" class="btn btn-sm btn-white border-0 fw-semibold active" id="btnViewCards" onclick="setLeadsView('cards')">
        <i class="bi bi-grid-fill me-1"></i>Cards
      </button>
      <button type="button" class="btn btn-sm btn-light border-0 fw-semibold text-muted" id="btnViewTable" onclick="setLeadsView('table')">
        <i class="bi bi-table me-1"></i>Table
      </button>
    </div>

    <?php if (!$isInvestor): ?>
      <?php if ($isAdmin): ?>
      <button type="button" class="btn btn-sm btn-outline-danger d-none d-sm-inline-flex" id="btnSyncMetaLeadsDirect">
        <i class="bi bi-arrow-repeat me-1"></i>Sync Meta
      </button>
      <button class="btn btn-sm btn-outline-danger d-none d-md-inline-flex" data-bs-toggle="modal" data-bs-target="#instagramModal">
        <i class="bi bi-instagram me-1"></i>Meta Webhook
      </button>
      <?php endif; ?>
      <button class="btn btn-sm btn-outline-secondary d-none d-sm-inline-flex" data-bs-toggle="modal" data-bs-target="#csvModal">
        <i class="bi bi-file-earmark-arrow-up me-1"></i>Import
      </button>
      <button class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#leadModal">
        <i class="bi bi-plus-circle me-1"></i>Add Lead
      </button>
    <?php endif; ?>
  </div>
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

<!-- Status Summary Pills (Horizontally Scrollable on Mobile) -->
<div class="status-pill-scroll mb-3">
  <?php
  $statusColors = [
    'new'            => 'primary',
    'contacted'      => 'info',
    'interested'     => 'warning',
    'trial'          => 'info',
    'trial_ended'    => 'danger',
    'not_interested' => 'danger',
    'follow_up'      => 'secondary',
    'converted'      => 'success',
    'false_lead'     => 'dark'
  ];
  ?>
  <a href="?clear=1" class="status-pill-link bg-light text-dark border <?= (!$filterStatus && !$filterSource && $filterAssigned === '') ? 'active-filter' : '' ?>">
    <i class="bi bi-layers-fill me-1 text-primary"></i>All Leads <span class="badge bg-secondary ms-1"><?= count($leads) ?></span>
  </a>
  <?php foreach ($statusColors as $st => $cls): 
    $isActive = ($filterStatus === $st);
  ?>
  <a href="?status=<?= $st ?><?= $filterProject ? "&project_id=$filterProject" : '' ?><?= $filterRegion ? "&region_id=$filterRegion" : '' ?><?= $filterSource ? "&source=" . urlencode($filterSource) : '' ?><?= $filterAssigned !== '' ? "&assigned_to=" . urlencode($filterAssigned) : '' ?>" 
     class="status-pill-link bg-<?= $cls ?> text-white <?= $isActive ? 'active-filter' : '' ?>">
    <?= ucwords(str_replace('_',' ',$st)) ?> <span class="badge bg-dark bg-opacity-25 ms-1"><?= $counts[$st] ?? 0 ?></span>
  </a>
  <?php endforeach; ?>
  <?php if ($filterSource): ?>
  <a href="?source=<?= urlencode($filterSource) ?><?= $filterProject ? "&project_id=$filterProject" : '' ?><?= $filterRegion ? "&region_id=$filterRegion" : '' ?><?= $filterAssigned !== '' ? "&assigned_to=" . urlencode($filterAssigned) : '' ?>" class="status-pill-link text-white shadow-sm active-filter" style="background: linear-gradient(45deg, #f09433, #dc2743, #bc1888);">
    <i class="bi bi-instagram me-1"></i>Source: <?= htmlspecialchars($filterSource) ?>
  </a>
  <?php endif; ?>
  <?php if ($filterAssigned !== ''): 
    $assignedLabel = ($filterAssigned === 'unassigned' || $filterAssigned === '0') ? 'Unassigned' : '';
    if (!$assignedLabel) {
      foreach ($agents as $ag) {
        if ((string)$ag['id'] === (string)$filterAssigned) {
          $assignedLabel = $ag['name'];
          break;
        }
      }
    }
    if (!$assignedLabel && is_numeric($filterAssigned)) {
      $assignedLabel = 'User #' . $filterAssigned;
    }
  ?>
  <a href="?assigned_to=<?= urlencode($filterAssigned) ?><?= $filterProject ? "&project_id=$filterProject" : '' ?><?= $filterRegion ? "&region_id=$filterRegion" : '' ?><?= $filterSource ? "&source=" . urlencode($filterSource) : '' ?>" class="status-pill-link bg-secondary text-white shadow-sm active-filter">
    <i class="bi bi-person-fill me-1"></i>Assignee: <?= htmlspecialchars($assignedLabel ?: $filterAssigned) ?>
  </a>
  <?php endif; ?>
  <?php if ($filterStatus || $filterProject || $filterRegion || $filterSource || $filterAssigned !== '' || $filterSearch): ?>
  <a href="?clear=1" class="status-pill-link bg-danger text-white border-0">
    <i class="bi bi-x-circle me-1"></i>Clear Filters
  </a>
  <?php endif; ?>
</div>

<!-- Filter Bar -->
<?php
$activeFilterCount = 0;
if ($filterProject) $activeFilterCount++;
if ($filterRegion) $activeFilterCount++;
if ($filterSource) $activeFilterCount++;
if ($filterStatus) $activeFilterCount++;
if ($filterAssigned !== '') $activeFilterCount++;
?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body p-2 p-md-3">
    <form method="GET" id="leadsFilterForm">
      <div class="row g-2 align-items-center">
        <div class="col-12 col-md-5">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" class="form-control border-start-0" placeholder="Search name, phone, company..." value="<?= htmlspecialchars($filterSearch) ?>">
            <?php if ($filterSearch): ?>
              <a href="?clear=1" class="btn btn-outline-secondary" title="Clear search"><i class="bi bi-x"></i></a>
            <?php endif; ?>
          </div>
        </div>
        
        <div class="col-12 col-md-7 d-flex flex-wrap gap-2 align-items-center justify-content-between justify-content-md-end">
          <button type="button" class="btn btn-sm btn-outline-secondary d-md-none" data-bs-toggle="collapse" data-bs-target="#moreFiltersCollapse" aria-expanded="<?= $activeFilterCount > 0 ? 'true' : 'false' ?>">
            <i class="bi bi-funnel me-1"></i>Filters <?php if ($activeFilterCount > 0): ?><span class="badge bg-primary ms-1"><?= $activeFilterCount ?></span><?php endif; ?>
          </button>
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-search me-1"></i>Search & Filter
          </button>
          <?php if ($filterStatus || $filterProject || $filterRegion || $filterSource || $filterAssigned !== '' || $filterSearch): ?>
          <a href="?clear=1" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-x me-1"></i>Clear
          </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Expandable Filters (Collapsed on Mobile by default, always visible on Desktop) -->
      <div class="collapse d-md-block <?= $activeFilterCount > 0 ? 'show' : '' ?> mt-2" id="moreFiltersCollapse">
        <div class="row g-2 pt-2 border-top">
          <div class="col-6 col-md-4 col-lg">
            <label class="form-label small text-muted mb-1 d-md-none">Project</label>
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
          <div class="col-6 col-md-4 col-lg">
            <label class="form-label small text-muted mb-1 d-md-none">Assignee</label>
            <select name="assigned_to" class="form-select form-select-sm">
              <option value="">All Assignees</option>
              <option value="unassigned" <?= ($filterAssigned === 'unassigned' || $filterAssigned === '0') ? 'selected' : '' ?>>-- Unassigned --</option>
              <?php foreach ($agents as $ag): ?>
              <option value="<?= $ag['id'] ?>" <?= (string)$filterAssigned === (string)$ag['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($ag['name']) ?><?= ((int)$ag['id'] === (int)($user['id'] ?? 0)) ? ' (Me)' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-4 col-lg">
            <label class="form-label small text-muted mb-1 d-md-none">Region</label>
            <select name="region_id" class="form-select form-select-sm">
              <option value="">All Regions</option>
              <?php foreach ($regions as $r): ?>
              <option value="<?= $r['id'] ?>" <?= $filterRegion==$r['id']?'selected':'' ?>><?= htmlspecialchars($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-4 col-lg">
            <label class="form-label small text-muted mb-1 d-md-none">Source</label>
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
          <div class="col-12 col-sm-6 col-md-4 col-lg">
            <label class="form-label small text-muted mb-1 d-md-none">Status</label>
            <select name="status" class="form-select form-select-sm">
              <option value="">All Statuses</option>
              <?php foreach (array_keys($statusColors) as $st): ?>
              <option value="<?= $st ?>" <?= $filterStatus===$st?'selected':'' ?>><?= ucwords(str_replace('_',' ',$st)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Bulk Actions Bar -->
<?php if ($isAdmin): ?>
<div id="bulkActionsBar" class="d-none alert alert-light border border-secondary border-opacity-25 d-flex justify-content-between align-items-center mb-3 py-3 px-4 shadow-sm" style="border-radius: 12px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);">
  <div class="d-flex align-items-center gap-2 text-dark">
    <i class="bi bi-check2-square fs-5 text-primary"></i>
    <div>
      <span class="fw-bold" id="selectedCount">0</span> leads selected for bulk action
      <div class="text-muted small">Mark selected leads as False Lead, or Delete (for Not Interested & False Leads).</div>
    </div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-dark btn-sm px-3 py-2 fw-semibold" onclick="openBulkMarkFalseModal()">
      <i class="bi bi-x-octagon me-1"></i> Mark as False Lead
    </button>
    <button type="button" class="btn btn-danger btn-sm px-3 py-2 fw-semibold" onclick="confirmBulkDelete()">
      <i class="bi bi-trash me-1"></i> Delete Selected
    </button>
  </div>
</div>

<form id="bulkDeleteForm" method="POST" action="<?= BASE_URL ?>/modules/leads/delete.php" class="d-none">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
  <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
  <input type="hidden" name="bulk" value="1">
  <div id="bulkDeleteInputs"></div>
</form>
<?php endif; ?>

<!-- Mobile Cards View Container -->
<div id="leadsCardsContainer">
  <div class="row g-3 mb-4">
    <?php if (empty($leads)): ?>
      <div class="col-12">
        <div class="card border-0 shadow-sm text-center py-5">
          <i class="bi bi-inbox fs-1 text-muted mb-2"></i>
          <h6 class="fw-bold">No leads found</h6>
          <p class="text-muted small mb-0">Try clearing your filters or add a new lead.</p>
        </div>
      </div>
    <?php else: ?>
      <?php foreach ($leads as $l): 
        $isAdLead = ($l['source'] === 'social_media');
        $isUnviewedAdLead = ($isAdLead && empty($l['is_viewed']) && $l['status'] === 'new');
        $cleanPhone = preg_replace('/\D/', '', $l['phone'] ?? '');
      ?>
      <div class="col-12 col-md-6 col-lg-4">
        <div class="lead-mobile-card h-100 d-flex flex-column justify-content-between <?= $isUnviewedAdLead ? 'is-new-ad-lead' : '' ?>">
          <div>
            <div class="lead-card-header">
              <span class="badge bg-light text-dark font-monospace border"><?= htmlspecialchars($l['lead_code'] ?? '') ?></span>
              <div class="d-flex align-items-center gap-1">
                <?php if ($isUnviewedAdLead): ?>
                  <span class="badge shadow-sm" style="background: linear-gradient(45deg, #f09433, #dc2743, #bc1888); color: #fff; font-size: 0.65rem;">
                    <i class="bi bi-star-fill me-1"></i>New Ad
                  </span>
                <?php endif; ?>
                <?= statusBadge($l['status']) ?>
                <?php if (!empty($l['next_followup'])): ?>
                  <span class="badge bg-warning-subtle text-warning-emphasis border border-warning" title="Scheduled Follow-up">
                    <i class="bi bi-clock-history me-1"></i><?= date('d M', strtotime($l['next_followup'])) ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
            <div>
              <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $l['id'] ?>" class="lead-card-title">
                <?= htmlspecialchars($l['name']) ?>
              </a>
              <?php if (!empty($l['company'])): ?>
                <div class="text-muted small mt-1"><i class="bi bi-building me-1"></i><?= htmlspecialchars($l['company']) ?></div>
              <?php endif; ?>
            </div>
            <div class="lead-card-meta">
              <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"><?= htmlspecialchars($l['project_name'] ?? '') ?></span>
              <?php if (!empty($l['region_name'])): ?>
                <span class="badge bg-light text-dark border"><?= htmlspecialchars($l['region_name']) ?></span>
              <?php endif; ?>
              <?php if ($isAdLead): ?>
                <span class="badge shadow-sm" style="background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); color: #fff;">
                  <i class="bi bi-instagram me-1"></i>Instagram
                </span>
              <?php elseif (!empty($l['outreach_id'])): ?>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                  <i class="bi bi-envelope-at-fill me-1"></i>Cold Email
                </span>
              <?php else: ?>
                <span class="badge bg-light text-dark border"><?= ucfirst(str_replace('_',' ',$l['source'])) ?></span>
              <?php endif; ?>
              <?php if (!empty($l['assigned_name'])): ?>
                <span class="badge bg-light text-muted border"><i class="bi bi-person me-1"></i><?= htmlspecialchars($l['assigned_name']) ?></span>
              <?php endif; ?>
            </div>
            <div class="lead-card-phone-row">
              <a href="tel:<?= htmlspecialchars($l['phone']) ?>" class="fw-semibold text-dark text-decoration-none">
                <i class="bi bi-telephone me-1 text-success"></i><?= htmlspecialchars($l['phone']) ?>
              </a>
              <small class="text-muted"><?= date('d M Y', strtotime($l['created_at'])) ?></small>
            </div>
          </div>
          <div class="lead-card-actions">
            <a href="tel:<?= htmlspecialchars($l['phone']) ?>" class="btn btn-call btn-sm flex-grow-1">
              <i class="bi bi-telephone-fill me-1"></i>Call
            </a>
            <?php if ($cleanPhone): ?>
            <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" class="btn btn-whatsapp btn-sm px-3" title="WhatsApp Message">
              <i class="bi bi-whatsapp"></i>
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $l['id'] ?>" class="btn btn-outline-primary btn-sm px-3" title="View Lead Details">
              <i class="bi bi-eye"></i>
            </a>
            <?php if (!$isInvestor): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm px-2 btn-edit-lead" data-id="<?= $l['id'] ?>" title="Edit Lead">
              <i class="bi bi-pencil"></i>
            </button>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Desktop/Full Table View Container -->
<div id="leadsTableContainer" class="d-none">
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
                <?php if ($l['status'] !== 'converted'): ?>
                <input type="checkbox" value="<?= $l['id'] ?>" class="form-check-input lead-select-checkbox" data-status="<?= $l['status'] ?>" data-name="<?= htmlspecialchars($l['name'], ENT_QUOTES) ?>">
                <?php else: ?>
                <input type="checkbox" class="form-check-input" disabled title="Converted leads cannot be modified or deleted.">
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
                <div>
                  <a href="tel:<?= htmlspecialchars($l['phone']) ?>" class="text-decoration-none text-dark">
                    <i class="bi bi-telephone text-success me-1"></i><?= htmlspecialchars($l['phone']) ?>
                  </a>
                </div>
                <?php if ($l['email']): ?><small class="text-muted"><?= htmlspecialchars($l['email']) ?></small><?php endif; ?>
              </td>
              <td><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"><?= htmlspecialchars($l['project_name'] ?? '') ?></span></td>
              <td><?= $l['region_name'] ? htmlspecialchars($l['region_name']) : '<span class="text-muted">-</span>' ?></td>
              <td>
                <?php if ($isAdLead): ?>
                  <span class="badge shadow-sm" style="background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); color: #fff;">
                    <i class="bi bi-instagram me-1"></i>Instagram
                  </span>
                <?php elseif (!empty($l['outreach_id'])): ?>
                  <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                    <i class="bi bi-envelope-at-fill me-1"></i>Cold Email
                  </span>
                <?php else: ?>
                  <span class="badge bg-light text-dark border"><?= ucfirst(str_replace('_',' ',$l['source'])) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?= statusBadge($l['status']) ?>
                <?php if (!empty($l['next_followup'])): ?>
                  <div class="mt-1">
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning text-nowrap" title="Scheduled Follow-up" style="font-size: 0.72rem;">
                      <i class="bi bi-clock-history me-1"></i><?= date('d M Y', strtotime($l['next_followup'])) ?>
                    </span>
                  </div>
                <?php endif; ?>
              </td>
              <td class="small"><?= htmlspecialchars($l['assigned_name'] ?? '-') ?></td>
              <td class="text-muted small text-nowrap"><?= date('d M Y', strtotime($l['created_at'])) ?></td>
              <td>
                <div class="btn-group btn-group-sm">
                  <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $l['id'] ?>" class="btn btn-outline-info" title="View"><i class="bi bi-eye"></i></a>
                  <?php if (!$isInvestor): ?>
                  <button type="button" class="btn btn-outline-primary btn-edit-lead" data-id="<?= $l['id'] ?>" title="Edit Lead"><i class="bi bi-pencil"></i></button>
                  <?php if ($l['status'] !== 'converted' && hasAccess('clients')): ?>
                  <a href="<?= BASE_URL ?>/modules/leads/convert.php?id=<?= $l['id'] ?>" class="btn btn-outline-success" title="Convert to Client"><i class="bi bi-person-check"></i></a>
                  <?php endif; ?>
                  <?php if ($l['status'] !== 'converted' && $l['status'] !== 'false_lead'): ?>
                  <button type="button" class="btn btn-outline-secondary btn-mark-false" data-id="<?= $l['id'] ?>" data-name="<?= htmlspecialchars($l['name'], ENT_QUOTES) ?>" title="Mark as False Lead"><i class="bi bi-x-octagon"></i></button>
                  <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($isAdmin && in_array($l['status'], ['not_interested', 'false_lead'])): ?>
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
</div>

<!-- Add/Edit Lead Modal (Mobile-Optimized Scrollable) -->
<div class="modal fade" id="leadModal" tabindex="-1" aria-labelledby="leadModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
    <form class="modal-content border-0 shadow-lg" method="POST" action="<?= BASE_URL ?>/modules/leads/save.php">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="id" id="leadId" value="0">
      <div class="modal-header bg-light border-bottom sticky-top py-3 px-4">
        <h5 class="modal-title fw-bold text-dark" id="leadModalTitle">
          <i class="bi bi-person-plus me-2 text-primary"></i>Add Lead
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Full Name *</label>
              <input type="text" name="name" id="lName" class="form-control" required placeholder="e.g. John Doe">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Phone *</label>
              <input type="tel" name="phone" id="lPhone" class="form-control" inputmode="tel" required placeholder="+91 9876543210">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Email</label>
              <input type="email" name="email" id="lEmail" class="form-control" inputmode="email" placeholder="client@example.com">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Company</label>
              <input type="text" name="company" id="lCompany" class="form-control" placeholder="Company Name">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Designation</label>
              <input type="text" name="designation" id="lDesignation" class="form-control" placeholder="e.g. Director">
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
                <option value="false_lead">False Lead</option>
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
        <div class="modal-footer bg-light border-top sticky-bottom py-3 px-4 d-flex justify-content-between align-items-center">
          <span class="text-muted small d-none d-sm-inline">
            <i class="bi bi-info-circle me-1"></i>Fields marked with * are required.
          </span>
          <div class="d-flex gap-2 ms-auto">
            <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm" id="btnSaveLead">
              <i class="bi bi-check-circle me-1"></i>Save Lead
            </button>
          </div>
        </div>
      </form>
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
<?php if ($isAdmin): ?>
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
<?php endif; ?>
<!-- Mark Lead as False Lead Modal -->
<div class="modal fade" id="markFalseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="markFalseForm" method="POST" action="<?= BASE_URL ?>/modules/leads/mark_false.php">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
        <input type="hidden" name="id" id="markFalseLeadId" value="0">
        <input type="hidden" name="bulk" id="markFalseBulk" value="0">
        <div id="markFalseBulkInputs"></div>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-x-octagon text-danger me-2"></i>Mark as False Lead</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3" id="markFalsePrompt">Are you sure you want to mark this lead as a <strong>False Lead</strong>?</p>
          <div class="mb-3">
            <label class="form-label">Reason <span class="text-muted">(Optional)</span></label>
            <select name="reason" id="markFalseReason" class="form-select">
              <option value="">-- Select Reason --</option>
              <option value="Invalid Phone / Contact Number">Invalid Phone / Contact Number</option>
              <option value="Fake / Spam Inquiry">Fake / Spam Inquiry</option>
              <option value="Wrong Location / Out of Service Area">Wrong Location / Out of Service Area</option>
              <option value="Irrelevant / Student / Job Seeker">Irrelevant / Student / Job Seeker</option>
              <option value="Duplicate Lead">Duplicate Lead</option>
              <option value="Not Reachable / Disconnected">Not Reachable / Disconnected</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Additional Notes <span class="text-muted">(Optional)</span></label>
            <textarea name="notes" id="markFalseNotes" class="form-control" rows="2" placeholder="Any details explaining why this lead is false..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-x-octagon me-1"></i>Confirm Mark as False</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function setLeadsView(mode) {
  const cardsContainer = document.getElementById('leadsCardsContainer');
  const tableContainer = document.getElementById('leadsTableContainer');
  const btnCards = document.getElementById('btnViewCards');
  const btnTable = document.getElementById('btnViewTable');

  if (mode === 'cards') {
    if (cardsContainer) cardsContainer.classList.remove('d-none');
    if (tableContainer) tableContainer.classList.add('d-none');
    if (btnCards) {
      btnCards.classList.add('active', 'btn-white');
      btnCards.classList.remove('btn-light', 'text-muted');
    }
    if (btnTable) {
      btnTable.classList.remove('active', 'btn-white');
      btnTable.classList.add('btn-light', 'text-muted');
    }
  } else {
    if (cardsContainer) cardsContainer.classList.add('d-none');
    if (tableContainer) tableContainer.classList.remove('d-none');
    if (btnTable) {
      btnTable.classList.add('active', 'btn-white');
      btnTable.classList.remove('btn-light', 'text-muted');
    }
    if (btnCards) {
      btnCards.classList.remove('active', 'btn-white');
      btnCards.classList.add('btn-light', 'text-muted');
    }
    // Re-adjust DataTables columns if table was hidden
    if (window.jQuery && $.fn && $.fn.DataTable) {
      $('table.datatable').each(function () {
        if ($.fn.DataTable.isDataTable(this)) {
          $(this).DataTable().columns.adjust();
        }
      });
    }
  }
  try {
    localStorage.setItem('trias_leads_view_mode', mode);
  } catch(e) {}
}

// Initialize view preference
(function initLeadsView() {
  let saved = null;
  try {
    saved = localStorage.getItem('trias_leads_view_mode');
  } catch(e) {}
  
  // If user hasn't explicitly set preference, use cards on mobile (< 768px) and table on desktop
  if (!saved) {
    saved = (window.innerWidth < 768) ? 'cards' : 'table';
  }
  setLeadsView(saved);
})();

const allPlans = <?= json_encode($db->query("SELECT id,project_id,name,price FROM plans WHERE status='active' ORDER BY name")->fetchAll()) ?>;
const leadsDataMap = <?= json_encode(array_column($leads, null, 'id'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?> || {};

function loadPlans(projectId, selectedId = '') {
  const sel = document.getElementById('lPlan');
  if (!sel) return;
  sel.innerHTML = '<option value="">Not specified</option>';
  if (projectId) {
    allPlans.filter(p => String(p.project_id) === String(projectId)).forEach(p => {
      const isSelected = Boolean(selectedId && String(p.id) === String(selectedId));
      const opt = new Option(`${p.name} (₹${parseFloat(p.price).toLocaleString()})`, p.id, isSelected, isSelected);
      sel.add(opt);
    });
  }
  $(sel).val(selectedId || '').trigger('change.select2');
}

function editLead(idOrObj) {
  let l = null;
  if (typeof idOrObj === 'object' && idOrObj !== null) {
    l = idOrObj;
  } else if (typeof leadsDataMap !== 'undefined' && leadsDataMap[idOrObj]) {
    l = leadsDataMap[idOrObj];
  }
  if (!l) {
    console.error('Lead not found for editing:', idOrObj);
    return;
  }

  document.getElementById('leadId').value         = l.id;
  document.getElementById('lName').value          = l.name || '';
  document.getElementById('lPhone').value         = l.phone || '';
  document.getElementById('lEmail').value         = l.email || '';
  document.getElementById('lCompany').value       = l.company || '';
  document.getElementById('lDesignation').value   = l.designation || '';
  document.getElementById('lWebsite').value       = l.website || '';
  document.getElementById('lAddress').value       = l.address || '';
  document.getElementById('lSource').value        = l.source || 'website';
  document.getElementById('lStatus').value        = l.status || 'new';
  document.getElementById('lNotes').value         = l.notes || '';
  document.getElementById('lTrialEndDate').value   = l.trial_end_date || '';
  document.getElementById('lTrialLoginUrl').value  = l.trial_login_url || '';
  document.getElementById('lTrialUsername').value  = l.trial_username || '';
  document.getElementById('lTrialPassword').value  = l.trial_password || '';
  
  // Set Project, Region, Assigned without triggering full plan clearing
  $('#lProject').val(l.project_id || '').trigger('change', [true]);
  $('#lRegion').val(l.region_id || '').trigger('change.select2');
  $('#lAssigned').val(l.assigned_to || '').trigger('change.select2');
  
  // Load plans specifically for this project and select the interested plan
  loadPlans(l.project_id, l.interested_plan_id);
  
  // Update status (and trial fields display)
  $('#lStatus').trigger('change');
  
  const titleEl = document.getElementById('leadModalTitle');
  if (titleEl) {
    titleEl.innerHTML = '<i class="bi bi-pencil-square me-2 text-primary"></i>Edit Lead: ' + $('<div>').text(l.name || '').html();
  }

  const modalEl = document.getElementById('leadModal');
  const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
  modalInstance.show();
}

// Global delegated event for Edit Lead buttons in Cards, Tables, and DataTables redraws
$(document).on('click', '.btn-edit-lead', function(e) {
  e.preventDefault();
  const id = $(this).data('id');
  editLead(id);
});

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

function openBulkMarkFalseModal() {
  const checkboxes = document.querySelectorAll('.lead-select-checkbox:checked');
  if (checkboxes.length === 0) {
    alert('Please select at least one lead.');
    return;
  }
  const eligible = Array.from(checkboxes).filter(cb => cb.getAttribute('data-status') !== 'false_lead');
  if (eligible.length === 0) {
    alert('All selected leads are already marked as False Lead.');
    return;
  }

  document.getElementById('markFalseLeadId').value = '0';
  document.getElementById('markFalseBulk').value = '1';
  document.getElementById('markFalsePrompt').innerHTML = `Are you sure you want to mark <strong>${eligible.length}</strong> selected lead(s) as <strong>False Lead</strong>?`;
  document.getElementById('markFalseReason').value = '';
  document.getElementById('markFalseNotes').value = '';

  const container = document.getElementById('markFalseBulkInputs');
  container.innerHTML = '';
  eligible.forEach(cb => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'lead_ids[]';
    input.value = cb.value;
    container.appendChild(input);
  });

  new bootstrap.Modal(document.getElementById('markFalseModal')).show();
}

function confirmBulkDelete() {
  const checkboxes = document.querySelectorAll('.lead-select-checkbox:checked');
  if (checkboxes.length === 0) {
    alert('Please select at least one lead to delete.');
    return;
  }
  const invalidDeletes = Array.from(checkboxes).filter(cb => {
    const st = cb.getAttribute('data-status');
    return st !== 'not_interested' && st !== 'false_lead';
  });
  if (invalidDeletes.length > 0) {
    if (!confirm(`${invalidDeletes.length} of the ${checkboxes.length} selected lead(s) are neither "Not Interested" nor "False Lead" and cannot be deleted. Proceed with deleting only the eligible leads?`)) {
      return;
    }
  } else {
    if (!confirm(`Are you sure you want to delete the ${checkboxes.length} selected lead(s)? This action cannot be undone.`)) {
      return;
    }
  }
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

<?php endif; ?>

window.addEventListener('DOMContentLoaded', () => {
  // Direct Meta Leads Sync Action
  $('#btnSyncMetaLeadsDirect').on('click', function() {
    const $btn = $(this);
    const origHtml = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Syncing Meta...');

    $.ajax({
      url: '<?= BASE_URL ?>/api/sync_meta_leads.php?action=sync',
      method: 'GET',
      dataType: 'json',
      success: function(res) {
        $btn.prop('disabled', false).html(origHtml);
        if (res && res.success) {
          alert('SUCCESS: ' + res.message);
          window.location.reload();
        } else {
          alert('Error: ' + (res.message || 'Meta sync failed.'));
        }
      },
      error: function(xhr) {
        $btn.prop('disabled', false).html(origHtml);
        const res = xhr.responseJSON || {};
        const msg = res.message || xhr.responseText || 'Connection failed.';
        if (res.token_expired || msg.toLowerCase().includes('expired') || msg.toLowerCase().includes('token')) {
          alert("⚠️ META ACCESS TOKEN EXPIRED!\n\n" + msg + "\n\nPlease go to Settings -> System Settings to paste a new valid Page Access Token.");
        } else {
          alert("Sync Error: " + msg);
        }
      }
    });
  });

  // Handle status selection change
  $('#lStatus').on('change', function() {
    const status = this.value;
    const trialDiv = document.getElementById('lTrialFields');
    if (!trialDiv) return;
    if (status === 'trial' || status === 'trial_ended') {
      $(trialDiv).show();
      document.getElementById('lTrialEndDate').required = true;
    } else {
      $(trialDiv).hide();
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
      $('#lProject').val('').trigger('change', [true]);
      $('#lRegion').val('').trigger('change.select2');
      $('#lAssigned').val('').trigger('change.select2');
      loadPlans('', '');
      const titleEl = document.getElementById('leadModalTitle');
      if (titleEl) {
        titleEl.innerHTML = '<i class="bi bi-person-plus me-2 text-primary"></i>Add Lead';
      }
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

  $('#lProject').on('change', function(e, isProgrammatic) {
    if (isProgrammatic) {
      if ($(this).hasClass('select2-hidden-accessible')) {
        $(this).trigger('change.select2');
      }
      return;
    }
    loadPlans(this.value);
  });

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

  // Single mark false lead modal trigger
  $(document).on('click', '.btn-mark-false', function() {
    const id = $(this).data('id');
    const name = $(this).data('name');

    document.getElementById('markFalseLeadId').value = id;
    document.getElementById('markFalseBulk').value = '0';
    document.getElementById('markFalseBulkInputs').innerHTML = '';
    document.getElementById('markFalsePrompt').innerHTML = `Are you sure you want to mark <strong>${name}</strong> as a <strong>False Lead</strong>?`;
    document.getElementById('markFalseReason').value = '';
    document.getElementById('markFalseNotes').value = '';

    new bootstrap.Modal(document.getElementById('markFalseModal')).show();
  });

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
  <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?>">
  <input type="hidden" name="id" id="singleDeleteId" value="0">
</form>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
