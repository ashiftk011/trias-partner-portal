<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/accounting.php';

requireAccess('accounts');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    
    $action         = trim($_POST['action'] ?? 'transfer');
    $transactionDate= trim($_POST['transaction_date'] ?? date('Y-m-d'));
    $amount         = max(0.01, (float)($_POST['amount'] ?? 0));
    $notes          = trim($_POST['notes'] ?? '');
    $userId         = currentUser()['id'];

    if ($amount <= 0) {
        setFlash('error', 'Amount must be greater than zero.');
        redirect(BASE_URL . '/modules/accounts/index.php');
    }

    if ($action === 'transfer') {
        $fromAccountId = (int)($_POST['from_account_id'] ?? 0);
        $toAccountId   = (int)($_POST['to_account_id'] ?? 0);

        if (!$fromAccountId || !$toAccountId || $fromAccountId === $toAccountId) {
            setFlash('error', 'Please select two different valid accounts for transfer.');
            redirect(BASE_URL . '/modules/accounts/index.php');
        }

        $fromAcc = getCompanyAccountById($fromAccountId);
        $toAcc   = getCompanyAccountById($toAccountId);

        if (!$fromAcc || !$toAcc) {
            setFlash('error', 'Selected account(s) not found.');
            redirect(BASE_URL . '/modules/accounts/index.php');
        }

        $transferRefId = time(); // Group reference for the pair of transactions

        // Credit From Account (Money Out)
        recordAccountTransaction(
            $fromAccountId,
            $transactionDate,
            'transfer_out',
            'credit',
            $amount,
            'transfer',
            $transferRefId,
            "Transfer to {$toAcc['account_name']}",
            $notes,
            $userId
        );

        // Debit To Account (Money In)
        recordAccountTransaction(
            $toAccountId,
            $transactionDate,
            'transfer_in',
            'debit',
            $amount,
            'transfer',
            $transferRefId,
            "Transfer from {$fromAcc['account_name']}",
            $notes,
            $userId
        );

        setFlash('success', "Transfer of ₹" . number_format($amount, 2) . " from {$fromAcc['account_name']} to {$toAcc['account_name']} recorded successfully.");

    } elseif ($action === 'direct_entry') {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $entryType = trim($_POST['entry_type'] ?? 'debit'); // 'debit' = Money In, 'credit' = Money Out
        $desc      = trim($_POST['description'] ?? '');

        $acc = getCompanyAccountById($accountId);
        if (!$acc) {
            setFlash('error', 'Selected account not found.');
            redirect(BASE_URL . '/modules/accounts/index.php');
        }

        $txType = ($entryType === 'debit') ? 'direct_credit' : 'direct_debit'; // direct credit to company = income/deposit; direct debit = withdrawal/expense
        
        recordAccountTransaction(
            $accountId,
            $transactionDate,
            $txType,
            $entryType,
            $amount,
            'manual',
            null,
            $desc ?: ($entryType === 'debit' ? 'Direct Deposit / Credit' : 'Direct Withdrawal / Debit'),
            $notes,
            $userId
        );

        setFlash('success', "Direct transaction of ₹" . number_format($amount, 2) . " recorded for {$acc['account_name']}.");
    }

    redirect(BASE_URL . '/modules/accounts/index.php');
}

redirect(BASE_URL . '/modules/accounts/index.php');
