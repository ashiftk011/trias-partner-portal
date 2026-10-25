<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('outreach');

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    redirect(BASE_URL . '/modules/outreach/index.php');
}

$stmt = $db->prepare("SELECT o.*, p.name as project_name, u.name as assigned_name, cb.name as created_by_name,
                             l.lead_code, l.status as lead_status, l.name as lead_name
                      FROM email_outreaches o
                      LEFT JOIN projects p ON p.id = o.project_id
                      LEFT JOIN users u ON u.id = o.assigned_to
                      LEFT JOIN users cb ON cb.id = o.created_by
                      LEFT JOIN leads l ON l.id = o.converted_lead_id
                      WHERE o.id = ?");
$stmt->execute([$id]);
$outreach = $stmt->fetch();

if (!$outreach) {
    setFlash('error', 'Outreach record not found.');
    redirect(BASE_URL . '/modules/outreach/index.php');
}

$isTelecall = isRole('telecall');
$telecallProjectIds = $isTelecall ? getTelecallProjectIds() : [];
if ($isTelecall && !in_array((int)$outreach['project_id'], $telecallProjectIds)) {
    setFlash('error', 'You do not have permission to view this outreach.');
    redirect(BASE_URL . '/modules/outreach/index.php');
}

$isAdmin = isRole('admin');

// Handle Add Activity / Log Touch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_activity') {
    verifyCsrf();
    $actType   = $_POST['activity_type'] ?? 'note';
    $title     = trim($_POST['title'] ?? '');
    $actNotes  = trim($_POST['activity_notes'] ?? '');
    $schedAt   = !empty($_POST['scheduled_at']) ? $_POST['scheduled_at'] : null;

    if ($title) {
        $db->prepare("INSERT INTO email_outreach_activities (outreach_id, activity_type, title, notes, scheduled_at, created_by) VALUES (?,?,?,?,?,?)")
           ->execute([$id, $actType, $title, $actNotes, $schedAt, $user['id']]);

        // If follow-up sent, increment count and update last followup date
        if ($actType === 'follow_up_sent') {
            $db->prepare("UPDATE email_outreaches SET followup_count = followup_count + 1, last_followup_date = CURRENT_DATE(), updated_at = NOW() WHERE id = ?")
               ->execute([$id]);
        }

        setFlash('success', 'Activity touch recorded successfully.');
        redirect(BASE_URL . '/modules/outreach/view.php?id=' . $id);
    }
}

