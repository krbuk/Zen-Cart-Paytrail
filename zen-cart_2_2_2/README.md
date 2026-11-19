# Paytrail Zen-Cart Module
Paytrail payment methods for your Zen-Cart web store
Always perform a backup of your database and source code before installing any payment extensions.

*This module works on Zen-Cart 2.2.2 If you find some bug please inform me.

To use this extension, you need to sign up for a Paytrail account. 
Before account activation use test mode if Okay call to Paytrail customer service and ask to account activations.

 * www.paytrail.com
 * REQUIRES PHP version 8.2 >=
 * Use Guzzle HTTP client v7 installed with Composer https://github.com/guzzle/guzzle/
 * We recommend using Guzzle HTTP client through composer as default HTTP client for PHP because it has
 * well documented and nice api. You can use any HTTP library to connect into Paytrail API. https://docs.paytrail.com/
 * Alternatively, if you can't install composer packages you can use http://php.net/manual/en/book.curl.php	
 * @copyright Copyright 2003-2019 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Nida Verkkopalvelu (www.nida.fi) / Krbuk 2026 Nov 19 Modified in v5.8.0
 * Providers selection in checkout page or redirect to paytrail payment page
 * Payment status check: waiting page max 30 seconds, status checked every 10 seconds.
 * Card tokenization (saved cards), Paytrail payments table, pending invoices, Shop-in-Shop.
 *
 *
 ***** UPDATE *****
 * Attention !!!
 * Remove Module from admin page Moduler / Payment / Paytrail - Online Payment
   Zencart 2.2.2 version  
   includes
    | application_top
    |- find line 139
        if (strlen($_SERVER['QUERY_STRING']) > 256)
     - change line 139 
        if (strlen($_SERVER['QUERY_STRING']) > 3000)
 * Then install the module again and enter your merchant ID and secret key (new settings in v5.8.0).
 * The tables paytrail_payments and paytrail_cards are created automatically and kept when the module is removed.
 *
 *
 ***** INSTALL *****
 * Copy all files zencart folder (put the admin files in your own, renamed admin folder)
 * Admin page Modules / Payment / Paytrail - Online Payment
 * Install module
 * Add paytrail merchantid and secretkey
 *
 * New files in v5.8.0:
 *   includes/modules/pages/paytrail_callback/header_php.php
 *   includes/modules/pages/paytrail_cards/header_php.php
 *   includes/templates/template_default/templates/tpl_paytrail_cards_default.php
 *   includes/languages/{english,finnish}/lang.paytrail_callback.php, lang.paytrail_cards.php
 *   admin/paytrail_payments.php
 *   admin/includes/extra_datafiles/paytrail_filenames.php
 *   admin/includes/auto_loaders/config.paytrail_admin.php
 *   admin/includes/init_includes/init_paytrail_admin.php   (registers the admin menu entry automatically)
 *   admin/includes/languages/{english,finnish}/lang.paytrail_payments.php
 *   admin/includes/languages/{english,finnish}/extra_definitions/lang.paytrail_admin.php
 *   (rename the admin folder to your own admin folder name)


## Changelog

### v5.8.0 (2026-11-19)

#### Card tokenization (saved cards)
- Logged-in customers can save a payment card at Paytrail and pay with it later (guest checkout cannot save cards).
- **Pay and save card**: `/tokenization/pay-and-add-card`. The token and card details (`type`, `partial_pan`, `expire_month`, `expire_year`) arrive with the Paytrail callback.
- **Pay with a saved card**: CIT charge (`/payments/token/cit/charge`) made after the customer confirms the order. 3D Secure step-up is supported.
- **My saved cards page** (`index.php?main_page=paytrail_cards`): list, remove and add cards (`/tokenization/addcard-form`).
- The card choice on the checkout page is a dropdown, so it works with any template.
- Only the Paytrail token and masked card details are stored, never the card number. Expired cards are hidden; cards the bank declines permanently are removed.
- New admin setting **"Korttien tallennus (tokenisointi)"** (token payments must be activated in your Paytrail contract).

#### Paytrail payments table (`DB_PREFIX paytrail_payments`)
- Every payment is saved when it is created: transactionId, stamp, merchant reference, Paytrail bank reference, customer ID / e-mail, amount, method, provider, status, order ID, session ID and a full copy of the order and basket.
- Return URL, callback, waiting page and admin page all read and update this table.
- Paytrail reports a paid payment but no order was created (e.g. OP mobile app, customer did not come back): one e-mail to the store owner with customer, basket and amount.
- Customer comes back to the payment page in the same session and the payment is paid: the order is created automatically (only if the cart total has not changed).

#### Admin: Customers → Paytrail Payments
- Filters (paid without order, not finished, today, all) and search by transactionId, reference, e-mail or order number.
- **Check again**: current status from the Paytrail API.
- **Basket → place order as customer**: restores the paid basket into the customer's basket and opens the store as the customer with Zen Cart's "Place Order" (EMP / passwordless) login. On the payment page the already paid Paytrail payment is used automatically (no new charge) and the order is created through the normal checkout (stock, e-mails, order hooks).
- **Create order** (last resort): creates the order directly from the saved copy (stock is not decreased, no customer e-mail).
- The admin menu entry is registered automatically.

#### Pending payments (manual invoice activation, Walley B2B check)
- `pending` / `delayed` no longer shows "payment failed": the order is created with the new admin status **"Tilauksen tila: odottaa aktivointia"**.
- Admin order page → "Additional payment options": **Activate invoice** (`/capture-order`) and **Cancel** (`/cancel-order`, Klarna).
- The Paytrail callback with the final status updates the order automatically.

#### Changes
- Payment status waiting page 40 s → 30 s (checked every 10 seconds).
- Customer cancels on the Paytrail page (`fail`): one quick API check first. `fail` → back to the payment selection at once with a message; `ok` / `pending` → order is created (OP mobile app); `new` → 30 s waiting page.
- When the waiting page finds a successful payment, the order is now actually created (verified again with the Paytrail API).
- Callback URL is now `index.php?main_page=paytrail_callback` (signature checked, callback delay 120 s).
- `manualInvoiceActivation` follows the admin setting.
- **Shop-in-Shop**: `manualInvoiceActivation` is no longer sent (Paytrail rejects it for Shop-in-Shop accounts: "instance is not allowed to have the additional property manualInvoiceActivation").
- Order history note shows the **merchant reference ("Kauppiaan viite")** for every payment method; the transactionId is no longer written to the order note (it stays in the payments table and on the admin order page).
- Paytrail's own bank reference (POST `/payments` response `reference`) is saved in the `bank_reference` column (added automatically to existing tables).
- Developer debug lines (commented out) restored: Paytrail response (HTTP status, HMAC, transactionId, request-id, JSON) after `/payments`, and the request body at the end of `getBody()`.

#### Security fixes
- **The return URL signature (HMAC) is validated before the order is created.** Before, opening `checkout_process?checkout-status=ok` could create an unpaid order.
- The returned transaction must belong to the customer's own session (no reuse of an old signed URL).
- Paytrail API response signatures are validated on every request.
- The status AJAX endpoint only answers for the customer's own transactions.

#### Bug fixes
- Apple Pay script loaded over https; JavaScript comment syntax error fixed.
- Error message after a failed payment was never shown (redirect happened before the message was added).
- `validateHmac()` referenced an undefined exception class.
