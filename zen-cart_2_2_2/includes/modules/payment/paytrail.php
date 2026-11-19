<?php

/**
 * www.paytrail.com (Finland)
 * REQUIRES PHP version >= 8.4
 * @copyright Copyright 2003-2025 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license   http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: piloujp 2025 Oct 10 Modified in v2.2.2 $
 * @version $Id: Nida Verkkopalvelu (www.nida.fi) / krbuk 2026 Nov 20 Modified in v5.8.1 (card tokenization, payments table, 30s status check)
 */

// Paytrail module use vendors
require DIR_FS_CATALOG .DIR_WS_CLASSES .'vendors/paytrail/autoload.php';

/**
 * @since ZC v1.0.3
 */
class paytrail extends base
{
        /**
         * $_check is used to check the configuration key set up
         *
         * @var int
         */
        protected $_check;
        /**
         * $code determines the internal 'code' name used to designate "this" payment module
         *
         * @var string
         */
        public $code;
        /**
         * $description is a soft name for this payment method
         *
         * @var string
         */
        public $description;
        /**
         * $email_footer is the text to me placed in the footer of the email
         *
         * @var string
         */
        public $email_footer;
        /**
         * $enabled determines whether this module shows or not... during checkout.
         *
         * @var boolean
         */
        public $enabled;
        /**
         * $order_status is the order status to set after processing the payment
         *
         * @var int
         */
        public $order_status;
        /**
         * $title is the displayed name for this order total method
         *
         * @var string
         */
        public $title;
        /**
         * $sort_order is the order priority of this payment module when displayed
         *
         * @var int
         */
        public $sort_order;
        /**
         * Aggregate merchant ID Paytrail account ID
         * @var string
         */
        protected $merchantId;
        /**
         * Aggregate secret key Paytrail Secret key
         * @var string
         */
        protected $secretKey;
        /**
         * The Shop-in-Shop merchant ID
         */
        public $shop_in_shop_merchant_id;
        /**
         * The URL to process the payment for local processing payment successful
         */
        public $success_address;
        /**
         * The URL to process the payment for local processing payment cancel
         */
        public $cancel_address;
        /**
         * Card addition form language, currently supported are FI, SV, and EN
         * alpha2
         */
        public $language;
        /**
         * order number
         * numeric
         */
        public $order_number;
        /**
         * Payment amount in currency minor unit, e.g. cents. Maximum value of 99999999
         * numeric
         */ 
        public $amount;
        /**
         * Http client timeout seconds
         */
        public $timeout = 1800;    
        /**
         * $form_action_url is the URL to process the payment or not set for local processing
         * @var string
         */
        public $form_action_url; 
        /**
         * Assigned transaction ID for the payment
         * @var string
         */
        public $transactionId;
        /**
         *  For API module version
         */
        public $moduleVersion = '5.8.1';      
        /**
         * $allowed_currencies is the valid Paytrail currency to use default EUR
         * @var string
         */
        private $allowed_currencies = ['EUR'];
        /**
         * Currency
         * @var alpha3
         */
        protected $mycurrency;
        /**
         * Platform name for the API.
         * @var string
         */
        protected $platformName = 'zencart-2.2.2 - Nida Verkkopalvelu Oy';   
        /**
         * Paytrail API base URL
         */
        protected $apiBase = 'https://services.paytrail.com';
        /**
         * Verified payment result (filled in before_process, used in after_process)
         * @var array
         */
        protected $paymentResult = [];
        /**
         * Saved (tokenized) cards table
         */
        protected $cardsTable;
        /**
         * Payment status wait time in seconds (status checked every 10 seconds)
         */
        public $statusWaitSeconds = 30;

// class constructor
        function __construct()
        {
            global $order;

            $this->code = 'paytrail';
            $this->title = MODULE_PAYMENT_PAYTRAIL_TEXT_TITLE;
            $this->description ='<strong>Paytrail version -v' . $this->moduleVersion . '</strong><br><br>' .MODULE_PAYMENT_PAYTRAIL_TEXT_DESCRIPTION;
            $this->sort_order = defined('MODULE_PAYMENT_PAYTRAIL_SORT_ORDER') ? MODULE_PAYMENT_PAYTRAIL_SORT_ORDER : null;
            $this->enabled = (defined('MODULE_PAYMENT_PAYTRAIL_STATUS') && MODULE_PAYMENT_PAYTRAIL_STATUS == 'true');
            $this->form_action_url = 'https://services.paytrail.com/payments';
            $this->merchantId = defined('MODULE_PAYMENT_PAYTRAIL_KAUPPIAS') ? MODULE_PAYMENT_PAYTRAIL_KAUPPIAS : null;
            $this->secretKey = defined('MODULE_PAYMENT_PAYTRAIL_TURVA_AVAIN') ? MODULE_PAYMENT_PAYTRAIL_TURVA_AVAIN : null;			
            $this->shop_in_shop_merchant_id = defined('MODULE_PAYMENT_PAYTRAIL_PAAMYYJA') ? MODULE_PAYMENT_PAYTRAIL_PAAMYYJA : null;
            $this->language = (($_SESSION['languages_code'] ?? '') == 'fi') ? 'FI' : 'EN';          
            $this->success_address = zen_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL');
            $this->cancel_address = zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL');
            $this->cardsTable = DB_PREFIX . 'paytrail_cards';

            // Saved card payment: the confirmation form is posted to checkout_process,
            // the token charge is made in before_process() only after the customer confirms.
            if (IS_ADMIN_FLAG !== true && !empty($_SESSION['paytrail_card_id']) && $this->cardSavingEnabled()) {
                $this->form_action_url = zen_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL');
            }

            if (null === $this->sort_order) {
                return false;
            }

            if (IS_ADMIN_FLAG === true && (
                (defined('MODULE_PAYMENT_PAYTRAIL_KAUPPIAS') && MODULE_PAYMENT_PAYTRAIL_KAUPPIAS == '375917') || 
                (defined('MODULE_PAYMENT_PAYTRAIL_KAUPPIAS')  && MODULE_PAYMENT_PAYTRAIL_KAUPPIAS == '695861') || 
                (defined('MODULE_PAYMENT_PAYTRAIL_PAAMYYJA')  && MODULE_PAYMENT_PAYTRAIL_PAAMYYJA == '695874')))  {
                $this->title .= '<span class="alert">' .MODULE_PAYMENT_PAYTRAIL_ALERT_TEST .'</span>';
            }

            if ((int)MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID > 0) {
                $this->order_status = MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID;
            }

            if (is_object($order)) {
                $this->update_status();
            }

        }

// class methods
        /**
         * @since ZC v1.0.3
         */
        function update_status()
        {
          
            global $db, $order, $currencies, $currency;

            if ($this->enabled && (int)MODULE_PAYMENT_PAYTRAIL_ZONE > 0 && isset($order->billing['country']['id'])) {
                $check_flag = false;
                $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_PAYTRAIL_ZONE . "' and zone_country_id = '" . (int)$order->billing['country']['id'] . "' order by zone_id");
                while (!$check->EOF) {
                    if ($check->fields['zone_id'] < 1) {
                        $check_flag = true;
                        break;
                    } elseif ($check->fields['zone_id'] == $order->billing['zone_id']) {
                        $check_flag = true;
                        break;
                    }
                    $check->MoveNext();
                }

                if ($check_flag == false) {
                    $this->enabled = false;
                }
            }
          
            // Disable the module if the order currency is not supported.
            // Only EUR orders are accepted.
            if ($this->enabled) {
                $mycurrency = $order->info['currency'];
                if (!in_array($mycurrency, $this->allowed_currencies, true)) {
                  $this->enabled = false;
                }
            }          

