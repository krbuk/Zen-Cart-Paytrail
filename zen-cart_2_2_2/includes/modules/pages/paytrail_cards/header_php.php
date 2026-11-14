<?php
/**
 * My account: saved Paytrail cards (list, remove, add new card with Paytrail add card form).
 * @version $Id: Nida Verkkopalvelu (www.nida.fi) / krbuk 2026 Nov 14 v5.7.0
 */
if (!zen_is_logged_in() || zen_in_guest_checkout()) {
    $_SESSION['navigation']->set_snapshot();
    zen_redirect(zen_href_link(FILENAME_LOGIN, '', 'SSL'));
}

require(DIR_WS_MODULES . zen_get_module_directory('require_languages.php'));
require_once(DIR_WS_MODULES . 'payment/paytrail.php');

$paytrail   = new paytrail();
$customerId = (int)$_SESSION['customer_id'];
$cardsUrl   = zen_href_link('paytrail_cards', '', 'SSL', true, false);
$paytrail_enabled = $paytrail->enabled && $paytrail->cardSavingEnabled();

if ($paytrail_enabled) {
    // Remove card (POST + security token)
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['card_id'])) {
        if (isset($_POST['securityToken']) && hash_equals($_SESSION['securityToken'], (string)$_POST['securityToken'])) {
            $paytrail->deleteCustomerCard($customerId, (int)$_POST['card_id']);
            $messageStack->add_session('paytrail_cards', TEXT_PAYTRAIL_CARD_DELETED, 'success');
        }
        zen_redirect($cardsUrl);
    }

    // Return from Paytrail add card form
    if (isset($_GET['result'])) {
        if ($_GET['result'] === 'success' && !empty($_GET['checkout-tokenization-id'])) {
            $tokenizationId = (string)$_GET['checkout-tokenization-id'];
            // The token can only be fetched with our own signed API request; if Paytrail
            // signs the return url, the signature is checked too.
            $signatureOk = !isset($_GET['signature']) || $paytrail->validateRedirectSignature($_GET);
            $tokenData = (preg_match('/^[A-Za-z0-9\-]{1,64}$/', $tokenizationId) && $signatureOk)
                ? $paytrail->getCardToken($tokenizationId)
                : null;
            if ($tokenData !== null) {
                $card = isset($tokenData->card) ? (array)$tokenData->card : [];
                $paytrail->saveCustomerCard($customerId, (string)$tokenData->token, $card);
                $messageStack->add_session('paytrail_cards', TEXT_PAYTRAIL_CARD_ADDED, 'success');
            } else {
                $messageStack->add_session('paytrail_cards', TEXT_PAYTRAIL_CARD_ADD_FAILED, 'error');
            }
        } else {
            $messageStack->add_session('paytrail_cards', TEXT_PAYTRAIL_CARD_ADD_CANCEL, 'caution');
        }
        zen_redirect($cardsUrl);
    }

    $paytrail_cards = $paytrail->getCustomerCards($customerId);
    $paytrail_add_card_form = $paytrail->addCardFormHtml(
        zen_href_link('paytrail_cards', 'result=success', 'SSL', true, false),
        zen_href_link('paytrail_cards', 'result=cancel', 'SSL', true, false)
    );
} else {
    $paytrail_cards = [];
    $paytrail_add_card_form = '';
}

$breadcrumb->add(defined('NAVBAR_TITLE_1') ? NAVBAR_TITLE_1 : 'My Account', zen_href_link(FILENAME_ACCOUNT, '', 'SSL'));
$breadcrumb->add(NAVBAR_TITLE);
