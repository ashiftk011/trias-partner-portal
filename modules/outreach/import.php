<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('outreach');

$db = getDB();
$user = currentUser();

// 1. Download Sample CSV
if (isset($_GET['sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=cold_email_outreach_sample.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Contact Name', 'Email', 'Project Name', 'Company', 'Phone', 'Designation', 'Website', 'Campaign Name', 'Email Subject', 'Outreach Date', 'Notes']);
    fputcsv($output, ['John Smith', 'john@acme.corp', 'SEO Package', 'Acme Corp', '+91 9876543210', 'Founder & CEO', 'https://acme.corp', 'Q4 Founders Campaign', 'Quick idea for Acme search presence', date('Y-m-d'), 'Cold email sent highlighting 3 competitors ranking ahead.']);
    fputcsv($output, ['Sarah Connor', 'sarah@skynet.ai', 'Website Development', 'Cyberdyne', '+1 555-0199', 'VP Marketing', 'https://skynet.ai', 'Tech Startups Outbound', 'Redesign collaboration proposal', date('Y-m-d'), 'Emailed regarding modern redesign of company website.']);
    fclose($output);
    exit;
}

// 2. Handle CSV Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (empty($_FILES['csv_file']['tmp_name'])) {
        setFlash('error', 'Please choose a CSV file to upload.');
        redirect(BASE_URL . '/modules/outreach/import.php');
    }

    $defaultProjectId = !empty($_POST['default_project_id']) ? (int)$_POST['default_project_id'] : null;

    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle) {
        setFlash('error', 'Unable to read the uploaded CSV file.');
        redirect(BASE_URL . '/modules/outreach/import.php');
    }

    $rawHeaders = fgetcsv($handle);
    if (!$rawHeaders) {
        setFlash('error', 'The CSV file is empty.');
        redirect(BASE_URL . '/modules/outreach/import.php');
    }

    $headers = array_map(function($h) {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
        return strtolower(trim(str_replace([' ', '-', '_'], '', $h)));
    }, $rawHeaders);

    // Map projects by name and id
    $projectsByName = $db->query("SELECT id, name FROM projects")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    function resolveProject(array $projects, ?string $input, ?int $fallbackId): ?int {
        if (!empty($input)) {
            foreach ($projects as $id => $name) {
                if (strcasecmp(trim($name), trim($input)) === 0) {
                    return (int)$id;
                }
            }
        }
        return $fallbackId;
    }

    $imported = 0;
    $skipped  = 0;
    $rowNum   = 1;
    $errors   = [];

    while (($row = fgetcsv($handle)) !== false) {
        $rowNum++;
        if (count($row) < 2) {
            $skipped++;
            continue;
        }

        $data = [];
        foreach ($headers as $idx => $key) {
            $data[$key] = trim($row[$idx] ?? '');
        }

        $contactName = $data['contactname'] ?? $data['name'] ?? $data['fullname'] ?? '';
        $email = $data['email'] ?? $data['emailaddress'] ?? '';
        $projInput = $data['projectname'] ?? $data['project'] ?? '';
        $company = $data['company'] ?? $data['companyname'] ?? '';
        $phone = $data['phone'] ?? $data['phonenumber'] ?? $data['mobile'] ?? '';
        $designation = $data['designation'] ?? $data['role'] ?? $data['title'] ?? '';
        $website = $data['website'] ?? $data['url'] ?? '';
        $campaign = $data['campaignname'] ?? $data['campaign'] ?? ($_POST['default_campaign'] ?? '');
        $subject = $data['emailsubject'] ?? $data['subject'] ?? '';
        $outreachDate = !empty($data['outreachdate']) ? date('Y-m-d', strtotime($data['outreachdate'])) : date('Y-m-d');
        $notes = $data['notes'] ?? $data['message'] ?? '';

        if (!$contactName || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Row #{$rowNum}: Invalid name or email address.";
            $skipped++;
            continue;
        }

        $projectId = resolveProject($projectsByName, $projInput, $defaultProjectId);
        if (!$projectId) {
            $errors[] = "Row #{$rowNum}: Target project could not be determined. Please specify a Project or choose a Default Project.";
            $skipped++;
            continue;
        }

        try {
            $code = generateCode('OUT', 'email_outreaches', 'outreach_code');
            $db->prepare("INSERT INTO email_outreaches (
                outreach_code, project_id, contact_name, email, phone, company, designation,
                website, campaign_name, email_subject, outreach_date, status, notes, created_by
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,'sent',?,?)")
            ->execute([
                $code, $projectId, $contactName, $email, $phone, $company, $designation,
                $website, $campaign, $subject, $outreachDate, $notes, $user['id']
            ]);
            $newOutreachId = (int)$db->lastInsertId();

            $db->prepare("INSERT INTO email_outreach_activities (outreach_id, activity_type, title, notes, created_by) VALUES (?, 'initial_email', ?, ?, ?)")
               ->execute([$newOutreachId, "Imported cold outreach: " . ($subject ?: 'Cold Outreach'), $notes ?: 'Imported from CSV prospect list.', $user['id']]);

            $imported++;
        } catch (\Throwable $e) {
            $errors[] = "Row #{$rowNum}: " . $e->getMessage();
            $skipped++;
        }
    }
    fclose($handle);

    if ($imported > 0) {
        $msg = "Successfully imported {$imported} outreach prospect(s).";
        if ($skipped > 0) {
            $msg .= " ({$skipped} rows skipped due to missing data or validation errors).";
        }
        setFlash('success', $msg);
    } else {
        setFlash('error', "Import failed. 0 rows imported. " . implode(' ', array_slice($errors, 0, 3)));
    }

    redirect(BASE_URL . '/modules/outreach/index.php');
}

