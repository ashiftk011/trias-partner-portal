<?php
/**
 * Expense & Asset Helper Functions and Profitability Engine
 * Trias Partner Portal
 */

require_once __DIR__ . '/../config/db.php';

/**
 * Save / Update split allocations for an expense across clients and/or projects.
 * Splits totalAmount equally among selected clients and/or projects.
 */
function saveExpenseAllocations(int $expenseId, float $totalAmount, string $allocationType, array $clientIds = [], array $projectIds = []): void {
    if (!$expenseId || $totalAmount <= 0) return;
    $db = getDB();

    // Sanitize IDs
    $cleanClientIds = array_values(array_filter(array_unique(array_map('intval', $clientIds))));
    $cleanProjectIds = array_values(array_filter(array_unique(array_map('intval', $projectIds))));

    // Determine actual allocation type based on selections
    $hasClients  = !empty($cleanClientIds);
    $hasProjects = !empty($cleanProjectIds);

    if ($hasClients && $hasProjects) {
        $actualType = 'both';
    } elseif ($hasClients) {
        $actualType = 'client';
    } elseif ($hasProjects) {
        $actualType = 'project';
    } else {
        $actualType = 'general';
    }

    // Update expense allocation_type
    $db->prepare("UPDATE expenses SET allocation_type = ? WHERE id = ?")->execute([$actualType, $expenseId]);

    // Clear existing allocations
    $db->prepare("DELETE FROM expense_clients WHERE expense_id = ?")->execute([$expenseId]);
    $db->prepare("DELETE FROM expense_projects WHERE expense_id = ?")->execute([$expenseId]);

    // 1. Equal Split Across Clients
    if ($hasClients) {
        $clientCount = count($cleanClientIds);
        $baseSplit = round($totalAmount / $clientCount, 2);
        $totalDistributed = 0.00;

        $stmt = $db->prepare("INSERT INTO expense_clients (expense_id, client_id, split_amount) VALUES (?, ?, ?)");
        foreach ($cleanClientIds as $idx => $cid) {
            // Adjust last item for rounding differences
            if ($idx === $clientCount - 1) {
                $splitAmt = round($totalAmount - $totalDistributed, 2);
            } else {
                $splitAmt = $baseSplit;
                $totalDistributed += $splitAmt;
            }
            $stmt->execute([$expenseId, $cid, $splitAmt]);
        }
    }

    // 2. Equal Split Across Projects
    if ($hasProjects) {
        $projectCount = count($cleanProjectIds);
        $baseSplit = round($totalAmount / $projectCount, 2);
        $totalDistributed = 0.00;

        $stmt = $db->prepare("INSERT INTO expense_projects (expense_id, project_id, split_amount) VALUES (?, ?, ?)");
        foreach ($cleanProjectIds as $idx => $pid) {
            if ($idx === $projectCount - 1) {
                $splitAmt = round($totalAmount - $totalDistributed, 2);
            } else {
                $splitAmt = $baseSplit;
                $totalDistributed += $splitAmt;
            }
            $stmt->execute([$expenseId, $pid, $splitAmt]);
        }
    }
}

/**
 * Fetch all allocation details for an expense
 */
