<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('leads');
verifyCsrf();

$db   = getDB();
$user = currentUser();

if (empty($_FILES['csv_file']['tmp_name'])) {
    setFlash('error', 'No CSV file uploaded.');
    redirect(BASE_URL . '/modules/leads/index.php');
}

$file = $_FILES['csv_file']['tmp_name'];
$handle = fopen($file, 'r');
if (!$handle) {
    setFlash('error', 'Could not read file.');
    redirect(BASE_URL . '/modules/leads/index.php');
}

// Read header row and normalize
$rawHeaders = fgetcsv($handle);
if (!$rawHeaders) {
    setFlash('error', 'CSV file is empty or unreadable.');
    redirect(BASE_URL . '/modules/leads/index.php');
}
$headers = array_map(fn($h) => strtolower(trim(str_replace([' ','-'], '_', $h))), $rawHeaders);

// Validate mandatory columns
$required = ['name', 'phone', 'project_code'];
$missing  = array_diff($required, $headers);
if ($missing) {
    setFlash('error', 'Missing required columns: ' . implode(', ', $missing) . '. Required: name, phone, project_code.');
    redirect(BASE_URL . '/modules/leads/index.php');
}

// Load lookup maps
$projects = $db->query("SELECT id, code FROM projects")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
// Also try by name
$projectsByName = $db->query("SELECT id, name FROM projects")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$regions = $db->query("SELECT id, LOWER(name) as name FROM regions WHERE status='active'")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
// Flip: name => id
$regionMap = array_flip($regions);

function findProject(array $projects, array $byName, string $code): ?int {
    if (isset($projects[$code])) return (int)$projects[$code];
    // Try case-insensitive name match
    foreach ($byName as $id => $name) {
        if (strcasecmp($name, $code) === 0) return (int)$id;
    }
    return null;
}

$imported = 0;
$skipped  = 0;
$errors   = [];
$rowNum   = 1;

$validSources  = ['website','referral','social_media','cold_call','email','exhibition','other'];
$validStatuses = ['new','contacted','interested','not_interested','follow_up'];

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($row) < 3) { $skipped++; continue; }

    $data = [];
    foreach ($headers as $i => $h) {
        $data[$h] = trim($row[$i] ?? '');
    }

    $name  = $data['name'] ?? '';
    $phone = $data['phone'] ?? '';
    $pCode = $data['project_code'] ?? '';

    if (!$name || !$phone || !$pCode) {
        $errors[] = "Row $rowNum: name, phone, and project_code are required.";
        $skipped++;
        continue;
    }

    $projectId = findProject($projects, $projectsByName, $pCode);
    if (!$projectId) {
        $errors[] = "Row $rowNum: Project '$pCode' not found.";
        $skipped++;
        continue;
    }

    $regionId = null;
    if (!empty($data['region_name'])) {
        $regionId = $regionMap[strtolower($data['region_name'])] ?? null;
    }

    $source = in_array($data['source'] ?? '', $validSources) ? $data['source'] : 'other';
    $status = in_array($data['status'] ?? '', $validStatuses) ? $data['status'] : 'new';

    $code = generateCode('LD', 'leads', 'lead_code');
    $db->prepare("INSERT INTO leads (lead_code,project_id,region_id,name,email,phone,company,designation,website,address,source,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([
           $code, $projectId, $regionId,
           $name,
           $data['email'] ?? '',
           $phone,
           $data['company'] ?? '',
           $data['designation'] ?? '',
           $data['website'] ?? '',
           $data['address'] ?? '',
           $source,
           $status,
           $data['notes'] ?? ''
       ]);
    $imported++;
}
fclose($handle);

$msg = "$imported lead(s) imported successfully.";
if ($skipped) $msg .= " $skipped row(s) skipped.";
if ($errors) $msg .= ' Issues: ' . implode('; ', array_slice($errors, 0, 3)) . (count($errors) > 3 ? '...' : '');

setFlash($errors || $skipped ? 'warning' : 'success', $msg);
redirect(BASE_URL . '/modules/leads/index.php');
