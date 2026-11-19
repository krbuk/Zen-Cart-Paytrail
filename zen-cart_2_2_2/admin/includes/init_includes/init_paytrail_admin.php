<?php
/**
 * Paytrail module v5.8.0 - register admin page "Paytrail Payments" under Customers if missing.
 */
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}
if (defined('FILENAME_PAYTRAIL_PAYMENTS') && function_exists('zen_page_key_exists') && function_exists('zen_register_admin_page')) {
    if (!zen_page_key_exists('paytrailPayments')) {
        zen_register_admin_page('paytrailPayments', 'BOX_PAYTRAIL_PAYMENTS', 'FILENAME_PAYTRAIL_PAYMENTS', '', 'customers', 'Y');
    }
}
