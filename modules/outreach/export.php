<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('outreach');

$db = getDB();
$user = currentUser();
$isTelecall = isRole('telecall');
$telecallProjectIds = $isTelecall ? getTelecallProjectIds() : [];

$filterProject  = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$filterStatus   = $_GET['status'] ?? '';
$filterResponse = $_GET['response_type'] ?? '';
$filterSearch   = trim($_GET['q'] ?? '');
$filterCampaign = trim($_GET['campaign'] ?? '');

$sql = "SELECT o.*, p.name as project_name, u.name as assigned_name, l.lead_code
        FROM email_outreaches o
        LEFT JOIN projects p ON p.id = o.project_id
        LEFT JOIN users u ON u.id = o.assigned_to
        LEFT JOIN leads l ON l.id = o.converted_lead_id
        WHERE 1=1";
$params = [];

if ($isTelecall && $telecallProjectIds) {
    $in = implode(',', array_fill(0, count($telecallProjectIds), '?'));
    $sql .= " AND o.project_id IN ($in)";
    $params = array_merge($params, $telecallProjectIds);
} elseif ($isTelecall) {
    $sql .= " AND 1=0";
}

if ($filterProject) {
    $sql .= " AND o.project_id = ?";
    $params[] = $filterProject;
}
if ($filterStatus) {
    $sql .= " AND o.status = ?";
    $params[] = $filterStatus;
}
if ($filterResponse) {
    $sql .= " AND o.response_type = ?";
    $params[] = $filterResponse;
}
if ($filterCampaign) {
    $sql .= " AND o.campaign_name = ?";
    $params[] = $filterCampaign;
}
if ($filterSearch) {
    $sql .= " AND (o.contact_name LIKE ? OR o.email LIKE ? OR o.company LIKE ? OR o.outreach_code LIKE ?)";
    $term = "%{$filterSearch}%";
    $params = array_merge($params, [$term, $term, $term, $term]);
}

$sql .= " ORDER BY o.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=cold_email_outreaches_' . date('Y_m_d_His') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, [
    'Outreach Code', 'Contact Name', 'Email', 'Phone', 'Company', 'Designation',
    'Website', 'LinkedIn', 'Target Project', 'Campaign', 'Email Subject',
    'Outreach Date', 'Status', 'Response Outcome', 'Response Notes',
    'Meeting/Demo Date', 'Follow-up Date', 'Touches Count', 'Converted Lead Code', 'Assigned Agent'
]);

foreach ($rows as $r) {
    fputcsv($output, [
        $r['outreach_code'],
        $r['contact_name'],
        $r['email'],
        $r['phone'] ?? '',
        $r['company'] ?? '',
        $r['designation'] ?? '',
        $r['website'] ?? '',
        $r['linkedin_url'] ?? '',
        $r['project_name'] ?? '',
        $r['campaign_name'] ?? '',
        $r['email_subject'] ?? '',
        $r['outreach_date'],
        $r['status'],
        $r['response_type'],
        $r['response_summary'] ?? '',
        $r['meeting_date'] ?? '',
        $r['next_followup_date'] ?? '',
        (int)$r['followup_count'] + 1,
        $r['lead_code'] ?? '',
        $r['assigned_name'] ?? ''
    ]);
}

fclose($output);
exit;
