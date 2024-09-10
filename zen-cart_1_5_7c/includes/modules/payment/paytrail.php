<?php
/**
 * www.paytrail.com (Finland)
 *
 * Use Guzzle HTTP client v6 installed with Composer https://github.com/guzzle/guzzle/
 *
 * REQUIRES PHP version >= 7.4
 * 
 * Use Guzzle HTTP client v6 installed with Composer https://github.com/guzzle/guzzle/
 * We recommend using Guzzle HTTP client through composer as default HTTP client for PHP because it has
 * well documented and nice api. You can use any HTTP library to connect into Paytrail API.
 * Alternatively, if you can't install composer packages you can use http://php.net/manual/en/book.curl.php	
 *
 * @package payment
 * @copyright Copyright 2003-2019 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Nida Verkkopalvelu (www.nida.fi) / krbuk 2024 Aug 28 Modified in v1.57c $
 */
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Client as GuzzleHttpClient;

require DIR_FS_CATALOG .DIR_WS_CLASSES . 'vendors/paytrail/autoload.php';
	
class paytrail
{
  var $code, $title, $description, $enabled, $sort_order;
  private $allowed_currencies = array('EUR');	
  public $moduleVersion = '4.8';
  protected $PaytrailApiVersion = '1.57c';	
	
  function __construct()	
  {
    global $order;	
    $this->code = 'paytrail';
    $this->title = defined('MODULE_PAYMENT_PAYTRAIL_TEXT_TITLE') ? MODULE_PAYMENT_PAYTRAIL_TEXT_TITLE : null;	
    $this->description = '<strong>Paytrail version -v' . $this->moduleVersion . '</strong><br><br>' .MODULE_PAYMENT_PAYTRAIL_TEXT_DESCRIPTION;
    $this->enabled  = (defined('MODULE_PAYMENT_PAYTRAIL_STATUS') && MODULE_PAYMENT_PAYTRAIL_STATUS == 'Kyllä') ? true : false;		
    $this->sort_order = defined('MODULE_PAYMENT_PAYTRAIL_SORT_ORDER') ? MODULE_PAYMENT_PAYTRAIL_SORT_ORDER : null;
    $this->form_action_url = "https://services.paytrail.com/payments";
    $this->merchantId = defined('MODULE_PAYMENT_PAYTRAIL_KAUPPIAS') ? MODULE_PAYMENT_PAYTRAIL_KAUPPIAS : null;
    $this->privateKey = defined('MODULE_PAYMENT_PAYTRAIL_TURVA_AVAIN') ? MODULE_PAYMENT_PAYTRAIL_TURVA_AVAIN : null;			
    $this->shop_in_shop_merchant_id = defined('MODULE_PAYMENT_PAYTRAIL_PAAMYYJA') ? MODULE_PAYMENT_PAYTRAIL_PAAMYYJA : null;
    $this->return_address = zen_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL');
    $this->cancel_address = zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL');
    $this->currency = $order->info['currency'];			
    $this->language = ($_SESSION['languages_code'] == 'fi') ? 'FI' : 'EN';
    $this->order_status = defined('MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID_SETTLED') ? MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID_SETTLED : null;			
    
    // Client header
    $stack = $this->createLoggerStack($args);
    $this->http_client = new GuzzleHttpClient
    (
      [
        'headers'  => [],
        'base_uri' => $this->form_action_url,
        'timeout'  => $args['timeout'] ?? 10,
        'handler'  => $stack,
      ]
    );
    if (null === $this->sort_order) return false;	
      if (IS_ADMIN_FLAG === true && ((defined('MODULE_PAYMENT_PAYTRAIL_KAUPPIAS') && MODULE_PAYMENT_PAYTRAIL_KAUPPIAS == '375917') || 	(defined('MODULE_PAYMENT_PAYTRAIL_KAUPPIAS') && MODULE_PAYMENT_PAYTRAIL_KAUPPIAS == '695861') || (defined('MODULE_PAYMENT_PAYTRAIL_PAAMYYJA') && MODULE_PAYMENT_PAYTRAIL_PAAMYYJA  == '695874'))) $this->title .= '<span class="alert">' .MODULE_PAYMENT_PAYTRAIL_ALERT_TEST .'</span>';
        
      // determine order-status for transactions
      if ((int)defined('MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID_SETTLED') && MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID_SETTLED > 0)
	{
          $this->order_status = MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID_SETTLED;
	}

      // check for zone compliance and any other conditionals
      if(is_object($order)) $this->update_status();
  }
  function update_status()
  {
    global $order; $zones_to_geo_zones; 
      
    //Only EUR orders accepted
    $currency = $order->info['currency'];
    if(!(in_array($currency, $this->allowed_currencies)))
    $this->enabled = false;
  }	
	
