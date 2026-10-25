<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('outreach');
verifyCsrf();

$db = getDB();
$user = currentUser();

$outreachId = (int)($_POST['outreach_id'] ?? 0);
if (!$outreachId) {
    setFlash('error', 'Invalid outreach ID.');
    redirect(BASE_URL . '/modules/outreach/index.php');
}

$stmt = $db->prepare("SELECT o.*, p.name as project_name FROM email_outreaches o LEFT JOIN projects p ON p.id=o.project_id WHERE o.id = ?");
$stmt->execute([$outreachId]);
$outreach = $stmt->fetch();
if (!$outreach) {
    setFlash('error', 'Outreach record not found.');
    redirect(BASE_URL . '/modules/outreach/index.php');
}

$isTelecall = isRole('telecall');
$telecallProjectIds = $isTelecall ? getTelecallProjectIds() : [];
if ($isTelecall && !in_array((int)$outreach['project_id'], $telecallProjectIds)) {
    setFlash('error', 'You do not have permission to update this outreach.');
    redirect(BASE_URL . '/modules/outreach/index.php');
}

$responseType     = $_POST['response_type'] ?? 'other';
$responseSummary  = trim($_POST['response_summary'] ?? '');
$repliedAt        = !empty($_POST['replied_at']) ? $_POST['replied_at'] : date('Y-m-d H:i:s');
$meetingDate      = !empty($_POST['meeting_date']) ? $_POST['meeting_date'] : null;
$nextFollowupDate = !empty($_POST['next_followup_date']) ? $_POST['next_followup_date'] : null;
$convertToLead    = !empty($_POST['convert_to_lead']);
$returnUrl        = $_POST['return_url'] ?? (BASE_URL . '/modules/outreach/view.php?id=' . $outreachId);

