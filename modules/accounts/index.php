<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/currencies.php';
require_once __DIR__ . '/../../includes/accounting.php';

requireAccess('accounts');

$db = getDB();

// Fetch accounts
$accounts = getCompanyAccounts(null); // fetch all active & inactive

// Stat totals
$totalLiquidAssets = 0.00;
$totalCash = 0.00;
$totalBank = 0.00;
$activeCount = 0;

foreach ($accounts as $a) {
    if ($a['status'] === 'active') {
        $activeCount++;
        $bal = (float)$a['current_balance'];
        $totalLiquidAssets += $bal;
        if ($a['account_type'] === 'cash') {
            $totalCash += $bal;
        } else {
            $totalBank += $bal;
        }
    }
}

$pageTitle = 'Company Accounts (Bank & Cash)';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Header & Action Bar -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h4 class="mb-0 fw-bold">Company Accounts</h4>
    <p class="text-muted small mb-0">Manage cash boxes, bank accounts, opening balances, and fund transfers</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/modules/accounts/reports.php" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-file-earmark-bar-graph me-1"></i>Financial Reports
    </a>
    <a href="<?= BASE_URL ?>/modules/accounts/ledger.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-journal-text me-1"></i>Account Ledgers
    </a>
    <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#transferModal">
      <i class="bi bi-arrow-left-right me-1"></i>Transfer Funds
    </button>
    <button class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#directEntryModal">
      <i class="bi bi-plus-minus me-1"></i>Direct Deposit / Debit
    </button>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#accountModal" onclick="resetAccountForm()">
      <i class="bi bi-plus-circle me-1"></i>Add Account
    </button>
  </div>
</div>

<?php displayFlash(); ?>

