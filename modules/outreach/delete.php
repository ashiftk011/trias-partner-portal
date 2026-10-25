<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('outreach');
verifyCsrf();

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    redirect(BASE_URL . '/modules/outreach/index.php');
}

$db = getDB();
$user = currentUser();

// Only admin or creator can delete
$stmt = $db->prepare("SELECT * FROM email_outreaches WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    setFlash('error', 'Outreach record not found.');
    redirect(BASE_URL . '/modules/outreach/index.php');
}

if (!isRole('admin') && (int)$row['created_by'] !== (int)$user['id']) {
    setFlash('error', 'You do not have permission to delete this record.');
    redirect(BASE_URL . '/modules/outreach/index.php');
}

try {
    // Unlink lead outreach_id if any
    $db->prepare("UPDATE leads SET outreach_id = NULL WHERE outreach_id = ?")->execute([$id]);
    // Delete outreach (activities cascade delete)
    $db->prepare("DELETE FROM email_outreaches WHERE id = ?")->execute([$id]);

    setFlash('success', 'Outreach record deleted successfully.');
} catch (\Throwable $e) {
    setFlash('error', 'Delete failed: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/outreach/index.php');
