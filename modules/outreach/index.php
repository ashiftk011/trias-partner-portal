<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('outreach');

$db = getDB();
$user = currentUser();
$isAdmin = isRole('admin');
$isTelecall = isRole('telecall');
$isCsm = isRole('csm');
$telecallProjectIds = $isTelecall ? getTelecallProjectIds() : [];

// Filters
restorePageFilters('outreach', ['project_id', 'status', 'response_type', 'q', 'date_from', 'date_to', 'campaign']);

if ($isTelecall) {
    $req = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    $filterProject = ($req && in_array($req, $telecallProjectIds)) ? $req : 0;
} else {
    $filterProject = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
}

$filterStatus   = $_GET['status'] ?? '';
$filterResponse = $_GET['response_type'] ?? '';
$filterSearch   = trim($_GET['q'] ?? '');
$filterCampaign = trim($_GET['campaign'] ?? '');
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to'] ?? '';

// Build main query
$sql = "SELECT o.*, p.name as project_name, u.name as assigned_name, cb.name as created_by_name,
               l.lead_code, l.status as lead_status
        FROM email_outreaches o
        LEFT JOIN projects p ON p.id = o.project_id
        LEFT JOIN users u ON u.id = o.assigned_to
        LEFT JOIN users cb ON cb.id = o.created_by
        LEFT JOIN leads l ON l.id = o.converted_lead_id
        WHERE 1=1";
$params = [];

// Scoping
if ($isTelecall && $telecallProjectIds) {
    $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
    $sql .= " AND o.project_id IN ($in)";
    $params = array_merge($params, $telecallProjectIds);
} elseif ($isTelecall) {
    $sql .= " AND 1=0";
}

if ($filterProject) {
    $sql .= " AND o.project_id = ?";
    $params[] = $filterProject;
}
if ($filterStatus) {
    $sql .= " AND o.status = ?";
    $params[] = $filterStatus;
}
if ($filterResponse) {
    $sql .= " AND o.response_type = ?";
    $params[] = $filterResponse;
}
if ($filterCampaign) {
    $sql .= " AND o.campaign_name = ?";
    $params[] = $filterCampaign;
}
if ($filterDateFrom) {
    $sql .= " AND o.outreach_date >= ?";
    $params[] = $filterDateFrom;
}
if ($filterDateTo) {
    $sql .= " AND o.outreach_date <= ?";
    $params[] = $filterDateTo;
}
if ($filterSearch) {
    $sql .= " AND (o.contact_name LIKE ? OR o.email LIKE ? OR o.company LIKE ? OR o.outreach_code LIKE ? OR o.email_subject LIKE ?)";
    $term = "%{$filterSearch}%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

$sql .= " ORDER BY o.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$outreaches = $stmt->fetchAll();

// Scoped stats query
$statsSql = "SELECT 
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN status = 'replied' THEN 1 ELSE 0 END) as replied_count,
                SUM(CASE WHEN response_type IN ('meeting_scheduled', 'demo_requested') THEN 1 ELSE 0 END) as meeting_demo_count,
                SUM(CASE WHEN status = 'converted' OR converted_lead_id IS NOT NULL THEN 1 ELSE 0 END) as converted_count,
                SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) as bounced_count
             FROM email_outreaches WHERE 1=1";
$statsParams = [];
if ($isTelecall && $telecallProjectIds) {
    $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
    $statsSql .= " AND project_id IN ($in)";
    $statsParams = array_merge($statsParams, $telecallProjectIds);
} elseif ($isTelecall) {
    $statsSql .= " AND 1=0";
}
if ($filterProject) {
    $statsSql .= " AND project_id = ?";
    $statsParams[] = $filterProject;
}

$statsStmt = $db->prepare($statsSql);
$statsStmt->execute($statsParams);
$stats = $statsStmt->fetch() ?: [
    'total_count' => 0, 'sent_count' => 0, 'replied_count' => 0, 
    'meeting_demo_count' => 0, 'converted_count' => 0, 'bounced_count' => 0
];

$totalCount = (int)$stats['total_count'];
$sentCount = (int)$stats['sent_count'];
$repliedCount = (int)$stats['replied_count'];
$meetingDemoCount = (int)$stats['meeting_demo_count'];
$convertedCount = (int)$stats['converted_count'];
$bouncedCount = (int)$stats['bounced_count'];

$replyRate = $totalCount > 0 ? round(($repliedCount / $totalCount) * 100, 1) : 0;
$convertRate = $totalCount > 0 ? round(($convertedCount / $totalCount) * 100, 1) : 0;