<!-- Summary Stat Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-3 col-sm-6">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-3 d-flex align-items-center gap-3">
        <div class="stat-icon stat-icon-indigo">
          <i class="bi bi-wallet2 fs-4"></i>
        </div>
        <div>
          <div class="stat-value text-primary">₹<?= number_format($totalLiquidAssets, 2) ?></div>
          <div class="stat-label">Total Liquid Assets</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-3 d-flex align-items-center gap-3">
        <div class="stat-icon stat-icon-emerald">
          <i class="bi bi-cash-coin fs-4"></i>
        </div>
        <div>
          <div class="stat-value text-success">₹<?= number_format($totalCash, 2) ?></div>
          <div class="stat-label">Cash In Hand</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-3 d-flex align-items-center gap-3">
        <div class="stat-icon stat-icon-sky">
          <i class="bi bi-bank fs-4"></i>
        </div>
        <div>
          <div class="stat-value text-info">₹<?= number_format($totalBank, 2) ?></div>
          <div class="stat-label">Bank & Digital Accounts</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-sm-6">
    <div class="card border-0 shadow-sm">
      <div class="card-body p-3 d-flex align-items-center gap-3">
        <div class="stat-icon stat-icon-amber">
          <i class="bi bi-building-check fs-4"></i>
        </div>
        <div>
          <div class="stat-value text-dark"><?= $activeCount ?></div>
          <div class="stat-label">Active Accounts</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Accounts List Table -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
    <h6 class="mb-0 fw-bold"><i class="bi bi-piggy-bank me-2 text-primary"></i>Company Financial Accounts</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Account Name</th>
            <th>Type</th>
            <th>Bank & Account Details</th>
            <th class="text-end">Opening Balance</th>
            <th class="text-end">Current Balance</th>
            <th class="text-center">Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($accounts)): ?>
            <?php foreach ($accounts as $a): ?>
            <tr>
              <td>
                <div class="fw-bold text-dark"><?= htmlspecialchars($a['account_name']) ?></div>
                <?php if (!empty($a['notes'])): ?>
                <div class="small text-muted"><?= htmlspecialchars($a['notes']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php
                $typeBadges = [
                  'cash'        => 'bg-success text-white',
                  'bank'        => 'bg-primary text-white',
                  'wallet'      => 'bg-info text-dark',
                  'credit_card' => 'bg-warning text-dark',
                  'other'       => 'bg-secondary text-white'
                ];
                $badgeClass = $typeBadges[$a['account_type']] ?? 'bg-secondary';
                ?>
                <span class="badge <?= $badgeClass ?> text-uppercase" style="font-size:.7rem">
                  <?= strtoupper(str_replace('_', ' ', $a['account_type'])) ?>
                </span>
              </td>
              <td>
                <?php if ($a['account_type'] === 'cash'): ?>
                  <span class="text-muted small"><i class="bi bi-safe me-1"></i>Physical Cash Vault</span>
                <?php else: ?>
                  <div class="small fw-semibold"><?= htmlspecialchars($a['bank_name'] ?: 'Bank Account') ?></div>
                  <div class="small text-muted">
                    <?= $a['account_number'] ? 'A/C: ' . htmlspecialchars($a['account_number']) : '' ?>
                    <?= $a['ifsc_code'] ? ' • IFSC: ' . htmlspecialchars($a['ifsc_code']) : '' ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <div class="fw-semibold">₹<?= number_format($a['opening_balance'], 2) ?></div>
                <div class="small text-muted" style="font-size:.75rem">As of <?= date('d M Y', strtotime($a['opening_date'])) ?></div>
              </td>
              <td class="text-end">
                <div class="fw-bold fs-6 <?= (float)$a['current_balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                  ₹<?= number_format($a['current_balance'], 2) ?>
                </div>
              </td>
              <td class="text-center">
                <?php if ($a['status'] === 'active'): ?>
                  <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">Active</span>
                <?php else: ?>
                  <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <div class="btn-group btn-group-sm">
                  <a href="<?= BASE_URL ?>/modules/accounts/ledger.php?account_id=<?= $a['id'] ?>" 
                     class="btn btn-outline-secondary" title="View Account Ledger">
                    <i class="bi bi-journal-text"></i> Ledger
                  </a>
                  <button class="btn btn-outline-primary" 
                          onclick='editAccount(<?= json_encode($a, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'
                          title="Edit Account Details">
                    <i class="bi bi-pencil-square"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center text-muted py-4">No company accounts configured. Click "Add Account" to get started.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ============================================================
     MODALS
     ============================================================ -->

<!-- Add/Edit Account Modal -->
<div class="modal fade" id="accountModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="<?= BASE_URL ?>/modules/accounts/save_account.php" id="accountForm">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="id" id="acc_id" value="0">
        
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="accModalTitle">Add Company Account</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Account Name *</label>
            <input type="text" name="account_name" id="acc_name" class="form-control" placeholder="e.g. HDFC Main Current Account / Cash Box" required>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">Account Type *</label>
              <select name="account_type" id="acc_type" class="form-select" onchange="toggleBankFields()" required>
                <option value="bank">Bank Account</option>
                <option value="cash">Cash Account / Vault</option>
                <option value="wallet">E-Wallet / UPI Portal</option>
                <option value="credit_card">Credit Card</option>
                <option value="other">Other Account</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Status *</label>
              <select name="status" id="acc_status" class="form-select" required>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>

          <!-- Bank Specific Details -->
          <div id="bankDetailsSection">
            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label fw-semibold">Bank Name</label>
                <input type="text" name="bank_name" id="acc_bank_name" class="form-control" placeholder="e.g. HDFC Bank">
              </div>
              <div class="col-6">
                <label class="form-label fw-semibold">Account Number</label>
                <input type="text" name="account_number" id="acc_number" class="form-control" placeholder="e.g. 50200012345678">
              </div>
            </div>
            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label fw-semibold">IFSC / SWIFT Code</label>
                <input type="text" name="ifsc_code" id="acc_ifsc" class="form-control" placeholder="e.g. HDFC0001234">
              </div>
              <div class="col-6">
                <label class="form-label fw-semibold">Branch Name</label>
                <input type="text" name="branch_name" id="acc_branch" class="form-control" placeholder="e.g. MG Road Branch">
              </div>
            </div>
          </div>

          <!-- Opening Balance Details -->
          <div class="card bg-light border-0 p-3 mb-3">
            <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-clock-history me-1 text-primary"></i>Opening Balance Details</h6>
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Opening Balance (₹)</label>
                <input type="number" step="0.01" name="opening_balance" id="acc_opening_balance" class="form-control" value="0.00" min="0">
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Opening Date</label>
                <input type="date" name="opening_date" id="acc_opening_date" class="form-control" value="<?= date('Y-m-d') ?>">
              </div>
            </div>
            <div class="mt-2">
              <label class="form-label small fw-semibold">Balance Type</label>
              <div class="d-flex gap-3">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="balance_type" id="bal_debit" value="debit" checked>
                  <label class="form-check-label small" for="bal_debit">Debit / Positive (Asset)</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="balance_type" id="bal_credit" value="credit">
                  <label class="form-check-label small" for="bal_credit">Credit / Overdraft (Liability)</label>
                </div>
              </div>
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label fw-semibold">Notes / Description</label>
            <textarea name="notes" id="acc_notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Save Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Fund Transfer Modal -->
<div class="modal fade" id="transferModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="<?= BASE_URL ?>/modules/accounts/transfer.php">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="transfer">
        
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="bi bi-arrow-left-right me-2 text-success"></i>Transfer Funds Between Accounts</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">From Account (Debit / Money Out) *</label>
            <select name="from_account_id" class="form-select" required>
              <option value="">-- Select Source Account --</option>
              <?php foreach ($accounts as $a): ?>
                <?php if ($a['status'] === 'active'): ?>
                <option value="<?= $a['id'] ?>">
                  <?= htmlspecialchars($a['account_name']) ?> (Current Bal: ₹<?= number_format($a['current_balance'], 2) ?>)
                </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">To Account (Credit / Money In) *</label>
            <select name="to_account_id" class="form-select" required>
              <option value="">-- Select Destination Account --</option>
              <?php foreach ($accounts as $a): ?>
                <?php if ($a['status'] === 'active'): ?>
                <option value="<?= $a['id'] ?>">
                  <?= htmlspecialchars($a['account_name']) ?> (Current Bal: ₹<?= number_format($a['current_balance'], 2) ?>)
                </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">Transfer Amount (₹) *</label>
              <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" min="0.01" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Transfer Date *</label>
              <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label fw-semibold">Transfer Description / Notes</label>
            <input type="text" name="notes" class="form-control" placeholder="e.g. Cash deposited into Bank / ATM Withdrawal">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-arrow-right-circle me-1"></i>Record Transfer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Direct Deposit / Withdrawal Modal -->
<div class="modal fade" id="directEntryModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="<?= BASE_URL ?>/modules/accounts/transfer.php">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="direct_entry">
        
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="bi bi-plus-minus me-2 text-info"></i>Direct Account Entry (Deposit / Withdrawal)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Target Account *</label>
            <select name="account_id" class="form-select" required>
              <option value="">-- Select Account --</option>
              <?php foreach ($accounts as $a): ?>
                <?php if ($a['status'] === 'active'): ?>
                <option value="<?= $a['id'] ?>">
                  <?= htmlspecialchars($a['account_name']) ?> (Current Bal: ₹<?= number_format($a['current_balance'], 2) ?>)
                </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Entry Type *</label>
            <select name="entry_type" class="form-select" required>
              <option value="debit">Deposit / Money In (Debit Asset Account)</option>
              <option value="credit">Withdrawal / Money Out (Credit Asset Account)</option>
            </select>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">Amount (₹) *</label>
              <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" min="0.01" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Date *</label>
              <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Description / Purpose *</label>
            <input type="text" name="description" class="form-control" placeholder="e.g. Bank Interest Received / Bank Charges Deducted" required>
          </div>

          <div class="mb-2">
            <label class="form-label fw-semibold">Additional Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-info text-white"><i class="bi bi-check-circle me-1"></i>Record Entry</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function toggleBankFields() {
  const type = document.getElementById('acc_type').value;
  const bankSec = document.getElementById('bankDetailsSection');
  if (type === 'cash') {
    bankSec.style.display = 'none';
  } else {
    bankSec.style.display = 'block';
  }
}