$projects = $db->query("SELECT id, name FROM projects WHERE status='active' ORDER BY name")->fetchAll();

$pageTitle = 'Import Cold Email Outreaches';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/outreach/index.php" class="btn btn-sm btn-outline-secondary rounded-circle" style="width:36px;height:36px;display:flex;align-items:center;justify-content:center;">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="mb-0 fw-bold">Import Cold Email Outreaches</h4>
    <p class="text-muted small mb-0">Upload a CSV list of cold outreach prospects and connect them to projects</p>
  </div>
</div>

<?php displayFlash(); ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm rounded-4 mb-4">
      <div class="card-header bg-white py-3 px-4 border-bottom">
        <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
          <i class="bi bi-file-earmark-arrow-up text-primary"></i> Upload CSV File
        </h6>
      </div>
      <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

          <div class="mb-4">
            <label class="form-label fw-semibold small text-dark">Select CSV File <span class="text-danger">*</span></label>
            <input type="file" name="csv_file" class="form-control" accept=".csv" required>
            <div class="form-text small">Standard .csv format UTF-8 encoded.</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small text-dark">Default Project (Used if Project column is empty)</label>
            <select name="default_project_id" class="form-select">
              <option value="">-- Let CSV specify project or select default --</option>
              <?php foreach ($projects as $p): ?>
              <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text small">Ensures every imported prospect has a designated project.</div>
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold small text-dark">Default Campaign Name</label>
            <input type="text" name="default_campaign" class="form-control" placeholder="e.g. November Tech Founder Batch">
          </div>

          <div class="d-flex justify-content-between align-items-center pt-2">
            <a href="?sample=1" class="btn btn-outline-primary btn-sm rounded-pill px-3">
              <i class="bi bi-download me-1"></i> Download Sample CSV Template
            </a>
            <button type="submit" class="btn btn-primary btn-sm rounded-pill px-4 fw-semibold shadow-sm">
              <i class="bi bi-upload me-1"></i> Import Prospects
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-light">
      <h6 class="fw-bold text-dark mb-3"><i class="bi bi-info-circle-fill text-primary me-2"></i> CSV Column Guidelines</h6>
      <p class="small text-muted mb-3">Your CSV file should have a header row with the following column names (case-insensitive):</p>

      <ul class="list-unstyled small mb-3">
        <li class="mb-2">
          <strong class="text-dark">Contact Name:</strong> <span class="badge bg-danger-subtle text-danger">Required</span><br>
          <span class="text-muted">Full name of the prospect.</span>
        </li>
        <li class="mb-2">
          <strong class="text-dark">Email:</strong> <span class="badge bg-danger-subtle text-danger">Required</span><br>
          <span class="text-muted">Valid email address of the prospect.</span>
        </li>
        <li class="mb-2">
          <strong class="text-dark">Project Name:</strong> <span class="badge bg-warning-subtle text-warning-emphasis">Recommended</span><br>
          <span class="text-muted">Matches one of your active projects (e.g. "SEO Package"). If blank, the Default Project selected above is used.</span>
        </li>
        <li class="mb-2">
          <strong class="text-dark">Company:</strong> <span class="badge bg-secondary-subtle text-secondary">Optional</span>
        </li>
        <li class="mb-2">
          <strong class="text-dark">Phone:</strong> <span class="badge bg-secondary-subtle text-secondary">Optional</span>
        </li>
        <li class="mb-2">
          <strong class="text-dark">Designation, Website, Campaign Name, Email Subject, Notes:</strong> <span class="badge bg-secondary-subtle text-secondary">Optional</span>
        </li>
      </ul>

      <div class="alert alert-info border-0 rounded-3 small mb-0">
        <i class="bi bi-lightbulb-fill me-1"></i>
        Once imported, all records will be marked as <strong>Waiting for Reply</strong> so you can track responses as they arrive!
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