// Fetch activities timeline
$actStmt = $db->prepare("SELECT a.*, u.name as created_by_name 
                         FROM email_outreach_activities a 
                         LEFT JOIN users u ON u.id = a.created_by 
                         WHERE a.outreach_id = ? 
                         ORDER BY a.created_at DESC");
$actStmt->execute([$id]);
$activities = $actStmt->fetchAll();

$pageTitle = 'Outreach: ' . $outreach['contact_name'];
include __DIR__ . '/../../includes/header.php';
?>

<!-- Header -->
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div class="d-flex align-items-center gap-3">
    <a href="<?= BASE_URL ?>/modules/outreach/index.php" class="btn btn-sm btn-outline-secondary rounded-circle" style="width:38px;height:38px;display:flex;align-items:center;justify-content:center;">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div>
      <div class="d-flex align-items-center gap-2">
        <h4 class="mb-0 fw-bold"><?= htmlspecialchars($outreach['contact_name']) ?></h4>
        <span class="badge bg-light text-dark font-monospace border"><?= htmlspecialchars($outreach['outreach_code']) ?></span>
      </div>
      <div class="text-muted small mt-1">
        <?php if (!empty($outreach['company'])): ?>
          <span class="fw-semibold text-secondary"><i class="bi bi-building me-1"></i><?= htmlspecialchars($outreach['company']) ?></span>
        <?php endif; ?>
        <?php if (!empty($outreach['designation'])): ?>
          <span> &bull; <?= htmlspecialchars($outreach['designation']) ?></span>
        <?php endif; ?>
        <span> &bull; Pitched for: <strong class="text-primary"><?= htmlspecialchars($outreach['project_name']) ?></strong></span>
      </div>
    </div>
  </div>

  <div class="d-flex flex-wrap align-items-center gap-2">
    <div><?= outreachStatusBadge($outreach['status']) ?></div>

    <!-- Record Response Modal Button -->
    <button type="button" class="btn btn-sm btn-outline-primary fw-semibold rounded-pill px-3 shadow-sm"
            onclick="openReplyModal(<?= htmlspecialchars(json_encode([
              'id' => $outreach['id'],
              'code' => $outreach['outreach_code'],
              'name' => $outreach['contact_name'],
              'company' => $outreach['company'],
              'project' => $outreach['project_name'],
              'status' => $outreach['status'],
              'response_type' => $outreach['response_type'],
              'response_summary' => $outreach['response_summary'],
              'meeting_date' => $outreach['meeting_date'] ? date('Y-m-d\TH:i', strtotime($outreach['meeting_date'])) : '',
              'next_followup_date' => $outreach['next_followup_date'],
              'is_converted' => !empty($outreach['converted_lead_id'])
            ])) ?>)">
      <i class="bi bi-reply-fill me-1"></i> Record / Update Reply
    </button>

    <!-- Convert to Lead Button -->
    <?php if (empty($outreach['converted_lead_id']) && $outreach['status'] !== 'converted'): ?>
    <a href="<?= BASE_URL ?>/modules/outreach/convert.php?id=<?= $outreach['id'] ?>" class="btn btn-sm btn-success fw-bold rounded-pill px-3 shadow-sm">
      <i class="bi bi-person-plus-fill me-1"></i> Convert to Lead
    </a>
    <?php else: ?>
    <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $outreach['converted_lead_id'] ?>" class="btn btn-sm btn-outline-success fw-semibold rounded-pill px-3 shadow-sm">
      <i class="bi bi-arrow-up-right-circle-fill me-1"></i> View Lead Profile
    </a>
    <?php endif; ?>

    <a href="<?= BASE_URL ?>/modules/outreach/save.php?id=<?= $outreach['id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3 shadow-sm">
      <i class="bi bi-pencil me-1"></i> Edit
    </a>

    <?php if ($isAdmin): ?>
    <form method="POST" action="<?= BASE_URL ?>/modules/outreach/delete.php" class="d-inline" onsubmit="return confirm('Delete this outreach record permanently?');">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="id" value="<?= $outreach['id'] ?>">
      <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 shadow-sm">
        <i class="bi bi-trash"></i>
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Converted Lead Banner -->
<?php if (!empty($outreach['converted_lead_id'])): ?>
<div class="alert alert-success border-0 shadow-sm rounded-4 d-flex align-items-center justify-content-between p-3 mb-4">
  <div class="d-flex align-items-center gap-3">
    <div class="bg-success text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width:40px;height:40px;">
      <i class="bi bi-check2-circle fs-5"></i>
    </div>
    <div>
      <h6 class="mb-0 fw-bold text-success-emphasis">Successfully Converted to Lead</h6>
      <div class="small text-muted">
        This prospect has been converted into Lead <strong><?= htmlspecialchars($outreach['lead_code'] ?: '#' . $outreach['converted_lead_id']) ?></strong> in the sales pipeline.
      </div>
    </div>
  </div>
  <a href="<?= BASE_URL ?>/modules/leads/view.php?id=<?= $outreach['converted_lead_id'] ?>" class="btn btn-sm btn-success rounded-pill px-3 fw-semibold">
    <i class="bi bi-box-arrow-up-right me-1"></i> Open Lead Details
  </a>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- Left Side: Prospect Profile & Response Outcome -->
  <div class="col-lg-7">
    <!-- Response Outcome Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
      <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
          <i class="bi bi-chat-heart text-primary"></i> Outreach Response & Outcome
        </h6>
        <div><?= outreachResponseBadge($outreach['response_type']) ?></div>
      </div>
      <div class="card-body p-4">
        <div class="row g-3">
          <div class="col-md-6">
            <span class="text-muted small d-block">Replied to Cold Email?</span>
            <span class="fw-bold <?= in_array($outreach['status'], ['replied', 'converted']) ? 'text-success' : 'text-warning' ?>">
              <i class="bi <?= in_array($outreach['status'], ['replied', 'converted']) ? 'bi-check-circle-fill' : 'bi-hourglass-split' ?> me-1"></i>
              <?= in_array($outreach['status'], ['replied', 'converted']) ? 'Yes, Response Received' : 'No Reply Yet (Waiting)' ?>
            </span>
          </div>

          <div class="col-md-6">
            <span class="text-muted small d-block">Response Received At</span>
            <span class="fw-semibold text-dark">
              <?= $outreach['replied_at'] ? date('D, M d, Y - h:i A', strtotime($outreach['replied_at'])) : '<span class="text-muted font-italic">Pending</span>' ?>
            </span>
          </div>

          <?php if (!empty($outreach['meeting_date'])): ?>
          <div class="col-12">
            <div class="alert alert-success border-success-subtle bg-success-subtle mb-0 p-3 rounded-3">
              <div class="d-flex align-items-center justify-content-between">
                <div>
                  <h6 class="mb-1 fw-bold text-success">
                    <i class="bi bi-calendar2-check-fill me-2"></i> Meeting / Demo Scheduled
                  </h6>
                  <div class="text-dark fw-semibold">
                    <?= date('l, F j, Y \a\t h:i A', strtotime($outreach['meeting_date'])) ?>
                  </div>
                </div>
                <span class="badge bg-success text-white px-3 py-2 rounded-pill">Confirmed</span>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <div class="col-12">
            <span class="text-muted small d-block mb-1">Response Summary / Notes</span>
            <div class="p-3 bg-light rounded-3 text-dark small border">
              <?= !empty($outreach['response_summary']) ? nl2br(htmlspecialchars($outreach['response_summary'])) : '<span class="text-muted fst-italic">No reply message recorded yet. Click "Record / Update Reply" to log their feedback.</span>' ?>
            </div>
          </div>

          <?php if (!empty($outreach['next_followup_date'])): ?>
          <div class="col-md-6">
            <span class="text-muted small d-block">Scheduled Follow-up Date</span>
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">
              <i class="bi bi-bell-fill me-1"></i> <?= date('M d, Y', strtotime($outreach['next_followup_date'])) ?>
            </span>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Prospect Details Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
      <div class="card-header bg-white py-3 px-4 border-bottom">
        <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
          <i class="bi bi-person-badge text-primary"></i> Prospect Profile & Connection Info
        </h6>
      </div>
      <div class="card-body p-4">
        <div class="row g-3">
          <div class="col-md-6">
            <span class="text-muted small d-block">Contact Name</span>
            <span class="fw-semibold text-dark"><?= htmlspecialchars($outreach['contact_name']) ?></span>
          </div>

          <div class="col-md-6">
            <span class="text-muted small d-block">Email Address</span>
            <a href="mailto:<?= htmlspecialchars($outreach['email']) ?>" class="fw-semibold text-primary text-decoration-none">
              <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($outreach['email']) ?>
            </a>
          </div>

          <div class="col-md-6">
            <span class="text-muted small d-block">Phone / WhatsApp</span>
            <span class="text-dark">
              <?= !empty($outreach['phone']) ? '<i class="bi bi-telephone me-1 text-success"></i>' . htmlspecialchars($outreach['phone']) : '<span class="text-muted">Not specified</span>' ?>
            </span>
          </div>

          <div class="col-md-6">
            <span class="text-muted small d-block">Company</span>
            <span class="text-dark fw-medium">
              <?= !empty($outreach['company']) ? htmlspecialchars($outreach['company']) : '<span class="text-muted">Not specified</span>' ?>
            </span>
          </div>

          <div class="col-md-6">
            <span class="text-muted small d-block">Designation</span>
            <span class="text-dark">
              <?= !empty($outreach['designation']) ? htmlspecialchars($outreach['designation']) : '<span class="text-muted">Not specified</span>' ?>
            </span>
          </div>

          <div class="col-md-6">
            <span class="text-muted small d-block">Location</span>
            <span class="text-dark">
              <?= htmlspecialchars(implode(', ', array_filter([$outreach['city'], $outreach['country']]))) ?: '<span class="text-muted">Not specified</span>' ?>
            </span>
          </div>

          <?php if (!empty($outreach['website'])): ?>
          <div class="col-md-6">
            <span class="text-muted small d-block">Website</span>
            <a href="<?= htmlspecialchars(str_starts_with($outreach['website'], 'http') ? $outreach['website'] : 'https://' . $outreach['website']) ?>" target="_blank" class="text-decoration-none">
              <i class="bi bi-globe me-1"></i><?= htmlspecialchars($outreach['website']) ?>
            </a>
          </div>
          <?php endif; ?>

          <?php if (!empty($outreach['linkedin_url'])): ?>
          <div class="col-md-6">
            <span class="text-muted small d-block">LinkedIn Profile</span>
            <a href="<?= htmlspecialchars($outreach['linkedin_url']) ?>" target="_blank" class="text-decoration-none text-primary">
              <i class="bi bi-linkedin me-1"></i>View Profile
            </a>
          </div>
          <?php endif; ?>
        </div>

        <hr class="my-4 text-muted opacity-25">

        <h6 class="fw-bold text-dark small mb-3">Project & Outreach Specifications</h6>
        <div class="row g-3">
          <div class="col-md-6">
            <span class="text-muted small d-block">Project Pitched</span>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-2 fs-6">
              <i class="bi bi-folder2-open me-1"></i><?= htmlspecialchars($outreach['project_name']) ?>
            </span>
          </div>

          <div class="col-md-6">
            <span class="text-muted small d-block">Campaign / Batch Tag</span>
            <span class="fw-medium text-dark"><?= !empty($outreach['campaign_name']) ? htmlspecialchars($outreach['campaign_name']) : '<span class="text-muted">General Cold Outreach</span>' ?></span>
          </div>

          <div class="col-md-6">
            <span class="text-muted small d-block">Initial Sent Date</span>
            <span class="text-dark"><i class="bi bi-calendar-event me-1 text-muted"></i><?= date('F j, Y', strtotime($outreach['outreach_date'])) ?></span>
          </div>

          <div class="col-md-6">
            <span class="text-muted small d-block">Assigned Representative</span>
            <span class="text-dark"><i class="bi bi-person me-1 text-muted"></i><?= htmlspecialchars($outreach['assigned_name'] ?: 'Unassigned') ?></span>
          </div>

          <?php if (!empty($outreach['email_subject'])): ?>
          <div class="col-12">
            <span class="text-muted small d-block">Email Subject Line</span>
            <span class="text-dark fw-semibold"><?= htmlspecialchars($outreach['email_subject']) ?></span>
          </div>
          <?php endif; ?>

          <?php if (!empty($outreach['notes'])): ?>
          <div class="col-12">
            <span class="text-muted small d-block mb-1">Cold Email Body / Pitch Notes</span>
            <div class="p-3 bg-light rounded-3 text-muted small border">
              <?= nl2br(htmlspecialchars($outreach['notes'])) ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Right Side: Timeline & Follow-up Actions -->
  <div class="col-lg-5">
    <!-- Log Touch / Follow-up Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
      <div class="card-header bg-white py-3 px-4 border-bottom">
        <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
          <i class="bi bi-send-plus text-primary"></i> Log Follow-up or Touchpoint
        </h6>
      </div>
      <div class="card-body p-4">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="add_activity">

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Action / Touch Type</label>
            <select name="activity_type" class="form-select form-select-sm" required>
              <option value="follow_up_sent">Follow-up Email Sent (Increments touch count)</option>
              <option value="call">Phone / WhatsApp Call Made</option>
              <option value="reply_received">Received Reply / Inbound Message</option>
              <option value="meeting_booked">Meeting / Demo Scheduled</option>
              <option value="note">Internal Team Note</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Title / Summary <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control form-control-sm" placeholder="e.g. Sent Follow-up #1 with Case Study" required>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Details / Notes</label>
            <textarea name="activity_notes" class="form-control form-control-sm" rows="3" placeholder="Notes, responses, or points discussed..."></textarea>
          </div>

          <div class="d-flex justify-content-between align-items-center">
            <span class="small text-muted">Total Touches: <strong class="text-dark"><?= (int)$outreach['followup_count'] + 1 ?></strong></span>
            <button type="submit" class="btn btn-sm btn-primary px-3 rounded-pill fw-semibold">
              <i class="bi bi-plus-circle me-1"></i> Add Activity
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Activity Timeline -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
      <div class="card-header bg-white py-3 px-4 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
          <i class="bi bi-clock-history text-primary"></i> Activity & Outreach Timeline
        </h6>
        <span class="badge bg-secondary-subtle text-secondary rounded-pill"><?= count($activities) ?> logs</span>
      </div>
      <div class="card-body p-4">
        <?php if (empty($activities)): ?>
        <p class="text-muted small text-center my-3">No activity recorded yet.</p>
        <?php else: ?>
        <div class="timeline">
          <?php foreach ($activities as $act): ?>
          <?php
            $icon = 'chat-dots';
            $badgeColor = 'primary';
            if ($act['activity_type'] === 'initial_email') {
                $icon = 'send-fill'; $badgeColor = 'primary';
            } elseif ($act['activity_type'] === 'follow_up_sent') {
                $icon = 'arrow-repeat'; $badgeColor = 'warning';
            } elseif ($act['activity_type'] === 'reply_received') {
                $icon = 'reply-fill'; $badgeColor = 'info';
            } elseif ($act['activity_type'] === 'meeting_booked' || $act['activity_type'] === 'demo_booked') {
                $icon = 'calendar-check-fill'; $badgeColor = 'success';
            } elseif ($act['activity_type'] === 'converted_to_lead') {
                $icon = 'person-check-fill'; $badgeColor = 'success';
            } elseif ($act['activity_type'] === 'call') {
                $icon = 'telephone-fill'; $badgeColor = 'secondary';
            }
          ?>
          <div class="d-flex gap-3 mb-4 position-relative">
            <div class="rounded-circle p-2 bg-<?= $badgeColor ?>-subtle text-<?= $badgeColor ?> d-flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;">
              <i class="bi bi-<?= $icon ?> fs-6"></i>
            </div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-baseline">
                <h6 class="mb-1 fw-bold text-dark small"><?= htmlspecialchars($act['title']) ?></h6>
                <span class="text-muted small" style="font-size:0.75rem;"><?= date('M d, h:i A', strtotime($act['created_at'])) ?></span>
              </div>
              <?php if (!empty($act['notes'])): ?>
              <p class="text-muted small mb-1"><?= nl2br(htmlspecialchars($act['notes'])) ?></p>
              <?php endif; ?>
              <?php if (!empty($act['scheduled_at'])): ?>
              <div class="small text-success fw-semibold">
                <i class="bi bi-calendar-event me-1"></i> Scheduled: <?= date('M d, Y h:i A', strtotime($act['scheduled_at'])) ?>
              </div>
              <?php endif; ?>
              <span class="text-muted" style="font-size:0.72rem;">by <?= htmlspecialchars($act['created_by_name'] ?: 'System') ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Include Quick Record Response Modal -->
