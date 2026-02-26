<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('clients');

$db = getDB();

$filterProject = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
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
foreach ($db->query("SELECT status, COUNT(*) as cnt FROM clients GROUP BY status") as $row) {
    $counts[$row['status']] = $row['cnt'];
}

$pageTitle = 'Clients';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
  <div><h4 class="mb-0">Clients</h4><p class="text-muted small mb-0">All registered clients</p></div>
  <?php if (currentUser()['role'] === 'admin'): ?>
  <a href="<?= BASE_URL ?>/modules/clients/save.php" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>Add Client
  </a>
  <?php endif; ?>
</div>

<?php displayFlash(); ?>

<!-- Status Pills -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="?" class="badge bg-<?= !$filterStatus?'primary':'secondary' ?> text-decoration-none p-2 fs-6">All <span><?= array_sum($counts) ?></span></a>
  <a href="?status=active" class="badge bg-<?= $filterStatus==='active'?'success':'secondary' ?> text-decoration-none p-2 fs-6">Active <span><?= $counts['active']??0 ?></span></a>
  <a href="?status=inactive" class="badge bg-<?= $filterStatus==='inactive'?'secondary':'light text-dark' ?> text-decoration-none p-2 fs-6">Inactive <span><?= $counts['inactive']??0 ?></span></a>
  <a href="?status=suspended" class="badge bg-<?= $filterStatus==='suspended'?'danger':'secondary' ?> text-decoration-none p-2 fs-6">Suspended <span><?= $counts['suspended']??0 ?></span></a>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by name, phone, company, code..." value="<?= htmlspecialchars($filterSearch) ?>">
      </div>
      <div class="col-md-3">
        <select name="project_id" class="form-select form-select-sm">
          <option value="">All Projects</option>
          <?php foreach ($projects as $pr): ?>
          <option value="<?= $pr['id'] ?>" <?= $filterProject==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Search</button>
        <?php if ($filterSearch || $filterProject || $filterStatus): ?>
        <a href="?" class="btn btn-sm btn-outline-secondary ms-1">Clear</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
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
                <a href="<?= BASE_URL ?>/modules/clients/save.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                <a href="<?= BASE_URL ?>/modules/clients/settings.php?id=<?= $c['id'] ?>" class="btn btn-outline-secondary" title="Settings"><i class="bi bi-gear"></i></a>
                <?php if (hasAccess('invoices')): ?>
                <a href="<?= BASE_URL ?>/modules/invoices/index.php?client_id=<?= $c['id'] ?>" class="btn btn-outline-success" title="Invoices"><i class="bi bi-receipt"></i></a>
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
