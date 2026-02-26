<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('leads');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect(BASE_URL . '/modules/leads/index.php'); }

$stmt = $db->prepare("SELECT l.*,p.name as project_name,r.name as region_name,u.name as assigned_name,pl.name as plan_name,pl.price as plan_price FROM leads l LEFT JOIN projects p ON p.id=l.project_id LEFT JOIN regions r ON r.id=l.region_id LEFT JOIN users u ON u.id=l.assigned_to LEFT JOIN plans pl ON pl.id=l.interested_plan_id WHERE l.id=?");
$stmt->execute([$id]);
$lead = $stmt->fetch();
if (!$lead) { redirect(BASE_URL . '/modules/leads/index.php'); }

// If converted, find the linked client
$linkedClient = null;
if ($lead['status'] === 'converted') {
    $cStmt = $db->prepare("SELECT id, client_code, name FROM clients WHERE lead_id = ? LIMIT 1");
    $cStmt->execute([$id]);
    $linkedClient = $cStmt->fetch();
}

// Responses
$responses = $db->prepare("SELECT lr.*,u.name as by_name FROM lead_responses lr LEFT JOIN users u ON u.id=lr.response_by WHERE lr.lead_id=? ORDER BY lr.created_at DESC");
$responses->execute([$id]);
$responses = $responses->fetchAll();

// Handle add response
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_response') {
        $type        = $_POST['response_type'];
        $response    = trim($_POST['response']);
        $followup    = $_POST['next_followup'] ?: null;
        $statusUpd   = $_POST['status_updated'] ?: null;
        $user        = currentUser();

        $db->prepare("INSERT INTO lead_responses (lead_id,response_type,response,response_by,next_followup,status_updated) VALUES (?,?,?,?,?,?)")
           ->execute([$id,$type,$response,$user['id'],$followup,$statusUpd]);

        // If converting, redirect to convert page for plan selection
        if ($statusUpd === 'converted') {
            setFlash('info','Please select a plan and fill client details to complete the conversion.');
            redirect(BASE_URL . '/modules/leads/convert.php?id=' . $id);
        }

        // Update lead status if changed
        if ($statusUpd) {
            $db->prepare("UPDATE leads SET status=?,updated_at=NOW() WHERE id=?")->execute([$statusUpd,$id]);
        } elseif ($followup) {
            $db->prepare("UPDATE leads SET status='follow_up',updated_at=NOW() WHERE id=?")->execute([$id]);
        }
        setFlash('success','Response recorded.');
        redirect(BASE_URL . '/modules/leads/view.php?id=' . $id);
    }
}