  function javascript_validation()
  {
  }

  function selection()
  {
    return array('id' => $this->code, 'module' => $this->title);
  }

  function pre_confirmation_check()
  {
    return false;
  }

  function confirmation()
  {
    return false;
  }
	
  function check()
  {
    global $db;
    if (!isset($this->_check))
    {
      $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_PAYTRAIL_STATUS'");
      $this->_check = $check_query->RecordCount();
    }
    return $this->_check;
  }
	
  function process_button()
  {
    global $order, $currencies, $db, $order_totals;
		
    //Create a randomized order number and order stamp'
    $number_rand = time().rand(1,9);
    $order_number = str_pad($number_rand, 12, "1", STR_PAD_RIGHT);
    $this->order_number = $order_number;
	
    // Order amount
    $decimals = $currencies->get_decimal_places($_SESSION['currency']);
    $amount = number_format($order->info['total'], 2, '.', '')*100;
    $this->amount = intval($amount);
    // ********************************
    // ***       Paytrail           ***
    // ********************************		
    $headers = $this->getHeaders('POST');
    $body = $this->getBody();
    $headers['signature'] = $this->calculateHmac($headers, $body, $this->privateKey);
    $client = new \GuzzleHttp\Client([ 'headers' => $headers ]);
    $response = null;
    try {
          $response = $client->post($this->form_action_url . $uri, ['body' => $body]);
	} 
        catch (\GuzzleHttp\Exception\ClientException $e) 
          {
            if ($e->hasResponse()) 
            {
              $response = $e->getResponse();
              echo "Unexpected HTTP status code: {$response->getStatusCode()}\n\n";
              echo "<div style='color:red'>" .MODULE_PAYMENT_PAYTRAIL_PAYMENT_ERROR;
              echo "<br><strong>" .MODULE_PAYMENT_PAYTRAIL_SELECET_OTHER  ."</strong>";
              echo "<div class='buttonRow back'>" .zen_back_link() . zen_image_button(BUTTON_IMAGE_BACK, BUTTON_BACK_ALT) . "</a></div></div>";
            }
          }
  
    $responseBody = $response->getBody()->getContents();
    
    // Flatten Guzzle response headers
    $responseHeaders = array_column(array_map(function ($key, $value) 
    {
      return [ $key, $value[0] ];
    },array_keys($response->getHeaders()), array_values($response->getHeaders())), 1, 0);
       
    $responseHmac = $this->calculateHmac($responseHeaders, $responseBody, $this->privateKey);
      if ($responseHmac !== $response->getHeader('signature')[0]) 
      {
	echo '<div style="color:red">'. MODULE_PAYMENT_PAYTRAIL_TEXT_API_ERROR .'</div>';
      } 
	else 
        {
          $decodedresponsebody = json_decode($responseBody);
          /**
          **	 Error control	
          **   Erase " // " and check to sending request data.
          **/	
          //  echo "\n\nRequest ID: {$response->getHeader('cof-request-id')[0]}\n\n";
          //  echo '<br>' .'Request ID: ' .$response->getHeader('request-id')[0];
          //  echo '<br>' .(json_encode(json_decode($responseBody), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
          // echo '<br><pre>'; print_r(json_decode($body,true)); exit;
	}
        
	// Starting active payment icon
	$html  = "</form>\n";
	$html .='
          <style>		
          #provider-group-switcher 
            { 
              width: 100%; 
              height:auto;
            }
          .provider-group 
            {
              float: left;
              margin-right: 8px;
              margin-bottom: 8px;
            }
	  #apple-pay-button 
            {
              display: none;
              -webkit-appearance: -apple-pay-button;
              -apple-pay-button-type: plain;
              -apple-pay-button-style: white-outline;
              height: 50px;
              width: 30%;
	    }		
          #btn_submit, .buttonRow  { display: none; } /*Submit button hidden */
          .btn-success { display: none; } /*Submit button hidden */
          </style>	
          ';
        $html .='<script src="https://services.paytrail.com/static/paytrail.js"></script>
	';
	$html .='
          <script>
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
          </script>
          ';		
	
        // Provider payment group title	
	$group_titles = 
          [
            'mobile'     => 'Mobile payment methods',
            'bank'       => 'Bank payment methods', 
            'creditcard' => 'Card payment methods', 
            'credit'     => 'Invoice and instalment payment methods',
          ];
	$html .= '';
	$html .= '<section class="payment-providers"><h2>' .MODULE_PAYMENT_PAYTRAIL_SELECET_PAYMENT .'</h2>
	';
	$html .= $decodedresponsebody->terms;	
	$html .= '
	<div id="provider-group-switcher"><br>
	';
	// Apple Pay Button
	if ($decodedresponsebody->customProviders->applepay->parameters > 0):
          $html .= '<div id="apple-pay-button">
          ';	
            foreach ($decodedresponsebody->customProviders->applepay->parameters as $parameter):
              $html .= '<input type="hidden" name="'.$parameter->name .'" value="'.$parameter->value .'">
              ';	
            endforeach;				
          $html .= '</div>
          ';				
	endif;
	
        // Provider payment group name, icon and id 	
	foreach ($decodedresponsebody->groups as $group) 
        {
          $groups[] = 
            [
              'id'  => $group->id,
              'name'=> $group->name,
              'icon'=> $group->icon,
              'svg' => $group->svg
            ];
          $html .= '<div style="clear: both"></div>';	
          $html .= '
          <h3 class="provider-group-header">
          <img src="' . $group->icon .'" alt="'. $group->name .'" title="'. $group->name.'" style="width:22px; float:left; margin-bottom: 10px;">' .$group->name .'</h3>
          ';
          // Provider name 
          foreach ($decodedresponsebody->providers  as $providersmethods) 
          {
            $allPovidersMethods[] = 
              [
                'url'         =>  $providersmethods->url,
                'icon'        =>  $providersmethods->icon,
                'svg'         =>  $providersmethods->svg,
                'name'        =>  $providersmethods->name,
                'group'       =>  $providersmethods->group,
                'id'          =>  $providersmethods->id,
                'parameters'  =>  $providersmethods->parameters
              ];	
              // Selected provider id and group 
              if ($group->id == $providersmethods->group)
              {	
                  $html .= '<div class="provider-group">
                  ';
                  $html .= '<form action="' .$providersmethods->url .'" method="POST">
                  ';
                // Provider form filesds
                foreach ($providersmethods->parameters as $parameter) 
                {
                  $formFields[] = 
                    [
                      'name'  =>  $parameter->name,
                      'value' =>  $parameter->value,			
                    ];
                  $html .= '<input type="hidden" name="'.$parameter->name .'" value="'.$parameter->value .'">
                  ';			
                } // end provider form filesds	
                  $html .= '<button class="provider-button">
                  ';
                  $html .= '<div class="button-content">
                  ';
                  $html .= '<img src="'.$providersmethods->icon .'" alt="'.$providersmethods->id .'" title="'.$providersmethods->id .'" style="width:140px; height:75px;>"
                  ';
                  $html .= '</div>
                  ';
                  $html .= '</button>
                  ';
                  $html .= '</form>
                  ';	
                  $html .= '</div>
                  ';	
                } // end  selected provider id and group 
          } // end provider name 
        } // end provider-group-header		
	$html .= '</div>
	</section>
	';
	$html .= '<div style="clear: both"></div>';
	return $html;			
} // end function process_button

function before_process()
{
  if (empty($_GET['checkout-status']))				
  {	
    zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));				
  }		
  return false;
}

