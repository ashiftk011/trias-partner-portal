<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('invoices');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/invoices/index.php');

$inv = $db->prepare("SELECT * FROM invoices WHERE id=?");
$inv->execute([$id]);
$inv = $inv->fetch();
if (!$inv) redirect(BASE_URL . '/modules/invoices/index.php');

// Don't allow editing fully paid or cancelled invoices
if (in_array($inv['status'], ['paid', 'cancelled'])) {
    setFlash('error', 'Paid or cancelled invoices cannot be edited.');
    redirect(BASE_URL . '/modules/invoices/view.php?id=' . $id);
}

$itemStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$itemStmt->execute([$id]);
$lineItems = $itemStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $clientId      = (int)$_POST['client_id'];
    $renewalId     = $_POST['renewal_id'] ? (int)$_POST['renewal_id'] : null;
    $invDate       = $_POST['invoice_date'];
    $dueDate       = $_POST['due_date'];
    $taxPercent    = (float)$_POST['tax_percent'];
    $discountType  = ($_POST['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
    $notes         = trim($_POST['notes'] ?? '');
    $termsRaw      = array_filter(array_map('trim', $_POST['terms_conditions'] ?? []));
    $termsConditions = !empty($termsRaw) ? json_encode(array_values($termsRaw)) : null;
    $advanceAmount = max(0, (float)($_POST['advance_amount'] ?? 0));
    $advanceDate   = !empty($_POST['advance_date']) ? $_POST['advance_date'] : null;

    $itemNames  = $_POST['item_name'] ?? [];
    $itemDescs  = $_POST['item_desc'] ?? [];
    $quantities = $_POST['item_qty'] ?? [];
    $unitPrices = $_POST['item_price'] ?? [];
    $items = [];
    $subtotal = 0;
    for ($i = 0; $i < count($itemNames); $i++) {
        $name = trim($itemNames[$i] ?? '');
        if ($name === '') continue;
        $desc  = trim($itemDescs[$i] ?? '');
        $qty   = max(0, (float)($quantities[$i] ?? 1));
        $price = max(0, (float)($unitPrices[$i] ?? 0));
        $amt   = round($qty * $price, 2);
        $items[] = ['name' => $name, 'desc' => $desc, 'qty' => $qty, 'price' => $price, 'amount' => $amt];
        $subtotal += $amt;
    }

    if (empty($items)) {
        setFlash('error', 'Please add at least one line item.');
        redirect(BASE_URL . '/modules/invoices/edit.php?id=' . $id);
    }

    if ($discountType === 'fixed') {
        $discountAmount  = round(min((float)($_POST['discount_value'] ?? 0), $subtotal), 2);
        $discountPercent = $subtotal > 0 ? round($discountAmount / $subtotal * 100, 4) : 0;
    } else {
        $discountPercent = max(0, min(100, (float)($_POST['discount_value'] ?? 0)));
        $discountAmount  = round($subtotal * $discountPercent / 100, 2);
    }

    $afterDiscount = $subtotal - $discountAmount;
    $taxAmount     = round($afterDiscount * $taxPercent / 100, 2);
    $totalAmount   = round($afterDiscount + $taxAmount, 2);

    // Recalculate status based on paid_amount
    $paidAmount = (float)$inv['paid_amount'];
    if ($paidAmount >= $totalAmount) {
        $newStatus = 'paid';
    } elseif ($paidAmount > 0) {
        $newStatus = 'partial';
    } elseif (strtotime($dueDate) < time()) {
        $newStatus = 'overdue';
    } else {
        $newStatus = 'pending';
    }

    $db->prepare("UPDATE invoices SET client_id=?,renewal_id=?,invoice_date=?,due_date=?,subtotal=?,discount_type=?,discount_percent=?,discount_amount=?,tax_percent=?,tax_amount=?,total_amount=?,advance_amount=?,advance_date=?,status=?,notes=?,terms_conditions=? WHERE id=?")
       ->execute([$clientId,$renewalId,$invDate,$dueDate,$subtotal,$discountType,$discountPercent,$discountAmount,$taxPercent,$taxAmount,$totalAmount,$advanceAmount,$advanceDate,$newStatus,$notes,$termsConditions,$id]);

    $db->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$id]);
    $itemStmt = $db->prepare("INSERT INTO invoice_items (invoice_id,item_name,description,quantity,unit_price,amount) VALUES (?,?,?,?,?,?)");
    foreach ($items as $item) {
        $itemStmt->execute([$id, $item['name'], $item['desc'], $item['qty'], $item['price'], $item['amount']]);
    }

    setFlash('success', 'Invoice ' . $inv['invoice_no'] . ' updated successfully.');
    redirect(BASE_URL . '/modules/invoices/view.php?id=' . $id);
}

