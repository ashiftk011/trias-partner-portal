<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('quotations');

// Only users who can also create invoices
if (!hasAccess('invoices')) {
    setFlash('error', 'You do not have permission to create invoices.');
    redirect(BASE_URL . '/modules/quotations/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/quotations/index.php');
}

verifyCsrf();

$quotationId = (int)($_POST['quotation_id'] ?? 0);
if (!$quotationId) {
    setFlash('error', 'Invalid quotation.');
    redirect(BASE_URL . '/modules/quotations/index.php');
}

$db = getDB();

// Fetch the quotation
$stmt = $db->prepare("SELECT * FROM quotations WHERE id = ?");
$stmt->execute([$quotationId]);
$q = $stmt->fetch();

if (!$q) {
    setFlash('error', 'Quotation not found.');
    redirect(BASE_URL . '/modules/quotations/index.php');
}

// Must be linked to a client
if (empty($q['client_id'])) {
    setFlash('error', 'This quotation is not linked to a client. Please link it to a converted client before converting to an invoice.');
    redirect(BASE_URL . '/modules/quotations/view.php?id=' . $quotationId);
}

// Fetch line items
$iStmt = $db->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id");
$iStmt->execute([$quotationId]);
$lineItems = $iStmt->fetchAll();

$userId = currentUser()['id'];

try {
    $db->beginTransaction();

    // Generate next invoice number
    $lastInv = $db->query("SELECT invoice_no FROM invoices ORDER BY id DESC LIMIT 1")->fetchColumn();
    $lastNum = $lastInv ? (int)preg_replace('/\D/', '', $lastInv) : 0;
    $invoiceNo = 'INV-' . str_pad($lastNum + 1, 5, '0', STR_PAD_LEFT);

    // Map quotation financials to invoice
    $subtotal        = (float)$q['subtotal'];
    $discountAmount  = (float)($q['discount'] ?? 0);
    $discountPercent = $subtotal > 0 ? round($discountAmount / $subtotal * 100, 4) : 0;
    $discountType    = 'fixed';
    $afterDiscount   = $subtotal - $discountAmount;
    $taxPercent      = (float)$q['tax_percent'];
    $taxAmount       = (float)$q['tax_amount'];
    $totalAmount     = (float)$q['total_amount'];

    $invoiceDate = date('Y-m-d');
    $dueDate     = date('Y-m-d', strtotime('+15 days'));

    // Insert invoice
    $db->prepare("INSERT INTO invoices
        (invoice_no, client_id, invoice_date, due_date, subtotal, discount_type, discount_percent,
         discount_amount, tax_percent, tax_amount, total_amount, advance_amount, paid_amount,
         status, notes, terms_conditions, currency, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,'pending',?,?,?,?)")
       ->execute([
           $invoiceNo,
           $q['client_id'],
           $invoiceDate,
           $dueDate,
           $subtotal,
           $discountType,
           $discountPercent,
           $discountAmount,
           $taxPercent,
           $taxAmount,
           $totalAmount,
           $q['notes'] ?? '',
           $q['terms_conditions'] ?? null,
           $q['currency'] ?? 'INR',
           $userId,
       ]);

    $invoiceId = (int)$db->lastInsertId();

    // Copy line items: quotation_items → invoice_items
    // quotation_items columns: description, price_type, quantity, unit_price, amount
    // invoice_items columns: item_name, description, quantity, unit_price, amount
    $itemStmt = $db->prepare("INSERT INTO invoice_items (invoice_id, item_name, description, quantity, unit_price, amount) VALUES (?,?,?,?,?,?)");
    foreach ($lineItems as $item) {
        $itemStmt->execute([
            $invoiceId,
            $item['description'],   // use description as item_name
            '',                     // no separate description in quotation items
            (float)$item['quantity'],
            (float)$item['unit_price'],
            (float)$item['amount'],
        ]);
    }

    // Mark the quotation as accepted
    $db->prepare("UPDATE quotations SET status = 'accepted' WHERE id = ?")->execute([$quotationId]);

    $db->commit();

    setFlash('success', "Quotation {$q['quotation_no']} converted to Invoice {$invoiceNo} successfully.");
    redirect(BASE_URL . '/modules/invoices/view.php?id=' . $invoiceId);

} catch (Exception $e) {
    $db->rollBack();
    setFlash('error', 'Conversion failed: ' . $e->getMessage());
    redirect(BASE_URL . '/modules/quotations/view.php?id=' . $quotationId);
}
