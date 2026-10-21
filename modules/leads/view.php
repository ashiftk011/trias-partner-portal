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

$user = currentUser();
if (isRole('csm') && (int)($lead['created_by'] ?? 0) !== (int)$user['id']) {
    setFlash('error', 'You do not have permission to view this lead.');
    redirect(BASE_URL . '/modules/leads/index.php');
}
if (isRole('investor') && (int)$lead['project_id'] !== getInvestorProjectId()) {
    setFlash('error', 'You do not have permission to view this lead.');
    redirect(BASE_URL . '/modules/leads/index.php');
}
if (isRole('telecall') && !in_array((int)$lead['project_id'], getTelecallProjectIds())) {
    setFlash('error', 'You do not have permission to view this lead.');
    redirect(BASE_URL . '/modules/leads/index.php');
}

// Mark lead as viewed
if (empty($lead['is_viewed'])) {
    $db->prepare("UPDATE leads SET is_viewed = 1 WHERE id = ?")->execute([$id]);
    $lead['is_viewed'] = 1;
}

$isAdmin = isRole('admin');
$isInvestor = isRole('investor');
$canDelete = $isAdmin && in_array($lead['status'], ['not_interested', 'false_lead']);

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
            if ($statusUpd === 'trial' || $statusUpd === 'trial_ended') {
                $trialEndDate  = !empty($_POST['trial_end_date']) ? $_POST['trial_end_date'] : null;
                $trialLoginUrl = !empty($_POST['trial_login_url']) ? trim($_POST['trial_login_url']) : null;
                $trialUsername = !empty($_POST['trial_username']) ? trim($_POST['trial_username']) : null;
                $trialPassword = !empty($_POST['trial_password']) ? trim($_POST['trial_password']) : null;

                $db->prepare("UPDATE leads SET status=?, trial_end_date=?, trial_login_url=?, trial_username=?, trial_password=?, updated_at=NOW() WHERE id=?")
                   ->execute([$statusUpd, $trialEndDate, $trialLoginUrl, $trialUsername, $trialPassword, $id]);
            } else {
                $db->prepare("UPDATE leads SET status=?, trial_end_date=NULL, trial_login_url=NULL, trial_username=NULL, trial_password=NULL, updated_at=NOW() WHERE id=?")
                   ->execute([$statusUpd, $id]);
            }
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

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div class="d-flex align-items-center gap-2 min-w-0">
    <a href="<?= BASE_URL ?>/modules/leads/index.php" class="btn btn-sm btn-outline-secondary flex-shrink-0" title="Back to Leads">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div class="min-w-0">
      <h4 class="mb-0 text-truncate"><?= htmlspecialchars($lead['name']) ?></h4>
      <div class="d-flex align-items-center gap-2 mt-1">
        <span class="badge bg-light text-dark font-monospace border"><?= htmlspecialchars($lead['lead_code'] ?? '') ?></span>
        <?php if (!empty($lead['company'])): ?>
          <span class="text-muted small text-truncate"><i class="bi bi-building me-1"></i><?= htmlspecialchars($lead['company']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <div class="d-flex flex-wrap align-items-center gap-2">
    <div><?= statusBadge($lead['status']) ?></div>
    <?php if ($lead['status'] !== 'converted' && hasAccess('clients')): ?>
    <a href="<?= BASE_URL ?>/modules/leads/convert.php?id=<?= $id ?>" class="btn btn-sm btn-success fw-bold">
      <i class="bi bi-person-check me-1"></i>Convert to Client
    </a>
    <?php elseif ($lead['status'] === 'converted'): ?>
      <?php if ($linkedClient): ?>
      <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $linkedClient['id'] ?>" class="btn btn-sm btn-outline-success">
        <i class="bi bi-person-badge me-1"></i>View Client
      </a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!$isInvestor && $lead['status'] !== 'converted' && $lead['status'] !== 'false_lead'): ?>
    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#markFalseModal">
      <i class="bi bi-x-octagon me-1"></i>Mark False
    </button>
    <?php endif; ?>
    <?php if ($canDelete): ?>
    <form method="POST" action="<?= BASE_URL ?>/modules/leads/delete.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this lead? This action cannot be undone.')">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-sm btn-danger">
        <i class="bi bi-trash me-1"></i>Delete
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php displayFlash(); ?>

