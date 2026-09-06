<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('clients');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/clients/index.php');

$stmt = $db->prepare("SELECT c.*,p.name as project_name,pl.name as plan_name,pl.price as plan_price,pl.duration_months,r.name as region_name FROM clients c LEFT JOIN projects p ON p.id=c.project_id LEFT JOIN plans pl ON pl.id=c.plan_id LEFT JOIN regions r ON r.id=c.region_id WHERE c.id=?");
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) redirect(BASE_URL . '/modules/clients/index.php');

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_status') {
        verifyCsrf();
        $db->prepare("UPDATE clients SET status=? WHERE id=?")->execute([$_POST['status'],$id]);
        setFlash('success','Client status updated.');
        redirect(BASE_URL . '/modules/clients/view.php?id=' . $id);
    }
    
    // Handle add query/suggestion
    if ($action === 'add_query') {
        verifyCsrf();
        $type        = $_POST['type'] ?? 'query';
        $status      = $_POST['status'] ?? 'pending';
        $taskLink    = trim($_POST['task_link'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $currentUser = currentUser();

        if (!$description) {
            setFlash('error', 'Description/Details is required.');
        } else {
            $db->prepare("INSERT INTO client_queries (client_id, type, description, status, task_link, created_by) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$id, $type, $description, $status, $taskLink ?: null, $currentUser['id']]);
            setFlash('success', 'Query/Suggestion recorded.');
        }
        redirect(BASE_URL . '/modules/clients/view.php?id=' . $id);
    }

    // Handle update query status and link
    if ($action === 'update_query') {
        verifyCsrf();
        $queryId     = (int)($_POST['query_id'] ?? 0);
        $status      = $_POST['status'] ?? 'pending';
        $taskLink    = trim($_POST['task_link'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (!$queryId || !$description) {
            setFlash('error', 'Query ID and Description are required.');
        } else {
            $db->prepare("UPDATE client_queries SET status = ?, task_link = ?, description = ? WHERE id = ? AND client_id = ?")
               ->execute([$status, $taskLink ?: null, $description, $queryId, $id]);
            setFlash('success', 'Query/Suggestion updated.');
        }
        redirect(BASE_URL . '/modules/clients/view.php?id=' . $id);
    }
}

$renewals = $db->prepare("SELECT rn.*,pl.name as plan_name FROM renewals rn LEFT JOIN plans pl ON pl.id=rn.plan_id WHERE rn.client_id=? ORDER BY rn.start_date DESC");
$renewals->execute([$id]);
$renewals = $renewals->fetchAll();

$invoices = [];
if (hasAccess('invoices')) {
    $invStmt = $db->prepare("SELECT * FROM invoices WHERE client_id=? ORDER BY invoice_date DESC LIMIT 5");
    $invStmt->execute([$id]);
    $invoices = $invStmt->fetchAll();
}

$queries = $db->prepare("SELECT cq.*, u.name as by_name FROM client_queries cq LEFT JOIN users u ON u.id = cq.created_by WHERE cq.client_id = ? ORDER BY cq.created_at DESC");
$queries->execute([$id]);
$queries = $queries->fetchAll();

$pageTitle = 'Client: ' . $client['name'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/clients/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div class="flex-grow-1">
    <h4 class="mb-0"><?= htmlspecialchars($client['name']) ?> <small class="text-muted fs-6"><?= htmlspecialchars($client['client_code'] ?? '') ?></small></h4>
    <p class="text-muted small mb-0"><?= htmlspecialchars($client['company'] ?? '') ?></p>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?= statusBadge($client['status']) ?>
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Actions</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/clients/settings.php?id=<?= $id ?>"><i class="bi bi-gear me-2"></i>Settings</a></li>
        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/renewals/index.php?client_id=<?= $id ?>"><i class="bi bi-arrow-repeat me-2"></i>Renewals</a></li>
        <?php if (hasAccess('invoices')): ?>
        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/invoices/index.php?client_id=<?= $id ?>"><i class="bi bi-receipt me-2"></i>Invoices</a></li>
        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/invoices/save.php?client_id=<?= $id ?>"><i class="bi bi-plus me-2"></i>New Invoice</a></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li>
          <form method="POST" class="px-3">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_status">
            <div class="mb-2"><label class="form-label small mb-1">Change Status</label>
              <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="active" <?= $client['status']==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $client['status']==='inactive'?'selected':'' ?>>Inactive</option>
                <option value="suspended" <?= $client['status']==='suspended'?'selected':'' ?>>Suspended</option>
              </select>
            </div>
          </form>
        </li>
      </ul>
    </div>
  </div>
</div>

<?php displayFlash(); ?>

<div class="row g-4">
  <!-- Client Details -->
  <div class="col-xl-4">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-person me-2 text-primary"></i>Client Details</div>
      <div class="card-body">
        <?php
        $fields = [
          'Phone'       => $client['phone'] ?: '-',
          'Alt Phone'   => $client['alt_phone'] ?: '-',
          'Email'       => $client['email'] ?: '-',
          'Designation' => $client['designation'] ?: '-',
          'Project'     => $client['project_name'],
          'Plan'        => $client['plan_name'] ? $client['plan_name'] . ' (₹' . number_format($client['plan_price'],2) . ')' : '-',
          'Region'      => $client['region_name'] ?: '-',
          'Joined Date' => $client['joined_date'] ? date('d M Y', strtotime($client['joined_date'])) : '-',
        ];
        foreach ($fields as $label => $value): ?>
        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small"><?= $label ?></span>
          <span class="fw-semibold small text-end"><?= htmlspecialchars($value) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-geo-alt me-2 text-primary"></i>Address & Tax</div>
      <div class="card-body">
        <?php
        $addr = array_filter([$client['address'],$client['city'],$client['state'],$client['pincode']]);
        if ($addr): ?>
        <p class="small mb-2"><?= nl2br(htmlspecialchars(implode(', ', $addr))) ?></p>
        <?php else: ?><p class="text-muted small">No address on record</p><?php endif; ?>
        <?php if ($client['gst_no']): ?><div class="small"><strong>GST:</strong> <?= htmlspecialchars($client['gst_no']) ?></div><?php endif; ?>
        <?php if ($client['pan_no']): ?><div class="small"><strong>PAN:</strong> <?= htmlspecialchars($client['pan_no']) ?></div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Renewals + Invoices -->
  <div class="col-xl-8">
    <!-- Renewals -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <span class="fw-semibold"><i class="bi bi-arrow-repeat me-2 text-primary"></i>Renewals</span>
        <?php if (hasAccess('renewals')): ?>
        <a href="<?= BASE_URL ?>/modules/renewals/save.php?client_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-plus me-1"></i>Add Renewal
        </a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if ($renewals): ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>Plan</th><th>Start</th><th>End</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($renewals as $rn): ?>
              <?php $daysLeft = (int)floor((strtotime($rn['end_date']) - time()) / 86400); ?>
              <tr>
                <td class="fw-semibold small"><?= htmlspecialchars($rn['plan_name']) ?></td>
                <td class="small"><?= date('d M Y', strtotime($rn['start_date'])) ?></td>
                <td class="small">
                  <?= date('d M Y', strtotime($rn['end_date'])) ?>
                  <?php if ($rn['status']==='active' && $daysLeft<=30): ?>
                  <br><span class="badge bg-warning text-dark"><?= $daysLeft>=0?$daysLeft.'d left':'Expired' ?></span>
                  <?php endif; ?>
                </td>
                <td class="fw-semibold">₹<?= number_format($rn['amount'],2) ?></td>
                <td><?= statusBadge($rn['status']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4"><i class="bi bi-arrow-repeat fs-3 d-block mb-2"></i>No renewals found</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent Invoices -->
    <?php if (hasAccess('invoices')): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <span class="fw-semibold"><i class="bi bi-receipt me-2 text-primary"></i>Recent Invoices</span>
        <div class="d-flex gap-2">
          <a href="<?= BASE_URL ?>/modules/invoices/save.php?client_id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus me-1"></i>New Invoice</a>
          <a href="<?= BASE_URL ?>/modules/invoices/index.php?client_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">View All</a>
        </div>
      </div>
      <div class="card-body p-0">
        <?php if ($invoices): ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>Invoice No</th><th>Date</th><th>Amount</th><th>Paid</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach ($invoices as $inv): ?>
              <tr>
                <td class="fw-semibold small"><?= htmlspecialchars($inv['invoice_no']) ?></td>
                <td class="small"><?= date('d M Y', strtotime($inv['invoice_date'])) ?></td>
                <td class="fw-semibold">₹<?= number_format($inv['total_amount'],2) ?></td>
                <td class="text-success">₹<?= number_format($inv['paid_amount'],2) ?></td>
                <td><?= statusBadge($inv['status']) ?></td>
                <td><a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4"><i class="bi bi-receipt fs-3 d-block mb-2"></i>No invoices yet</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Feed Query & Suggestion Form -->
    <div class="card border-0 shadow-sm mt-4 mb-4">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-chat-right-dots-fill me-2 text-primary"></i>Feed Query / Suggestion</div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="add_query">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Type</label>
              <select name="type" class="form-select">
                <option value="query">Query</option>
                <option value="suggestion">Suggestion</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Status</label>
              <select name="status" class="form-select">
                <option value="pending">Pending</option>
                <option value="blocked">Blocked</option>
                <option value="resolved">Resolved</option>
                <option value="closed">Closed</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">PM Task Link</label>
              <input type="url" name="task_link" class="form-control" placeholder="e.g. https://jira.com/browse/TASK-123">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Description / Details *</label>
              <textarea name="description" class="form-control" rows="3" required placeholder="Describe the client query or suggestion details..."></textarea>
            </div>
          </div>
          <div class="mt-3 text-end">
            <button type="submit" class="btn btn-primary btn-sm px-3"><i class="bi bi-send me-1"></i>Submit</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Queries & Suggestions History -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-chat-square-text-fill me-2 text-primary"></i>Queries & Suggestions</span>
        <span class="badge bg-secondary ms-2"><?= count($queries) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($queries): ?>
        <div class="timeline p-3">
          <?php foreach ($queries as $q): ?>
          <div class="timeline-item d-flex gap-3 mb-3">
            <div class="timeline-icon bg-light border rounded-circle d-flex align-items-center justify-content-center text-primary flex-shrink-0" style="width:36px;height:36px">
              <?php if ($q['type'] === 'query'): ?>
              <i class="bi bi-question-circle-fill"></i>
              <?php else: ?>
              <i class="bi bi-lightbulb-fill text-warning"></i>
              <?php endif; ?>
            </div>
            <div class="flex-grow-1 border rounded p-3 bg-light">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                  <span class="fw-semibold small"><?= htmlspecialchars($q['by_name']) ?></span>
                  <span class="mx-1 text-muted">•</span>
                  <small class="text-muted"><?= date('d M Y H:i', strtotime($q['created_at'])) ?></small>
                </div>
                <div class="d-flex gap-2 align-items-center">
                  <!-- Edit/Update Button -->
                  <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" onclick='openEditQueryModal(<?= json_encode($q, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit/Update Query">
                    <i class="bi bi-pencil small"></i> Edit
                  </button>
                </div>
              </div>
              
              <p class="mb-2 small text-dark text-break" style="white-space: pre-line;"><?= htmlspecialchars($q['description']) ?></p>
              
              <div class="d-flex gap-2 flex-wrap align-items-center">
                <span class="badge bg-secondary small"><?= ucfirst($q['type']) ?></span>
                
                <!-- Status Badge -->
                <?php
                $statusColors = [
                    'pending'  => 'warning text-dark',
                    'blocked'  => 'danger',
                    'resolved' => 'success',
                    'closed'   => 'secondary'
                ];
                $color = $statusColors[$q['status']] ?? 'secondary';
                ?>
                <span class="badge bg-<?= $color ?> small"><?= ucfirst($q['status']) ?></span>
                
                <!-- PM Link if exists -->
                <?php if ($q['task_link']): ?>
                <a href="<?= htmlspecialchars($q['task_link']) ?>" target="_blank" class="badge bg-info text-decoration-none small d-inline-flex align-items-center gap-1">
                  <i class="bi bi-link-45deg"></i> PM Task <i class="bi bi-box-arrow-up-right" style="font-size: 0.7rem;"></i>
                </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4"><i class="bi bi-chat-left-text fs-3 d-block mb-2"></i>No queries or suggestions logged yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Edit Query Modal -->
<div class="modal fade" id="editQueryModal" tabindex="-1" aria-labelledby="editQueryModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="update_query">
        <input type="hidden" name="query_id" id="editQueryId">
        <div class="modal-header">
          <h5 class="modal-title" id="editQueryModalLabel">Update Query / Suggestion</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Status</label>
              <select name="status" id="editQueryStatus" class="form-select">
                <option value="pending">Pending</option>
                <option value="blocked">Blocked</option>
                <option value="resolved">Resolved</option>
                <option value="closed">Closed</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">PM Task Link</label>
              <input type="url" name="task_link" id="editQueryTaskLink" class="form-control" placeholder="https://jira.com/browse/TASK-123">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Description / Details *</label>
              <textarea name="description" id="editQueryDescription" class="form-control" rows="3" required></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEditQueryModal(q) {
  document.getElementById('editQueryId').value = q.id;
  document.getElementById('editQueryStatus').value = q.status;
  document.getElementById('editQueryTaskLink').value = q.task_link || '';
  document.getElementById('editQueryDescription').value = q.description;
  
  const modal = new bootstrap.Modal(document.getElementById('editQueryModal'));
  modal.show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
