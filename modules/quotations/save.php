<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/currencies.php';
requireAccess('quotations');

$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);
$quotation = null;
$items     = [];

if ($id) {
    $stmt = $db->prepare("SELECT * FROM quotations WHERE id=?");
    $stmt->execute([$id]);
    $quotation = $stmt->fetch();
    if (!$quotation) { setFlash('error','Quotation not found.'); redirect(BASE_URL.'/modules/quotations/index.php'); }
    $si = $db->prepare("SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY id");
    $si->execute([$id]); $items = $si->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title      = trim($_POST['title'] ?? '');
    $project_id = (int)($_POST['project_id'] ?? 0);
    $lead_id    = $_POST['lead_id']   ? (int)$_POST['lead_id']   : null;
    $client_id  = $_POST['client_id'] ? (int)$_POST['client_id'] : null;
    $valid_until= $_POST['valid_until'] ?: null;
    $tax_percent   = (float)($_POST['tax_percent'] ?? 18);
    $status        = $_POST['status']  ?? 'draft';
    $notes         = trim($_POST['notes'] ?? '');
    $currency      = isset(CURRENCIES[$_POST['currency'] ?? '']) ? $_POST['currency'] : 'INR';
    $discount_type = in_array($_POST['discount_type'] ?? '', ['before_gst','after_gst']) ? $_POST['discount_type'] : 'after_gst';

    $descs       = $_POST['item_desc']       ?? [];
    $price_types = $_POST['item_price_type'] ?? [];
    $qtys        = $_POST['item_qty']        ?? [];
    $prices      = $_POST['item_price']      ?? [];

    $subtotal = 0;
    $lineItems = [];
    foreach ($descs as $i => $desc) {
        if (!trim($desc)) continue;
        $qty   = (float)($qtys[$i]   ?? 1);
        $price = (float)($prices[$i] ?? 0);
        $ptype = $price_types[$i] ?? 'one_time';
        $amt   = round($qty * $price, 2);
        $subtotal += $amt;
        $lineItems[] = ['desc'=>trim($desc),'price_type'=>$ptype,'qty'=>$qty,'price'=>$price,'amount'=>$amt];
    }
    $discount = (float)($_POST['discount'] ?? 0);

    if ($discount_type === 'before_gst') {
        // Discount applied on subtotal, GST calculated on discounted base
        $discountedBase = max(0, $subtotal - $discount);
        $taxAmt         = round($discountedBase * $tax_percent / 100, 2);
        $totalAmount    = $discountedBase + $taxAmt;   // this IS the net payable
        $netPayable     = $totalAmount;
    } else {
        // Discount applied after GST (default)
        $taxAmt      = round($subtotal * $tax_percent / 100, 2);
        $totalAmount = $subtotal + $taxAmt;
        $netPayable  = max(0, $totalAmount - $discount);
    }

    $termsRaw    = array_filter(array_map('trim', $_POST['terms_conditions'] ?? []));
    $termsJson   = !empty($termsRaw) ? json_encode(array_values($termsRaw)) : null;

    if (!$title || !$project_id) {
        setFlash('error','Title and Project are required.');
        redirect(BASE_URL.'/modules/quotations/save.php'.($id ? "?id=$id" : ''));
    }

    if ($id) {
        $db->prepare("UPDATE quotations SET title=?,project_id=?,lead_id=?,client_id=?,valid_until=?,
            subtotal=?,discount=?,discount_type=?,tax_percent=?,tax_amount=?,total_amount=?,status=?,notes=?,terms_conditions=?,currency=? WHERE id=?")
           ->execute([$title,$project_id,$lead_id,$client_id,$valid_until,
                       $subtotal,$discount,$discount_type,$tax_percent,$taxAmt,$netPayable,$status,$notes,$termsJson,$currency,$id]);
        $db->prepare("DELETE FROM quotation_items WHERE quotation_id=?")->execute([$id]);
        setFlash('success','Quotation updated.');
    } else {
        $last = $db->query("SELECT quotation_no FROM quotations ORDER BY id DESC LIMIT 1")->fetchColumn();
        $num  = $last && preg_match('/QTN-(\d+)/', $last, $m) ? (int)$m[1]+1 : 1;
        $no   = 'QTN-'.str_pad($num, 4, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO quotations (quotation_no,title,project_id,lead_id,client_id,valid_until,
            subtotal,discount,discount_type,tax_percent,tax_amount,total_amount,status,notes,terms_conditions,currency,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$no,$title,$project_id,$lead_id,$client_id,$valid_until,
                       $subtotal,$discount,$discount_type,$tax_percent,$taxAmt,$netPayable,$status,$notes,$termsJson,$currency,$user['id']]);
        $id = (int)$db->lastInsertId();
        setFlash('success',"Quotation $no created.");
    }

    $ins = $db->prepare("INSERT INTO quotation_items (quotation_id,description,price_type,quantity,unit_price,amount) VALUES (?,?,?,?,?,?)");
    foreach ($lineItems as $li) { $ins->execute([$id,$li['desc'],$li['price_type'],$li['qty'],$li['price'],$li['amount']]); }

    redirect(BASE_URL.'/modules/quotations/save.php?id='.$id);
}

$projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
$leads    = $db->query("SELECT id,name,company FROM leads ORDER BY name")->fetchAll();
$clients  = $db->query("SELECT id,name,company FROM clients ORDER BY name")->fetchAll();

$q = $quotation ?? ['title'=>'','project_id'=>'','lead_id'=>'','client_id'=>'','valid_until'=>'',
     'subtotal'=>0,'discount'=>0,'discount_type'=>'after_gst','tax_percent'=>18,'tax_amount'=>0,'total_amount'=>0,'status'=>'draft','notes'=>'','terms_conditions'=>null,'currency'=>'INR'];

$pageTitle = $id ? 'Edit Quotation' : 'New Quotation';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/quotations/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0"><?= $id ? 'Edit Quotation' : 'New Quotation' ?></h4>
    <p class="text-muted small mb-0"><?= $id ? htmlspecialchars($quotation['quotation_no'] ?? '') : 'Create a formal price quotation' ?></p>
  </div>
</div>

<?php displayFlash(); ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

  <div class="row g-4">
    <div class="col-xl-8">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-calculator me-2 text-primary"></i>Quotation Details</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small fw-semibold">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required value="<?= htmlspecialchars($q['title']) ?>" placeholder="e.g. Website Development Quotation">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Project <span class="text-danger">*</span></label>
              <select name="project_id" id="quotationProject" class="form-select" required>
                <option value="">Select Project</option>
                <?php foreach ($projects as $pr): ?>
                <option value="<?= $pr['id'] ?>" <?= $q['project_id']==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Currency</label>
              <select name="currency" id="currencySelect" class="form-select">
                <?php foreach (CURRENCIES as $code => $info): ?>
                <option value="<?= $code ?>" <?= ($q['currency'] ?? 'INR') === $code ? 'selected' : '' ?>>
                  <?= $code ?> — <?= htmlspecialchars($info['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Valid Until</label>
              <input type="date" name="valid_until" class="form-control" value="<?= htmlspecialchars($q['valid_until'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Status</label>
              <select name="status" class="form-select">
                <option value="draft"    <?= $q['status']==='draft'   ?'selected':'' ?>>Draft</option>
                <option value="sent"     <?= $q['status']==='sent'    ?'selected':'' ?>>Sent</option>
                <option value="accepted" <?= $q['status']==='accepted'?'selected':'' ?>>Accepted</option>
                <option value="rejected" <?= $q['status']==='rejected'?'selected':'' ?>>Rejected</option>
                <option value="expired"  <?= $q['status']==='expired' ?'selected':'' ?>>Expired</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Link to Client</label>
              <select name="client_id" class="form-select select2">
                <option value="">Not linked</option>
                <?php foreach ($clients as $cl): ?>
                <option value="<?= $cl['id'] ?>" <?= $q['client_id']==$cl['id']?'selected':'' ?>><?= htmlspecialchars($cl['name'] . ($cl['company'] ? ' — '.$cl['company'] : '')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Link to Lead</label>
              <select name="lead_id" class="form-select select2">
                <option value="">Not linked</option>
                <?php foreach ($leads as $l): ?>
                <option value="<?= $l['id'] ?>" <?= $q['lead_id']==$l['id']?'selected':'' ?>><?= htmlspecialchars($l['name'] . ($l['company'] ? ' — '.$l['company'] : '')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- Line Items -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
          <span><i class="bi bi-list-ul me-2 text-primary"></i>Line Items</span>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addRow()"><i class="bi bi-plus me-1"></i>Add Item</button>
        </div>
        <div class="card-body p-0">
          <table class="table mb-0" id="itemsTable">
            <thead class="table-light">
              <tr><th>Description</th><th style="width:140px">Price Type</th><th style="width:100px">Qty</th><th style="width:130px">Unit Price</th><th style="width:130px">Amount</th><th style="width:40px"></th></tr>
            </thead>
            <tbody id="itemsBody">
              <?php $rowItems = $items ?: [['description'=>'','price_type'=>'one_time','quantity'=>1,'unit_price'=>0,'amount'=>0]]; ?>
              <?php foreach ($rowItems as $li): ?>
              <tr>
                <td><input type="text" name="item_desc[]" class="form-control form-control-sm" value="<?= htmlspecialchars($li['description']) ?>" placeholder="Item description" required></td>
                <td>
                  <select name="item_price_type[]" class="form-select form-select-sm">
                    <option value="one_time" <?= ($li['price_type'] ?? 'one_time') === 'one_time' ? 'selected' : '' ?>>One-time</option>
                    <option value="monthly" <?= ($li['price_type'] ?? '') === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    <option value="yearly" <?= ($li['price_type'] ?? '') === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                  </select>
                </td>
                <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" value="<?= $li['quantity'] ?>" step="0.01" min="0.01" required></td>
                <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" value="<?= $li['unit_price'] ?>" step="0.01" min="0" required></td>
                <td><input type="text" class="form-control form-control-sm item-amount bg-light" value="<?= number_format($li['amount'], 2, '.', '') ?>" readonly></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="bi bi-x"></i></button></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Right: Summary -->
    <div class="col-xl-4">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-receipt me-2 text-primary"></i>Summary</div>
        <div class="card-body">
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Subtotal</span>
            <span class="fw-semibold" id="subtotalDisplay">₹0.00</span>
          </div>

          <!-- Discount Type Toggle -->
          <div class="mb-2">
            <label class="form-label small fw-semibold mb-1">Discount Type</label>
            <div class="d-flex gap-2">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="discount_type" id="discBefore" value="before_gst"
                  <?= ($q['discount_type'] ?? 'after_gst') === 'before_gst' ? 'checked' : '' ?>>
                <label class="form-check-label small" for="discBefore">Before GST</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="discount_type" id="discAfter" value="after_gst"
                  <?= ($q['discount_type'] ?? 'after_gst') === 'after_gst' ? 'checked' : '' ?>>
                <label class="form-check-label small" for="discAfter">After GST</label>
              </div>
            </div>
          </div>

          <!-- Discount Amount (shown above GST when before_gst) -->
          <div id="discountBeforeBlock" class="row g-2 align-items-center mb-2" style="display:none!important">
            <div class="col-6"><label class="form-label small fw-semibold mb-0">Discount</label></div>
            <div class="col-6 text-end">
              <input type="number" id="discountInputBefore" class="form-control form-control-sm text-end" value="0" step="0.01" min="0" tabindex="-1">
            </div>
          </div>
          <div id="discountedBaseRow" class="d-flex justify-content-between mb-2" style="display:none!important">
            <span class="text-muted">After Discount</span>
            <span id="discountedBaseDisplay">₹0.00</span>
          </div>

          <div class="row g-2 align-items-center mb-2">
            <div class="col-6"><label class="form-label small fw-semibold mb-0">Tax / VAT %</label></div>
            <div class="col-6 text-end">
              <input type="number" name="tax_percent" id="taxInput" class="form-control form-control-sm text-end" value="<?= $q['tax_percent'] ?>" step="0.01" min="0" max="100">
            </div>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Tax Amount</span>
            <span id="taxDisplay">₹0.00</span>
          </div>
          <div class="d-flex justify-content-between border-top pt-2 mb-3">
            <span class="fw-semibold">Total (incl. GST)</span>
            <span class="fw-semibold" id="totalAmountDisplay">₹0.00</span>
          </div>

          <!-- Discount Amount (shown below GST when after_gst) -->
          <div id="discountAfterBlock" class="row g-2 align-items-center mb-3">
            <div class="col-6"><label class="form-label small fw-semibold mb-0" id="discountLabel">Discount</label></div>
            <div class="col-6 text-end">
              <input type="number" id="discountInputAfter" class="form-control form-control-sm text-end" value="<?= $q['discount'] ?>" step="0.01" min="0">
            </div>
          </div>

          <input type="hidden" name="discount" id="discountHidden" value="<?= $q['discount'] ?>">

          <hr>
          <div class="d-flex justify-content-between">
            <span class="fw-bold fs-5">Net Payable</span>
            <span class="fw-bold fs-5 text-primary" id="netPayableDisplay">₹0.00</span>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3 d-flex justify-content-between align-items-center">
          <span><i class="bi bi-shield-check me-2 text-primary"></i>Terms &amp; Conditions</span>
          <button type="button" class="btn btn-sm btn-outline-primary" id="addTermBtn"><i class="bi bi-plus"></i></button>
        </div>
        <div class="card-body">
          <div id="termsList">
            <?php
            $existingTerms = [];
            if (!empty($q['terms_conditions'])) {
              $decoded = json_decode($q['terms_conditions'], true);
              if (is_array($decoded)) $existingTerms = $decoded;
            }
            foreach ($existingTerms as $term): ?>
            <div class="d-flex gap-2 mb-2 term-row">
              <input type="text" name="terms_conditions[]" class="form-control form-control-sm" value="<?= htmlspecialchars($term) ?>">
              <button type="button" class="btn btn-sm btn-outline-danger remove-term"><i class="bi bi-x"></i></button>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if (empty($existingTerms)): ?>
          <p class="text-muted small mb-0" id="noTermsMsg">No terms added. Click + to add.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-chat-left-text me-2 text-primary"></i>General Notes</div>
        <div class="card-body">
          <textarea name="notes" class="form-control" rows="3" placeholder="Remarks (Internal use or extra info)..."><?= htmlspecialchars($q['notes']) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?= $id ? 'Update Quotation' : 'Save Quotation' ?></button>
    <a href="<?= BASE_URL ?>/modules/quotations/index.php" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<script>
function calcRow(row) {
  const qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
  const price = parseFloat(row.querySelector('.item-price').value) || 0;
  const amt   = qty * price;
  row.querySelector('.item-amount').value = amt.toFixed(2);
  recalcTotals();
}

function getCurrencySymbol() {
  const sel = document.getElementById('currencySelect');
  if (!sel) return '₹';
  const symbols = <?= json_encode(array_map(fn($c) => $c['symbol'], CURRENCIES)) ?>;
  return symbols[sel.value] || sel.value;
}

function getDiscountType() {
  const checked = document.querySelector('input[name="discount_type"]:checked');
  return checked ? checked.value : 'after_gst';
}

function getDiscountValue() {
  const type = getDiscountType();
  if (type === 'before_gst') {
    return parseFloat(document.getElementById('discountInputBefore').value) || 0;
  } else {
    return parseFloat(document.getElementById('discountInputAfter').value) || 0;
  }
}

function syncDiscountHidden() {
  document.getElementById('discountHidden').value = getDiscountValue();
}

function updateDiscountUI() {
  const type = getDiscountType();
  const beforeBlock  = document.getElementById('discountBeforeBlock');
  const afterBlock   = document.getElementById('discountAfterBlock');
  const baseRow      = document.getElementById('discountedBaseRow');

  if (type === 'before_gst') {
    beforeBlock.style.setProperty('display', 'flex', 'important');
    baseRow.style.setProperty('display', 'flex', 'important');
    afterBlock.style.setProperty('display', 'none', 'important');
    // Copy value across when switching
    document.getElementById('discountInputBefore').value = document.getElementById('discountInputAfter').value;
    document.getElementById('discountInputBefore').removeAttribute('tabindex');
    document.getElementById('discountInputAfter').setAttribute('tabindex', '-1');
  } else {
    beforeBlock.style.setProperty('display', 'none', 'important');
    baseRow.style.setProperty('display', 'none', 'important');
    afterBlock.style.setProperty('display', 'flex', 'important');
    document.getElementById('discountInputAfter').value = document.getElementById('discountInputBefore').value;
    document.getElementById('discountInputAfter').removeAttribute('tabindex');
    document.getElementById('discountInputBefore').setAttribute('tabindex', '-1');
  }
  recalcTotals();
}

function recalcTotals() {
  const sym     = getCurrencySymbol();
  const type    = getDiscountType();
  const discount = getDiscountValue();
  let sub = 0;
  document.querySelectorAll('.item-amount').forEach(el => sub += parseFloat(el.value) || 0);
  const taxPct = parseFloat(document.getElementById('taxInput').value) || 0;

  let taxBase, taxAmt, totalAmount, netPayable;
  if (type === 'before_gst') {
    taxBase     = Math.max(0, sub - discount);
    taxAmt      = taxBase * taxPct / 100;
    totalAmount = taxBase + taxAmt;   // net payable = total after disc + GST
    netPayable  = totalAmount;
    document.getElementById('discountedBaseDisplay').textContent = sym + taxBase.toFixed(2);
  } else {
    taxAmt      = sub * taxPct / 100;
    totalAmount = sub + taxAmt;
    netPayable  = Math.max(0, totalAmount - discount);
  }

  syncDiscountHidden();
  document.getElementById('subtotalDisplay').textContent    = sym + sub.toFixed(2);
  document.getElementById('taxDisplay').textContent         = sym + taxAmt.toFixed(2);
  document.getElementById('totalAmountDisplay').textContent = sym + totalAmount.toFixed(2);
  document.getElementById('netPayableDisplay').textContent  = sym + netPayable.toFixed(2);
  const lbl = document.getElementById('discountLabel');
  if (lbl) lbl.textContent = 'Discount (' + sym + ')';
}

function addRow() {
  const tbody = document.getElementById('itemsBody');
  const tr = document.querySelector('#itemsBody tr').cloneNode(true);
  tr.querySelectorAll('input').forEach(i => {
    if (i.classList.contains('item-qty')) i.value = '1';
    else if (i.classList.contains('item-price')) i.value = '0';
    else if (i.classList.contains('item-amount')) i.value = '0.00';
    else i.value = '';
  });
  tbody.appendChild(tr);
  attachRowEvents(tr);
}

function removeRow(btn) {
  if (document.querySelectorAll('#itemsBody tr').length > 1) { btn.closest('tr').remove(); recalcTotals(); }
}

function attachRowEvents(row) {
  row.querySelectorAll('.item-qty, .item-price').forEach(inp => inp.addEventListener('input', () => calcRow(row)));
}

document.querySelectorAll('#itemsBody tr').forEach(attachRowEvents);
document.getElementById('taxInput').addEventListener('input', recalcTotals);
document.getElementById('discountInputBefore').addEventListener('input', recalcTotals);
document.getElementById('discountInputAfter').addEventListener('input', recalcTotals);
document.querySelectorAll('input[name="discount_type"]').forEach(r => r.addEventListener('change', updateDiscountUI));

// Remove the hidden duplicate discount field so only one is submitted
document.querySelector('form').addEventListener('submit', function() {
  syncDiscountHidden();
});

// Init UI
updateDiscountUI();
// Restore correct value from PHP for the active input
(function() {
  const savedDiscount = <?= (float)($q['discount'] ?? 0) ?>;
  const savedType = '<?= htmlspecialchars($q['discount_type'] ?? 'after_gst') ?>';
  if (savedType === 'before_gst') {
    document.getElementById('discountInputBefore').value = savedDiscount;
  } else {
    document.getElementById('discountInputAfter').value = savedDiscount;
  }
})();

// Terms & Conditions Logic
function addTermRow(val = '') {
  const container = document.getElementById('termsList');
  const msg = document.getElementById('noTermsMsg');
  if (msg) msg.remove();

  const div = document.createElement('div');
  div.className = 'd-flex gap-2 mb-2 term-row';
  div.innerHTML = `
    <input type="text" name="terms_conditions[]" class="form-control form-control-sm" value="${val.replace(/"/g, '&quot;')}" placeholder="e.g. Validity 30 days">
    <button type="button" class="btn btn-sm btn-outline-danger remove-term"><i class="bi bi-x"></i></button>
  `;
  div.querySelector('.remove-term').addEventListener('click', () => {
    div.remove();
    if (container.querySelectorAll('.term-row').length === 0) {
      container.insertAdjacentHTML('afterend', '<p class="text-muted small mb-0" id="noTermsMsg">No terms added. Click + to add.</p>');
    }
  });
  container.appendChild(div);
}

document.getElementById('addTermBtn').addEventListener('click', () => addTermRow());
document.querySelectorAll('#termsList .remove-term').forEach(btn => {
  btn.addEventListener('click', function() {
    this.closest('.term-row').remove();
    const container = document.getElementById('termsList');
    if (container.querySelectorAll('.term-row').length === 0) {
       if (!document.getElementById('noTermsMsg'))
         container.insertAdjacentHTML('afterend', '<p class="text-muted small mb-0" id="noTermsMsg">No terms added. Click + to add.</p>');
    }
  });
});

// Load Default Project Terms
document.getElementById('currencySelect').addEventListener('change', recalcTotals);

document.getElementById('quotationProject').addEventListener('change', function() {
  const projectId = this.value;
  if (!projectId) return;

  // Only auto-load if terms list is empty to prevent overwriting
  const currentTerms = document.querySelectorAll('#termsList .term-row');
  if (currentTerms.length > 0) {
    if (!confirm('Load default terms for this project? This will NOT remove your existing terms.')) return;
  }

  fetch('<?= BASE_URL ?>/modules/projects/get_project.php?id=' + projectId)
    .then(r => r.json())
    .then(p => {
      if (p.default_quotation_terms) {
        const terms = p.default_quotation_terms.split('\n');
        terms.forEach(t => {
          if (t.trim()) addTermRow(t.trim());
        });
      }
    });
});

recalcTotals(); // initial calc after UI is set up
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
