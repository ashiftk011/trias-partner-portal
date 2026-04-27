<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('quotations');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/quotations/index.php');

$stmt = $db->prepare("SELECT q.*,
    COALESCE(c.name, l.name) as client_name,
    c.client_code,
    COALESCE(c.company, l.company) as company,
    COALESCE(c.email, l.email) as client_email,
    COALESCE(c.phone, l.phone) as client_phone,
    COALESCE(c.address, l.address) as address,
    c.city, c.state, c.gst_no,
    p.name as project_name,
    u.name as created_by_name
    FROM quotations q
    LEFT JOIN clients c ON c.id=q.client_id
    LEFT JOIN leads l ON l.id=q.lead_id
    LEFT JOIN projects p ON p.id=q.project_id
    LEFT JOIN users u ON u.id=q.created_by
    WHERE q.id=?");
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) redirect(BASE_URL . '/modules/quotations/index.php');

// Line items
$itemStmt = $db->prepare("SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY id");
$itemStmt->execute([$id]);
$lineItems = $itemStmt->fetchAll();

// Company settings
$companySettings = [];
foreach ($db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll() as $row) {
    $companySettings[$row['setting_key']] = $row['setting_value'];
}

$discountAmount = $inv['discount'] ?? 0;

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

$pageTitle = 'Quotation ' . $inv['quotation_no'];
include __DIR__ . '/../../includes/header.php';
?>

<!-- Action Bar (hidden on print) -->
<div class="d-flex align-items-center gap-3 mb-4 no-print">
  <a href="<?= BASE_URL ?>/modules/quotations/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div class="flex-grow-1">
    <h4 class="mb-0"><?= htmlspecialchars($inv['quotation_no']) ?> - <?= htmlspecialchars($inv['title']) ?></h4>
    <p class="text-muted small mb-0"><?= htmlspecialchars($inv['client_name'] ?? 'Guest/Lead') ?></p>
  </div>
  <div class="d-flex gap-2">
    <?= statusBadge($inv['status']) ?>
    <a href="<?= BASE_URL ?>/modules/quotations/save.php?id=<?= $id ?>" class="btn btn-warning btn-sm">
      <i class="bi bi-pencil-square me-1"></i>Edit
    </a>
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>Print
    </button>
  </div>
</div>

<?php displayFlash(); ?>

