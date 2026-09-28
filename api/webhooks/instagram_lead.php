<?php
/**
 * Instagram & Meta Lead Ads Webhook API Endpoint
 * 
 * Automatically captures leads submitted via Instagram Ads, Facebook Lead Ads,
 * Zapier, Make.com, Pabbly Connect, or custom Webhooks.
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

$db = getDB();

// 1. Meta Webhook Verification Handshake (GET Request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hubMode        = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $hubChallenge   = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    $hubVerifyToken = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';

    if ($hubMode === 'subscribe' && !empty($hubChallenge)) {
        // Return Meta verification challenge directly
        echo $hubChallenge;
        exit;
    }

    echo json_encode([
        'status'  => 'online',
        'service' => 'Instagram Lead Ads Webhook API',
        'usage'   => 'POST lead JSON payload to this endpoint'
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
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $input = array_merge($input, $decoded);
    }
}

// Map flexible key names from Instagram/Meta/Zapier/Make
$name = trim($input['name'] ?? $input['full_name'] ?? (($input['first_name'] ?? '') . ' ' . ($input['last_name'] ?? '')));
$phone = trim($input['phone'] ?? $input['phone_number'] ?? $input['mobile'] ?? $input['contact'] ?? '');
$email = trim($input['email'] ?? $input['email_address'] ?? '');
$company = trim($input['company'] ?? $input['company_name'] ?? $input['business_name'] ?? '');
$designation = trim($input['designation'] ?? $input['job_title'] ?? '');
$website = trim($input['website'] ?? '');
$address = trim($input['address'] ?? $input['city'] ?? '');
$source = !empty($input['source']) ? trim($input['source']) : 'social_media';

// Project mapping (project_id or fallback to default first active project)
$projectId = (int)($input['project_id'] ?? 0);
if ($projectId <= 0 && !empty($input['project_name'])) {
    $pStmt = $db->prepare("SELECT id FROM projects WHERE name LIKE ? LIMIT 1");
    $pStmt->execute(['%' . trim($input['project_name']) . '%']);
    $projectId = (int)$pStmt->fetchColumn();
}
if ($projectId <= 0) {
    // Default to the first active project if not explicitly supplied
    $projectId = (int)$db->query("SELECT id FROM projects WHERE status='active' ORDER BY id ASC LIMIT 1")->fetchColumn();
}

// Region mapping if provided
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
        'message' => 'No active project found to assign this lead to. Please create a project or pass project_id.'
    ]);
    exit;
}

try {
    // Generate unique Lead Code
    $leadCode = generateCode('LD', 'leads', 'lead_code');

    // Insert lead into database
    $query = "INSERT INTO leads (
                lead_code, project_id, region_id, name, email,
                phone, company, designation, website, address,
                source, status, notes, created_at
              ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, NOW())";

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
        $notes
    ]);

    $insertedId = $db->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'success'   => true,
        'message'   => 'Instagram lead recorded automatically.',
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