<?php
$cleanPhone = preg_replace('/\D/', '', $lead['phone'] ?? '');
?>
<!-- Quick Contact Bar for Telecallers -->
<div class="mobile-lead-quickbar d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div class="d-flex align-items-center gap-3">
    <div class="stat-icon bg-success-subtle text-success rounded-3 p-2">
      <i class="bi bi-telephone-outbound fs-4"></i>
    </div>
    <div>
      <div class="small text-muted fw-medium">Direct Contact</div>
      <div class="fw-bold fs-5 text-dark font-monospace"><?= htmlspecialchars($lead['phone']) ?></div>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2 w-100 w-sm-auto">
    <a href="tel:<?= htmlspecialchars($lead['phone']) ?>" class="btn btn-call flex-grow-1 px-3 py-2">
      <i class="bi bi-telephone-fill me-2"></i>Call Now
    </a>
    <?php if ($cleanPhone): ?>
    <a href="https://wa.me/<?= $cleanPhone ?>" target="_blank" class="btn btn-whatsapp flex-grow-1 px-3 py-2">
      <i class="bi bi-whatsapp me-2"></i>WhatsApp
    </a>
    <?php endif; ?>
    <?php if (!empty($lead['email'])): ?>
    <a href="mailto:<?= htmlspecialchars($lead['email']) ?>" class="btn btn-outline-secondary px-3 py-2" title="Send Email">
      <i class="bi bi-envelope"></i>
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <!-- Lead Details -->
  <div class="col-12 col-xl-4">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person me-2 text-primary"></i>Lead Information</span>
        <span class="badge bg-light text-dark border"><?= htmlspecialchars($lead['project_name']) ?></span>
      </div>
      <div class="card-body">
        <div class="d-flex justify-content-between border-bottom py-2 align-items-center">
          <span class="text-muted small">Phone</span>
          <div class="d-flex align-items-center gap-2">
            <a href="tel:<?= htmlspecialchars($lead['phone']) ?>" class="fw-semibold small text-decoration-none text-dark">
              <i class="bi bi-telephone text-success me-1"></i><?= htmlspecialchars($lead['phone']) ?>
            </a>
            <button type="button" class="btn btn-link p-0 text-muted" onclick="copyTextValue('<?= htmlspecialchars($lead['phone']) ?>')" title="Copy Phone">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>
        </div>

        <div class="d-flex justify-content-between border-bottom py-2 align-items-center">
          <span class="text-muted small">Email</span>
          <span class="fw-semibold small text-end">
            <?php if (!empty($lead['email'])): ?>
              <a href="mailto:<?= htmlspecialchars($lead['email']) ?>" class="text-decoration-none">
                <?= htmlspecialchars($lead['email']) ?>
              </a>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </span>
        </div>

        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small">Company</span>
          <span class="fw-semibold small text-end"><?= htmlspecialchars($lead['company'] ?: '-') ?></span>
        </div>

        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small">Designation</span>
          <span class="fw-semibold small text-end"><?= htmlspecialchars($lead['designation'] ?: '-') ?></span>
        </div>

        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small">Region</span>
          <span class="fw-semibold small text-end"><?= htmlspecialchars($lead['region_name'] ?: 'All India') ?></span>
        </div>

        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small">Source</span>
          <span class="fw-semibold small text-end">
            <?php if ($lead['source'] === 'social_media'): ?>
              <span class="badge" style="background: linear-gradient(45deg, #f09433, #dc2743, #bc1888); color: #fff;">
                <i class="bi bi-instagram me-1"></i>Instagram
              </span>
            <?php else: ?>
              <?= ucfirst(str_replace('_',' ',$lead['source'])) ?>
            <?php endif; ?>
          </span>
        </div>

        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small">Assigned To</span>
          <span class="fw-semibold small text-end"><?= htmlspecialchars($lead['assigned_name'] ?: 'Unassigned') ?></span>
        </div>

        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small">Interested Plan</span>
          <span class="fw-semibold small text-end">
            <?= $lead['plan_name'] ? htmlspecialchars($lead['plan_name']).' (₹'.number_format($lead['plan_price'],2).')' : '-' ?>
          </span>
        </div>

        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small">Created</span>
          <span class="fw-semibold small text-end"><?= date('d M Y H:i', strtotime($lead['created_at'])) ?></span>
        </div>

        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small">Last Updated</span>
          <span class="fw-semibold small text-end"><?= date('d M Y H:i', strtotime($lead['updated_at'])) ?></span>
        </div>

        <?php if (!empty($lead['address'])): ?>
        <div class="mt-3 border-top pt-2">
          <strong class="small text-dark d-block mb-1"><i class="bi bi-geo-alt me-1 text-primary"></i>Address:</strong>
          <p class="text-muted small mb-0"><?= nl2br(htmlspecialchars($lead['address'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($lead['notes'])): ?>
        <div class="mt-3 border-top pt-2">
          <strong class="small text-dark d-block mb-1"><i class="bi bi-journal-text me-1 text-primary"></i>Initial Notes:</strong>
          <p class="text-muted small mb-0"><?= nl2br(htmlspecialchars($lead['notes'])) ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($lead['status'] === 'trial' || $lead['status'] === 'trial_ended'): ?>
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2 text-primary"></i>Trial Information</span>
        <?php
        if ($lead['status'] === 'trial' && $lead['trial_end_date']) {
            $daysLeft = (int)ceil((strtotime($lead['trial_end_date']) - time()) / 86400);
            if ($daysLeft > 0) {
                echo "<span class='badge bg-success'>{$daysLeft} days left</span>";
            } elseif ($daysLeft === 0) {
                echo "<span class='badge bg-warning text-dark'>Expires today</span>";
            } else {
                echo "<span class='badge bg-danger'>Expired</span>";
            }
        } else {
            echo "<span class='badge bg-secondary'>Expired</span>";
        }
        ?>
      </div>
      <div class="card-body">
        <?php if ($lead['trial_end_date']): ?>
        <div class="d-flex justify-content-between border-bottom py-2">
          <span class="text-muted small">End Date</span>
          <span class="fw-semibold small"><?= date('d M Y', strtotime($lead['trial_end_date'])) ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($lead['trial_login_url']): ?>
        <div class="d-flex justify-content-between border-bottom py-2 align-items-center">
          <span class="text-muted small">Login URL</span>
          <a href="<?= htmlspecialchars($lead['trial_login_url']) ?>" target="_blank" class="btn btn-xs btn-outline-primary py-0 px-2 small text-decoration-none">
            Open Link <i class="bi bi-box-arrow-up-right ms-1"></i>
          </a>
        </div>
        <?php endif; ?>

        <?php if ($lead['trial_username']): ?>
        <div class="d-flex justify-content-between border-bottom py-2 align-items-center">
          <span class="text-muted small">Username</span>
          <span class="fw-semibold small d-flex align-items-center gap-1">
            <span id="trialUserText"><?= htmlspecialchars($lead['trial_username']) ?></span>
            <button class="btn btn-link p-0 text-muted" onclick="copyText('trialUserText')" title="Copy Username">
              <i class="bi bi-clipboard"></i>
            </button>
          </span>
        </div>
        <?php endif; ?>

        <?php if ($lead['trial_password']): ?>
        <div class="d-flex justify-content-between border-bottom py-2 align-items-center">
          <span class="text-muted small">Password</span>
          <span class="fw-semibold small d-flex align-items-center gap-2">
            <span id="trialPassText" data-raw="<?= htmlspecialchars($lead['trial_password']) ?>" data-masked="••••••••">••••••••</span>
            <button class="btn btn-link p-0 text-muted" id="togglePassBtn" onclick="togglePassword()" title="Show/Hide Password">
              <i class="bi bi-eye"></i>
            </button>
            <button class="btn btn-link p-0 text-muted" onclick="copyPassword()" title="Copy Password">
              <i class="bi bi-clipboard"></i>
            </button>
          </span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Responses + Feed Response -->
  <div class="col-12 col-xl-8">
    <!-- Feed Response Form -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-chat-left-text me-2 text-primary"></i>Feed Response / Log Activity</span>
        <span class="text-muted small">Record calls, notes, or status changes</span>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="add_response">
          
          <!-- Touch Segmented Buttons for Response Type -->
          <label class="form-label fw-semibold small text-dark mb-2">Interaction Type</label>
          <input type="hidden" name="response_type" id="responseTypeInput" value="call">
          <div class="response-type-pill-group mb-3">
            <button type="button" class="response-type-btn active" data-type="call" onclick="setResponseType('call', this)">
              <i class="bi bi-telephone-fill"></i>
              <span>Call</span>
            </button>
            <button type="button" class="response-type-btn" data-type="whatsapp" onclick="setResponseType('whatsapp', this)">
              <i class="bi bi-whatsapp"></i>
              <span>WhatsApp</span>
            </button>
            <button type="button" class="response-type-btn" data-type="email" onclick="setResponseType('email', this)">
              <i class="bi bi-envelope-fill"></i>
              <span>Email</span>
            </button>
            <button type="button" class="response-type-btn" data-type="meeting" onclick="setResponseType('meeting', this)">
              <i class="bi bi-calendar-event-fill"></i>
              <span>Meeting</span>
            </button>
            <button type="button" class="response-type-btn" data-type="other" onclick="setResponseType('other', this)">
              <i class="bi bi-chat-dots-fill"></i>
              <span>Other</span>
            </button>
          </div>

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold small text-dark">Update Status</label>
              <select name="status_updated" id="statusUpdateSelect" class="form-select">
                <option value="">-- No Change (Keep <?= ucwords(str_replace('_',' ',$lead['status'])) ?>) --</option>
                <option value="contacted">Contacted</option>
                <option value="interested">Interested</option>
                <option value="trial">Trial</option>
                <option value="trial_ended">Trial Ended</option>
                <option value="not_interested">Not Interested</option>
                <option value="follow_up">Follow Up</option>
                <option value="converted">Converted</option>
                <option value="false_lead">False Lead</option>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold small text-dark">Next Follow-up Date</label>
              <input type="date" name="next_followup" class="form-control" min="<?= date('Y-m-d') ?>">
            </div>
            
            <!-- Feed Response Trial Fields Section -->
            <div class="col-12" id="feedTrialFields" style="display: none;">
              <div class="card border-0 bg-light shadow-sm">
                <div class="card-header bg-white border-bottom-0 py-3 fw-semibold">
                  <i class="bi bi-clock-history text-primary me-2"></i>Trial Version Settings
                </div>
                <div class="card-body pt-0">
                  <div class="row g-3">
                    <div class="col-12 col-md-3">
                      <label class="form-label">Trial End Date *</label>
                      <input type="date" name="trial_end_date" id="feedTrialEndDate" class="form-control">
                    </div>
                    <div class="col-12 col-md-3">
                      <label class="form-label">Trial Login URL</label>
                      <input type="url" name="trial_login_url" id="feedTrialLoginUrl" class="form-control" placeholder="https://example.com/login">
                    </div>
                    <div class="col-12 col-md-3">
                      <label class="form-label">Trial Username</label>
                      <input type="text" name="trial_username" id="feedTrialUsername" class="form-control" placeholder="Username">
                    </div>
                    <div class="col-12 col-md-3">
                      <label class="form-label">Trial Password</label>
                      <input type="text" name="trial_password" id="feedTrialPassword" class="form-control" placeholder="Password">
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold small text-dark">Response / Notes *</label>
              <textarea name="response" class="form-control" rows="3" required placeholder="Describe conversation details, client requirements, or next steps..."></textarea>
            </div>
          </div>
          <div class="mt-3 text-end">
            <button type="submit" class="btn btn-primary px-4 fw-bold w-100 w-sm-auto">
              <i class="bi bi-send me-2"></i>Submit Response
            </button>
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

<script>
window.addEventListener('DOMContentLoaded', () => {
  $('#statusUpdateSelect').on('change', function() {
    const status = this.value;
    const trialDiv = document.getElementById('feedTrialFields');
    if (status === 'trial' || status === 'trial_ended') {
      $(trialDiv).slideDown();
      document.getElementById('feedTrialEndDate').required = true;
    } else {
      $(trialDiv).slideUp();
      document.getElementById('feedTrialEndDate').required = false;
    }
  });
});

function setResponseType(type, btn) {
  document.getElementById('responseTypeInput').value = type;
  document.querySelectorAll('.response-type-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
}

function copyTextValue(val) {
  navigator.clipboard.writeText(val).then(() => {
    alert('Copied to clipboard: ' + val);
  }).catch(() => {});
}

function copyText(elementId) {
  const text = document.getElementById(elementId).innerText;
  navigator.clipboard.writeText(text).then(() => {
    alert('Copied to clipboard!');
  }).catch(err => {
    console.error('Failed to copy text: ', err);
  });
}

function copyPassword() {
  const passSpan = document.getElementById('trialPassText');
  const rawPass = passSpan.getAttribute('data-raw');
  navigator.clipboard.writeText(rawPass).then(() => {
    alert('Password copied to clipboard!');
  }).catch(err => {
    console.error('Failed to copy password: ', err);
  });
}

function togglePassword() {
  const passSpan = document.getElementById('trialPassText');
  const btnIcon = document.querySelector('#togglePassBtn i');
  const current = passSpan.innerText;
  const raw = passSpan.getAttribute('data-raw');
  const masked = passSpan.getAttribute('data-masked');
  
  if (current === masked) {
    passSpan.innerText = raw;
    btnIcon.classList.replace('bi-eye', 'bi-eye-slash');
  } else {
    passSpan.innerText = masked;
    btnIcon.classList.replace('bi-eye-slash', 'bi-eye');
  }
}
</script>

<!-- Mark False Lead Modal -->
<div class="modal fade" id="markFalseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="<?= BASE_URL ?>/modules/leads/mark_false.php">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="redirect_to" value="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $id ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-x-octagon text-danger me-2"></i>Mark as False Lead</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3">Are you sure you want to mark <strong><?= htmlspecialchars($lead['name']) ?></strong> as a <strong>False Lead</strong>?</p>
          <div class="mb-3">
            <label class="form-label">Reason <span class="text-muted">(Optional)</span></label>
            <select name="reason" class="form-select">
              <option value="">-- Select Reason --</option>
              <option value="Invalid Phone / Contact Number">Invalid Phone / Contact Number</option>
              <option value="Fake / Spam Inquiry">Fake / Spam Inquiry</option>
              <option value="Wrong Location / Out of Service Area">Wrong Location / Out of Service Area</option>
              <option value="Irrelevant / Student / Job Seeker">Irrelevant / Student / Job Seeker</option>
              <option value="Duplicate Lead">Duplicate Lead</option>
              <option value="Not Reachable / Disconnected">Not Reachable / Disconnected</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label">Additional Notes <span class="text-muted">(Optional)</span></label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Any details explaining why this lead is false..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-x-octagon me-1"></i>Confirm Mark as False</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
