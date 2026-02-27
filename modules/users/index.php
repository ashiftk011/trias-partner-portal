<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('users');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $db = getDB();

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name']);
        $email    = trim($_POST['email']);
        $role     = $_POST['role'];
        $phone    = trim($_POST['phone'] ?? '');
        $status   = $_POST['status'];
        $password = trim($_POST['password'] ?? '');
        $assignedProject = (int)($_POST['investor_project_id'] ?? 0);

        if ($id > 0) {
            if ($password) {
                $db->prepare("UPDATE users SET name=?,email=?,role=?,phone=?,status=?,password=? WHERE id=?")
                   ->execute([$name,$email,$role,$phone,$status,password_hash($password,PASSWORD_DEFAULT),$id]);
            } else {
                $db->prepare("UPDATE users SET name=?,email=?,role=?,phone=?,status=? WHERE id=?")
                   ->execute([$name,$email,$role,$phone,$status,$id]);
            }
            setFlash('success','User updated successfully.');
        } else {
            if (!$password) { setFlash('error','Password is required for new users.'); }
            else {
                $db->prepare("INSERT INTO users (name,email,role,phone,status,password) VALUES (?,?,?,?,?,?)")
                   ->execute([$name,$email,$role,$phone,$status,password_hash($password,PASSWORD_DEFAULT)]);
                $id = (int)$db->lastInsertId();
                setFlash('success','User created successfully.');
            }
        }
        // Upsert investor project assignment
        if ($role === 'investor' && $id > 0 && $assignedProject > 0) {
            $db->prepare("INSERT INTO investor_projects (user_id, project_id) VALUES (?,?) ON DUPLICATE KEY UPDATE project_id=?")
               ->execute([$id, $assignedProject, $assignedProject]);
        } elseif ($role !== 'investor' && $id > 0) {
            $db->prepare("DELETE FROM investor_projects WHERE user_id=?")->execute([$id]);
        }
    } elseif ($action === 'toggle') {
        $id  = (int)$_POST['id'];
        $new = $_POST['new_status'];
        $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new,$id]);
        setFlash('success','User status updated.');
    }
    redirect(BASE_URL . '/modules/users/index.php');
}

$db    = getDB();
$users = $db->query("SELECT u.*, ip.project_id as investor_project_id, p.name as investor_project_name
                     FROM users u
                     LEFT JOIN investor_projects ip ON ip.user_id = u.id
                     LEFT JOIN projects p ON p.id = ip.project_id
                     ORDER BY u.role, u.name")->fetchAll();
$editUser = null;
$projects = $db->query("SELECT id, name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch();
}

$pageTitle = 'User Management';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
  <div><h4 class="mb-0">User Management</h4><p class="text-muted small mb-0">Manage portal users and roles</p></div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal">
    <i class="bi bi-person-plus me-1"></i> Add User
  </button>
</div>

<?php displayFlash(); ?>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 datatable">
        <thead class="table-light">
          <tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Project</th><th>Status</th><th>Last Login</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $i => $u): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($u['name']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['phone'] ?? '-') ?></td>
            <td><?= roleLabel($u['role']) ?></td>
            <td>
              <?php if ($u['role'] === 'investor' && $u['investor_project_name']): ?>
                <span class="badge bg-primary"><?= htmlspecialchars($u['investor_project_name']) ?></span>
              <?php else: ?>
                <span class="text-muted small">-</span>
              <?php endif; ?>
            </td>
            <td><?= statusBadge($u['status']) ?></td>
            <td class="text-muted small"><?= $u['last_login'] ? date('d M Y H:i', strtotime($u['last_login'])) : 'Never' ?></td>
            <td>
              <button class="btn btn-sm btn-outline-primary" onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)">
                <i class="bi bi-pencil"></i>
              </button>
              <?php if ($u['id'] !== currentUser()['id']): ?>
              <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <input type="hidden" name="new_status" value="<?= $u['status']==='active'?'inactive':'active' ?>">
                <button type="submit" class="btn btn-sm <?= $u['status']==='active'?'btn-outline-warning':'btn-outline-success' ?>"
                        title="<?= $u['status']==='active'?'Deactivate':'Activate' ?>">
                  <i class="bi bi-<?= $u['status']==='active'?'person-dash':'person-check' ?>"></i>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="userId" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="modalTitle">Add New User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Full Name *</label>
            <input type="text" name="name" id="uName" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Email *</label>
            <input type="email" name="email" id="uEmail" class="form-control" required>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Phone</label>
              <input type="text" name="phone" id="uPhone" class="form-control">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Role *</label>
              <select name="role" id="uRole" class="form-select" required onchange="toggleInvestorProject(this.value)">
                <option value="telecall">Telecall</option>
                <option value="finance">Finance</option>
                <option value="admin">Admin</option>
                <option value="investor">Investor</option>
                <option value="csm">Client Success Manager</option>
              </select>
            </div>
          </div>
          <div class="mb-3" id="investorProjectField" style="display:none">
            <label class="form-label">Assigned Project <span class="text-danger">*</span></label>
            <select name="investor_project_id" id="uInvestorProject" class="form-select">
              <option value="">Select Project</option>
              <?php foreach ($projects as $pr): ?>
              <option value="<?= $pr['id'] ?>"><?= htmlspecialchars($pr['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Password <span id="pwdNote" class="text-muted small">(leave blank to keep current)</span></label>
            <input type="password" name="password" id="uPassword" class="form-control" placeholder="Min 6 characters">
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="uStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editUser(u) {
  document.getElementById('userId').value    = u.id;
  document.getElementById('uName').value     = u.name;
  document.getElementById('uEmail').value    = u.email;
  document.getElementById('uPhone').value    = u.phone || '';
  document.getElementById('uRole').value     = u.role;
  document.getElementById('uStatus').value   = u.status;
  document.getElementById('modalTitle').textContent = 'Edit User';
  document.getElementById('pwdNote').textContent = '(leave blank to keep current)';
  toggleInvestorProject(u.role);
  if (u.role === 'investor' && u.investor_project_id) {
    document.getElementById('uInvestorProject').value = u.investor_project_id;
  }
  new bootstrap.Modal(document.getElementById('userModal')).show();
}

function toggleInvestorProject(role) {
  const field = document.getElementById('investorProjectField');
  const sel   = document.getElementById('uInvestorProject');
  if (role === 'investor') {
    field.style.display = '';
    sel.required = true;
  } else {
    field.style.display = 'none';
    sel.required = false;
  }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
