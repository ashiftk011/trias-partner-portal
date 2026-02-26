<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('projects');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name']);
        $desc   = trim($_POST['description'] ?? '');
        $status = $_POST['status'];

        if ($id > 0) {
            $db->prepare("UPDATE projects SET name=?,description=?,status=? WHERE id=?")->execute([$name,$desc,$status,$id]);
            setFlash('success','Project updated.');
        } else {
            $db->prepare("INSERT INTO projects (name,description,status) VALUES (?,?,?)")->execute([$name,$desc,$status]);
            setFlash('success','Project created.');
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM projects WHERE id=?")->execute([$id]);
        setFlash('success','Project deleted.');
    }
    redirect(BASE_URL . '/modules/projects/index.php');
}

$projects = $db->query("SELECT p.*, (SELECT COUNT(*) FROM leads l WHERE l.project_id=p.id) as lead_count, (SELECT COUNT(*) FROM plans pl WHERE pl.project_id=p.id) as plan_count FROM projects p ORDER BY p.name")->fetchAll();

$pageTitle = 'Projects';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
  <div><h4 class="mb-0">Projects</h4><p class="text-muted small mb-0">Manage all service projects</p></div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal">
    <i class="bi bi-folder-plus me-1"></i> Add Project
  </button>
</div>

<?php displayFlash(); ?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card border-0 shadow-sm text-center py-3">
      <div class="fs-2 fw-bold text-primary"><?= count($projects) ?></div>
      <div class="text-muted small">Total Projects</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm text-center py-3">
      <div class="fs-2 fw-bold text-success"><?= count(array_filter($projects, fn($p)=>$p['status']==='active')) ?></div>
      <div class="text-muted small">Active Projects</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm text-center py-3">
      <div class="fs-2 fw-bold text-info"><?= array_sum(array_column($projects,'lead_count')) ?></div>
      <div class="text-muted small">Total Leads</div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable">
        <thead class="table-light">
          <tr><th>#</th><th>Project Name</th><th>Description</th><th>Plans</th><th>Leads</th><th>Status</th><th>Created</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $i => $p): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($p['name']) ?></td>
            <td class="text-muted small"><?= htmlspecialchars(substr($p['description']??'',0,60)) ?><?= strlen($p['description']??'')>60?'...':'' ?></td>
            <td><a href="<?= BASE_URL ?>/modules/plans/index.php?project_id=<?= $p['id'] ?>" class="badge bg-info text-decoration-none"><?= $p['plan_count'] ?> Plans</a></td>
            <td><a href="<?= BASE_URL ?>/modules/leads/index.php?project_id=<?= $p['id'] ?>" class="badge bg-primary text-decoration-none"><?= $p['lead_count'] ?> Leads</a></td>
            <td><?= statusBadge($p['status']) ?></td>
            <td class="text-muted small"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
            <td>
              <button class="btn btn-sm btn-outline-primary" onclick="editProject(<?= htmlspecialchars(json_encode($p)) ?>)"><i class="bi bi-pencil"></i></button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this project?')">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Project Modal -->
<div class="modal fade" id="projectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="projectId" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="projectModalTitle">Add Project</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Project Name *</label>
            <input type="text" name="name" id="pName" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" id="pDesc" class="form-control" rows="3"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="pStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Project</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editProject(p) {
  document.getElementById('projectId').value = p.id;
  document.getElementById('pName').value     = p.name;
  document.getElementById('pDesc').value     = p.description || '';
  document.getElementById('pStatus').value   = p.status;
  document.getElementById('projectModalTitle').textContent = 'Edit Project';
  new bootstrap.Modal(document.getElementById('projectModal')).show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