            // other status checks?
            if ($this->enabled) {
                // other checks here
            }
          
        }

        /**
         * @since ZC v1.0.3
         */
        function javascript_validation()
        {
            return false;
        }

        /**
         * @since ZC v1.0.3
         */
        function selection()
        {
            if(isset($_GET['checkout-status'])){
                $status=$_GET['checkout-status'];

                // fail = customer pressed cancel / back on the Paytrail page (or the payment
                // was declined): if Paytrail also says fail -> no waiting, back to the
                // payment selection right away.
                if ($status === 'fail') {
                    // One quick server side check first: with OP (mobile app approval) the
                    // customer may come back via cancel/back while the payment is still being
                    // completed or is already paid.
                    static $failChecked = null;
                    $txId = (string)($_GET['checkout-transaction-id'] ?? '');
                    if ($failChecked === null) {
                        $failChecked = 'fail';
                        if ($this->isOwnTransaction($txId)) {
                            $failChecked = $this->recheckPayment($txId) ?: 'unknown';
                        }
                    }
                    if (in_array($failChecked, ['ok', 'pending', 'delayed'], true)) {
                        // Paid after all -> create the order (verified again in before_process)
                        zen_redirect(zen_href_link(FILENAME_CHECKOUT_PROCESS, 'paytrail_tx=' . urlencode($txId), 'SSL', true, false));
                    }
                    if (in_array($failChecked, ['new', 'unknown'], true)) {
                        // Not finished at Paytrail yet (or API not answering): wait max 30s
                        $this->waitPage();
                    }
                    global $messageStack;
                    static $failShown = false;   // selection() is called twice on checkout_payment
                    if (!$failShown && is_object($messageStack)) {
                        $messageStack->add('checkout_payment', MODULE_PAYMENT_PAYTRAIL_PAYMENT_CANCELLED_SELECT_OTHER, 'caution');
                        $failShown = true;
                    }
                }
                // Not final yet: waiting page (status checked every 10s, max 30s)
                elseif(in_array($status,[
                    'new',
                    'pending',
                    'delayed',
                    'authorization-hold'
                ], true)){
                    $this->waitPage();
                }
            }

            // Back on the payment page: payment of this session paid after all? -> create the order
            if (!isset($_GET['checkout-status'])) {
                $this->recoverPaidPayment();
            }

            $selection = [
                'id'=>$this->code,
                'module'=>$this->title
            ];

            // Saved cards / save a new card (only for logged in customers, not guest checkout)
            $customerId = $this->getLoggedInCustomerId();
            if ($customerId > 0 && $this->cardSavingEnabled()) {
                $current = (string)($_SESSION['paytrail_card_id'] ?? (!empty($_SESSION['paytrail_save_card']) ? 'new' : ''));
                $fields  = [];

                // <select> works with every template (some templates hide radio buttons in .ccinfo).
                // Choosing a card also selects the Paytrail payment method radio.
                $onchange = "var r=document.getElementById('pmt-" . $this->code . "'); if(r){r.checked=true; if(typeof r.onclick==='function'){r.onclick();}}";
                $html  = '<select name="paytrail_card" id="paytrail-card-select" class="form-control form-select" style="max-width:420px; display:inline-block; width:auto;" onchange="' . $onchange . '">';
                $html .= '<option value=""' . ($current === '' ? ' selected' : '') . '>' . MODULE_PAYMENT_PAYTRAIL_CARD_OTHER_METHOD . '</option>';
                foreach ($this->getCustomerCards($customerId) as $card) {
                    $selected = ($current === (string)$card['id']) ? ' selected' : '';
                    $html .= '<option value="' . (int)$card['id'] . '"' . $selected . '>' . MODULE_PAYMENT_PAYTRAIL_CARD_PAY_WITH . ': ' . $this->cardLabel($card) . '</option>';
                }
                $html .= '<option value="new"' . ($current === 'new' ? ' selected' : '') . '>' . MODULE_PAYMENT_PAYTRAIL_CARD_SAVE_NEW . '</option>';
                $html .= '</select>';
                $html .= ' <small><a href="' . zen_href_link('paytrail_cards', '', 'SSL') . '">' . MODULE_PAYMENT_PAYTRAIL_CARD_MANAGE . '</a></small>';

                $fields[] = ['title' => MODULE_PAYMENT_PAYTRAIL_CARD_CHOOSE, 'field' => $html, 'tag' => 'paytrail-card-select'];
                $selection['fields'] = $fields;
            }

            return $selection;
        }

        /**
         * Store the saved card / save card choice in session.
         * @since ZC v1.0.3
         */
        function pre_confirmation_check()
        {
            unset($_SESSION['paytrail_card_id'], $_SESSION['paytrail_save_card'], $_SESSION['paytrail_token_stamp']);
            $this->form_action_url = $this->apiBase . '/payments';

            $customerId = $this->getLoggedInCustomerId();
            if ($customerId < 1 || !$this->cardSavingEnabled() || !isset($_POST['paytrail_card'])) {
                return false;
            }

            $choice = trim((string)$_POST['paytrail_card']);

            if ($choice === 'new') {
                $_SESSION['paytrail_save_card'] = true;
            } elseif (ctype_digit($choice) && $this->getCustomerCard($customerId, (int)$choice) !== null) {
                $_SESSION['paytrail_card_id'] = (int)$choice;
                // One stamp per confirmation: Paytrail rejects a duplicate stamp, so a
                // double submit can not charge the card twice.
                $_SESSION['paytrail_token_stamp'] = $this->generate_uuid();
                $this->form_action_url = zen_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL');
            }

            return false;
        }

        /**
         * @since ZC v1.0.3
         */
        function confirmation()
        {
            $confirmation = ['title' => MODULE_PAYMENT_PAYTRAIL_TEXT_CHECKOUT];

            $customerId = $this->getLoggedInCustomerId();
            if (!empty($_SESSION['paytrail_card_id']) && $customerId > 0) {
                $card = $this->getCustomerCard($customerId, (int)$_SESSION['paytrail_card_id']);
                if ($card !== null) {
                    $confirmation['fields'] = [[
                        'title' => MODULE_PAYMENT_PAYTRAIL_CARD_PAY_WITH,
                        'field' => $this->cardLabel($card),
                    ]];
                }
            } elseif (!empty($_SESSION['paytrail_save_card'])) {
                $confirmation['fields'] = [[
                    'title' => '',
                    'field' => MODULE_PAYMENT_PAYTRAIL_CARD_SAVE_NEW_INFO,
                ]];
            }

            return $confirmation;
        }

        /**
         * @since ZC v1.0.3
         */
        function process_button()
        {
          global $db, $order, $currencies, $currency, $order_totals;
          // ********************************
          // ***       Paytrail           ***
          // ********************************
          $this->prepareOrderData();

          // ------------------------------------------------------------
          // Saved card: nothing is created here. The confirmation form posts
          // to checkout_process and the token is charged in before_process().
          // ------------------------------------------------------------
          if (!empty($_SESSION['paytrail_card_id']) && $this->getLoggedInCustomerId() > 0) {
              return '<input type="hidden" name="paytrail_token_payment" value="1">';
          }

          // ------------------------------------------------------------
          // Pay and save card: /tokenization/pay-and-add-card
          // ------------------------------------------------------------
          if (!empty($_SESSION['paytrail_save_card']) && $this->getLoggedInCustomerId() > 0) {
              $stamp  = $this->generate_uuid();
              $body   = $this->getBody([
                  'stamp'        => $stamp,
                  'callbackUrls' => $this->getCallbackUrls($this->getLoggedInCustomerId(), $stamp),
              ]);
              $result = $this->apiPost('/tokenization/pay-and-add-card', $body);

              $html = "</form>\n";
              if ($result['ok'] && $result['code'] == 200 && !empty($result['data']->redirectUrl)) {
                  $this->rememberTransaction($result['data']->transactionId ?? '');
                  $this->recordPayment($result['data']->transactionId ?? '', 'pay_add_card');
                  $html .= $this->redirectHtml($result['data']->redirectUrl);
              } else {
                  $html .= $this->apiErrorHtml($result);
              }
              return $html;
          }

          // ------------------------------------------------------------
          // Normal payment: /payments
          // ------------------------------------------------------------
          $body   = $this->getBody();
          $result = $this->apiPost('/payments', $body);

          /**
          ** Error control (develop)
          ** Erase " // " and check the sent request and the Paytrail response.
          **/
          // echo '<br>' .'HTTP status: ' .$result['code'] .' / HMAC ok: ' .($result['ok'] ? 'yes' : 'NO');
          // echo '<br>' .'transactionId: ' .($result['data']->transactionId ?? '-');
          // echo '<br>' .'Request ID: ' .$result['request_id'];
          // echo '<br><pre>' .htmlspecialchars(json_encode(json_decode($result['raw']), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) .'</pre>';
          // echo '<br><pre>'; print_r(json_decode($body,true)); exit;

          if (!$result['ok'] || $result['code'] != 201) {
              return "</form>\n" . $this->apiErrorHtml($result);
          }

          $decodedresponsebody = $result['data'];

          if (isset($decodedresponsebody->transactionId)) {
            $this->transactionId = $decodedresponsebody->transactionId;
            $this->rememberTransaction($this->transactionId);
            $this->recordPayment($this->transactionId, 'payment', (string)($decodedresponsebody->reference ?? ''));
          }
          
          $provider_selection = MODULE_PAYMENT_PAYTRAIL_PAYMENT_PROVIDER;
          // We are closing the current form.
          $html  = "</form>\n";
          // ************************************************************
          // METHOD 1 IMPLEMENTATION (Guidance and Backup Submit Button)
          // ************************************************************          
          if ($provider_selection == '1') {
          if (isset($decodedresponsebody->href) && !empty($decodedresponsebody->href)) {
              $html .= $this->redirectHtml($decodedresponsebody->href);
          } else {
              $html .= '<div style="color:red; text-align:center; margin: 20px;">' . MODULE_PAYMENT_PAYTRAIL_TEXT_API_ERROR . ' (' . MODULE_PAYMENT_PAYTRAIL_NO_REDIRECT_URL . ')</div>';
          }
          }
          
          // ************************************************************
          // METHOD 2 IMPLEMENTATION (Guidance all payment button)
          // ************************************************************
          if ($provider_selection == '0') {
          $html .='<style>
                  #provider-group-switcher {
                      width: 100%;
                      height:auto;
                  }
                  .provider-group {
                      float: left;
                      margin-right: 8px;
                      margin-bottom: 8px;
                  }

                  #apple-pay-button {
                      display: none;
                      -webkit-appearance: -apple-pay-button;
                      -apple-pay-button-type: plain;
                      -apple-pay-button-style: white-outline;
                      height: 50px;
                      width: 30%;
                  }

                  .button-content img {
                      width:100px; 
                      height:50px;
                  }
                  #btn_submit, .buttonRow  { display: none; } /*Submit button hidden */
                  .btn-success { display: none; } /*Submit button hidden */
              </style>';
            
          $html .='<script src="https://resources.paytrail.com/libraries/paytrail.js"></script>';
          $html .='<script>
                const applePayButton = checkoutFinland.applePayButton;
                // canMakePayment() checks that the user is on a Safari browser which supports Apple Pay.
                if (applePayButton.canMakePayment()) 
                {
                  // Mount the button to the element you created earlier, here e.g. #apple-pay-button.
                  applePayButton.mount("#apple-pay-button", (redirectUrl) => 
                    {
                      setTimeout(() => {
                      window.location.replace(redirectUrl);
                    }, 1500);
                });
                }		
              </script>';

          // Provider payment group title	
          $group_titles = [
            'mobile'     => 'Mobile payment methods',
            'bank'       => 'Bank payment methods', 
            'creditcard' => 'Card payment methods', 
            'credit'     => 'Invoice and instalment payment methods',
          ];

          $html .= '';
          $html .= '<section class="payment-providers"><h2>' .MODULE_PAYMENT_PAYTRAIL_SELECET_PAYMENT .'</h2>';
          $html .= !empty($decodedresponsebody->terms) ? $decodedresponsebody->terms : '';
          $html .= '<div id="provider-group-switcher"><br>';

          // Apple Pay Button
          if (
            isset($decodedresponsebody->customProviders) &&  // Check if the custom Providers feature exists.
            isset($decodedresponsebody->customProviders->applepay) &&  // Check if Apple Pay is available.
            isset($decodedresponsebody->customProviders->applepay->parameters) &&  // Check the existence of the parameters property.
            !empty($decodedresponsebody->customProviders->applepay->parameters)
            ):

            $html .= '<div id="apple-pay-button">';

            foreach ($decodedresponsebody->customProviders->applepay->parameters as $parameter):
              $html .= '<input type="hidden" name="'.$parameter->name .'" value="'.$parameter->value .'">';
            endforeach;

            $html .= '</div>';
          endif;

          // Provider payment group name, icon and id 
          if (!empty($decodedresponsebody->groups) && is_iterable($decodedresponsebody->groups)) {
            foreach ($decodedresponsebody->groups as $group) {
              $groups[] = [
                'id'  => $group->id,
                'name'=> $group->name,
                'icon'=> $group->icon,
                'svg' => $group->svg
              ];

              $html .= '<div style="clear: both"></div>';	
              $html .= '<img src="'.$group->icon .'" alt="'.$group->name .'" title="'.$group->name.'" style="width:24px; float:left; margin-bottom: 10px">' .'<h3 class="provider-group-header">&nbsp;' .$group->name .'</h3>';

              // Provider name 
              foreach ($decodedresponsebody->providers  as $providersmethods) {
                $allPovidersMethods[] = [
                  'url'         =>  $providersmethods->url,
                  'icon'        =>  $providersmethods->icon,
                  'svg'         =>  $providersmethods->svg,
                  'name'        =>  $providersmethods->name,
                  'group'       =>  $providersmethods->group,
                  'id'          =>  $providersmethods->id,
                  'parameters'  =>  $providersmethods->parameters
                ];

                // Selected provider id and group 
                if ($group->id == $providersmethods->group) {
                  $html .= '<div class="provider-group">';
                  $html .= '<form action="' .$providersmethods->url .'" method="POST">';

                  // Provider form filesds
                  foreach ($providersmethods->parameters as $parameter) {
                    $formFields[] = [
                      'name'  =>  $parameter->name,
                      'value' =>  $parameter->value,			
                    ];

                    $html .= '<input type="hidden" name="'.$parameter->name .'" value="'.$parameter->value .'">';
                  } // end provider form filesds	

                  $html .= '<button class="provider-button">';
                  $html .= '<div class="button-content"><img src="'.$providersmethods->icon .'" alt="'.$providersmethods->id .'" title="'.$providersmethods->id .'"></div>';
                  $html .= '</button>';
                  $html .= '</form>';
                  $html .= '</div>';
                } // end  selected provider id and group 
              } // end provider name 
            } // end provider-group-header	
          } else {
            // Take action or log in case of error
            echo '<div style="color:red">'. MODULE_PAYMENT_PAYTRAIL_TEXT_API_ERROR .'</div>';
            error_log('Invalid or empty group data was received.');
            }

          $html .= '</div>
          </section>';
          }
          $html .= '<div style="clear: both"></div>';
          return $html;          
        }

        /**
         * @since ZC v1.0.3
         */
        function before_process()
        {
            global $messageStack;

            $this->paymentResult = [];
            $this->prepareOrderData();

            // 1) Saved card (token) payment posted from the confirmation page
            if (empty($_GET['checkout-status']) && empty($_GET['paytrail_tx']) && !empty($_SESSION['paytrail_card_id'])) {
                $this->chargeSavedCard();
                return;
            }

            // 2) Return from the waiting page: verify server side with the Paytrail API
            if (empty($_GET['checkout-status']) && !empty($_GET['paytrail_tx'])) {
                $transactionId = (string)$_GET['paytrail_tx'];
                if (!$this->isOwnTransaction($transactionId)) {
                    $this->failToPaymentPage(MODULE_PAYMENT_PAYTRAIL_PAYMENT_VERIFY_ERROR);
                }
                $status = $this->getPaymentStatus($transactionId);
                $apiStatus = is_array($status) ? (string)($status['status'] ?? '') : '';
                if ($apiStatus !== '') {
                    $this->setPaymentStatus($transactionId, $apiStatus, (string)($status['provider'] ?? ''));
                }
                if (!in_array($apiStatus, ['ok', 'pending', 'delayed'], true)) {
                    $this->failToPaymentPage(MODULE_PAYMENT_PAYTRAIL_PAYMENT_ERROR);
                }
                $this->setPendingOrderStatus($apiStatus);
                $this->paymentResult = [
                    'status'        => $apiStatus,
                    'provider'      => $status['provider'] ?? '',
                    'reference'     => $status['reference'] ?? '',
                    'transactionId' => $transactionId,
                    'amount'        => (int)($status['amount'] ?? 0),
                ];
                return;
            }

            // 3) Redirect back from Paytrail (payment page, 3DS, pay-and-add-card)
            if (empty($_GET['checkout-status'])) {
                zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
            }

            // The signature of the return URL MUST be checked, otherwise anybody could
            // open checkout_process?checkout-status=ok and create a paid order.
            if (!$this->validateRedirectSignature($_GET)) {
                error_log('Paytrail: return URL signature mismatch');
                $this->failToPaymentPage(MODULE_PAYMENT_PAYTRAIL_PAYMENT_VERIFY_ERROR);
            }

            $transactionId = (string)($_GET['checkout-transaction-id'] ?? '');
            if (!$this->isOwnTransaction($transactionId)) {
                error_log('Paytrail: unknown transaction id on return ' . $transactionId);
                $this->failToPaymentPage(MODULE_PAYMENT_PAYTRAIL_PAYMENT_VERIFY_ERROR);
            }

            $status = (string)$_GET['checkout-status'];

            // pending = approved by the provider, further processing needed
            //   (invoice waiting for manual activation, Walley B2B anti-fraud check)
            // delayed = can be handled the same way as pending (Paytrail docs)
            // -> the order is created with the "pending" order status, the final
            //    result comes later to the callback URL or with admin activation.
            if (in_array($status, ['pending', 'delayed'], true)) {
                $this->setPendingOrderStatus($status);
                $this->paymentResult = [
                    'status'        => $status,
                    'provider'      => (string)($_GET['checkout-provider'] ?? ''),
                    'reference'     => (string)($_GET['checkout-reference'] ?? ''),
                    'transactionId' => $transactionId,
                    'amount'        => (int)($_GET['checkout-amount'] ?? 0),
                ];
                return;
            }

            if (in_array($status, ['new', 'authorization-hold'], true)) {
                // Not final yet: show the waiting page (checks the status every 10s for 30s)
                zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'checkout-status=' . urlencode($status) . '&checkout-transaction-id=' . urlencode($transactionId), 'SSL', true, false));
            }

            if ($status !== 'ok') {
                $this->setPaymentStatus($transactionId, $status, (string)($_GET['checkout-provider'] ?? ''));
                $this->failToPaymentPage(MODULE_PAYMENT_PAYTRAIL_PAYMENT_ERROR);
            }

            $this->paymentResult = [
                'status'        => 'ok',
                'provider'      => (string)($_GET['checkout-provider'] ?? ''),
                'reference'     => (string)($_GET['checkout-reference'] ?? ''),
                'transactionId' => $transactionId,
                'amount'        => (int)($_GET['checkout-amount'] ?? 0),
            ];
        }

        /**
         * @since ZC v1.0.3
         */
        function after_process()
        {
            // Adding the instructions to the Order Status History, will be visible but will not generate a new email.
            global $insert_id, $db, $order, $messageStack;
            
            // It sends blank information to the order details.
            $commentString = '';
            zen_update_orders_history($insert_id, $commentString, "System", -1, 0);

            $resultStatus = $this->paymentResult['status'] ?? '';
            if (in_array($resultStatus, ['ok', 'pending', 'delayed'], true)) {
              $provider = $this->paymentResult['provider'];
              if (!empty($this->paymentResult['savedCard'])) {
                  $provider .= ' (' . MODULE_PAYMENT_PAYTRAIL_CARD_SAVED_CARD . ' ' . $this->paymentResult['savedCard'] . ')';
              }
              // Update order history
              $statusText = ($resultStatus === 'ok') ? MODULE_PAYMENT_PAYTRAIL_PAYMENT_OK : MODULE_PAYMENT_PAYTRAIL_PAYMENT_WAITING_ACTIVATION;
              $viite = $this->paytrailReference($this->getPayment((string)$this->paymentResult['transactionId']), (string)$this->paymentResult['reference']);
              $commentString = zen_db_prepare_input(MODULE_PAYMENT_PAYTRAIL_TITLE_STATUS . $statusText . MODULE_PAYMENT_PAYTRAIL_PAYMENT_METHOD . $provider . " , " . MODULE_PAYMENT_PAYTRAIL_REFERENCE_NUMBER . $viite . ".");

              // Paid amount differs from the order total (cart changed in another tab etc.)
              if (!empty($this->paymentResult['amount']) && (int)$this->paymentResult['amount'] !== (int)$this->amount) {
                  $commentString .= "\n" . sprintf(MODULE_PAYMENT_PAYTRAIL_AMOUNT_MISMATCH, number_format($this->paymentResult['amount'] / 100, 2, ',', ''), number_format($this->amount / 100, 2, ',', ''));
              }
              if ($this->isEmpAdminSession() && !in_array($this->paymentResult['transactionId'], $_SESSION['paytrail_transactions'] ?? [], true)) {
                  $commentString .= "\n" . MODULE_PAYMENT_PAYTRAIL_EMP_ORDER_NOTE;
              }
              // Writing payment information to order status history	
              zen_update_orders_history($insert_id, $commentString, null, $order->info['order_status'], 3);	

              // Order <-> Paytrail transaction (callback updates, admin activation)
              $this->setPaymentStatus($this->paymentResult['transactionId'], $resultStatus, (string)$this->paymentResult['provider'], (int)$insert_id);

              // Paytrail payments table: viite exactly as Paytrail returned it (checkout-reference)
              if (!empty($this->paymentResult['reference'])) {
                  $sql = "UPDATE " . $this->paymentsTable() . " SET reference = :ref WHERE transaction_id = :tx";
                  $sql = $db->bindVars($sql, ':ref', substr((string)$this->paymentResult['reference'], 0, 32), 'string');
                  $sql = $db->bindVars($sql, ':tx', (string)$this->paymentResult['transactionId'], 'string');
                  $db->Execute($sql);
              }
            }

            // Clean up the session
            unset($_SESSION['paytrail_card_id'], $_SESSION['paytrail_save_card'], $_SESSION['paytrail_token_stamp'], $_SESSION['paytrail_transactions']);
          
            return false;
        }

        /**
         * @since ZC v1.0.3
         */
        function get_error()
        {
            return false;
        }

        /**
         * @since ZC v1.0.3
         */
        function check()
        {
            global $db;
            if (!isset($this->_check)) {
                $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_PAYTRAIL_STATUS'");
                $this->_check = $check_query->RecordCount();
            }

            return $this->_check;
        }

        /**
         * @since ZC v1.0.3
         */
        function install()
        {
            global $db, $messageStack;
            if (defined('MODULE_PAYMENT_PAYTRAIL_STATUS')) {
                  $messageStack->add_session(sprintf(TEXT_ERROR_MODULE_ALREADY_INSTALLED, $this->title), 'error');
                  zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=paytrail', 'NONSSL'));
                  return 'failed';
            }
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Lajittelujärjestys', 'MODULE_PAYMENT_PAYTRAIL_SORT_ORDER', '0', 'Maksutavan lajittelujärjestys. Pienimmän luvun omaava on ylimpänä.', '6', '1', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Ota käyttöön Paytrail', 'MODULE_PAYMENT_PAYTRAIL_STATUS', 'true', 'Otetaanko maksumoduuli käyttöön?', '6', '2', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Kauppiastunnus', 'MODULE_PAYMENT_PAYTRAIL_KAUPPIAS', '375917', 'TEST kauppiastunnus: 375917 <br> Shop-in-Shop kauppiastunnus: 695861', '6', '3', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Turva-avain', 'MODULE_PAYMENT_PAYTRAIL_TURVA_AVAIN', 'SAIPPUAKAUPPIAS', 'Normal merchant test turva-avain: SAIPPUAKAUPPIAS <br> Shop-in-Shop merchant test turva-avain: MONISAIPPUAKAUPPIAS', '6', '4', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Myyjältä Myyjä kauppiastunnus', 'MODULE_PAYMENT_PAYTRAIL_PAAMYYJA', '', 'Test myyjältä myyjä kauppiastunnus: 695874', '6', '7', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Maksupalveluntarjoajan valinta käyttöö', 'MODULE_PAYMENT_PAYTRAIL_PAYMENT_PROVIDER', '0', 'Ota maksupalveluntarjoajan valinta käyttöön omalla sivulla tai suora paytrailin sivulla?<br> 0 = omallla sivulla. <br>1 = suora mene paytrailin sivulla', '6', '8', 'zen_cfg_select_option(array(\'0\',\'1\'), ', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Ota OP Lasku -laskin käyttöön', 'MODULE_PAYMENT_PAYTRAIL_PAYMENT_OP_INVOICE', 'True', 'Näytä OP Lasku -laskuri tuote- ja ostoskorisivulla.', '6', '9', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())"); 
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Ota käyttöön laskun manuaalinen aktivointi', 'MODULE_PAYMENT_PAYTRAIL_PAYMENT_INVOICE_ACTIVATION', 'True', 'Tietyillä laskun maksutavoilla (Walley/Klarna) voit aktivoida laskun myöhemmin, esimerkiksi ennakkoon tilatuille tuotteille.<br>Aktivointiaika <br>Walley: Jopa 90 päivää <br>Klarna: Jopa 28 päivää', '6', '10', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");           
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Ota käyttöön yksittäiset selvitykset', 'MODULE_PAYMENT_PAYTRAIL_PAYMENT_INDIVIDUAL_SETTLEMENTS', 'True', 'Tämä asetus on pakollinen vain, jos käytät Paytrailissa tapahtumakohtaista selvitystä.', '6', '11', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");             
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Pankin viitenumeron etuliite', 'MODULE_PAYMENT_PAYTRAIL_BANK_REFERENCE_PREFIX', '10', 'Oletus 10', '6', '12', now())");          
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Maksumoduulin voimassaoloalue', 'MODULE_PAYMENT_PAYTRAIL_ZONE', '0', 'Jos alue on valittu, käytä tätä maksutapaa vain valitun alueen ostotapahtumille..', '6', '13', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Tilauksen tila suoritetun maksun jälkeen', 'MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID', '2', 'Tilauksen tila maksun suorittamisen jälkeen:', '6', '14', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Korttien tallennus (tokenisointi)', 'MODULE_PAYMENT_PAYTRAIL_SAVE_CARD', 'False', 'Kirjautuneet asiakkaat voivat tallentaa maksukortin ja maksaa tallennetulla kortilla (Paytrail Token payments).<br>Ominaisuus täytyy olla aktivoitu Paytrail-sopimuksessa.', '6', '15', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Tilauksen tila: odottaa aktivointia', 'MODULE_PAYMENT_PAYTRAIL_PENDING_STATUS_ID', '1', 'Tilauksen tila, kun maksu on <b>pending</b> (lasku odottaa manuaalista aktivointia, Walley B2B -tarkistus).<br>Kun lasku aktivoidaan (Paytrail-kauppiaspaneeli tai tilauksen sivulla) tila vaihtuu maksetuksi.', '6', '16', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
            $this->ensureCardTable();
            // Admin: Customers -> Paytrail Payments
            if (function_exists('zen_register_admin_page') && !zen_page_key_exists('paytrailPayments')) {
                zen_register_admin_page('paytrailPayments', 'BOX_PAYTRAIL_PAYMENTS', 'FILENAME_PAYTRAIL_PAYMENTS', '', 'customers', 'Y');
            }

        }

        /**
         * @since ZC v1.0.3
         */
        function remove()
        {
            global $db;
            $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
            // Payment / card tables are kept (history). Admin menu entry is removed.
            if (defined('TABLE_ADMIN_PAGES')) {
                $db->Execute("delete from " . TABLE_ADMIN_PAGES . " where page_key = 'paytrailPayments'");
            }
        }

        /**
         * @since ZC v1.0.3
         */
        function keys()
        {
            return [
              'MODULE_PAYMENT_PAYTRAIL_SORT_ORDER', 
              'MODULE_PAYMENT_PAYTRAIL_STATUS', 
              'MODULE_PAYMENT_PAYTRAIL_KAUPPIAS', 
              'MODULE_PAYMENT_PAYTRAIL_TURVA_AVAIN', 
              'MODULE_PAYMENT_PAYTRAIL_PAAMYYJA',
              'MODULE_PAYMENT_PAYTRAIL_PAYMENT_PROVIDER',
              'MODULE_PAYMENT_PAYTRAIL_PAYMENT_OP_INVOICE',
              'MODULE_PAYMENT_PAYTRAIL_PAYMENT_INVOICE_ACTIVATION',
              'MODULE_PAYMENT_PAYTRAIL_PAYMENT_INDIVIDUAL_SETTLEMENTS',
              'MODULE_PAYMENT_PAYTRAIL_BANK_REFERENCE_PREFIX',
              'MODULE_PAYMENT_PAYTRAIL_ZONE', 
              'MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID',
              'MODULE_PAYMENT_PAYTRAIL_SAVE_CARD',
              'MODULE_PAYMENT_PAYTRAIL_PENDING_STATUS_ID',
             ];
        }
  
        /**   
        * ********************************  
        *       Paytrail  Get Body
		* @since Module  v5.6.0 
        * ********************************	
        */ 
        public function getBody(array $extra = []) 
        {

          global $order, $currencies, $db, $order_totals;

          if (!isset($order->delivery['country']['iso_code_2'])) {
            $order->delivery['street_address'] = $order->billing['street_address'];
            $order->delivery['postcode'] = $order->billing['postcode'];
            $order->delivery['city'] = $order->delivery['city'];
            $order->delivery['state'] = $order->delivery['state'];
            $order->delivery['country']['iso_code_2'] = $order->billing['country']['iso_code_2'];
          }

        // deliveryAddress If delivery state is null or emty
        $deliverystate = isset($order->delivery['state']) ? $order->delivery['state'] : '';
        $delivery_state = substr($deliverystate, 0, 10);

        // invoicingAddress If delivery state is null or emty
        $billingstate = isset($order->billing['state']) ? $order->billing['state'] : '';
        $billing_state = substr($billingstate, 0, 10);
        $mycurrency = $order->info['currency'];

        $body_request_data = [
          'stamp' 	  => $this->generate_uuid(),
          'reference' => $this->order_number,
          'amount'    => $this->amount,
          'currency'  => $mycurrency,
          'language'  => $this->language,
          'items'     => $this->getOrderItems($order),
          'customer' => [
            'firstName'  => $order->customer['firstname'],
            'lastName'   => $order->customer['lastname'],
            'phone'      => $order->customer['telephone'],
            'email'      => $order->customer['email_address'],
            'vatId'      => '',
            'companyName'=> $order->customer['company'],
            ],
          'deliveryAddress' =>  [
            'streetAddress' =>  trim(substr($order->delivery['street_address'],0,50)),
            'postalCode' 	=>  trim(substr($order->delivery['postcode'],0,5)),
            'city' 			=> trim(substr($order->delivery['city'],0,18)),
            'county' 		=> $delivery_state,
            'country' 		=> $order->delivery['country']['iso_code_2'],					
            ],
          'invoicingAddress' =>  [
            'streetAddress' => trim(substr($order->billing['street_address'],0,50)),
            'postalCode' 	=> trim(substr($order->billing['postcode'],0,5)),
            'city' 			=> trim(substr($order->billing['city'],0,18)),
            'county' 		=> $billing_state,
            'country' 		=> $order->billing['country']['iso_code_2'],					
            ],
          'manualInvoiceActivation' => (defined('MODULE_PAYMENT_PAYTRAIL_PAYMENT_INVOICE_ACTIVATION') && MODULE_PAYMENT_PAYTRAIL_PAYMENT_INVOICE_ACTIVATION == 'True'),
          'redirectUrls' => [
            'success' => $this->success_address,
            'cancel' => $this->cancel_address,
            ],
          'callbackUrls' => $this->getCallbackUrls(),
          // 120s: normally the customer is back and the order exists before the callback
          'callbackDelay' => 120,
          ];

          // token, own stamp, callback urls for pay-and-add-card etc.
          // Shop-in-Shop: Paytrail rejects the whole request if manualInvoiceActivation is sent
          // ("instance is not allowed to have the additional property manualInvoiceActivation")
          if (!empty($this->shop_in_shop_merchant_id)) {
            unset($body_request_data['manualInvoiceActivation']);
          }

          foreach ($extra as $key => $value) {
            $body_request_data[$key] = $value;
          }
          $this->lastStamp = (string)$body_request_data['stamp'];
          
          // The json_encoding process in your current code continues from here:
          $body = json_encode($body_request_data, JSON_UNESCAPED_SLASHES);

          // Testing and showing order items (develop): erase " // " to see the request sent to Paytrail
          //echo '<pre>'; print_r(json_decode($body,true)); exit;

          return $body;
        }

        // Get Headers
        protected function getHeaders(string $method, $transactionId = null, $tokenizationId = null) 
        {
          //$datetime = new \DateTime();
          $headers = ['checkout-account'        => $this->merchantId,
                      'checkout-algorithm'      => 'sha256',
                      'checkout-method'         => strtoupper($method),
                      'checkout-nonce'          => bin2hex(random_bytes(16)),
                      'checkout-timestamp'      => gmdate('Y-m-d\TH:i:s\Z'),
                      'platform-name'           => $this->platformName,
                      'content-type'            => 'application/json; charset=utf-8'
                     ];
          
          // Required only for POST requests.
          if (strtoupper($method) == 'POST') {
              $headers['checkout-reference']      = $this->order_number;
              $headers['checkout-transaction-id'] = $this->transactionId;
          }          
          
          if($method == 'GET' && !empty($transactionId))
          {
              $headers['checkout-transaction-id'] = $transactionId;
          }          
          // If you want to send the transactionId in the GET query
          if (!empty($transactionId)) {
            $headers['checkout-transaction-id'] = $transactionId;
          }
          // Tokenization: get card token
          if (!empty($tokenizationId)) {
            $headers['checkout-tokenization-id'] = $tokenizationId;
          }
          return $headers;
        }	
 
        public function getOrderItems($order) 
        {
          $items = [];	
          // Normal payment item	
          if (empty($this->shop_in_shop_merchant_id)) {	
            foreach ($this->itemArgs($order) as $key => $item) {
              $items[] = [
                'unitPrice'    => intval($item['price']),
                'units'        => $item['qty'],
                'vatPercentage'=> (float)number_format($item['vat'], 1, '.', ''),
                'productCode'  => $item['code'],
                'description'  => $item['title'],
                'category'     => '',
              ];				
            }			  
          }	

          // shop-in-shop payment item request 
          else {
            foreach ($this->itemArgs($order) as $key => $item) {
              $items[] = [
                'unitPrice'    => intval($item['price']),
                'units'        => $item['qty'],
                'vatPercentage'=> (float)number_format($item['vat'], 1, '.', ''),
                'productCode'  => $item['code'],
                'description'  => $item['title'],
                'category'     => '',
                'stamp'        => $this->generate_uuid(),
                'reference'    => $this->order_number,				  
                'merchant'     => $this->shop_in_shop_merchant_id,				  
                'commission'   => [
                  'merchant' => $this->shop_in_shop_merchant_id,
                  'amount'   => intval($item['price'] / 10),
                  ],	
              ];			
            }		
          }		
          return $items;
        }

		// Start itemArgs($order)
        public function itemArgs($order) 
        {
            global $order, $currencies, $db, $order_totals;

            //Add product breakdown
            $decimals = $currencies->get_decimal_places($_SESSION['currency']);
            $order_subtotal  = zen_round($order->info['subtotal'], 2);

            //Variable to compare product calculation to total amount of the order		
            $total_check = 0;
            $itemqyt     = null;

            // Array order items, tax  and price
            $items = array();	

            foreach ($order->products as $key => $item) {
              $item_final_price = number_format($item['final_price'], 2, '.', '')*100;	
              //$item_final_price = $item['final_price'] *100 ;
              $item_tax = $item['tax'];
              $item_price = round($item_final_price * ($item_tax/100+1));		
              $itemqyt += $item['qty'];
              $total_check  +=  $item_price * $item['qty'];
              if ($order_subtotal == 0) {
                $items[] = [
                  'title' => $item['name'],
                  'code' => $item['model'],
                  'category' => '',
                  'qty' => floatval($item['qty']),
                  'price' => 0,
                  'vat' => 0,
                  'discount' => 0,
                  'type' => 1,
                  ];
              }
              else {
                $items[] = [
                  'title' => $item['name'],
                  'code' => $item['model'],
                  'category' => '',
                  'qty' => floatval($item['qty']),
                  'price' => intval($item_price),
                  'vat' => $item_tax,
                  'discount' => 0,
                  'type' => 1,
                  ];
              }
            }

            //Add shipping to product breakdown
            $shipping_price     =  $order->info['shipping_cost'];	

            $shipping_tax_total = $order->info['shipping_tax'];

            if (($shipping_price - $shipping_tax_total) != 0) {
                 $shipping_tax = ($shipping_tax_total / ($shipping_price - $shipping_tax_total)) * 100;
            } else {
				// Handle the case where the denominator is zero
				$shipping_tax = 0; // or another appropriate value or error handling
              }

            if (DISPLAY_PRICE_WITH_TAX == 'true') {
              $shipping_price = $shipping_price;
            } 
            else {
				$shipping_price = $shipping_price + $shipping_tax_total;
            }		

            if($shipping_price > 0 ) {
			$shipping_price = number_format($shipping_price, 2, '.', '')*100;
            $items[] = [
              'title'     => $order->info['shipping_method'],
              'code'      => $order->info['shipping_module_code'].'',
              'qty'       => 1,
              'price'     => $shipping_price,
              'vat'       => $shipping_tax,
              'discount'  => 0,
              'type'      => 2,
              ];	
            $total_check += $shipping_price; 	
            }

            //Add storepickup dicount
            $storepickup_discount = $order->info['shipping_cost'];
            if ($storepickup_discount < 0) {
            $storepickup_discount_price = abs($order->info['shipping_cost']) * 100;
            $items[] = [
              'title'     => $order->info['shipping_method'],
              'code'      => $order->info['shipping_module_code'].'',
              'qty'       => -1,
              'price'     => $storepickup_discount_price,
              'vat'       => 0,
              'discount'  => 0,
              'type'      => 4,
              ];	
            $total_check -= $storepickup_discount_price; 
            }

            // Add loworderfee breakdown
            // Check if there is a group discount enabled
            $discount_amount_shipping = 0; 	
            foreach ($order_totals as $o_total) {
              if(isset($o_total['code']) && $o_total['code'] == 'ot_loworderfee') {
                if(isset($o_total['value']) && $o_total['value'] > 0) {
                  $query = "select * from " . TABLE_CONFIGURATION . " where configuration_key='MODULE_ORDER_TOTAL_LOWORDERFEE_TAX_CLASS'";
                  $loworder_tax = $db->Execute($query);
                  $loworder_tax_rate = zen_get_tax_rate($loworder_tax->fields['configuration_value'], $order->billing['country']['id'], $order->billing['zone_id']);
                  $loworder_price_format = number_format($o_total['value'], 2, '.', '') * 100;
                  if (DISPLAY_PRICE_WITH_TAX == 'true') {
                    $loworderpretax_price = $loworder_price_format;
                    } else {
                        $loworderpretax_price =  ($loworder_price_format / ($loworder_tax_rate/100+1)) * 100;
                      }
                  $items[] = [
                    'title'     => MODULE_PAYMENT_PAYTRAIL_LOWORDER_TEXT,
                    'code'      => '',
                    'qty'       => 1,
                    'price'     => $loworderpretax_price,
                    'vat'       => $loworder_tax_rate,
                    'discount'  => 0,
                    'type'      => 1,
                    ];
                  $total_check += $loworderpretax_price;
                }
              }
              if(isset($o_total['code']) && $o_total['code'] == 'ot_subtotal') {
                if(isset($o_total['value']) && $o_total['value'] > 0) {
                  $order_total_sub_total = $o_total['value'];
                }
              }			

              //Add group discount pricing breakdown
              if($o_total['code'] == 'ot_group_pricing') {
                if($o_total['value'] > 0) {
                  $group_amount_format = number_format($o_total['value'], 2, '.', '') * 100;
                  if (DISPLAY_PRICE_WITH_TAX == 'true') {
                          $group_amount = round(floatval($group_amount_format));
                        }
                        else {
                          $group_amount = round(floatval($group_amount_format + $order_total_sub_total));
                        }				
                  $items[] = [
                    'title'     => MODULE_PAYMENT_PAYTRAIL_GROUP_TEXT,
                    'code'      => '',
                    'qty'       => -1,
                    'price'     => intval($group_amount),
                    'vat'       => 0,
                    'discount'  => 0,
                    'type'      => 4,
                    ];			
                  $total_check -= $group_amount;					
                }
              }
              else if(isset($o_total['code']) && $o_total['code'] == 'ot_shipping') {
                if(isset($o_total['value']) && $o_total['value'] > 0) {
                  $discount_amount_shipping = $o_total['value'];
                } else { 
                    $discount_amount_shipping = 0; 
                  }
              }
              else if(isset($o_total['code']) && $o_total['code'] == 'ot_group_pricing') {
                if(isset($o_total['value']) && $o_total['value'] > 0) {
                    $group_discount_amount = $o_total['value'];
                }
              }
              else if(isset($o_total['code']) && $o_total['code'] == 'ot_coupon') {
                if(isset($o_total['value']) && $o_total['value'] > 0) {
                    $coupon_amount = $o_total['value'];
                }
              }
              else if(isset($o_total['code']) && $o_total['code'] == 'ot_tax') {
                if(isset($o_total['value']) && $o_total['value'] > 0) {
                    $shiping_ot_tax = $o_total['value'];
                }
              }
              else if(isset($o_total['code']) && $o_total['code'] == 'ot_total') {
                if(isset($o_total['value']) && $o_total['value'] > 0) {
                    $total_amount = number_format($o_total['value'], 2, '.', '') * 100;
                }
              }	
            } 
			// End loworderfee breakdown

            //Add coupon breakdown
            $coupon_amount = isset($coupon_amount) ? $coupon_amount : 0;
            $coupon_amount_shipping = 0;	
            if (abs(isset($_SESSION['cc_id']))) {
                $sql = "select * 
                        from " . TABLE_COUPONS . " c,
                            " . TABLE_COUPONS_DESCRIPTION . " cd,
                            " . TABLE_TAX_RATES . " tr 
                        where c.coupon_id=:couponID: and coupon_active='Y' 
                        and c.coupon_id = cd.coupon_id	";
                $sql = $db->bindVars($sql, ':couponID:', $_SESSION['cc_id'], 'integer');

                $coupon = $db->Execute($sql);
                $coupon_product_count	= $coupon->fields['coupon_product_count'];
                $coupon_tax_rate			= $coupon->fields['tax_rate'];
                $coupon_code				= $coupon->fields['coupon_code'].'';
                $coupon_amount_formatted = number_format((float)$coupon_amount, 2, '.', '');
                $coupon_shipping_tax		= zen_round($shipping_tax, $decimals) * 100 ;
                $coupon_amount_shipping	= $discount_amount_shipping * 100;

                if (DISPLAY_PRICE_WITH_TAX == 'true') {
                    $coupon_amount = $coupon_amount_formatted * 100;
                } else {
                    $coupon_amount = $coupon->fields['coupon_amount'];
                }

                // Variable to compare product discount calculation to total amount of the order
                $coupon_result = 0;	
                switch ($coupon->fields['coupon_type']) {
                // case 'S': // shipping
                // $coupon_result = $coupon_tax_amount ;		
                //	break;
                case 'F': // unit amount
                    // One by one  unit amount total
                    if ($coupon_product_count == 1) {
                        $coupon_result = $coupon_amount * $itemqyt * 100;
                    } else {
                        $coupon_result = $coupon_amount;
                    }
                break;

                // amount off and free shipping	
                case 'O': 
                $coupon_amount_shipping = $discount_amount_shipping * 100;
                $O_shippingprice = $coupon_amount_shipping +  $coupon_shipping_tax;
                // One by one  unit amount total					
                if ($coupon_product_count == 1) {
                    $coupon_amount = $coupon_amount * 100 * $itemqyt;
                    $coupon_amount_shipping = $discount_amount_shipping * 100;
                    $coupon_result = $coupon_amount + $coupon_amount_shipping;
                } else {
                    $coupon_result = ($coupon_amount_formatted + $discount_amount_shipping) * 100;
                  }

                $items[] = [
					'title'    => MODULE_PAYMENT_PAYTRAIL_FREE_SHPING,
                   	'code'     => '',
                   	'qty'      => 1,
                   	'price'    => $O_shippingprice,
                   	'vat'      => 0,
                   	'discount' => 0,
                   	'type'     => 2,
				];

                $total_check += $O_shippingprice;
                break;

				// percentage	
                case 'P': 
                if($shippingcost > 0 ) {
                   // Coupon cost
                   $coupon_cost = ($order_subtotal/100)*($coupon_amount);
                   // add shiping cost and shping tax
                   $coupon_shiping_tax = ($shippingcost/100)*($coupon_amount);
                   $coupon_result =  ($coupon_cost + $coupon_shiping_tax) ;
                   $coupon_result = zen_round($coupon_result, $decimals) * 100;
                } else {
                   $coupon_result = ($order_subtotal/100)*($coupon_amount_formatted);
                   $coupon_result = zen_round($coupon_result, $decimals) * 100;
                  }
                break;

                // percentage and Free Shipping
                case 'E':
                if (isset($coupon_amount_shipping) == 0) {
                  $coupon_amount_shipping = 1;
                }

              	$E_shipping_tax_cost  = zen_round($shiping_ot_tax, $decimals) * 100;
              	$E_shipping_price     = $coupon_amount_shipping + $E_shipping_tax_cost ;
              	$E_shipping_tax       = ($E_shipping_tax_cost/$coupon_amount_shipping)* 100 ;
              	$coupon_cost          = (($order_subtotal + $discount_amount_shipping)/100) * ($coupon_amount_formatted);
              	$coupon_result        = ($coupon_cost + $discount_amount_shipping);
              	$coupon_result        = zen_round($coupon_result, $decimals) * 100;

              	$items[] = [
					'title'     => MODULE_PAYMENT_PAYTRAIL_FREE_SHPING,
                	'code'      => '',
                	'qty'       => 1,
                	'price'     => $E_shipping_price,
					'vat'       => $E_shipping_tax,
                	'discount'  => 0,
                	'type'      => 2,
				];	
              	$total_check += $E_shipping_price;	
                break;				
				} // end switch

             	$items[] = [
					'title'    => MODULE_PAYMENT_PAYTRAIL_COUPON_TEXT,
					'code'     => $coupon_code,
					'qty'      => -1,
					'price'    => $coupon_result,
					'vat'      => 0,
					'discount' => 0,
					'type'     => 4,
				];
             	$total_check -= $coupon_result;
                }
				// End coupon breakdown

				// Add Gift Voucher breakdown
				if ($_SESSION['cot_gv'] > 0) {
					$gv_query = "select *
								from " . TABLE_COUPON_GV_CUSTOMER . " 
								where customer_id = '" . $_SESSION['customer_id'] . "'";
					$gv_order = $db->Execute($gv_query);

					// Gift amonut total	
					$gv_order_amount = number_format($gv_order->fields['amount'], 2, '.', '') .'€';
					$gv_amount = $_SESSION['cot_gv'] * 100;

					// if tax is to be calculated on purchased GVs, calculate it
					$items[] = [
					  'title'     => MODULE_PAYMENT_PAYTRAIL_GIFT_TEXT,
					  'code'      => $gv_order_amount,
					  'qty'       => -1,
					  'price'     => $gv_amount,
					  'vat'       => 0,
					  'discount'  => 0,
					  'type'      => 4,
					  ];
					$total_check -= $gv_amount;
				}

				// Add reward points breakdown
				// Reward points module: $_SESSION['redeem_points'] = number of points,
				// $_SESSION['redeem_value'] = discount in EUR (points x ratio, deducted from the order total).
				// Paytrail item prices are in cents -> use the EUR value x 100.
				$redeemValue = (isset($_SESSION['redeem_value']) && is_numeric($_SESSION['redeem_value'])) ? (float)$_SESSION['redeem_value'] : 0; // Reward Point not aktif = 0

				// Add reward points breakdown
				if ($redeemValue > 0) {
				  $redem_value = (int)round($redeemValue * 100);
				  // if tax is to be calculated on purchased GVs, calculate it
				  $items[] = [
					'title'     => MODULE_PAYMENT_PAYTRAIL_REWARD_POINT_TEXT,
					'code'      => '',
					'qty'       => -1,
					'price'     => $redem_value,
					'vat'       => 0,
					'discount'  => 0,
					'type'      => 4,
					];
				  $total_check -= $redem_value;
				}		

				// Add sumround breakdown
				if ($this->amount <> $total_check) {
				  if ($this->amount > $total_check) {
					  $sum_round_count = $this->amount - $total_check;
					  $qty = 1;
				  }

				  if ($total_check > $this->amount) {
					  $sum_round_count = $total_check - $this->amount;
					  $qty = -1;
				  }
				  $sum_round = round(floatval($sum_round_count));
				  $items[] = [
					'title'     => MODULE_PAYMENT_PAYTRAIL_SUM_ROUND,
					'code'      => '',
					'qty'       => $qty,
					'price'     => $sum_round,
					'vat'       => 0,
					'discount'  => 0,
					'type'      => 1,
					];
				}
				return $items;
        } 
		// end itemArgs($order)

        /**   
        * ********************************  
        *  Helpers  (since v5.8.0)
        * ********************************	
        */ 

        /**
         * Order number and amount (minor unit) for the current order
         */
        protected function prepareOrderData()
        {
            global $order;
            // This order number random 
            $prefix             = '78';  // The first two digits you specify
            $this->order_number = $this->createOrderNumber($prefix);
            $amount             = number_format($order->info['total'], 2, '.', '') * 100;
            $this->amount       = intval(round($amount));
        }

        /**
         * Signed POST request to the Paytrail API with Guzzle.
         * Response HMAC is always validated.
         *
         * @return array ['ok' => bool, 'code' => int, 'data' => object|null, 'raw' => string]
         */
        protected function apiPost(string $uri, string $body = '', ?string $transactionId = null, ?string $tokenizationId = null): array
        {
            $headers = $this->getHeaders('POST', $transactionId, $tokenizationId);
            // New token payments / pay-and-add-card are created without a transaction id
            if (empty($headers['checkout-transaction-id'])) {
                unset($headers['checkout-transaction-id']);
            }
            $headers['signature'] = $this->calculateHmac($headers, $body, $this->secretKey);

            $client = new \GuzzleHttp\Client([
                'headers'         => $headers,
                'http_errors'     => false,
                'allow_redirects' => false,
                'timeout'         => 30,
            ]);

            try {
                $response = $client->post($this->apiBase . $uri, ['body' => $body]);
            } catch (\Throwable $e) {
                error_log('Paytrail API error ' . $uri . ': ' . $e->getMessage());
                return ['ok' => false, 'code' => 0, 'data' => null, 'raw' => ''];
            }

            $responseBody    = (string)$response->getBody();
            $responseHeaders = [];
            foreach ($response->getHeaders() as $name => $values) {
                $responseHeaders[strtolower($name)] = $values[0] ?? '';
            }

            if (!$this->validateHmac($responseHeaders, $responseBody, $responseHeaders['signature'] ?? '', (string)$this->secretKey)) {
                error_log('Paytrail API ' . $uri . ': response HMAC signature mismatch (HTTP ' . $response->getStatusCode() . ') ' . substr($responseBody, 0, 500));
                return ['ok' => false, 'code' => $response->getStatusCode(), 'data' => json_decode($responseBody), 'raw' => $responseBody, 'hmac' => false, 'request_id' => $responseHeaders['request-id'] ?? ''];
            }

            return ['ok' => true, 'code' => $response->getStatusCode(), 'data' => json_decode($responseBody), 'raw' => $responseBody, 'request_id' => $responseHeaders['request-id'] ?? ''];
        }

        /**
         * Customer initiated (CIT) charge with a saved card token.
         * Called in before_process() after the customer has pressed "Confirm order".
         */
        protected function chargeSavedCard()
        {
            $customerId = $this->getLoggedInCustomerId();
            $card = ($customerId > 0) ? $this->getCustomerCard($customerId, (int)$_SESSION['paytrail_card_id']) : null;
            if ($card === null) {
                unset($_SESSION['paytrail_card_id']);
                $this->failToPaymentPage(MODULE_PAYMENT_PAYTRAIL_CARD_NOT_FOUND);
            }

            if (empty($_SESSION['paytrail_token_stamp'])) {
                $_SESSION['paytrail_token_stamp'] = $this->generate_uuid();
            }
            $stamp = $_SESSION['paytrail_token_stamp'];

            $body   = $this->getBody(['stamp' => $stamp, 'token' => $card['token']]);
            $result = $this->apiPost('/payments/token/cit/charge', $body);
            $data   = $result['data'];

            // A new stamp is needed for the next try (Paytrail rejects duplicate stamps)
            unset($_SESSION['paytrail_token_stamp']);

            if ($result['ok'] && $result['code'] == 201 && !empty($data->transactionId)) {
                $this->rememberTransaction($data->transactionId);
                $this->recordPayment($data->transactionId, 'token');
                $this->setPaymentStatus($data->transactionId, 'ok', (string)($card['card_type'] ?: 'creditcard'));
                $this->paymentResult = [
                    'status'        => 'ok',
                    'provider'      => $card['card_type'] ?: 'creditcard',
                    'reference'     => $this->order_number,
                    'transactionId' => $data->transactionId,
                    'amount'        => $this->amount,
                    'savedCard'     => '**** ' . $card['partial_pan'],
                ];
                return;
            }

            // 3D Secure step-up required: customer is redirected, Paytrail returns
            // to checkout_process (redirectUrls) with signed parameters.
            if ($result['ok'] && $result['code'] == 403 && !empty($data->threeDSecureUrl)) {
                $this->rememberTransaction($data->transactionId ?? '');
                $this->recordPayment($data->transactionId ?? '', 'token');
                zen_redirect($data->threeDSecureUrl);
            }

            $message = MODULE_PAYMENT_PAYTRAIL_CARD_CHARGE_FAILED;
            if (!empty($data->acquirerResponseCodeDescription)) {
                $message .= ' (' . zen_output_string_protected($data->acquirerResponseCodeDescription) . ')';
            }
            // Card can not be used any more -> remove it
            if (!empty($data->acquirerResponseCode) && in_array((string)$data->acquirerResponseCode, ['111','119','165','200','207','208','209','902','908','909','181','183','187','189'], true)) {
                $this->deleteCustomerCard($customerId, (int)$card['id']);
                $message .= ' ' . MODULE_PAYMENT_PAYTRAIL_CARD_REMOVED;
            }
            error_log('Paytrail token charge failed: HTTP ' . $result['code'] . ' ' . substr($result['raw'], 0, 500));
            unset($_SESSION['paytrail_card_id']);
            $this->failToPaymentPage($message);
        }

        /**
         * Tokenization: get the card token with checkout-tokenization-id (add card form flow)
         * @return object|null
         */
        public function getCardToken(string $tokenizationId)
        {
            $body   = json_encode(['checkout-tokenization-id' => $tokenizationId], JSON_UNESCAPED_SLASHES);
            $result = $this->apiPost('/tokenization/' . rawurlencode($tokenizationId), $body, null, $tokenizationId);
            if ($result['ok'] && $result['code'] == 200 && !empty($result['data']->token)) {
                return $result['data'];
            }
            error_log('Paytrail get token failed: HTTP ' . $result['code'] . ' ' . substr($result['raw'], 0, 500));
            return null;
        }

        /**
         * Auto-submitting form to Paytrail add card page (POST /tokenization/addcard-form).
         * Authentication is done with signed form fields.
         */
        public function addCardFormHtml(string $successUrl, string $cancelUrl): string
        {
            $fields = [
                'checkout-account'              => (string)$this->merchantId,
                'checkout-algorithm'            => 'sha256',
                'checkout-method'               => 'POST',
                'checkout-nonce'                => bin2hex(random_bytes(16)),
                'checkout-timestamp'            => gmdate('Y-m-d\TH:i:s\Z'),
                'checkout-redirect-success-url' => $successUrl,
                'checkout-redirect-cancel-url'  => $cancelUrl,
                'language'                      => $this->language,
            ];
            $fields['signature'] = $this->calculateHmac($fields, '', $this->secretKey);

            $html = '<form id="paytrail-addcard-form" action="' . $this->apiBase . '/tokenization/addcard-form" method="post">';
            foreach ($fields as $name => $value) {
                $html .= '<input type="hidden" name="' . zen_output_string_protected($name) . '" value="' . zen_output_string_protected($value) . '">';
            }
            $html .= '<button type="submit" class="btn btn-primary">' . MODULE_PAYMENT_PAYTRAIL_ADD_CARD . '</button>';
            $html .= '</form>';
            return $html;
        }

        /**
         * Callback urls. For pay-and-add-card the customer id is signed into the url,
         * because the callback is a server-to-server call without the customer session.
         */
        protected function getCallbackUrls(int $customerId = 0, string $stamp = ''): array
        {
            $params = '';
            if ($customerId > 0 && $stamp !== '') {
                $params = 'cid=' . $customerId . '&csig=' . $this->customerSignature($customerId, $stamp);
            }
            $url = zen_href_link('paytrail_callback', $params, 'SSL', false, false);
            return ['success' => $url, 'cancel' => $url];
        }

        public function customerSignature(int $customerId, string $stamp): string
        {
            return hash_hmac('sha256', 'paytrail-card|' . $customerId . '|' . $stamp, (string)$this->secretKey);
        }

        /**
         * Remember transaction ids created in this session (return URL / waiting page check).
         */
        protected function rememberTransaction(?string $transactionId)
        {
            if (empty($transactionId)) {
                return;
            }
            $list   = $_SESSION['paytrail_transactions'] ?? [];
            $list[] = $transactionId;
            $_SESSION['paytrail_transactions'] = array_slice(array_unique($list), -10);
        }

        public function isOwnTransaction(?string $transactionId): bool
        {
            if (empty($transactionId)) {
                return false;
            }
            if (in_array($transactionId, $_SESSION['paytrail_transactions'] ?? [], true)) {
                return true;
            }
            // Admin "Place Order" (EMP login) for this customer: his paid payment without an order
            if ($this->isEmpAdminSession()) {
                $row = $this->getPayment($transactionId);
                return $row !== null
                    && (int)$row['customers_id'] === (int)$_SESSION['customer_id']
                    && (int)$row['orders_id'] === 0
                    && in_array($row['status'], ['ok', 'pending', 'delayed'], true);
            }
            return false;
        }

        /**
         * Admin is logged in as the customer (Zen Cart "Place Order" / passwordless EMP login)
         */
        public function isEmpAdminSession(): bool
        {
            return !empty($_SESSION['emp_admin_login']) && !empty($_SESSION['emp_admin_id']) && !empty($_SESSION['customer_id']);
        }

        /**
         * EMP session: paid payments without an order of the logged in customer (last 14 days)
         */
        protected function empPaidWithoutOrder(): array
        {
            global $db;
            if (!$this->isEmpAdminSession()) {
                return [];
            }
            $this->ensureCardTable();
            $list   = [];
            $result = $db->Execute("SELECT transaction_id FROM " . $this->paymentsTable() . "
                                    WHERE customers_id = " . (int)$_SESSION['customer_id'] . "
                                      AND orders_id = 0 AND status IN ('ok','pending','delayed')
                                      AND date_added >= NOW() - INTERVAL 14 DAY
                                    ORDER BY id DESC LIMIT 5");
            while (!$result->EOF) {
                $list[] = $result->fields['transaction_id'];
                $result->MoveNext();
            }
            return $list;
        }

        protected function failToPaymentPage(string $message)
        {
            global $messageStack;
            $messageStack->add_session('checkout_payment', $message, 'error');
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
        }

        protected function redirectHtml(string $paytrail_url): string
        {
            $urlAttr = zen_output_string_protected($paytrail_url);
            $urlJs   = json_encode($paytrail_url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

            $html  = '<style>
                  .paytrail-redirect-container { text-align: center; margin: 30px auto; padding: 20px; max-width: 500px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9; }
                  .paytrail-submit-btn { display: inline-block; background-color: #0073e6; color: #ffffff !important; padding: 12px 30px; font-size: 16px; font-weight: bold; text-decoration: none !important; border-radius: 5px; border: none; cursor: pointer; transition: background 0.2s; margin-top: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                  .paytrail-submit-btn:hover { background-color: #0059b3; }
                  #btn_submit, .buttonRow, .btn-success { display: none; }
              </style>';
            $html .= '<div class="paytrail-redirect-container">';
            $html .= '  <p style="font-size: 15px; color: #333;">' . MODULE_PAYMENT_PAYTRAIL_TEXT_REDIRECTING . '</p>';
            $html .= '  <p style="font-size: 13px; color: #666;">' . MODULE_PAYMENT_PAYTRAIL_TEXT_REDIRECTING_BUTTON . ' </p>';
            $html .= '  <a href="' . $urlAttr . '" class="paytrail-submit-btn">' . MODULE_PAYMENT_PAYTRAIL_GO_TO_PAYTRAIL_PAGE . '</a>';
            $html .= '</div>';
            $html .= '<script type="text/javascript">setTimeout(function() { window.location.href = ' . $urlJs . '; }, 500);</script>';
            return $html;
        }

        protected function apiErrorHtml(array $result): string
        {
            $html  = '<style>#btn_submit, .buttonRow, .btn-success, .payment-providers, .ms-auto { display: none; }</style>';
            $html .= '<div style="color:red">';
            if (isset($result['hmac']) && $result['hmac'] === false) {
                $html .= MODULE_PAYMENT_PAYTRAIL_TEXT_API_ERROR;
            } else {
                $html .= MODULE_PAYMENT_PAYTRAIL_PAYMENT_ERROR . ($result['code'] ? ' (HTTP ' . (int)$result['code'] . ')' : '');
            }
            $html .= '<br><strong>' . MODULE_PAYMENT_PAYTRAIL_SELECET_OTHER . '</strong> &nbsp;';
            $html .= '<a class="btn btn-outline-danger btn-sm" href="' . zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL') . '">' . BUTTON_BACK_ALT . '</a></div>';
            return $html;
        }

        // ---------------- Paytrail payments table (transactionId <-> customer <-> order) ----------------
        //
        // Every payment is written to DB_PREFIX paytrail_payments when it is created.
        // Return URL, callback, waiting page, admin page and recovery all use this table.

        public $lastStamp = '';

        protected function paymentsTable(): string
        {
            return DB_PREFIX . 'paytrail_payments';
        }

        /**
         * Viitenumero to show: Paytrail's bank reference (POST /payments response "reference"),
         * or our own reference (checkout-reference) when Paytrail did not return one
         * (token charge / pay-and-add-card responses have no bank reference).
         */
        public function paytrailReference(?array $row, string $fallback = ''): string
        {
            // Kauppiaan viite (our reference, checkout-reference) everywhere - same number for
            // all payment methods, easy to search and analyse. Paytrail's own bank reference is
            // still saved in the bank_reference column.
            if (is_array($row) && !empty($row['reference'])) {
                return (string)$row['reference'];
            }
            return $fallback;
        }

        protected function pendingOrderStatusId(): int
        {
            return (defined('MODULE_PAYMENT_PAYTRAIL_PENDING_STATUS_ID') && (int)MODULE_PAYMENT_PAYTRAIL_PENDING_STATUS_ID > 0)
                ? (int)MODULE_PAYMENT_PAYTRAIL_PENDING_STATUS_ID
                : (int)DEFAULT_ORDERS_STATUS_ID;
        }

        protected function paidOrderStatusId(): int
        {
            return ((int)MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID > 0) ? (int)MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID : (int)DEFAULT_ORDERS_STATUS_ID;
        }

        /**
         * The order is created after before_process(): set the "waiting" status for pending payments.
         */
        protected function setPendingOrderStatus(string $status)
        {
            global $order;
            if ($status !== 'ok') {
                // checkout_process.php copies $this->order_status to the order AFTER before_process()
                $this->order_status = $this->pendingOrderStatusId();
                if (is_object($order)) {
                    $order->info['order_status'] = $this->order_status;
                }
            }
        }

        /**
         * Short cart / order snapshot (JSON) so a paid payment without an order can be handled.
         */
        protected function orderSnapshot(): string
        {
            global $order;
            if (!is_object($order)) {
                return '';
            }
            $products = [];
            foreach ((array)$order->products as $product) {
                $attributes = [];
                foreach ((array)($product['attributes'] ?? []) as $attribute) {
                    $attributes[] = ($attribute['option'] ?? '') . ': ' . ($attribute['value'] ?? '');
                }
                $products[] = [
                    'qty'        => $product['qty'] ?? 0,
                    'name'       => $product['name'] ?? '',
                    'model'      => $product['model'] ?? '',
                    'price'      => round((float)($product['final_price'] ?? 0) * (1 + (float)($product['tax'] ?? 0) / 100), 2),
                    'attributes' => $attributes,
                ];
            }
            $snapshot = [
                'products'  => $products,
                'shipping'  => $order->info['shipping_method'] ?? '',
                'total'     => $order->info['total'] ?? 0,
                'comments'  => $order->info['comments'] ?? '',
                'telephone' => $order->customer['telephone'] ?? '',
                'delivery'  => trim(($order->delivery['firstname'] ?? '') . ' ' . ($order->delivery['lastname'] ?? '') . ', '
                             . ($order->delivery['street_address'] ?? '') . ', ' . ($order->delivery['postcode'] ?? '') . ' ' . ($order->delivery['city'] ?? ''), ' ,'),
            ];
            // Full order data: used by the admin "create order" (last resort, see createOrderFromPayment)
            global $order_totals;
            $snapshot['full'] = [
                'info'     => $order->info,
                'customer' => $order->customer,
                'delivery' => $order->delivery,
                'billing'  => $order->billing,
                'products' => $order->products,
                'totals'   => is_array($order_totals) ? $order_totals : [],
                'session'  => [
                    'customer_id'   => (int)($_SESSION['customer_id'] ?? 0),
                    'languages_code'=> $_SESSION['languages_code'] ?? '',
                    'ip'            => ($_SESSION['customers_ip_address'] ?? '') . ' - ' . ($_SERVER['REMOTE_ADDR'] ?? ''),
                    'weight'        => (isset($_SESSION['cart']) && is_object($_SESSION['cart'])) ? (float)$_SESSION['cart']->weight : 0,
                    'shipping_id'   => $_SESSION['shipping']['id'] ?? '',
                    'session_id'    => session_id(),
                ],
                // Checkout state that is NOT in the basket (restored for admin "Place Order"):
                // shipping, coupon, gift voucher, reward points, comments, addresses
                'restore'  => [
                    'shipping'      => $_SESSION['shipping'] ?? null,
                    'cc_id'         => $_SESSION['cc_id'] ?? null,
                    'cot_gv'        => $_SESSION['cot_gv'] ?? null,
                    'redeem_points' => $_SESSION['redeem_points'] ?? null,
                    'redeem_value'  => $_SESSION['redeem_value'] ?? null,
                    'comments'      => $_SESSION['comments'] ?? null,
                    'sendto'        => $_SESSION['sendto'] ?? null,
                    'billto'        => $_SESSION['billto'] ?? null,
                ],
                // Raw basket (same data as customers_basket) at the moment of payment
                'cart'     => (isset($_SESSION['cart']) && is_object($_SESSION['cart'])) ? (array)$_SESSION['cart']->contents : [],
            ];
            return (string)json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        /**
         * ADMIN - last resort: create the Zen Cart order from the saved order data of a paid
         * payment that has no order (same tables / fields as order::create()).
         * Stock is not decreased and no customer e-mail is sent automatically.
         *
         * @return int new orders_id or 0 (error message in $this->lastError)
         */
        public $lastError = '';

        public function createOrderFromPayment(string $transactionId, bool $emptyBasket = true): int
        {
            global $db;
            $this->lastError = '';
            $row = $this->getPayment($transactionId);
            if ($row === null) {
                $this->lastError = 'transaction not found';
                return 0;
            }
            if ((int)$row['orders_id'] > 0) {
                $this->lastError = 'order already exists: ' . (int)$row['orders_id'];
                return 0;
            }
            if (!in_array($row['status'], ['ok', 'pending', 'delayed'], true)) {
                $this->lastError = 'payment status is ' . $row['status'];
                return 0;
            }
            $snapshot = json_decode((string)$row['cart_snapshot'], true);
            $full = $snapshot['full'] ?? null;
            if (!is_array($full) || empty($full['products'])) {
                $this->lastError = 'no saved order data';
                return 0;
            }

            $info = $full['info'];
            $c    = $full['customer'];
            $d    = $full['delivery'] ?: $full['billing'];
            $b    = $full['billing'];
            $sess = $full['session'];
            $status = ($row['status'] === 'ok') ? $this->paidOrderStatusId() : $this->pendingOrderStatusId();
            $val  = function ($array, $key, $default = '') { return (is_array($array) && isset($array[$key]) && !is_array($array[$key])) ? $array[$key] : $default; };
            $country = function ($address) { return (is_array($address) && isset($address['country']['title'])) ? $address['country']['title'] : ''; };
            $shipCode = (string)$val($info, 'shipping_module_code');
            if ($val($sess, 'shipping_id') === 'free_free') {
                $shipCode = 'free_free';
            }

            $order_data = [
                'customers_id' => (int)$row['customers_id'],
                'customers_name' => trim($val($c, 'firstname') . ' ' . $val($c, 'lastname')),
                'customers_company' => $val($c, 'company'),
                'customers_street_address' => $val($c, 'street_address'),
                'customers_suburb' => $val($c, 'suburb'),
                'customers_city' => $val($c, 'city'),
                'customers_postcode' => $val($c, 'postcode'),
                'customers_state' => $val($c, 'state'),
                'customers_country' => $country($c),
                'customers_telephone' => $val($c, 'telephone'),
                'customers_email_address' => $val($c, 'email_address'),
                'customers_address_format_id' => (int)$val($c, 'format_id', 1),
                'delivery_name' => trim($val($d, 'firstname') . ' ' . $val($d, 'lastname')),
                'delivery_company' => $val($d, 'company'),
                'delivery_street_address' => $val($d, 'street_address'),
                'delivery_suburb' => $val($d, 'suburb'),
                'delivery_city' => $val($d, 'city'),
                'delivery_postcode' => $val($d, 'postcode'),
                'delivery_state' => $val($d, 'state'),
                'delivery_country' => $country($d),
                'delivery_address_format_id' => (int)$val($d, 'format_id', 1),
                'billing_name' => trim($val($b, 'firstname') . ' ' . $val($b, 'lastname')),
                'billing_company' => $val($b, 'company'),
                'billing_street_address' => $val($b, 'street_address'),
                'billing_suburb' => $val($b, 'suburb'),
                'billing_city' => $val($b, 'city'),
                'billing_postcode' => $val($b, 'postcode'),
                'billing_state' => $val($b, 'state'),
                'billing_country' => $country($b),
                'billing_address_format_id' => (int)$val($b, 'format_id', 1),
                'payment_method' => $val($info, 'payment_method', MODULE_PAYMENT_PAYTRAIL_TEXT_TITLE),
                'payment_module_code' => $this->code,
                'shipping_method' => $val($info, 'shipping_method'),
                'shipping_module_code' => (strpos($shipCode, '_') > 0 ? substr($shipCode, 0, strpos($shipCode, '_')) : $shipCode),
                'coupon_code' => $val($info, 'coupon_code'),
                'cc_type' => '', 'cc_owner' => '', 'cc_number' => '', 'cc_expires' => '',
                'date_purchased' => 'now()',
                'orders_status' => $status,
                'order_total' => (float)$val($info, 'total', $row['amount'] / 100),
                'order_tax' => (float)$val($info, 'tax', 0),
                'currency' => $val($info, 'currency', 'EUR'),
                'currency_value' => (float)$val($info, 'currency_value', 1),
                'ip_address' => substr((string)$val($sess, 'ip'), 0, 96),
                'language_code' => $val($sess, 'languages_code'),
                'order_weight' => (float)$val($sess, 'weight', 0),
                'is_wholesale' => (int)$val($info, 'is_wholesale', 0),
            ];
            if ($val($info, 'shipping_tax_rate', null) !== null) {
                $order_data['shipping_tax_rate'] = (float)$info['shipping_tax_rate'];
            }
            zen_db_perform(TABLE_ORDERS, $order_data);
            $ordersId = (int)$db->insert_ID();
            if ($ordersId < 1) {
                $this->lastError = 'order insert failed';
                return 0;
            }

            foreach ((array)$full['totals'] as $total) {
                zen_db_perform(TABLE_ORDERS_TOTAL, [
                    'orders_id' => $ordersId,
                    'title' => (string)($total['title'] ?? ''),
                    'text' => (string)($total['text'] ?? ''),
                    'value' => is_numeric($total['value'] ?? null) ? $total['value'] : '0',
                    'class' => (string)($total['code'] ?? ''),
                    'sort_order' => (int)($total['sort_order'] ?? 0),
                ]);
            }

            foreach ((array)$full['products'] as $p) {
                $prid = (string)($p['id'] ?? '');
                zen_db_perform(TABLE_ORDERS_PRODUCTS, [
                    'orders_id' => $ordersId,
                    'products_id' => (int)$prid,
                    'products_model' => (string)($p['model'] ?? ''),
                    'products_name' => (string)($p['name'] ?? ''),
                    'products_price' => (float)($p['price'] ?? 0),
                    'final_price' => (float)($p['final_price'] ?? 0),
                    'onetime_charges' => (float)($p['onetime_charges'] ?? 0),
                    'products_tax' => (float)($p['tax'] ?? 0),
                    'products_quantity' => (float)($p['qty'] ?? 0),
                    'products_priced_by_attribute' => (int)($p['products_priced_by_attribute'] ?? 0),
                    'product_is_free' => (int)($p['product_is_free'] ?? 0),
                    'products_discount_type' => (int)($p['products_discount_type'] ?? 0),
                    'products_discount_type_from' => (int)($p['products_discount_type_from'] ?? 0),
                    'products_prid' => $prid,
                    'products_weight' => (float)($p['weight'] ?? 0),
                    'products_virtual' => (int)($p['products_virtual'] ?? 0),
                    'product_is_always_free_shipping' => (int)($p['product_is_always_free_shipping'] ?? 0),
                    'products_quantity_order_min' => (float)($p['products_quantity_order_min'] ?? 1),
                    'products_quantity_order_units' => (float)($p['products_quantity_order_units'] ?? 1),
                    'products_quantity_order_max' => (float)($p['products_quantity_order_max'] ?? 0),
                    'products_quantity_mixed' => (int)($p['products_quantity_mixed'] ?? 0),
                    'products_mixed_discount_quantity' => (int)($p['products_mixed_discount_quantity'] ?? 0),
                ]);
                $opId = (int)$db->insert_ID();
                foreach ((array)($p['attributes'] ?? []) as $a) {
                    zen_db_perform(TABLE_ORDERS_PRODUCTS_ATTRIBUTES, [
                        'orders_id' => $ordersId,
                        'orders_products_id' => $opId,
                        'products_options' => (string)($a['option'] ?? ''),
                        'products_options_values' => (string)($a['value'] ?? ''),
                        'options_values_price' => (float)($a['price'] ?? 0),
                        'price_prefix' => (string)($a['prefix'] ?? '+'),
                        'products_options_id' => (int)($a['option_id'] ?? 0),
                        'products_options_values_id' => (int)($a['value_id'] ?? 0),
                        'products_prid' => $prid,
                    ]);
                }
            }

            // Order history: customer comment + Paytrail payment info
            zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, [
                'orders_id' => $ordersId,
                'orders_status_id' => $status,
                'date_added' => 'now()',
                'customer_notified' => 0,
                'comments' => (string)$val($info, 'comments'),
                'updated_by' => $this->adminName(),
            ]);
            $statusText = ($row['status'] === 'ok') ? MODULE_PAYMENT_PAYTRAIL_PAYMENT_OK : MODULE_PAYMENT_PAYTRAIL_PAYMENT_WAITING_ACTIVATION;
            zen_update_orders_history($ordersId, MODULE_PAYMENT_PAYTRAIL_ORDER_FROM_TABLE . "\n" . MODULE_PAYMENT_PAYTRAIL_TITLE_STATUS . $statusText . MODULE_PAYMENT_PAYTRAIL_PAYMENT_METHOD . $row['provider'] . ' , ' . MODULE_PAYMENT_PAYTRAIL_REFERENCE_NUMBER . $this->paytrailReference($row), $this->adminName(), -1, 0);

            $this->setPaymentStatus($row['transaction_id'], '', '', $ordersId);

            // The products are still in the customer's saved basket -> remove them
            if ($emptyBasket && (int)$row['customers_id'] > 0) {
                $db->Execute("DELETE FROM " . TABLE_CUSTOMERS_BASKET . " WHERE customers_id = " . (int)$row['customers_id']);
                $db->Execute("DELETE FROM " . TABLE_CUSTOMERS_BASKET_ATTRIBUTES . " WHERE customers_id = " . (int)$row['customers_id']);
            }
            return $ordersId;
        }

        /**
         * New payment created at Paytrail -> row with status "new".
         * $method: payment | pay_add_card | token
         */
        protected function recordPayment(?string $transactionId, string $method = 'payment', string $bankReference = '')
        {
            global $db, $order;
            if (empty($transactionId)) {
                return;
            }
            $this->ensureCardTable();
            $email = is_object($order) ? (string)($order->customer['email_address'] ?? '') : '';
            $name  = is_object($order) ? trim(($order->customer['firstname'] ?? '') . ' ' . ($order->customer['lastname'] ?? '')) : '';

            $sql = "INSERT INTO " . $this->paymentsTable() . "
                    (transaction_id, stamp, reference, bank_reference, customers_id, customer_email, customer_name, orders_id, amount, currency, method, provider, status, cart_snapshot, session_id, admin_notified, date_added, last_modified)
                    VALUES (:tx, :stamp, :ref, :bankref, :cid, :email, :name, 0, :amount, 'EUR', :method, '', 'new', :snapshot, :sid, 0, now(), now())
                    ON DUPLICATE KEY UPDATE last_modified = now()";
            $sql = $db->bindVars($sql, ':tx', $transactionId, 'string');
            $sql = $db->bindVars($sql, ':stamp', substr((string)$this->lastStamp, 0, 64), 'string');
            $sql = $db->bindVars($sql, ':ref', substr((string)$this->order_number, 0, 32), 'string');
            $sql = $db->bindVars($sql, ':bankref', substr($bankReference, 0, 32), 'string');
            $sql = $db->bindVars($sql, ':cid', (int)($_SESSION['customer_id'] ?? 0), 'integer');
            $sql = $db->bindVars($sql, ':email', substr($email, 0, 96), 'string');
            $sql = $db->bindVars($sql, ':name', substr($name, 0, 128), 'string');
            $sql = $db->bindVars($sql, ':amount', (int)$this->amount, 'integer');
            $sql = $db->bindVars($sql, ':method', $method, 'string');
            $sql = $db->bindVars($sql, ':snapshot', $this->orderSnapshot(), 'string');
            $sql = $db->bindVars($sql, ':sid', substr((string)session_id(), 0, 128), 'string');
            $db->Execute($sql);
        }

        public function getPayment(string $transactionId): ?array
        {
            global $db;
            if ($transactionId === '') {
                return null;
            }
            $this->ensureCardTable();
            $sql = "SELECT * FROM " . $this->paymentsTable() . " WHERE transaction_id = :tx LIMIT 1";
            $sql = $db->bindVars($sql, ':tx', $transactionId, 'string');
            $result = $db->Execute($sql);
            return $result->EOF ? null : $result->fields;
        }

        public function getPaymentByOrder(int $ordersId): ?array
        {
            global $db;
            $this->ensureCardTable();
            $result = $db->Execute("SELECT * FROM " . $this->paymentsTable() . " WHERE orders_id = " . (int)$ordersId . " ORDER BY id DESC LIMIT 1");
            return $result->EOF ? null : $result->fields;
        }

        /**
         * Update status (and order id). A final status (ok / cancelled) is not overwritten
         * by a late or repeated callback, only by an explicit admin action ($force).
         */
        public function setPaymentStatus(string $transactionId, string $status, string $provider = '', int $ordersId = 0, bool $force = false)
        {
            global $db;
            $row = $this->getPayment($transactionId);
            if ($row === null) {
                return;
            }
            $set = ["last_modified = now()"];
            if ($status !== '' && ($force || !in_array($row['status'], ['ok', 'cancelled'], true) || $status === $row['status'])) {
                $set[] = "status = '" . zen_db_input(substr($status, 0, 32)) . "'";
            }
            if ($provider !== '') {
                $set[] = "provider = '" . zen_db_input(substr($provider, 0, 64)) . "'";
            }
            if ($ordersId > 0) {
                $set[] = "orders_id = " . (int)$ordersId;
            }
            $db->Execute("UPDATE " . $this->paymentsTable() . " SET " . implode(', ', $set) . " WHERE id = " . (int)$row['id']);
        }

        /**
         * Fresh status from the Paytrail API -> table. Returns the status or '' on API error.
         */
        /** Last GET /payments/{id} answer of recheckPayment() (status, amount, provider ...) */
        public $lastApiStatus = [];

        public function recheckPayment(string $transactionId): string
        {
            global $db;
            $result = $this->getPaymentStatus($transactionId);
            $this->lastApiStatus = is_array($result) ? $result : [];
            if (!is_array($result) || empty($result['status'])) {
                return '';
            }
            $this->setPaymentStatus($transactionId, (string)$result['status'], (string)($result['provider'] ?? ''));
            $sql = "UPDATE " . $this->paymentsTable() . " SET last_checked = now() WHERE transaction_id = :tx";
            $db->Execute($db->bindVars($sql, ':tx', $transactionId, 'string'));
            return (string)$result['status'];
        }

        /**
         * Callback (server to server): status from Paytrail.
         * - order exists: pending -> ok updates the order status, fail adds a warning
         * - paid but NO order (customer did not come back, OP mobile app, other browser):
         *   e-mail to the store owner once
         * Callbacks may come several times, so this is idempotent.
         */
        public function updateOrderFromCallback(string $transactionId, string $status, string $provider = '')
        {
            $row = $this->getPayment($transactionId);
            if ($row === null || $status === '') {
                return;
            }
            $oldStatus = $row['status'];
            $ordersId  = (int)$row['orders_id'];

            if ($ordersId > 0 && $oldStatus !== $status && !in_array($oldStatus, ['ok', 'cancelled'], true)) {
                if ($status === 'ok') {
                    zen_update_orders_history($ordersId, MODULE_PAYMENT_PAYTRAIL_TITLE_STATUS . MODULE_PAYMENT_PAYTRAIL_PAYMENT_OK . ' (Paytrail callback)', 'Paytrail', $this->paidOrderStatusId(), 0);
                } elseif ($status === 'fail') {
                    zen_update_orders_history($ordersId, MODULE_PAYMENT_PAYTRAIL_TITLE_STATUS . MODULE_PAYMENT_PAYTRAIL_PAYMENT_FAIL . ' (Paytrail callback) - ' . MODULE_PAYMENT_PAYTRAIL_CHECK_ORDER, 'Paytrail', -1, 0);
                }
            }

            $this->setPaymentStatus($transactionId, $status, $provider);

            if ($ordersId === 0 && in_array($status, ['ok', 'pending', 'delayed'], true) && (int)$row['admin_notified'] === 0) {
                $row['status']   = $status;
                $row['provider'] = $provider !== '' ? $provider : $row['provider'];
                $this->notifyPaidWithoutOrder($row);
            }
        }

        /**
         * E-mail to the store owner: payment received but the order was not created.
         */
        protected function notifyPaidWithoutOrder(array $row)
        {
            global $db;
            $db->Execute("UPDATE " . $this->paymentsTable() . " SET admin_notified = 1 WHERE id = " . (int)$row['id']);

            $lines   = [];
            $lines[] = MODULE_PAYMENT_PAYTRAIL_NO_ORDER_INTRO;
            $lines[] = '';
            $lines[] = 'Transaction ID: ' . $row['transaction_id'];
            $lines[] = MODULE_PAYMENT_PAYTRAIL_REFERENCE_NUMBER . $this->paytrailReference($row);
            $lines[] = MODULE_PAYMENT_PAYTRAIL_TITLE_STATUS . $row['status'] . '  /  ' . MODULE_PAYMENT_PAYTRAIL_PAYMENT_METHOD . $row['provider'];
            $lines[] = MODULE_PAYMENT_PAYTRAIL_NO_ORDER_AMOUNT . number_format($row['amount'] / 100, 2, ',', ' ') . ' ' . $row['currency'];
            $lines[] = MODULE_PAYMENT_PAYTRAIL_NO_ORDER_CUSTOMER . $row['customer_name'] . ' <' . $row['customer_email'] . '> (ID ' . (int)$row['customers_id'] . ')';
            $lines[] = MODULE_PAYMENT_PAYTRAIL_NO_ORDER_CREATED . $row['date_added'];

            $snapshot = json_decode((string)$row['cart_snapshot'], true);
            if (is_array($snapshot)) {
                $lines[] = '';
                if (!empty($snapshot['telephone'])) $lines[] = 'Tel: ' . $snapshot['telephone'];
                if (!empty($snapshot['delivery']))  $lines[] = MODULE_PAYMENT_PAYTRAIL_NO_ORDER_DELIVERY . $snapshot['delivery'];
                if (!empty($snapshot['shipping']))  $lines[] = MODULE_PAYMENT_PAYTRAIL_NO_ORDER_SHIPPING . $snapshot['shipping'];
                $lines[] = '';
                foreach ((array)($snapshot['products'] ?? []) as $product) {
                    $line = $product['qty'] . ' x ' . $product['name'] . ' - ' . number_format((float)$product['price'], 2, ',', ' ') . ' EUR';
                    if (!empty($product['attributes'])) {
                        $line .= ' (' . implode(', ', $product['attributes']) . ')';
                    }
                    $lines[] = $line;
                }
                if (!empty($snapshot['comments'])) {
                    $lines[] = '';
                    $lines[] = $snapshot['comments'];
                }
            }
            $lines[] = '';
            $lines[] = MODULE_PAYMENT_PAYTRAIL_NO_ORDER_ACTION;

            $text = implode("\n", $lines);
            if (function_exists('zen_mail')) {
                zen_mail(STORE_NAME, STORE_OWNER_EMAIL_ADDRESS, MODULE_PAYMENT_PAYTRAIL_NO_ORDER_SUBJECT . ' ' . $this->paytrailReference($row), $text, STORE_NAME, EMAIL_FROM, ['EMAIL_MESSAGE_HTML' => nl2br(zen_output_string_protected($text))], 'default');
            }
            error_log('Paytrail: payment ' . $row['transaction_id'] . ' (' . $row['status'] . ') has no order - store owner notified');
        }

        /**
         * Final order total in cents as the customer will see it on the confirmation page.
         * On checkout_payment selection() runs BEFORE the order total modules have been
         * processed, so $order->info['total'] does not yet contain coupons, gift vouchers,
         * reward points or fees. Run them here and restore everything afterwards
         * (order info + each module's output, otherwise the totals box shows lines twice).
         */
        protected function currentOrderTotalCents(): int
        {
            global $order, $order_total_modules;
            if (!is_object($order)) {
                return 0;
            }
            $total = (float)($order->info['total'] ?? 0);
            if (is_object($order_total_modules) && method_exists($order_total_modules, 'process') && !empty($order_total_modules->modules)) {
                $savedInfo    = $order->info;
                $savedOutputs = [];
                foreach ($order_total_modules->modules as $value) {
                    $class = pathinfo($value, PATHINFO_FILENAME);
                    if (isset($GLOBALS[$class]) && is_object($GLOBALS[$class]) && property_exists($GLOBALS[$class], 'output')) {
                        $savedOutputs[$class] = $GLOBALS[$class]->output;
                    }
                }
                try {
                    foreach ($order_total_modules->process() as $line) {
                        if (($line['code'] ?? '') === 'ot_total') {
                            $total = (float)$line['value'];
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('Paytrail: order total calculation failed: ' . $e->getMessage());
                }
                $order->info = $savedInfo;
                foreach ($savedOutputs as $class => $output) {
                    $GLOBALS[$class]->output = $output;
                }
            }
            return (int)round($total * 100);
        }

        /**
         * Admin "Place Order" (EMP) for a paid payment without an order: put the checkout
         * state of the original payment back into the session once (shipping, coupon,
         * gift voucher, reward points, comments, addresses) and reload checkout_payment,
         * so the order total is calculated exactly like when the customer paid.
         */
        protected function restoreEmpCheckoutState(array $empList)
        {
            foreach ($empList as $transactionId) {
                if (!empty($_SESSION['paytrail_emp_restored'][$transactionId])) {
                    continue;
                }
                $row = $this->getPayment($transactionId);
                $snapshot = ($row !== null) ? json_decode((string)$row['cart_snapshot'], true) : null;
                $restore  = $snapshot['full']['restore'] ?? null;
                $_SESSION['paytrail_emp_restored'][$transactionId] = true;
                if (!is_array($restore)) {
                    continue; // payment saved before v5.8.1: only the basket is available
                }
                foreach (['shipping', 'cc_id', 'cot_gv', 'redeem_points', 'redeem_value', 'comments', 'sendto', 'billto'] as $key) {
                    if (array_key_exists($key, $restore) && $restore[$key] !== null && $restore[$key] !== '') {
                        $_SESSION[$key] = $restore[$key];
                    } else {
                        unset($_SESSION[$key]);
                    }
                }
                zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
            }
        }

        /**
         * Customer is back on checkout_payment in the same session: was one of the
         * session's payments paid after all (OP mobile app etc.)? Then create the order.
         */
        protected function recoverPaidPayment()
        {
            global $order, $messageStack;
            static $done = false;
            $empList = $this->empPaidWithoutOrder();
            if ($done || (empty($_SESSION['paytrail_transactions']) && empty($empList))) {
                return;
            }
            $done  = true;
            $tried = $_SESSION['paytrail_recover_tried'] ?? [];
            $checks = 0;
            $isEmp  = !empty($empList);
            if ($isEmp) {
                $this->restoreEmpCheckoutState($empList);
            }
            $cartAmount = null;

            $candidates = array_unique(array_merge($empList, array_reverse($_SESSION['paytrail_transactions'] ?? [])));
            foreach ($candidates as $transactionId) {
                if (isset($tried[$transactionId])) {
                    continue;
                }
                $row = $this->getPayment($transactionId);
                if ($row === null || (int)$row['orders_id'] > 0) {
                    continue;
                }
                $status = $row['status'];
                // Not known yet: ask Paytrail (max 3 recent payments, younger than 1 hour)
                if ($status === 'new' && $checks < 3 && strtotime($row['date_added']) > time() - 3600) {
                    $checks++;
                    $status = $this->recheckPayment($transactionId) ?: $status;
                }
                if (!in_array($status, ['ok', 'pending', 'delayed'], true)) {
                    continue;
                }
                if ($cartAmount === null) {
                    $cartAmount = $this->currentOrderTotalCents();
                }
                if ($cartAmount === (int)$row['amount']) {
                    $_SESSION['paytrail_recover_tried'][$transactionId] = true;
                    zen_redirect(zen_href_link(FILENAME_CHECKOUT_PROCESS, 'paytrail_tx=' . urlencode($transactionId), 'SSL', true, false));
                }
                // Admin as customer: tell why, can be retried (e.g. choose the same shipping method)
                if ($isEmp && in_array($transactionId, $empList, true)) {
                    if (is_object($messageStack)) {
                        $messageStack->add('checkout_payment', sprintf(MODULE_PAYMENT_PAYTRAIL_EMP_AMOUNT_DIFFERS, $this->paytrailReference($row), number_format($row['amount'] / 100, 2, ',', ' '), number_format($cartAmount / 100, 2, ',', ' ')), 'caution');
                    }
                    continue;
                }
                $_SESSION['paytrail_recover_tried'][$transactionId] = true;
                // Cart changed after the payment: do not create a wrong order automatically
                if (is_object($messageStack)) {
                    $messageStack->add('checkout_payment', sprintf(MODULE_PAYMENT_PAYTRAIL_PAID_CART_CHANGED, $this->paytrailReference($row)), 'caution');
                }
                if ((int)$row['admin_notified'] === 0) {
                    $this->notifyPaidWithoutOrder($row);
                }
            }
        }

        /**
         * Admin order page: payment info + activation / cancel buttons for pending (invoice) payments.
         * Zen Cart admin orders.php shows admin_notification() and runs the doCapture / doVoid actions.
         */
        function admin_notification($zf_order_id)
        {
            $row = $this->getPaymentByOrder((int)$zf_order_id);
            if ($row === null) {
                return '';
            }
            $html  = '<div class="paytrail-admin" style="border:1px solid #ccc; padding:10px; margin:10px 0">';
            $html .= '<strong>Paytrail</strong> &nbsp; Transaction ID: ' . zen_output_string_protected($row['transaction_id'])
                   . ' &nbsp; ' . MODULE_PAYMENT_PAYTRAIL_TITLE_STATUS . '<b>' . zen_output_string_protected($row['status']) . '</b>'
                   . ($row['provider'] !== '' ? ' &nbsp; (' . zen_output_string_protected($row['provider']) . ')' : '')
                   . ' &nbsp; ' . MODULE_PAYMENT_PAYTRAIL_REFERENCE_NUMBER . zen_output_string_protected($this->paytrailReference($row))
                   . ' &nbsp; ' . number_format($row['amount'] / 100, 2, ',', ' ') . ' ' . zen_output_string_protected($row['currency']);

            if (in_array($row['status'], ['pending', 'delayed'], true)) {
                $html .= '<br><br>' . MODULE_PAYMENT_PAYTRAIL_ADMIN_PENDING_INFO . '<br>';
                $html .= zen_draw_form('paytrail_capture', FILENAME_ORDERS, zen_get_all_get_params(['action']) . 'action=doCapture', 'post', 'style="display:inline" onsubmit="return confirm(\'' . addslashes(MODULE_PAYMENT_PAYTRAIL_ADMIN_ACTIVATE_CONFIRM) . '\');"');
                $html .= '<button type="submit" class="btn btn-success btn-sm">' . MODULE_PAYMENT_PAYTRAIL_ADMIN_ACTIVATE . '</button></form> &nbsp; ';
                $html .= zen_draw_form('paytrail_void', FILENAME_ORDERS, zen_get_all_get_params(['action']) . 'action=doVoid', 'post', 'style="display:inline" onsubmit="return confirm(\'' . addslashes(MODULE_PAYMENT_PAYTRAIL_ADMIN_CANCEL_CONFIRM) . '\');"');
                $html .= '<button type="submit" class="btn btn-danger btn-sm">' . MODULE_PAYMENT_PAYTRAIL_ADMIN_CANCEL . '</button></form>';
                $html .= '<br><small>' . MODULE_PAYMENT_PAYTRAIL_ADMIN_CANCEL_INFO . '</small>';
            }
            $html .= '</div>';
            return $html;
        }

        protected function adminName(): string
        {
            return (isset($_SESSION['admin_id']) && function_exists('zen_get_admin_name')) ? (string)zen_get_admin_name($_SESSION['admin_id']) : 'Admin';
        }

        /**
         * Admin: activate (capture) a pending invoice payment.  POST /payments/{id}/capture-order
         */
        function _doCapt($oID, $captureType = 'Complete', $amt = 0, $currency = 'EUR', $note = '')
        {
            global $messageStack;
            $row = $this->getPaymentByOrder((int)$oID);
            if ($row === null || !in_array($row['status'], ['pending', 'delayed'], true)) {
                $messageStack->add_session(MODULE_PAYMENT_PAYTRAIL_ADMIN_NOT_PENDING, 'error');
                return false;
            }
            $tx     = $row['transaction_id'];
            $result = $this->apiPost('/payments/' . rawurlencode($tx) . '/capture-order', '', $tx);

            if ($result['ok'] && $result['code'] == 200 && (($result['data']->status ?? '') === 'ok')) {
                $this->setPaymentStatus($tx, 'ok', '', 0, true);
                zen_update_orders_history((int)$oID, MODULE_PAYMENT_PAYTRAIL_TITLE_STATUS . MODULE_PAYMENT_PAYTRAIL_PAYMENT_ACTIVATED, $this->adminName(), $this->paidOrderStatusId(), 0);
                $messageStack->add_session(MODULE_PAYMENT_PAYTRAIL_PAYMENT_ACTIVATED, 'success');
                return true;
            }
            $msg = $result['data']->message ?? ('HTTP ' . $result['code']);
            error_log('Paytrail capture-order failed: ' . substr($result['raw'], 0, 500));
            $messageStack->add_session(MODULE_PAYMENT_PAYTRAIL_ADMIN_ACTIVATE_FAILED . ' ' . zen_output_string_protected((string)$msg), 'error');
            return false;
        }

        /**
         * Admin: cancel a pending Klarna payment (releases the authorization hold).  POST /payments/{id}/cancel-order
         * Walley invoices have no authorization hold: they are simply not activated.
         */
        function _doVoid($oID, $note = '')
        {
            global $messageStack;
            $row = $this->getPaymentByOrder((int)$oID);
            if ($row === null || !in_array($row['status'], ['pending', 'delayed'], true)) {
                $messageStack->add_session(MODULE_PAYMENT_PAYTRAIL_ADMIN_NOT_PENDING, 'error');
                return false;
            }
            $tx     = $row['transaction_id'];
            $result = $this->apiPost('/payments/' . rawurlencode($tx) . '/cancel-order', '', $tx);

            if ($result['ok'] && in_array((int)$result['code'], [200, 201, 202], true)) {
                $this->setPaymentStatus($tx, 'cancelled', '', 0, true);
                zen_update_orders_history((int)$oID, MODULE_PAYMENT_PAYTRAIL_TITLE_STATUS . MODULE_PAYMENT_PAYTRAIL_PAYMENT_CANCELLED, $this->adminName(), -1, 0);
                $messageStack->add_session(MODULE_PAYMENT_PAYTRAIL_PAYMENT_CANCELLED, 'success');
                return true;
            }
            $msg = $result['data']->message ?? ('HTTP ' . $result['code']);
            error_log('Paytrail cancel-order failed: ' . substr($result['raw'], 0, 500));
            $messageStack->add_session(MODULE_PAYMENT_PAYTRAIL_ADMIN_CANCEL_FAILED . ' ' . zen_output_string_protected((string)$msg), 'error');
            return false;
        }

        // ---------------- Saved cards (DB) ----------------

        public function cardSavingEnabled(): bool
        {
            return defined('MODULE_PAYMENT_PAYTRAIL_SAVE_CARD') && MODULE_PAYMENT_PAYTRAIL_SAVE_CARD == 'True';
        }

        public function getLoggedInCustomerId(): int
        {
            if (empty($_SESSION['customer_id'])) {
                return 0;
            }
            // Guest checkout (OPC) customers can not save cards
            if (!empty($_SESSION['COWOA']) || (function_exists('zen_in_guest_checkout') && zen_in_guest_checkout())) {
                return 0;
            }
            return (int)$_SESSION['customer_id'];
        }

        public function ensureCardTable()
        {
            global $db;
            static $done = false;
            if ($done) {
                return;
            }
            $db->Execute("CREATE TABLE IF NOT EXISTS " . $this->cardsTable . " (
                id int(11) NOT NULL AUTO_INCREMENT,
                customers_id int(11) NOT NULL,
                token varchar(255) NOT NULL,
                card_type varchar(32) NOT NULL DEFAULT '',
                partial_pan varchar(4) NOT NULL DEFAULT '',
                expire_month varchar(2) NOT NULL DEFAULT '',
                expire_year varchar(4) NOT NULL DEFAULT '',
                card_fingerprint varchar(64) NOT NULL DEFAULT '',
                date_added datetime NOT NULL,
                PRIMARY KEY (id),
                KEY idx_customers_id (customers_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->Execute("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "paytrail_payments (
                id int(11) NOT NULL AUTO_INCREMENT,
                transaction_id varchar(64) NOT NULL,
                stamp varchar(64) NOT NULL DEFAULT '',
                reference varchar(32) NOT NULL DEFAULT '',
                bank_reference varchar(32) NOT NULL DEFAULT '',
                customers_id int(11) NOT NULL DEFAULT 0,
                customer_email varchar(96) NOT NULL DEFAULT '',
                customer_name varchar(128) NOT NULL DEFAULT '',
                orders_id int(11) NOT NULL DEFAULT 0,
                amount int(11) NOT NULL DEFAULT 0,
                currency char(3) NOT NULL DEFAULT 'EUR',
                method varchar(16) NOT NULL DEFAULT 'payment',
                provider varchar(64) NOT NULL DEFAULT '',
                status varchar(32) NOT NULL DEFAULT 'new',
                cart_snapshot mediumtext,
                session_id varchar(128) NOT NULL DEFAULT '',
                admin_notified tinyint(1) NOT NULL DEFAULT 0,
                date_added datetime NOT NULL,
                last_modified datetime NOT NULL,
                last_checked datetime NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_transaction_id (transaction_id),
                KEY idx_customers_id (customers_id),
                KEY idx_orders_id (orders_id),
                KEY idx_status (status, orders_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // v5.8.0 upgrade: Paytrail bank reference column (tables created before it existed)
            $col = $db->Execute("SHOW COLUMNS FROM " . DB_PREFIX . "paytrail_payments LIKE 'bank_reference'");
            if ($col->EOF) {
                $db->Execute("ALTER TABLE " . DB_PREFIX . "paytrail_payments ADD bank_reference varchar(32) NOT NULL DEFAULT '' AFTER reference");
            }
            $done = true;
        }

        public function getCustomerCards(int $customerId): array
        {
            global $db;
            $this->ensureCardTable();
            $cards  = [];
            $result = $db->Execute("SELECT * FROM " . $this->cardsTable . " WHERE customers_id = " . (int)$customerId . " ORDER BY date_added DESC");
            while (!$result->EOF) {
                if (!$this->cardExpired($result->fields)) {
                    $cards[] = $result->fields;
                }
                $result->MoveNext();
            }
            return $cards;
        }

        public function getCustomerCard(int $customerId, int $cardId): ?array
        {
            global $db;
            $this->ensureCardTable();
            $result = $db->Execute("SELECT * FROM " . $this->cardsTable . " WHERE id = " . (int)$cardId . " AND customers_id = " . (int)$customerId . " LIMIT 1");
            if ($result->EOF || $this->cardExpired($result->fields)) {
                return null;
            }
            return $result->fields;
        }

        /**
         * Save card token. Same card (fingerprint) is updated, not duplicated.
         */
        public function saveCustomerCard(int $customerId, string $token, array $card)
        {
            global $db;
            if ($customerId < 1 || $token === '') {
                return;
            }
            $this->ensureCardTable();

            $data = [
                'card_type'        => substr(preg_replace('/[^A-Za-z0-9 _-]/', '', (string)($card['type'] ?? '')), 0, 32),
                'partial_pan'      => substr(preg_replace('/\D/', '', (string)($card['partial_pan'] ?? '')), -4),
                'expire_month'     => substr(preg_replace('/\D/', '', (string)($card['expire_month'] ?? '')), 0, 2),
                'expire_year'      => substr(preg_replace('/\D/', '', (string)($card['expire_year'] ?? '')), 0, 4),
                'card_fingerprint' => substr(preg_replace('/[^A-Fa-f0-9]/', '', (string)($card['card_fingerprint'] ?? '')), 0, 64),
            ];

            $existing = null;
            $sql = "SELECT id FROM " . $this->cardsTable . " WHERE customers_id = :cid AND (token = :token" . ($data['card_fingerprint'] !== '' ? " OR card_fingerprint = :fp" : "") . ") LIMIT 1";
            $sql = $db->bindVars($sql, ':cid', $customerId, 'integer');
            $sql = $db->bindVars($sql, ':token', $token, 'string');
            $sql = $db->bindVars($sql, ':fp', $data['card_fingerprint'], 'string');
            $check = $db->Execute($sql);
            if (!$check->EOF) {
                $existing = (int)$check->fields['id'];
            }

            $sqlData = [
                ['fieldName' => 'customers_id',     'value' => $customerId,               'type' => 'integer'],
                ['fieldName' => 'token',            'value' => $token,                    'type' => 'string'],
                ['fieldName' => 'card_type',        'value' => $data['card_type'],        'type' => 'string'],
                ['fieldName' => 'partial_pan',      'value' => $data['partial_pan'],      'type' => 'string'],
                ['fieldName' => 'expire_month',     'value' => $data['expire_month'],     'type' => 'string'],
                ['fieldName' => 'expire_year',      'value' => $data['expire_year'],      'type' => 'string'],
                ['fieldName' => 'card_fingerprint', 'value' => $data['card_fingerprint'], 'type' => 'string'],
                ['fieldName' => 'date_added',       'value' => 'now()',                   'type' => 'noquotestring'],
            ];

            if ($existing) {
                $db->perform($this->cardsTable, $sqlData, 'UPDATE', 'id = ' . $existing);
            } else {
                $db->perform($this->cardsTable, $sqlData);
            }
        }

        public function deleteCustomerCard(int $customerId, int $cardId)
        {
            global $db;
            $this->ensureCardTable();
            $db->Execute("DELETE FROM " . $this->cardsTable . " WHERE id = " . (int)$cardId . " AND customers_id = " . (int)$customerId);
        }

        protected function cardExpired(array $card): bool
        {
            $year  = (int)($card['expire_year'] ?? 0);
            $month = (int)($card['expire_month'] ?? 0);
            if ($year < 1 || $month < 1) {
                return false; // unknown expiry
            }
            if ($year < 100) {
                $year += 2000;
            }
            return ($year * 100 + $month) < (int)date('Ym');
        }

        public function cardLabel(array $card): string
        {
            $label = trim(($card['card_type'] ?? '') . ' **** ' . ($card['partial_pan'] ?? ''));
            if (!empty($card['expire_month']) && !empty($card['expire_year'])) {
                $label .= ' (' . str_pad($card['expire_month'], 2, '0', STR_PAD_LEFT) . '/' . substr($card['expire_year'], -2) . ')';
            }
            return zen_output_string_protected($label);
        }

		// Stamp random trans id
		public function generate_uuid() 
		{
			return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
						   mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
						   mt_rand( 0, 0xffff ),
						   mt_rand( 0, 0x0C2f ) | 0x4000,
						   mt_rand( 0, 0x3fff ) | 0x8000,
						   mt_rand( 0, 0x2Aff ), mt_rand( 0, 0xffD3 ), mt_rand( 0, 0xff4B )
						);
		}

        // Order number and Reference Number
        public function createOrderNumber(string $prefix): string
        {
            $random = str_pad((string) random_int(0, 99999999999), 12, '0', STR_PAD_LEFT);            
            return $prefix . $random;
        }

        public function calculateHmac($params = [], $body = '', $secretKey = '') 
		{
           // Keep only checkout- params, more relevant for response validation.
           $includedKeys = array_filter(array_keys($params), function ($key) {
           return preg_match('/^checkout-/', $key);
           });

           // Keys must be sorted alphabetically
           sort($includedKeys, SORT_STRING);

            $hmacPayload = array_map(
            function ($key) use ($params) {
              // Responses have headers in an array.
              $param = is_array($params[ $key ]) ? $params[ $key ][0] : $params[ $key ];
              return join(':', [ $key, $param ]);
            }, $includedKeys );
            array_push($hmacPayload, $body);
            return hash_hmac('sha256', join("\n", $hmacPayload), $secretKey);		
        }

        public function validateHmac(array $params = [], string $body = '', string $signature = '', string $secretKey = ''): bool 
		{
           if ($signature === '') {
               return false;
           }
           $hmac = $this->calculateHmac($params, $body, $secretKey);  
           return hash_equals($hmac, $signature);
        }

        /**
         * Check signature of redirect / callback query string parameters (empty body).
         */
        public function validateRedirectSignature(array $params): bool
        {
            $signature = isset($params['signature']) ? (string)$params['signature'] : '';
            $checkoutParams = [];
            foreach ($params as $key => $value) {
                if (is_string($key) && strpos($key, 'checkout-') === 0 && !is_array($value)) {
                    $checkoutParams[$key] = (string)$value;
                }
            }
            if (($checkoutParams['checkout-account'] ?? '') !== (string)$this->merchantId) {
                return false;
            }
            return $this->validateHmac($checkoutParams, '', $signature, (string)$this->secretKey);
        }
  
        /**
        * Get Paytrail payment status
        */
        public function getPaymentStatus($transactionId) {
            $url = $this->apiBase . '/payments/' . rawurlencode($transactionId);
            $headers = $this->getHeaders('GET',$transactionId);

            $headers['signature'] = $this->calculateHmac($headers,'',$this->secretKey);

            $client = new \GuzzleHttp\Client([
                'headers'     => $headers,
                'http_errors' => false,
                'timeout'     => 15,
            ]);

            try {
                $response = $client->get($url);
                $body = (string)$response->getBody();

                $responseHeaders = [];
                foreach ($response->getHeaders() as $name => $values) {
                    $responseHeaders[strtolower($name)] = $values[0] ?? '';
                }
                if (!$this->validateHmac($responseHeaders, $body, $responseHeaders['signature'] ?? '', (string)$this->secretKey)) {
                    error_log('Paytrail status: response HMAC signature mismatch');
                    return false;
                }
                if ($response->getStatusCode() != 200) {
                    error_log('Paytrail status: HTTP ' . $response->getStatusCode() . ' ' . substr($body, 0, 300));
                    return false;
                }

                return json_decode($body, true);

            } catch(\Throwable $e){
                error_log("Paytrail status error: ".$e->getMessage());

                return false;
            }
        } 
	
        /**
         * Paytrail waiting page for payment status 
         */
        public function waitPage()
        {
            $transactionId = zen_db_prepare_input(
                $_GET['checkout-transaction-id'] ?? ''
            );

            $transactionIdJs = json_encode(
                $transactionId,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
            );

            // After a successful check the order is created in checkout_process,
            // where the transaction is verified again server side with the Paytrail API.
            $successAddressJs = json_encode(
                zen_href_link(FILENAME_CHECKOUT_PROCESS, 'paytrail_tx=' . urlencode($transactionId), 'SSL', true, false),
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
            );

            $cancelAddressJs = json_encode(
                $this->cancel_address,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
            );

            echo '
            <!DOCTYPE html>
            <html lang="' . ($this->language == 'FI' ? 'fi' : 'en') . '">
            <head>
                <meta charset="utf-8">
                <title>' .MODULE_PAYMENT_PAYTRAIL_PAYMENT_STATUS_TITLE .'</title>
                <style>
                    body {
                        font-family: Arial, Helvetica, sans-serif;
                        background: #f5f5f5;
                        text-align: center;
                        margin-top: 120px;
                    }

                    .box {
                        width: 500px;
                        margin: auto;
                        padding: 30px;
                        background: #fff;
                        border-radius: 8px;
                        box-shadow: 0 0 10px rgba(0, 0, 0, .15);
                    }

                    #counter {
                        font-size: 42px;
                        font-weight: bold;
                        color: #0073aa;
                        margin-top: 20px;
                    }

                    .retry-button {
                        margin-top: 15px;
                        padding: 10px 20px;
                        border: 0;
                        border-radius: 5px;
                        background: #0073aa;
                        color: #fff;
                        font-size: 16px;
                        cursor: pointer;
                    }
                </style>
            </head>
            <body>
                <div class="box">
                    <h2>' .MODULE_PAYMENT_PAYTRAIL_RETURN_PAYMENT_CHECKING .'</h2>
                    <p>'. MODULE_PAYMENT_PAYTRAIL_RETURN_PAYMENT_CHECKING_INFO .'</p>
                    <div id="counter">' . (int)$this->statusWaitSeconds . '</div>
                </div>

                <script>
                    const transactionId = ' . $transactionIdJs . ';
                    const successAddress = ' . $successAddressJs . ';
                    const cancelAddress = ' . $cancelAddressJs . ';
                    const statusUrl = ' . json_encode(zen_href_link('paytrail_status', '', 'SSL', true, false), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . ';

                    let second = ' . (int)$this->statusWaitSeconds . ';
                    let checking = false;
                    let finished = false;

                    function showFailMessage() {
                        finished = true;

                        document.querySelector(".box").innerHTML =
                            "<h2>'.MODULE_PAYMENT_PAYTRAIL_RETURN_PAYMENT_FAIL .'</h2>" +
                            "<p>' .MODULE_PAYMENT_PAYTRAIL_RETURN_PAYMENT_FAIL_INFO .'</p>" +
                            \'<button type="button" class="retry-button" id="retryPayment">' .MODULE_PAYMENT_PAYTRAIL_RETURN_PAYMENT_SELECT_BUTTON .'</button>\';

                        document.querySelector("#retryPayment").addEventListener(
                            "click",
                            function () {
                                window.location.href = cancelAddress;
                            }
                        );
                    }

                    function checkStatus(finalCheck = false) {
                        if (checking || finished) {
                            return;
                        }

                        checking = true;

                        fetch(
                            statusUrl + "&transactionId=" +
                            encodeURIComponent(transactionId)
                        )
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (data) {
                            console.log(data);

                            // ok = paid, pending/delayed = approved, waiting activation/processing
                            if (["ok", "pending", "delayed"].indexOf(data["checkout-status"]) !== -1) {
                                finished = true;
                                window.location.href = successAddress;
                                return;
                            }

                            // If the final check is also unsuccessful, display the error screen.
                            if (finalCheck) {
                                showFailMessage();
                            }
                        })
                        .catch(function (error) {
                            console.log("Paytrail AJAX ERROR:", error);

                            if (finalCheck) {
                                showFailMessage();
                            }
                        })
                        .finally(function () {
                            checking = false;
                        });
                    }

                    // The first check is performed as soon as the page opens.
                    checkStatus();

                    const timer = setInterval(function () {
                        if (finished) {
                            clearInterval(timer);
                            return;
                        }

                        second--;
                        document.getElementById("counter").innerHTML = second;

                        // Checked every 10 seconds (20 and 10 seconds left)
                        if (second > 0 && second % 10 === 0) {
                            checkStatus();
                        }

                        // Checked one last time when the 30 seconds are over.
                        if (second <= 0) {
                            clearInterval(timer);
                            checkStatus(true);
                        }
                    }, 1000);
                </script>
            </body>
            </html>
            ';

            exit;
        }

} // end class paytrail