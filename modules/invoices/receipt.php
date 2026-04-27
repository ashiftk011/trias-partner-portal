<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('invoices');

$db = getDB();
$paymentId = (int)($_GET['payment_id'] ?? 0);
if (!$paymentId) redirect(BASE_URL . '/modules/invoices/index.php');

$stmt = $db->prepare("
    SELECT py.*, u.name as by_name,
           i.invoice_no, i.total_amount, i.paid_amount,
           c.name as client_name, c.company, c.email as client_email,
           c.phone as client_phone, c.address, c.city, c.state, c.gst_no
    FROM payments py
    LEFT JOIN users u ON u.id = py.created_by
    LEFT JOIN invoices i ON i.id = py.invoice_id
    LEFT JOIN clients c ON c.id = py.client_id
    WHERE py.id = ?
");
$stmt->execute([$paymentId]);
$py = $stmt->fetch();
if (!$py) redirect(BASE_URL . '/modules/invoices/index.php');

$companySettings = [];
foreach ($db->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll() as $row) {
    $companySettings[$row['setting_key']] = $row['setting_value'];
}

$receiptNo = 'RCP-' . str_pad($paymentId, 5, '0', STR_PAD_LEFT);
$pageTitle  = 'Receipt ' . $receiptNo;
include __DIR__ . '/../../includes/header.php';
?>

<!-- Action Bar -->
<div class="d-flex align-items-center gap-3 mb-4 no-print">
  <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $py['invoice_id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div class="flex-grow-1">
    <h4 class="mb-0"><?= $receiptNo ?></h4>
    <p class="text-muted small mb-0">Payment Receipt — <?= htmlspecialchars($py['invoice_no']) ?></p>
  </div>
  <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
    <i class="bi bi-printer me-1"></i>Print / Download
  </button>
</div>

<div class="row justify-content-center">
  <div class="col-xl-7 col-lg-9">
    <div class="card border-0 shadow-sm" id="receiptPrint">
      <div class="card-body p-0 rcp-body">
        <div class="rcp-content">

          <?php if (!empty($companySettings['company_logo'])): ?>
          <div class="rcp-watermark">
            <img src="<?= BASE_URL . '/' . htmlspecialchars($companySettings['company_logo']) ?>" alt="">
          </div>
          <?php endif; ?>

          <!-- Header -->
          <div class="rcp-header">
            <div>
              <h1 class="rcp-title">RECEIPT</h1>
              <div class="rcp-subtitle">Payment Confirmation</div>
            </div>
            <div class="rcp-company text-end">
              <?php if (!empty($companySettings['company_logo'])): ?>
              <img src="<?= BASE_URL . '/' . htmlspecialchars($companySettings['company_logo']) ?>" alt="Logo" class="rcp-logo">
              <?php endif; ?>
              <?php if (!empty($companySettings['company_address'])): ?>
              <div class="rcp-company-detail"><?= nl2br(htmlspecialchars($companySettings['company_address'])) ?></div>
              <?php endif; ?>
              <?php if (!empty($companySettings['company_phone'])): ?>
              <div class="rcp-company-detail">Phone: <?= htmlspecialchars($companySettings['company_phone']) ?></div>
              <?php endif; ?>
              <?php if (!empty($companySettings['company_email'])): ?>
              <div class="rcp-company-detail"><?= htmlspecialchars($companySettings['company_email']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Info Bar -->
          <div class="rcp-info-bar">
            <div class="rcp-info-cell">
              <div class="rcp-info-label">Received From</div>
              <div class="rcp-info-value"><?= htmlspecialchars($py['client_name']) ?></div>
              <?php if ($py['company']): ?>
              <div class="rcp-info-sub"><?= htmlspecialchars($py['company']) ?></div>
              <?php endif; ?>
              <?php if ($py['client_phone']): ?>
              <div class="rcp-info-sub"><?= htmlspecialchars($py['client_phone']) ?></div>
              <?php endif; ?>
            </div>
            <div class="rcp-info-cell">
              <div class="rcp-info-label">Receipt No.</div>
              <div class="rcp-info-value"><?= $receiptNo ?></div>
            </div>
            <div class="rcp-info-cell">
              <div class="rcp-info-label">Invoice No.</div>
              <div class="rcp-info-value"><?= htmlspecialchars($py['invoice_no']) ?></div>
            </div>
            <div class="rcp-info-cell">
              <div class="rcp-info-label">Payment Date</div>
              <div class="rcp-info-value"><?= date('d-M-Y', strtotime($py['payment_date'])) ?></div>
            </div>
          </div>

          <!-- Amount Block -->
          <div class="rcp-amount-block">
            <div class="rcp-amount-label">Amount Received</div>
            <div class="rcp-amount-value">₹<?= number_format($py['amount'], 2) ?></div>
          </div>

          <!-- Payment Details Table -->
          <table class="rcp-table">
            <tr>
              <td class="rcp-td-label">Payment Mode</td>
              <td class="rcp-td-value"><?= ucfirst($py['payment_mode']) ?></td>
            </tr>
            <?php if ($py['transaction_id']): ?>
            <tr>
              <td class="rcp-td-label">Transaction / Ref. ID</td>
              <td class="rcp-td-value"><?= htmlspecialchars($py['transaction_id']) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
              <td class="rcp-td-label">Invoice Total</td>
              <td class="rcp-td-value">₹<?= number_format($py['total_amount'], 2) ?></td>
            </tr>
            <tr>
              <td class="rcp-td-label">Total Paid (incl. this)</td>
              <td class="rcp-td-value">₹<?= number_format($py['paid_amount'], 2) ?></td>
            </tr>
            <?php $balance = $py['total_amount'] - $py['paid_amount']; ?>
            <tr>
              <td class="rcp-td-label">Balance Remaining</td>
              <td class="rcp-td-value <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                <?= $balance > 0 ? '₹' . number_format($balance, 2) : 'Fully Paid' ?>
              </td>
            </tr>
            <?php if ($py['notes']): ?>
            <tr>
              <td class="rcp-td-label">Notes</td>
              <td class="rcp-td-value"><?= htmlspecialchars($py['notes']) ?></td>
            </tr>
            <?php endif; ?>
          </table>

          <!-- Divider & Thank You -->
          <div class="rcp-thankyou">
            <i class="bi bi-check-circle-fill me-2"></i>Payment received. Thank you!
          </div>

          <!-- Recorded by -->
          <div class="rcp-recorded">
            Recorded by: <?= htmlspecialchars($py['by_name'] ?? '—') ?>
          </div>

        </div><!-- /.rcp-content -->

        <?php
          $hasWebsite = !empty($companySettings['company_website']);
          $hasEmail   = !empty($companySettings['company_email']);
        ?>
        <?php if ($hasWebsite || $hasEmail): ?>
        <div class="rcp-footer">
          <?php if ($hasEmail): ?>
          <span><?= htmlspecialchars($companySettings['company_email']) ?></span>
          <?php endif; ?>
          <?php if ($hasWebsite && $hasEmail): ?>
          <span class="rcp-footer-sep">|</span>
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
.rcp-body {
  font-family: 'Inter', 'Segoe UI', sans-serif;
  color: #1e293b;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.rcp-content {
  padding: 40px;
  flex: 1;
  position: relative;
  z-index: 1;
}

.rcp-watermark {
  position: absolute;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  opacity: 0.04;
  pointer-events: none;
  z-index: 0;
}
.rcp-watermark img { max-width: 400px; max-height: 400px; }

/* Header */
.rcp-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 24px;
}
.rcp-title {
  font-size: 2.4rem;
  font-weight: 800;
  color: #10b981;
  letter-spacing: -.02em;
  line-height: 1;
  margin: 0;
}
.rcp-subtitle {
  font-size: .78rem;
  color: #64748b;
  margin-top: 4px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: .06em;
}
.rcp-company { max-width: 240px; }
.rcp-logo { height: 40px; object-fit: contain; margin-bottom: 6px; }
.rcp-company-detail { font-size: .76rem; color: #64748b; line-height: 1.4; }

/* Info Bar */
.rcp-info-bar {
  display: grid;
  grid-template-columns: 1.6fr 1fr 1fr 1fr;
  border-top: 2px solid #10b981;
  border-bottom: 1px solid #e2e8f0;
  padding: 12px 0;
  margin-bottom: 28px;
}
.rcp-info-cell { padding: 0 12px; }
.rcp-info-cell:first-child { padding-left: 0; }
.rcp-info-cell:not(:last-child) { border-right: 1px solid #e2e8f0; }
.rcp-info-label {
  font-size: .7rem;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: .04em;
  margin-bottom: 2px;
}
.rcp-info-value { font-size: .9rem; font-weight: 600; color: #1e293b; }
.rcp-info-sub { font-size: .74rem; color: #64748b; }

/* Amount Block */
.rcp-amount-block {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 10px;
  padding: 20px 24px;
  margin-bottom: 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.rcp-amount-label { font-size: .82rem; color: #16a34a; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.rcp-amount-value { font-size: 2rem; font-weight: 800; color: #15803d; }

/* Details Table */
.rcp-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 28px;
}
.rcp-td-label {
  padding: 9px 14px;
  font-size: .82rem;
  color: #64748b;
  font-weight: 500;
  width: 42%;
  border-bottom: 1px solid #f1f5f9;
}
.rcp-td-value {
  padding: 9px 14px;
  font-size: .85rem;
  font-weight: 600;
  color: #1e293b;
  border-bottom: 1px solid #f1f5f9;
}

/* Thank You */
.rcp-thankyou {
  font-size: .95rem;
  font-weight: 700;
  color: #10b981;
  padding: 14px 0 6px;
}
.rcp-recorded {
  font-size: .76rem;
  color: #94a3b8;
  padding-bottom: 24px;
}

/* Footer */
.rcp-footer {
  background: #1e2d5a;
  color: #e2e8f0;
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 16px;
  padding: 12px 40px;
  font-size: .8rem;
  letter-spacing: .01em;
  position: relative;
  z-index: 1;
}
.rcp-footer-sep { opacity: .5; }

/* Print */
@media print {
  .sidebar, .top-navbar, #topNavbar, .no-print { display: none !important; }
  .main-content { margin-left: 0 !important; }
  body { background: white !important; }
  .card { box-shadow: none !important; border: none !important; }
  .col-xl-7 { width: 100% !important; max-width: 100% !important; flex: 0 0 100% !important; }
  .rcp-content { padding: 10px 20px; }
  .rcp-watermark { opacity: 0.05 !important; }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
