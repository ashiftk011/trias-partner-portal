<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('invoices');

$db = getDB();
$invoiceId = (int)($_GET['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
if (!$invoiceId) redirect(BASE_URL . '/modules/invoices/index.php');

$stmt = $db->prepare("SELECT i.*,c.name as client_name,c.id as client_id FROM invoices i LEFT JOIN clients c ON c.id=i.client_id WHERE i.id=?");
$stmt->execute([$invoiceId]);
$inv = $stmt->fetch();
if (!$inv) redirect(BASE_URL . '/modules/invoices/index.php');

$balance = $inv['total_amount'] - $inv['paid_amount'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $amount      = (float)$_POST['amount'];
    $paymentDate = $_POST['payment_date'];
    $paymentMode = $_POST['payment_mode'];
    $txnId       = trim($_POST['transaction_id'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');
    $userId      = currentUser()['id'];

    if ($amount <= 0 || $amount > $balance + 0.01) {
        setFlash('error', 'Invalid payment amount. Balance due is ₹' . number_format($balance, 2));
        redirect($_SERVER['REQUEST_URI']);
    }

    // Insert payment
    $db->prepare("INSERT INTO payments (invoice_id,client_id,amount,payment_date,payment_mode,transaction_id,notes,created_by) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$invoiceId,$inv['client_id'],$amount,$paymentDate,$paymentMode,$txnId,$notes,$userId]);

    // Update invoice paid amount + status
    $newPaid = $inv['paid_amount'] + $amount;
    if ($newPaid >= $inv['total_amount'] - 0.01) {
        $newStatus = 'paid';
    } else {
        $newStatus = 'partial';
    }
    $db->prepare("UPDATE invoices SET paid_amount=?, status=? WHERE id=?")->execute([$newPaid,$newStatus,$invoiceId]);

    setFlash('success', 'Payment of ₹'.number_format($amount,2).' recorded. Invoice status: '.ucfirst($newStatus).'.');
    redirect(BASE_URL . '/modules/invoices/view.php?id=' . $invoiceId);
}

$pageTitle = 'Record Payment';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $invoiceId ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0">Record Payment</h4>
    <p class="text-muted small mb-0">Invoice: <?= htmlspecialchars($inv['invoice_no']) ?> — <?= htmlspecialchars($inv['client_name']) ?></p>
  </div>
</div>

<?php displayFlash(); ?>

<div class="row justify-content-center">
  <div class="col-xl-6">
    <!-- Invoice Summary -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-body">
        <div class="row text-center g-3">
          <div class="col-4">
            <div class="text-muted small">Total Amount</div>
            <div class="fw-bold fs-5">₹<?= number_format($inv['total_amount'],2) ?></div>
          </div>
          <div class="col-4">
            <div class="text-muted small">Already Paid</div>
            <div class="fw-bold fs-5 text-success">₹<?= number_format($inv['paid_amount'],2) ?></div>
          </div>
          <div class="col-4">
            <div class="text-muted small">Balance Due</div>
            <div class="fw-bold fs-5 text-danger">₹<?= number_format($balance,2) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Payment Form -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-cash-coin me-2 text-success"></i>Payment Details</div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="invoice_id" value="<?= $invoiceId ?>">
          <div class="mb-3">
            <label class="form-label fw-semibold">Amount Received (₹) *</label>
            <div class="input-group input-group-lg">
              <span class="input-group-text">₹</span>
              <input type="number" name="amount" class="form-control fw-bold" step="0.01" min="0.01"
                     max="<?= $balance ?>" value="<?= number_format($balance,2,'.','') ?>" required>
            </div>
            <div class="form-text">Max: ₹<?= number_format($balance,2) ?></div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Payment Date *</label>
            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Payment Mode *</label>
            <div class="row g-2">
              <?php foreach (['upi'=>'UPI','neft'=>'NEFT','rtgs'=>'RTGS','cash'=>'Cash','cheque'=>'Cheque','card'=>'Card','other'=>'Other'] as $val => $lbl): ?>
              <div class="col-auto">
                <input type="radio" class="btn-check" name="payment_mode" id="pm_<?= $val ?>" value="<?= $val ?>" <?= $val==='upi'?'checked':'' ?>>
                <label class="btn btn-outline-primary btn-sm" for="pm_<?= $val ?>"><?= $lbl ?></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Transaction ID / Reference</label>
            <input type="text" name="transaction_id" class="form-control" placeholder="UTR number, cheque no, etc.">
          </div>
          <div class="mb-4">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success btn-lg flex-grow-1">
              <i class="bi bi-check-circle me-2"></i>Record Payment
            </button>
            <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $invoiceId ?>" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