// Projects list for dropdown
if ($isTelecall && $telecallProjectIds) {
    $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
    $pStmt = $db->prepare("SELECT id, name FROM projects WHERE status='active' AND id IN ($in) ORDER BY name");
    $pStmt->execute($telecallProjectIds);
    $projects = $pStmt->fetchAll();
} else {
    $projects = $db->query("SELECT id, name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
}

// Active agents
$agents = $db->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();

// Unique campaigns for filter
$campaigns = $db->query("SELECT DISTINCT campaign_name FROM email_outreaches WHERE campaign_name IS NOT NULL AND campaign_name != '' ORDER BY campaign_name")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Cold Email Outreach';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Header Section -->
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 mb-md-4">
  <div>
    <h4 class="mb-0 fs-4 fw-bold text-dark d-flex align-items-center gap-2">
      <i class="bi bi-envelope-at-fill text-primary"></i> Cold Email Outreach
    </h4>
    <p class="text-muted small mb-0 d-none d-sm-block">Record, track cold outreach responses, schedule demos/meetings, and convert prospects directly to leads.</p>
  </div>
  <div class="d-flex flex-wrap align-items-center justify-content-between justify-content-sm-end gap-2 w-100 w-sm-auto">
    <!-- View Switcher (Cards vs Table) -->
    <div class="btn-group btn-group-sm border rounded-3 p-1 bg-white shadow-sm" id="outreachViewToggleGroup">
      <button type="button" class="btn btn-sm btn-white border-0 fw-semibold active" id="btnViewCards" onclick="setOutreachView('cards')">
        <i class="bi bi-grid-fill me-1"></i>Cards
      </button>
      <button type="button" class="btn btn-sm btn-light border-0 fw-semibold text-muted" id="btnViewTable" onclick="setOutreachView('table')">
        <i class="bi bi-table me-1"></i>Table
      </button>
    </div>

    <div class="d-flex gap-2 align-items-center">
      <a href="<?= BASE_URL ?>/modules/outreach/import.php" class="btn btn-outline-secondary btn-sm rounded-3 px-2 px-sm-3" title="Import CSV">
        <i class="bi bi-file-earmark-arrow-up me-1"></i><span class="d-none d-md-inline">Import </span>CSV
      </a>
      <a href="<?= BASE_URL ?>/modules/outreach/export.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" class="btn btn-outline-secondary btn-sm rounded-3 px-2 px-sm-3" title="Export CSV">
        <i class="bi bi-download me-1"></i><span class="d-none d-md-inline">Export</span>
      </a>
      <a href="<?= BASE_URL ?>/modules/outreach/save.php" class="btn btn-primary btn-sm rounded-3 px-3 shadow-sm fw-semibold">
        <i class="bi bi-plus-lg me-1"></i>Record<span class="d-none d-sm-inline"> Outreach</span>
      </a>
    </div>
  </div>
</div>

<?php displayFlash(); ?>

<!-- KPI Summary Cards -->
<div class="row g-2 g-md-3 mb-3 mb-md-4">
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold text-truncate">Total Outreach</span>
        <div class="p-2 rounded-3 bg-primary-subtle text-primary flex-shrink-0">
          <i class="bi bi-send-fill fs-6"></i>
        </div>
      </div>
      <h4 class="fw-bold mb-0 text-dark"><?= number_format($totalCount) ?></h4>
      <div class="small text-muted mt-1 text-truncate" style="font-size: 0.72rem;"><i class="bi bi-people me-1"></i>Outreached</div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold text-truncate">Waiting Reply</span>
        <div class="p-2 rounded-3 bg-warning-subtle text-warning-emphasis flex-shrink-0">
          <i class="bi bi-hourglass-split fs-6"></i>
        </div>
      </div>
      <h4 class="fw-bold mb-0 text-warning-emphasis"><?= number_format($sentCount) ?></h4>
      <div class="small text-muted mt-1 text-truncate" style="font-size: 0.72rem;">Pending response</div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-2">
    <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold text-truncate">Replied</span>
        <div class="p-2 rounded-3 bg-info-subtle text-info flex-shrink-0">
          <i class="bi bi-chat-left-dots-fill fs-6"></i>
        </div>
      </div>
      <h4 class="fw-bold mb-0 text-info"><?= number_format($repliedCount) ?></h4>
      <div class="small text-success fw-semibold mt-1 text-truncate" style="font-size: 0.72rem;">
        <i class="bi bi-graph-up-arrow me-1"></i><?= $replyRate ?>% rate
      </div>
    </div>
  </div>

  <div class="col-6 col-md-4 col-xl-3">
    <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white" style="border-left: 4px solid #10b981 !important;">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold text-truncate">Meeting / Demo Asked</span>
        <div class="p-2 rounded-3 bg-success-subtle text-success flex-shrink-0">
          <i class="bi bi-calendar-check-fill fs-6"></i>
        </div>
      </div>
      <h4 class="fw-bold mb-0 text-success"><?= number_format($meetingDemoCount) ?></h4>
      <div class="small text-muted mt-1 text-truncate" style="font-size: 0.72rem;">High-intent replies</div>
    </div>
  </div>

  <div class="col-12 col-md-8 col-xl-3">
    <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white" style="border-left: 4px solid #6366f1 !important;">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold text-truncate">Converted to Lead</span>
        <div class="p-2 rounded-3 bg-primary-subtle text-primary flex-shrink-0">
          <i class="bi bi-person-check-fill fs-6"></i>
        </div>
      </div>
      <div class="d-flex align-items-baseline gap-2">
        <h4 class="fw-bold mb-0 text-primary"><?= number_format($convertedCount) ?></h4>
        <span class="badge bg-success-subtle text-success fw-semibold"><?= $convertRate ?>% converted</span>
      </div>
      <div class="small text-muted mt-1 text-truncate" style="font-size: 0.72rem;">Directly fed into Leads pipeline</div>
    </div>
  </div>
</div>

<?php
$activeFilterCount = ($filterProject ? 1 : 0) + ($filterStatus ? 1 : 0) + ($filterResponse ? 1 : 0) + ($filterCampaign ? 1 : 0) + ($filterSearch ? 1 : 0) + ($filterDateFrom ? 1 : 0) + ($filterDateTo ? 1 : 0);
?>
<!-- Status Filters Bar -->
<div class="card border-0 shadow-sm rounded-3 mb-3">
  <div class="card-body p-3">
    <!-- Mobile Filter Header Toggle -->
    <div class="d-flex d-md-none justify-content-between align-items-center mb-2">
      <div class="d-flex align-items-center gap-2">
        <span class="fw-bold text-dark small"><i class="bi bi-funnel text-primary me-1"></i>Search &amp; Filters</span>
        <?php if ($activeFilterCount > 0): ?>
          <span class="badge bg-primary rounded-pill"><?= $activeFilterCount ?> active</span>
        <?php endif; ?>
      </div>
      <div class="d-flex align-items-center gap-2">
        <?php if ($activeFilterCount > 0): ?>
          <a href="?clear=1" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.75rem;">
            Clear All
          </a>
        <?php endif; ?>
        <button class="btn btn-sm btn-light border py-1 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#outreachFiltersCollapse" aria-expanded="<?= $activeFilterCount > 0 ? 'true' : 'false' ?>">
          <i class="bi bi-sliders me-1"></i><span><?= $activeFilterCount > 0 ? 'Hide' : 'Filter' ?></span>
        </button>
      </div>
    </div>

    <!-- Quick Status Pills (Scrollable horizontally) -->
    <div class="d-flex align-items-center gap-2 expense-nav-scroll pb-2 pb-md-0">
      <span class="text-muted small fw-semibold me-1 text-nowrap">Status:</span>
      <a href="?<?= http_build_query(array_merge($_GET, ['status' => ''])) ?>"
         class="badge <?= empty($filterStatus) ? 'bg-primary text-white' : 'bg-light text-dark' ?> text-decoration-none p-2 rounded-pill px-3 text-nowrap">
        All (<?= $totalCount ?>)
      </a>
      <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'sent'])) ?>"
         class="badge <?= $filterStatus === 'sent' ? 'bg-warning text-dark' : 'bg-light text-dark' ?> text-decoration-none p-2 rounded-pill px-3 text-nowrap">
        <i class="bi bi-hourglass-split me-1"></i> Waiting (<?= $sentCount ?>)
      </a>
      <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'replied'])) ?>"
         class="badge <?= $filterStatus === 'replied' ? 'bg-info text-white' : 'bg-light text-dark' ?> text-decoration-none p-2 rounded-pill px-3 text-nowrap">
        <i class="bi bi-chat-dots-fill me-1"></i> Replied (<?= $repliedCount ?>)
      </a>
      <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'converted'])) ?>"
         class="badge <?= $filterStatus === 'converted' ? 'bg-success text-white' : 'bg-light text-dark' ?> text-decoration-none p-2 rounded-pill px-3 text-nowrap">
        <i class="bi bi-check-circle-fill me-1"></i> Converted (<?= $convertedCount ?>)
      </a>
      <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'not_interested'])) ?>"
         class="badge <?= $filterStatus === 'not_interested' ? 'bg-secondary text-white' : 'bg-light text-dark' ?> text-decoration-none p-2 rounded-pill px-3 text-nowrap">
        Not Interested
      </a>
      <?php if ($activeFilterCount > 0): ?>
        <a href="?clear=1" class="btn btn-sm btn-outline-danger rounded-pill px-3 text-nowrap d-none d-md-inline-block ms-auto">
          <i class="bi bi-x-circle me-1"></i> Clear Filters
        </a>
      <?php endif; ?>
    </div>

    <!-- Collapsible on mobile, open on desktop -->
    <div class="collapse d-md-block <?= $activeFilterCount > 0 ? 'show' : '' ?>" id="outreachFiltersCollapse">
      <hr class="my-3 text-muted opacity-25">
      <form method="GET" class="row g-2 align-items-center">
        <?php if ($filterStatus): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
        <?php endif; ?>

        <div class="col-12 col-md-3">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" class="form-control bg-light border-start-0" placeholder="Search name, email, company..." value="<?= htmlspecialchars($filterSearch) ?>">
          </div>
        </div>

        <div class="col-6 col-md-3">
          <select name="project_id" class="form-select form-select-sm bg-light">
            <option value="">All Projects</option>
            <?php foreach ($projects as $pr): ?>
            <option value="<?= $pr['id'] ?>" <?= $filterProject === (int)$pr['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($pr['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-3">
          <select name="response_type" class="form-select form-select-sm bg-light">
            <option value="">All Response Outcomes</option>
            <option value="meeting_scheduled" <?= $filterResponse === 'meeting_scheduled' ? 'selected' : '' ?>>Meeting Scheduled</option>
            <option value="demo_requested" <?= $filterResponse === 'demo_requested' ? 'selected' : '' ?>>Demo Asked</option>
            <option value="interested_more_info" <?= $filterResponse === 'interested_more_info' ? 'selected' : '' ?>>Interested / More Info</option>
            <option value="pricing_inquiry" <?= $filterResponse === 'pricing_inquiry' ? 'selected' : '' ?>>Pricing Inquiry</option>
            <option value="call_back_later" <?= $filterResponse === 'call_back_later' ? 'selected' : '' ?>>Follow-up Later</option>
            <option value="referral" <?= $filterResponse === 'referral' ? 'selected' : '' ?>>Referral</option>
            <option value="not_interested" <?= $filterResponse === 'not_interested' ? 'selected' : '' ?>>Not Interested</option>
            <option value="other" <?= $filterResponse === 'other' ? 'selected' : '' ?>>Other Response</option>
          </select>
        </div>

        <div class="col-12 col-md-3 d-flex gap-2">
          <?php if (!empty($campaigns)): ?>
          <select name="campaign" class="form-select form-select-sm bg-light">
            <option value="">All Campaigns</option>
            <?php foreach ($campaigns as $camp): ?>
            <option value="<?= htmlspecialchars($camp) ?>" <?= $filterCampaign === $camp ? 'selected' : '' ?>>
              <?= htmlspecialchars($camp) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <button type="submit" class="btn btn-sm btn-primary px-3 rounded-3 flex-shrink-0">
            <i class="bi bi-funnel-fill me-1"></i> Filter
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Mobile Cards View Container (Shown by default on mobile / when Cards selected) -->
<div id="outreachCardsContainer">
  <?php if (empty($outreaches)): ?>
    <div class="card border-0 shadow-sm p-4 text-center">
      <div class="avatar avatar-lg bg-light text-muted rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
        <i class="bi bi-inbox fs-2 text-secondary"></i>
      </div>
      <h6 class="fw-semibold text-dark mb-1">No Outreach Records Found</h6>
      <p class="text-muted small mb-3">No email outreaches match your search or filter criteria.</p>
      <a href="<?= BASE_URL ?>/modules/outreach/save.php" class="btn btn-primary btn-sm mx-auto">
        <i class="bi bi-plus-lg me-1"></i>Record Outreach
      </a>
    </div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($outreaches as $row): 
        $cleanPhone = preg_replace('/\D/', '', $row['phone'] ?? '');
        $isMeeting = in_array($row['response_type'], ['meeting_scheduled', 'demo_requested']);
        $isReplied = ($row['status'] === 'replied');
        $isConverted = !empty($row['converted_lead_id']) || ($row['status'] === 'converted');
        $borderClass = $isConverted ? 'is-converted' : ($isMeeting ? 'is-meeting' : ($isReplied ? 'is-replied' : ''));
      ?>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="outreach-mobile-card h-100 d-flex flex-column justify-content-between <?= $borderClass ?>">
          <div>
            <!-- Top Row: Project Badge, Outreach Code & Status -->
            <div class="d-flex align-items-center justify-content-between gap-1 mb-2">
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle text-truncate" style="max-width: 160px;">
                <i class="bi bi-folder2 me-1"></i><?= htmlspecialchars($row['project_name'] ?? 'Unassigned') ?>
              </span>
              <div class="d-flex align-items-center gap-1">
                <a href="<?= BASE_URL ?>/modules/outreach/view.php?id=<?= $row['id'] ?>" class="text-muted font-monospace small text-decoration-none">
                  <?= htmlspecialchars($row['outreach_code']) ?>
                </a>
                <div class="ms-1"><?= outreachStatusBadge($row['status']) ?></div>
              </div>
            </div>

            <!-- Contact & Company -->
            <div class="mb-2">
              <a href="<?= BASE_URL ?>/modules/outreach/view.php?id=<?= $row['id'] ?>" class="fw-bold text-dark text-decoration-none fs-6 d-block lh-sm">
                <?= htmlspecialchars($row['contact_name']) ?>
              </a>
              <?php if (!empty($row['company']) || !empty($row['designation'])): ?>
                <div class="text-muted small mt-1 text-truncate">
                  <?php if (!empty($row['company'])): ?>
                    <i class="bi bi-building me-1 text-secondary"></i><span class="fw-medium text-dark"><?= htmlspecialchars($row['company']) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($row['designation'])): ?>
                    <span class="text-muted">&bull; <?= htmlspecialchars($row['designation']) ?></span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Contact Row (Email, Phone, WhatsApp) -->
            <div class="bg-light p-2 px-3 rounded-3 mb-2 border">
              <div class="d-flex align-items-center justify-content-between gap-2">
                <a href="mailto:<?= htmlspecialchars($row['email']) ?>" class="small text-decoration-none text-dark text-truncate">
                  <i class="bi bi-envelope me-1 text-primary"></i><?= htmlspecialchars($row['email']) ?>
                </a>
                <?php if (!empty($row['phone'])): ?>
                  <div class="d-flex gap-2 flex-shrink-0">
                    <a href="tel:<?= htmlspecialchars($row['phone']) ?>" class="text-success small text-decoration-none" title="Call">
                      <i class="bi bi-telephone-fill"></i>
                    </a>
                    <?php if ($cleanPhone): ?>
                      <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" class="text-success small text-decoration-none" title="WhatsApp">
                        <i class="bi bi-whatsapp"></i>
                      </a>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              <?php if (!empty($row['phone'])): ?>
                <div class="small text-muted mt-1" style="font-size: 0.76rem;">
                  <i class="bi bi-phone me-1"></i><?= htmlspecialchars($row['phone']) ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Response & Meeting Details -->
            <div class="mb-2">
              <div class="d-flex align-items-center justify-content-between gap-1 flex-wrap">
                <div><?= outreachResponseBadge($row['response_type']) ?></div>
                <div class="text-muted small" style="font-size: 0.72rem;">
                  <i class="bi bi-calendar3 me-1"></i><?= date('d M Y', strtotime($row['outreach_date'])) ?>
                </div>
              </div>

              <?php if (!empty($row['meeting_date'])): ?>
                <div class="alert alert-success py-1 px-2 mt-2 mb-0 small d-flex align-items-center gap-1" style="font-size: 0.78rem;">
                  <i class="bi bi-calendar2-check-fill text-success flex-shrink-0"></i>
                  <span><strong>Meeting:</strong> <?= date('d M Y, h:i A', strtotime($row['meeting_date'])) ?></span>
                </div>
              <?php endif; ?>

              <?php if (!empty($row['response_summary'])): ?>
                <div class="text-muted small mt-2 bg-light p-2 rounded-2 border" style="font-size: 0.8rem;">
                  <i class="bi bi-chat-left-quote me-1 text-secondary"></i><?= htmlspecialchars(mb_strimwidth($row['response_summary'], 0, 110, '...')) ?>
                </div>
              <?php endif; ?>

              <?php if (!empty($row['converted_lead_id'])): ?>
                <div class="mt-2">
                  <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $row['converted_lead_id'] ?>" class="badge bg-success-subtle text-success border border-success-subtle text-decoration-none w-100 text-start py-1">
                    <i class="bi bi-person-check-fill me-1"></i>Converted to Lead: <?= htmlspecialchars($row['lead_code'] ?: '#' . $row['converted_lead_id']) ?>
                  </a>
                </div>
              <?php endif; ?>
            </div>

            <!-- Campaign & Assigned Agent Tag -->
            <div class="d-flex align-items-center justify-content-between text-muted small mb-3 pt-1 border-top" style="font-size: 0.72rem;">
              <span class="text-truncate" style="max-width: 140px;">
                <i class="bi bi-person me-1"></i><?= htmlspecialchars($row['assigned_name'] ?: 'Unassigned') ?>
              </span>
              <span>
                Touches: <strong class="text-dark"><?= (int)$row['followup_count'] + 1 ?></strong>
              </span>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="d-flex align-items-center gap-1 pt-2 border-top">
            <!-- Quick Response Button -->
            <button type="button" class="btn btn-outline-primary btn-sm flex-grow-1" title="Record Reply"
                    onclick="openReplyModal(<?= htmlspecialchars(json_encode([
                      'id' => $row['id'],
                      'code' => $row['outreach_code'],
                      'name' => $row['contact_name'],
                      'company' => $row['company'],
                      'project' => $row['project_name'],
                      'status' => $row['status'],
                      'response_type' => $row['response_type'],
                      'response_summary' => $row['response_summary'],
                      'meeting_date' => $row['meeting_date'] ? date('Y-m-d\TH:i', strtotime($row['meeting_date'])) : '',
                      'next_followup_date' => $row['next_followup_date'],
                      'is_converted' => !empty($row['converted_lead_id'])
                    ])) ?>)">
              <i class="bi bi-reply-fill me-1"></i>Reply
            </button>

            <!-- Convert / View Converted Lead Button -->
            <?php if (empty($row['converted_lead_id']) && $row['status'] !== 'converted'): ?>
              <a href="<?= BASE_URL ?>/modules/outreach/convert.php?id=<?= $row['id'] ?>" class="btn btn-outline-success btn-sm flex-grow-1" title="Convert to Lead">
                <i class="bi bi-person-plus-fill me-1"></i>Convert
              </a>
            <?php else: ?>
              <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $row['converted_lead_id'] ?>" class="btn btn-outline-success btn-sm flex-grow-1" title="View Converted Lead">
                <i class="bi bi-check-circle-fill me-1"></i>Lead
              </a>
            <?php endif; ?>

            <!-- View Details -->
            <a href="<?= BASE_URL ?>/modules/outreach/view.php?id=<?= $row['id'] ?>" class="btn btn-outline-secondary btn-sm px-2" title="View Details">
              <i class="bi bi-eye"></i>
            </a>

            <!-- Dropdown Menu for Edit/Delete -->
            <div class="dropdown">
              <button class="btn btn-outline-secondary btn-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-three-dots-vertical"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3">
                <li>
                  <a class="dropdown-item small" href="<?= BASE_URL ?>/modules/outreach/save.php?id=<?= $row['id'] ?>">
                    <i class="bi bi-pencil me-2 text-warning"></i>Edit Record
                  </a>
                </li>
                <?php if ($isAdmin): ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form method="POST" action="<?= BASE_URL ?>/modules/outreach/delete.php" onsubmit="return confirm('Delete this outreach record? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                    <button type="submit" class="dropdown-item text-danger small">
                      <i class="bi bi-trash me-2"></i>Delete Record
                    </button>
                  </form>
                </li>
                <?php endif; ?>
              </ul>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Desktop/Full Table View Container -->
