<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('outreach');

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
$isTelecall = isRole('telecall');
$telecallProjectIds = $isTelecall ? getTelecallProjectIds() : [];

$outreach = null;
if ($id) {
    $stmt = $db->prepare("SELECT * FROM email_outreaches WHERE id = ?");
    $stmt->execute([$id]);
    $outreach = $stmt->fetch();
    if (!$outreach) {
        setFlash('error', 'Email outreach record not found.');
        redirect(BASE_URL . '/modules/outreach/index.php');
    }
    if ($isTelecall && !in_array((int)$outreach['project_id'], $telecallProjectIds)) {
        setFlash('error', 'You do not have permission to view or edit this outreach record.');
        redirect(BASE_URL . '/modules/outreach/index.php');
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $projectId   = (int)($_POST['project_id'] ?? 0);
    $contactName = trim($_POST['contact_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $company     = trim($_POST['company'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $website     = trim($_POST['website'] ?? '');
    $linkedinUrl = trim($_POST['linkedin_url'] ?? '');
    $country     = trim($_POST['country'] ?? '');
    $city        = trim($_POST['city'] ?? '');
    $campaign    = trim($_POST['campaign_name'] ?? '');
    $subject     = trim($_POST['email_subject'] ?? '');
    $outreachDate= trim($_POST['outreach_date'] ?? date('Y-m-d'));
    $status      = $_POST['status'] ?? 'sent';
    $notes       = trim($_POST['notes'] ?? '');
    $assignedTo  = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;

    // Response fields
    $responseType    = ($status === 'replied') ? ($_POST['response_type'] ?? 'none') : 'none';
    $responseSummary = ($status === 'replied') ? trim($_POST['response_summary'] ?? '') : null;
    $repliedAt       = ($status === 'replied' && !empty($_POST['replied_at'])) ? $_POST['replied_at'] : null;
    $meetingDate     = ($status === 'replied' && !empty($_POST['meeting_date'])) ? $_POST['meeting_date'] : null;
    $nextFollowupDate= !empty($_POST['next_followup_date']) ? $_POST['next_followup_date'] : null;

    // Validation
    if (!$projectId || !$contactName || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('error', 'Please provide a valid Project, Contact Name, and Email address.');
        redirect(BASE_URL . '/modules/outreach/save.php' . ($id ? "?id={$id}" : ''));
    }

    if ($isTelecall && !in_array($projectId, $telecallProjectIds)) {
        setFlash('error', 'You are not assigned to this project.');
        redirect(BASE_URL . '/modules/outreach/index.php');
    }

    try {
        if ($id) {
            // Update
            $sql = "UPDATE email_outreaches SET
                        project_id = ?, contact_name = ?, email = ?, phone = ?, company = ?, designation = ?,
                        website = ?, linkedin_url = ?, country = ?, city = ?, campaign_name = ?, email_subject = ?,
                        outreach_date = ?, status = ?, response_type = ?, response_summary = ?, replied_at = ?,
                        meeting_date = ?, next_followup_date = ?, notes = ?, assigned_to = ?, updated_at = NOW()
                    WHERE id = ?";
            $db->prepare($sql)->execute([
                $projectId, $contactName, $email, $phone, $company, $designation,
                $website, $linkedinUrl, $country, $city, $campaign, $subject,
                $outreachDate, $status, $responseType, $responseSummary, $repliedAt,
                $meetingDate, $nextFollowupDate, $notes, $assignedTo, $id
            ]);

            // Add edit activity
            $db->prepare("INSERT INTO email_outreach_activities (outreach_id, activity_type, title, notes, created_by) VALUES (?, 'note', 'Outreach details updated', ?, ?)")
               ->execute([$id, "Updated outreach info and status to " . ucfirst($status), $user['id']]);

            setFlash('success', 'Outreach record updated successfully.');
            redirect(BASE_URL . '/modules/outreach/view.php?id=' . $id);
        } else {
            // Insert
            $code = generateCode('OUT', 'email_outreaches', 'outreach_code');
            $sql = "INSERT INTO email_outreaches (
                        outreach_code, project_id, contact_name, email, phone, company, designation,
                        website, linkedin_url, country, city, campaign_name, email_subject, outreach_date,
                        first_sent_at, status, response_type, response_summary, replied_at, meeting_date,
                        next_followup_date, notes, assigned_to, created_by
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?,?)";
            $db->prepare($sql)->execute([
                $code, $projectId, $contactName, $email, $phone, $company, $designation,
                $website, $linkedinUrl, $country, $city, $campaign, $subject, $outreachDate,
                $status, $responseType, $responseSummary, $repliedAt, $meetingDate,
                $nextFollowupDate, $notes, $assignedTo, $user['id']
            ]);
            $newId = (int)$db->lastInsertId();

            // Initial activity log
            $db->prepare("INSERT INTO email_outreach_activities (outreach_id, activity_type, title, notes, created_by) VALUES (?, 'initial_email', ?, ?, ?)")
               ->execute([$newId, "Initial cold email recorded: " . ($subject ?: 'Cold Outreach'), $notes ?: 'First cold email outreach sent to contact.', $user['id']]);

            setFlash('success', "Outreach record created successfully. Code: {$code}");
            redirect(BASE_URL . '/modules/outreach/view.php?id=' . $newId);
        }
    } catch (\Throwable $e) {
        setFlash('error', 'Database Error: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/outreach/save.php' . ($id ? "?id={$id}" : ''));
    }
}

// Data for dropdowns
if ($isTelecall && $telecallProjectIds) {
    $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
    $pStmt = $db->prepare("SELECT id, name FROM projects WHERE status='active' AND id IN ($in) ORDER BY name");
    $pStmt->execute($telecallProjectIds);
    $projects = $pStmt->fetchAll();
} else {
    $projects = $db->query("SELECT id, name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
}

$agents = $db->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();

$d = $outreach ?? [
    'project_id' => '', 'contact_name' => '', 'email' => '', 'phone' => '',
    'company' => '', 'designation' => '', 'website' => '', 'linkedin_url' => '',
    'country' => 'India', 'city' => '', 'campaign_name' => '', 'email_subject' => '',
    'outreach_date' => date('Y-m-d'), 'status' => 'sent', 'response_type' => 'none',
    'response_summary' => '', 'replied_at' => '', 'meeting_date' => '',
    'next_followup_date' => '', 'notes' => '', 'assigned_to' => $user['id']
];

$pageTitle = $id ? 'Edit Cold Email Outreach' : 'Record New Email Outreach';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/outreach/index.php" class="btn btn-sm btn-outline-secondary rounded-circle" style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="mb-0 fw-bold"><?= $id ? 'Edit Outreach Record' : 'Record New Email Outreach' ?></h4>
    <p class="text-muted small mb-0"><?= $id ? ('Outreach Code: ' . htmlspecialchars($outreach['outreach_code'])) : 'Log a cold email connection with a prospect' ?></p>
  </div>
</div>

<?php displayFlash(); ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

  <div class="row g-4">
    <!-- Left Column: Primary Contact & Project -->
    <div class="col-lg-8">
      <!-- Target Project & Campaign Card -->
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom">
          <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
            <i class="bi bi-folder2 text-primary"></i> 1. Target Project & Campaign Specification
          </h6>
        </div>
        <div class="card-body p-4">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">
                Project Connected For <span class="text-danger">*</span>
              </label>
              <select name="project_id" class="form-select" required>
                <option value="">-- Select Project --</option>
                <?php foreach ($projects as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (int)$d['project_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($p['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text small">Specify which product or offering was pitched in this outreach.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Campaign / Outreach Batch</label>
              <input type="text" name="campaign_name" class="form-control" placeholder="e.g. Q4 Founders Cold List, Tech Outbound" value="<?= htmlspecialchars($d['campaign_name']) ?>">
              <div class="form-text small">Optional group tag to analyze response rates by batch.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Prospect Contact Card -->
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom">
          <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
            <i class="bi bi-person-lines-fill text-primary"></i> 2. Prospect & Company Information
          </h6>
        </div>
        <div class="card-body p-4">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Contact Person Name <span class="text-danger">*</span></label>
              <input type="text" name="contact_name" class="form-control" placeholder="Full name of recipient" value="<?= htmlspecialchars($d['contact_name']) ?>" required>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" placeholder="name@company.com" value="<?= htmlspecialchars($d['email']) ?>" required>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Phone / WhatsApp</label>
              <input type="text" name="phone" class="form-control" placeholder="Optional or when replying" value="<?= htmlspecialchars($d['phone']) ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Company / Organization</label>
              <input type="text" name="company" class="form-control" placeholder="Company name" value="<?= htmlspecialchars($d['company']) ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Designation / Role</label>
              <input type="text" name="designation" class="form-control" placeholder="e.g. CEO, Marketing Head, CTO" value="<?= htmlspecialchars($d['designation']) ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Website URL</label>
              <input type="url" name="website" class="form-control" placeholder="https://example.com" value="<?= htmlspecialchars($d['website']) ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">LinkedIn Profile</label>
              <input type="url" name="linkedin_url" class="form-control" placeholder="https://linkedin.com/in/..." value="<?= htmlspecialchars($d['linkedin_url']) ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label fw-semibold small text-dark">City</label>
              <input type="text" name="city" class="form-control" placeholder="City" value="<?= htmlspecialchars($d['city']) ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label fw-semibold small text-dark">Country</label>
              <input type="text" name="country" class="form-control" placeholder="Country" value="<?= htmlspecialchars($d['country']) ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Cold Email Content & Notes -->
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom">
          <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
            <i class="bi bi-chat-text text-primary"></i> 3. Outreach Message & Context
          </h6>
        </div>
        <div class="card-body p-4">
          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Email Subject Line</label>
            <input type="text" name="email_subject" class="form-control" placeholder="e.g. Quick question regarding SEO for your platform" value="<?= htmlspecialchars($d['email_subject']) ?>">
          </div>

          <div class="mb-0">
            <label class="form-label fw-semibold small text-dark">Outreach Pitch / Notes / Email Body Snippet</label>
            <textarea name="notes" class="form-control" rows="4" placeholder="Brief outline of the pitch made, personalizations used, or specific angles tested..."><?= htmlspecialchars($d['notes']) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Column: Status & Response Tracking -->
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom">
          <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
            <i class="bi bi-sliders text-primary"></i> Status & Tracking
          </h6>
        </div>
        <div class="card-body p-4">
          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Outreach Sent Date <span class="text-danger">*</span></label>
            <input type="date" name="outreach_date" class="form-control" value="<?= htmlspecialchars($d['outreach_date']) ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Outreach Status <span class="text-danger">*</span></label>
            <select name="status" id="outreach_status_select" class="form-select" onchange="toggleFormResponseSection()">
              <option value="sent" <?= $d['status'] === 'sent' ? 'selected' : '' ?>>Waiting for Reply (Sent)</option>
              <option value="replied" <?= $d['status'] === 'replied' ? 'selected' : '' ?>>Replied</option>
              <option value="bounced" <?= $d['status'] === 'bounced' ? 'selected' : '' ?>>Bounced / Invalid</option>
              <option value="not_interested" <?= $d['status'] === 'not_interested' ? 'selected' : '' ?>>Not Interested</option>
              <?php if (!empty($d['converted_lead_id']) || $d['status'] === 'converted'): ?>
              <option value="converted" selected>Converted to Lead</option>
              <?php endif; ?>
            </select>
          </div>

          <!-- Dynamic Response Section -->
          <div id="form_response_section" class="border rounded-3 p-3 bg-light mb-3" style="display: <?= $d['status'] === 'replied' ? 'block' : 'none' ?>;">
            <h6 class="fw-bold text-primary small mb-2"><i class="bi bi-chat-heart-fill me-1"></i> Response Details</h6>

            <div class="mb-3">
              <label class="form-label fw-semibold small text-dark">Response Outcome</label>
              <select name="response_type" id="form_response_type" class="form-select form-select-sm" onchange="toggleFormMeetingField()">
                <option value="none">-- Select Response Type --</option>
                <option value="meeting_scheduled" <?= $d['response_type'] === 'meeting_scheduled' ? 'selected' : '' ?>>Meeting Scheduled</option>
                <option value="demo_requested" <?= $d['response_type'] === 'demo_requested' ? 'selected' : '' ?>>Demo Asked / Requested</option>
                <option value="interested_more_info" <?= $d['response_type'] === 'interested_more_info' ? 'selected' : '' ?>>Interested / Seeking Details</option>
                <option value="pricing_inquiry" <?= $d['response_type'] === 'pricing_inquiry' ? 'selected' : '' ?>>Pricing Inquiry</option>
                <option value="call_back_later" <?= $d['response_type'] === 'call_back_later' ? 'selected' : '' ?>>Follow-up Later</option>
                <option value="referral" <?= $d['response_type'] === 'referral' ? 'selected' : '' ?>>Referral to Colleague</option>
                <option value="not_interested" <?= $d['response_type'] === 'not_interested' ? 'selected' : '' ?>>Not Interested</option>
                <option value="other" <?= $d['response_type'] === 'other' ? 'selected' : '' ?>>Other</option>
              </select>
            </div>

            <div class="mb-3" id="form_meeting_wrapper" style="display: <?= in_array($d['response_type'], ['meeting_scheduled', 'demo_requested']) ? 'block' : 'none' ?>;">
              <label class="form-label fw-semibold small text-success">
                <i class="bi bi-calendar-event me-1"></i> Meeting / Demo Scheduled Time
              </label>
              <input type="datetime-local" name="meeting_date" class="form-control form-control-sm" value="<?= $d['meeting_date'] ? date('Y-m-d\TH:i', strtotime($d['meeting_date'])) : '' ?>">
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold small text-dark">Reply Summary</label>
              <textarea name="response_summary" class="form-control form-control-sm" rows="3" placeholder="What did they say in their response?"><?= htmlspecialchars($d['response_summary'] ?? '') ?></textarea>
            </div>

            <div class="mb-0">
              <label class="form-label fw-semibold small text-dark">Replied At</label>
              <input type="datetime-local" name="replied_at" class="form-control form-control-sm" value="<?= $d['replied_at'] ? date('Y-m-d\TH:i', strtotime($d['replied_at'])) : date('Y-m-d\TH:i') ?>">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Next Follow-up Date</label>
            <input type="date" name="next_followup_date" class="form-control" value="<?= htmlspecialchars($d['next_followup_date'] ?? '') ?>">
            <div class="form-text small">Scheduled date to ping or follow up.</div>
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold small text-dark">Assigned Team Member</label>
            <select name="assigned_to" class="form-select">
              <option value="">Unassigned</option>
              <?php foreach ($agents as $ag): ?>
              <option value="<?= $ag['id'] ?>" <?= (int)$d['assigned_to'] === (int)$ag['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($ag['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary fw-semibold py-2 rounded-3 shadow-sm">
              <i class="bi bi-check2-circle me-1"></i> <?= $id ? 'Update Outreach Record' : 'Save Outreach Record' ?>
            </button>
            <a href="<?= BASE_URL ?>/modules/outreach/index.php" class="btn btn-outline-secondary py-2 rounded-3">
              Cancel
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<script>
function toggleFormResponseSection() {
  const status = document.getElementById('outreach_status_select').value;
  const section = document.getElementById('form_response_section');
  if (status === 'replied') {
    section.style.display = 'block';
  } else {
    section.style.display = 'none';
  }
}

function toggleFormMeetingField() {
  const type = document.getElementById('form_response_type').value;
  const wrapper = document.getElementById('form_meeting_wrapper');
  if (type === 'meeting_scheduled' || type === 'demo_requested') {
    wrapper.style.display = 'block';
  } else {
    wrapper.style.display = 'none';
  }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
