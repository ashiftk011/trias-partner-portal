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

// If converted from cold email outreach, find linked outreach
$linkedOutreach = null;
if (!empty($lead['outreach_id'])) {
    $oStmt = $db->prepare("SELECT id, outreach_code, contact_name, response_type, outreach_date, meeting_date, response_summary FROM email_outreaches WHERE id = ?");
    $oStmt->execute([$lead['outreach_id']]);
    $linkedOutreach = $oStmt->fetch();
}

if (!function_exists('parseCallDuration')) {
    function parseCallDuration(string $input): int {
        $input = trim($input);
        if (empty($input)) return 0;
        if (strpos($input, ':') !== false) {
            $parts = explode(':', $input);
            if (count($parts) === 2) {
                return ((int)$parts[0] * 60) + (int)$parts[1];
            } elseif (count($parts) === 3) {
                return ((int)$parts[0] * 3600) + ((int)$parts[1] * 60) + (int)$parts[2];
            }
        }
        return (int)$input;
    }
}

if (!function_exists('formatCallDuration')) {
    function formatCallDuration(?int $seconds): string {
        if (!$seconds || $seconds <= 0) return '00:00';
        $m = floor($seconds / 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d', $m, $s);
    }
}

// Call Recordings
$recordingsStmt = $db->prepare("SELECT cr.*, u.name as recorded_by_name
                                FROM lead_call_recordings cr
                                LEFT JOIN users u ON u.id = cr.recorded_by
                                WHERE cr.lead_id = ?
                                ORDER BY cr.call_date DESC, cr.id DESC");
$recordingsStmt->execute([$id]);
$callRecordings = $recordingsStmt->fetchAll();

$recsByResponse = [];
foreach ($callRecordings as $cr) {
    if (!empty($cr['response_id'])) {
        $recsByResponse[$cr['response_id']] = $cr;
    }
}

// Responses
$responses = $db->prepare("SELECT lr.*,u.name as by_name FROM lead_responses lr LEFT JOIN users u ON u.id=lr.response_by WHERE lr.lead_id=? ORDER BY lr.created_at DESC");
$responses->execute([$id]);
$responses = $responses->fetchAll();

// Handle add response & call recordings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // 1. ACTION: ADD RESPONSE
    if ($action === 'add_response') {
        $type        = $_POST['response_type'];
        $response    = trim($_POST['response']);
        $followup    = $_POST['next_followup'] ?: null;
        $statusUpd   = $_POST['status_updated'] ?: null;
        $user        = currentUser();

        $db->prepare("INSERT INTO lead_responses (lead_id,response_type,response,response_by,next_followup,status_updated) VALUES (?,?,?,?,?,?)")
           ->execute([$id,$type,$response,$user['id'],$followup,$statusUpd]);
        $newRespId = (int)$db->lastInsertId();

        // Check if a call recording audio file was attached during response submission
        if ($type === 'call' && !empty($_FILES['call_recording_file']['name']) && $_FILES['call_recording_file']['error'] === UPLOAD_ERR_OK) {
            $allowedExts = ['mp3', 'wav', 'm4a', 'ogg', 'aac', 'webm', 'amr', 'mp4'];
            $ext = strtolower(pathinfo($_FILES['call_recording_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExts)) {
                $uploadDir = __DIR__ . '/../../uploads/call_recordings/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $safeName = 'call_' . $id . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                if (move_uploaded_file($_FILES['call_recording_file']['tmp_name'], $uploadDir . $safeName)) {
                    $filePath = 'uploads/call_recordings/' . $safeName;
                    $fileName = $_FILES['call_recording_file']['name'];
                    $fileSize = (int)$_FILES['call_recording_file']['size'];
                    $recTitle = "Call Recording - " . date('M d, Y H:i');

                    $db->prepare("INSERT INTO lead_call_recordings (lead_id, response_id, title, call_date, duration_seconds, call_type, call_status, file_path, file_name, file_size, notes, recorded_by)
                                  VALUES (?,?,?,NOW(),0,'outbound','connected',?,?,?,?,?)")
                       ->execute([$id, $newRespId, $recTitle, $filePath, $fileName, $fileSize, $response, $user['id']]);
                }
            }
        }

        // If converting, redirect to convert page for plan selection
        if ($statusUpd === 'converted') {
            $db->prepare("UPDATE leads SET next_followup=NULL, updated_at=NOW() WHERE id=?")->execute([$id]);
            setFlash('info','Please select a plan and fill client details to complete the conversion.');
            redirect(BASE_URL . '/modules/leads/convert.php?id=' . $id);
        }

        // Update lead status and next_followup if changed
        if ($statusUpd) {
            $clearFollowup = in_array($statusUpd, ['converted', 'false_lead', 'not_interested']);
            if ($statusUpd === 'trial' || $statusUpd === 'trial_ended') {
                $trialEndDate  = !empty($_POST['trial_end_date']) ? $_POST['trial_end_date'] : null;
                $trialLoginUrl = !empty($_POST['trial_login_url']) ? trim($_POST['trial_login_url']) : null;
                $trialUsername = !empty($_POST['trial_username']) ? trim($_POST['trial_username']) : null;
                $trialPassword = !empty($_POST['trial_password']) ? trim($_POST['trial_password']) : null;

                if ($followup) {
                    $db->prepare("UPDATE leads SET status=?, trial_end_date=?, trial_login_url=?, trial_username=?, trial_password=?, next_followup=?, updated_at=NOW() WHERE id=?")
                       ->execute([$statusUpd, $trialEndDate, $trialLoginUrl, $trialUsername, $trialPassword, $followup, $id]);
                } elseif ($clearFollowup) {
                    $db->prepare("UPDATE leads SET status=?, trial_end_date=?, trial_login_url=?, trial_username=?, trial_password=?, next_followup=NULL, updated_at=NOW() WHERE id=?")
                       ->execute([$statusUpd, $trialEndDate, $trialLoginUrl, $trialUsername, $trialPassword, $id]);
                } else {
                    $db->prepare("UPDATE leads SET status=?, trial_end_date=?, trial_login_url=?, trial_username=?, trial_password=?, updated_at=NOW() WHERE id=?")
                       ->execute([$statusUpd, $trialEndDate, $trialLoginUrl, $trialUsername, $trialPassword, $id]);
                }
            } else {
                if ($followup) {
                    $db->prepare("UPDATE leads SET status=?, trial_end_date=NULL, trial_login_url=NULL, trial_username=NULL, trial_password=NULL, next_followup=?, updated_at=NOW() WHERE id=?")
                       ->execute([$statusUpd, $followup, $id]);
                } elseif ($clearFollowup) {
                    $db->prepare("UPDATE leads SET status=?, trial_end_date=NULL, trial_login_url=NULL, trial_username=NULL, trial_password=NULL, next_followup=NULL, updated_at=NOW() WHERE id=?")
                       ->execute([$statusUpd, $id]);
                } else {
                    $db->prepare("UPDATE leads SET status=?, trial_end_date=NULL, trial_login_url=NULL, trial_username=NULL, trial_password=NULL, updated_at=NOW() WHERE id=?")
                       ->execute([$statusUpd, $id]);
                }
            }
        } elseif ($followup) {
            $db->prepare("UPDATE leads SET status='follow_up', next_followup=?, updated_at=NOW() WHERE id=?")->execute([$followup, $id]);
        }
        setFlash('success','Response recorded.');
        redirect(BASE_URL . '/modules/leads/view.php?id=' . $id);
    }

    // 2. ACTION: ADD CALL RECORDING
    if ($action === 'add_call_recording') {
        $title       = trim($_POST['title'] ?? '');
        $callDate    = !empty($_POST['call_date']) ? $_POST['call_date'] : date('Y-m-d H:i:s');
        $callType    = in_array($_POST['call_type'] ?? '', ['outbound', 'inbound']) ? $_POST['call_type'] : 'outbound';
        $callStatus  = in_array($_POST['call_status'] ?? '', ['connected', 'no_answer', 'busy', 'voicemail', 'wrong_number']) ? $_POST['call_status'] : 'connected';
        $durationRaw = trim($_POST['duration'] ?? '');
        $durationSec = parseCallDuration($durationRaw);
        $externalUrl = trim($_POST['external_url'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $addToTimeline = !empty($_POST['add_to_timeline']);
        $statusUpd   = !empty($_POST['status_updated']) ? $_POST['status_updated'] : null;
        $followup    = !empty($_POST['next_followup']) ? $_POST['next_followup'] : null;

        if (!$title) {
            $title = ucfirst($callType) . ' Call - ' . date('M d, Y', strtotime($callDate));
        }

        $filePath = null;
        $fileName = null;
        $fileSize = null;

        if (!empty($_FILES['audio_file']['name']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK) {
            $allowedExts = ['mp3', 'wav', 'm4a', 'ogg', 'aac', 'webm', 'amr', 'mp4'];
            $ext = strtolower(pathinfo($_FILES['audio_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExts)) {
                $uploadDir = __DIR__ . '/../../uploads/call_recordings/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $safeName = 'call_' . $id . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
                if (move_uploaded_file($_FILES['audio_file']['tmp_name'], $uploadDir . $safeName)) {
                    $filePath = 'uploads/call_recordings/' . $safeName;
                    $fileName = $_FILES['audio_file']['name'];
                    $fileSize = (int)$_FILES['audio_file']['size'];
                }
            } else {
                setFlash('error', 'Invalid audio format. Allowed formats: ' . implode(', ', $allowedExts));
                redirect(BASE_URL . '/modules/leads/view.php?id=' . $id);
            }
        }

        if (!$filePath && !$externalUrl) {
            setFlash('error', 'Please upload an audio file or provide a recording link.');
            redirect(BASE_URL . '/modules/leads/view.php?id=' . $id);
        }

        // Optional: add to response timeline
        $responseId = null;
        if ($addToTimeline) {
            $respNote = "Call Recording Added: " . $title . ($notes ? " — " . $notes : "");
            $db->prepare("INSERT INTO lead_responses (lead_id, response_type, response, response_by, next_followup, status_updated) VALUES (?, 'call', ?, ?, ?, ?)")
               ->execute([$id, $respNote, $user['id'], $followup, $statusUpd]);
            $responseId = (int)$db->lastInsertId();
        }

        // Insert into lead_call_recordings
        $db->prepare("INSERT INTO lead_call_recordings (lead_id, response_id, title, call_date, duration_seconds, call_type, call_status, file_path, file_name, file_size, external_url, notes, recorded_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$id, $responseId, $title, $callDate, $durationSec, $callType, $callStatus, $filePath, $fileName, $fileSize, $externalUrl ?: null, $notes, $user['id']]);

        // Update lead status and follow-up if specified
        if ($followup) {
            $newStatus = $statusUpd ?: 'follow_up';
            $db->prepare("UPDATE leads SET status=?, next_followup=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $followup, $id]);
        } elseif ($statusUpd) {
            $clearFu = in_array($statusUpd, ['converted', 'false_lead', 'not_interested']) ? ", next_followup=NULL" : "";
            $db->prepare("UPDATE leads SET status=? $clearFu, updated_at=NOW() WHERE id=?")->execute([$statusUpd, $id]);
        }

        setFlash('success', 'Call recording added successfully.');
        redirect(BASE_URL . '/modules/leads/view.php?id=' . $id);
    }

    // 3. ACTION: DELETE CALL RECORDING
    if ($action === 'delete_call_recording') {
        $recId = (int)($_POST['recording_id'] ?? 0);
        if ($recId) {
            $chk = $db->prepare("SELECT * FROM lead_call_recordings WHERE id=? AND lead_id=?");
            $chk->execute([$recId, $id]);
            $rec = $chk->fetch();
            if ($rec) {
                if (isRole('admin') || (int)$rec['recorded_by'] === (int)$user['id']) {
                    if (!empty($rec['file_path'])) {
                        $fullPath = __DIR__ . '/../../' . $rec['file_path'];
                        if (file_exists($fullPath)) {
                            @unlink($fullPath);
                        }
                    }
                    $db->prepare("DELETE FROM lead_call_recordings WHERE id=?")->execute([$recId]);
                    setFlash('success', 'Call recording deleted.');
                } else {
                    setFlash('error', 'You do not have permission to delete this recording.');
                }
            }
        }
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
    <button type="button" class="btn btn-sm btn-outline-danger fw-semibold" data-bs-toggle="modal" data-bs-target="#addRecordingModal">
      <i class="bi bi-mic-fill me-1"></i>Add Call Recording
    </button>
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

<?php if ($linkedOutreach): ?>
<div class="alert alert-info border-0 shadow-sm rounded-4 p-3 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
  <div class="d-flex align-items-center gap-3">
    <div class="bg-primary text-white p-2 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:38px;height:38px;">
      <i class="bi bi-envelope-at-fill fs-5"></i>
    </div>
    <div>
      <div class="fw-bold text-dark">Origin: Cold Email Outreach <a href="<?= BASE_URL ?>/modules/outreach/view.php?id=<?= $linkedOutreach['id'] ?>" class="text-primary text-decoration-none font-monospace ms-1"><?= htmlspecialchars($linkedOutreach['outreach_code']) ?></a></div>
      <div class="small text-muted mt-1">
        Sent: <?= date('M d, Y', strtotime($linkedOutreach['outreach_date'])) ?> &bull; Outcome: <?= outreachResponseBadge($linkedOutreach['response_type']) ?>
        <?php if (!empty($linkedOutreach['meeting_date'])): ?>
        &bull; <span class="text-success fw-medium"><i class="bi bi-calendar-check me-1"></i>Scheduled: <?= date('M d, h:i A', strtotime($linkedOutreach['meeting_date'])) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <a href="<?= BASE_URL ?>/modules/outreach/view.php?id=<?= $linkedOutreach['id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm">
    <i class="bi bi-eye me-1"></i> View Outreach History
  </a>
</div>
<?php endif; ?>

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
    <?php if (!empty($callRecordings)): ?>
    <a href="#callRecordingsCard" class="btn btn-outline-danger px-3 py-2 fw-semibold" title="Jump to Call Recordings">
      <i class="bi bi-mic-fill me-1"></i><?= count($callRecordings) ?> Audio<?= count($callRecordings) > 1 ? 's' : '' ?>
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

        <?php if (!empty($lead['next_followup'])): 
          $fuDiff = (int)round((strtotime($lead['next_followup']) - strtotime(date('Y-m-d'))) / 86400);
        ?>
        <div class="d-flex justify-content-between border-bottom py-2 align-items-center bg-warning bg-opacity-10 px-2 rounded-2 mt-2">
          <span class="text-dark fw-semibold small"><i class="bi bi-clock-history text-warning me-1"></i>Next Follow-up</span>
          <span class="text-end">
            <span class="fw-bold small text-dark"><?= date('d M Y', strtotime($lead['next_followup'])) ?></span>
            <?php if ($fuDiff === 0): ?>
              <span class="badge bg-warning text-dark ms-1">Today</span>
            <?php elseif ($fuDiff < 0): ?>
              <span class="badge bg-danger ms-1">Overdue (<?= abs($fuDiff) ?>d ago)</span>
            <?php elseif ($fuDiff === 1): ?>
              <span class="badge bg-info text-dark ms-1">Tomorrow</span>
            <?php else: ?>
              <span class="badge bg-secondary ms-1">In <?= $fuDiff ?>d</span>
            <?php endif; ?>
          </span>
        </div>
        <?php endif; ?>

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
        <form method="POST" enctype="multipart/form-data">
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

            <!-- Optional Call Recording Attachment inside Feed Response -->
            <div class="col-12" id="feedCallRecordingWrap">
              <label class="form-label fw-semibold small text-dark">
                <i class="bi bi-mic text-danger me-1"></i>Attach Call Recording (Optional)
              </label>
              <input type="file" name="call_recording_file" class="form-control form-control-sm" accept="audio/*,.mp3,.wav,.m4a,.ogg,.aac,.webm,.amr">
              <div class="form-text small">Upload .mp3, .wav, or .m4a audio recording of this phone conversation.</div>
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

    <!-- Call Recordings Card -->
    <div class="card border-0 shadow-sm mb-3" id="callRecordingsCard">
      <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-mic-fill text-danger fs-5"></i>
          <span class="fw-bold">Call Recordings</span>
          <span class="badge bg-danger-subtle text-danger rounded-pill"><?= count($callRecordings) ?></span>
        </div>
        <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#addRecordingModal">
          <i class="bi bi-plus-lg me-1"></i> Add Call Recording
        </button>
      </div>
      <div class="card-body p-3">
        <?php if (empty($callRecordings)): ?>
          <div class="text-center py-4 text-muted">
            <div class="mb-2">
              <i class="bi bi-soundwave fs-1 text-danger opacity-50"></i>
            </div>
            <h6 class="fw-bold text-dark mb-1">No Call Recordings Logged Yet</h6>
            <p class="small text-muted mb-3" style="max-width: 440px; margin: 0 auto;">
              Upload phone call audio files (.mp3, .wav, .m4a) or provide cloud recording URLs to preserve customer discussions and train agents.
            </p>
            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addRecordingModal">
              <i class="bi bi-upload me-1"></i> Upload First Recording
            </button>
          </div>
        <?php else: ?>
          <div class="d-flex flex-column gap-3">
            <?php foreach ($callRecordings as $rec): ?>
            <?php
              $statusColors = [
                'connected'    => 'success',
                'no_answer'    => 'warning',
                'busy'         => 'secondary',
                'voicemail'    => 'info',
                'wrong_number' => 'danger'
              ];
              $stColor = $statusColors[$rec['call_status']] ?? 'secondary';
            ?>
            <div class="border rounded-4 p-3 bg-light position-relative">
              <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                  <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="fw-bold text-dark fs-6"><?= htmlspecialchars($rec['title']) ?></span>
                    <span class="badge bg-<?= $rec['call_type'] === 'inbound' ? 'success' : 'primary' ?>-subtle text-<?= $rec['call_type'] === 'inbound' ? 'success' : 'primary' ?> border border-<?= $rec['call_type'] === 'inbound' ? 'success' : 'primary' ?>-subtle">
                      <i class="bi bi-telephone-<?= $rec['call_type'] === 'inbound' ? 'inbound' : 'outbound' ?> me-1"></i><?= ucfirst($rec['call_type']) ?>
                    </span>
                    <span class="badge bg-<?= $stColor ?>-subtle text-<?= $stColor ?> border border-<?= $stColor ?>-subtle">
                      <?= ucwords(str_replace('_', ' ', $rec['call_status'])) ?>
                    </span>
                  </div>
                  <div class="small text-muted mt-1">
                    <i class="bi bi-calendar3 me-1"></i><?= date('D, d M Y - h:i A', strtotime($rec['call_date'])) ?>
                    &bull; <i class="bi bi-person me-1"></i><?= htmlspecialchars($rec['recorded_by_name'] ?: 'Agent') ?>
                    <?php if ($rec['duration_seconds'] > 0): ?>
                    &bull; <span class="fw-semibold text-dark"><i class="bi bi-stopwatch me-1 text-danger"></i><?= formatCallDuration((int)$rec['duration_seconds']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Actions (Download & Delete) -->
                <div class="d-flex align-items-center gap-2">
                  <?php if (!empty($rec['file_path'])): ?>
                  <a href="<?= BASE_URL . '/' . htmlspecialchars($rec['file_path']) ?>" download class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-1" title="Download Audio File">
                    <i class="bi bi-download me-1"></i> Download
                  </a>
                  <?php endif; ?>
                  <?php if (isRole('admin') || (int)$rec['recorded_by'] === (int)$user['id']): ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Delete this call recording? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete_call_recording">
                    <input type="hidden" name="recording_id" value="<?= $rec['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle p-1" style="width:30px;height:30px;" title="Delete Recording">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Audio Player or External Link -->
              <?php if (!empty($rec['file_path'])): ?>
              <div class="bg-white p-2 rounded-3 border my-2 shadow-sm">
                <audio controls preload="none" class="w-100" style="height: 38px;">
                  <source src="<?= BASE_URL . '/' . htmlspecialchars($rec['file_path']) ?>" type="audio/mpeg">
                  Your browser does not support the audio player.
                </audio>
              </div>
              <?php elseif (!empty($rec['external_url'])): ?>
              <div class="my-2">
                <a href="<?= htmlspecialchars($rec['external_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill">
                  <i class="bi bi-box-arrow-up-right me-1"></i> Open & Listen External Recording
                </a>
              </div>
              <?php endif; ?>

              <?php if (!empty($rec['notes'])): ?>
              <div class="mt-2 p-2 bg-white rounded-3 small text-muted border">
                <strong class="text-dark d-block mb-1"><i class="bi bi-card-text me-1 text-primary"></i>Call Discussion Notes:</strong>
                <?= nl2br(htmlspecialchars($rec['notes'])) ?>
              </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
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

              <?php if (!empty($recsByResponse[$r['id']])): ?>
              <?php $attachedRec = $recsByResponse[$r['id']]; ?>
              <div class="mt-2 p-2 bg-white rounded-3 border">
                <div class="small fw-semibold text-danger mb-1 d-flex align-items-center justify-content-between">
                  <span><i class="bi bi-mic-fill me-1"></i>Attached Call Recording</span>
                  <?php if ($attachedRec['duration_seconds'] > 0): ?>
                  <span class="badge bg-danger-subtle text-danger"><i class="bi bi-stopwatch me-1"></i><?= formatCallDuration((int)$attachedRec['duration_seconds']) ?></span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($attachedRec['file_path'])): ?>
                <audio controls preload="none" class="w-100" style="height:34px;">
                  <source src="<?= BASE_URL . '/' . htmlspecialchars($attachedRec['file_path']) ?>" type="audio/mpeg">
                </audio>
                <?php elseif (!empty($attachedRec['external_url'])): ?>
                <a href="<?= htmlspecialchars($attachedRec['external_url']) ?>" target="_blank" class="btn btn-xs btn-outline-primary rounded-pill small">
                  <i class="bi bi-box-arrow-up-right me-1"></i>Listen Recording
                </a>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <div class="d-flex gap-2 flex-wrap mt-2">
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

  const recWrap = document.getElementById('feedCallRecordingWrap');
  if (recWrap) {
    recWrap.style.display = (type === 'call') ? 'block' : 'none';
  }
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

<!-- Add Call Recording Modal -->
<div class="modal fade" id="addRecordingModal" tabindex="-1" aria-labelledby="addRecordingModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="add_call_recording">

        <div class="modal-header bg-danger text-white py-3 rounded-top-4">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-mic-fill fs-5"></i>
            <div>
              <h5 class="modal-title fw-bold mb-0" id="addRecordingModalLabel">Add Call Recording</h5>
              <div class="small opacity-75">Lead: <?= htmlspecialchars($lead['name']) ?></div>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Call Title / Topic <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control" placeholder="e.g. Discovery Pitch Call, Pricing Negotiation" required>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Call Date & Time <span class="text-danger">*</span></label>
              <input type="datetime-local" name="call_date" class="form-control form-control-sm" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Call Duration</label>
              <input type="text" name="duration" class="form-control form-control-sm" placeholder="e.g. 03:45 or 225">
              <div class="form-text small" style="font-size:0.7rem;">Enter mm:ss or seconds</div>
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Call Direction</label>
              <select name="call_type" class="form-select form-select-sm">
                <option value="outbound">Outbound (We Called)</option>
                <option value="inbound">Inbound (Client Called)</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Call Outcome</label>
              <select name="call_status" class="form-select form-select-sm">
                <option value="connected" selected>Connected</option>
                <option value="no_answer">No Answer</option>
                <option value="busy">Busy</option>
                <option value="voicemail">Voicemail / Message Left</option>
                <option value="wrong_number">Wrong Number</option>
              </select>
            </div>
          </div>

          <!-- Audio File Upload -->
          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">
              <i class="bi bi-file-earmark-music text-danger me-1"></i> Upload Audio Recording File
            </label>
            <input type="file" name="audio_file" class="form-control" accept="audio/*,.mp3,.wav,.m4a,.ogg,.aac,.webm,.amr,.mp4">
            <div class="form-text small">Supported formats: .mp3, .wav, .m4a, .ogg, .aac, .webm.</div>
          </div>

          <!-- Or External URL -->
          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">
              <i class="bi bi-link-45deg me-1"></i> Or External Recording URL (Optional)
            </label>
            <input type="url" name="external_url" class="form-control form-control-sm" placeholder="https://cloud-telephony-link... / Google Drive">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Call Discussion Notes / Summary</label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Key topics discussed, objections raised, agreed action items..."></textarea>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Update Lead Status (Optional)</label>
              <select name="status_updated" class="form-select form-select-sm">
                <option value="">-- Keep Current (<?= ucwords(str_replace('_',' ',$lead['status'])) ?>) --</option>
                <option value="contacted">Contacted</option>
                <option value="interested">Interested</option>
                <option value="follow_up">Follow Up</option>
                <option value="not_interested">Not Interested</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Next Follow-up Date (Optional)</label>
              <input type="date" name="next_followup" class="form-control form-control-sm" min="<?= date('Y-m-d') ?>">
            </div>
          </div>

          <div class="card bg-light border p-3 rounded-3">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="add_to_timeline" value="1" id="addToTimelineCheck" checked>
              <label class="form-check-label fw-semibold text-dark small" for="addToTimelineCheck">
                <i class="bi bi-clock-history me-1 text-primary"></i> Also log this call in Response History timeline
              </label>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light py-3 px-4 rounded-bottom-4">
          <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm px-4 fw-semibold shadow-sm">
            <i class="bi bi-upload me-1"></i> Save Call Recording
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
