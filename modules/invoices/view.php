<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('invoices');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/invoices/index.php');

$stmt = $db->prepare("SELECT i.*,c.name as client_name,c.client_code,c.company,c.email as client_email,c.phone as client_phone,c.address,c.city,c.state,c.gst_no,p.name as project_name,pl.name as plan_name,u.name as created_by_name FROM invoices i LEFT JOIN clients c ON c.id=i.client_id LEFT JOIN projects p ON p.id=c.project_id LEFT JOIN plans pl ON pl.id=c.plan_id LEFT JOIN users u ON u.id=i.created_by WHERE i.id=?");
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) redirect(BASE_URL . '/modules/invoices/index.php');

// Line items
$itemStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$itemStmt->execute([$id]);
$lineItems = $itemStmt->fetchAll();

// Payments
$payments = $db->prepare("SELECT py.*,u.name as by_name FROM payments py LEFT JOIN users u ON u.id=py.created_by WHERE py.invoice_id=? ORDER BY py.payment_date");
$payments->execute([$id]);
$payments = $payments->fetchAll();

// Company settings
$companySettings = [];
foreach ($db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll() as $row) {
    $companySettings[$row['setting_key']] = $row['setting_value'];
}

// Mark overdue
if ($inv['status'] === 'pending' && strtotime($inv['due_date']) < time()) {
    $db->prepare("UPDATE invoices SET status='overdue' WHERE id=?")->execute([$id]);
    $inv['status'] = 'overdue';
}

$discountPercent = $inv['discount_percent'] ?? 0;
$discountAmount  = $inv['discount_amount'] ?? 0;

// Amount in words helper
function numberToWords($num) {
    $num = (int)round($num);
    if ($num === 0) return 'Zero';
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten',
             'Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];

    $words = '';
    if ($num >= 10000000) { $words .= numberToWords((int)($num/10000000)) . ' Crore '; $num %= 10000000; }
    if ($num >= 100000) { $words .= numberToWords((int)($num/100000)) . ' Lakh '; $num %= 100000; }
    if ($num >= 1000) { $words .= numberToWords((int)($num/1000)) . ' Thousand '; $num %= 1000; }
    if ($num >= 100) { $words .= $ones[(int)($num/100)] . ' Hundred '; $num %= 100; }
    if ($num >= 20) { $words .= $tens[(int)($num/10)] . ' '; $num %= 10; }
    if ($num > 0) { $words .= $ones[$num] . ' '; }
    return trim($words);
}

$amountInWords = numberToWords($inv['total_amount']) . ' Rupees Only';

$pageTitle = 'Invoice ' . $inv['invoice_no'];
include __DIR__ . '/../../includes/header.php';
?>

<!-- Action Bar (hidden on print) -->
<div class="d-flex align-items-center gap-3 mb-4 no-print">
  <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div class="flex-grow-1">
    <h4 class="mb-0"><?= htmlspecialchars($inv['invoice_no']) ?></h4>
    <p class="text-muted small mb-0"><?= htmlspecialchars($inv['client_name']) ?></p>
  </div>
  <div class="d-flex gap-2">
    <?= statusBadge($inv['status']) ?>
    <?php if (!in_array($inv['status'],['paid','cancelled'])): ?>
    <a href="<?= BASE_URL ?>/modules/invoices/edit.php?id=<?= $id ?>" class="btn btn-warning btn-sm">
      <i class="bi bi-pencil-square me-1"></i>Edit
    </a>
    <?php endif; ?>
    <?php if (in_array($inv['status'],['pending','partial','overdue'])): ?>
    <a href="<?= BASE_URL ?>/modules/invoices/payment.php?invoice_id=<?= $id ?>" class="btn btn-success btn-sm">
      <i class="bi bi-cash-coin me-1"></i>Record Payment
    </a>
    <?php endif; ?>
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print
    </button>
  </div>
</div>

<?php displayFlash(); ?>