function resetAccountForm() {
  document.getElementById('accModalTitle').textContent = 'Add Company Account';
  document.getElementById('acc_id').value = '0';
  document.getElementById('acc_name').value = '';
  document.getElementById('acc_type').value = 'bank';
  document.getElementById('acc_status').value = 'active';
  document.getElementById('acc_bank_name').value = '';
  document.getElementById('acc_number').value = '';
  document.getElementById('acc_ifsc').value = '';
  document.getElementById('acc_branch').value = '';
  document.getElementById('acc_opening_balance').value = '0.00';
  document.getElementById('acc_opening_date').value = '<?= date('Y-m-d') ?>';
  document.getElementById('bal_debit').checked = true;
  document.getElementById('acc_notes').value = '';
  toggleBankFields();
}

function editAccount(acc) {
  document.getElementById('accModalTitle').textContent = 'Edit Company Account';
  document.getElementById('acc_id').value = acc.id;
  document.getElementById('acc_name').value = acc.account_name || '';
  document.getElementById('acc_type').value = acc.account_type || 'bank';
  document.getElementById('acc_status').value = acc.status || 'active';
  document.getElementById('acc_bank_name').value = acc.bank_name || '';
  document.getElementById('acc_number').value = acc.account_number || '';
  document.getElementById('acc_ifsc').value = acc.ifsc_code || '';
  document.getElementById('acc_branch').value = acc.branch_name || '';
  document.getElementById('acc_opening_balance').value = acc.opening_balance || '0.00';
  document.getElementById('acc_opening_date').value = acc.opening_date || '<?= date('Y-m-d') ?>';
  
  if (acc.balance_type === 'credit') {
    document.getElementById('bal_credit').checked = true;
  } else {
    document.getElementById('bal_debit').checked = true;
  }
  document.getElementById('acc_notes').value = acc.notes || '';

  toggleBankFields();
  const modal = new bootstrap.Modal(document.getElementById('accountModal'));
  modal.show();
}

document.addEventListener('DOMContentLoaded', function() {
  toggleBankFields();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
