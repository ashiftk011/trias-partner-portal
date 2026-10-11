<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['error' => 'Invalid ID']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT c.*, p.default_invoice_terms 
                      FROM clients c 
                      LEFT JOIN projects p ON p.id = c.project_id 
                      WHERE c.id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    echo json_encode(['error' => 'Client not found']);
    exit;
}

if (isRole('csm') && (int)($client['created_by'] ?? 0) !== (int)($_SESSION['user_id'] ?? 0)) {
    echo json_encode(['error' => 'Client not found']);
    exit;
}

echo json_encode($client);
