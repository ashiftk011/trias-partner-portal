<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('leads');
verifyCsrf();

$db = getDB();
$id          = (int)($_POST['id'] ?? 0);
$projectId   = (int)$_POST['project_id'];
$regionId    = $_POST['region_id'] ? (int)$_POST['region_id'] : null;
$name        = trim($_POST['name']);
$email       = trim($_POST['email'] ?? '');
$phone       = trim($_POST['phone']);
$company     = trim($_POST['company'] ?? '');
$designation = trim($_POST['designation'] ?? '');
$source      = $_POST['source'];
$status      = $_POST['status'];
$assignedTo  = $_POST['assigned_to'] ? (int)$_POST['assigned_to'] : null;
$planId      = $_POST['interested_plan_id'] ? (int)$_POST['interested_plan_id'] : null;
$notes       = trim($_POST['notes'] ?? '');
$website     = trim($_POST['website'] ?? '');
$address     = trim($_POST['address'] ?? '');

$trialEndDate   = ($status === 'trial' || $status === 'trial_ended') && !empty($_POST['trial_end_date']) ? $_POST['trial_end_date'] : null;
$trialLoginUrl  = ($status === 'trial' || $status === 'trial_ended') && !empty($_POST['trial_login_url']) ? trim($_POST['trial_login_url']) : null;
$trialUsername  = ($status === 'trial' || $status === 'trial_ended') && !empty($_POST['trial_username']) ? trim($_POST['trial_username']) : null;
$trialPassword  = ($status === 'trial' || $status === 'trial_ended') && !empty($_POST['trial_password']) ? trim($_POST['trial_password']) : null;

if (!$name || !$phone || !$projectId) {
    setFlash('error', 'Name, phone and project are required.');
    redirect(BASE_URL . '/modules/leads/index.php');
}

if ($id > 0) {
    $db->prepare("UPDATE leads SET project_id=?,region_id=?,name=?,email=?,phone=?,company=?,designation=?,website=?,address=?,source=?,status=?,assigned_to=?,interested_plan_id=?,trial_end_date=?,trial_login_url=?,trial_username=?,trial_password=?,notes=?,updated_at=NOW() WHERE id=?")
       ->execute([$projectId,$regionId,$name,$email,$phone,$company,$designation,$website,$address,$source,$status,$assignedTo,$planId,$trialEndDate,$trialLoginUrl,$trialUsername,$trialPassword,$notes,$id]);
    setFlash('success', 'Lead updated successfully.');
} else {
    $code = generateCode('LD', 'leads', 'lead_code');
    $db->prepare("INSERT INTO leads (lead_code,project_id,region_id,name,email,phone,company,designation,website,address,source,status,assigned_to,interested_plan_id,trial_end_date,trial_login_url,trial_username,trial_password,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$code,$projectId,$regionId,$name,$email,$phone,$company,$designation,$website,$address,$source,$status,$assignedTo,$planId,$trialEndDate,$trialLoginUrl,$trialUsername,$trialPassword,$notes]);
    setFlash('success', 'Lead added successfully. Code: ' . $code);
}

redirect(BASE_URL . '/modules/leads/index.php');
