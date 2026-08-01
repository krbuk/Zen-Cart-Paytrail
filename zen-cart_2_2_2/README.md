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
 *
 * Adding (tokenizing) cards (coming soon)
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
 ***** INSTALL *****
 * Copy all files zencart folder
 * Admin page Modules / Payment / Paytrail - Online Payment
 * Install module
 * Add paytrail merchantid and secretkey
 