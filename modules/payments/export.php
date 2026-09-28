<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('payments');

$db = getDB();

$tab         = trim($_GET['tab'] ?? 'collections');
$fromDate    = trim($_GET['from_date'] ?? '');
$toDate      = trim($_GET['to_date'] ?? '');
$paymentMode = trim($_GET['payment_mode'] ?? '');
$search      = trim($_GET['q'] ?? '');

if ($tab === 'payroll') {
    // Export Payroll Disbursements
    $pSql = "SELECT sp.*, e.name as employee_name, e.emp_number, e.designation, u.name as recorder_name
             FROM salary_payments sp
             JOIN employees e ON e.id = sp.employee_id
             LEFT JOIN users u ON u.id = sp.created_by
             WHERE sp.status = 'paid'";
    $pParams = [];

    if ($fromDate) {
        $pSql .= " AND sp.payment_date >= ?";
        $pParams[] = $fromDate;
    }
    if ($toDate) {
        $pSql .= " AND sp.payment_date <= ?";
        $pParams[] = $toDate;
    }
    if ($paymentMode) {
        $pSql .= " AND sp.payment_mode = ?";
        $pParams[] = $paymentMode;
    }
    if ($search) {
        $pSql .= " AND (sp.slip_number LIKE ? OR e.name LIKE ? OR sp.transaction_ref LIKE ?)";
        $pParams = array_merge($pParams, ["%$search%", "%$search%", "%$search%"]);
    }

    $pSql .= " ORDER BY sp.payment_date DESC, sp.id DESC";
    $pStmt = $db->prepare($pSql);
    $pStmt->execute($pParams);
    $rows = $pStmt->fetchAll();

    $filename = 'payroll_disbursements_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Slip Number', 'Employee Name', 'Emp Code', 'Designation', 'Month/Year', 'Payment Date', 'Gross Salary', 'Deductions', 'Net Salary', 'Payment Mode', 'Reference/UTR', 'Recorded By']);

    $monthNames = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];

    foreach ($rows as $r) {
        fputcsv($output, [
            $r['slip_number'],
            $r['employee_name'],
            $r['emp_number'],
            $r['designation'],
            ($monthNames[$r['month']] ?? $r['month']) . ' ' . $r['year'],
            date('Y-m-d', strtotime($r['payment_date'])),
            number_format((float)$r['gross_salary'], 2, '.', ''),
            number_format((float)$r['total_deductions'], 2, '.', ''),
            number_format((float)$r['net_salary'], 2, '.', ''),
            strtoupper(str_replace('_', ' ', $r['payment_mode'])),
            $r['transaction_ref'] ?? '',
            $r['recorder_name'] ?? ''
        ]);
    }
    fclose($output);
    exit;

} elseif ($tab === 'expenses') {
    // Export Company Expenses
    $eSql = "SELECT ex.*, cat.name as category_name, u.name as recorder_name
             FROM expenses ex
             LEFT JOIN expense_categories cat ON cat.id = ex.category_id
             LEFT JOIN users u ON u.id = ex.created_by
             WHERE 1=1";
    $eParams = [];

    if ($fromDate) {
        $eSql .= " AND ex.expense_date >= ?";
        $eParams[] = $fromDate;
    }
    if ($toDate) {
        $eSql .= " AND ex.expense_date <= ?";
        $eParams[] = $toDate;
    }
    if ($paymentMode) {
        $eSql .= " AND ex.payment_mode = ?";
        $eParams[] = $paymentMode;
    }
    if ($search) {
        $eSql .= " AND (ex.title LIKE ? OR cat.name LIKE ? OR ex.reference_no LIKE ? OR ex.vendor_name LIKE ?)";
        $eParams = array_merge($eParams, ["%$search%", "%$search%", "%$search%", "%$search%"]);
    }

    $eSql .= " ORDER BY ex.expense_date DESC, ex.id DESC";
    $eStmt = $db->prepare($eSql);
    $eStmt->execute($eParams);
    $rows = $eStmt->fetchAll();

    $filename = 'company_expenses_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Expense Date', 'Title', 'Category', 'Vendor', 'Amount', 'Payment Mode', 'Reference No', 'Notes', 'Recorded By']);

    foreach ($rows as $r) {
        fputcsv($output, [
            date('Y-m-d', strtotime($r['expense_date'])),
            $r['title'],
            $r['category_name'] ?? 'Uncategorized',
            $r['vendor_name'] ?? '',
            number_format((float)$r['amount'], 2, '.', ''),
            strtoupper(str_replace('_', ' ', $r['payment_mode'])),
            $r['reference_no'] ?? '',
            $r['notes'] ?? '',
            $r['recorder_name'] ?? ''
        ]);
    }
    fclose($output);
    exit;

} else {
    // Export Client Collections (Default)
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

    $filename = 'client_collections_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

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
}