<div class="row g-4 justify-content-center">
  <!-- Quotation Document -->
  <div class="col-xl-9">
    <div class="card border-0 shadow-sm" id="invoicePrint">
      <div class="card-body p-0 position-relative inv-body">
        <div class="inv-content">

        <?php if (!empty($companySettings['company_logo'])): ?>
        <div class="inv-watermark">
          <img src="<?= BASE_URL . '/' . htmlspecialchars($companySettings['company_logo']) ?>" alt="">
        </div>
        <?php endif; ?>

        <!-- ===== Quotation Header ===== -->
        <div class="inv-header">
          <div>
            <h1 class="inv-title" style="color: #6366f1;">QUOTATION</h1>
          </div>
          <div class="inv-company text-end">
            <?php if (!empty($companySettings['company_logo'])): ?>
            <img src="<?= BASE_URL . '/' . htmlspecialchars($companySettings['company_logo']) ?>"
                 alt="Logo" class="inv-logo">
            <?php endif; ?>
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
        <div class="inv-info-bar" style="border-top-color: #6366f1;">
          <div class="inv-info-cell">
            <div class="inv-info-label">Prepared For:</div>
            <div class="inv-info-value"><?= htmlspecialchars($inv['client_name'] ?? 'Lead') ?></div>
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
            <div class="inv-info-value"><?= date('d-M-Y', strtotime($inv['created_at'])) ?></div>
          </div>
          <div class="inv-info-cell">
            <div class="inv-info-label">Quotation Number:</div>
            <div class="inv-info-value"><?= htmlspecialchars($inv['quotation_no']) ?></div>
          </div>
          <div class="inv-info-cell">
            <div class="inv-info-label">Valid Until:</div>
            <div class="inv-info-value"><?= $inv['valid_until'] ? date('d-M-Y', strtotime($inv['valid_until'])) : '-' ?></div>
          </div>
        </div>

        <!-- ===== Items Table ===== -->
        <div class="inv-table-wrap">
          <table class="inv-table">
            <thead>
              <tr style="background-color: #6366f1;">
                <th style="width:5%" class="text-center">S.No</th>
                <th style="width:40%">Description</th>
                <th style="width:12%" class="text-center">Qty</th>
                <th style="width:20%" class="text-end">Unit Price</th>
                <th style="width:23%" class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($lineItems)): ?>
                <?php foreach ($lineItems as $idx => $item): ?>
                <tr>
                  <td class="text-center text-muted"><?= $idx + 1 ?></td>
                  <td class="fw-semibold">
                    <?= htmlspecialchars($item['description']) ?>
                    <?php if ($item['price_type'] && $item['price_type'] !== 'one_time'): ?>
                      <br><small class="text-muted fst-italic">(<?= ucfirst($item['price_type']) ?>)</small>
                    <?php endif; ?>
                  </td>
                  <td class="text-center"><?= $item['quantity'] != 1 ? number_format($item['quantity'], 2) : 1 ?></td>
                  <td class="text-end">₹<?= number_format($item['unit_price'], 2) ?></td>
                  <td class="text-end fw-bold">₹<?= number_format($item['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" class="text-center text-muted py-4">No line items added.</td>
                </tr>
              <?php endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" class="text-end text-muted">Subtotal:</td>
                <td class="text-end text-muted">₹<?= number_format($inv['subtotal'], 2) ?></td>
              </tr>
              <?php if ($inv['tax_percent'] > 0): ?>
              <tr>
                <td colspan="4" class="text-end">GST / Tax (<?= $inv['tax_percent'] ?>%):</td>
                <td class="text-end">₹<?= number_format($inv['tax_amount'], 2) ?></td>
              </tr>
              <?php endif; ?>
              <?php if ($discountAmount > 0): ?>
              <tr>
                <td colspan="4" class="text-end">Discount:</td>
                <td class="text-end text-danger">-₹<?= number_format($discountAmount, 2) ?></td>
              </tr>
              <?php endif; ?>
              <tr class="inv-total-row" style="border-top-color: #6366f1;">
                <td colspan="4" class="text-end"><strong>Total</strong></td>
                <td class="text-end"><strong style="color: #6366f1; font-size: 1.1rem;">₹<?= number_format($inv['total_amount'], 2) ?></strong></td>
              </tr>
              <tr>
                <td colspan="1"></td>
                <td colspan="4" class="inv-words-cell">
                  <strong><?= $amountInWords ?></strong>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>

        <?php if (!empty(trim($inv['notes'] ?? ''))): ?>
        <div class="inv-notes-section">
          <strong>Notes &amp; Terms:</strong><br>
          <?= nl2br(htmlspecialchars($inv['notes'])) ?>
        </div>
        <?php endif; ?>

        <!-- ===== Thank You ===== -->
        <div class="inv-thankyou" style="color: #6366f1;">
          We look forward to doing business with you.
        </div>

        </div><!-- /.inv-content -->

        <?php
          $hasWebsite = !empty($companySettings['company_website']);
          $hasEmail   = !empty($companySettings['company_email']);
        ?>
        <?php if ($hasWebsite || $hasEmail): ?>
        <div class="inv-footer" style="background: #312e81;">
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
</div>

<style>
/* ============================================================
   QUOTATION VIEW — Matching Reference Design (Invoice View)
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

.inv-company-detail {
  font-size: .78rem;
  color: #64748b;
  line-height: 1.4;
}

/* Info Bar — 4 columns */
.inv-info-bar {
  display: grid;
  grid-template-columns: 1.5fr 1fr 1fr 1fr;
  border-top: 2px solid;
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

/* Client address sub-text */
.inv-client-sub {
  font-size: .75rem;
  color: #64748b;
  line-height: 1.5;
  margin-top: 3px;
}

/* Footer */
.inv-footer {
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
  border-top: 2px solid;
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

/* Thank You */
.inv-thankyou {
  font-size: 1rem;
  font-weight: 700;
  margin-top: 30px;
  padding-top: 16px;
  padding-bottom: 30px;
}

/* ===== Print ===== */
@media print {
  .sidebar, .top-navbar, #topNavbar, .no-print, .col-xl-4 { display: none !important; }
  .col-xl-9 { width: 100% !important; max-width: 100% !important; flex: 0 0 100% !important; }
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
