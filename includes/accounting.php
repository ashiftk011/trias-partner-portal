<?php
/**
 * Accounting Helper Functions & Financial Engine
 * Trias Partner Portal
 */

require_once __DIR__ . '/../config/db.php';

/**
 * Get all company accounts
 */
function getCompanyAccounts(?string $status = 'active'): array {
    $db = getDB();
    $sql = "SELECT * FROM company_accounts WHERE 1=1";
    $params = [];
    if ($status) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY account_type ASC, account_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get a single company account by ID
 */
function getCompanyAccountById(int $id): ?array {
    if (!$id) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM company_accounts WHERE id = ?");
    $stmt->execute([$id]);
    $account = $stmt->fetch();
    return $account ?: null;
}

/**
 * Recalculate and update current balance of an account
 */
function recalculateAccountBalance(int $accountId): float {
    if (!$accountId) return 0.00;
    $db = getDB();
    
    $acc = getCompanyAccountById($accountId);
    if (!$acc) return 0.00;

    $opening = (float)$acc['opening_balance'];
    if ($acc['balance_type'] === 'credit') {
        $opening = -$opening;
    }

    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as total_debit,
            COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as total_credit
        FROM account_transactions 
        WHERE account_id = ?
    ");
    $stmt->execute([$accountId]);
    $totals = $stmt->fetch();

    $currentBalance = $opening + (float)$totals['total_debit'] - (float)$totals['total_credit'];

    $upd = $db->prepare("UPDATE company_accounts SET current_balance = ? WHERE id = ?");
    $upd->execute([$currentBalance, $accountId]);

    return $currentBalance;
}

/**
 * Record a transaction in account_transactions ledger and update account balance
 */
function recordAccountTransaction(
    int $accountId,
    string $transactionDate,
    string $transactionType,
    string $type,
    float $amount,
    string $referenceType = 'manual',
    ?int $referenceId = null,
    string $description = '',
    string $notes = '',
    ?int $userId = null
): int {
    if (!$accountId || $amount <= 0) return 0;
    $db = getDB();

    if ($userId === null && function_exists('currentUser')) {
        $userId = currentUser()['id'] ?? null;
    }

    $stmt = $db->prepare("
        INSERT INTO account_transactions 
        (account_id, transaction_date, transaction_type, type, amount, reference_type, reference_id, description, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $accountId,
        $transactionDate,
        $transactionType,
        $type,
        $amount,
        $referenceType,
        $referenceId,
        $description,
        $notes,
        $userId
    ]);
    $txId = (int)$db->lastInsertId();

    // Recalculate current balance and set balance_after for this transaction
    $newBalance = recalculateAccountBalance($accountId);

    $db->prepare("UPDATE account_transactions SET balance_after = ? WHERE id = ?")
       ->execute([$newBalance, $txId]);

    return $txId;
}

/**
 * Delete ledger transaction linked to a reference (e.g., when a payment/expense/salary is deleted or updated)
 */
function deleteReferenceTransactions(string $referenceType, int $referenceId): void {
    if (!$referenceId) return;
    $db = getDB();

    // Fetch affected accounts first to recalculate balances after deletion
    $stmt = $db->prepare("SELECT DISTINCT account_id FROM account_transactions WHERE reference_type = ? AND reference_id = ?");
    $stmt->execute([$referenceType, $referenceId]);
    $accountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $del = $db->prepare("DELETE FROM account_transactions WHERE reference_type = ? AND reference_id = ?");
    $del->execute([$referenceType, $referenceId]);

    foreach ($accountIds as $accId) {
        recalculateAccountBalance((int)$accId);
    }
}

/**
 * Sync transaction for a reference object (removes old tx for reference & inserts new if account_id is provided)
 */
function syncReferenceTransaction(
    ?int $accountId,
    string $referenceType,
    int $referenceId,
    string $transactionDate,
    string $transactionType,
    string $type,
    float $amount,
    string $description = '',
    string $notes = '',
    ?int $userId = null
): void {
    deleteReferenceTransactions($referenceType, $referenceId);
    if ($accountId && $amount > 0) {
        recordAccountTransaction($accountId, $transactionDate, $transactionType, $type, $amount, $referenceType, $referenceId, $description, $notes, $userId);
    }
}

/**
 * Get Account Ledger statement for a specific account
 */
function getAccountLedger(int $accountId, string $fromDate = '', string $toDate = '', string $search = ''): array {
    $db = getDB();
    $account = getCompanyAccountById($accountId);
    if (!$account) return ['account' => null, 'opening_balance' => 0, 'transactions' => [], 'total_debit' => 0, 'total_credit' => 0, 'closing_balance' => 0];

    $initialBalance = (float)$account['opening_balance'];
    if ($account['balance_type'] === 'credit') {
        $initialBalance = -$initialBalance;
    }

    // Calculate opening balance prior to $fromDate if $fromDate is provided
    $openingBalance = $initialBalance;
    if ($fromDate) {
        $opStmt = $db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as total_debit,
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as total_credit
            FROM account_transactions 
            WHERE account_id = ? AND transaction_date < ?
        ");
        $opStmt->execute([$accountId, $fromDate]);
        $opData = $opStmt->fetch();
        $openingBalance += ((float)$opData['total_debit'] - (float)$opData['total_credit']);
    }

    // Fetch transactions in range
    $sql = "SELECT tx.*, u.name as created_by_name 
            FROM account_transactions tx 
            LEFT JOIN users u ON u.id = tx.created_by 
            WHERE tx.account_id = ?";
    $params = [$accountId];

    if ($fromDate) {
        $sql .= " AND tx.transaction_date >= ?";
        $params[] = $fromDate;
    }
    if ($toDate) {
        $sql .= " AND tx.transaction_date <= ?";
        $params[] = $toDate;
    }
    if ($search) {
        $sql .= " AND (tx.description LIKE ? OR tx.notes LIKE ? OR tx.transaction_type LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY tx.transaction_date ASC, tx.id ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $runningBalance = $openingBalance;
    $totalDebit = 0.00;
    $totalCredit = 0.00;

    foreach ($rows as &$r) {
        $amt = (float)$r['amount'];
        if ($r['type'] === 'debit') {
            $runningBalance += $amt;
            $totalDebit += $amt;
        } else {
            $runningBalance -= $amt;
            $totalCredit += $amt;
        }
        $r['running_balance'] = $runningBalance;
    }
    unset($r);

    return [
        'account'         => $account,
        'opening_balance' => $openingBalance,
        'transactions'    => $rows,
        'total_debit'     => $totalDebit,
        'total_credit'    => $totalCredit,
        'closing_balance' => $runningBalance
    ];
}

/**
 * Get Cash Book statement (All Cash Accounts or specific cash account)
 */
function getCashBook(string $fromDate = '', string $toDate = '', int $accountId = 0): array {
    $db = getDB();
    
    // Get all cash account IDs
    if ($accountId > 0) {
        $accStmt = $db->prepare("SELECT id FROM company_accounts WHERE id = ? AND account_type = 'cash'");
        $accStmt->execute([$accountId]);
        $cashAccIds = $accStmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $accStmt = $db->query("SELECT id FROM company_accounts WHERE account_type = 'cash'");
        $cashAccIds = $accStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($cashAccIds)) {
        return ['accounts' => [], 'opening_balance' => 0, 'transactions' => [], 'total_in' => 0, 'total_out' => 0, 'closing_balance' => 0];
    }

    $inClause = implode(',', array_map('intval', $cashAccIds));

    // Calculate opening balance
    $opStmt = $db->query("
        SELECT SUM(CASE WHEN balance_type = 'credit' THEN -opening_balance ELSE opening_balance END) FROM company_accounts WHERE id IN ($inClause)
    ");
    $openingBalance = (float)($opStmt->fetchColumn() ?: 0);

    if ($fromDate) {
        $opTx = $db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as total_debit,
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as total_credit
            FROM account_transactions 
            WHERE account_id IN ($inClause) AND transaction_date < ?
        ");
        $opTx->execute([$fromDate]);
        $opData = $opTx->fetch();
        $openingBalance += ((float)$opData['total_debit'] - (float)$opData['total_credit']);
    }

    // Fetch transactions
    $sql = "SELECT tx.*, ca.account_name, u.name as created_by_name 
            FROM account_transactions tx 
            JOIN company_accounts ca ON ca.id = tx.account_id
            LEFT JOIN users u ON u.id = tx.created_by 
            WHERE tx.account_id IN ($inClause)";
    $params = [];

    if ($fromDate) {
        $sql .= " AND tx.transaction_date >= ?";
        $params[] = $fromDate;
    }
    if ($toDate) {
        $sql .= " AND tx.transaction_date <= ?";
        $params[] = $toDate;
    }
    $sql .= " ORDER BY tx.transaction_date ASC, tx.id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $running = $openingBalance;
    $totalIn = 0.00;
    $totalOut = 0.00;

    foreach ($rows as &$r) {
        $amt = (float)$r['amount'];
        if ($r['type'] === 'debit') {
            $running += $amt;
            $totalIn += $amt;
        } else {
            $running -= $amt;
            $totalOut += $amt;
        }
        $r['running_balance'] = $running;
    }
    unset($r);

    return [
        'opening_balance' => $openingBalance,
        'transactions'    => $rows,
        'total_in'        => $totalIn,
        'total_out'       => $totalOut,
        'closing_balance' => $running
    ];
}

/**
 * Get Bank Book statement (All Bank/Wallet/Card Accounts or specific bank account)
 */
function getBankBook(string $fromDate = '', string $toDate = '', int $accountId = 0): array {
    $db = getDB();
    
    if ($accountId > 0) {
        $accStmt = $db->prepare("SELECT id FROM company_accounts WHERE id = ? AND account_type IN ('bank','wallet','credit_card','other')");
        $accStmt->execute([$accountId]);
        $bankAccIds = $accStmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $accStmt = $db->query("SELECT id FROM company_accounts WHERE account_type IN ('bank','wallet','credit_card','other')");
        $bankAccIds = $accStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($bankAccIds)) {
        return ['accounts' => [], 'opening_balance' => 0, 'transactions' => [], 'total_deposits' => 0, 'total_withdrawals' => 0, 'closing_balance' => 0];
    }

    $inClause = implode(',', array_map('intval', $bankAccIds));

    // Calculate opening balance
    $opStmt = $db->query("
        SELECT SUM(CASE WHEN balance_type = 'credit' THEN -opening_balance ELSE opening_balance END) FROM company_accounts WHERE id IN ($inClause)
    ");
    $openingBalance = (float)($opStmt->fetchColumn() ?: 0);

    if ($fromDate) {
        $opTx = $db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as total_debit,
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as total_credit
            FROM account_transactions 
            WHERE account_id IN ($inClause) AND transaction_date < ?
        ");
        $opTx->execute([$fromDate]);
        $opData = $opTx->fetch();
        $openingBalance += ((float)$opData['total_debit'] - (float)$opData['total_credit']);
    }

    // Fetch transactions
    $sql = "SELECT tx.*, ca.account_name, ca.bank_name, ca.account_number, u.name as created_by_name 
            FROM account_transactions tx 
            JOIN company_accounts ca ON ca.id = tx.account_id
            LEFT JOIN users u ON u.id = tx.created_by 
            WHERE tx.account_id IN ($inClause)";
    $params = [];

    if ($fromDate) {
        $sql .= " AND tx.transaction_date >= ?";
        $params[] = $fromDate;
    }
    if ($toDate) {
        $sql .= " AND tx.transaction_date <= ?";
        $params[] = $toDate;
    }
    $sql .= " ORDER BY tx.transaction_date ASC, tx.id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $running = $openingBalance;
    $totalDeposits = 0.00;
    $totalWithdrawals = 0.00;

    foreach ($rows as &$r) {
        $amt = (float)$r['amount'];
        if ($r['type'] === 'debit') {
            $running += $amt;
            $totalDeposits += $amt;
        } else {
            $running -= $amt;
            $totalWithdrawals += $amt;
        }
        $r['running_balance'] = $running;
    }
    unset($r);

    return [
        'opening_balance'   => $openingBalance,
        'transactions'      => $rows,
        'total_deposits'    => $totalDeposits,
        'total_withdrawals' => $totalWithdrawals,
        'closing_balance'   => $running
    ];
}

/**
 * Get Profit & Loss (P&L) Statement
 */
function getProfitAndLoss(string $fromDate = '', string $toDate = ''): array {
    $db = getDB();

    // 1. Total Invoice Sales Revenue
    $invSql = "SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status != 'cancelled'";
    $invParams = [];
    if ($fromDate) { $invSql .= " AND invoice_date >= ?"; $invParams[] = $fromDate; }
    if ($toDate)   { $invSql .= " AND invoice_date <= ?"; $invParams[] = $toDate; }
    $invStmt = $db->prepare($invSql);
    $invStmt->execute($invParams);
    $invoiceRevenue = (float)$invStmt->fetchColumn();

    // 2. Direct Account Credits (Other Income)
    $dirIncSql = "SELECT COALESCE(SUM(amount), 0) FROM account_transactions WHERE transaction_type = 'direct_credit'";
    $dirParams = [];
    if ($fromDate) { $dirIncSql .= " AND transaction_date >= ?"; $dirParams[] = $fromDate; }
    if ($toDate)   { $dirIncSql .= " AND transaction_date <= ?"; $dirParams[] = $toDate; }
    $dirStmt = $db->prepare($dirIncSql);
    $dirStmt->execute($dirParams);
    $otherIncome = (float)$dirStmt->fetchColumn();

    $totalRevenue = $invoiceRevenue + $otherIncome;

    // 3. Expenses Categorized
    $expSql = "SELECT ec.name as category_name, ec.color_code, COALESCE(SUM(e.amount), 0) as total_amount
               FROM expenses e
               JOIN expense_categories ec ON ec.id = e.category_id
               WHERE 1=1";
    $expParams = [];
    if ($fromDate) { $expSql .= " AND e.expense_date >= ?"; $expParams[] = $fromDate; }
    if ($toDate)   { $expSql .= " AND e.expense_date <= ?"; $expParams[] = $toDate; }
    $expSql .= " GROUP BY ec.id, ec.name, ec.color_code ORDER BY total_amount DESC";
    $expStmt = $db->prepare($expSql);
    $expStmt->execute($expParams);
    $expensesByCategory = $expStmt->fetchAll();

    $totalExpensesOnly = 0.00;
    foreach ($expensesByCategory as $ec) {
        $totalExpensesOnly += (float)$ec['total_amount'];
    }

    // 4. Salary & Payroll Costs
    $salSql = "SELECT COALESCE(SUM(net_salary), 0) FROM salary_payments WHERE status = 'paid'";
    $salParams = [];
    if ($fromDate) { $salSql .= " AND payment_date >= ?"; $salParams[] = $fromDate; }
    if ($toDate)   { $salSql .= " AND payment_date <= ?"; $salParams[] = $toDate; }
    $salStmt = $db->prepare($salSql);
    $salStmt->execute($salParams);
    $totalSalaryExpenses = (float)$salStmt->fetchColumn();

    // 5. Direct Account Debits (Other Direct Expenses)
    $dirExpSql = "SELECT COALESCE(SUM(amount), 0) FROM account_transactions WHERE transaction_type = 'direct_debit'";
    $dirExpParams = [];
    if ($fromDate) { $dirExpSql .= " AND transaction_date >= ?"; $dirExpParams[] = $fromDate; }
    if ($toDate)   { $dirExpSql .= " AND transaction_date <= ?"; $dirExpParams[] = $toDate; }
    $dirExpStmt = $db->prepare($dirExpSql);
    $dirExpStmt->execute($dirExpParams);
    $otherDirectExpenses = (float)$dirExpStmt->fetchColumn();

    $totalExpenses = $totalExpensesOnly + $totalSalaryExpenses + $otherDirectExpenses;
    $netProfit = $totalRevenue - $totalExpenses;

    return [
        'invoice_revenue'        => $invoiceRevenue,
        'other_income'           => $otherIncome,
        'total_revenue'          => $totalRevenue,
        'expenses_by_category'   => $expensesByCategory,
        'total_operating_expense'=> $totalExpensesOnly,
        'salary_expenses'        => $totalSalaryExpenses,
        'other_expenses'         => $otherDirectExpenses,
        'total_expenses'         => $totalExpenses,
        'net_profit'             => $netProfit,
        'is_profit'              => ($netProfit >= 0)
    ];
}

/**
 * Get Balance Sheet as of Date
 */
function getBalanceSheet(string $asOfDate = ''): array {
    $db = getDB();

    if (!$asOfDate) {
        $asOfDate = date('Y-m-d');
    }

    // 1. Cash & Bank Accounts (Asset)
    $accounts = getCompanyAccounts('active');
    $totalCashBank = 0.00;
    $accountBalances = [];

    foreach ($accounts as $a) {
        // Calculate balance as of $asOfDate
        $op = (float)$a['opening_balance'];
        if ($a['balance_type'] === 'credit') $op = -$op;

        $st = $db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as total_debit,
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as total_credit
            FROM account_transactions
            WHERE account_id = ? AND transaction_date <= ?
        ");
        $st->execute([$a['id'], $asOfDate]);
        $res = $st->fetch();

        $bal = $op + (float)$res['total_debit'] - (float)$res['total_credit'];
        $accountBalances[] = [
            'id'             => $a['id'],
            'account_name'   => $a['account_name'],
            'account_type'   => $a['account_type'],
            'current_balance'=> $bal
        ];
        $totalCashBank += $bal;
    }

    // 2. Accounts Receivable (Pending client invoice balances)
    $arStmt = $db->prepare("
        SELECT COALESCE(SUM(total_amount - paid_amount - COALESCE(advance_amount, 0)), 0)
        FROM invoices 
        WHERE status IN ('pending', 'partial', 'overdue') AND invoice_date <= ?
    ");
    $arStmt->execute([$asOfDate]);
    $accountsReceivable = (float)$arStmt->fetchColumn();

    $totalAssets = $totalCashBank + $accountsReceivable;

    // 3. Liabilities & Retained Earnings / Equity
    $pnl = getProfitAndLoss('', $asOfDate);
    $retainedEarnings = $pnl['net_profit'];

    // Opening Equity sum
    $opEquityStmt = $db->query("
        SELECT COALESCE(SUM(CASE WHEN balance_type = 'debit' THEN opening_balance ELSE -opening_balance END), 0)
        FROM company_accounts
    ");
    $openingEquity = (float)$opEquityStmt->fetchColumn();

    $totalEquity = $openingEquity + $retainedEarnings;
    $totalLiabilitiesEquity = $totalEquity; // Simplified balanced ledger

    return [
        'as_of_date'            => $asOfDate,
        'cash_bank_accounts'    => $accountBalances,
        'total_cash_bank'       => $totalCashBank,
        'accounts_receivable'   => $accountsReceivable,
        'total_assets'          => $totalAssets,
        'opening_equity'        => $openingEquity,
        'retained_earnings'     => $retainedEarnings,
        'total_equity'          => $totalEquity,
        'total_liabilities_equity' => $totalLiabilitiesEquity
    ];
}

/**
 * Get Trial Balance (Summary of All Accounts)
 */
function getTrialBalance(string $fromDate = '', string $toDate = ''): array {
    $db = getDB();

    $rows = [];
    $totalDebit = 0.00;
    $totalCredit = 0.00;

    // 1. Company Accounts
    $accounts = getCompanyAccounts();
    foreach ($accounts as $a) {
        $bal = (float)$a['current_balance'];
        if ($bal >= 0) {
            $debit = $bal;
            $credit = 0.00;
        } else {
            $debit = 0.00;
            $credit = abs($bal);
        }
        $rows[] = [
            'account_name' => 'Account: ' . $a['account_name'] . ' (' . ucfirst($a['account_type']) . ')',
            'type'         => 'Asset / Bank',
            'debit'        => $debit,
            'credit'       => $credit
        ];
        $totalDebit += $debit;
        $totalCredit += $credit;
    }

    // 2. Accounts Receivable (Clients)
    $ar = (float)$db->query("SELECT COALESCE(SUM(total_amount - paid_amount - COALESCE(advance_amount, 0)), 0) FROM invoices WHERE status IN ('pending','partial','overdue')")->fetchColumn();
    if ($ar > 0) {
        $rows[] = [
            'account_name' => 'Accounts Receivable (Client Invoices Due)',
            'type'         => 'Asset',
            'debit'        => $ar,
            'credit'       => 0.00
        ];
        $totalDebit += $ar;
    }

    // 3. Sales Income (Revenue)
    $pnl = getProfitAndLoss($fromDate, $toDate);
    if ($pnl['total_revenue'] > 0) {
        $rows[] = [
            'account_name' => 'Sales Revenue & Other Income',
            'type'         => 'Revenue',
            'debit'        => 0.00,
            'credit'       => $pnl['total_revenue']
        ];
        $totalCredit += $pnl['total_revenue'];
    }

    // 4. Operating Expenses Categories
    foreach ($pnl['expenses_by_category'] as $ec) {
        $amt = (float)$ec['total_amount'];
        if ($amt > 0) {
            $rows[] = [
                'account_name' => 'Expense: ' . $ec['category_name'],
                'type'         => 'Expense',
                'debit'        => $amt,
                'credit'       => 0.00
            ];
            $totalDebit += $amt;
        }
    }

    // 5. Salaries & Wages
    if ($pnl['salary_expenses'] > 0) {
        $rows[] = [
            'account_name' => 'Payroll: Employee Salaries & Wages',
            'type'         => 'Expense',
            'debit'        => $pnl['salary_expenses'],
            'credit'       => 0.00
        ];
        $totalDebit += $pnl['salary_expenses'];
    }

    return [
        'rows'         => $rows,
        'total_debit'  => $totalDebit,
        'total_credit' => $totalCredit
    ];
}