$pageTitle = 'Lead: ' . $lead['name'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/leads/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div class="flex-grow-1">
    <h4 class="mb-0"><?= htmlspecialchars($lead['name']) ?> <small class="text-muted fs-6"><?= htmlspecialchars($lead['lead_code'] ?? '') ?></small></h4>
    <span class="text-muted small"><?= htmlspecialchars($lead['company'] ?? '') ?></span>
  </div>
  <?= statusBadge($lead['status']) ?>
  <?php if ($lead['status'] !== 'converted' && hasAccess('clients')): ?>
  <a href="<?= BASE_URL ?>/modules/leads/convert.php?id=<?= $id ?>" class="btn btn-success">
    <i class="bi bi-person-check me-1"></i>Convert to Client
  </a>
  <?php elseif ($lead['status'] === 'converted'): ?>
  <?php if ($linkedClient): ?>
  <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $linkedClient['id'] ?>" class="btn btn-outline-success">
    <i class="bi bi-person-badge me-1"></i>View Client: <?= htmlspecialchars($linkedClient['client_code']) ?>
  </a>
  <?php endif; ?>
  <span class="badge bg-success fs-6 p-2"><i class="bi bi-check-circle me-1"></i>Converted</span>
  <?php endif; ?>
</div>

<?php displayFlash(); ?>

<div class="row g-4">
  <!-- Lead Details -->
  <div class="col-xl-4">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-person me-2 text-primary"></i>Lead Information</div>
      <div class="card-body">
        <?php
        $fields = [
          'Phone'       => $lead['phone'],
          'Email'       => $lead['email'] ?: '-',
          'Designation' => $lead['designation'] ?: '-',
          'Project'     => $lead['project_name'],
          'Region'      => $lead['region_name'] ?: 'All India',
          'Source'      => ucfirst(str_replace('_',' ',$lead['source'])),
          'Assigned To' => $lead['assigned_name'] ?: 'Unassigned',
          'Interested Plan' => $lead['plan_name'] ? $lead['plan_name'].' (₹'.number_format($lead['plan_price'],2).')' : '-',
          'Created'     => date('d M Y H:i', strtotime($lead['created_at'])),
          'Updated'     => date('d M Y H:i', strtotime($lead['updated_at'])),
        ];
        foreach ($fields as $label => $value): ?>
        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small"><?= $label ?></span>
          <span class="fw-semibold small text-end"><?= htmlspecialchars($value) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if ($lead['notes']): ?>
        <div class="mt-3"><strong class="small">Notes:</strong><p class="text-muted small mt-1"><?= nl2br(htmlspecialchars($lead['notes'])) ?></p></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Responses + Feed Response -->
  <div class="col-xl-8">
    <!-- Feed Response Form -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-chat-left-text me-2 text-primary"></i>Feed Response</div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="add_response">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Response Type</label>
              <select name="response_type" class="form-select">
                <option value="call">Call</option>
                <option value="email">Email</option>
                <option value="meeting">Meeting</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Update Status</label>
              <select name="status_updated" id="statusUpdateSelect" class="form-select">
                <option value="">-- No Change --</option>
                <option value="contacted">Contacted</option>
                <option value="interested">Interested</option>
                <option value="not_interested">Not Interested</option>
                <option value="follow_up">Follow Up</option>
                <option value="converted">Converted</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Next Follow-up Date</label>
              <input type="date" name="next_followup" class="form-control" min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Response / Notes *</label>
              <textarea name="response" class="form-control" rows="3" required placeholder="Describe what happened in this interaction..."></textarea>
            </div>
          </div>
          <div class="mt-3 text-end">
            <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit Response</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Response History -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold py-3">
        <i class="bi bi-clock-history me-2 text-primary"></i>Response History
        <span class="badge bg-secondary ms-2"><?= count($responses) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($responses): ?>
        <div class="timeline p-3">
          <?php foreach ($responses as $r): ?>
          <div class="timeline-item d-flex gap-3 mb-3">
            <div class="timeline-icon bg-primary rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0" style="width:36px;height:36px">
              <?php $icons=['call'=>'telephone','email'=>'envelope','meeting'=>'calendar','whatsapp'=>'whatsapp','other'=>'chat']; ?>
              <i class="bi bi-<?= $icons[$r['response_type']] ?? 'chat' ?> small"></i>
            </div>
            <div class="flex-grow-1 border rounded p-3 bg-light">
              <div class="d-flex justify-content-between mb-1">
                <span class="fw-semibold small"><?= htmlspecialchars($r['by_name']) ?></span>
                <small class="text-muted"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></small>
              </div>
              <p class="mb-1 small"><?= nl2br(htmlspecialchars($r['response'])) ?></p>
              <div class="d-flex gap-2 flex-wrap">
                <span class="badge bg-secondary"><?= ucfirst($r['response_type']) ?></span>
                <?php if ($r['status_updated']): ?><span class="badge bg-warning text-dark">Status → <?= ucfirst(str_replace('_',' ',$r['status_updated'])) ?></span><?php endif; ?>
                <?php if ($r['next_followup']): ?><span class="badge bg-info">Follow-up: <?= date('d M Y', strtotime($r['next_followup'])) ?></span><?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4"><i class="bi bi-chat-square-text fs-3 d-block mb-2"></i>No responses yet. Add the first one above.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