<div id="outreachTableContainer" class="d-none">
  <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
    <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-2">
        <h6 class="mb-0 fw-bold text-dark">Outreach Records</h6>
        <span class="badge bg-secondary-subtle text-secondary rounded-pill"><?= count($outreaches) ?> items</span>
      </div>
      <div class="small text-muted">
        Sorted by recent outreach date
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="outreachTable">
        <thead class="table-light text-muted small text-uppercase">
          <tr>
            <th class="ps-4">Code / Date</th>
            <th>Contact & Company</th>
            <th>Email & Phone</th>
            <th>Target Project</th>
            <th>Status</th>
            <th>Response Outcome</th>
            <th>Assigned / Touches</th>
            <th class="text-end pe-4">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($outreaches)): ?>
          <tr>
            <td colspan="8" class="text-center py-5">
              <div class="py-4">
                <i class="bi bi-inbox text-muted display-4"></i>
                <p class="text-muted mt-3 mb-2">No email outreaches found matching the criteria.</p>
                <a href="<?= BASE_URL ?>/modules/outreach/save.php" class="btn btn-sm btn-primary rounded-pill px-3">
                  <i class="bi bi-plus-lg me-1"></i> Record First Outreach
                </a>
              </div>
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($outreaches as $row): ?>
          <tr>
            <!-- Code / Date -->
            <td class="ps-4">
              <a href="<?= BASE_URL ?>/modules/outreach/view.php?id=<?= $row['id'] ?>" class="fw-bold text-primary text-decoration-none">
                <?= htmlspecialchars($row['outreach_code']) ?>
              </a>
              <div class="text-muted small">
                <i class="bi bi-calendar3 me-1"></i><?= date('M d, Y', strtotime($row['outreach_date'])) ?>
              </div>
            </td>

            <!-- Contact & Company -->
            <td>
              <div class="fw-semibold text-dark">
                <a href="<?= BASE_URL ?>/modules/outreach/view.php?id=<?= $row['id'] ?>" class="text-dark text-decoration-none hover-primary">
                  <?= htmlspecialchars($row['contact_name']) ?>
                </a>
              </div>
              <div class="small text-muted text-truncate" style="max-width: 220px;">
                <?php if (!empty($row['company'])): ?>
                  <span class="fw-medium text-secondary"><i class="bi bi-building me-1"></i><?= htmlspecialchars($row['company']) ?></span>
                <?php endif; ?>
                <?php if (!empty($row['designation'])): ?>
                  <span class="text-muted"> &bull; <?= htmlspecialchars($row['designation']) ?></span>
                <?php endif; ?>
              </div>
            </td>

            <!-- Email & Phone -->
            <td>
              <div>
                <a href="mailto:<?= htmlspecialchars($row['email']) ?>" class="small text-decoration-none text-dark">
                  <i class="bi bi-envelope me-1 text-primary"></i><?= htmlspecialchars($row['email']) ?>
                </a>
              </div>
              <?php if (!empty($row['phone'])): ?>
              <div class="small text-muted">
                <i class="bi bi-telephone me-1 text-success"></i><?= htmlspecialchars($row['phone']) ?>
              </div>
              <?php endif; ?>
              <?php if (!empty($row['website'])): ?>
              <div class="small">
                <a href="<?= htmlspecialchars(str_starts_with($row['website'], 'http') ? $row['website'] : 'https://' . $row['website']) ?>" target="_blank" class="text-decoration-none text-muted small">
                  <i class="bi bi-globe me-1"></i><?= htmlspecialchars(parse_url($row['website'], PHP_URL_HOST) ?: $row['website']) ?>
                </a>
              </div>
              <?php endif; ?>
            </td>

            <!-- Target Project -->
            <td>
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-2">
                <i class="bi bi-folder2 me-1"></i><?= htmlspecialchars($row['project_name'] ?? 'Unassigned') ?>
              </span>
              <?php if (!empty($row['campaign_name'])): ?>
              <div class="small text-muted mt-1 text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($row['campaign_name']) ?>">
                <i class="bi bi-tag me-1"></i><?= htmlspecialchars($row['campaign_name']) ?>
              </div>
              <?php endif; ?>
            </td>

            <!-- Status -->
            <td>
              <?= outreachStatusBadge($row['status']) ?>
              <?php if (!empty($row['converted_lead_id'])): ?>
              <div class="mt-1">
                <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $row['converted_lead_id'] ?>" class="badge bg-success text-white text-decoration-none">
                  <i class="bi bi-person-check-fill me-1"></i>Lead: <?= htmlspecialchars($row['lead_code'] ?: '#' . $row['converted_lead_id']) ?>
                </a>
              </div>
              <?php endif; ?>
            </td>

            <!-- Response Outcome -->
            <td>
              <div><?= outreachResponseBadge($row['response_type']) ?></div>
              <?php if (!empty($row['response_summary'])): ?>
              <div class="small text-muted mt-1 text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($row['response_summary']) ?>">
                <i class="bi bi-quote me-1"></i><?= htmlspecialchars($row['response_summary']) ?>
              </div>
              <?php endif; ?>
              <?php if (!empty($row['meeting_date'])): ?>
              <div class="small text-success mt-1 fw-medium">
                <i class="bi bi-clock me-1"></i><?= date('M d, h:i A', strtotime($row['meeting_date'])) ?>
              </div>
              <?php endif; ?>
            </td>

            <!-- Assigned / Touches -->
            <td>
              <div class="small text-dark fw-medium">
                <i class="bi bi-person me-1 text-muted"></i><?= htmlspecialchars($row['assigned_name'] ?: 'Unassigned') ?>
              </div>
              <div class="small text-muted">
                Touches: <span class="badge bg-light text-dark border"><?= (int)$row['followup_count'] + 1 ?></span>
              </div>
            </td>

            <!-- Actions -->
            <td class="text-end pe-4">
              <div class="btn-group btn-group-sm">
                <!-- Quick Response Button -->
                <button type="button" class="btn btn-outline-primary btn-sm rounded-start" title="Record Reply"
                        onclick="openReplyModal(<?= htmlspecialchars(json_encode([
                          'id' => $row['id'],
                          'code' => $row['outreach_code'],
                          'name' => $row['contact_name'],
                          'company' => $row['company'],
                          'project' => $row['project_name'],
                          'status' => $row['status'],
                          'response_type' => $row['response_type'],
                          'response_summary' => $row['response_summary'],
                          'meeting_date' => $row['meeting_date'] ? date('Y-m-d\TH:i', strtotime($row['meeting_date'])) : '',
                          'next_followup_date' => $row['next_followup_date'],
                          'is_converted' => !empty($row['converted_lead_id'])
                        ])) ?>)">
                  <i class="bi bi-reply-fill"></i>
                </button>

                <!-- Convert to Lead Button -->
                <?php if (empty($row['converted_lead_id']) && $row['status'] !== 'converted'): ?>
                <a href="<?= BASE_URL ?>/modules/outreach/convert.php?id=<?= $row['id'] ?>" class="btn btn-outline-success btn-sm" title="Convert to Lead">
                  <i class="bi bi-person-plus-fill"></i>
                </a>
                <?php else: ?>
                <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $row['converted_lead_id'] ?>" class="btn btn-outline-success btn-sm" title="View Converted Lead">
                  <i class="bi bi-arrow-up-right-circle-fill"></i>
                </a>
                <?php endif; ?>

                <!-- View Details -->
                <a href="<?= BASE_URL ?>/modules/outreach/view.php?id=<?= $row['id'] ?>" class="btn btn-outline-secondary btn-sm" title="View Details">
                  <i class="bi bi-eye"></i>
                </a>

                <!-- Dropdown for more -->
                <div class="dropdown d-inline-block">
                  <button class="btn btn-outline-secondary btn-sm dropdown-toggle rounded-end" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3">
                    <li>
                      <a class="dropdown-item" href="<?= BASE_URL ?>/modules/outreach/save.php?id=<?= $row['id'] ?>">
                        <i class="bi bi-pencil me-2 text-warning"></i> Edit Outreach
                      </a>
                    </li>
                    <?php if (empty($row['converted_lead_id'])): ?>
                    <li>
                      <a class="dropdown-item" href="<?= BASE_URL ?>/modules/outreach/convert.php?id=<?= $row['id'] ?>">
                        <i class="bi bi-person-check me-2 text-success"></i> Convert to Lead
                      </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                      <form method="POST" action="<?= BASE_URL ?>/modules/outreach/delete.php" onsubmit="return confirm('Delete this outreach record? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                        <button type="submit" class="dropdown-item text-danger">
                          <i class="bi bi-trash me-2"></i> Delete Record
                        </button>
                      </form>
                    </li>
                    <?php endif; ?>
                  </ul>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Quick Record Response Modal -->
