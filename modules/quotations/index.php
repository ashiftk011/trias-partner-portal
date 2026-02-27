<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('quotations');

$db = getDB();

$filterStatus  = $_GET['status'] ?? '';
$filterProject = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$filterSearch  = trim($_GET['q'] ?? '');

$sql = "SELECT q.*, pr.name as project_name,
               COALESCE(c.name, l.name) as contact_name,
               u.name as created_by_name
        FROM quotations q
        LEFT JOIN projects pr ON pr.id = q.project_id
        LEFT JOIN clients c ON c.id = q.client_id
        LEFT JOIN leads l ON l.id = q.lead_id
        LEFT JOIN users u ON u.id = q.created_by
        WHERE 1=1";
$params = [];
if ($filterStatus)  { $sql .= " AND q.status=?";     $params[] = $filterStatus; }
if ($filterProject) { $sql .= " AND q.project_id=?"; $params[] = $filterProject; }
if ($filterSearch)  { $sql .= " AND (q.title LIKE ? OR q.quotation_no LIKE ?)";
                      $params[] = "%$filterSearch%"; $params[] = "%$filterSearch%"; }
$sql .= " ORDER BY q.created_at DESC";

$stmt = $db->prepare($sql); $stmt->execute($params);
$quotations = $stmt->fetchAll();

$projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
$counts   = $db->query("SELECT status, COUNT(*) cnt FROM quotations GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Quotations';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
  <div><h4 class="mb-0">Quotations</h4><p class="text-muted small mb-0">Manage formal price quotations</p></div>
  <a href="<?= BASE_URL ?>/modules/quotations/save.php" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>New Quotation
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
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search title or quotation no..." value="<?= htmlspecialchars($filterSearch) ?>">
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
          <tr><th>No.</th><th>Title</th><th>Contact</th><th>Project</th><th>Valid Until</th><th>Total (incl. Tax)</th><th>Status</th><th>By</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($quotations as $q): ?>
          <?php
          $sc = ['draft'=>'secondary','sent'=>'info','accepted'=>'success','rejected'=>'danger','expired'=>'warning'][$q['status']] ?? 'secondary';
          $isExpired = $q['valid_until'] && strtotime($q['valid_until']) < time() && $q['status'] === 'sent';
          ?>
          <tr>
            <td><small class="text-muted fw-semibold"><?= htmlspecialchars($q['quotation_no'] ?? '') ?></small></td>
            <td>
              <a href="<?= BASE_URL ?>/modules/quotations/save.php?id=<?= $q['id'] ?>" class="fw-semibold text-decoration-none">
                <?= htmlspecialchars($q['title']) ?>
              </a>
            </td>
            <td><small><?= htmlspecialchars($q['contact_name'] ?? '-') ?></small></td>
            <td><span class="badge bg-primary"><?= htmlspecialchars($q['project_name'] ?? '') ?></span></td>
            <td><small class="<?= $isExpired ? 'text-danger' : 'text-muted' ?>"><?= $q['valid_until'] ? date('d M Y', strtotime($q['valid_until'])) : '-' ?></small></td>
            <td class="fw-semibold">₹<?= number_format($q['total_amount'], 2) ?><br><small class="text-muted"><?= number_format($q['tax_percent'],0) ?>% GST</small></td>
            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($q['status']) ?></span></td>
            <td><small class="text-muted"><?= htmlspecialchars($q['created_by_name'] ?? '-') ?></small></td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/modules/quotations/save.php?id=<?= $q['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                <?php if (isRole('admin')): ?>
                <form method="POST" action="<?= BASE_URL ?>/modules/quotations/delete.php" class="d-inline" onsubmit="return confirm('Delete this quotation?')">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <input type="hidden" name="id" value="<?= $q['id'] ?>">
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