function getExpenseAllocations(int $expenseId): array {
    if (!$expenseId) {
        return ['clients' => [], 'projects' => [], 'client_ids' => [], 'project_ids' => [], 'summary' => 'General / Overhead'];
    }
    $db = getDB();

    $clStmt = $db->prepare("
        SELECT ec.client_id, ec.split_amount, c.name as client_name, c.company as client_company, c.client_code, p.name as project_name
        FROM expense_clients ec
        JOIN clients c ON c.id = ec.client_id
        LEFT JOIN projects p ON p.id = c.project_id
        WHERE ec.expense_id = ?
        ORDER BY c.name ASC
    ");
    $clStmt->execute([$expenseId]);
    $clients = $clStmt->fetchAll(PDO::FETCH_ASSOC);

    $prStmt = $db->prepare("
        SELECT ep.project_id, ep.split_amount, p.name as project_name
        FROM expense_projects ep
        JOIN projects p ON p.id = ep.project_id
        WHERE ep.expense_id = ?
        ORDER BY p.name ASC
    ");
    $prStmt->execute([$expenseId]);
    $projects = $prStmt->fetchAll(PDO::FETCH_ASSOC);

    $clientIds = array_column($clients, 'client_id');
    $projectIds = array_column($projects, 'project_id');

    // Build short summary text
    $summaryParts = [];
    if (!empty($clients)) {
        $count = count($clients);
        $names = implode(', ', array_map(fn($c) => $c['client_company'] ?: $c['client_name'], array_slice($clients, 0, 2)));
        if ($count > 2) $names .= " +" . ($count - 2) . " more";
        $summaryParts[] = "Clients ({$count}): " . $names;
    }
    if (!empty($projects)) {
        $count = count($projects);
        $names = implode(', ', array_map(fn($p) => $p['project_name'], array_slice($projects, 0, 2)));
        if ($count > 2) $names .= " +" . ($count - 2) . " more";
        $summaryParts[] = "Projects ({$count}): " . $names;
    }

    $summary = !empty($summaryParts) ? implode(' | ', $summaryParts) : 'General / Overhead';

    return [
        'clients'     => $clients,
        'projects'    => $projects,
        'client_ids'  => $clientIds,
        'project_ids' => $projectIds,
        'summary'     => $summary
    ];
}

/**
 * Get map of all expense allocations keyed by expense_id (batch fetch for table listings)
 */
function getBatchExpenseAllocations(array $expenseIds): array {
    if (empty($expenseIds)) return [];
    $db = getDB();

    $inClause = implode(',', array_map('intval', $expenseIds));
    $map = [];
    foreach ($expenseIds as $id) {
        $map[$id] = [
            'clients'     => [],
            'projects'    => [],
            'client_ids'  => [],
            'project_ids' => [],
            'summary'     => 'General / Overhead'
        ];
    }

    // Clients
    $clRows = $db->query("
        SELECT ec.expense_id, ec.client_id, ec.split_amount, c.name as client_name, c.company as client_company, c.client_code
        FROM expense_clients ec
        JOIN clients c ON c.id = ec.client_id
        WHERE ec.expense_id IN ($inClause)
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clRows as $r) {
        $map[$r['expense_id']]['clients'][] = $r;
        $map[$r['expense_id']]['client_ids'][] = (int)$r['client_id'];
    }

    // Projects
    $prRows = $db->query("
        SELECT ep.expense_id, ep.project_id, ep.split_amount, p.name as project_name
        FROM expense_projects ep
        JOIN projects p ON p.id = ep.project_id
        WHERE ep.expense_id IN ($inClause)
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($prRows as $r) {
        $map[$r['expense_id']]['projects'][] = $r;
        $map[$r['expense_id']]['project_ids'][] = (int)$r['project_id'];
    }

    // Generate summaries
    foreach ($map as $eid => &$data) {
        $parts = [];
        if (!empty($data['clients'])) {
            $count = count($data['clients']);
            $names = implode(', ', array_map(fn($c) => $c['client_company'] ?: $c['client_name'], array_slice($data['clients'], 0, 2)));
            if ($count > 2) $names .= " +" . ($count - 2) . " more";
            $parts[] = "Clients ({$count}): " . $names;
        }
        if (!empty($data['projects'])) {
            $count = count($data['projects']);
            $names = implode(', ', array_map(fn($p) => $p['project_name'], array_slice($data['projects'], 0, 2)));
            if ($count > 2) $names .= " +" . ($count - 2) . " more";
            $parts[] = "Projects ({$count}): " . $names;
        }
        if (!empty($parts)) {
            $data['summary'] = implode(' | ', $parts);
        }
    }
    unset($data);

    return $map;
}

/**
 * Get comprehensive financial report for all clients (or a single client)
 */
function getAllClientsFinancials(string $fromDate = '', string $toDate = '', int $projectId = 0): array {
    $db = getDB();

    $sql = "
        SELECT 
            c.id, c.client_code, c.name, c.company, c.email, c.phone, c.status, c.joined_date,
            p.id as project_id, p.name as project_name,
            pl.name as plan_name, pl.price as plan_price
        FROM clients c
        LEFT JOIN projects p ON p.id = c.project_id
        LEFT JOIN plans pl ON pl.id = c.plan_id
        WHERE 1=1
    ";
    $params = [];
    if ($projectId > 0) {
        $sql .= " AND c.project_id = ?";
        $params[] = $projectId;
    }
    $sql .= " ORDER BY c.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($clients)) return [];

    $clientIds = array_column($clients, 'id');
    $inClause = implode(',', array_map('intval', $clientIds));

    // 1. Invoices Revenue per client
    $invSql = "
        SELECT 
            client_id,
            COALESCE(SUM(total_amount), 0) as total_invoiced,
            COALESCE(SUM(paid_amount), 0) as total_paid,
            COUNT(id) as invoice_count
        FROM invoices
        WHERE status != 'cancelled' AND client_id IN ($inClause)
    ";
    $invParams = [];
    if ($fromDate) { $invSql .= " AND invoice_date >= ?"; $invParams[] = $fromDate; }
    if ($toDate)   { $invSql .= " AND invoice_date <= ?"; $invParams[] = $toDate; }
    $invSql .= " GROUP BY client_id";
    $invStmt = $db->prepare($invSql);
    $invStmt->execute($invParams);
    $invMap = [];
    while ($r = $invStmt->fetch(PDO::FETCH_ASSOC)) {
        $invMap[$r['client_id']] = $r;
    }

    // 2. Direct Payments collected per client
    $paySql = "
        SELECT 
            client_id,
            COALESCE(SUM(amount), 0) as total_collected,
            COUNT(id) as payment_count
        FROM payments
        WHERE client_id IN ($inClause)
    ";
    $payParams = [];
    if ($fromDate) { $paySql .= " AND payment_date >= ?"; $payParams[] = $fromDate; }
    if ($toDate)   { $paySql .= " AND payment_date <= ?"; $payParams[] = $toDate; }
    $paySql .= " GROUP BY client_id";
    $payStmt = $db->prepare($paySql);
    $payStmt->execute($payParams);
    $payMap = [];
    while ($r = $payStmt->fetch(PDO::FETCH_ASSOC)) {
        $payMap[$r['client_id']] = $r;
    }

    // 3. Allocated Expenses per client
    $expSql = "
        SELECT 
            ec.client_id,
            COALESCE(SUM(ec.split_amount), 0) as total_expenses,
            COUNT(DISTINCT ec.expense_id) as expense_count
        FROM expense_clients ec
        JOIN expenses e ON e.id = ec.expense_id
        WHERE ec.client_id IN ($inClause)
    ";
    $expParams = [];
    if ($fromDate) { $expSql .= " AND e.expense_date >= ?"; $expParams[] = $fromDate; }
    if ($toDate)   { $expSql .= " AND e.expense_date <= ?"; $expParams[] = $toDate; }
    $expSql .= " GROUP BY ec.client_id";
    $expStmt = $db->prepare($expSql);
    $expStmt->execute($expParams);
    $expMap = [];
    while ($r = $expStmt->fetch(PDO::FETCH_ASSOC)) {
        $expMap[$r['client_id']] = $r;
    }

    // Combine and calculate profitability
    $results = [];
    foreach ($clients as $c) {
        $cid = (int)$c['id'];
        $inv = $invMap[$cid] ?? ['total_invoiced' => 0.00, 'total_paid' => 0.00, 'invoice_count' => 0];
        $pay = $payMap[$cid] ?? ['total_collected' => 0.00, 'payment_count' => 0];
        $exp = $expMap[$cid] ?? ['total_expenses' => 0.00, 'expense_count' => 0];

        $invoiced   = (float)$inv['total_invoiced'];
        // Cash collected is either sum of payments or sum of invoice paid amounts (use highest of the two or payments)
        $collected  = max((float)$pay['total_collected'], (float)$inv['total_paid']);
        $expenses   = (float)$exp['total_expenses'];

        // Invoiced Net Profit and Cash Net Profit
        $profitInvoiced = $invoiced - $expenses;
        $profitCash     = $collected - $expenses;

        // Margin % based on invoiced revenue
        $marginPct = $invoiced > 0 ? round(($profitInvoiced / $invoiced) * 100, 1) : ($expenses > 0 ? -100.0 : 0.0);

        // Status: profit, loss, breakeven
        if ($profitInvoiced > 0) {
            $status = 'profit';
        } elseif ($profitInvoiced < 0) {
            $status = 'loss';
        } else {
            $status = ($invoiced == 0 && $expenses == 0) ? 'neutral' : 'breakeven';
        }

        $results[] = array_merge($c, [
            'total_invoiced'  => $invoiced,
            'total_collected' => $collected,
            'pending_amount'  => max(0.00, $invoiced - $collected),
            'total_expenses'  => $expenses,
            'expense_count'   => (int)$exp['expense_count'],
            'invoice_count'   => (int)$inv['invoice_count'],
            'profit_invoiced' => $profitInvoiced,
            'profit_cash'     => $profitCash,
            'margin_pct'      => $marginPct,
            'profit_status'   => $status
        ]);
    }

    return $results;
}

/**
 * Get detailed financials and breakdown for a single client
 */
function getClientFinancialSummary(int $clientId, string $fromDate = '', string $toDate = ''): ?array {
    $list = getAllClientsFinancials($fromDate, $toDate);
    foreach ($list as $c) {
        if ((int)$c['id'] === $clientId) {
            $db = getDB();

            // Fetch list of allocated expenses
            $sql = "
                SELECT 
                    e.id, e.title, e.vendor_name, e.amount as total_expense_amount, e.expense_date, 
                    e.payment_mode, e.receipt_file, e.reference_no,
                    ec.split_amount as client_split_amount,
                    cat.name as category_name, cat.color_code,
                    (SELECT COUNT(*) FROM expense_clients WHERE expense_id = e.id) as client_split_count
                FROM expense_clients ec
                JOIN expenses e ON e.id = ec.expense_id
                JOIN expense_categories cat ON cat.id = e.category_id
                WHERE ec.client_id = ?
            ";
            $params = [$clientId];
            if ($fromDate) { $sql .= " AND e.expense_date >= ?"; $params[] = $fromDate; }
            if ($toDate)   { $sql .= " AND e.expense_date <= ?"; $params[] = $toDate; }
            $sql .= " ORDER BY e.expense_date DESC, e.id DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $c['allocated_expenses_list'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch list of invoices
            $invSql = "SELECT * FROM invoices WHERE client_id = ? AND status != 'cancelled'";
            $invParams = [$clientId];
            if ($fromDate) { $invSql .= " AND invoice_date >= ?"; $invParams[] = $fromDate; }
            if ($toDate)   { $invSql .= " AND invoice_date <= ?"; $invParams[] = $toDate; }
            $invSql .= " ORDER BY invoice_date DESC";
            $invStmt = $db->prepare($invSql);
            $invStmt->execute($invParams);
            $c['invoices_list'] = $invStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch payments
            $paySql = "SELECT * FROM payments WHERE client_id = ?";
            $payParams = [$clientId];
            if ($fromDate) { $paySql .= " AND payment_date >= ?"; $payParams[] = $fromDate; }
            if ($toDate)   { $paySql .= " AND payment_date <= ?"; $payParams[] = $toDate; }
            $paySql .= " ORDER BY payment_date DESC";
            $payStmt = $db->prepare($paySql);
            $payStmt->execute($payParams);
            $c['payments_list'] = $payStmt->fetchAll(PDO::FETCH_ASSOC);

            return $c;
        }
    }
    return null;
}

/**
 * Get comprehensive financial report for all projects
 */
function getAllProjectsFinancials(string $fromDate = '', string $toDate = ''): array {
    $db = getDB();

    $projects = $db->query("
        SELECT p.*, COUNT(DISTINCT c.id) as client_count
        FROM projects p
        LEFT JOIN clients c ON c.project_id = p.id
        GROUP BY p.id
        ORDER BY p.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($projects)) return [];

    $projectIds = array_column($projects, 'id');
    $inClause = implode(',', array_map('intval', $projectIds));

    // 1. Direct Project Invoiced Revenue & Collections via clients
    $revSql = "
        SELECT 
            c.project_id,
            COALESCE(SUM(i.total_amount), 0) as total_revenue,
            COALESCE(SUM(i.paid_amount), 0) as total_paid
        FROM invoices i
        JOIN clients c ON c.id = i.client_id
        WHERE i.status != 'cancelled' AND c.project_id IN ($inClause)
    ";
    $revParams = [];
    if ($fromDate) { $revSql .= " AND i.invoice_date >= ?"; $revParams[] = $fromDate; }
    if ($toDate)   { $revSql .= " AND i.invoice_date <= ?"; $revParams[] = $toDate; }
    $revSql .= " GROUP BY c.project_id";
    $revStmt = $db->prepare($revSql);
    $revStmt->execute($revParams);
    $revMap = [];
    while ($r = $revStmt->fetch(PDO::FETCH_ASSOC)) {
        $revMap[$r['project_id']] = $r;
    }

    // 2. Direct Project Expenses (from expense_projects)
    $dirExpSql = "
        SELECT 
            ep.project_id,
            COALESCE(SUM(ep.split_amount), 0) as direct_expenses,
            COUNT(DISTINCT ep.expense_id) as direct_expense_count
        FROM expense_projects ep
        JOIN expenses e ON e.id = ep.expense_id
        WHERE ep.project_id IN ($inClause)
    ";
    $dirParams = [];
    if ($fromDate) { $dirExpSql .= " AND e.expense_date >= ?"; $dirParams[] = $fromDate; }
    if ($toDate)   { $dirExpSql .= " AND e.expense_date <= ?"; $dirParams[] = $toDate; }
    $dirExpSql .= " GROUP BY ep.project_id";
    $dirStmt = $db->prepare($dirExpSql);
    $dirStmt->execute($dirParams);
    $dirExpMap = [];
    while ($r = $dirStmt->fetch(PDO::FETCH_ASSOC)) {
        $dirExpMap[$r['project_id']] = $r;
    }

    // 3. Client Allocated Expenses belonging to this project (from expense_clients where client.project_id = project.id)
    $clExpSql = "
        SELECT 
            c.project_id,
            COALESCE(SUM(ec.split_amount), 0) as client_allocated_expenses,
            COUNT(DISTINCT ec.expense_id) as client_expense_count
        FROM expense_clients ec
        JOIN clients c ON c.id = ec.client_id
        JOIN expenses e ON e.id = ec.expense_id
        WHERE c.project_id IN ($inClause)
    ";
    $clParams = [];
    if ($fromDate) { $clExpSql .= " AND e.expense_date >= ?"; $clParams[] = $fromDate; }
    if ($toDate)   { $clExpSql .= " AND e.expense_date <= ?"; $clParams[] = $toDate; }
    $clExpSql .= " GROUP BY c.project_id";
    $clStmt = $db->prepare($clExpSql);
    $clStmt->execute($clParams);
    $clExpMap = [];
    while ($r = $clStmt->fetch(PDO::FETCH_ASSOC)) {
        $clExpMap[$r['project_id']] = $r;
    }

    // Combine calculations
    $results = [];
    foreach ($projects as $p) {
        $pid = (int)$p['id'];
        $rev    = $revMap[$pid] ?? ['total_revenue' => 0.00, 'total_paid' => 0.00];
        $dirExp = $dirExpMap[$pid] ?? ['direct_expenses' => 0.00, 'direct_expense_count' => 0];
        $clExp  = $clExpMap[$pid] ?? ['client_allocated_expenses' => 0.00, 'client_expense_count' => 0];

        $revenue = (float)$rev['total_revenue'];
        $paid    = (float)$rev['total_paid'];
        $directCost = (float)$dirExp['direct_expenses'];
        $clientCost = (float)$clExp['client_allocated_expenses'];
        $totalCost  = $directCost + $clientCost;

        $profit = $revenue - $totalCost;
        $marginPct = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : ($totalCost > 0 ? -100.0 : 0.0);

        if ($profit > 0) {
            $status = 'profit';
        } elseif ($profit < 0) {
            $status = 'loss';
        } else {
            $status = ($revenue == 0 && $totalCost == 0) ? 'neutral' : 'breakeven';
        }

        $results[] = array_merge($p, [
            'total_revenue'            => $revenue,
            'total_paid'               => $paid,
            'direct_expenses'          => $directCost,
            'client_expenses'          => $clientCost,
            'total_expenses'           => $totalCost,
            'net_profit'               => $profit,
            'margin_pct'               => $marginPct,
            'profit_status'            => $status,
            'direct_expense_count'     => (int)$dirExp['direct_expense_count'],
            'client_expense_count'     => (int)$clExp['client_expense_count']
        ]);
    }

    return $results;
}

/**
 * Generate Next Auto-Incrementing Asset Code (e.g. AST-0001)
 */
function generateNextAssetCode(): string {
    $db = getDB();
    $maxCode = $db->query("SELECT asset_code FROM company_assets ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($maxCode && preg_match('/AST-(\d+)/', $maxCode, $m)) {
        $next = (int)$m[1] + 1;
    } else {
        $next = 1;
    }
    return sprintf('AST-%04d', $next);
}

/**
 * Fetch Asset Categories
 */
function getAssetCategories(): array {
    $db = getDB();
    return $db->query("
        SELECT ac.*, COUNT(ca.id) as asset_count, COALESCE(SUM(ca.purchase_cost), 0) as total_cost
        FROM asset_categories ac
        LEFT JOIN company_assets ca ON ca.category_id = ac.id
        GROUP BY ac.id
        ORDER BY ac.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
