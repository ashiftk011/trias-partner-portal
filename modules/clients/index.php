<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('clients');

$db = getDB();
$user = currentUser();
$isInvestor = isRole('investor');
$isCsm = isRole('csm');
$investorProjectId = $isInvestor ? getInvestorProjectId() : 0;

// Persist & restore filters across requests/edits
restorePageFilters('clients', ['project_id', 'status', 'q']);

$filterProject = $isInvestor ? $investorProjectId : (isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0);
$filterStatus  = $_GET['status'] ?? '';
$filterSearch  = trim($_GET['q'] ?? '');

$sql = "SELECT c.*, p.name as project_name, pl.name as plan_name, r.name as region_name,
               (SELECT end_date FROM renewals WHERE client_id=c.id AND status='active' ORDER BY end_date DESC LIMIT 1) as renewal_end
        FROM clients c
        LEFT JOIN projects p ON p.id=c.project_id
        LEFT JOIN plans pl ON pl.id=c.plan_id
        LEFT JOIN regions r ON r.id=c.region_id
        WHERE 1=1";
$params = [];
if ($isCsm) {
    $sql .= " AND c.created_by = ?";
    $params[] = $user['id'];
}
if ($filterProject) { $sql .= " AND c.project_id=?"; $params[] = $filterProject; }
if ($filterStatus)  { $sql .= " AND c.status=?";     $params[] = $filterStatus; }
if ($filterSearch)  { $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.company LIKE ? OR c.client_code LIKE ?)";
                      $params = array_merge($params, ["%$filterSearch%","%$filterSearch%","%$filterSearch%","%$filterSearch%"]); }
$sql .= " ORDER BY c.created_at DESC";

$stmt = $db->prepare($sql); $stmt->execute($params);
$clients = $stmt->fetchAll();

$projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();

// Summary counts
$counts = [];
if ($isCsm) {
    $cntStmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM clients WHERE created_by = ? GROUP BY status");
    $cntStmt->execute([$user['id']]);
    foreach ($cntStmt as $row) {
        $counts[$row['status']] = $row['cnt'];
    }
} else {
    foreach ($db->query("SELECT status, COUNT(*) as cnt FROM clients GROUP BY status") as $row) {
        $counts[$row['status']] = $row['cnt'];
    }
}
$totalClients = array_sum($counts);

$activeFilterCount = 0;
if ($filterSearch) $activeFilterCount++;
if (!$isInvestor && $filterProject) $activeFilterCount++;
if ($filterStatus) $activeFilterCount++;

$pageTitle = 'Clients';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Header -->
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <div class="d-flex align-items-center gap-2">
      <h4 class="mb-0 fw-bold">Clients</h4>
      <span class="badge bg-primary-subtle text-primary rounded-pill px-2"><?= count($clients) ?><?= count($clients) !== $totalClients ? " of {$totalClients}" : '' ?></span>
    </div>
    <p class="text-muted small mb-0">Registered clients, active subscriptions & instances</p>
  </div>

  <div class="d-flex flex-wrap align-items-center gap-2">
    <!-- View Switcher (Cards vs Table) -->
    <div class="btn-group btn-group-sm bg-light p-1 rounded-pill border" role="group" aria-label="View switch">
      <button type="button" class="btn btn-sm rounded-pill px-3 active btn-white shadow-sm" id="btnViewCards" onclick="setClientsView('cards')">
        <i class="bi bi-grid me-1"></i> <span class="d-none d-sm-inline">Cards</span>
      </button>
      <button type="button" class="btn btn-sm rounded-pill px-3 text-muted btn-light" id="btnViewTable" onclick="setClientsView('table')">
        <i class="bi bi-table me-1"></i> <span class="d-none d-sm-inline">Table</span>
      </button>
    </div>

    <!-- Mobile Filter Toggle Button -->
    <button class="btn btn-sm btn-outline-secondary d-md-none rounded-pill px-3" type="button" data-bs-toggle="collapse" data-bs-target="#clientsFiltersCollapse" aria-expanded="false">
      <i class="bi bi-funnel me-1"></i> Filters
      <?php if ($activeFilterCount > 0): ?>
        <span class="badge bg-primary rounded-pill ms-1"><?= $activeFilterCount ?></span>
      <?php endif; ?>
    </button>

    <?php if (isRole('admin','csm')): ?>
    <a href="<?= BASE_URL ?>/modules/clients/save.php" class="btn btn-sm btn-primary rounded-pill px-3 fw-semibold shadow-sm">
      <i class="bi bi-plus-circle me-1"></i><span class="d-none d-sm-inline">Add </span>Client
    </a>
    <?php endif; ?>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Status Filter Pills (Horizontally Scrollable) -->