try {
    $db->beginTransaction();

    // 1. Update Outreach Record
    $newStatus = $convertToLead ? 'converted' : 'replied';
    $updateStmt = $db->prepare("UPDATE email_outreaches SET
        status = ?,
        response_type = ?,
        response_summary = ?,
        replied_at = ?,
        meeting_date = ?,
        next_followup_date = ?,
        updated_at = NOW()
        WHERE id = ?");
    $updateStmt->execute([$newStatus, $responseType, $responseSummary, $repliedAt, $meetingDate, $nextFollowupDate, $outreachId]);

    // 2. Activity title based on response
    $typeLabels = [
        'meeting_scheduled'    => 'Meeting Scheduled',
        'demo_requested'       => 'Demo Requested',
        'interested_more_info' => 'Interested / More Info Requested',
        'pricing_inquiry'      => 'Pricing Inquiry',
        'call_back_later'      => 'Follow-up Later',
        'referral'             => 'Referred to Colleague',
        'not_interested'       => 'Not Interested',
        'other'                => 'Response Received'
    ];
    $activityTitle = "Reply Recorded: " . ($typeLabels[$responseType] ?? 'Response');

    $actStmt = $db->prepare("INSERT INTO email_outreach_activities (outreach_id, activity_type, title, notes, scheduled_at, created_by) VALUES (?, 'reply_received', ?, ?, ?, ?)");
    $actStmt->execute([$outreachId, $activityTitle, $responseSummary, $meetingDate, $user['id']]);

    // 3. Convert to Lead if requested
    $createdLeadId = null;
    $createdLeadCode = null;
    if ($convertToLead && empty($outreach['converted_lead_id'])) {
        $leadCode = generateCode('LD', 'leads', 'lead_code');
        $phone = !empty($outreach['phone']) ? $outreach['phone'] : 'N/A';
        $leadStatus = ($responseType === 'meeting_scheduled' || $responseType === 'demo_requested' || $responseType === 'interested_more_info') ? 'interested' : 'contacted';
        
        $leadNotes = "Converted from Cold Email Outreach [{$outreach['outreach_code']}].\n";
        $leadNotes .= "Response Outcome: " . ($typeLabels[$responseType] ?? $responseType) . "\n";
        if ($responseSummary) {
            $leadNotes .= "Response Details: {$responseSummary}\n";
        }
        if ($meetingDate) {
            $leadNotes .= "Scheduled Session: {$meetingDate}\n";
        }
        if (!empty($outreach['notes'])) {
            $leadNotes .= "\nInitial Outreach Notes:\n{$outreach['notes']}";
        }

        $leadInsert = $db->prepare("INSERT INTO leads (
            lead_code, outreach_id, project_id, name, email, phone, company, designation,
            website, source, status, assigned_to, notes, created_by, created_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $leadInsert->execute([
            $leadCode,
            $outreachId,
            $outreach['project_id'],
            $outreach['contact_name'],
            $outreach['email'],
            $phone,
            $outreach['company'],
            $outreach['designation'],
            $outreach['website'],
            'email',
            $leadStatus,
            $outreach['assigned_to'] ?: $user['id'],
            $leadNotes,
            $user['id']
        ]);
        $createdLeadId = (int)$db->lastInsertId();
        $createdLeadCode = $leadCode;

        // Link back to outreach
        $db->prepare("UPDATE email_outreaches SET converted_lead_id = ?, status = 'converted' WHERE id = ?")
           ->execute([$createdLeadId, $outreachId]);

        // Add lead response entry in lead timeline
        $db->prepare("INSERT INTO lead_responses (lead_id, response_type, response, response_by, status_updated) VALUES (?, 'email', ?, ?, ?)")
           ->execute([$createdLeadId, "Prospect replied to cold email outreach: {$responseSummary}", $user['id'], $leadStatus]);

        // Add conversion activity in outreach timeline
        $db->prepare("INSERT INTO email_outreach_activities (outreach_id, activity_type, title, notes, created_by) VALUES (?, 'converted_to_lead', ?, ?, ?)")
           ->execute([$outreachId, "Converted to Lead ({$leadCode})", "Lead successfully created in sales pipeline under " . ($outreach['project_name'] ?? 'Project'), $user['id']]);

        // If Demo was requested or Meeting scheduled, optionally schedule a Demo record in demos table
        if ($meetingDate && ($responseType === 'demo_requested' || $responseType === 'meeting_scheduled')) {
            try {
                $lastDemo = $db->query("SELECT demo_no FROM demos ORDER BY id DESC LIMIT 1")->fetchColumn();
                $demoNum  = $lastDemo && preg_match('/DMO-(\d+)/', $lastDemo, $m) ? (int)$m[1]+1 : 1;
                $demoNo   = 'DMO-' . str_pad($demoNum, 4, '0', STR_PAD_LEFT);
                $demoTitle = ($responseType === 'demo_requested' ? 'Product Demo: ' : 'Intro Meeting: ') . $outreach['contact_name'] . ($outreach['company'] ? " ({$outreach['company']})" : '');

                $db->prepare("INSERT INTO demos (demo_no, title, project_id, lead_id, assigned_to, demo_type, scheduled_at, duration_mins, status, notes, created_by)
                             VALUES (?,?,?,?,?, 'online', ?, 45, 'scheduled', ?, ?)")
                   ->execute([
                       $demoNo,
                       $demoTitle,
                       $outreach['project_id'],
                       $createdLeadId,
                       $outreach['assigned_to'] ?: $user['id'],
                       $meetingDate,
                       "Auto-scheduled from Cold Email Outreach [{$outreach['outreach_code']}]. Response: {$responseSummary}",
                       $user['id']
                   ]);
            } catch (\Throwable $demoEx) {
                // Ignore demo table insertion if schema differs
            }
        }
    }

    $db->commit();

    if ($createdLeadId) {
        setFlash('success', "Response recorded and prospect successfully converted to Lead ({$createdLeadCode})!");
    } else {
        setFlash('success', "Prospect response recorded successfully.");
    }
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    setFlash('error', 'Error recording response: ' . $e->getMessage());
}

redirect($returnUrl);
