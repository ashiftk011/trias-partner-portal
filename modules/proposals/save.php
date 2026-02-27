<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('proposals');

$db   = getDB();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);
$proposal = null;
$items    = [];

if ($id) {
    $stmt = $db->prepare("SELECT * FROM proposals WHERE id=?");
    $stmt->execute([$id]);
    $proposal = $stmt->fetch();
    if (!$proposal) { setFlash('error','Proposal not found.'); redirect(BASE_URL.'/modules/proposals/index.php'); }
    $items = $db->prepare("SELECT * FROM proposal_items WHERE proposal_id=? ORDER BY id");
    $items->execute([$id]);
    $items = $items->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title      = trim($_POST['title'] ?? '');
    $project_id = (int)($_POST['project_id'] ?? 0);
    $lead_id    = $_POST['lead_id'] ? (int)$_POST['lead_id'] : null;
    $client_id  = $_POST['client_id'] ? (int)$_POST['client_id'] : null;
    $description= trim($_POST['description'] ?? '');
    $valid_until= $_POST['valid_until'] ?: null;
    $discount   = (float)($_POST['discount'] ?? 0);
    $status     = $_POST['status'] ?? 'draft';
    $notes      = trim($_POST['notes'] ?? '');

    // Line items
    $descs  = $_POST['item_desc'] ?? [];
    $qtys   = $_POST['item_qty']  ?? [];
    $prices = $_POST['item_price']?? [];

    $subtotal = 0;
    $lineItems = [];
    foreach ($descs as $i => $desc) {
        if (!trim($desc)) continue;
        $qty   = (float)($qtys[$i] ?? 1);
        $price = (float)($prices[$i] ?? 0);
        $amt   = round($qty * $price, 2);
        $subtotal += $amt;
        $lineItems[] = ['desc'=>trim($desc), 'qty'=>$qty, 'price'=>$price, 'amount'=>$amt];
    }
    $total = max(0, $subtotal - $discount);

    if (!$title || !$project_id) {
        setFlash('error', 'Title and Project are required.');
        redirect(BASE_URL.'/modules/proposals/save.php'.($id ? "?id=$id" : ''));
    }

    if ($id) {
        $db->prepare("UPDATE proposals SET title=?,project_id=?,lead_id=?,client_id=?,description=?,
            valid_until=?,subtotal=?,discount=?,total_amount=?,status=?,notes=? WHERE id=?")
           ->execute([$title,$project_id,$lead_id,$client_id,$description,$valid_until,
                      $subtotal,$discount,$total,$status,$notes,$id]);
        $db->prepare("DELETE FROM proposal_items WHERE proposal_id=?")->execute([$id]);
        setFlash('success', 'Proposal updated.');
    } else {
        // Generate proposal number
        $last = $db->query("SELECT proposal_no FROM proposals ORDER BY id DESC LIMIT 1")->fetchColumn();
        $num  = $last && preg_match('/PRP-(\d+)/', $last, $m) ? (int)$m[1]+1 : 1;
        $no   = 'PRP-'.str_pad($num, 4, '0', STR_PAD_LEFT);

        $db->prepare("INSERT INTO proposals (proposal_no,title,project_id,lead_id,client_id,description,
            valid_until,subtotal,discount,total_amount,status,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$no,$title,$project_id,$lead_id,$client_id,$description,$valid_until,
                      $subtotal,$discount,$total,$status,$notes,$user['id']]);
        $id = (int)$db->lastInsertId();
        setFlash('success', "Proposal $no created.");
    }

    // Save line items
    $insStmt = $db->prepare("INSERT INTO proposal_items (proposal_id,description,quantity,unit_price,amount) VALUES (?,?,?,?,?)");
    foreach ($lineItems as $li) {
        $insStmt->execute([$id, $li['desc'], $li['qty'], $li['price'], $li['amount']]);
    }

    redirect(BASE_URL.'/modules/proposals/save.php?id='.$id);
}

$projects = $db->query("SELECT id,name FROM projects WHERE status='active' ORDER BY name")->fetchAll();
$leads    = $db->query("SELECT id,name,company FROM leads ORDER BY name")->fetchAll();
$clients  = $db->query("SELECT id,name,company FROM clients ORDER BY name")->fetchAll();

$p = $proposal ?? ['title'=>'','project_id'=>'','lead_id'=>'','client_id'=>'','description'=>'',
     'valid_until'=>'','subtotal'=>0,'discount'=>0,'total_amount'=>0,'status'=>'draft','notes'=>''];

$pageTitle = $id ? 'Edit Proposal' : 'New Proposal';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/proposals/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0"><?= $id ? 'Edit Proposal' : 'New Proposal' ?></h4>
    <p class="text-muted small mb-0"><?= $id ? htmlspecialchars($proposal['proposal_no'] ?? '') : 'Create a new proposal' ?></p>
  </div>
</div>

