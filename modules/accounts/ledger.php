<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/currencies.php';
require_once __DIR__ . '/../../includes/accounting.php';

requireAccess('accounts');

$db = getDB();
$accounts = getCompanyAccounts();

$accountId  = (int)($_GET['account_id'] ?? ($accounts[0]['id'] ?? 0));
$fromDate   = trim($_GET['from_date'] ?? date('Y-m-01')); // first day of current month
$toDate     = trim($_GET['to_date'] ?? date('Y-m-d'));
$search     = trim($_GET['q'] ?? '');

$ledgerData = getAccountLedger($accountId, $fromDate, $toDate, $search);
$selectedAccount = $ledgerData['account'];

$pageTitle = 'Account Ledger Statement';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Action & Filter Bar (hidden on print) -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 no-print">
  <div>
    <h4 class="mb-0 fw-bold">Account Ledger</h4>
    <p class="text-muted small mb-0">Detailed transaction statement and running balances for company accounts</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/modules/accounts/index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Back to Accounts
    </a>
    <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print Statement
    </button>
  </div>
</div>

<!-- Filters Card (hidden on print) -->
<div class="card border-0 shadow-sm mb-4 no-print">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label small fw-semibold mb-1">Select Account</label>
        <select name="account_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ($accounts as $a): ?>
          <option value="<?= $a['id'] ?>" <?= $a['id'] === $accountId ? 'selected' : '' ?>>
            <?= htmlspecialchars($a['account_name']) ?> (<?= strtoupper($a['account_type']) ?>) — ₹<?= number_format($a['current_balance'], 2) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2 col-6">
        <label class="form-label small fw-semibold mb-1">From Date</label>
        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
      </div>

      <div class="col-md-2 col-6">
        <label class="form-label small fw-semibold mb-1">To Date</label>
        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
      </div>

      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Search Keywords</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Description, notes..." value="<?= htmlspecialchars($search) ?>">
      </div>

      <div class="col-md-1 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm w-100" title="Filter"><i class="bi bi-search"></i></button>
        <a href="<?= BASE_URL ?>/modules/accounts/ledger.php?account_id=<?= $accountId ?>" class="btn btn-light btn-sm" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
      </div>
    </form>
  </div>
</div>