<div class="expense-nav-scroll mb-3">
  <div class="d-flex align-items-center gap-2 flex-nowrap pb-1">
    <a href="?<?= http_build_query(array_merge($_GET, ['status' => ''])) ?>" class="badge <?= empty($filterStatus) ? 'bg-primary' : 'bg-light text-dark border' ?> text-decoration-none px-3 py-2 rounded-pill fw-semibold" style="font-size:0.83rem;">
      All <span class="opacity-75 ms-1">(<?= $totalClients ?>)</span>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'active'])) ?>" class="badge <?= $filterStatus === 'active' ? 'bg-success text-white' : 'bg-light text-success border border-success-subtle' ?> text-decoration-none px-3 py-2 rounded-pill fw-semibold" style="font-size:0.83rem;">
      <i class="bi bi-check-circle me-1"></i> Active <span class="opacity-75 ms-1">(<?= $counts['active'] ?? 0 ?>)</span>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'inactive'])) ?>" class="badge <?= $filterStatus === 'inactive' ? 'bg-secondary text-white' : 'bg-light text-secondary border' ?> text-decoration-none px-3 py-2 rounded-pill fw-semibold" style="font-size:0.83rem;">
      <i class="bi bi-pause-circle me-1"></i> Inactive <span class="opacity-75 ms-1">(<?= $counts['inactive'] ?? 0 ?>)</span>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['status' => 'suspended'])) ?>" class="badge <?= $filterStatus === 'suspended' ? 'bg-danger text-white' : 'bg-light text-danger border border-danger-subtle' ?> text-decoration-none px-3 py-2 rounded-pill fw-semibold" style="font-size:0.83rem;">
      <i class="bi bi-x-circle me-1"></i> Suspended <span class="opacity-75 ms-1">(<?= $counts['suspended'] ?? 0 ?>)</span>
    </a>
  </div>
</div>