$clients = $db->query("SELECT id,name,client_code,company FROM clients WHERE status='active' ORDER BY name")->fetchAll();
$renewals = [];
$rStmt = $db->prepare("SELECT id,plan_id,start_date,end_date,amount FROM renewals WHERE client_id=? AND status='active' ORDER BY end_date DESC");
$rStmt->execute([$inv['client_id']]);
$renewals = $rStmt->fetchAll();

$pageTitle = 'Edit Invoice ' . $inv['invoice_no'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div><h4 class="mb-0">Edit Invoice <span class="text-muted fw-normal"><?= htmlspecialchars($inv['invoice_no']) ?></span></h4></div>
</div>

<?php displayFlash(); ?>

<div class="row justify-content-center">
  <div class="col-xl-10">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-pencil-square me-2 text-warning"></i>Edit Invoice Details</div>
      <div class="card-body">
        <form method="POST" id="invoiceForm">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Client *</label>
              <select name="client_id" id="invClient" class="form-select select2" required>
                <option value="">Select Client</option>
                <?php foreach ($clients as $cl): ?>
                <option value="<?= $cl['id'] ?>" <?= $cl['id']==$inv['client_id']?'selected':'' ?>>
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
                <option value="<?= $rn['id'] ?>" data-amount="<?= $rn['amount'] ?>" <?= $rn['id']==$inv['renewal_id']?'selected':'' ?>>
                  <?= date('d M Y', strtotime($rn['start_date'])) ?> → <?= date('d M Y', strtotime($rn['end_date'])) ?> (₹<?= number_format($rn['amount'],2) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Invoice Date *</label>
              <input type="date" name="invoice_date" class="form-control" value="<?= htmlspecialchars($inv['invoice_date']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Due Date *</label>
              <input type="date" name="due_date" class="form-control" value="<?= htmlspecialchars($inv['due_date']) ?>" required>
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
                    <th style="width:4%" class="text-center">S.No</th>
                    <th style="width:22%">Item</th>
                    <th style="width:28%">Description</th>
                    <th style="width:12%">Qty</th>
                    <th style="width:17%">Unit Price (₹)</th>
                    <th style="width:13%">Amount (₹)</th>
                    <th style="width:4%"></th>
                  </tr>
                </thead>
                <tbody id="itemsBody">
                  <?php if (!empty($lineItems)): ?>
                    <?php foreach ($lineItems as $idx => $item): ?>
                    <tr class="item-row">
                      <td class="row-num text-center text-muted"><?= $idx+1 ?></td>
                      <td><input type="text" name="item_name[]" class="form-control form-control-sm" placeholder="Item name" required value="<?= htmlspecialchars($item['item_name']) ?>"></td>
                      <td><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Details (optional)" value="<?= htmlspecialchars($item['description'] ?? '') ?>"></td>
                      <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" min="0" step="0.01" value="<?= $item['quantity'] ?>"></td>
                      <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" min="0" step="0.01" value="<?= $item['unit_price'] ?>"></td>
                      <td><input type="text" class="form-control form-control-sm item-amount bg-light fw-semibold" readonly></td>
                      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-item" title="Remove"><i class="bi bi-x-lg"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                  <tr class="item-row">
                    <td class="row-num text-center text-muted">1</td>
                    <td><input type="text" name="item_name[]" class="form-control form-control-sm" placeholder="Item name" required></td>
                    <td><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Details (optional)"></td>
                    <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" min="0" step="0.01" value="1"></td>
                    <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" min="0" step="0.01" value=""></td>
                    <td><input type="text" class="form-control form-control-sm item-amount bg-light fw-semibold" readonly></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger remove-item" title="Remove"><i class="bi bi-x-lg"></i></button></td>
                  </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Totals Section -->
          <div class="row mt-4">
            <div class="col-md-6">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes for this invoice (optional)"><?= htmlspecialchars($inv['notes'] ?? '') ?></textarea>

              <div class="mt-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <label class="form-label mb-0">Terms &amp; Conditions</label>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="addTermBtn">
                    <i class="bi bi-plus-circle me-1"></i>Add Condition
                  </button>
                </div>
                <div id="termsList">
                  <?php
                  $existingTerms = [];
                  if (!empty($inv['terms_conditions'])) {
                    $decoded = json_decode($inv['terms_conditions'], true);
                    if (is_array($decoded)) $existingTerms = $decoded;
                  }
                  foreach ($existingTerms as $term): ?>
                  <div class="d-flex gap-2 mb-2 term-row">
                    <input type="text" name="terms_conditions[]" class="form-control form-control-sm" placeholder="e.g. Payment due within 15 days" value="<?= htmlspecialchars($term) ?>">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-term" title="Remove"><i class="bi bi-x-lg"></i></button>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
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
                    <div class="input-group input-group-sm" style="width:160px">
                      <button type="button" class="btn btn-outline-secondary btn-sm px-2" id="discTypeToggle" title="Switch between % and ₹"><?= $inv['discount_type']==='fixed'?'₹':'%' ?></button>
                      <input type="hidden" name="discount_type" id="discTypeHidden" value="<?= htmlspecialchars($inv['discount_type'] ?? 'percent') ?>">
                      <input type="number" name="discount_value" id="invDiscount" class="form-control" value="<?= $inv['discount_type']==='fixed' ? $inv['discount_amount'] : $inv['discount_percent'] ?>" min="0" step="0.01" <?= $inv['discount_type']!=='fixed'?'max="100"':'' ?>>
                      <span class="input-group-text" id="discSymbol"><?= $inv['discount_type']==='fixed'?'₹':'%' ?></span>
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
                      <input type="number" name="tax_percent" id="invTax" class="form-control" value="<?= $inv['tax_percent'] ?>" min="0" max="100" step="0.01">
                      <span class="input-group-text">%</span>
                    </div>
                  </div>
                  <span id="dispTax">₹0.00</span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between mb-2">
                  <span class="fw-bold fs-5">Total:</span>
                  <span class="fw-bold fs-5 text-primary" id="dispTotal">₹0.00</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <span class="text-muted">Advance Paid:</span>
                    <input type="number" name="advance_amount" id="invAdvance" class="form-control form-control-sm" style="width:110px" value="<?= $inv['advance_amount'] ?? 0 ?>" min="0" step="0.01">
                  </div>
                  <span class="text-success" id="dispAdvance">₹0.00</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <span class="text-muted">Advance Date:</span>
                    <input type="date" name="advance_date" id="invAdvanceDate" class="form-control form-control-sm" style="width:145px" value="<?= htmlspecialchars($inv['advance_date'] ?? '') ?>">
                  </div>
                </div>
                <div class="d-flex justify-content-between border-top pt-2 mt-1">
                  <span class="fw-semibold text-danger">Balance Due:</span>
                  <span class="fw-bold text-danger" id="dispBalance">₹0.00</span>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-warning"><i class="bi bi-save me-1"></i>Update Invoice</button>
            <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
let rowCounter = <?= max(count($lineItems), 1) ?>;

function renumberRows() {
  document.querySelectorAll('#itemsBody .item-row').forEach((row, i) => {
    row.querySelector('.row-num').textContent = i + 1;
  });
}

function calcRow(row) {
  const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
  const price = parseFloat(row.querySelector('.item-price').value) || 0;
  row.querySelector('.item-amount').value = '₹' + (qty * price).toFixed(2);
  calcTotals();
}

function calcTotals() {
  let subtotal = 0;
  document.querySelectorAll('#itemsBody .item-row').forEach(row => {
    subtotal += (parseFloat(row.querySelector('.item-qty').value) || 0) * (parseFloat(row.querySelector('.item-price').value) || 0);
  });

  const discType = document.getElementById('discTypeHidden').value;
  const discVal  = parseFloat(document.getElementById('invDiscount').value) || 0;
  const discAmt  = discType === 'fixed' ? Math.min(discVal, subtotal) : subtotal * discVal / 100;
  const afterDisc = subtotal - discAmt;
  const taxPct = parseFloat(document.getElementById('invTax').value) || 0;
  const taxAmt = afterDisc * taxPct / 100;
  const total = afterDisc + taxAmt;
  const advance = parseFloat(document.getElementById('invAdvance').value) || 0;
  const balance = Math.max(0, total - advance);

  document.getElementById('dispSubtotal').textContent = '₹' + subtotal.toFixed(2);
  document.getElementById('dispDiscount').textContent = '-₹' + discAmt.toFixed(2);
  document.getElementById('dispAfterDiscount').textContent = '₹' + afterDisc.toFixed(2);
  document.getElementById('dispTax').textContent = '₹' + taxAmt.toFixed(2);
  document.getElementById('dispTotal').textContent = '₹' + total.toFixed(2);
  document.getElementById('dispAdvance').textContent = '₹' + advance.toFixed(2);
  document.getElementById('dispBalance').textContent = '₹' + balance.toFixed(2);
}

function addRow(name = '', desc = '', qty = 1, price = '') {
  rowCounter++;
  const tr = document.createElement('tr');
  tr.className = 'item-row';
  tr.innerHTML = `
    <td class="row-num text-center text-muted">${rowCounter}</td>
    <td><input type="text" name="item_name[]" class="form-control form-control-sm" placeholder="Item name" required value="${name}"></td>
    <td><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Details (optional)" value="${desc}"></td>
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

document.querySelectorAll('#itemsBody .item-row').forEach(row => {
  attachRowEvents(row);
  calcRow(row);
});

document.getElementById('addItemBtn').addEventListener('click', () => addRow());
document.getElementById('invDiscount').addEventListener('input', calcTotals);
document.getElementById('invTax').addEventListener('input', calcTotals);
document.getElementById('invAdvance').addEventListener('input', calcTotals);

document.getElementById('discTypeToggle').addEventListener('click', function() {
  const hidden = document.getElementById('discTypeHidden');
  const symbol = document.getElementById('discSymbol');
  if (hidden.value === 'percent') {
    hidden.value = 'fixed';
    this.textContent = '₹';
    symbol.textContent = '₹';
    document.getElementById('invDiscount').removeAttribute('max');
  } else {
    hidden.value = 'percent';
    this.textContent = '%';
    symbol.textContent = '%';
    document.getElementById('invDiscount').setAttribute('max', '100');
  }
  calcTotals();
});

document.getElementById('invClient').addEventListener('change', function() {
  const clientId = this.value;
  const renewalSel = document.getElementById('invRenewal');
  renewalSel.innerHTML = '<option value="">None</option>';
  if (clientId) {
  fetch('<?= BASE_URL ?>/modules/invoices/get_renewals.php?client_id=' + clientId)
    .then(r => r.json())
    .then(data => {
      data.forEach(r => {
        const opt = new Option(r.label, r.id);
        opt.dataset.amount = r.amount;
        renewalSel.appendChild(opt);
      });
    });

    // Fetch default terms for the client's project
  fetch('<?= BASE_URL ?>/modules/clients/get_client.php?id=' + clientId)
    .then(r => r.json())
    .then(c => {
      if (c.default_invoice_terms) {
        const currentTerms = document.querySelectorAll('#termsList .term-row');
        if (currentTerms.length > 0) {
          if (!confirm('Load default terms for this client\'s project? This will NOT remove your existing terms.')) return;
        }
        const terms = c.default_invoice_terms.split('\n');
        terms.forEach(t => {
          if (t.trim()) addTermRow(t.trim());
        });
      }
    });
}
});

// ---- Terms & Conditions ----
function addTermRow(value = '') {
  const div = document.createElement('div');
  div.className = 'd-flex gap-2 mb-2 term-row';
  div.innerHTML = `
    <input type="text" name="terms_conditions[]" class="form-control form-control-sm" placeholder="e.g. Payment due within 15 days" value="${value.replace(/"/g, '&quot;')}">
    <button type="button" class="btn btn-sm btn-outline-danger remove-term" title="Remove"><i class="bi bi-x-lg"></i></button>
  `;
  div.querySelector('.remove-term').addEventListener('click', () => div.remove());
  document.getElementById('termsList').appendChild(div);
}
document.getElementById('addTermBtn').addEventListener('click', () => addTermRow());

// Attach remove handlers to pre-rendered rows
document.querySelectorAll('#termsList .term-row').forEach(row => {
  row.querySelector('.remove-term').addEventListener('click', () => row.remove());
});

calcTotals();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