<div class="modal fade" id="replyModal" tabindex="-1" aria-labelledby="replyModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <form method="POST" action="<?= BASE_URL ?>/modules/outreach/log_reply.php">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="outreach_id" id="reply_outreach_id" value="<?= $outreach['id'] ?>">
        <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

        <div class="modal-header bg-primary text-white py-3 rounded-top-4">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-reply-fill fs-5"></i>
            <div>
              <h5 class="modal-title fw-bold mb-0" id="replyModalLabel">Record Prospect Reply</h5>
              <div class="small opacity-75">Outreach: <?= htmlspecialchars($outreach['outreach_code']) ?></div>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-4">
          <div class="alert alert-light border small py-2 mb-3 d-flex justify-content-between">
            <div><strong><?= htmlspecialchars($outreach['contact_name']) ?></strong> <span class="text-muted"><?= htmlspecialchars($outreach['company'] ? "({$outreach['company']})" : '') ?></span></div>
            <div class="badge bg-primary-subtle text-primary"><?= htmlspecialchars($outreach['project_name']) ?></div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Response Outcome <span class="text-danger">*</span></label>
            <select name="response_type" id="reply_response_type" class="form-select" required onchange="toggleResponseFields()">
              <option value="meeting_scheduled" <?= $outreach['response_type'] === 'meeting_scheduled' ? 'selected' : '' ?>>Meeting Scheduled</option>
              <option value="demo_requested" <?= $outreach['response_type'] === 'demo_requested' ? 'selected' : '' ?>>Demo Asked / Requested</option>
              <option value="interested_more_info" <?= $outreach['response_type'] === 'interested_more_info' ? 'selected' : '' ?>>Interested / Requesting Info</option>
              <option value="pricing_inquiry" <?= $outreach['response_type'] === 'pricing_inquiry' ? 'selected' : '' ?>>Pricing / Commercial Inquiry</option>
              <option value="call_back_later" <?= $outreach['response_type'] === 'call_back_later' ? 'selected' : '' ?>>Follow-up Later (Check back)</option>
              <option value="referral" <?= $outreach['response_type'] === 'referral' ? 'selected' : '' ?>>Referred to Colleague</option>
              <option value="not_interested" <?= $outreach['response_type'] === 'not_interested' ? 'selected' : '' ?>>Not Interested / Not Now</option>
              <option value="other" <?= $outreach['response_type'] === 'other' ? 'selected' : '' ?>>Other Response</option>
            </select>
          </div>

          <div class="mb-3" id="meetingDateFieldWrapper">
            <label class="form-label fw-semibold small text-success">
              <i class="bi bi-calendar2-check-fill me-1"></i> Scheduled Meeting / Demo Time
            </label>
            <input type="datetime-local" name="meeting_date" id="reply_meeting_date" class="form-control" value="<?= $outreach['meeting_date'] ? date('Y-m-d\TH:i', strtotime($outreach['meeting_date'])) : '' ?>">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Response Message / Summary</label>
            <textarea name="response_summary" id="reply_response_summary" rows="3" class="form-control" placeholder="Summarize their reply, requirements, objections, or demo interests..."><?= htmlspecialchars($outreach['response_summary'] ?? '') ?></textarea>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Reply Date & Time</label>
              <input type="datetime-local" name="replied_at" id="reply_replied_at" class="form-control form-control-sm" value="<?= $outreach['replied_at'] ? date('Y-m-d\TH:i', strtotime($outreach['replied_at'])) : date('Y-m-d\TH:i') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Next Follow-up Date</label>
              <input type="date" name="next_followup_date" id="reply_next_followup_date" class="form-control form-control-sm" value="<?= htmlspecialchars($outreach['next_followup_date'] ?? '') ?>">
            </div>
          </div>

          <?php if (empty($outreach['converted_lead_id'])): ?>
          <div class="card bg-success-subtle border-success-subtle p-3 rounded-3" id="convertOptionWrapper">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="convert_to_lead" value="1" id="convertLeadCheck" checked>
              <label class="form-check-label fw-semibold text-success-emphasis" for="convertLeadCheck">
                <i class="bi bi-person-check-fill me-1"></i> Convert to Lead immediately
              </label>
              <div class="small text-muted mt-1">
                Creates a new Lead in the pipeline under <?= htmlspecialchars($outreach['project_name']) ?>.
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="modal-footer bg-light py-3 px-4 rounded-bottom-4">
          <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 fw-semibold">
            <i class="bi bi-check2-circle me-1"></i> Save Response
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openReplyModal(data) {
  toggleResponseFields();
  const modal = new bootstrap.Modal(document.getElementById('replyModal'));
  modal.show();
}

function toggleResponseFields() {
  const respType = document.getElementById('reply_response_type').value;
  const meetingWrap = document.getElementById('meetingDateFieldWrapper');
  const convertCheck = document.getElementById('convertLeadCheck');

  if (respType === 'meeting_scheduled' || respType === 'demo_requested') {
    meetingWrap.style.display = 'block';
    if (convertCheck) convertCheck.checked = true;
  } else if (respType === 'interested_more_info' || respType === 'pricing_inquiry') {
    meetingWrap.style.display = 'none';
    if (convertCheck) convertCheck.checked = true;
  } else {
    meetingWrap.style.display = 'none';
    if (convertCheck) convertCheck.checked = false;
  }
}

document.addEventListener('DOMContentLoaded', function() {
  toggleResponseFields();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
