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
 * @version $Id: Nida Verkkopalvelu (www.nida.fi) / Krbuk 2024 Aug 8 Modified in v2.2.2
 * Providers selection in checkout page or redirect to paytrail payment page
 * If the status is fail, pending, or delayed when the payment process is initiated, use Ajax payment status checking 30sn inside every 10sn.
 * Status fail (customer pressed cancel / back on Paytrail): one quick API check -> fail: back to the payment
   selection at once / ok,pending: order is created / new (e.g. OP mobile app still approving): 30s waiting page.
 *
 * Adding (tokenizing) cards  (v5.7.0)
 *   - Admin: Modules / Payment / Paytrail -> "Korttien tallennus (tokenisointi)" = True
 *     (Token payments must be activated in your Paytrail contract)
 *   - Checkout: logged in customer can "pay by card and save the card" (pay-and-add-card)
 *     or pay with a saved card (CIT charge, 3D Secure step-up supported)
 *   - My account page: index.php?main_page=paytrail_cards  (list / remove / add card)
 *   - Tokens are stored in table  DB_PREFIX paytrail_cards  (created automatically)
 *   - Test cards: https://docs.paytrail.com/#/payment-method-providers?id=test-cards-for-tokenization
 * Klarna (coming soon)
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
 *
 *
 ***** v5.7.0 CHANGES *****
 **Card tokenization (saved cards)**
  - Logged-in customers can save a payment card at Paytrail and pay with it later.
    Guest checkout customers cannot save cards.
  - Three flows:
    - **Pay and save card**: uses `/tokenization/pay-and-add-card`. The token arrives with the Paytrail callback.
    - **Pay with a saved card**: a CIT charge (`/payments/token/cit/charge`) made after the customer confirms the order. 3D Secure step-up is supported.
    - **My saved cards page** (`index.php?main_page=paytrail_cards`): list, remove and add cards (`/tokenization/addcard-form`).
  - The card choice on the checkout page is a dropdown, so it works with any template (some templates hide radio buttons).
  - Card numbers are never stored, only the Paytrail token and masked details (type, last 4 digits, expiry date).
  - Expired cards are hidden. Cards that the bank declines permanently are removed automatically.
  - New admin setting: **"Korttien tallennus (tokenisointi)"**. Token payments must be activated in your Paytrail contract.

  **Paytrail payments table (`DB_PREFIX paytrail_payments`)**
  - Every payment is saved when it is created: transactionId, stamp, reference, customer ID and e-mail, amount, method, provider, status, order ID and session ID, plus a full copy of the order and basket.
  - The return URL, callback, waiting page and admin page all read and update this table.
  - If Paytrail reports a paid payment but no order was created (for example the customer approved in the OP mobile app and never came back), the store owner gets one e-mail with the customer, basket and amount.
  - If the customer comes back to the payment page in the same session and the payment turns out to be paid, the order is created automatically. This only happens if the cart total has not changed.

  **Admin: Customers → Paytrail Payments**
  - Filters: paid without order, not finished, today, all. Search by transactionId, reference, e-mail or order number.
  - **Check again**: fetches the current status from the Paytrail API.
  - **Basket → place order as customer**:
    1. Restores the paid basket into the customer's basket.
    2. Opens the store as the customer using Zen Cart's "Place Order" (EMP / passwordless) login.
    3. On the payment page the already paid Paytrail payment is used automatically, so the customer is not charged again.
    4. The order is created through the normal checkout, so stock is updated, e-mails are sent and order hooks (printing etc.) run.
  - **Create order** (last resort): creates the order directly from the saved copy. Stock is not decreased and no customer e-mail is sent.
  - The menu entry is registered automatically.

  **Pending payments (manual invoice activation, Walley B2B check)**
  - A `pending` / `delayed` payment no longer shows "payment failed". The order is created with the new admin status **"Tilauksen tila: odottaa aktivointia"**.
  - On the admin order page under "Additional payment options":
    - **Activate invoice** (`/capture-order`)
    - **Cancel** (`/cancel-order`, Klarna)
  - When the Paytrail callback reports the final status, the order is updated automatically.

  ### Changes
  - Payment status waiting page shortened from 40 to 30 seconds (checked every 10 seconds).
  - Customer cancels on the Paytrail page (`fail`): one quick API check is made first.
    - `fail` → back to the payment selection immediately, with a message.
    - `ok` / `pending` → the order is created (useful for OP mobile app payments).
    - `new` → 30-second waiting page.
  - When the waiting page finds a successful payment, the order is now actually created (it is verified again with the Paytrail API).
  - Callback URL is now `index.php?main_page=paytrail_callback`. The signature is checked and the callback delay is 120 seconds.
  - `manualInvoiceActivation` now follows the admin setting.

  ### Security fixes
  - **The return URL signature (HMAC) is now validated before the order is created.**
    Before this release, opening `checkout_process?checkout-status=ok` could create an unpaid order.
  - The returned transaction must belong to the customer's own session (protects against reusing an old signed URL).
  - Paytrail API response signatures are validated on every request.
  - The status AJAX endpoint only answers for the customer's own transactions.

  ### Bug fixes
  - Apple Pay script: loaded over https instead of http, and a JavaScript comment syntax error fixed.
  - Error message after a failed payment was never shown (the redirect happened before the message was added).
  - `validateHmac()` referenced an undefined exception class.

  ### New files
  - `includes/modules/pages/paytrail_callback/header_php.php`
  - `includes/modules/pages/paytrail_cards/header_php.php`
  - `includes/templates/template_default/templates/tpl_paytrail_cards_default.php`
  - `includes/languages/{english,finnish}/lang.paytrail_callback.php`
  - `includes/languages/{english,finnish}/lang.paytrail_cards.php`
  - `admin/paytrail_payments.php`
  - `admin/includes/extra_datafiles/paytrail_filenames.php`
  - `admin/includes/auto_loaders/config.paytrail_admin.php`
  - `admin/includes/init_includes/init_paytrail_admin.php`
  - `admin/includes/languages/{english,finnish}/lang.paytrail_payments.php`
  - `admin/includes/languages/{english,finnish}/extra_definitions/lang.paytrail_admin.php`

  Rename the `admin` folder to match your own admin folder name.

  ### Update instructions
  1. Back up your database and files.
  2. Copy the files (put the admin files in your own admin folder).
  3. Admin → Modules → Payment → Paytrail: **Remove** and **Install** again (new settings), then enter your merchant ID and secret key.
  4. The tables `paytrail_payments` and `paytrail_cards` are created automatically. They are kept when the module is removed.
 *
 *
 ***** INSTALL *****
 * Copy all files zencart folder
 * New files in v5.7.0:
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
 * Admin page Modules / Payment / Paytrail - Online Payment
 * Install module
 * Add paytrail merchantid and secretkey
 