<?php if ($selectedAccount): ?>
<!-- Statement Card Header -->
<div class="card border-0 shadow-sm mb-4" id="ledgerPrint">
  <div class="card-body p-4">
    <!-- Printable Header -->
    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
      <div>
        <h4 class="fw-bold mb-1 text-primary"><?= htmlspecialchars($selectedAccount['account_name']) ?></h4>
        <div class="text-muted small">
          <span class="badge bg-secondary text-uppercase me-2"><?= $selectedAccount['account_type'] ?></span>
          <?= $selectedAccount['bank_name'] ? 'Bank: ' . htmlspecialchars($selectedAccount['bank_name']) : '' ?>
          <?= $selectedAccount['account_number'] ? ' • A/C: ' . htmlspecialchars($selectedAccount['account_number']) : '' ?>
          <?= $selectedAccount['ifsc_code'] ? ' • IFSC: ' . htmlspecialchars($selectedAccount['ifsc_code']) : '' ?>
        </div>
      </div>
      <div class="text-end">
        <div class="fw-bold text-dark fs-5">LEDGER STATEMENT</div>
        <div class="small text-muted">
          Period: <?= $fromDate ? date('d M Y', strtotime($fromDate)) : 'Start' ?> to <?= $toDate ? date('d M Y', strtotime($toDate)) : 'Present' ?>
        </div>
      </div>
    </div>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4">
      <div class="col-md-3 col-6">
        <div class="p-3 bg-light rounded-3 text-center border">
          <div class="text-muted small fw-semibold">Opening Balance</div>
          <div class="fw-bold fs-6 text-dark mt-1">₹<?= number_format($ledgerData['opening_balance'], 2) ?></div>
        </div>
      </div>
      <div class="col-md-3 col-6">
        <div class="p-3 bg-success bg-opacity-10 rounded-3 text-center border border-success border-opacity-25">
          <div class="text-success small fw-semibold">Total Debits (In)</div>
          <div class="fw-bold fs-6 text-success mt-1">+ ₹<?= number_format($ledgerData['total_debit'], 2) ?></div>
        </div>
      </div>
      <div class="col-md-3 col-6">
        <div class="p-3 bg-danger bg-opacity-10 rounded-3 text-center border border-danger border-opacity-25">
          <div class="text-danger small fw-semibold">Total Credits (Out)</div>
          <div class="fw-bold fs-6 text-danger mt-1">- ₹<?= number_format($ledgerData['total_credit'], 2) ?></div>
        </div>
      </div>
      <div class="col-md-3 col-6">
        <div class="p-3 bg-primary bg-opacity-10 rounded-3 text-center border border-primary border-opacity-25">
          <div class="text-primary small fw-semibold">Closing Balance</div>
          <div class="fw-bold fs-6 text-primary mt-1">₹<?= number_format($ledgerData['closing_balance'], 2) ?></div>
        </div>
      </div>
    </div>

    <!-- Ledger Table -->
    <div class="table-responsive">
      <table class="table table-bordered table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:10%">Date</th>
            <th style="width:18%">Transaction Type</th>
            <th style="width:36%">Description & Reference</th>
            <th style="width:12%" class="text-end">Debit (In ₹)</th>
            <th style="width:12%" class="text-end">Credit (Out ₹)</th>
            <th style="width:12%" class="text-end">Balance (₹)</th>
          </tr>
        </thead>
        <tbody>
          <!-- Opening Balance Row -->
          <tr class="table-secondary fw-semibold">
            <td><?= $fromDate ? date('d M Y', strtotime($fromDate)) : '-' ?></td>
            <td colspan="2"><em>Opening Balance Carried Forward</em></td>
            <td class="text-end">-</td>
            <td class="text-end">-</td>
            <td class="text-end">₹<?= number_format($ledgerData['opening_balance'], 2) ?></td>
          </tr>

          <?php if (!empty($ledgerData['transactions'])): ?>
            <?php foreach ($ledgerData['transactions'] as $tx): ?>
            <tr>
              <td><?= date('d M Y', strtotime($tx['transaction_date'])) ?></td>
              <td>
                <?php
                $txTypeBadges = [
                  'invoice_payment' => 'bg-success',
                  'advance_payment' => 'bg-info text-dark',
                  'expense'         => 'bg-danger',
                  'salary'          => 'bg-warning text-dark',
                  'transfer_in'     => 'bg-primary',
                  'transfer_out'    => 'bg-secondary',
                  'direct_credit'   => 'bg-success',
                  'direct_debit'    => 'bg-danger',
                  'opening_balance' => 'bg-dark'
                ];
                $bCls = $txTypeBadges[$tx['transaction_type']] ?? 'bg-secondary';
                ?>
                <span class="badge <?= $bCls ?> small">
                  <?= strtoupper(str_replace('_', ' ', $tx['transaction_type'])) ?>
                </span>
              </td>
              <td>
                <div class="fw-semibold text-dark"><?= htmlspecialchars($tx['description'] ?: '-') ?></div>
                <?php if (!empty($tx['notes'])): ?>
                <div class="small text-muted"><?= htmlspecialchars($tx['notes']) ?></div>
                <?php endif; ?>
                <?php if ($tx['created_by_name']): ?>
                <div class="small text-muted" style="font-size:.75rem">Recorded by: <?= htmlspecialchars($tx['created_by_name']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-end fw-semibold text-success">
                <?= $tx['type'] === 'debit' ? '₹' . number_format($tx['amount'], 2) : '-' ?>
              </td>
              <td class="text-end fw-semibold text-danger">
                <?= $tx['type'] === 'credit' ? '₹' . number_format($tx['amount'], 2) : '-' ?>
              </td>
              <td class="text-end fw-bold">
                ₹<?= number_format($tx['running_balance'], 2) ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="text-center text-muted py-4">No transactions recorded for this account during the selected period.</td>
            </tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
          <tr>
            <td colspan="3" class="text-end">Total Period Activity & Closing Balance:</td>
            <td class="text-end text-success">+ ₹<?= number_format($ledgerData['total_debit'], 2) ?></td>
            <td class="text-end text-danger">- ₹<?= number_format($ledgerData['total_credit'], 2) ?></td>
            <td class="text-end text-primary">₹<?= number_format($ledgerData['closing_balance'], 2) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>

  </div>
</div>
<?php else: ?>
<div class="alert alert-warning">No company accounts found. Please configure an account first.</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
