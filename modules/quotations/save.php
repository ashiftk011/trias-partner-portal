<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
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
    $tax_percent= (float)($_POST['tax_percent'] ?? 18);
    $status     = $_POST['status']  ?? 'draft';
    $notes      = trim($_POST['notes'] ?? '');

    $descs  = $_POST['item_desc']  ?? [];
    $qtys   = $_POST['item_qty']   ?? [];
    $prices = $_POST['item_price'] ?? [];

    $subtotal = 0;
    $lineItems = [];
    foreach ($descs as $i => $desc) {
        if (!trim($desc)) continue;
        $qty   = (float)($qtys[$i]   ?? 1);
        $price = (float)($prices[$i] ?? 0);
        $amt   = round($qty * $price, 2);
        $subtotal += $amt;
        $lineItems[] = ['desc'=>trim($desc),'qty'=>$qty,'price'=>$price,'amount'=>$amt];
    }
    $taxAmt = round($subtotal * $tax_percent / 100, 2);
    $total  = $subtotal + $taxAmt;

    if (!$title || !$project_id) {
        setFlash('error','Title and Project are required.');
        redirect(BASE_URL.'/modules/quotations/save.php'.($id ? "?id=$id" : ''));
    }

    if ($id) {
        $db->prepare("UPDATE quotations SET title=?,project_id=?,lead_id=?,client_id=?,valid_until=?,
            subtotal=?,tax_percent=?,tax_amount=?,total_amount=?,status=?,notes=? WHERE id=?")
           ->execute([$title,$project_id,$lead_id,$client_id,$valid_until,
                      $subtotal,$tax_percent,$taxAmt,$total,$status,$notes,$id]);
        $db->prepare("DELETE FROM quotation_items WHERE quotation_id=?")->execute([$id]);
        setFlash('success','Quotation updated.');
    } else {
        $last = $db->query("SELECT quotation_no FROM quotations ORDER BY id DESC LIMIT 1")->fetchColumn();
        $num  = $last && preg_match('/QTN-(\d+)/', $last, $m) ? (int)$m[1]+1 : 1;
        $no   = 'QTN-'.str_pad($num, 4, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO quotations (quotation_no,title,project_id,lead_id,client_id,valid_until,
            subtotal,tax_percent,tax_amount,total_amount,status,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$no,$title,$project_id,$lead_id,$client_id,$valid_until,
                      $subtotal,$tax_percent,$taxAmt,$total,$status,$notes,$user['id']]);
        $id = (int)$db->lastInsertId();
        setFlash('success',"Quotation $no created.");
    }

    $ins = $db->prepare("INSERT INTO quotation_items (quotation_id,description,quantity,unit_price,amount) VALUES (?,?,?,?,?)");
    foreach ($lineItems as $li) { $ins->execute([$id,$li['desc'],$li['qty'],$li['price'],$li['amount']]); }

    redirect(BASE_URL.'/modules/quotations/save.php?id='.$id);
}

$projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
$leads    = $db->query("SELECT id,name,company FROM leads ORDER BY name")->fetchAll();
$clients  = $db->query("SELECT id,name,company FROM clients ORDER BY name")->fetchAll();

$q = $quotation ?? ['title'=>'','project_id'=>'','lead_id'=>'','client_id'=>'','valid_until'=>'',
     'subtotal'=>0,'tax_percent'=>18,'tax_amount'=>0,'total_amount'=>0,'status'=>'draft','notes'=>''];

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
              <select name="project_id" class="form-select" required>
                <option value="">Select Project</option>
                <?php foreach ($projects as $pr): ?>
                <option value="<?= $pr['id'] ?>" <?= $q['project_id']==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Valid Until</label>
              <input type="date" name="valid_until" class="form-control" value="<?= htmlspecialchars($q['valid_until'] ?? '') ?>">
            </div>
            <div class="col-md-4">
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
              <tr><th>Description</th><th style="width:100px">Qty</th><th style="width:130px">Unit Price</th><th style="width:130px">Amount</th><th style="width:40px"></th></tr>
            </thead>
            <tbody id="itemsBody">
              <?php $rowItems = $items ?: [['description'=>'','quantity'=>1,'unit_price'=>0,'amount'=>0]]; ?>
              <?php foreach ($rowItems as $li): ?>
              <tr>
                <td><input type="text" name="item_desc[]" class="form-control form-control-sm" value="<?= htmlspecialchars($li['description']) ?>" placeholder="Item description" required></td>
                <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" value="<?= $li['quantity'] ?>" step="0.01" min="0.01" required></td>
                <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" value="<?= $li['unit_price'] ?>" step="0.01" min="0" required></td>
                <td><input type="text" class="form-control form-control-sm item-amount bg-light" value="<?= number_format($li['amount'], 2) ?>" readonly></td>
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
          <div class="mb-3">
            <label class="form-label small fw-semibold">GST %</label>
            <input type="number" name="tax_percent" id="taxInput" class="form-control" value="<?= $q['tax_percent'] ?>" step="0.01" min="0" max="100">
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Tax Amount</span>
            <span class="text-muted" id="taxDisplay">₹0.00</span>
          </div>
          <hr>
          <div class="d-flex justify-content-between">
            <span class="fw-bold fs-5">Total</span>
            <span class="fw-bold fs-5 text-primary" id="totalDisplay">₹0.00</span>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-chat-left-text me-2 text-primary"></i>Notes</div>
        <div class="card-body">
          <textarea name="notes" class="form-control" rows="5" placeholder="Terms, validity, or remarks..."><?= htmlspecialchars($q['notes']) ?></textarea>
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

function recalcTotals() {
  let sub = 0;
  document.querySelectorAll('.item-amount').forEach(el => sub += parseFloat(el.value) || 0);
  const tax  = (parseFloat(document.getElementById('taxInput').value) || 0) / 100;
  const taxAmt = sub * tax;
  const total  = sub + taxAmt;
  document.getElementById('subtotalDisplay').textContent = '₹' + sub.toFixed(2);
  document.getElementById('taxDisplay').textContent      = '₹' + taxAmt.toFixed(2);
  document.getElementById('totalDisplay').textContent    = '₹' + total.toFixed(2);
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
recalcTotals();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