<?php displayFlash(); ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

  <div class="row g-4">
    <!-- Left: Details -->
    <div class="col-xl-8">
      <!-- Basic Info -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Proposal Details</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small fw-semibold">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required value="<?= htmlspecialchars($p['title']) ?>" placeholder="e.g. Digital Marketing Proposal for ABC Corp">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Project <span class="text-danger">*</span></label>
              <select name="project_id" class="form-select" required>
                <option value="">Select Project</option>
                <?php foreach ($projects as $pr): ?>
                <option value="<?= $pr['id'] ?>" <?= $p['project_id']==$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Valid Until</label>
              <input type="date" name="valid_until" class="form-control" value="<?= htmlspecialchars($p['valid_until'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Status</label>
              <select name="status" class="form-select">
                <option value="draft"    <?= $p['status']==='draft'   ?'selected':'' ?>>Draft</option>
                <option value="sent"     <?= $p['status']==='sent'    ?'selected':'' ?>>Sent</option>
                <option value="accepted" <?= $p['status']==='accepted'?'selected':'' ?>>Accepted</option>
                <option value="rejected" <?= $p['status']==='rejected'?'selected':'' ?>>Rejected</option>
                <option value="expired"  <?= $p['status']==='expired' ?'selected':'' ?>>Expired</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Link to Client</label>
              <select name="client_id" class="form-select select2">
                <option value="">Not linked</option>
                <?php foreach ($clients as $cl): ?>
                <option value="<?= $cl['id'] ?>" <?= $p['client_id']==$cl['id']?'selected':'' ?>><?= htmlspecialchars($cl['name'] . ($cl['company'] ? ' — '.$cl['company'] : '')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Link to Lead</label>
              <select name="lead_id" class="form-select select2">
                <option value="">Not linked</option>
                <?php foreach ($leads as $l): ?>
                <option value="<?= $l['id'] ?>" <?= $p['lead_id']==$l['id']?'selected':'' ?>><?= htmlspecialchars($l['name'] . ($l['company'] ? ' — '.$l['company'] : '')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Description</label>
              <textarea name="description" class="form-control" rows="3" placeholder="Brief overview of this proposal..."><?= htmlspecialchars($p['description']) ?></textarea>
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
              <?php if ($items): ?>
              <?php foreach ($items as $li): ?>
              <tr>
                <td><input type="text" name="item_desc[]" class="form-control form-control-sm" value="<?= htmlspecialchars($li['description']) ?>" required></td>
                <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" value="<?= $li['quantity'] ?>" step="0.01" min="0.01" required></td>
                <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" value="<?= $li['unit_price'] ?>" step="0.01" min="0" required></td>
                <td><input type="text" class="form-control form-control-sm item-amount bg-light" value="<?= number_format($li['amount'], 2) ?>" readonly></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="bi bi-x"></i></button></td>
              </tr>
              <?php endforeach; ?>
              <?php else: ?>
              <tr>
                <td><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Service or product description" required></td>
                <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" value="1" step="0.01" min="0.01" required></td>
                <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" value="0" step="0.01" min="0" required></td>
                <td><input type="text" class="form-control form-control-sm item-amount bg-light" value="0.00" readonly></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="bi bi-x"></i></button></td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Right: Summary -->
    <div class="col-xl-4">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-calculator me-2 text-primary"></i>Summary</div>
        <div class="card-body">
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Subtotal</span>
            <span class="fw-semibold" id="subtotalDisplay">₹0.00</span>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Discount (₹)</label>
            <input type="number" name="discount" id="discountInput" class="form-control" value="<?= $p['discount'] ?>" step="0.01" min="0">
          </div>
          <hr>
          <div class="d-flex justify-content-between mb-0">
            <span class="fw-bold fs-5">Total</span>
            <span class="fw-bold fs-5 text-primary" id="totalDisplay">₹0.00</span>
          </div>
        </div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-chat-left-text me-2 text-primary"></i>Notes</div>
        <div class="card-body">
          <textarea name="notes" class="form-control" rows="5" placeholder="Terms, conditions, or additional notes..."><?= htmlspecialchars($p['notes']) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?= $id ? 'Update Proposal' : 'Save Proposal' ?></button>
    <a href="<?= BASE_URL ?>/modules/proposals/index.php" class="btn btn-outline-secondary">Cancel</a>
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
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  const total = Math.max(0, sub - discount);
  document.getElementById('subtotalDisplay').textContent = '₹' + sub.toFixed(2);
  document.getElementById('totalDisplay').textContent    = '₹' + total.toFixed(2);
}

function addRow() {
  const tbody = document.getElementById('itemsBody');
  const tr = document.querySelector('#itemsBody tr').cloneNode(true);
  tr.querySelectorAll('input[type=text], input[type=number]').forEach(i => {
    if (i.classList.contains('item-qty')) i.value = '1';
    else if (i.classList.contains('item-price')) i.value = '0';
    else if (i.classList.contains('item-amount')) i.value = '0.00';
    else i.value = '';
  });
  tbody.appendChild(tr);
  attachRowEvents(tr);
}

function removeRow(btn) {
  const rows = document.querySelectorAll('#itemsBody tr');
  if (rows.length > 1) { btn.closest('tr').remove(); recalcTotals(); }
}

function attachRowEvents(row) {
  row.querySelectorAll('.item-qty, .item-price').forEach(inp => {
    inp.addEventListener('input', () => calcRow(row));
  });
}

document.querySelectorAll('#itemsBody tr').forEach(attachRowEvents);
document.getElementById('discountInput').addEventListener('input', recalcTotals);
recalcTotals();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
