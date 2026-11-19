<?php
/**
 * Admin: Customers -> Paytrail Payments
 * Paytrail payments table (transactionId <-> customer <-> order): check again, create order (last resort).
 * @version $Id: Nida Verkkopalvelu (www.nida.fi) / krbuk 2026 Nov 19 v5.8.0
 */
require('includes/application_top.php');

// Paytrail module + its catalog language definitions
$paytrail = null;
if (defined('MODULE_PAYMENT_PAYTRAIL_STATUS')) {
    $ptLang = is_dir(DIR_FS_CATALOG_LANGUAGES . $_SESSION['language']) ? $_SESSION['language'] : 'english';
    $ptLangFile = DIR_FS_CATALOG_LANGUAGES . $ptLang . '/modules/payment/lang.paytrail.php';
    if (!is_file($ptLangFile)) {
        $ptLangFile = DIR_FS_CATALOG_LANGUAGES . 'english/modules/payment/lang.paytrail.php';
    }
    foreach ((array)(include $ptLangFile) as $ptKey => $ptValue) {
        if (!defined($ptKey)) {
            define($ptKey, $ptValue);
        }
    }
    require_once DIR_FS_CATALOG_MODULES . 'payment/paytrail.php';
    $paytrail = new paytrail();
    $paytrail->ensureCardTable();
}

$table  = DB_PREFIX . 'paytrail_payments';
$filter = in_array($_GET['filter'] ?? '', ['all', 'today', 'open', 'noorder'], true) ? $_GET['filter'] : 'noorder';
$search = trim((string)($_GET['search'] ?? ''));
$selfParams = 'filter=' . $filter . ($search !== '' ? '&search=' . urlencode($search) : '');