function after_process()
{
  global  $messageStack, $insert_id, $db; 
  $transaction_id = $this->transactionId;
  $payment_status = array
          (
            'new'     => MODULE_PAYMENT_PAYTRAIL_PAYMENT_NEW,
            'fail'    => MODULE_PAYMENT_PAYTRAIL_PAYMENT_FAIL, 
            'ok'      => MODULE_PAYMENT_PAYTRAIL_PAYMENT_OK, 
            'pending' => MODULE_PAYMENT_PAYTRAIL_PAYMENT_PENDING, 
            'delayed' => MODULE_PAYMENT_PAYTRAIL_PAYMENT_DELAYED
          );		
		
  if ($transaction_id == $response['checkout-transaction-id'])
  {
    if ($_GET['checkout-status'] == 'new' || 
        $_GET['checkout-status'] == 'fail' || 
	$_GET['checkout-status'] == 'pending' || 
	$_GET['checkout-status'] == 'delayed')
          {
            $error_message = MODULE_PAYMENT_PAYTRAIL_PAYMENT_ERROR;
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));	
            $messageStack->add_session('checkout_payment', $error_message, 'error');				
          }
	else if ($_GET['checkout-status'] == 'ok')				
	{
          // Update order history
          $comments = zen_db_prepare_input(MODULE_PAYMENT_PAYTRAIL_TITLE_STATUS .$payment_status[$_GET['checkout-status']] .MODULE_PAYMENT_PAYTRAIL_PAYMENT_METHOD .$_GET['checkout-provider'] . " , " .MODULE_PAYMENT_PAYTRAIL_REFERENCE_NUMBER .$_GET['checkout-reference'] . ".");
          $db->Execute("update " . TABLE_ORDERS_STATUS_HISTORY . " set comments = CONCAT(comments, '" . zen_db_input($comments) . "') where orders_id = '" . $insert_id . "'");
        }
	else
        {
          zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
	}			
  }
    else
    {
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false));
    }		
} // end after_process			

