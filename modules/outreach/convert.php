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

$stmt = $db->prepare("SELECT o.*, p.name as project_name FROM email_outreaches o LEFT JOIN projects p ON p.id=o.project_id WHERE o.id = ?");
$stmt->execute([$id]);
$outreach = $stmt->fetch();

if (!$outreach) {
    setFlash('error', 'Outreach record not found.');
    redirect(BASE_URL . '/modules/outreach/index.php');
}

$isTelecall = isRole('telecall');
$telecallProjectIds = $isTelecall ? getTelecallProjectIds() : [];
if ($isTelecall && !in_array((int)$outreach['project_id'], $telecallProjectIds)) {
    setFlash('error', 'You do not have permission to convert this outreach.');
    redirect(BASE_URL . '/modules/outreach/index.php');
}

if (!empty($outreach['converted_lead_id'])) {
    setFlash('info', 'This outreach prospect has already been converted to a Lead.');
    redirect(BASE_URL . '/modules/leads/view.php?id=' . $outreach['converted_lead_id']);
}

// Handle conversion form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name        = trim($_POST['name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $company     = trim($_POST['company'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $website     = trim($_POST['website'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $projectId   = (int)($_POST['project_id'] ?? 0);
    $regionId    = !empty($_POST['region_id']) ? (int)$_POST['region_id'] : null;
    $planId      = !empty($_POST['interested_plan_id']) ? (int)$_POST['interested_plan_id'] : null;
    $status      = $_POST['status'] ?? 'interested';
    $assignedTo  = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
    $notes       = trim($_POST['notes'] ?? '');

    if (!$name || !$phone || !$projectId) {
        setFlash('error', 'Name, Phone number, and Project are required to create a Lead.');
        redirect(BASE_URL . '/modules/outreach/convert.php?id=' . $id);
    }

    try {
        $db->beginTransaction();

        $leadCode = generateCode('LD', 'leads', 'lead_code');
        $insertLead = $db->prepare("INSERT INTO leads (
            lead_code, outreach_id, project_id, region_id, name, email, phone,
            company, designation, website, address, source, status,
            assigned_to, interested_plan_id, notes, created_by, created_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");

        $insertLead->execute([
            $leadCode, $id, $projectId, $regionId, $name, $email, $phone,
            $company, $designation, $website, $address, 'email', $status,
            $assignedTo, $planId, $notes, $user['id']
        ]);
        $newLeadId = (int)$db->lastInsertId();

        // Update email_outreaches record
        $db->prepare("UPDATE email_outreaches SET converted_lead_id = ?, status = 'converted', updated_at = NOW() WHERE id = ?")
           ->execute([$newLeadId, $id]);

        // Insert outreach activity
        $db->prepare("INSERT INTO email_outreach_activities (outreach_id, activity_type, title, notes, created_by) VALUES (?, 'converted_to_lead', ?, ?, ?)")
           ->execute([$id, "Converted to Lead ({$leadCode})", "Lead record generated for project: " . ($outreach['project_name'] ?? 'Project'), $user['id']]);

        // Record initial response in lead timeline
        $leadTimelineNote = "Converted from Cold Email Outreach [{$outreach['outreach_code']}]. Response Outcome: " . ucwords(str_replace('_', ' ', $outreach['response_type']));
        if (!empty($outreach['response_summary'])) {
            $leadTimelineNote .= " — Reply: " . $outreach['response_summary'];
        }
        $db->prepare("INSERT INTO lead_responses (lead_id, response_type, response, response_by, status_updated) VALUES (?, 'email', ?, ?, ?)")
           ->execute([$newLeadId, $leadTimelineNote, $user['id'], $status]);

        // If outreach had a meeting or demo scheduled, create demo entry
        if (!empty($outreach['meeting_date']) && in_array($outreach['response_type'], ['meeting_scheduled', 'demo_requested'])) {
            try {
                $lastDemo = $db->query("SELECT demo_no FROM demos ORDER BY id DESC LIMIT 1")->fetchColumn();
                $demoNum  = $lastDemo && preg_match('/DMO-(\d+)/', $lastDemo, $m) ? (int)$m[1]+1 : 1;
                $demoNo   = 'DMO-' . str_pad($demoNum, 4, '0', STR_PAD_LEFT);
                $demoTitle = ($outreach['response_type'] === 'demo_requested' ? 'Product Demo: ' : 'Meeting: ') . $name . ($company ? " ({$company})" : '');

                $db->prepare("INSERT INTO demos (demo_no, title, project_id, lead_id, assigned_to, demo_type, scheduled_at, duration_mins, status, notes, created_by)
                             VALUES (?,?,?,?,?, 'online', ?, 45, 'scheduled', ?, ?)")
                   ->execute([
                       $demoNo, $demoTitle, $projectId, $newLeadId, $assignedTo,
                       $outreach['meeting_date'], "Converted from Outreach [{$outreach['outreach_code']}]. Response: " . ($outreach['response_summary'] ?? ''),
                       $user['id']
                   ]);
            } catch (\Throwable $ex) {}
        }

        $db->commit();

        setFlash('success', "Prospect successfully converted to Lead ({$leadCode})!");
        redirect(BASE_URL . '/modules/leads/view.php?id=' . $newLeadId);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', 'Lead conversion failed: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/outreach/convert.php?id=' . $id);
    }
}

// Data for form
if ($isTelecall && $telecallProjectIds) {
    $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
    $pStmt = $db->prepare("SELECT id, name FROM projects WHERE status='active' AND id IN ($in) ORDER BY name");
    $pStmt->execute($telecallProjectIds);
    $projects = $pStmt->fetchAll();
} else {
    $projects = $db->query("SELECT id, name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
}

$regions = $db->query("SELECT id, name FROM regions WHERE status='active' ORDER BY name")->fetchAll();
$plans   = $db->query("SELECT pl.*, p.name as project_name FROM plans pl LEFT JOIN projects p ON p.id=pl.project_id WHERE pl.status='active' ORDER BY p.name, pl.name")->fetchAll();
$agents  = $db->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();

// Pre-fill notes
$defaultNotes = "Converted from Cold Email Outreach [{$outreach['outreach_code']}].\n";
$defaultNotes .= "Outreach Date: " . date('M d, Y', strtotime($outreach['outreach_date'])) . "\n";
if ($outreach['response_type'] && $outreach['response_type'] !== 'none') {
    $defaultNotes .= "Response Outcome: " . ucwords(str_replace('_', ' ', $outreach['response_type'])) . "\n";
}
if (!empty($outreach['response_summary'])) {
    $defaultNotes .= "Prospect Feedback: " . $outreach['response_summary'] . "\n";
}
if (!empty($outreach['meeting_date'])) {
    $defaultNotes .= "Agreed Meeting Time: " . date('M d, Y h:i A', strtotime($outreach['meeting_date'])) . "\n";
}
if (!empty($outreach['notes'])) {
    $defaultNotes .= "\nOriginal Outreach Pitch:\n" . $outreach['notes'];
}

$pageTitle = 'Convert Outreach to Lead';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/outreach/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary rounded-circle" style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="mb-0 fw-bold">Convert Outreach to Lead</h4>
    <p class="text-muted small mb-0">Prospect: <?= htmlspecialchars($outreach['contact_name']) ?> &bull; Project: <?= htmlspecialchars($outreach['project_name']) ?></p>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Outreach Context Banner -->
<div class="card border-0 bg-primary-subtle rounded-4 p-4 mb-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
      <div class="bg-primary text-white p-3 rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;">
        <i class="bi bi-person-check-fill fs-4"></i>
      </div>
      <div>
        <h6 class="mb-1 fw-bold text-primary-emphasis">
          Converting Outreach <?= htmlspecialchars($outreach['outreach_code']) ?>
        </h6>
        <div class="small text-muted">
          Pitched for <strong><?= htmlspecialchars($outreach['project_name']) ?></strong> &bull; Response: <?= outreachResponseBadge($outreach['response_type']) ?>
        </div>
      </div>
    </div>
    <div class="text-end">
      <span class="badge bg-white text-dark border px-3 py-2 rounded-pill small">
        <i class="bi bi-send me-1 text-primary"></i> Outreached on <?= date('M d, Y', strtotime($outreach['outreach_date'])) ?>
      </span>
    </div>
  </div>
</div>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

  <div class="row g-4">
    <!-- Left Column: Lead Details -->
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom">
          <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
            <i class="bi bi-person-lines-fill text-primary"></i> Lead Contact & Company Information
          </h6>
        </div>
        <div class="card-body p-4">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Contact Person Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($outreach['contact_name']) ?>" required>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Email Address</label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($outreach['email']) ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Phone Number <span class="text-danger">*</span></label>
              <input type="text" name="phone" class="form-control" placeholder="Required for lead follow-up" value="<?= htmlspecialchars($outreach['phone'] ?: '') ?>" required>
              <div class="form-text small">Required for telecalling & sales touchpoints.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Company / Business Name</label>
              <input type="text" name="company" class="form-control" value="<?= htmlspecialchars($outreach['company'] ?? '') ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Designation / Role</label>
              <input type="text" name="designation" class="form-control" value="<?= htmlspecialchars($outreach['designation'] ?? '') ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold small text-dark">Website URL</label>
              <input type="url" name="website" class="form-control" value="<?= htmlspecialchars($outreach['website'] ?? '') ?>">
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold small text-dark">Location / Address</label>
              <input type="text" name="address" class="form-control" placeholder="Street, City, State" value="<?= htmlspecialchars(implode(', ', array_filter([$outreach['city'], $outreach['country']]))) ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Lead Notes & Context -->
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom">
          <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
            <i class="bi bi-journal-text text-primary"></i> Outreach Context & Notes
          </h6>
        </div>
        <div class="card-body p-4">
          <div class="mb-0">
            <label class="form-label fw-semibold small text-dark">Lead Notes (Pre-filled with cold outreach response history)</label>
            <textarea name="notes" class="form-control" rows="6"><?= htmlspecialchars($defaultNotes) ?></textarea>
            <div class="form-text small">This history will be preserved on the Lead profile.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Column: Pipeline Assignment -->
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom">
          <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
            <i class="bi bi-funnel text-primary"></i> Pipeline & Assignment
          </h6>
        </div>
        <div class="card-body p-4">
          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Target Project <span class="text-danger">*</span></label>
            <select name="project_id" class="form-select" required>
              <?php foreach ($projects as $p): ?>
              <option value="<?= $p['id'] ?>" <?= (int)$outreach['project_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text small">Project pitched during cold outreach.</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Region</label>
            <select name="region_id" class="form-select">
              <option value="">-- Select Region (Optional) --</option>
              <?php foreach ($regions as $r): ?>
              <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Interested Plan</label>
            <select name="interested_plan_id" class="form-select">
              <option value="">-- Select Plan (Optional) --</option>
              <?php foreach ($plans as $pl): ?>
              <option value="<?= $pl['id'] ?>">
                <?= htmlspecialchars($pl['project_name']) ?> - <?= htmlspecialchars($pl['name']) ?> (₹<?= number_format($pl['price']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Initial Lead Status <span class="text-danger">*</span></label>
            <select name="status" class="form-select" required>
              <option value="interested" selected>Interested</option>
              <option value="contacted">Contacted</option>
              <option value="follow_up">Follow Up</option>
              <option value="new">New</option>
            </select>
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold small text-dark">Assigned Sales Agent</label>
            <select name="assigned_to" class="form-select">
              <option value="">Unassigned</option>
              <?php foreach ($agents as $ag): ?>
              <option value="<?= $ag['id'] ?>" <?= (int)($outreach['assigned_to'] ?: $user['id']) === (int)$ag['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($ag['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-success fw-bold py-2 rounded-3 shadow-sm">
              <i class="bi bi-person-check-fill me-1"></i> Confirm & Convert to Lead
            </button>
            <a href="<?= BASE_URL ?>/modules/outreach/view.php?id=<?= $id ?>" class="btn btn-outline-secondary py-2 rounded-3">
              Cancel
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