<div class="modal fade" id="replyModal" tabindex="-1" aria-labelledby="replyModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <form method="POST" action="<?= BASE_URL ?>/modules/outreach/log_reply.php">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="outreach_id" id="reply_outreach_id" value="">
        <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

        <div class="modal-header bg-primary text-white py-3 rounded-top-4">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-reply-fill fs-5"></i>
            <div>
              <h5 class="modal-title fw-bold mb-0" id="replyModalLabel">Record Prospect Reply</h5>
              <div class="small opacity-75" id="replyModalSubtitle">Update outreach outcome</div>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-4">
          <div class="alert alert-light border small py-2 mb-3 d-flex justify-content-between">
            <div><strong id="modalContactName"></strong> <span id="modalCompany" class="text-muted"></span></div>
            <div class="badge bg-primary-subtle text-primary" id="modalProject"></div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Response Outcome <span class="text-danger">*</span></label>
            <select name="response_type" id="reply_response_type" class="form-select" required onchange="toggleResponseFields()">
              <option value="meeting_scheduled">Meeting Scheduled</option>
              <option value="demo_requested">Demo Asked / Requested</option>
              <option value="interested_more_info">Interested / Requesting Info</option>
              <option value="pricing_inquiry">Pricing / Commercial Inquiry</option>
              <option value="call_back_later">Follow-up Later (Check back)</option>
              <option value="referral">Referred to Colleague</option>
              <option value="not_interested">Not Interested / Not Now</option>
              <option value="other">Other Response</option>
            </select>
            <div class="form-text small">Select what the prospect communicated in their reply.</div>
          </div>

          <!-- Meeting Date Field (Shows when Meeting Scheduled or Demo Asked) -->
          <div class="mb-3" id="meetingDateFieldWrapper">
            <label class="form-label fw-semibold small text-success">
              <i class="bi bi-calendar2-check-fill me-1"></i> Scheduled Meeting / Demo Time
            </label>
            <input type="datetime-local" name="meeting_date" id="reply_meeting_date" class="form-control">
            <div class="form-text small">Record agreed meeting or presentation schedule.</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Response Message / Summary</label>
            <textarea name="response_summary" id="reply_response_summary" rows="3" class="form-control" placeholder="Summarize their reply, requirements, objections, or demo interests..."></textarea>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Reply Date & Time</label>
              <input type="datetime-local" name="replied_at" id="reply_replied_at" class="form-control form-control-sm" value="<?= date('Y-m-d\TH:i') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Next Follow-up Date</label>
              <input type="date" name="next_followup_date" id="reply_next_followup_date" class="form-control form-control-sm">
            </div>
          </div>

          <!-- Convert directly to lead checkbox -->
          <div class="card bg-success-subtle border-success-subtle p-3 rounded-3" id="convertOptionWrapper">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="convert_to_lead" value="1" id="convertLeadCheck" checked>
              <label class="form-check-label fw-semibold text-success-emphasis" for="convertLeadCheck">
                <i class="bi bi-person-check-fill me-1"></i> Convert to Lead immediately
              </label>
              <div class="small text-muted mt-1">
                Creates a new Lead in the pipeline under this project so your sales & telecall team can follow through.
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light py-3 px-4 rounded-bottom-4">
          <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 fw-semibold">
            <i class="bi bi-check2-circle me-1"></i> Save Response
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Mobile Floating Action Button (FAB) -->
<a href="<?= BASE_URL ?>/modules/outreach/save.php" class="btn btn-primary mobile-fab-btn d-md-none" title="Record Outreach" aria-label="Record Outreach">
  <i class="bi bi-plus-lg fs-4"></i>
