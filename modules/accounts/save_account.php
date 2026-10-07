<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/accounting.php';

requireAccess('accounts');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    
    $db = getDB();
    $id             = (int)($_POST['id'] ?? 0);
    $accountName    = trim($_POST['account_name'] ?? '');
    $accountType    = trim($_POST['account_type'] ?? 'bank');
    $bankName       = trim($_POST['bank_name'] ?? '');
    $accountNumber  = trim($_POST['account_number'] ?? '');
    $ifscCode       = trim($_POST['ifsc_code'] ?? '');
    $branchName     = trim($_POST['branch_name'] ?? '');
    $openingBalance = max(0, (float)($_POST['opening_balance'] ?? 0));
    $openingDate    = trim($_POST['opening_date'] ?? date('Y-m-d'));
    $balanceType    = trim($_POST['balance_type'] ?? 'debit');
    $status         = trim($_POST['status'] ?? 'active');
    $notes          = trim($_POST['notes'] ?? '');
    $userId         = currentUser()['id'];

    if (empty($accountName)) {
        setFlash('error', 'Account Name is required.');
        redirect(BASE_URL . '/modules/accounts/index.php');
    }

    if ($id > 0) {
        // Update Account
        $stmt = $db->prepare("
            UPDATE company_accounts 
            SET account_name = ?, account_type = ?, bank_name = ?, account_number = ?, ifsc_code = ?, branch_name = ?, opening_balance = ?, opening_date = ?, balance_type = ?, status = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $accountName, $accountType, $bankName, $accountNumber, $ifscCode, $branchName, $openingBalance, $openingDate, $balanceType, $status, $notes, $id
        ]);
        recalculateAccountBalance($id);
        setFlash('success', "Company Account '$accountName' updated successfully.");
    } else {
        // Create Account
        $stmt = $db->prepare("
            INSERT INTO company_accounts 
            (account_name, account_type, bank_name, account_number, ifsc_code, branch_name, opening_balance, opening_date, balance_type, current_balance, status, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
        ");
        $stmt->execute([
            $accountName, $accountType, $bankName, $accountNumber, $ifscCode, $branchName, $openingBalance, $openingDate, $balanceType, $status, $notes, $userId
        ]);
        $newId = (int)$db->lastInsertId();
        recalculateAccountBalance($newId);
        setFlash('success', "New Company Account '$accountName' created successfully.");
    }

    redirect(BASE_URL . '/modules/accounts/index.php');
}

redirect(BASE_URL . '/modules/accounts/index.php');
