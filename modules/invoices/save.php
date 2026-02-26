<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('invoices');

$db = getDB();
$preClientId  = isset($_GET['client_id'])  ? (int)$_GET['client_id']  : 0;
$preRenewalId = isset($_GET['renewal_id']) ? (int)$_GET['renewal_id'] : 0;

$preClient = $preRenewal = null;
if ($preClientId) {
    $stmt = $db->prepare("SELECT c.*,p.name as project_name,pl.name as plan_name,pl.price as plan_price FROM clients c LEFT JOIN projects p ON p.id=c.project_id LEFT JOIN plans pl ON pl.id=c.plan_id WHERE c.id=?");
    $stmt->execute([$preClientId]);
    $preClient = $stmt->fetch();
}
if ($preRenewalId) {
    $stmt = $db->prepare("SELECT * FROM renewals WHERE id=?");
    $stmt->execute([$preRenewalId]);
    $preRenewal = $stmt->fetch();
    if ($preRenewal) $preClientId = $preRenewal['client_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $clientId       = (int)$_POST['client_id'];
    $renewalId      = $_POST['renewal_id'] ? (int)$_POST['renewal_id'] : null;
    $invDate        = $_POST['invoice_date'];
    $dueDate        = $_POST['due_date'];
    $taxPercent     = (float)$_POST['tax_percent'];
    $discountPercent= (float)($_POST['discount_percent'] ?? 0);
    $notes          = trim($_POST['notes'] ?? '');
    $userId         = currentUser()['id'];

    // Parse line items
    $descriptions = $_POST['item_desc'] ?? [];
    $quantities   = $_POST['item_qty'] ?? [];
    $unitPrices   = $_POST['item_price'] ?? [];
    $items = [];
    $subtotal = 0;
    for ($i = 0; $i < count($descriptions); $i++) {
        $desc = trim($descriptions[$i] ?? '');
        if ($desc === '') continue;
        $qty   = max(0, (float)($quantities[$i] ?? 1));
        $price = max(0, (float)($unitPrices[$i] ?? 0));
        $amt   = round($qty * $price, 2);
        $items[] = ['desc' => $desc, 'qty' => $qty, 'price' => $price, 'amount' => $amt];
        $subtotal += $amt;
    }

    if (empty($items)) {
        setFlash('error', 'Please add at least one line item.');
        redirect(BASE_URL . '/modules/invoices/save.php?client_id=' . $clientId);
    }

    $discountAmount = round($subtotal * $discountPercent / 100, 2);
    $afterDiscount  = $subtotal - $discountAmount;
    $taxAmount      = round($afterDiscount * $taxPercent / 100, 2);
    $totalAmount    = round($afterDiscount + $taxAmount, 2);

    // Generate invoice number
    $lastInv = $db->query("SELECT invoice_no FROM invoices ORDER BY id DESC LIMIT 1")->fetchColumn();
    $lastNum = $lastInv ? (int)preg_replace('/\D/','',$lastInv) : 0;
    $invoiceNo = 'INV-' . str_pad($lastNum+1, 5, '0', STR_PAD_LEFT);

    $db->prepare("INSERT INTO invoices (invoice_no,client_id,renewal_id,invoice_date,due_date,subtotal,discount_percent,discount_amount,tax_percent,tax_amount,total_amount,paid_amount,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,'pending',?,?)")
       ->execute([$invoiceNo,$clientId,$renewalId,$invDate,$dueDate,$subtotal,$discountPercent,$discountAmount,$taxPercent,$taxAmount,$totalAmount,$notes,$userId]);

    $newId = $db->lastInsertId();

    // Save line items
    $itemStmt = $db->prepare("INSERT INTO invoice_items (invoice_id,description,quantity,unit_price,amount) VALUES (?,?,?,?,?)");
    foreach ($items as $item) {
        $itemStmt->execute([$newId, $item['desc'], $item['qty'], $item['price'], $item['amount']]);
    }

    setFlash('success',"Invoice {$invoiceNo} created successfully.");
    redirect(BASE_URL . '/modules/invoices/view.php?id=' . $newId);
}

$clients  = $db->query("SELECT id,name,client_code,company FROM clients WHERE status='active' ORDER BY name")->fetchAll();
$renewals = [];
if ($preClientId) {
    $rStmt = $db->prepare("SELECT id,plan_id,start_date,end_date,amount FROM renewals WHERE client_id=? AND status='active' ORDER BY end_date DESC");
    $rStmt->execute([$preClientId]);
    $renewals = $rStmt->fetchAll();
}

$pageTitle = 'Create Invoice';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div><h4 class="mb-0">Create Invoice</h4></div>
</div>

<?php displayFlash(); ?>

<div class="row justify-content-center">
  <div class="col-xl-10">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-receipt me-2 text-primary"></i>Invoice Details</div>
      <div class="card-body">
        <form method="POST" id="invoiceForm">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Client *</label>
              <select name="client_id" id="invClient" class="form-select select2" required>
                <option value="">Select Client</option>
                <?php foreach ($clients as $cl): ?>
                <option value="<?= $cl['id'] ?>" <?= $cl['id']==$preClientId?'selected':'' ?>>
                  <?= htmlspecialchars($cl['client_code'].' - '.$cl['name'].($cl['company']?' ('.$cl['company'].')':'')) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Linked Renewal</label>
              <select name="renewal_id" id="invRenewal" class="form-select select2">
                <option value="">None</option>
                <?php foreach ($renewals as $rn): ?>
                <option value="<?= $rn['id'] ?>" data-amount="<?= $rn['amount'] ?>" <?= $rn['id']==$preRenewalId?'selected':'' ?>>
                  <?= date('d M Y', strtotime($rn['start_date'])) ?> → <?= date('d M Y', strtotime($rn['end_date'])) ?> (₹<?= number_format($rn['amount'],2) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Invoice Date *</label>
              <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Due Date *</label>
              <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+15 days')) ?>" required>
            </div>
          </div>

          <!-- Line Items -->
          <div class="mt-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="fw-semibold text-muted mb-0"><i class="bi bi-list-ul me-1"></i>Line Items</h6>
              <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">
                <i class="bi bi-plus-circle me-1"></i>Add Item
              </button>
            </div>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0" id="itemsTable">
                <thead class="table-light">
                  <tr>
                    <th style="width:5%">#</th>
                    <th style="width:45%">Description</th>
                    <th style="width:15%">Qty</th>
                    <th style="width:15%">Unit Price (₹)</th>
                    <th style="width:15%">Amount (₹)</th>
                    <th style="width:5%"></th>
                  </tr>
                </thead>
                <tbody id="itemsBody">
                  <tr class="item-row">
                    <td class="row-num text-center text-muted">1</td>
                    <td><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Service description..." required value="<?= $preClient ? htmlspecialchars($preClient['plan_name'] ?? 'Service Charges') : '' ?>"></td>
                    <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" min="0" step="0.01" value="1"></td>
                    <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" min="0" step="0.01" value="<?= $preClient ? $preClient['plan_price'] : '' ?>"></td>
                    <td><input type="text" class="form-control form-control-sm item-amount bg-light fw-semibold" readonly></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-item" title="Remove"><i class="bi bi-x-lg"></i></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Totals Section -->
          <div class="row mt-4">
            <div class="col-md-6">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="3" placeholder="Payment terms, services included, etc."></textarea>
            </div>
            <div class="col-md-6">
              <div class="bg-light rounded p-3 border">
                <div class="d-flex justify-content-between mb-2">
                  <span class="text-muted">Sub Total:</span>
                  <span class="fw-semibold" id="dispSubtotal">₹0.00</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <span class="text-muted">Discount:</span>
                    <div class="input-group input-group-sm" style="width:100px">
                      <input type="number" name="discount_percent" id="invDiscount" class="form-control" value="0" min="0" max="100" step="0.01">
                      <span class="input-group-text">%</span>
                    </div>
                  </div>
                  <span class="text-danger" id="dispDiscount">-₹0.00</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                  <span class="text-muted">After Discount:</span>
                  <span class="fw-semibold" id="dispAfterDiscount">₹0.00</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <span class="text-muted">GST / Tax:</span>
                    <div class="input-group input-group-sm" style="width:100px">
                      <input type="number" name="tax_percent" id="invTax" class="form-control" value="18" min="0" max="100" step="0.01">
                      <span class="input-group-text">%</span>
                    </div>
                  </div>
                  <span id="dispTax">₹0.00</span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between">
                  <span class="fw-bold fs-5">Total:</span>
                  <span class="fw-bold fs-5 text-primary" id="dispTotal">₹0.00</span>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Create Invoice</button>
            <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// ---- Line Items Logic ----
let rowCounter = 1;

function renumberRows() {
  document.querySelectorAll('#itemsBody .item-row').forEach((row, i) => {
    row.querySelector('.row-num').textContent = i + 1;
  });
}

function calcRow(row) {
  const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
  const price = parseFloat(row.querySelector('.item-price').value) || 0;
  const amt = qty * price;
  row.querySelector('.item-amount').value = '₹' + amt.toFixed(2);
  calcTotals();
}

function calcTotals() {
  let subtotal = 0;
  document.querySelectorAll('#itemsBody .item-row').forEach(row => {
    const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
    const price = parseFloat(row.querySelector('.item-price').value) || 0;
    subtotal += qty * price;
  });

  const discPct = parseFloat(document.getElementById('invDiscount').value) || 0;
  const discAmt = subtotal * discPct / 100;
  const afterDisc = subtotal - discAmt;
  const taxPct = parseFloat(document.getElementById('invTax').value) || 0;
  const taxAmt = afterDisc * taxPct / 100;
  const total = afterDisc + taxAmt;

  document.getElementById('dispSubtotal').textContent = '₹' + subtotal.toFixed(2);
  document.getElementById('dispDiscount').textContent = '-₹' + discAmt.toFixed(2);
  document.getElementById('dispAfterDiscount').textContent = '₹' + afterDisc.toFixed(2);
  document.getElementById('dispTax').textContent = '₹' + taxAmt.toFixed(2);
  document.getElementById('dispTotal').textContent = '₹' + total.toFixed(2);
}

function addRow(desc = '', qty = 1, price = '') {
  rowCounter++;
  const tr = document.createElement('tr');
  tr.className = 'item-row';
  tr.innerHTML = `
    <td class="row-num text-center text-muted">${rowCounter}</td>
    <td><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Service description..." required value="${desc}"></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" min="0" step="0.01" value="${qty}"></td>
    <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" min="0" step="0.01" value="${price}"></td>
    <td><input type="text" class="form-control form-control-sm item-amount bg-light fw-semibold" readonly></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-item" title="Remove"><i class="bi bi-x-lg"></i></button></td>
  `;
  document.getElementById('itemsBody').appendChild(tr);
  attachRowEvents(tr);
  renumberRows();
  calcTotals();
}

function attachRowEvents(row) {
  row.querySelector('.item-qty').addEventListener('input', () => calcRow(row));
  row.querySelector('.item-price').addEventListener('input', () => calcRow(row));
  row.querySelector('.remove-item').addEventListener('click', () => {
    if (document.querySelectorAll('#itemsBody .item-row').length > 1) {
      row.remove();
      renumberRows();
      calcTotals();
    }
  });
}

// Init existing rows
document.querySelectorAll('#itemsBody .item-row').forEach(row => {
  attachRowEvents(row);
  calcRow(row);
});

document.getElementById('addItemBtn').addEventListener('click', () => addRow());
document.getElementById('invDiscount').addEventListener('input', calcTotals);
document.getElementById('invTax').addEventListener('input', calcTotals);

// ---- Renewal auto-fill ----
$('#invRenewal').on('change', function() {
  const amt = this.options[this.selectedIndex].dataset.amount;
  if (amt) {
    const firstRow = document.querySelector('#itemsBody .item-row');
    if (firstRow) {
      firstRow.querySelector('.item-price').value = parseFloat(amt);
      calcRow(firstRow);
    }
  }
});

<?php if ($preRenewalId): ?> if ($('#invRenewal').val()) $('#invRenewal').trigger('change'); <?php endif; ?>

$('#invClient').on('change', function() {
  const clientId = this.value;
  $('#invRenewal').html('<option value="">None</option>');
  if (clientId) {
    $.getJSON('<?= BASE_URL ?>/modules/invoices/get_renewals.php?client_id='+clientId, function(data) {
      data.forEach(r => {
        $('#invRenewal').append(`<option value="${r.id}" data-amount="${r.amount}">${r.label}</option>`);
      });
    });
  }
});

calcTotals();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
