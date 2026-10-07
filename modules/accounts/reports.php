<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/currencies.php';
require_once __DIR__ . '/../../includes/accounting.php';

requireAccess('accounts');

$reportType = trim($_GET['report'] ?? 'cashbook');
$fromDate   = trim($_GET['from_date'] ?? date('Y-m-01'));
$toDate     = trim($_GET['to_date'] ?? date('Y-m-d'));
$accId      = (int)($_GET['account_id'] ?? 0);

// Preset helper URL generator
function reportUrl(string $type, string $from = '', string $to = '', int $aid = 0): string {
    return BASE_URL . "/modules/accounts/reports.php?report=$type&from_date=$from&to_date=$to&account_id=$aid";
}

$pageTitle = 'Financial & Accounting Reports';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Header & Filter Bar -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 no-print">
  <div>
    <h4 class="mb-0 fw-bold">Financial Accounting Reports</h4>
    <p class="text-muted small mb-0">Comprehensive financial statements, cash & bank books, profit & loss, balance sheet & trial balance</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/modules/accounts/index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Company Accounts
    </a>
    <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print Report
    </button>
  </div>
</div>

<!-- Report Selection Tabs (hidden on print) -->
<div class="card border-0 shadow-sm mb-4 no-print">
  <div class="card-header bg-white p-2 border-bottom-0">
    <ul class="nav nav-pills nav-fill gap-1">
      <li class="nav-item">
        <a class="nav-link fw-semibold <?= $reportType === 'cashbook' ? 'active' : '' ?>" 
           href="<?= reportUrl('cashbook', $fromDate, $toDate, $accId) ?>">
          <i class="bi bi-cash-stack me-1"></i>Cash Book
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link fw-semibold <?= $reportType === 'bankbook' ? 'active' : '' ?>" 
           href="<?= reportUrl('bankbook', $fromDate, $toDate, $accId) ?>">
          <i class="bi bi-bank me-1"></i>Bank Book
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link fw-semibold <?= $reportType === 'pnl' ? 'active' : '' ?>" 
           href="<?= reportUrl('pnl', $fromDate, $toDate) ?>">
          <i class="bi bi-graph-up-arrow me-1"></i>Profit &amp; Loss
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link fw-semibold <?= $reportType === 'balancesheet' ? 'active' : '' ?>" 
           href="<?= reportUrl('balancesheet', $fromDate, $toDate) ?>">
          <i class="bi bi-pie-chart-fill me-1"></i>Balance Sheet
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link fw-semibold <?= $reportType === 'trialbalance' ? 'active' : '' ?>" 
           href="<?= reportUrl('trialbalance', $fromDate, $toDate) ?>">
          <i class="bi bi-calculator me-1"></i>Trial Balance
        </a>
      </li>
    </ul>
  </div>
  <div class="card-body bg-light border-top py-3">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="report" value="<?= htmlspecialchars($reportType) ?>">
      
      <div class="col-md-3 col-6">
        <label class="form-label small fw-semibold mb-1">From Date</label>
        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
      </div>
      <div class="col-md-3 col-6">
        <label class="form-label small fw-semibold mb-1">To Date</label>
        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
      </div>

      <?php if (in_array($reportType, ['cashbook', 'bankbook'])): ?>
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Filter Specific Account</label>
        <select name="account_id" class="form-select form-select-sm">
          <option value="0">-- All <?= $reportType === 'cashbook' ? 'Cash' : 'Bank & Digital' ?> Accounts --</option>
          <?php 
          $typeFilter = $reportType === 'cashbook' ? 'cash' : 'bank';
          foreach (getCompanyAccounts() as $ca) {
              if ($reportType === 'cashbook' && $ca['account_type'] === 'cash') {
                  $selected = $ca['id'] === $accId ? 'selected' : '';
                  echo "<option value='{$ca['id']}' $selected>" . htmlspecialchars($ca['account_name']) . "</option>";
              } elseif ($reportType === 'bankbook' && $ca['account_type'] !== 'cash') {
                  $selected = $ca['id'] === $accId ? 'selected' : '';
                  echo "<option value='{$ca['id']}' $selected>" . htmlspecialchars($ca['account_name']) . "</option>";
              }
          }
          ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel me-1"></i>Apply Filter</button>
        <a href="<?= reportUrl($reportType, date('Y-m-01'), date('Y-m-d')) ?>" class="btn btn-outline-secondary btn-sm">This Month</a>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     1. CASH BOOK REPORT
     ============================================================ -->
