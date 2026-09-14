<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('payments');

$db = getDB();

$fromDate    = trim($_GET['from_date'] ?? '');
$toDate      = trim($_GET['to_date'] ?? '');
$paymentMode = trim($_GET['payment_mode'] ?? '');
$search      = trim($_GET['q'] ?? '');

$sql = "SELECT py.*, c.name as client_name, c.company, i.invoice_no, i.total_amount as invoice_total, i.currency, u.name as recorder_name
        FROM payments py
        LEFT JOIN clients c ON c.id = py.client_id
        LEFT JOIN invoices i ON i.id = py.invoice_id
        LEFT JOIN users u ON u.id = py.created_by
        WHERE 1=1";
$params = [];

if ($fromDate) {
    $sql .= " AND py.payment_date >= ?";
    $params[] = $fromDate;
}
if ($toDate) {
    $sql .= " AND py.payment_date <= ?";
    $params[] = $toDate;
}
if ($paymentMode) {
    $sql .= " AND py.payment_mode = ?";
    $params[] = $paymentMode;
}
if ($search) {
    $sql .= " AND (i.invoice_no LIKE ? OR c.name LIKE ? OR c.company LIKE ? OR py.transaction_id LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}

$sql .= " ORDER BY py.payment_date DESC, py.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$filename = 'payments_report_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Output CSV Header
fputcsv($output, [
    'Payment Date',
    'Invoice Number',
    'Client Name',
    'Company',
    'Invoice Total',
    'Paid Amount',
    'Payment Mode',
    'Transaction ID / Ref',
    'Notes',
    'Recorded By'
]);

// Output CSV Data Rows
foreach ($payments as $p) {
    fputcsv($output, [
        date('Y-m-d', strtotime($p['payment_date'])),
        $p['invoice_no'] ?? ('#' . $p['invoice_id']),
        $p['client_name'] ?? 'N/A',
        $p['company'] ?? '',
        number_format((float)($p['invoice_total'] ?? 0), 2, '.', ''),
        number_format((float)$p['amount'], 2, '.', ''),
        strtoupper($p['payment_mode']),
        $p['transaction_id'] ?? '',
        $p['notes'] ?? '',
        $p['recorder_name'] ?? ''
    ]);
}

fclose($output);
exit;