<!-- Collapsible Filter Drawer -->
<div class="collapse d-md-block mb-3" id="clientsFiltersCollapse">
  <div class="card border-0 shadow-sm rounded-4">
    <div class="card-body py-3 px-3">
      <form method="GET" class="row g-2 align-items-center">
        <?php if ($filterStatus): ?>
          <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
        <?php endif; ?>

        <div class="col-12 col-md-5">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control form-control-sm border-start-0" placeholder="Search name, phone, company, code..." value="<?= htmlspecialchars($filterSearch) ?>">
          </div>
        </div>

        <div class="col-12 col-md-4">
          <?php if ($isInvestor): ?>
            <input type="hidden" name="project_id" value="<?= $investorProjectId ?>">
            <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($projects[array_search($investorProjectId, array_column($projects, 'id'))]['name'] ?? 'My Project') ?>" disabled>
          <?php else: ?>
          <select name="project_id" class="form-select form-select-sm">
            <option value="">All Projects</option>
            <?php foreach ($projects as $pr): ?>
            <option value="<?= $pr['id'] ?>" <?= $filterProject == $pr['id'] ? 'selected' : '' ?>><?= htmlspecialchars($pr['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>

        <div class="col-12 col-md-3 d-flex gap-2">
          <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-filter me-1"></i>Filter</button>
          <?php if ($filterSearch || (!$isInvestor && $filterProject) || $filterStatus): ?>
          <a href="?clear=1" class="btn btn-sm btn-outline-secondary" title="Clear all filters"><i class="bi bi-x-circle me-1"></i>Clear</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Mobile Cards View Container -->
<div id="clientsCardsContainer" class="d-none">
  <?php if (empty($clients)): ?>
    <div class="card border-0 shadow-sm rounded-4 text-center py-5">
      <div class="card-body">
        <i class="bi bi-people text-muted opacity-50" style="font-size: 3rem;"></i>
        <h5 class="fw-bold mt-3 mb-1">No Clients Found</h5>
        <p class="text-muted small mb-3">No client records match your current filter criteria.</p>
        <?php if ($filterSearch || $filterProject || $filterStatus): ?>
          <a href="?clear=1" class="btn btn-sm btn-outline-secondary rounded-pill px-3">Clear Filters</a>
        <?php elseif (isRole('admin','csm')): ?>
          <a href="<?= BASE_URL ?>/modules/clients/save.php" class="btn btn-sm btn-primary rounded-pill px-3">Add First Client</a>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <?php foreach ($clients as $c): 
      $statusClass = $c['status'] === 'active' ? 'is-active' : ($c['status'] === 'suspended' ? 'is-suspended' : 'is-inactive');
      
      $renewalBadge = '';
      if (!empty($c['renewal_end'])) {
        $daysLeft = (int)floor((strtotime($c['renewal_end']) - time()) / 86400);
        if ($daysLeft < 0) {
          $renewalBadge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-exclamation-triangle-fill me-1"></i>Expired</span>';
        } elseif ($daysLeft <= 30) {
          $renewalBadge = "<span class='badge bg-warning-subtle text-warning-emphasis border border-warning-subtle'><i class='bi bi-clock-history me-1'></i>{$daysLeft}d left</span>";
        } else {
          $renewalBadge = "<span class='badge bg-success-subtle text-success border border-success-subtle'><i class='bi bi-calendar-check me-1'></i>" . date('d M Y', strtotime($c['renewal_end'])) . "</span>";
        }
      } else {
        $renewalBadge = '<span class="badge bg-light text-muted border">No Active Renewal</span>';
      }

      $cleanPhone = !empty($c['phone']) ? preg_replace('/\D/', '', $c['phone']) : '';
    ?>
    <div class="client-mobile-card <?= $statusClass ?>">
      <!-- Header row: Project, Client Code & Status -->
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="d-flex align-items-center gap-1 flex-wrap">
          <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="font-size:0.75rem;">
            <?= htmlspecialchars($c['client_code'] ?: 'CLT-' . str_pad($c['id'], 4, '0', STR_PAD_LEFT)) ?>
          </span>
          <?php if (!empty($c['project_name'])): ?>
            <span class="badge bg-light text-dark border" style="font-size:0.75rem;">
              <i class="bi bi-folder2 text-primary me-1"></i><?= htmlspecialchars($c['project_name']) ?>
            </span>
          <?php endif; ?>
        </div>
        <div>
          <?= statusBadge($c['status']) ?>
        </div>
      </div>

      <!-- Client Name & Company -->
      <div class="mb-2">
        <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $c['id'] ?>" class="text-dark text-decoration-none d-block">
          <h6 class="fw-bold mb-0 text-truncate text-primary" style="font-size:1.05rem;">
            <?= htmlspecialchars($c['name']) ?>
          </h6>
        </a>
        <?php if (!empty($c['company'])): ?>
          <div class="text-muted small mt-1">
            <i class="bi bi-building me-1"></i><span class="fw-medium text-secondary"><?= htmlspecialchars($c['company']) ?></span>
            <?php if (!empty($c['designation'])): ?>
              <span class="text-muted"> &bull; <?= htmlspecialchars($c['designation']) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Plan & Renewal Details Banner -->
      <div class="bg-light rounded-3 p-2 mb-3 border">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
          <div class="small">
            <span class="text-muted">Plan:</span>
            <strong class="text-dark ms-1"><?= htmlspecialchars($c['plan_name'] ?: 'None') ?></strong>
            <?php if (!empty($c['region_name'])): ?>
              <span class="text-muted ms-2">&bull; <?= htmlspecialchars($c['region_name']) ?></span>
            <?php endif; ?>
          </div>
          <div>
            <?= $renewalBadge ?>
          </div>
        </div>
      </div>

      <!-- Direct Contact Row (Tap to Call, WhatsApp, Email) -->
      <?php if (!empty($c['phone']) || !empty($c['email'])): ?>
      <div class="d-flex align-items-center justify-content-between gap-2 border-top pt-2 mb-3">
        <div class="small text-truncate me-2">
          <?php if (!empty($c['phone'])): ?>
            <span class="text-dark fw-medium"><i class="bi bi-telephone text-muted me-1"></i><?= htmlspecialchars($c['phone']) ?></span>
          <?php elseif (!empty($c['email'])): ?>
            <span class="text-dark fw-medium text-truncate"><i class="bi bi-envelope text-muted me-1"></i><?= htmlspecialchars($c['email']) ?></span>
          <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-1 flex-shrink-0">
          <?php if (!empty($c['phone'])): ?>
            <a href="tel:<?= htmlspecialchars($c['phone']) ?>" class="btn btn-sm btn-outline-primary rounded-circle" style="width:32px;height:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;" title="Call">
              <i class="bi bi-telephone-fill" style="font-size:0.8rem;"></i>
            </a>
            <?php if ($cleanPhone): ?>
            <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success rounded-circle" style="width:32px;height:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;" title="WhatsApp">
              <i class="bi bi-whatsapp" style="font-size:0.85rem;"></i>
            </a>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!empty($c['email'])): ?>
            <a href="mailto:<?= htmlspecialchars($c['email']) ?>" class="btn btn-sm btn-outline-secondary rounded-circle" style="width:32px;height:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;" title="Email">
              <i class="bi bi-envelope-fill" style="font-size:0.8rem;"></i>
            </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Actions Toolbar -->
      <div class="d-flex align-items-center justify-content-between gap-2 pt-2 border-top">
        <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-primary rounded-pill px-3 fw-semibold flex-grow-1">
          <i class="bi bi-eye me-1"></i> View Profile
        </a>

        <?php if (!$isInvestor): ?>
        <a href="<?= BASE_URL ?>/modules/clients/save.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-2" title="Edit Client">
          <i class="bi bi-pencil"></i>
        </a>
        <?php if (isRole('admin')): ?>
        <a href="<?= BASE_URL ?>/modules/clients/settings.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-2" title="Client Settings & Instances">
          <i class="bi bi-gear"></i>
        </a>
        <?php endif; ?>
        <?php if (hasAccess('invoices')): ?>
        <a href="<?= BASE_URL ?>/modules/invoices/index.php?client_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-success rounded-pill px-2" title="Client Invoices">
          <i class="bi bi-receipt"></i>
        </a>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Desktop Table View Container -->
<div id="clientsTableContainer" class="card border-0 shadow-sm rounded-4">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable">
        <thead class="table-light">
          <tr><th>Code</th><th>Client</th><th>Contact</th><th>Project</th><th>Plan</th><th>Region</th><th>Renewal</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($clients as $c): ?>
          <?php
          $renewalBadge = '';
          if ($c['renewal_end']) {
            $daysLeft = (int)floor((strtotime($c['renewal_end']) - time()) / 86400);
            if ($daysLeft < 0) $renewalBadge = '<span class="badge bg-danger">Expired</span>';
            elseif ($daysLeft <= 30) $renewalBadge = "<span class='badge bg-warning text-dark'>{$daysLeft}d left</span>";
            else $renewalBadge = "<span class='badge bg-success'>".date('d M Y',strtotime($c['renewal_end']))."</span>";
          } else {
            $renewalBadge = '<span class="text-muted small">-</span>';
          }
          ?>
          <tr>
            <td><small class="text-muted fw-semibold"><?= htmlspecialchars($c['client_code'] ?? '') ?></small></td>
            <td>
              <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $c['id'] ?>" class="fw-semibold text-decoration-none">
                <?= htmlspecialchars($c['name']) ?>
              </a>
              <?php if ($c['company']): ?><div class="text-muted small"><i class="bi bi-building"></i> <?= htmlspecialchars($c['company']) ?></div><?php endif; ?>
            </td>
            <td>
              <div><?= htmlspecialchars($c['phone'] ?? '') ?></div>
              <?php if ($c['email']): ?><small class="text-muted"><?= htmlspecialchars($c['email']) ?></small><?php endif; ?>
            </td>
            <td><span class="badge bg-primary"><?= htmlspecialchars($c['project_name'] ?? '') ?></span></td>
            <td><small><?= htmlspecialchars($c['plan_name'] ?? '-') ?></small></td>
            <td class="small"><?= htmlspecialchars($c['region_name'] ?? '-') ?></td>
            <td><?= $renewalBadge ?></td>
            <td><?= statusBadge($c['status']) ?></td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $c['id'] ?>" class="btn btn-outline-info" title="View"><i class="bi bi-eye"></i></a>
                <?php if (!$isInvestor): ?>
                <a href="<?= BASE_URL ?>/modules/clients/save.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                <?php if (isRole('admin')): ?>
                <a href="<?= BASE_URL ?>/modules/clients/settings.php?id=<?= $c['id'] ?>" class="btn btn-outline-secondary" title="Settings"><i class="bi bi-gear"></i></a>
                <?php endif; ?>
                <?php if (hasAccess('invoices')): ?>
                <a href="<?= BASE_URL ?>/modules/invoices/index.php?client_id=<?= $c['id'] ?>" class="btn btn-outline-success" title="Invoices"><i class="bi bi-receipt"></i></a>
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

<?php if (isRole('admin','csm')): ?>
<!-- Mobile Floating Action Button (FAB) -->
<a href="<?= BASE_URL ?>/modules/clients/save.php" class="btn btn-primary mobile-fab-btn d-md-none" title="Add Client" aria-label="Add Client">
  <i class="bi bi-plus-lg fs-4"></i>
</a>
<?php endif; ?>

<script>
function setClientsView(mode) {
  const cardsContainer = document.getElementById('clientsCardsContainer');
  const tableContainer = document.getElementById('clientsTableContainer');
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
    localStorage.setItem('trias_clients_view_mode', mode);
  } catch(e) {}
}

(function initClientsView() {
  let saved = null;
  try {
    saved = localStorage.getItem('trias_clients_view_mode');
  } catch(e) {}
  if (saved) {
    setClientsView(saved);
  } else {
    setClientsView(window.innerWidth < 992 ? 'cards' : 'table');
  }
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

