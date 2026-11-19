<?php
/**
 * Paytrail payment status (AJAX) for the waiting page.
 * Only transactions created in the current customer session can be queried.
 * @version $Id: Nida Verkkopalvelu (www.nida.fi) / krbuk 2026 Nov 19 Modified in v5.8.0
 */
require(DIR_WS_MODULES . zen_get_module_directory('require_languages.php'));
require_once(DIR_WS_MODULES . 'payment/paytrail.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$paytrail = new paytrail();

$transactionId = (string)($_GET['transactionId'] ?? '');

if ($transactionId === '' || !preg_match('/^[A-Za-z0-9\-]{1,64}$/', $transactionId)) {
    echo json_encode(['checkout-status' => 'error', 'message' => 'missing transaction id']);
    exit;
}

if (!$paytrail->isOwnTransaction($transactionId)) {
    echo json_encode(['checkout-status' => 'error', 'message' => 'unknown transaction']);
    exit;
}

// 1) Paytrail payments table first (the callback may already have saved the final status)
$row = $paytrail->getPayment($transactionId);
if ($row !== null && in_array($row['status'], ['ok', 'pending', 'delayed'], true)) {
    echo json_encode(['checkout-status' => $row['status']]);
    exit;
}

// 2) Ask the Paytrail API (the table is updated at the same time)
$status = $paytrail->recheckPayment($transactionId);

if ($status === '') {
    echo json_encode(['checkout-status' => 'error', 'message' => 'paytrail api error']);
    exit;
}

echo json_encode(['checkout-status' => $status]);
exit;