<div class="row g-4">
  <!-- Invoice Document -->
  <div class="col-xl-8">
    <div class="card border-0 shadow-sm" id="invoicePrint">
      <div class="card-body p-0 position-relative inv-body">
        <div class="inv-content">

        <?php if (!empty($companySettings['company_logo'])): ?>
        <div class="inv-watermark">
          <img src="<?= BASE_URL . '/' . htmlspecialchars($companySettings['company_logo']) ?>" alt="">
        </div>
        <?php endif; ?>

        <!-- ===== Invoice Header ===== -->
        <div class="inv-header">
          <div>
            <h1 class="inv-title">INVOICE</h1>
          </div>
          <div class="inv-company text-end">
            <?php if (!empty($companySettings['company_logo'])): ?>
            <img src="<?= BASE_URL . '/' . htmlspecialchars($companySettings['company_logo']) ?>"
                 alt="Logo" class="inv-logo">
            <?php endif; ?>
            <!--<div class="inv-company-name"><?= htmlspecialchars($companySettings['company_name'] ?? APP_NAME) ?></div>-->
            <?php if (!empty($companySettings['company_address'])): ?>
            <div class="inv-company-detail"><?= nl2br(htmlspecialchars($companySettings['company_address'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($companySettings['company_phone'])): ?>
            <div class="inv-company-detail">Phone: <?= htmlspecialchars($companySettings['company_phone']) ?></div>
            <?php endif; ?>
            <?php if (!empty($companySettings['company_email'])): ?>
            <div class="inv-company-detail"><?= htmlspecialchars($companySettings['company_email']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ===== Info Bar ===== -->
        <div class="inv-info-bar">
          <div class="inv-info-cell">
            <div class="inv-info-label">Billed to:</div>
            <div class="inv-info-value"><?= htmlspecialchars($inv['client_name']) ?></div>
              <div class="inv-client-sub">
              <?php if ($inv['company']): ?><div><?= htmlspecialchars($inv['company']) ?></div><?php endif; ?>
              <?php
              $addrParts = array_filter([$inv['address'], $inv['city'], $inv['state']]);
              if ($addrParts): ?>
              <div><?= htmlspecialchars(implode(', ', $addrParts)) ?></div>
              <?php endif; ?>
              <?php if ($inv['gst_no']): ?><div>GST: <?= htmlspecialchars($inv['gst_no']) ?></div><?php endif; ?>
              <?php if ($inv['client_phone']): ?><div class="mt-1">Mobile: <?= htmlspecialchars($inv['client_phone']) ?></div><?php endif; ?>
              <?php if ($inv['client_email']): ?><div>Email: <?= htmlspecialchars($inv['client_email']) ?></div><?php endif; ?>
              </div>
          </div>
          <div class="inv-info-cell">
            <div class="inv-info-label">Date Issued:</div>
            <div class="inv-info-value"><?= date('d-M-Y', strtotime($inv['invoice_date'])) ?></div>
          </div>
          <div class="inv-info-cell">
            <div class="inv-info-label">Invoice Number:</div>
            <div class="inv-info-value"><?= htmlspecialchars($inv['invoice_no']) ?></div>
          </div>
          <div class="inv-info-cell">
            <div class="inv-info-label">Amount Due:</div>
            <?php $amountDue = max(0, $inv['total_amount'] - (float)($inv['advance_amount'] ?? 0) - $inv['paid_amount']); ?>
            <div class="inv-info-value inv-amount-due">₹<?= number_format($amountDue, 0) ?></div>
          </div>
        </div>

        <!-- ===== Items Table ===== -->
        <div class="inv-table-wrap">
          <table class="inv-table">
            <thead>
              <tr>
                <th style="width:5%" class="text-center">S.No</th>
                <th style="width:22%">Item</th>
                <th style="width:30%">Description</th>
                <th style="width:8%" class="text-center">Qty</th>
                <th style="width:17%" class="text-end">Unit Price</th>
                <th style="width:18%" class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($lineItems)): ?>
                <?php foreach ($lineItems as $idx => $item): ?>
                <tr>
                  <td class="text-center text-muted"><?= $idx + 1 ?></td>
                  <td class="fw-semibold"><?= htmlspecialchars($item['item_name'] ?: $item['description']) ?></td>
                  <td class="text-muted" style="font-size:.83rem"><?= htmlspecialchars($item['description'] ?? '') ?></td>
                  <td class="text-center"><?= $item['quantity'] != 1 ? number_format($item['quantity'], 0) : 1 ?></td>
                  <td class="text-end">₹<?= number_format($item['unit_price'], 0) ?></td>
                  <td class="text-end fw-bold">₹<?= number_format($item['amount'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td class="text-center text-muted">1</td>
                  <td class="fw-semibold"><?= htmlspecialchars($inv['plan_name'] ?? 'Service Charges') ?></td>
                  <td class="text-muted" style="font-size:.83rem"><?= $inv['notes'] ? htmlspecialchars($inv['notes']) : '' ?></td>
                  <td class="text-center">1</td>
                  <td class="text-end">₹<?= number_format($inv['subtotal'], 0) ?></td>
                  <td class="text-end fw-bold">₹<?= number_format($inv['subtotal'], 0) ?></td>
                </tr>
              <?php endif; ?>
            </tbody>
            <tfoot>
              <?php if ($discountPercent > 0 || $discountAmount > 0): ?>
              <tr>
                <td colspan="5" class="text-end">
                  Discount
                  <?php if (($inv['discount_type'] ?? 'percent') === 'fixed'): ?>
                    (Fixed):
                  <?php else: ?>
                    (<?= $discountPercent ?>%):
                  <?php endif; ?>
                </td>
                <td class="text-end text-danger">-₹<?= number_format($discountAmount,0) ?></td>
              </tr>
              <?php endif; ?>
              <?php if ($inv['tax_percent'] > 0): ?>
              <tr>
                <td colspan="5" class="text-end">GST / Tax (<?= $inv['tax_percent'] ?>%):</td>
                <td class="text-end">₹<?= number_format($inv['tax_amount'],0) ?></td>
              </tr>
              <?php endif; ?>
              <tr class="inv-total-row">
                <td colspan="5" class="text-end"><strong>Total</strong></td>
                <td class="text-end"><strong>₹<?= number_format($inv['total_amount'],0) ?></strong></td>
              </tr>
              <tr>
                <td colspan="1"></td>
                <td colspan="5" class="inv-words-cell">
                  <strong><?= $amountInWords ?></strong>
                </td>
              </tr>
              <?php $advanceAmt = (float)($inv['advance_amount'] ?? 0); ?>
              <?php if ($advanceAmt > 0): ?>
              <tr>
                <td colspan="5" class="text-end text-primary">
                  Advance Received
                  <?php if (!empty($inv['advance_date'])): ?>
                    <span class="text-muted" style="font-size:.8em">(<?= date('d-M-Y', strtotime($inv['advance_date'])) ?>)</span>
                  <?php endif; ?>
                  :
                </td>
                <td class="text-end text-primary fw-bold">₹<?= number_format($advanceAmt,0) ?></td>
              </tr>
              <?php endif; ?>
              <?php if ($inv['paid_amount'] > 0): ?>
              <tr>
                <td colspan="5" class="text-end text-success">Amount Paid:</td>
                <td class="text-end text-success fw-bold">₹<?= number_format($inv['paid_amount'],0) ?></td>
              </tr>
              <?php $balance = $inv['total_amount'] - $advanceAmt - $inv['paid_amount']; if ($balance > 0): ?>
              <tr>
                <td colspan="5" class="text-end text-danger">Balance Due:</td>
                <td class="text-end text-danger fw-bold">₹<?= number_format($balance,0) ?></td>
              </tr>
              <?php endif; ?>
              <?php else: ?>
              <?php $balance = $inv['total_amount'] - $advanceAmt; if ($balance > 0 && $advanceAmt > 0): ?>
              <tr>
                <td colspan="5" class="text-end text-danger">Balance Due:</td>
                <td class="text-end text-danger fw-bold">₹<?= number_format($balance,0) ?></td>
              </tr>
              <?php endif; ?>
              <?php endif; ?>
            </tfoot>
          </table>
        </div>

        <?php if (!empty(trim($inv['notes'] ?? ''))): ?>
        <div class="inv-notes-section">
          <strong>Notes:</strong> <?= nl2br(htmlspecialchars($inv['notes'])) ?>
        </div>
        <?php endif; ?>

        <?php
        $termsItems = [];
        if (!empty($inv['terms_conditions'])) {
            $decoded = json_decode($inv['terms_conditions'], true);
            if (is_array($decoded)) $termsItems = array_filter($decoded);
        }
        ?>
        <?php if (!empty($termsItems)): ?>
        <div class="inv-terms-section">
          <div class="inv-terms-title">Terms &amp; Conditions</div>
          <ol class="inv-terms-list">
            <?php foreach ($termsItems as $term): ?>
            <li><?= htmlspecialchars($term) ?></li>
            <?php endforeach; ?>
          </ol>
        </div>
        <?php endif; ?>

        <!-- ===== Thank You ===== -->
        <div class="inv-thankyou">
          Thank you for doing business with us
        </div>

        </div><!-- /.inv-content -->

        <?php
          $hasWebsite = !empty($companySettings['company_website']);
          $hasEmail   = !empty($companySettings['company_email']);
        ?>
        <?php if ($hasWebsite || $hasEmail): ?>
        <div class="inv-footer">
          <?php if ($hasEmail): ?>
          <span><?= htmlspecialchars($companySettings['company_email']) ?></span>
          <?php endif; ?>
          <?php if ($hasWebsite && $hasEmail): ?>
          <span class="inv-footer-sep">|</span>
          <?php endif; ?>
          <?php if ($hasWebsite): ?>
          <span><?= htmlspecialchars($companySettings['company_website']) ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- Payment History -->
  <div class="col-xl-4 no-print">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-cash-stack me-2 text-success"></i>Payments</div>
      <div class="card-body p-0">
        <?php if ($payments): ?>
        <?php foreach ($payments as $py): ?>
        <div class="border-bottom p-3">
          <div class="d-flex justify-content-between align-items-start">
            <span class="fw-semibold text-success">₹<?= number_format($py['amount'],2) ?></span>
            <div class="d-flex align-items-center gap-2">
              <small class="text-muted"><?= date('d M Y', strtotime($py['payment_date'])) ?></small>
              <a href="<?= BASE_URL ?>/modules/invoices/receipt.php?payment_id=<?= $py['id'] ?>" target="_blank" class="btn btn-xs btn-outline-success py-0 px-1" title="View Receipt" style="font-size:.7rem;line-height:1.6">
                <i class="bi bi-receipt"></i> Receipt
              </a>
            </div>
          </div>
          <div class="small text-muted"><?= ucfirst($py['payment_mode']) ?><?= $py['transaction_id']?' • '.$py['transaction_id']:'' ?></div>
          <div class="small text-muted">By: <?= htmlspecialchars($py['by_name'] ?? '') ?></div>
          <?php if ($py['notes']): ?><div class="small text-muted"><?= htmlspecialchars($py['notes']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <div class="p-3 bg-light d-flex justify-content-between fw-semibold">
          <span>Total Paid:</span>
          <span class="text-success">₹<?= number_format($inv['paid_amount'],2) ?></span>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4 small"><i class="bi bi-cash d-block fs-3 mb-2"></i>No payments recorded</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (in_array($inv['status'],['pending','partial','overdue'])): ?>
    <a href="<?= BASE_URL ?>/modules/invoices/payment.php?invoice_id=<?= $id ?>" class="btn btn-success w-100">
      <i class="bi bi-cash-coin me-2"></i>Record Payment
    </a>
    <?php endif; ?>
  </div>
</div>

<style>
/* ============================================================
   INVOICE VIEW — Matching Reference Design
   ============================================================ */
.inv-body {
  font-family: 'Inter', 'Segoe UI', sans-serif;
  color: #1e293b;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  min-height: 900px;
}

.inv-content {
  padding: 40px;
  flex: 1;
  position: relative;
  z-index: 1;
}

/* Watermark */
.inv-watermark {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  opacity: 0.04;
  pointer-events: none;
  z-index: 0;
}
.inv-watermark img {
  max-width: 450px;
  max-height: 450px;
}
.inv-body > *:not(.inv-watermark):not(.inv-footer) {
  position: relative;
  z-index: 1;
}

/* Header */
.inv-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 24px;
}

.inv-title {
  font-size: 2.8rem;
  font-weight: 800;
  color: #0ea5e9;
  letter-spacing: -.02em;
  line-height: 1;
  margin: 0;
}

.inv-company {
  max-width: 260px;
}

.inv-logo {
  height: 45px;
  object-fit: contain;
  margin-bottom: 6px;
}

.inv-company-name {
  font-weight: 700;
  font-size: .95rem;
  color: #1e293b;
}

.inv-company-detail {
  font-size: .78rem;
  color: #64748b;
  line-height: 1.4;
}

/* Info Bar — 4 columns */
.inv-info-bar {
  display: grid;
  grid-template-columns: 1.5fr 1fr 1fr 1fr;
  border-top: 2px solid #0ea5e9;
  border-bottom: 1px solid #e2e8f0;
  padding: 12px 0;
  margin-bottom: 16px;
}

.inv-info-cell {
  padding: 0 12px;
}
.inv-info-cell:first-child { padding-left: 0; }
.inv-info-cell:not(:last-child) { border-right: 1px solid #e2e8f0; }

.inv-info-label {
  font-size: .72rem;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: .04em;
  margin-bottom: 2px;
}

.inv-info-value {
  font-size: .92rem;
  font-weight: 600;
  color: #1e293b;
}

.inv-amount-due {
  font-size: 1.2rem;
  font-weight: 800;
  color: #0ea5e9;
}

/* Client address sub-text (company, address, phone, email under client name) */
.inv-client-sub {
  font-size: .75rem;
  color: #64748b;
  line-height: 1.5;
  margin-top: 3px;
}

/* Invoice Footer — dark navy bar at bottom */
.inv-footer {
  background: #1e2d5a;
  color: #e2e8f0;
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 16px;
  padding: 14px 40px;
  font-size: .82rem;
  letter-spacing: .01em;
  position: relative;
  z-index: 1;
  margin-top: auto;
  flex-shrink: 0;
}
.inv-footer-sep {
  opacity: 0.5;
  font-size: 1rem;
  line-height: 1;
}

/* Items Table */
.inv-table-wrap {
  margin-bottom: 24px;
}

.inv-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .88rem;
}

.inv-table thead th {
  background: #0ea5e9;
  color: #fff;
  font-weight: 600;
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: .03em;
  padding: 10px 14px;
  border: none;
}

.inv-table thead th:first-child { border-radius: 4px 0 0 4px; }
.inv-table thead th:last-child  { border-radius: 0 4px 4px 0; }

.inv-table tbody td {
  padding: 10px 14px;
  border-bottom: 1px solid #e2e8f0;
  vertical-align: top;
  color: #334155;
}

.inv-table tfoot td {
  padding: 8px 14px;
  border-bottom: 1px solid #e2e8f0;
}

.inv-total-row td {
  border-top: 2px solid #0ea5e9;
  font-size: 1rem;
}

.inv-words-cell {
  font-size: .85rem;
  color: #334155;
  padding-top: 4px;
  font-style: italic;
}

/* Notes */
.inv-notes-section {
  font-size: .82rem;
  color: #64748b;
  margin-bottom: 20px;
  padding: 10px 14px;
  background: #f8fafc;
  border-radius: 6px;
}

/* Terms & Conditions */
.inv-terms-section {
  font-size: .82rem;
  color: #475569;
  margin-bottom: 20px;
  padding: 12px 16px;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  border-left: 3px solid #0ea5e9;
}
.inv-terms-title {
  font-weight: 700;
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: #0ea5e9;
  margin-bottom: 6px;
}
.inv-terms-list {
  margin: 0;
  padding-left: 18px;
  line-height: 1.7;
}

/* Thank You */
.inv-thankyou {
  font-size: 1rem;
  font-weight: 700;
  color: #0ea5e9;
  margin-top: 30px;
  padding-top: 16px;
  padding-bottom: 30px;
}

/* ===== Print ===== */
@media print {
  .sidebar, .top-navbar, #topNavbar, .no-print, .col-xl-4 { display: none !important; }
  .col-xl-8 { width: 100% !important; max-width: 100% !important; flex: 0 0 100% !important; }
  .main-content { margin-left: 0 !important; }
  body { background: white !important; }
  .card { box-shadow: none !important; border: none !important; }
  .inv-content { padding: 10px 20px; }
  .inv-body { min-height: 100vh; }
  .inv-watermark { opacity: 0.05 !important; }
  .inv-watermark img { max-width: 500px; max-height: 500px; }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