<?php if ($reportType === 'cashbook'): ?>
  <?php $cb = getCashBook($fromDate, $toDate, $accId); ?>
  <div class="card border-0 shadow-sm" id="reportPrint">
    <div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
        <div>
          <h4 class="fw-bold mb-1 text-success"><i class="bi bi-cash-stack me-2"></i>CASH BOOK STATEMENT</h4>
          <div class="text-muted small">Period: <?= date('d M Y', strtotime($fromDate)) ?> to <?= date('d M Y', strtotime($toDate)) ?></div>
        </div>
        <div class="text-end">
          <div class="text-muted small fw-semibold">Net Cash Position</div>
          <div class="fw-bold fs-5 text-success">₹<?= number_format($cb['closing_balance'], 2) ?></div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
          <div class="p-3 bg-light rounded-3 text-center border">
            <div class="text-muted small fw-semibold">Opening Cash</div>
            <div class="fw-bold fs-6 mt-1">₹<?= number_format($cb['opening_balance'], 2) ?></div>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="p-3 bg-success bg-opacity-10 rounded-3 text-center border border-success border-opacity-25">
            <div class="text-success small fw-semibold">Total Cash Receipts (In)</div>
            <div class="fw-bold fs-6 text-success mt-1">+ ₹<?= number_format($cb['total_in'], 2) ?></div>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="p-3 bg-danger bg-opacity-10 rounded-3 text-center border border-danger border-opacity-25">
            <div class="text-danger small fw-semibold">Total Cash Payments (Out)</div>
            <div class="fw-bold fs-6 text-danger mt-1">- ₹<?= number_format($cb['total_out'], 2) ?></div>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="p-3 bg-primary bg-opacity-10 rounded-3 text-center border border-primary border-opacity-25">
            <div class="text-primary small fw-semibold">Closing Cash in Hand</div>
            <div class="fw-bold fs-6 text-primary mt-1">₹<?= number_format($cb['closing_balance'], 2) ?></div>
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Cash Vault Account</th>
              <th>Type / Purpose</th>
              <th>Description</th>
              <th class="text-end">Cash In (₹)</th>
              <th class="text-end">Cash Out (₹)</th>
              <th class="text-end">Cash Balance (₹)</th>
            </tr>
          </thead>
          <tbody>
            <tr class="table-secondary fw-semibold">
              <td><?= date('d M Y', strtotime($fromDate)) ?></td>
              <td colspan="3"><em>Opening Cash Balance Carried Forward</em></td>
              <td class="text-end">-</td>
              <td class="text-end">-</td>
              <td class="text-end">₹<?= number_format($cb['opening_balance'], 2) ?></td>
            </tr>
            <?php if (!empty($cb['transactions'])): ?>
              <?php foreach ($cb['transactions'] as $tx): ?>
              <tr>
                <td><?= date('d M Y', strtotime($tx['transaction_date'])) ?></td>
                <td class="fw-semibold text-dark"><?= htmlspecialchars($tx['account_name']) ?></td>
                <td><span class="badge bg-secondary"><?= strtoupper(str_replace('_',' ', $tx['transaction_type'])) ?></span></td>
                <td><?= htmlspecialchars($tx['description'] ?: '-') ?></td>
                <td class="text-end fw-semibold text-success"><?= $tx['type'] === 'debit' ? '₹' . number_format($tx['amount'], 2) : '-' ?></td>
                <td class="text-end fw-semibold text-danger"><?= $tx['type'] === 'credit' ? '₹' . number_format($tx['amount'], 2) : '-' ?></td>
                <td class="text-end fw-bold">₹<?= number_format($tx['running_balance'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7" class="text-center text-muted py-4">No cash transactions recorded during this period.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<!-- ============================================================
     2. BANK BOOK REPORT
     ============================================================ -->
<?php elseif ($reportType === 'bankbook'): ?>
  <?php $bb = getBankBook($fromDate, $toDate, $accId); ?>
  <div class="card border-0 shadow-sm" id="reportPrint">
    <div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
        <div>
          <h4 class="fw-bold mb-1 text-primary"><i class="bi bi-bank me-2"></i>BANK &amp; DIGITAL ACCOUNTS BOOK</h4>
          <div class="text-muted small">Period: <?= date('d M Y', strtotime($fromDate)) ?> to <?= date('d M Y', strtotime($toDate)) ?></div>
        </div>
        <div class="text-end">
          <div class="text-muted small fw-semibold">Net Bank Position</div>
          <div class="fw-bold fs-5 text-primary">₹<?= number_format($bb['closing_balance'], 2) ?></div>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
          <div class="p-3 bg-light rounded-3 text-center border">
            <div class="text-muted small fw-semibold">Opening Bank Balance</div>
            <div class="fw-bold fs-6 mt-1">₹<?= number_format($bb['opening_balance'], 2) ?></div>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="p-3 bg-success bg-opacity-10 rounded-3 text-center border border-success border-opacity-25">
            <div class="text-success small fw-semibold">Total Deposits (In)</div>
            <div class="fw-bold fs-6 text-success mt-1">+ ₹<?= number_format($bb['total_deposits'], 2) ?></div>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="p-3 bg-danger bg-opacity-10 rounded-3 text-center border border-danger border-opacity-25">
            <div class="text-danger small fw-semibold">Total Withdrawals (Out)</div>
            <div class="fw-bold fs-6 text-danger mt-1">- ₹<?= number_format($bb['total_withdrawals'], 2) ?></div>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="p-3 bg-primary bg-opacity-10 rounded-3 text-center border border-primary border-opacity-25">
            <div class="text-primary small fw-semibold">Closing Bank Balance</div>
            <div class="fw-bold fs-6 text-primary mt-1">₹<?= number_format($bb['closing_balance'], 2) ?></div>
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Bank Account</th>
              <th>Transaction Type</th>
              <th>Description &amp; Reference</th>
              <th class="text-end">Deposit (In ₹)</th>
              <th class="text-end">Withdrawal (Out ₹)</th>
              <th class="text-end">Balance (₹)</th>
            </tr>
          </thead>
          <tbody>
            <tr class="table-secondary fw-semibold">
              <td><?= date('d M Y', strtotime($fromDate)) ?></td>
              <td colspan="3"><em>Opening Bank Balance Carried Forward</em></td>
              <td class="text-end">-</td>
              <td class="text-end">-</td>
              <td class="text-end">₹<?= number_format($bb['opening_balance'], 2) ?></td>
            </tr>
            <?php if (!empty($bb['transactions'])): ?>
              <?php foreach ($bb['transactions'] as $tx): ?>
              <tr>
                <td><?= date('d M Y', strtotime($tx['transaction_date'])) ?></td>
                <td>
                  <div class="fw-semibold text-dark"><?= htmlspecialchars($tx['account_name']) ?></div>
                  <div class="small text-muted"><?= htmlspecialchars($tx['bank_name'] ?: '') ?> <?= $tx['account_number'] ? '('.$tx['account_number'].')' : '' ?></div>
                </td>
                <td><span class="badge bg-primary"><?= strtoupper(str_replace('_',' ', $tx['transaction_type'])) ?></span></td>
                <td><?= htmlspecialchars($tx['description'] ?: '-') ?></td>
                <td class="text-end fw-semibold text-success"><?= $tx['type'] === 'debit' ? '₹' . number_format($tx['amount'], 2) : '-' ?></td>
                <td class="text-end fw-semibold text-danger"><?= $tx['type'] === 'credit' ? '₹' . number_format($tx['amount'], 2) : '-' ?></td>
                <td class="text-end fw-bold">₹<?= number_format($tx['running_balance'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7" class="text-center text-muted py-4">No bank transactions recorded during this period.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<!-- ============================================================
     3. PROFIT & LOSS STATEMENT (P&L)
     ============================================================ -->
<?php elseif ($reportType === 'pnl'): ?>
  <?php $pnl = getProfitAndLoss($fromDate, $toDate); ?>
  <div class="card border-0 shadow-sm" id="reportPrint">
    <div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
        <div>
          <h4 class="fw-bold mb-1 text-dark"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>PROFIT &amp; LOSS STATEMENT</h4>
          <div class="text-muted small">Period: <?= date('d M Y', strtotime($fromDate)) ?> to <?= date('d M Y', strtotime($toDate)) ?></div>
        </div>
        <div class="text-end">
          <div class="text-muted small fw-semibold">Net Operating Performance</div>
          <div class="fw-bold fs-4 <?= $pnl['is_profit'] ? 'text-success' : 'text-danger' ?>">
            <?= $pnl['is_profit'] ? 'NET PROFIT: ₹' . number_format($pnl['net_profit'], 2) : 'NET LOSS: ₹' . number_format(abs($pnl['net_profit']), 2) ?>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <!-- Revenue / Income Column -->
        <div class="col-md-6">
          <div class="card border-0 bg-success bg-opacity-10 h-100">
            <div class="card-header bg-success text-white fw-bold py-2">
              <i class="bi bi-arrow-down-left-circle me-2"></i>REVENUES &amp; OPERATING INCOMES
            </div>
            <div class="card-body p-3">
              <table class="table table-borderless align-middle mb-0">
                <tbody>
                  <tr>
                    <td class="fw-semibold text-dark">Invoice Sales Revenue</td>
                    <td class="text-end fw-bold text-success">₹<?= number_format($pnl['invoice_revenue'], 2) ?></td>
                  </tr>
                  <?php if ($pnl['other_income'] > 0): ?>
                  <tr>
                    <td class="fw-semibold text-dark">Other Direct Incomes &amp; Deposits</td>
                    <td class="text-end fw-bold text-success">₹<?= number_format($pnl['other_income'], 2) ?></td>
                  </tr>
                  <?php endif; ?>
                </tbody>
                <tfoot class="border-top border-success">
                  <tr class="fs-6">
                    <td class="fw-bold text-dark">TOTAL REVENUE (A)</td>
                    <td class="text-end fw-bold text-success fs-5">₹<?= number_format($pnl['total_revenue'], 2) ?></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>

        <!-- Expenses Column -->
        <div class="col-md-6">
          <div class="card border-0 bg-danger bg-opacity-10 h-100">
            <div class="card-header bg-danger text-white fw-bold py-2">
              <i class="bi bi-arrow-up-right-circle me-2"></i>OPERATING EXPENSES &amp; PAYROLL
            </div>
            <div class="card-body p-3">
              <table class="table table-borderless align-middle mb-0">
                <tbody>
                  <?php if (!empty($pnl['expenses_by_category'])): ?>
                    <?php foreach ($pnl['expenses_by_category'] as $ec): ?>
                    <tr>
                      <td>
                        <span class="badge me-1" style="background:<?= $ec['color_code'] ?>">&nbsp;</span>
                        <span class="fw-semibold text-dark"><?= htmlspecialchars($ec['category_name']) ?></span>
                      </td>
                      <td class="text-end fw-bold text-danger">₹<?= number_format($ec['total_amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>

                  <tr>
                    <td class="fw-semibold text-dark"><i class="bi bi-people me-1 text-primary"></i>Employee Payroll &amp; Salaries</td>
                    <td class="text-end fw-bold text-danger">₹<?= number_format($pnl['salary_expenses'], 2) ?></td>
                  </tr>

                  <?php if ($pnl['other_expenses'] > 0): ?>
                  <tr>
                    <td class="fw-semibold text-dark">Other Direct Account Withdrawals</td>
                    <td class="text-end fw-bold text-danger">₹<?= number_format($pnl['other_expenses'], 2) ?></td>
                  </tr>
                  <?php endif; ?>
                </tbody>
                <tfoot class="border-top border-danger">
                  <tr class="fs-6">
                    <td class="fw-bold text-dark">TOTAL EXPENSES (B)</td>
                    <td class="text-end fw-bold text-danger fs-5">₹<?= number_format($pnl['total_expenses'], 2) ?></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Net Performance Summary Box -->
      <div class="mt-4 p-4 rounded-3 text-center border <?= $pnl['is_profit'] ? 'bg-success bg-opacity-10 border-success' : 'bg-danger bg-opacity-10 border-danger' ?>">
        <h5 class="fw-bold <?= $pnl['is_profit'] ? 'text-success' : 'text-danger' ?> mb-1">
          <?= $pnl['is_profit'] ? 'NET OPERATING PROFIT' : 'NET OPERATING LOSS' ?>: ₹<?= number_format(abs($pnl['net_profit']), 2) ?>
        </h5>
        <div class="text-muted small">Calculation: Total Revenue (₹<?= number_format($pnl['total_revenue'], 2) ?>) - Total Expenses (₹<?= number_format($pnl['total_expenses'], 2) ?>)</div>
      </div>
    </div>
  </div>

<!-- ============================================================
     4. BALANCE SHEET REPORT
     ============================================================ -->
<?php elseif ($reportType === 'balancesheet'): ?>
  <?php $bs = getBalanceSheet($toDate); ?>
  <div class="card border-0 shadow-sm" id="reportPrint">
    <div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
        <div>
          <h4 class="fw-bold mb-1 text-dark"><i class="bi bi-pie-chart-fill me-2 text-primary"></i>BALANCE SHEET</h4>
          <div class="text-muted small">As of Date: <?= date('d M Y', strtotime($toDate)) ?></div>
        </div>
        <div class="text-end">
          <div class="text-muted small fw-semibold">Total Assets Value</div>
          <div class="fw-bold fs-4 text-primary">₹<?= number_format($bs['total_assets'], 2) ?></div>
        </div>
      </div>

      <div class="row g-4">
        <!-- Assets Column -->
        <div class="col-md-6">
          <div class="card border-0 bg-light">
            <div class="card-header bg-primary text-white fw-bold py-2">
              <i class="bi bi-safe me-2"></i>ASSETS (WHAT COMPANY OWNS)
            </div>
            <div class="card-body p-3">
              <h6 class="fw-bold text-muted border-bottom pb-2">1. Cash &amp; Bank Balances</h6>
              <table class="table table-sm table-borderless mb-3">
                <tbody>
                  <?php foreach ($bs['cash_bank_accounts'] as $ca): ?>
                  <tr>
                    <td><?= htmlspecialchars($ca['account_name']) ?> <small class="text-muted">(<?= ucfirst($ca['account_type']) ?>)</small></td>
                    <td class="text-end fw-bold">₹<?= number_format($ca['current_balance'], 2) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot class="border-top">
                  <tr>
                    <td class="fw-semibold">Total Cash &amp; Liquid Balances:</td>
                    <td class="text-end fw-bold text-primary">₹<?= number_format($bs['total_cash_bank'], 2) ?></td>
                  </tr>
                </tfoot>
              </table>

              <h6 class="fw-bold text-muted border-bottom pb-2">2. Receivables &amp; Current Assets</h6>
              <table class="table table-sm table-borderless mb-0">
                <tbody>
                  <tr>
                    <td>Accounts Receivable (Pending Invoices)</td>
                    <td class="text-end fw-bold">₹<?= number_format($bs['accounts_receivable'], 2) ?></td>
                  </tr>
                </tbody>
                <tfoot class="border-top">
                  <tr class="fs-6">
                    <td class="fw-bold">TOTAL ASSETS</td>
                    <td class="text-end fw-bold text-primary fs-5">₹<?= number_format($bs['total_assets'], 2) ?></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>

        <!-- Liabilities & Equity Column -->
        <div class="col-md-6">
          <div class="card border-0 bg-light">
            <div class="card-header bg-dark text-white fw-bold py-2">
              <i class="bi bi-shield-check me-2"></i>EQUITY &amp; LIABILITIES
            </div>
            <div class="card-body p-3">
              <h6 class="fw-bold text-muted border-bottom pb-2">1. Capital &amp; Retained Earnings</h6>
              <table class="table table-sm table-borderless mb-3">
                <tbody>
                  <tr>
                    <td>Opening Capital / Equity</td>
                    <td class="text-end fw-bold">₹<?= number_format($bs['opening_equity'], 2) ?></td>
                  </tr>
                  <tr>
                    <td>Retained Earnings (Period Net Profit)</td>
                    <td class="text-end fw-bold <?= $bs['retained_earnings'] >= 0 ? 'text-success' : 'text-danger' ?>">
                      ₹<?= number_format($bs['retained_earnings'], 2) ?>
                    </td>
                  </tr>
                </tbody>
                <tfoot class="border-top">
                  <tr>
                    <td class="fw-semibold">Total Owner Equity:</td>
                    <td class="text-end fw-bold text-dark">₹<?= number_format($bs['total_equity'], 2) ?></td>
                  </tr>
                </tfoot>
              </table>

              <h6 class="fw-bold text-muted border-bottom pb-2">2. Total Balanced Liabilities &amp; Capital</h6>
              <table class="table table-sm table-borderless mb-0">
                <tbody>
                  <tr>
                    <td>Total Liabilities</td>
                    <td class="text-end fw-bold">₹0.00</td>
                  </tr>
                </tbody>
                <tfoot class="border-top">
                  <tr class="fs-6">
                    <td class="fw-bold">TOTAL LIABILITIES &amp; EQUITY</td>
                    <td class="text-end fw-bold text-dark fs-5">₹<?= number_format($bs['total_liabilities_equity'], 2) ?></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

<!-- ============================================================
     5. TRIAL BALANCE REPORT
     ============================================================ -->
<?php elseif ($reportType === 'trialbalance'): ?>
  <?php $tb = getTrialBalance($fromDate, $toDate); ?>
  <div class="card border-0 shadow-sm" id="reportPrint">
    <div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
        <div>
          <h4 class="fw-bold mb-1 text-dark"><i class="bi bi-calculator me-2 text-primary"></i>TRIAL BALANCE SUMMARY</h4>
          <div class="text-muted small">Period: <?= date('d M Y', strtotime($fromDate)) ?> to <?= date('d M Y', strtotime($toDate)) ?></div>
        </div>
        <div class="text-end">
          <div class="text-muted small fw-semibold">Balanced Total</div>
          <div class="fw-bold fs-4 text-primary">₹<?= number_format($tb['total_debit'], 2) ?></div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Account / Ledger Head</th>
              <th>Category Type</th>
              <th class="text-end" style="width:20%">Debit Balance (₹)</th>
              <th class="text-end" style="width:20%">Credit Balance (₹)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tb['rows'] as $r): ?>
            <tr>
              <td class="fw-semibold text-dark"><?= htmlspecialchars($r['account_name']) ?></td>
              <td><span class="badge bg-secondary"><?= htmlspecialchars($r['type']) ?></span></td>
              <td class="text-end fw-semibold text-success"><?= $r['debit'] > 0 ? '₹' . number_format($r['debit'], 2) : '-' ?></td>
              <td class="text-end fw-semibold text-primary"><?= $r['credit'] > 0 ? '₹' . number_format($r['credit'], 2) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot class="table-dark fs-6 fw-bold">
            <tr>
              <td colspan="2" class="text-end">TOTAL TRIAL BALANCE:</td>
              <td class="text-end text-success">₹<?= number_format($tb['total_debit'], 2) ?></td>
              <td class="text-end text-info">₹<?= number_format($tb['total_credit'], 2) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
