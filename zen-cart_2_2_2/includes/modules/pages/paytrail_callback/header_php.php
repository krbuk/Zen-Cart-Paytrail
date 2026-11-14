<?php
/**
 * Paytrail callback URL (server to server, HTTP GET, no customer session).
 * - Validates the signature of all checkout- parameters.
 * - Pending payments: final status (ok / fail) updates the order.
 * - Pay and add card: saves checkout-card-token for the customer whose id is signed into the url (cid + csig).
 * Always answers HTTP 200 so Paytrail does not retry a request that has been handled.
 * @version $Id: Nida Verkkopalvelu (www.nida.fi) / krbuk 2026 Nov 14 v5.7.0
 */
require(DIR_WS_MODULES . zen_get_module_directory('require_languages.php'));
require_once(DIR_WS_MODULES . 'payment/paytrail.php');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$paytrail = new paytrail();

if (!$paytrail->validateRedirectSignature($_GET)) {
    error_log('Paytrail callback: signature mismatch');
    http_response_code(401);
    echo 'invalid signature';
    exit;
}

$status = (string)($_GET['checkout-status'] ?? '');
$token  = (string)($_GET['checkout-card-token'] ?? '');

// Paytrail payments table: status update. Paid but no order -> e-mail to store owner.
// Final result of a pending payment (invoice activated in the Paytrail merchant panel,
// Walley B2B anti-fraud check ready, etc.) -> update the order status.
$paytrail->updateOrderFromCallback((string)($_GET['checkout-transaction-id'] ?? ''), $status, (string)($_GET['checkout-provider'] ?? ''));

if ($status === 'ok' && $token !== '' && $paytrail->cardSavingEnabled()) {
    $customerId = (int)($_GET['cid'] ?? 0);
    $stamp      = (string)($_GET['checkout-stamp'] ?? '');
    $csig       = (string)($_GET['csig'] ?? '');

    if ($customerId > 0 && $stamp !== '' && hash_equals($paytrail->customerSignature($customerId, $stamp), $csig)) {
        // Card details come as extra parameters WITHOUT the checkout- prefix
        // (type, partial_pan, expire_month, expire_year, card_fingerprint) - same as
        // Paytrail's own Magento module reads them. Prefixed names are accepted too.
        $param = function (string $name) {
            foreach ([$name, 'checkout-' . $name, 'checkout-' . str_replace('_', '-', $name), 'checkout-card-' . str_replace('_', '-', $name)] as $key) {
                if (isset($_GET[$key]) && !is_array($_GET[$key]) && $_GET[$key] !== '') {
                    return (string)$_GET[$key];
                }
            }
            return '';
        };
        $card = [
            'type'             => $param('type'),
            'partial_pan'      => $param('partial_pan'),
            'expire_month'     => $param('expire_month'),
            'expire_year'      => $param('expire_year'),
            'card_fingerprint' => $param('card_fingerprint'),
        ];
        if ($card['partial_pan'] === '') {
            // Log only the parameter NAMES (never the token) to find the right names
            error_log('Paytrail callback: card details missing, parameters: ' . implode(',', array_keys($_GET)));
        }
        $paytrail->saveCustomerCard($customerId, $token, $card);
    } else {
        error_log('Paytrail callback: card token received but customer signature is invalid');
    }
}

if ($status !== 'ok' && !empty($_GET['checkout-acquirer-response-code'])) {
    error_log('Paytrail callback: ' . $status . ' acquirer ' . $_GET['checkout-acquirer-response-code'] . ' ' . ($_GET['checkout-acquirer-response-code-description'] ?? ''));
}

http_response_code(200);
echo 'OK';
exit;
