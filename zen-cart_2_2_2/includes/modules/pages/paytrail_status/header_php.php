<?php
//ini_set('display_errors',1);
//error_reporting(E_ALL);
//header('Content-Type: application/json; charset=utf-8');
require(DIR_WS_MODULES . zen_get_module_directory('require_languages.php'));
require_once(DIR_WS_MODULES . 'payment/paytrail.php');

$paytrail = new paytrail();

$transactionId = zen_db_prepare_input($_GET['transactionId'] ?? '');

if(empty($transactionId)) {
  echo json_encode([
        'checkout-status'=>'error',
        'message'=>'missing transaction id'
    ]);
  
    exit;
}

$result = $paytrail->getPaymentStatus($transactionId);

if($result === false) {
    echo json_encode([
        'checkout-status'=>'error',
        'message'=>'paytrail api error'
    ]);

    exit;
}

echo json_encode([
    'checkout-status'=>$result['status'] ?? 'pending'
]);

exit;