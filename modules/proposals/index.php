<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('proposals');

$db = getDB();

$filterStatus  = $_GET['status'] ?? '';
$filterProject = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$filterSearch  = trim($_GET['q'] ?? '');

$sql = "SELECT p.*, pr.name as project_name,
               COALESCE(c.name, l.name) as contact_name,
               u.name as created_by_name
        FROM proposals p
        LEFT JOIN projects pr ON pr.id = p.project_id
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN leads l ON l.id = p.lead_id
        LEFT JOIN users u ON u.id = p.created_by
        WHERE 1=1";
$params = [];
if ($filterStatus)  { $sql .= " AND p.status=?";     $params[] = $filterStatus; }
if ($filterProject) { $sql .= " AND p.project_id=?"; $params[] = $filterProject; }
if ($filterSearch)  { $sql .= " AND (p.title LIKE ? OR p.proposal_no LIKE ?)";
                      $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; }
$sql .= " ORDER BY p.created_at DESC";

$stmt = $db->prepare($sql); $stmt->execute($params);
$proposals = $stmt->fetchAll();

$projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
$counts   = $db->query("SELECT status, COUNT(*) cnt FROM proposals GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Proposals';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
  <div><h4 class="mb-0">Proposals</h4><p class="text-muted small mb-0">Manage client proposals</p></div>
  <a href="<?= BASE_URL ?>/modules/proposals/save.php" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>New Proposal
  </a>
</div>

<?php displayFlash(); ?>

<!-- Status Pills -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php
  $statusColors = ['draft'=>'secondary','sent'=>'info','accepted'=>'success','rejected'=>'danger','expired'=>'warning'];
  foreach ($statusColors as $st => $cls): ?>
  <a href="?status=<?= $st ?><?= $filterProject ? "&project_id=$filterProject" : '' ?>"
     class="badge bg-<?= $filterStatus===$st ? $cls : 'light text-dark' ?> text-decoration-none p-2 fs-6">
    <?= ucfirst($st) ?> <span class="ms-1"><?= $counts[$st] ?? 0 ?></span>
  </a>
  <?php endforeach; ?>
  <a href="?" class="badge <?= !$filterStatus ? 'bg-primary' : 'bg-light text-dark' ?> text-decoration-none p-2 fs-6">
    All <span class="ms-1"><?= array_sum($counts) ?></span>
  </a>
  <?php if ($filterStatus || $filterProject || $filterSearch): ?>
  <a href="?" class="badge bg-light text-dark text-decoration-none p-2 fs-6"><i class="bi bi-x-circle me-1"></i>Clear</a>
  <?php endif; ?>
</div>

<!-- Filter Bar -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search title or proposal no..." value="<?= htmlspecialchars($filterSearch) ?>">
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
          <tr><th>No.</th><th>Title</th><th>Contact</th><th>Project</th><th>Valid Until</th><th>Total</th><th>Status</th><th>By</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($proposals as $p): ?>
          <?php
          $statusBadgeMap = ['draft'=>'secondary','sent'=>'info','accepted'=>'success','rejected'=>'danger','expired'=>'warning'];
          $sc = $statusBadgeMap[$p['status']] ?? 'secondary';
          $isExpired = $p['valid_until'] && strtotime($p['valid_until']) < time() && $p['status'] === 'sent';
          ?>
          <tr>
            <td><small class="text-muted fw-semibold"><?= htmlspecialchars($p['proposal_no'] ?? '') ?></small></td>
            <td>
              <a href="<?= BASE_URL ?>/modules/proposals/save.php?id=<?= $p['id'] ?>" class="fw-semibold text-decoration-none">
                <?= htmlspecialchars($p['title']) ?>
              </a>
            </td>
            <td><small><?= htmlspecialchars($p['contact_name'] ?? '-') ?></small></td>
            <td><span class="badge bg-primary"><?= htmlspecialchars($p['project_name'] ?? '') ?></span></td>
            <td>
              <?php if ($p['valid_until']): ?>
                <small class="<?= $isExpired ? 'text-danger' : 'text-muted' ?>"><?= date('d M Y', strtotime($p['valid_until'])) ?></small>
              <?php else: ?><small class="text-muted">-</small><?php endif; ?>
            </td>
            <td class="fw-semibold">₹<?= number_format($p['total_amount'], 2) ?></td>
            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($p['status']) ?></span></td>
            <td><small class="text-muted"><?= htmlspecialchars($p['created_by_name'] ?? '-') ?></small></td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/modules/proposals/save.php?id=<?= $p['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                <?php if (isRole('admin')): ?>
                <form method="POST" action="<?= BASE_URL ?>/modules/proposals/delete.php" class="d-inline" onsubmit="return confirm('Delete this proposal?')">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                </form>
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
