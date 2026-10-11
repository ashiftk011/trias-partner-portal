<?php
/**
 * Meta (Facebook & Instagram Lead Ads) & Custom Webhook API Endpoint
 * 
 * Handshakes Meta webhook setup requests (GET)
 * Processes Meta real-time lead notifications (POST with leadgen_id)
 * Supports direct third-party lead webhooks (Zapier, Make, Pabbly)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php'; // For generateCode()
require_once __DIR__ . '/../../includes/meta_lead_service.php';

$db = getDB();
$metaService = new MetaLeadService($db);

// 1. Meta Webhook Verification Handshake (GET Request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hubMode        = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $hubChallenge   = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    $hubVerifyToken = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';

    if ($hubMode === 'subscribe' && !empty($hubChallenge)) {
        $expectedToken = $metaService->getVerifyToken();
        
        // Validate verify_token if expected token is set
        if (!empty($expectedToken) && $hubVerifyToken !== $expectedToken) {
            http_response_code(403);
            echo "Verification token mismatch.";
            exit;
        }

        // Return Meta verification challenge as plain text
        header('Content-Type: text/plain');
        echo $hubChallenge;
        exit;
    }

    echo json_encode([
        'status'  => 'online',
        'service' => 'Meta (Facebook & Instagram) Lead Ads Webhook API',
        'meta_configured' => $metaService->isConfigured(),
        'usage'   => 'POST lead JSON payload or Meta leadgen webhook to this endpoint'
    ]);
    exit;
}

// 2. Process Incoming Lead (POST Request)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

// Get input data (JSON or Form-Data)
$rawInput = file_get_contents('php://input');
$input = $_POST;
$decoded = null;
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $input = array_merge($input, $decoded);
    }
}

// Check if this is a Meta Graph API Webhook Event payload
// Meta sends payload structure: entry[0].changes[0].value.leadgen_id
$leadgenId = null;
if (isset($decoded['entry']) && is_array($decoded['entry'])) {
    foreach ($decoded['entry'] as $entry) {
        if (isset($entry['changes']) && is_array($entry['changes'])) {
            foreach ($entry['changes'] as $change) {
                if (isset($change['value']['leadgen_id'])) {
                    $leadgenId = (string)$change['value']['leadgen_id'];
                    break 2;
                }
            }
        }
    }
}

// Option A: Real-Time Meta Leadgen Webhook Payload
if ($leadgenId) {
    try {
        if (!$metaService->isConfigured()) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Received Meta webhook leadgen_id, but Meta Page Access Token is not configured in Settings.'
            ]);
            exit;
        }

        // Fetch lead details from Meta Graph API GET /{leadgen_id}
        $metaLead = $metaService->fetchSingleLead($leadgenId);

        // Save lead in database
        $result = $metaService->saveLead($metaLead);

        http_response_code(200);
        echo json_encode([
            'success'    => true,
            'meta_flow'  => true,
            'leadgen_id' => $leadgenId,
            'status'     => $result['status'],
            'lead_code'  => $result['lead_code'] ?? null,
            'message'    => $result['message']
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to process Meta lead: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Option B: Direct / Third-Party Payload (Zapier, Make, Custom Webhook)
$name = trim($input['name'] ?? $input['full_name'] ?? (($input['first_name'] ?? '') . ' ' . ($input['last_name'] ?? '')));
$phone = trim($input['phone'] ?? $input['phone_number'] ?? $input['mobile'] ?? $input['contact'] ?? '');
$email = trim($input['email'] ?? $input['email_address'] ?? '');
$company = trim($input['company'] ?? $input['company_name'] ?? $input['business_name'] ?? '');
$designation = trim($input['designation'] ?? $input['job_title'] ?? '');
$website = trim($input['website'] ?? '');
$address = trim($input['address'] ?? $input['city'] ?? '');
$source = !empty($input['source']) ? trim($input['source']) : 'social_media';

// Project mapping
$projectId = (int)($input['project_id'] ?? 0);
if ($projectId <= 0 && !empty($input['project_name'])) {
    $pStmt = $db->prepare("SELECT id FROM projects WHERE name LIKE ? LIMIT 1");
    $pStmt->execute(['%' . trim($input['project_name']) . '%']);
    $projectId = (int)$pStmt->fetchColumn();
}
if ($projectId <= 0) {
    $projectId = $metaService->getDefaultProjectId();
}

$regionId = isset($input['region_id']) ? (int)$input['region_id'] : null;

// Form / Ad details into Notes
$notes = trim($input['notes'] ?? '');
$formName = trim($input['form_name'] ?? $input['ad_name'] ?? $input['campaign_name'] ?? '');
if ($formName) {
    $notes = "[Instagram/Meta Ad: " . $formName . "] " . $notes;
}

// Validation
if (empty($name) || empty($phone)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: "name" (or full_name) and "phone" (or phone_number) are required.'
    ]);
    exit;
}

if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No active project found to assign this lead to. Please create a project or configure default project in Settings.'
    ]);
    exit;
}

try {
    $leadCode = generateCode('LD', 'leads', 'lead_code');
    $assignedTo = $metaService->getDefaultAssignedTo() > 0 ? $metaService->getDefaultAssignedTo() : null;

    $query = "INSERT INTO leads (
                lead_code, project_id, region_id, name, email,
                phone, company, designation, website, address,
                source, status, assigned_to, notes, created_at
              ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, NOW())";

    $stmt = $db->prepare($query);
    $stmt->execute([
        $leadCode,
        $projectId,
        $regionId,
        $name,
        $email,
        $phone,
        $company,
        $designation,
        $website,
        $address,
        $source,
        $assignedTo,
        $notes
    ]);

    $insertedId = $db->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'success'   => true,
        'message'   => 'Instagram/Meta lead recorded automatically.',
        'lead_id'   => (int)$insertedId,
        'lead_code' => $leadCode,
        'name'      => $name,
        'source'    => $source
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save lead: ' . $e->getMessage()
    ]);
}
