<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('invoices');
header('Content-Type: application/json');

$clientId = (int)($_GET['client_id'] ?? 0);
if (!$clientId) { echo json_encode([]); exit; }

$stmt = getDB()->prepare("SELECT id,start_date,end_date,amount FROM renewals WHERE client_id=? AND status='active' ORDER BY end_date DESC");
$stmt->execute([$clientId]);
$rows = $stmt->fetchAll();

$result = [];
foreach ($rows as $r) {
    $result[] = [
        'id'     => $r['id'],
        'amount' => $r['amount'],
        'label'  => date('d M Y', strtotime($r['start_date'])) . ' → ' . date('d M Y', strtotime($r['end_date'])) . ' (₹' . number_format($r['amount'],2) . ')',
    ];
}
echo json_encode($result);
