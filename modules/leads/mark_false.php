<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('leads');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/leads/index.php');
}

verifyCsrf();

$user = currentUser();
if (isRole('investor')) {
    setFlash('error', 'Investors do not have permission to modify leads.');
    redirect(BASE_URL . '/modules/leads/index.php');
}

$db = getDB();
$bulk = isset($_POST['bulk']) && $_POST['bulk'] == 1;
$redirectTo = trim($_POST['redirect_to'] ?? '');

$reason = trim($_POST['reason'] ?? '');
$notes  = trim($_POST['notes'] ?? '');

$responseNote = 'Marked as False Lead';
if ($reason) {
    $responseNote .= ' | Reason: ' . $reason;
}
if ($notes) {
    $responseNote .= ' | Notes: ' . $notes;
}

if ($bulk) {
    $leadIds = $_POST['lead_ids'] ?? [];
    if (empty($leadIds) || !is_array($leadIds)) {
        setFlash('error', 'No leads selected.');
        redirect(BASE_URL . '/modules/leads/index.php');
    }

    $leadIds = array_map('intval', $leadIds);
    $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
    $params = $leadIds;

    $whereClause = "id IN ($placeholders) AND status != 'converted'";

    if (isRole('csm')) {
        $whereClause .= " AND created_by = ?";
        $params[] = $user['id'];
    } elseif (isRole('telecall')) {
        $tcProjectIds = getTelecallProjectIds();
        if (empty($tcProjectIds)) {
            $whereClause .= " AND 1=0";
        } else {
            $inProj = implode(',', array_fill(0, count($tcProjectIds), '?'));
            $whereClause .= " AND project_id IN ($inProj)";
            $params = array_merge($params, $tcProjectIds);
        }
    }

    $stmt = $db->prepare("SELECT id, name FROM leads WHERE $whereClause");
    $stmt->execute($params);
    $validLeads = $stmt->fetchAll();

    if (empty($validLeads)) {
        setFlash('error', 'No eligible leads found to mark as False Lead (converted leads cannot be marked).');
        redirect(BASE_URL . '/modules/leads/index.php');
    }

    $validIds = array_column($validLeads, 'id');

    try {
        $db->beginTransaction();

        $updatePlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $updateStmt = $db->prepare("UPDATE leads SET status = 'false_lead', trial_end_date = NULL, trial_login_url = NULL, trial_username = NULL, trial_password = NULL, updated_at = NOW() WHERE id IN ($updatePlaceholders)");
        $updateStmt->execute($validIds);

        // Record history response for each lead
        $respStmt = $db->prepare("INSERT INTO lead_responses (lead_id, response_type, response, response_by, status_updated) VALUES (?, 'other', ?, ?, 'false_lead')");
        foreach ($validIds as $lid) {
            $respStmt->execute([$lid, $responseNote, $user['id']]);
        }

        $db->commit();

        $updatedCount = count($validIds);
        $totalRequested = count($leadIds);
        if ($updatedCount === $totalRequested) {
            setFlash('success', "Successfully marked $updatedCount lead(s) as False Lead.");
        } else {
            $skipped = $totalRequested - $updatedCount;
            setFlash('warning', "Marked $updatedCount lead(s) as False Lead. $skipped lead(s) were skipped due to permissions or converted status.");
        }
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Failed to update leads: ' . $e->getMessage());
    }

    redirect(BASE_URL . '/modules/leads/index.php');

} else {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        setFlash('error', 'Invalid lead ID.');
        redirect(BASE_URL . '/modules/leads/index.php');
    }

    $stmt = $db->prepare("SELECT id, name, status, project_id, created_by FROM leads WHERE id = ?");
    $stmt->execute([$id]);
    $lead = $stmt->fetch();

    if (!$lead) {
        setFlash('error', 'Lead not found.');
        redirect(BASE_URL . '/modules/leads/index.php');
    }

    if ($lead['status'] === 'converted') {
        setFlash('error', 'Converted leads cannot be marked as False Lead.');
        redirect($redirectTo ?: (BASE_URL . '/modules/leads/view.php?id=' . $id));
    }

    if (isRole('csm') && (int)($lead['created_by'] ?? 0) !== (int)$user['id']) {
        setFlash('error', 'You do not have permission to modify this lead.');
        redirect(BASE_URL . '/modules/leads/index.php');
    }

    if (isRole('telecall') && !in_array((int)$lead['project_id'], getTelecallProjectIds())) {
        setFlash('error', 'You do not have permission to modify this lead.');
        redirect(BASE_URL . '/modules/leads/index.php');
    }

    try {
        $db->beginTransaction();

        $db->prepare("UPDATE leads SET status = 'false_lead', trial_end_date = NULL, trial_login_url = NULL, trial_username = NULL, trial_password = NULL, updated_at = NOW() WHERE id = ?")
           ->execute([$id]);

        $db->prepare("INSERT INTO lead_responses (lead_id, response_type, response, response_by, status_updated) VALUES (?, 'other', ?, ?, 'false_lead')")
           ->execute([$id, $responseNote, $user['id']]);

        $db->commit();

        setFlash('success', 'Lead "' . htmlspecialchars($lead['name']) . '" has been marked as False Lead.');
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Failed to mark lead as False Lead: ' . $e->getMessage());
    }

    if ($redirectTo) {
        redirect($redirectTo);
    } else {
        redirect(BASE_URL . '/modules/leads/index.php');
    }
}
