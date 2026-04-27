<?php
/**
 * External Lead Capture API
 * 
 * Allows external forms to submit leads to the Partner Portal.
 * Returns JSON response.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow from any origin
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php'; // For generateCode()

$db = getDB();

// Get input data (handle both form-data and JSON)
$input = $_POST;
if (empty($input)) {
    $rawInput = file_get_contents('php://input');
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

// Required fields
$name      = trim($input['name'] ?? '');
$phone     = trim($input['phone'] ?? '');
$projectId = (int)($input['project_id'] ?? 0);

// Basic validation
if (empty($name) || empty($phone) || $projectId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Missing required fields: name, phone, and project_id are mandatory.'
    ]);
    exit;
}

try {
    // Check if project exists
    $stmt = $db->prepare("SELECT id FROM projects WHERE id = ?");
    $stmt->execute([$projectId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invalid project_id.']);
        exit;
    }

    // Optional fields
    $email       = trim($input['email'] ?? '');
    $company     = trim($input['company'] ?? '');
    $designation = trim($input['designation'] ?? '');
    $website     = trim($input['website'] ?? '');
    $address     = trim($input['address'] ?? '');
    $source      = trim($input['source'] ?? 'website');
    $notes       = trim($input['notes'] ?? '');
    $regionId    = isset($input['region_id']) ? (int)$input['region_id'] : null;
    $planId      = isset($input['interested_plan_id']) ? (int)$input['interested_plan_id'] : null;

    // Generate unique lead code
    $code = generateCode('LD', 'leads', 'lead_code');

    // Insert lead
    $query = "INSERT INTO leads (
                lead_code, project_id, region_id, name, email, 
                phone, company, designation, website, address, 
                source, status, interested_plan_id, notes
              ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?)";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        $code, $projectId, $regionId, $name, $email,
        $phone, $company, $designation, $website, $address,
        $source, $planId, $notes
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Lead captured successfully.',
        'lead_code' => $code
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while saving the lead: ' . $e->getMessage()
    ]);
}