</a>

<script>
function setOutreachView(mode) {
  const cardsContainer = document.getElementById('outreachCardsContainer');
  const tableContainer = document.getElementById('outreachTableContainer');
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
  }
  try {
    localStorage.setItem('trias_outreach_view_mode', mode);
  } catch(e) {}
}

(function initOutreachView() {
  let saved = null;
  try {
    saved = localStorage.getItem('trias_outreach_view_mode');
  } catch(e) {}
  if (saved) {
    setOutreachView(saved);
  } else {
    setOutreachView(window.innerWidth < 992 ? 'cards' : 'table');
  }
})();

function openReplyModal(data) {
  document.getElementById('reply_outreach_id').value = data.id;
  document.getElementById('replyModalSubtitle').textContent = 'Outreach: ' + data.code;
  document.getElementById('modalContactName').textContent = data.name;
  document.getElementById('modalCompany').textContent = data.company ? '(' + data.company + ')' : '';
  document.getElementById('modalProject').textContent = data.project;

  if (data.response_type && data.response_type !== 'none') {
    document.getElementById('reply_response_type').value = data.response_type;
  } else {
    document.getElementById('reply_response_type').value = 'meeting_scheduled';
  }

  document.getElementById('reply_response_summary').value = data.response_summary || '';
  document.getElementById('reply_meeting_date').value = data.meeting_date || '';
  document.getElementById('reply_next_followup_date').value = data.next_followup_date || '';

  const convertWrapper = document.getElementById('convertOptionWrapper');
  if (data.is_converted) {
    convertWrapper.style.display = 'none';
  } else {
    convertWrapper.style.display = 'block';
  }

  toggleResponseFields();

  const modal = new bootstrap.Modal(document.getElementById('replyModal'));
  modal.show();
}

function toggleResponseFields() {
  const respType = document.getElementById('reply_response_type').value;
  const meetingWrap = document.getElementById('meetingDateFieldWrapper');
  const convertCheck = document.getElementById('convertLeadCheck');

  if (respType === 'meeting_scheduled' || respType === 'demo_requested') {
    meetingWrap.style.display = 'block';
    if (convertCheck) convertCheck.checked = true;
  } else if (respType === 'interested_more_info' || respType === 'pricing_inquiry') {
    meetingWrap.style.display = 'none';
    if (convertCheck) convertCheck.checked = true;
  } else {
    meetingWrap.style.display = 'none';
    if (convertCheck) convertCheck.checked = false;
  }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
