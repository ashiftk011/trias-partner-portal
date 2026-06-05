<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('leads');

// Admin only restriction
if (!isRole('admin')) {
    setFlash('error', 'Only administrators can delete leads.');
    redirect(BASE_URL . '/modules/leads/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/leads/index.php');
}

verifyCsrf();

$db = getDB();
$bulk = isset($_POST['bulk']) && $_POST['bulk'] == 1;

if ($bulk) {
    $leadIds = $_POST['lead_ids'] ?? [];
    if (empty($leadIds) || !is_array($leadIds)) {
        setFlash('error', 'No leads selected for deletion.');
        redirect(BASE_URL . '/modules/leads/index.php');
    }

    $leadIds = array_map('intval', $leadIds);
    $placeholders = implode(',', array_fill(0, count($leadIds), '?'));

    // Select leads matching these IDs and whose status is 'not_interested'
    $stmt = $db->prepare("SELECT id FROM leads WHERE id IN ($placeholders) AND status = 'not_interested'");
    $stmt->execute($leadIds);
    $validIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($validIds)) {
        setFlash('error', 'No valid "Not Interested" leads found for deletion.');
        redirect(BASE_URL . '/modules/leads/index.php');
    }

    try {
        $db->beginTransaction();

        $deletePlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $deleteStmt = $db->prepare("DELETE FROM leads WHERE id IN ($deletePlaceholders)");
        $deleteStmt->execute($validIds);

        $db->commit();

        $deletedCount = count($validIds);
        $totalRequested = count($leadIds);
        if ($deletedCount === $totalRequested) {
            setFlash('success', "Successfully deleted $deletedCount lead(s).");
        } else {
            $skippedCount = $totalRequested - $deletedCount;
            setFlash('warning', "Deleted $deletedCount lead(s). $skippedCount lead(s) were skipped because their status is not 'Not Interested'.");
        }
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Failed to delete leads: ' . $e->getMessage());
    }
} else {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        setFlash('error', 'Invalid lead ID.');
        redirect(BASE_URL . '/modules/leads/index.php');
    }

    $stmt = $db->prepare("SELECT id, name, status FROM leads WHERE id = ?");
    $stmt->execute([$id]);
    $lead = $stmt->fetch();

    if (!$lead) {
        setFlash('error', 'Lead not found.');
        redirect(BASE_URL . '/modules/leads/index.php');
    }

    if ($lead['status'] !== 'not_interested') {
        setFlash('error', 'Only leads with "Not Interested" status can be deleted.');
        redirect(BASE_URL . '/modules/leads/index.php');
    }

    try {
        $db->prepare("DELETE FROM leads WHERE id = ?")->execute([$id]);
        setFlash('success', 'Lead "' . htmlspecialchars($lead['name']) . '" has been successfully deleted.');
    } catch (Exception $e) {
        setFlash('error', 'Failed to delete lead: ' . $e->getMessage());
    }
}

redirect(BASE_URL . '/modules/leads/index.php');