// ---- actions (POST, security token checked by admin application_top) ----
if ($paytrail !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['tx'])) {
    $tx = (string)$_POST['tx'];
    $action = (string)($_GET['action'] ?? '');

    if ($action === 'recheck') {
        $status = $paytrail->recheckPayment($tx);
        if ($status === '') {
            $messageStack->add_session(PT_RECHECK_FAILED, 'error');
        } else {
            $messageStack->add_session(sprintf(PT_RECHECK_DONE, zen_output_string_protected($status)), 'success');

            // Paid at Paytrail but no order: create it from the saved order copy.
            // Matched by Transaction ID and Paytrail's own amount - NOT by a recalculated cart
            // (shipping / fees / points may give a different total when the cart is rebuilt).
            $row = $paytrail->getPayment($tx);
            $saved = ($row !== null) ? json_decode((string)$row['cart_snapshot'], true) : null;
            if ($row !== null && (int)$row['orders_id'] === 0 && in_array($status, ['ok', 'pending', 'delayed'], true) && !empty($saved['full'])) {
                $paidAmount = (int)($paytrail->lastApiStatus['amount'] ?? -1);
                if (strtotime($row['date_added']) > time() - 300) {
                    // the customer may still be on his way back to the store
                    $messageStack->add_session(PT_RECHECK_TOO_NEW, 'caution');
                } elseif ($paidAmount !== (int)$row['amount']) {
                    $messageStack->add_session(sprintf(PT_RECHECK_AMOUNT_DIFFERS, number_format($paidAmount / 100, 2, ',', ' '), number_format($row['amount'] / 100, 2, ',', ' ')), 'error');
                } else {
                    $ordersId = $paytrail->createOrderFromPayment($tx, true);
                    if ($ordersId > 0) {
                        zen_record_admin_activity('Paytrail: order ' . $ordersId . ' created by "check again" from payment ' . $tx, 'warning');
                        $messageStack->add_session(sprintf(PT_ORDER_CREATED, $ordersId), 'success');
                        zen_redirect(zen_href_link(FILENAME_ORDERS, 'oID=' . $ordersId . '&action=edit', 'NONSSL'));
                    }
                    $messageStack->add_session(sprintf(PT_ORDER_FAILED, zen_output_string_protected($paytrail->lastError)), 'error');
                }
            }
        }
    }

    if ($action === 'create_order') {
        // Fresh status first - never create an order for an unpaid payment
        $status = $paytrail->recheckPayment($tx);
        $ordersId = $paytrail->createOrderFromPayment($tx, !empty($_POST['empty_basket']));
        if ($ordersId > 0) {
            zen_record_admin_activity('Paytrail: order ' . $ordersId . ' created from payment ' . $tx, 'warning');
            $messageStack->add_session(sprintf(PT_ORDER_CREATED, $ordersId), 'success');
            zen_redirect(zen_href_link(FILENAME_ORDERS, 'oID=' . $ordersId . '&action=edit', 'NONSSL'));
        }
        $messageStack->add_session(sprintf(PT_ORDER_FAILED, zen_output_string_protected($paytrail->lastError)), 'error');
    }
    if ($action === 'restore_basket') {
        // Saved basket of the payment -> customer's basket (customers_basket), then
        // the admin opens the store as the customer (Zen Cart "Place Order" / EMP login)
        $row = $paytrail->getPayment($tx);
        $snapshot = ($row !== null) ? json_decode((string)$row['cart_snapshot'], true) : null;
        $cart = $snapshot['full']['cart'] ?? [];
        $cid  = ($row !== null) ? (int)$row['customers_id'] : 0;
        if ($cid > 0 && is_array($cart) && !empty($cart)) {
            $db->Execute("DELETE FROM " . TABLE_CUSTOMERS_BASKET . " WHERE customers_id = " . $cid);
            $db->Execute("DELETE FROM " . TABLE_CUSTOMERS_BASKET_ATTRIBUTES . " WHERE customers_id = " . $cid);
            foreach ($cart as $uprid => $item) {
                $uprid_db = zen_db_input((string)$uprid);
                $qty = (float)($item['qty'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $db->Execute("INSERT INTO " . TABLE_CUSTOMERS_BASKET . " (customers_id, products_id, customers_basket_quantity, customers_basket_date_added)
                              VALUES (" . $cid . ", '" . $uprid_db . "', " . $qty . ", '" . date('Ymd') . "')");
                foreach ((array)($item['attributes'] ?? []) as $option => $value) {
                    $text = (string)($item['attributes_values'][$option] ?? '');
                    $db->Execute("INSERT INTO " . TABLE_CUSTOMERS_BASKET_ATTRIBUTES . " (customers_id, products_id, products_options_id, products_options_value_id, products_options_value_text, products_options_sort_order)
                                  VALUES (" . $cid . ", '" . $uprid_db . "', '" . zen_db_input((string)$option) . "', " . (int)$value . ", '" . zen_db_input($text) . "', '0')");
                }
            }
            zen_record_admin_activity('Paytrail: basket of payment ' . $tx . ' restored for customer ' . $cid, 'info');
            $messageStack->add_session(PT_BASKET_RESTORED, 'success');
            zen_redirect(zen_href_link(FILENAME_PAYTRAIL_PAYMENTS, $selfParams . '&emp_tx=' . urlencode($tx), 'NONSSL'));
        }
        $messageStack->add_session(PT_BASKET_FAILED, 'error');
    }
    zen_redirect(zen_href_link(FILENAME_PAYTRAIL_PAYMENTS, $selfParams, 'NONSSL'));
}

// ---- "open the store as the customer" box (after the basket was restored) ----
$empBox = '';
if ($paytrail !== null && !empty($_GET['emp_tx'])) {
    $empRow = $paytrail->getPayment((string)$_GET['emp_tx']);
    if ($empRow !== null && (int)$empRow['customers_id'] > 0) {
        $cust = $db->Execute("SELECT customers_id, customers_email_address FROM " . TABLE_CUSTOMERS . " WHERE customers_id = " . (int)$empRow['customers_id'] . " LIMIT 1");
        if (!$cust->EOF) {
            $empBox  = '<div class="alert alert-info"><b>' . PT_EMP_TITLE . '</b><br>' . sprintf(PT_EMP_INFO, number_format($empRow['amount'] / 100, 2, ',', ' ')) . '<br><br>';
            if (function_exists('zen_admin_authorized_to_place_order') && zen_admin_authorized_to_place_order()) {
                // Same form as admin/customers.php "Place Order"
                $hidden = zen_draw_hidden_field('email_address', $cust->fields['customers_email_address']);
                $action = zen_catalog_href_link(FILENAME_LOGIN, '', 'SSL');
                if (defined('EMP_LOGIN_AUTOMATIC') && EMP_LOGIN_AUTOMATIC === 'true' && ENABLE_SSL_CATALOG === 'true') {
                    $secret    = zen_update_customers_secret($cust->fields['customers_id']);
                    $timestamp = time();
                    $hmacUri   = zen_create_hmac_uri([
                        'cid' => $cust->fields['customers_id'],
                        'aid' => $_SESSION['admin_id'],
                        'email_address' => $cust->fields['customers_email_address'],
                        'timestamp' => $timestamp,
                    ], $secret);
                    $action  = zen_catalog_href_link(FILENAME_LOGIN, $hmacUri . '&action=process', 'SSL');
                    $hidden .= zen_draw_hidden_field('aid', $_SESSION['admin_id']) . zen_draw_hidden_field('cid', $cust->fields['customers_id']) . zen_draw_hidden_field('timestamp', $timestamp);
                }
                $empBox .= '<form rel="noopener" target="_blank" name="login" action="' . $action . '" method="post">' . $hidden
                         . '<button type="submit" class="btn btn-primary">' . PT_EMP_OPEN_STORE . '</button></form>';
            } else {
                $empBox .= PT_EMP_NOT_AUTHORIZED;
            }
            $empBox .= '</div>';
        }
    }
}

// ---- list ----
$rows = [];
if ($paytrail !== null) {
    $where = [];
    if ($filter === 'noorder') {
        $where[] = "orders_id = 0 AND status IN ('ok','pending','delayed')";
    } elseif ($filter === 'today') {
        $where[] = "date_added >= CURDATE()";
    } elseif ($filter === 'open') {
        $where[] = "orders_id = 0 AND status = 'new' AND date_added >= NOW() - INTERVAL 2 DAY";
    }
    if ($search !== '') {
        $like = "'%" . zen_db_input($search) . "%'";
        $where[] = "(transaction_id LIKE $like OR reference LIKE $like OR bank_reference LIKE $like OR customer_email LIKE $like OR customer_name LIKE $like" . (ctype_digit($search) ? " OR orders_id = " . (int)$search : "") . ")";
    }
    $sql = "SELECT * FROM " . $table . ($where ? " WHERE " . implode(' AND ', $where) : "") . " ORDER BY id DESC LIMIT 200";
    $result = $db->Execute($sql);
    while (!$result->EOF) {
        $rows[] = $result->fields;
        $result->MoveNext();
    }
}
$statusColors = ['ok' => 'success', 'pending' => 'warning', 'delayed' => 'warning', 'new' => 'default', 'fail' => 'danger', 'cancelled' => 'default'];
?>
<!doctype html>
<html <?php echo HTML_PARAMS; ?>>
  <head>
    <?php require DIR_WS_INCLUDES . 'admin_html_head.php'; ?>
  </head>
  <body>
    <?php require DIR_WS_INCLUDES . 'header.php'; ?>
    <div class="container-fluid">
      <h1 class="pageHeading"><?php echo HEADING_TITLE; ?></h1>

      <?php if ($paytrail === null) { ?>
        <p class="alert alert-warning"><?php echo PT_MODULE_MISSING; ?></p>
      <?php } else { ?>
        <p><?php echo PT_TEXT_INFO; ?></p>
        <?php echo $empBox; ?>

        <div class="row" style="margin-bottom:10px">
          <div class="col-sm-7">
            <?php foreach (['noorder' => PT_FILTER_NO_ORDER, 'open' => PT_FILTER_OPEN, 'today' => PT_FILTER_TODAY, 'all' => PT_FILTER_ALL] as $key => $label) { ?>
              <a class="btn btn-sm <?php echo $filter === $key ? 'btn-primary' : 'btn-default'; ?>" href="<?php echo zen_href_link(FILENAME_PAYTRAIL_PAYMENTS, 'filter=' . $key, 'NONSSL'); ?>"><?php echo $label; ?></a>
            <?php } ?>
          </div>
          <div class="col-sm-5 text-right">
            <?php echo zen_draw_form('pt_search', FILENAME_PAYTRAIL_PAYMENTS, '', 'get', 'class="form-inline"', true); ?>
              <?php echo zen_draw_hidden_field('filter', 'all'); ?>
              <?php echo zen_draw_input_field('search', $search, 'class="form-control input-sm" placeholder="' . PT_SEARCH . '" size="40"'); ?>
              <button type="submit" class="btn btn-sm btn-default"><i class="fa-solid fa-magnifying-glass"></i></button>
            </form>
          </div>
        </div>

        <table class="table table-hover table-condensed">
          <thead>
            <tr class="dataTableHeadingRow">
              <th class="dataTableHeadingContent"><?php echo PT_COL_DATE; ?></th>
              <th class="dataTableHeadingContent"><?php echo PT_COL_TX; ?></th>
              <th class="dataTableHeadingContent"><?php echo PT_COL_CUSTOMER; ?></th>
              <th class="dataTableHeadingContent text-right"><?php echo PT_COL_AMOUNT; ?></th>
              <th class="dataTableHeadingContent"><?php echo PT_COL_STATUS; ?></th>
              <th class="dataTableHeadingContent"><?php echo PT_COL_ORDER; ?></th>
              <th class="dataTableHeadingContent text-right"><?php echo PT_COL_ACTIONS; ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)) { ?>
            <tr><td colspan="7"><?php echo PT_NOTHING; ?></td></tr>
          <?php } ?>
          <?php foreach ($rows as $row) {
              $paidNoOrder = ((int)$row['orders_id'] === 0 && in_array($row['status'], ['ok', 'pending', 'delayed'], true));
              $snapshot = json_decode((string)$row['cart_snapshot'], true);
          ?>
            <tr class="dataTableRow<?php echo $paidNoOrder ? ' danger' : ''; ?>">
              <td class="dataTableContent"><?php echo zen_output_string_protected($row['date_added']); ?></td>
              <td class="dataTableContent">
                <small><?php echo zen_output_string_protected($row['transaction_id']); ?></small><br>
                <b><?php echo zen_output_string_protected($row['reference']); ?></b>
                &middot; <small><?php echo zen_output_string_protected($row['method']); ?></small>
              </td>
              <td class="dataTableContent">
                <?php if ((int)$row['customers_id'] > 0) { ?>
                  <a href="<?php echo zen_href_link(FILENAME_CUSTOMERS, 'cID=' . (int)$row['customers_id'] . '&action=edit', 'NONSSL'); ?>"><?php echo zen_output_string_protected($row['customer_name']); ?></a>
                <?php } else { echo zen_output_string_protected($row['customer_name']); } ?>
                <br><small><?php echo zen_output_string_protected($row['customer_email']); ?><?php echo !empty($snapshot['telephone']) ? ' &middot; ' . zen_output_string_protected($snapshot['telephone']) : ''; ?></small>
              </td>
              <td class="dataTableContent text-right"><?php echo number_format($row['amount'] / 100, 2, ',', ' ') . ' ' . zen_output_string_protected($row['currency']); ?></td>
              <td class="dataTableContent">
                <span class="label label-<?php echo $statusColors[$row['status']] ?? 'default'; ?>"><?php echo zen_output_string_protected($row['status']); ?></span>
                <?php echo $row['provider'] !== '' ? '<br><small>' . zen_output_string_protected($row['provider']) . '</small>' : ''; ?>
                <?php echo !empty($row['last_checked']) ? '<br><small>' . PT_LAST_CHECKED . ' ' . zen_output_string_protected($row['last_checked']) . '</small>' : ''; ?>
              </td>
              <td class="dataTableContent">
                <?php if ((int)$row['orders_id'] > 0) { ?>
                  <a href="<?php echo zen_href_link(FILENAME_ORDERS, 'oID=' . (int)$row['orders_id'] . '&action=edit', 'NONSSL'); ?>">#<?php echo (int)$row['orders_id']; ?></a>
                <?php } elseif ($paidNoOrder) { ?>
                  <b class="text-danger"><?php echo PT_NO_ORDER; ?></b>
                <?php } else { echo '-'; } ?>
              </td>
              <td class="dataTableContent text-right" style="white-space:nowrap">
                <?php echo zen_draw_form('pt_recheck_' . (int)$row['id'], FILENAME_PAYTRAIL_PAYMENTS, $selfParams . '&action=recheck', 'post', 'style="display:inline"' . (((int)$row['orders_id'] === 0 && in_array($row['status'], ['ok', 'pending', 'delayed', 'new'], true)) ? ' onsubmit="return confirm(\'' . addslashes(PT_RECHECK_CONFIRM) . '\');"' : '')); ?>
                  <?php echo zen_draw_hidden_field('tx', $row['transaction_id']); ?>
                  <button type="submit" class="btn btn-xs btn-default"><?php echo PT_RECHECK; ?></button>
                </form>
                <?php if ($paidNoOrder && isset($snapshot['full'])) { ?>
                  <?php echo zen_draw_form('pt_create_' . (int)$row['id'], FILENAME_PAYTRAIL_PAYMENTS, $selfParams . '&action=create_order', 'post', 'style="display:inline" onsubmit="return confirm(\'' . addslashes(PT_CREATE_CONFIRM) . '\');"'); ?>
                    <?php echo zen_draw_hidden_field('tx', $row['transaction_id']); ?>
                    <label style="font-weight:normal; font-size:11px"><input type="checkbox" name="empty_basket" value="1" checked> <?php echo PT_EMPTY_BASKET; ?></label>
                    <button type="submit" class="btn btn-xs btn-danger"><?php echo PT_CREATE_ORDER; ?></button>
                  </form>
                <?php } ?>
                <?php if ($paidNoOrder && (int)$row['customers_id'] > 0 && !empty($snapshot['full']['cart'])) { ?>
                  <?php echo zen_draw_form('pt_basket_' . (int)$row['id'], FILENAME_PAYTRAIL_PAYMENTS, $selfParams . '&action=restore_basket', 'post', 'style="display:inline" onsubmit="return confirm(\'' . addslashes(PT_BASKET_CONFIRM) . '\');"'); ?>
                    <?php echo zen_draw_hidden_field('tx', $row['transaction_id']); ?>
                    <button type="submit" class="btn btn-xs btn-primary"><?php echo PT_BASKET_BUTTON; ?></button>
                  </form>
                <?php } ?>
                <?php if (is_array($snapshot) && !empty($snapshot['products'])) { ?>
                  <a class="btn btn-xs btn-info" data-toggle="collapse" href="#pt-cart-<?php echo (int)$row['id']; ?>"><?php echo PT_DETAILS; ?></a>
                <?php } ?>
              </td>
            </tr>
            <?php if (is_array($snapshot) && !empty($snapshot['products'])) { ?>
            <tr class="collapse" id="pt-cart-<?php echo (int)$row['id']; ?>">
              <td colspan="7" class="dataTableContent" style="background:#f9f9f9">
                <?php foreach ($snapshot['products'] as $product) {
                    echo zen_output_string_protected($product['qty'] . ' x ' . $product['name'] . ' - ' . number_format((float)$product['price'], 2, ',', ' ') . ' EUR' . (!empty($product['attributes']) ? ' (' . implode(', ', $product['attributes']) . ')' : '')) . '<br>';
                } ?>
                <?php if (!empty($snapshot['shipping'])) echo '<small>' . zen_output_string_protected($snapshot['shipping']) . '</small><br>'; ?>
                <?php if (!empty($snapshot['delivery'])) echo '<small>' . zen_output_string_protected($snapshot['delivery']) . '</small><br>'; ?>
                <?php if (!empty($snapshot['comments'])) echo '<small><i>' . nl2br(zen_output_string_protected($snapshot['comments'])) . '</i></small>'; ?>
              </td>
            </tr>
            <?php } ?>
          <?php } ?>
          </tbody>
        </table>
      <?php } ?>
    </div>
    <?php require DIR_WS_INCLUDES . 'footer.php'; ?>
  </body>
</html>
<?php require DIR_WS_INCLUDES . 'application_bottom.php'; ?>
