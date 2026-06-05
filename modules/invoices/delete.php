<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('invoices');

// Admin only
if (!isRole('admin')) {
    setFlash('error', 'Only administrators can delete invoices.');
    redirect(BASE_URL . '/modules/invoices/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/invoices/index.php');
}

verifyCsrf();

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid invoice.');
    redirect(BASE_URL . '/modules/invoices/index.php');
}

$db = getDB();

// Fetch invoice first to confirm it exists
$stmt = $db->prepare("SELECT id, invoice_no FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$inv = $stmt->fetch();

if (!$inv) {
    setFlash('error', 'Invoice not found.');
    redirect(BASE_URL . '/modules/invoices/index.php');
}

try {
    $db->beginTransaction();

    // Delete related payments
    $db->prepare("DELETE FROM payments WHERE invoice_id = ?")->execute([$id]);

    // Delete related line items
    $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);

    // Delete the invoice
    $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([$id]);

    $db->commit();

    setFlash('success', 'Invoice ' . htmlspecialchars($inv['invoice_no']) . ' has been deleted.');
} catch (Exception $e) {
    $db->rollBack();
    setFlash('error', 'Failed to delete invoice: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/invoices/index.php');
