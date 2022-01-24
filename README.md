# Paytrail Zen-Cart Module
Paytrail payment methods for your Zen-Cart web store
Always perform a backup of your database and source code before installing any payment extensions.

*This module works on Zen-Cart 1.5.6 - 1.5.7 If you find some bug please inform me.

To use this extension, you need to sign up for a Paytrail account. 
Before account activation use test mode if Okay call to Paytrail customer service and ask to account activations.

 * www.paytrail.com
 * REQUIRES PHP version >= 7.3
 * Use Guzzle HTTP client v6 installed with Composer https://github.com/guzzle/guzzle/
 * We recommend using Guzzle HTTP client through composer as default HTTP client for PHP because it has
 * well documented and nice api. You can use any HTTP library to connect into Paytrail API. https://docs.paytrail.com/
 * Alternatively, if you can't install composer packages you can use http://php.net/manual/en/book.curl.php	
 * @package checkout
 * @copyright Copyright 2003-2019 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Nida Verkkopalvelu (www.nida.fi) / Krbuk 2022 Jan 12 Modified in v1.5.7
 
 
Paytrail on suomalainen maksulaitos. Paytrailin brändi-ilmeellä 5.10.2021.
Paytrail ja Checkout Finland yhdistyivät yrityskaupan myötä samaksi yritykseksi keväällä 2021.  
Myös maksupalvelumme yhdistyvät yhdeksi kokonaisuudeksi, uudeksi Paytrailiksi, johon on poimittu parhaat palat sekä Paytrailista että Checkoutista:

1. Pankkimaksut: Nordea, Osuuspankki, Danske Bank, Säästöpankki, Oma Säästöpankki, POP Pankki, Aktia, Handelsbanken, Ålandsbanken ja S-Pankki
2. Lasku- ja osamaksupalvelut: Walley, OP Lasku ja Jousto
3. Korttimaksaminen: Visa, Mastercard ja American Express
4. Mobiilimaksaminen: MobilePay, Pivo, Siirto ja Apple Pay