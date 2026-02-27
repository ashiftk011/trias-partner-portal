<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('regions');

$db = getDB();

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if (!$name) { setFlash('error','Region name is required.'); redirect(BASE_URL.'/modules/regions/index.php'); }

        if ($id) {
            $db->prepare("UPDATE regions SET name=?, status=? WHERE id=?")->execute([$name, $status, $id]);
            setFlash('success', 'Region updated.');
        } else {
            // Check duplicate
            $exists = $db->prepare("SELECT id FROM regions WHERE name=?");
            $exists->execute([$name]);
            if ($exists->fetch()) { setFlash('error', "Region '$name' already exists."); redirect(BASE_URL.'/modules/regions/index.php'); }
            $db->prepare("INSERT INTO regions (name, status) VALUES (?,?)")->execute([$name, $status]);
            setFlash('success', "Region '$name' added.");
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Check if in use
        $inUse = $db->prepare("SELECT COUNT(*) FROM leads WHERE region_id=?");
        $inUse->execute([$id]);
        if ($inUse->fetchColumn() > 0) {
            setFlash('error', 'Cannot delete: region is assigned to leads.');
        } else {
            $inUseC = $db->prepare("SELECT COUNT(*) FROM clients WHERE region_id=?");
            $inUseC->execute([$id]);
            if ($inUseC->fetchColumn() > 0) {
                setFlash('error', 'Cannot delete: region is assigned to clients.');
            } else {
                $db->prepare("DELETE FROM regions WHERE id=?")->execute([$id]);
                setFlash('success', 'Region deleted.');
            }
        }

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE regions SET status = IF(status='active','inactive','active') WHERE id=?")->execute([$id]);
        setFlash('success','Region status updated.');
    }

    redirect(BASE_URL.'/modules/regions/index.php');
}

$regions = $db->query("SELECT r.*, 
    (SELECT COUNT(*) FROM leads WHERE region_id=r.id) as lead_count,
    (SELECT COUNT(*) FROM clients WHERE region_id=r.id) as client_count
    FROM regions r ORDER BY r.name")->fetchAll();

$pageTitle = 'Regions';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
  <div><h4 class="mb-0">Regions</h4><p class="text-muted small mb-0">Manage geographic regions for leads and clients</p></div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#regionModal">
    <i class="bi bi-plus-circle me-1"></i>Add Region
  </button>
</div>

<?php displayFlash(); ?>

<div class="row">
  <div class="col-xl-8">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-0">
        <table class="table table-hover mb-0 datatable">
          <thead class="table-light">
            <tr><th>Region Name</th><th>Leads</th><th>Clients</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($regions as $r): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
              <td><span class="badge bg-primary"><?= $r['lead_count'] ?></span></td>
              <td><span class="badge bg-success"><?= $r['client_count'] ?></span></td>
              <td>
                <?php if ($r['status'] === 'active'): ?>
                  <span class="badge bg-success">Active</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-outline-primary" 
                          onclick="editRegion(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['name'])) ?>', '<?= $r['status'] ?>')"
                          title="Edit">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Toggle status of this region?')">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-outline-secondary" title="Toggle Status"><i class="bi bi-toggle-on"></i></button>
                  </form>
                  <?php if ($r['lead_count'] == 0 && $r['client_count'] == 0): ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Delete this region?')">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
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

  <div class="col-xl-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-info-circle me-2 text-primary"></i>About Regions</div>
      <div class="card-body small text-muted">
        <p>Regions help you organize leads and clients by geography — e.g. <strong>North India</strong>, <strong>South Zone</strong>, <strong>Maharashtra</strong>.</p>
        <p>They can be used to filter leads and clients in their respective modules.</p>
        <p class="mb-0"><i class="bi bi-exclamation-triangle text-warning me-1"></i>Regions assigned to leads or clients cannot be deleted.</p>
      </div>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="regionModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="rId" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="regionModalTitle">Add Region</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Region Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="rName" class="form-control" required placeholder="e.g. North India">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="rStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="rSubmit">Add Region</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editRegion(id, name, status) {
  document.getElementById('rId').value     = id;
  document.getElementById('rName').value   = name;
  document.getElementById('rStatus').value = status;
  document.getElementById('regionModalTitle').textContent = 'Edit Region';
  document.getElementById('rSubmit').textContent          = 'Update Region';
  new bootstrap.Modal(document.getElementById('regionModal')).show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
