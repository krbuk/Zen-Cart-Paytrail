# Paytrail Zen-Cart Module
Paytrail payment methods for your Zen-Cart web store
Always perform a backup of your database and source code before installing any payment extensions.

*This module works on Zen-Cart 2.0.0 If you find some bug please inform me.

To use this extension, you need to sign up for a Paytrail account. 
Before account activation use test mode if Okay call to Paytrail customer service and ask to account activations.

 * www.paytrail.com
 * REQUIRES PHP version >= 8.3
 * Use Guzzle HTTP client v6 installed with Composer https://github.com/guzzle/guzzle/
 * We recommend using Guzzle HTTP client through composer as default HTTP client for PHP because it has
 * well documented and nice api. You can use any HTTP library to connect into Paytrail API. https://docs.paytrail.com/
 * Alternatively, if you can't install composer packages you can use http://php.net/manual/en/book.curl.php	
 * @copyright Copyright 2003-2019 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Nida Verkkopalvelu (www.nida.fi) / Krbuk 2024 Aug 8 Modified in v2.0.0
 * The general VAT rate will increase from 24 percent to 25,5 percent on 01.09.2024. It now accepts decimals for VAT rates.
