<?php
/**
 * Paytrail module v5.8.0 - makes sure the admin page Customers -> Paytrail Payments is registered
 * (also when the admin files were copied after the payment module was installed).
 */
$autoLoadConfig[999][] = [
    'autoType' => 'init_script',
    'loadFile' => 'init_paytrail_admin.php',
];
