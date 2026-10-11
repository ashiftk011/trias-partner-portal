<?php
/**
 * Meta Leads Sync & Management API Endpoint
 * 
 * Supports:
 * - Direct manual/cron trigger for syncing Meta leads: ?action=sync
 * - Test connection & fetch page details: ?action=test
 * - Subscribe app to page webhooks: ?action=subscribe
 * - List leadgen forms: ?action=list_forms
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Cron-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/meta_lead_service.php';

// Session check or CRON key authorization
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$db = getDB();
$metaService = new MetaLeadService($db);

// Authorization check: User session (isLoggedIn / user_id) or valid Cron Key in header/query
$cronKey = $_GET['cron_key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$storedCronKey = $db->query("SELECT setting_value FROM app_settings WHERE setting_key='meta_verify_token'")->fetchColumn() ?: 'trias_meta_lead_verify_token';

$isAuthorized = isLoggedIn() || !empty($_SESSION['user_id']) || !empty($_SESSION['user']) || ($cronKey && $cronKey === $storedCronKey);

if (!$isAuthorized) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in to your portal session or provide a valid cron_key.']);
    exit;
}

$action = $_REQUEST['action'] ?? 'sync';

try {
    if ($action === 'test') {
        $pageInfo = $metaService->fetchPageInfo();
        echo json_encode([
            'success' => true,
            'message' => 'Successfully connected to Meta Graph API!',
            'page'    => $pageInfo
        ]);
        exit;
    }

    if ($action === 'list_forms') {
        $forms = $metaService->fetchLeadForms();
        echo json_encode([
            'success' => true,
            'count'   => count($forms),
            'forms'   => $forms
        ]);
        exit;
    }

    if ($action === 'subscribe') {
        $subRes = $metaService->subscribeAppToPage();
        echo json_encode([
            'success'  => true,
            'message'  => 'Successfully subscribed Facebook Page to App Webhooks!',
            'meta_res' => $subRes
        ]);
        exit;
    }

    if ($action === 'sync') {
        $summary = $metaService->syncAllLeads();
        echo json_encode([
            'success' => true,
            'message' => "Meta leads sync complete. {$summary['imported']} new leads imported, {$summary['duplicates']} duplicates skipped.",
            'summary' => $summary
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action specified.']);

} catch (Exception $e) {
    $msg = $e->getMessage();
    $isExpired = (str_contains(strtolower($msg), 'expired') || str_contains(strtolower($msg), 'token'));
    http_response_code(400);
    echo json_encode([
        'success'       => false,
        'token_expired' => $isExpired,
        'message'       => $msg
    ]);
}