function install()
{
  /* Test password include  */
  global $db;
  $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Lajittelujärjestys', 'MODULE_PAYMENT_PAYTRAIL_SORT_ORDER', '0', 'Maksutavan lajittelujärjestys. Pienimmän luvun omaava on ylimpänä.', '6', '1', now())");
  $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Ota käyttöön Paytrail', 'MODULE_PAYMENT_PAYTRAIL_STATUS', 'Kyllä', 'Otetaanko maksumoduuli käyttöön?', '6', '2', 'zen_cfg_select_option(array(\'Kyllä\', \'Ei\'), ', now())");
  $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Kauppiastunnus', 'MODULE_PAYMENT_PAYTRAIL_KAUPPIAS', '375917', 'TEST kauppiastunnus: 375917 <br> Shop-in-Shop kauppiastunnus: 695861', '6', '3', now())");
  $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Turva-avain', 'MODULE_PAYMENT_PAYTRAIL_TURVA_AVAIN', 'SAIPPUAKAUPPIAS', 'Normal merchant test turva-avain: SAIPPUAKAUPPIAS <br> Shop-in-Shop merchant test turva-avain: MONISAIPPUAKAUPPIAS', '6', '4', now())");
  $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Myyjältä Myyjä kauppiastunnus', 'MODULE_PAYMENT_PAYTRAIL_PAAMYYJA', '', 'Test myyjältä myyjä kauppiastunnus: 695874', '6', '7', now())");
  $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Maksumoduulin voimassaoloalue', 'MODULE_PAYMENT_PAYTRAIL_ZONE', '0', 'Jos alue on valittu, käytä tätä maksutapaa vain valitun alueen ostotapahtumille..', '6', '8', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
  $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Tilauksen tila suoritetun maksun jälkeen', 'MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID_SETTLED', '2', 'Tilauksen tila maksun suorittamisen jälkeen:', '6', '10', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
}

function remove()
{
  global $db;
  $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
}

function keys()
{
  return array('MODULE_PAYMENT_PAYTRAIL_SORT_ORDER', 
               'MODULE_PAYMENT_PAYTRAIL_STATUS', 
               'MODULE_PAYMENT_PAYTRAIL_KAUPPIAS', 
               'MODULE_PAYMENT_PAYTRAIL_TURVA_AVAIN', 
               'MODULE_PAYMENT_PAYTRAIL_PAAMYYJA', 
               'MODULE_PAYMENT_PAYTRAIL_ZONE', 
               'MODULE_PAYMENT_PAYTRAIL_ORDER_STATUS_ID_SETTLED');	
}
	
function get_error()
{
  global $_GET;
  $error = array
          (
            'title' => MODULE_PAYMENT_PAYTRAIL_HEADER_ERROR,
            'error' => MODULE_PAYMENT_PAYTRAIL_TEXT_ERROR);
  return $error;
}

// ********************************
//       Paytrail  Get Body
// ********************************	
public function getBody()
{
  global $order, $currencies, $db, $order_totals;	
  if (!isset($order->delivery['country']['iso_code_2'])) 
  {
    $order->delivery['street_address'] = $order->billing['street_address'];
    $order->delivery['postcode'] = $order->billing['postcode'];
    $order->delivery['city'] = $order->delivery['city'];
    $order->delivery['state'] = $order->delivery['state'];
    $order->delivery['country']['iso_code_2'] = $order->billing['country']['iso_code_2'];
  }

  $body = json_encode(  [
            'stamp' => $this->generate_uuid(),
            'reference' => $this->order_number,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'language' => $this->language,
            'items' => $this->getOrderItems($order),
            'customer' => [
                'firstName' => $order->customer['firstname'],
                'lastName' => $order->customer['lastname'],
                'phone' => $order->customer['telephone'],
                'email' => $order->customer['email_address'],
            ],
            'deliveryAddress' =>  [
		'streetAddress' =>  trim(substr($order->delivery['street_address'],0,50)),
		'postalCode' =>  trim(substr($order->delivery['postcode'],0,5)),
		'city' => trim(substr($order->delivery['city'],0,18)),
		'county' => trim(substr($order->delivery['state'],0,10)),
		'country' => $order->delivery['country']['iso_code_2'],					
            ],
            'invoicingAddress' =>  [
                'streetAddress' => trim(substr($order->billing['street_address'],0,50)),
		'postalCode' => trim(substr($order->billing['postcode'],0,5)),
		'city' => trim(substr($order->billing['city'],0,18)),
		'county' => trim(substr($order->billing['state'],0,10)),
		'country' => $order->billing['country']['iso_code_2'],					
            ],				
            'redirectUrls' => [
                'success' => $this->return_address,
                'cancel' => $this->cancel_address,
            ],
            'callbackUrls' => [
                'success' => $this->return_address,
                'cancel' => $this->cancel_address,
            ],
  ],

  JSON_UNESCAPED_SLASHES
  );

  // Testing and showing order items 			
  //echo '<pre>'; print_r(json_decode($body,true)); exit;
  return $body;
}

// Greate Logger Stack
private function createLoggerStack(array $args = null)
{
  if (empty($args['logger'])) 
  {
    return HandlerStack::create();
  }
  $stack = HandlerStack::create();
  $stack->push(
          Middleware::log(
                  $args['logger'],
                  new MessageFormatter($args['message_format'] ?? '{uri}: {req_body} - {res_body}')
          )
  );
  return $stack;
}

// Get Headers
protected function getHeaders(string $method, string $transactionId = null)
{
  $datetime = new \DateTime();
  $headers = ['checkout-account'    => $this->merchantId,
              'checkout-algorithm'  => 'sha256',
              'checkout-method'     => strtoupper($method),
              'checkout-nonce'      => uniqid(true),
              'checkout-timestamp'  => $datetime->format('Y-m-d\TH:i:s.u\Z'),
              'platform-name'       => 'zencart- '. $this->PaytrailApiVersion,
              'content-type'        => 'application/json; charset=utf-8',
  ];

  if (! empty($transactionId)) 
  {
    $headers['checkout-transaction-id'] = $transactionId;
  }
  return $headers;
}	
	
public function jsonSerialize()
{
  return array_filter(get_object_vars($this), function ($item) { return $item !== null; } );
}
	
protected function calculateHmac($params = [], $body = '')
{
  return SignaturePyt::calculateHmac($params, $body, $this->privateKey);
}

public function validateHmac($response = [], $body = '', $signature = '')
{
  SignaturePyt::validateHmac($response, $body, $signature, $this->privateKey);
}

public function getOrderItems($order)
{
  $items = [];	
  // Normal payment item	
  if (empty($this->shop_in_shop_merchant_id)) 
  {	
    foreach ($this->itemArgs($order) as $key => $item) 
    {
      $items[] = array('description'  => $item['title'],
                       'productCode'  => $item['code'],	
                       'units'        => $item['qty'],				
                       'unitPrice'    => intval($item['price']),
                       'vatPercentage'=> round(floatval($item['vat']), 1),
                       'deliveryDate' => date('Y-m-d'),
                       'merchant'     => $this->merchantId,
                       'stamp'        => $this->generate_uuid(),
                       'reference'    => $this->order_number,
      );				

    }			  
  }	

  // shop-in-shop payment item request 
  else 
  {
    foreach ($this->itemArgs($order) as $key => $item) 
    {
      $items[] = array('description'  => $item['title'],
                       'productCode'  => $item['code'],	
                       'units'        => $item['qty'],				
                       'unitPrice'    => intval($item['price']),
                       'vatPercentage'=> round(floatval($item['vat']), 1),
                       'deliveryDate' => date('Y-m-d'),
                       'merchant'     => $this->shop_in_shop_merchant_id,
                       'stamp'        => $this->generate_uuid(),				
                       'reference'    => $this->order_number,
                       'commission'   => [
                           'merchant' => $this->shop_in_shop_merchant_id,
                           'amount'   => intval($item['price'] / 10),				
                       ],	
      );			

    }		
  }		
  return $items;
}
	
public function itemArgs($order)
{
  global $order, $currencies, $db, $order_totals;
	
  //Add product breakdown
  $decimals = $currencies->get_decimal_places($_SESSION['currency']);
  $order_subtotal  = zen_round($order->info['subtotal'], 2);
		
  //Variable to compare product calculation to total amount of the order		
  $total_check = 0;
		
  // Array order items, tax  and price
  $items = array();		
  foreach ($order->products as $key => $item) 
  {
    $item_final_price = number_format($item['final_price'], 2, '.', '')*100;	
    $item_tax = $item['tax'];
    $item_price = round($item_final_price * ($item_tax/100+1));		
    $itemqyt   += $item['qty'];
    $total_check  +=  $item_price * $item['qty'];
    if ($order_subtotal == 0) 
    {
      $items[] = array('title' => $item['name'],
                       'code' => $item['model'],
                       'category' => '',
                       'qty' => floatval($item['qty']),
                       'price' => 0,
                       'vat' => 0,
                       'discount' => 0,
                       'type' => 1,
      );
    }
    else
    {
      $items[] = array('title' => $item['name'],
                       'code' => $item['model'],
                       'category' => '',
                       'qty' => floatval($item['qty']),
                       'price' => intval($item_price),
                       'vat' => $item_tax,
                       'discount' => 0,
                       'type' => 1,
      );
    }
  }
  
  //Add shipping to product breakdown
  $shipping_price = $order->info['shipping_cost'] * 100;
  $shipping_tax_total = $order->info['shipping_tax'] * 100;	
  	
  if (DISPLAY_PRICE_WITH_TAX == 'true') 
  {
    $shipping_price = $shipping_price;
  } 
  else 
  {
    $shipping_price = $shipping_price + $shipping_tax_total;
  }		
  
  if($shipping_price > 0 )
  {
    $shipping_tax = ($shipping_tax_total/($shipping_price - $shipping_tax_total))*100;			
    $items[] = array('title' => $order->info['shipping_method'],
                     'code' =>  $order->info['shipping_module_code'].'',
                     'qty' => 1,
                     'price' => round($shipping_price),
                     'vat' => $shipping_tax,
                     'discount' => 0,
                     'type' => 2,
    );	
    $total_check += $shipping_price; 	
  }
	
  //Add storepickup dicount
  $storepickup_discount = $order->info['shipping_cost'];
  if ($storepickup_discount < 0) 
  {
	$storepickup_discount_price = abs($order->info['shipping_cost']) * 100;
    $items[] = array('title' => $order->info['shipping_method'],
                     'code' =>  $order->info['shipping_module_code'].'',
                     'qty' => -1,
                     'price' => $storepickup_discount_price,
                     'vat' => 0,
                     'discount' => 0,
                     'type' => 4,
    );	
    $total_check -= $storepickup_discount_price; 
  }

  // Add loworderfee breakdown
  // Check if there is a group discount enabled
  foreach ($order_totals as $o_total)
  {
    if(isset($o_total['code']) && $o_total['code'] == 'ot_loworderfee')
    {
      if(isset($o_total['value']) && $o_total['value'] > 0)
      {
        $query = "select * from " . TABLE_CONFIGURATION . " where configuration_key='MODULE_ORDER_TOTAL_LOWORDERFEE_TAX_CLASS'";
	$loworder_tax = $db->Execute($query);
	$loworder_tax_rate = zen_get_tax_rate($loworder_tax->fields['configuration_value'], $order->billing['country']['id'], $order->billing['zone_id']);
	$loworder_price_format = number_format($o_total['value'], 2, '.', '') * 100;
	if (DISPLAY_PRICE_WITH_TAX == 'true')
	{
          $loworderpretax_price = $loworder_price_format;
	}
	else 
        {
          $loworderpretax_price =  ($loworder_price_format / ($loworder_tax_rate/100+1)) * 100;
	}
	$items[] = array('title' => MODULE_PAYMENT_PAYTRAIL_LOWORDER_TEXT,
                         'code' => '',
			 'qty' => 1,
			 'price' => $loworderpretax_price,
			 'vat' => $loworder_tax_rate,
			 'discount' => 0,
			 'type' => 1,
	);
	$total_check += $loworderpretax_price;
      }
    }
    if(isset($o_total['code']) && $o_total['code'] == 'ot_subtotal')
    {
      if(isset($o_total['value']) && $o_total['value'] > 0)
      {
        $order_total_sub_total = $o_total['value'];
      }
    }			

    //Add group discount pricing breakdown
    if($o_total['code'] == 'ot_group_pricing')
    {
      if($o_total['value'] > 0)
      {
        $group_amount_format = number_format($o_total['value'], 2, '.', '') * 100;
	if (DISPLAY_PRICE_WITH_TAX == 'true')
        {
          $group_amount = round(floatval($group_amount_format));
        }
        else 
        {
          $group_amount = round(floatval($group_amount_format + $order_total_sub_total));
        }				
	$items[] = array('title' => MODULE_PAYMENT_PAYTRAIL_GROUP_TEXT,
                         'code' => '',
			 'qty' => -1,
			 'price' => intval($group_amount),
			 'vat' => 0,
			 'discount' => 0,
			 'type' => 4,
        );			
	$total_check -= $group_amount;					
      }
    }
    else if(isset($o_total['code']) && $o_total['code'] == 'ot_shipping')
    {
      if(isset($o_total['value']) && $o_total['value'] > 0)
      {
        $discount_amount_shipping = $o_total['value'];
      }
    }
    else if(isset($o_total['code']) && $o_total['code'] == 'ot_group_pricing')
    {
      if(isset($o_total['value']) && $o_total['value'] > 0)
      {
        $group_discount_amount = $o_total['value'];
      }
    }
    else if(isset($o_total['code']) && $o_total['code'] == 'ot_coupon')
    {
      if(isset($o_total['value']) && $o_total['value'] > 0)
      {
        $coupon_amount = $o_total['value'];
      }
    }
    else if(isset($o_total['code']) && $o_total['code'] == 'ot_tax')
    {
      if(isset($o_total['value']) && $o_total['value'] > 0)
      {
        $shiping_ot_tax = $o_total['value'];
      }
    }
    else if(isset($o_total['code']) && $o_total['code'] == 'ot_total')
    {
      if(isset($o_total['value']) && $o_total['value'] > 0)
      {
        $total_amount = number_format($o_total['value'], 2, '.', '') * 100;
      }
    }	
 }
 
 //Add coupon breakdown
 if (abs(isset($_SESSION['cc_id'])))
 {
   $sql = "select * 
           from " . TABLE_COUPONS . " c,
           " . TABLE_COUPONS_DESCRIPTION . " cd,
           " . TABLE_TAX_RATES . " tr 
           where c.coupon_id=:couponID: and coupon_active='Y' 
           and c.coupon_id = cd.coupon_id	";
   $sql = $db->bindVars($sql, ':couponID:', $_SESSION['cc_id'], 'integer');
			
   $coupon = $db->Execute($sql);
   $coupon_product_count    = $coupon->fields['coupon_product_count'];
   $coupon_tax_rate = $coupon->fields['tax_rate'];
   $coupon_code     = $coupon->fields['coupon_code'].'';
   $coupon_amount_formatted = number_format($coupon_amount, 2, '.', '');
   $coupon_shipping_tax = zen_round($shipping_tax, $decimals) * 100 ;
   $coupon_amount_shipping = $discount_amount_shipping * 100;

   if (DISPLAY_PRICE_WITH_TAX == 'true') 
   {
     $coupon_amount = $coupon_amount_formatted * 100;
   }
   else 
   {
     $coupon_amount = $coupon->fields['coupon_amount'];
   }
   
   // Variable to compare product discount calculation to total amount of the order
   $coupon_result = 0;	
   switch ($coupon->fields['coupon_type'])
   {
     // case 'S': // shipping
     // $coupon_result = $coupon_tax_amount ;		
     //	break;
     case 'F': // unit amount
      // One by one  unit amount total
      if ($coupon_product_count == 1) 
      {
        $coupon_result = $coupon_amount * $itemqyt * 100;
      }
      else 
      {
        $coupon_result = $coupon_amount;
      }
     break;
     
     // amount off and free shipping	
     case 'O': 
      $coupon_amount_shipping = $discount_amount_shipping * 100;
      $O_shippingprice = $coupon_amount_shipping +  $coupon_shipping_tax;
      // One by one  unit amount total					
      if ($coupon_product_count == 1) 
      {
        $coupon_amount = $coupon_amount * 100 * $itemqyt;
        $coupon_amount_shipping = $discount_amount_shipping * 100;
        $coupon_result = $coupon_amount + $coupon_amount_shipping;
      } 
       else 
       {
         $coupon_result = ($coupon_amount_formatted + $discount_amount_shipping) * 100;
       }

       $items[] = array('title' => MODULE_PAYMENT_PAYTRAIL_FREE_SHPING,
                        'code' => '',
                        'qty' => 1,
                        'price' => $O_shippingprice,
                        'vat' => 0,
                        'discount' => 0,
                        'type' => 2,
       );

       $total_check += $O_shippingprice;
      break;
      
      // percentage	
      case 'P': 
        if($shippingcost > 0 )
        {
          // Coupon cost
          $coupon_cost = ($order_subtotal/100)*($coupon_amount);
          // add shiping cost and shping tax
          $coupon_shiping_tax = ($shippingcost/100)*($coupon_amount);
          $coupon_result =  ($coupon_cost + $coupon_shiping_tax) ;
          $coupon_result = zen_round($coupon_result, $decimals) * 100;
        }	
	else 
        {
          $coupon_result = ($order_subtotal/100)*($coupon_amount_formatted);
          $coupon_result = zen_round($coupon_result, $decimals) * 100;
	}
      break;

      // percentage and Free Shipping
      case 'E':
       $E_shipping_tax_cost =  zen_round($shiping_ot_tax, $decimals) * 100;
       $E_shipping_price = $coupon_amount_shipping + $E_shipping_tax_cost ;
       $E_shipping_tax =($E_shipping_tax_cost/$coupon_amount_shipping)* 100 ;
       $coupon_cost = (($order_subtotal + $discount_amount_shipping)/100) * ($coupon_amount_formatted);
       $coupon_result = ($coupon_cost + $discount_amount_shipping);
       $coupon_result = zen_round($coupon_result, $decimals) * 100;
					
       $items[] = array('title' => MODULE_PAYMENT_PAYTRAIL_FREE_SHPING,
                         'code' => '',
			 'qty' => 1,
			 'price' => $E_shipping_price,
			 'vat' => $E_shipping_tax,
			 'discount' => 0,
			 'type' => 2,
	);	
	$total_check += $E_shipping_price;	
      break;				
   } // end switch
   
   $items[] = array('title' => MODULE_PAYMENT_PAYTRAIL_COUPON_TEXT,
                    'code' => $coupon_code,
                    'qty' => -1,
                    'price' => $coupon_result,
                    'vat' => 0,
                    'discount' => 0,
                    'type' => 4,
   );
   $total_check -= $coupon_result;
  }
  
  // Add Gift Voucher breakdown
  if ($_SESSION['cot_gv'] > 0) 
  {
    $gv_query = "select *
                 from " . TABLE_COUPON_GV_CUSTOMER . " 
		 where customer_id = '" . $_SESSION['customer_id'] . "'";
    $gv_order = $db->Execute($gv_query);
			
    // Gift amonut total	
    $gv_order_amount = number_format($gv_order->fields['amount'], 2, '.', '') .'€';
    $gv_amount = $_SESSION['cot_gv'] * 100;
			
    // if tax is to be calculated on purchased GVs, calculate it
    $items[] = array('title' => MODULE_PAYMENT_PAYTRAIL_GIFT_TEXT,
                     'code' => $gv_order_amount,
                     'qty' => -1,
                     'price' => $gv_amount,
                     'vat' => 0,
                     'discount' => 0,
                     'type' => 4,
    );
    $total_check -= $gv_amount;
  }

  // Add reward points breakdown
  if ($_SESSION['redeem_points'] > 0) 
  {
    $redem_value = number_format($_SESSION['redeem_points'], 2, '.', '');
    // if tax is to be calculated on purchased GVs, calculate it
    $items[] = array('title' => MODULE_PAYMENT_PAYTRAIL_REWARD_POINT_TEXT,
                     'code' => '',
                     'qty' => -1,
                     'price' => $redem_value,
                     'vat' => 0,
                     'discount' => 0,
                     'type' => 4,
    );
    $total_check -= $redem_value;
  }		
	
  // Add sumround breakdown
  if ($this->amount <> $total_check)  
  {
    if ($this->amount > $total_check)
    {
      $sum_round_count = $this->amount - $total_check;
      $qty = 1;
    }
    if ($total_check > $this->amount)
    {
      $sum_round_count = $total_check - $this->amount;
      $qty = -1;
    }
    $sum_round = round(floatval($sum_round_count));
    $items[] = array('title' => MODULE_PAYMENT_PAYTRAIL_SUM_ROUND,
                     'code' => '',
                     'qty' => $qty,
                     'price' => $sum_round,
                     'vat' => 0,
                     'discount' => 0,
                     'type' => 1,
    );
  }
  return $items;
} // end itemArgs($order)
	
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

} // end class paytrail

// This class is Paytrail module signature
class SignaturePyt
{
  public static function calculateHmac($params = [], $body = '', $privateKey = '')
  {
    // Keep only checkout- params, more relevant for response validation.
    $includedKeys = array_filter(array_keys($params), function ($key) {
            return preg_match('/^checkout-/', $key);
        });

    // Keys must be sorted alphabetically
    sort($includedKeys, SORT_STRING);

    $hmacPayload = array_map(
    function ($key) use ($params) 
    {
      // Responses have headers in an array.
      $param = is_array($params[ $key ]) ? $params[ $key ][0] : $params[ $key ];
      return join(':', [ $key, $param ]);
    }, $includedKeys );
    array_push($hmacPayload, $body);
    return hash_hmac('sha256', join("\n", $hmacPayload), $privateKey);		
  }

  public static function validateHmac(
        array $params = [],
        string $body = '',
        string $signature = '',
        string $privateKey = ''
    ) 
    {
      $hmac = static::calculateHmac($params, $body, $privateKey);
      if ($hmac !== $signature) 
      {
        throw new HmacException('HMAC signature is invalid.', 401);
      }
    }
}
?>