=== WooCommerce maib Moldova Agroindbank Payment Gateway ===
Contributors: alexminza
Donate link: https://www.revolut.me/alexminza
Tags: WooCommerce, Moldova, maib, payment gateway, credit card
Requires at least: 4.8
Tested up to: 6.8
Stable tag: trunk
Requires PHP: 7.2.5
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WooCommerce Payment Gateway for maib Moldova Agroindbank

== Description ==

Accept Visa and Mastercard directly on your store with the maib Moldova Agroindbank payment gateway for WooCommerce.

= Features =

* Charge and Authorization card transaction types
* Reverse transactions – partial or complete refunds
* Admin order actions – complete authorized transaction
* Close business day scheduled action
* Supports WooCommerce [block-based checkout experience](https://woocommerce.com/checkout-blocks/)
* Free to use – [Open-source GPL-3.0 license on GitHub](https://github.com/alexminza/wc-moldovaagroindbank)

= Getting Started =

* [Installation Instructions](./installation/)
* [Frequently Asked Questions](./faq/)

== Installation ==

Before installation you need to sign up with **maib Moldova Agroindbank** and receive the client certificate.
See [https://www.maib.md/en/persoane-juridice/e-commerce](https://www.maib.md/en/persoane-juridice/e-commerce) and contact [ecom@maib.md](mailto:ecom@maib.md) for details.

1. Configure the plugin Connection Settings by performing one of the following steps:
    * **BASIC**: Upload the PFX client certificate file received from the bank
    * **ADVANCED**: Convert and copy the private key and certificates PEM files to the server, securely set up the owner and file system permissions, configure the paths to the files
2. Set the certificate / private key password
3. Provide the *Callback URL* to the bank to enable online payment notifications
4. Enable *Test* and *Debug* modes in the plugin settings
5. Perform the following tests and verify all the transactions are processed correctly:
    * **Test case No 1**: Set *Transaction type* to *Charge*, create a new order and pay with a test card
    * **Test case No 2**: Set *Transaction type* to *Authorization*, create a new order and pay with a test card, afterwards perform a full order refund
    * **Test case No 3**: Set *Transaction type* to *Charge*, create a new order and pay with a test card, afterwards perform a full order refund
6. Review the *Close day* scheduled action settings on the WooCommerce Status page
7. Disable *Test* and *Debug* modes when ready to accept live payments

See [MaibApi MAIB Payment PHP SDK](https://github.com/maibank/maibapi) for more details.

== Frequently Asked Questions ==

= How can I configure the plugin settings? =

Use the *WooCommerce > Settings > Payments > maib Moldova Agroindbank* screen to configure the plugin.

= Where can I get the Connection Settings data? =

The merchant data and connection settings are provided by maib Moldova Agroindbank. This data is used by the plugin to connect to the maib Moldova Agroindbank payment gateway and process the card transactions. See [https://www.maib.md/ro/persoane-juridice/acceptare-plati/e-commerce](https://www.maib.md/ro/persoane-juridice/acceptare-plati/e-commerce) and contact [ecom@maib.md](mailto:ecom@maib.md) for details.

= What store settings are supported? =

maib Moldova Agroindbank currently supports transactions in MDL (Moldovan Leu), EUR (Euro) and USD (United States Dollar).

= What is the difference between transaction types? =

* **Charge** submits all transactions for settlement.
* **Authorization** simply authorizes the order total for capture later. Use the *Complete transaction* order action to settle the previously authorized transaction.

= How can I manually run the Close day action? =

On the *WooCommerce > Status > Scheduled Actions* page filter the actions list by *Pending* status and search for *maib_close_day*. Click the **Run** link next to the action title to execute the *Close day* action immediately.

= How can I contribute to the plugin? =

If you're a developer and you have some ideas to improve the plugin or to solve a bug, feel free to raise an issue or submit a pull request in the [Github repository for the plugin](https://github.com/alexminza/wc-moldovaagroindbank).

You can also contribute to the plugin by translating it. Simply visit [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/wc-moldovaagroindbank) to get started.

== Screenshots ==

1. Plugin settings
2. Connection settings
3. Advanced connection settings
4. Refunds
5. Order actions

== Changelog ==

See [wc-moldovaagroindbank project releases on GitHub](https://github.com/alexminza/wc-moldovaagroindbank/releases) for details.

= 1.4.3 =
Updated Tested up to 6.8 and WC tested up to 9.8.2

= 1.4.2 =
Improved reliability of the `maib_close_day` scheduled action registration on certain systems.

= 1.4.1 =
* Improved [Composer packages versions compatibility](https://vanrossum.dev/37-wordpress-and-composer) by using [Jetpack Autoloader by Automattic](https://github.com/Automattic/jetpack-autoloader)
* Updated [MAIB Payment PHP SDK](https://github.com/maibank/maibapi) to the latest version [v3.0.5](https://packagist.org/packages/maib/maibapi)

= 1.4.0 =
Added support for WooCommerce [Cart and Checkout Blocks](https://woocommerce.com/document/cart-checkout-blocks-status/).

= 1.3.2 =
Added support for WooCommerce [High-Performance Order Storage (HPOS)](https://woocommerce.com/document/high-performance-order-storage/).

= 1.3.1 =
Updated [MAIB Payment PHP SDK](https://github.com/maibank/maibapi) to fix Windows platform compatibility.
See [Result code value is parsing wrong issue #29](https://github.com/alexminza/wc-moldovaagroindbank/issues/29) and [Gateway response parsing is platform-dependent issue #3](https://github.com/maibank/maibapi/issues/3) for details.

= 1.3.0 =
* Migrated to the official [MAIB Payment PHP SDK](https://github.com/maibank/maibapi)
* Minimum supported PHP version changed to 7.2
* Added *No logo* option for payment method at checkout
* Improved logging and admin setup guidance

= 1.2.5 =
Updated Tested up to 6.1 and WC tested up to 7.1.0

= 1.2.4 =
Updated maib test payment gateway URL and visual identity

= 1.2.3 =
Fixed refund transaction amount value in the underlying third party module.
See [GitHub Fruitware/MaibApi issue #9](https://github.com/Fruitware/MaibApi/issues/9) and [Pull request #10](https://github.com/Fruitware/MaibApi/pull/10) for details.

= 1.2.2 =
Fixed refund transaction amount parameter in the underlying third party module.
See [GitHub Fruitware/MaibApi issue #6](https://github.com/Fruitware/MaibApi/issues/6) and [Pull request #7](https://github.com/Fruitware/MaibApi/pull/7) for details.

= 1.2.1 =
Modified MAIB payment gateway URL for 3DS v2 compliance

= 1.2.0 =
Updated Tested up to 5.6 and WC tested up to 4.8.0

= 1.1.9 =
* Added Verify transaction order action
* Updated WC tested up to 4.5.2

= 1.1.7 =
Improved Close day scheduled action registration.
For this feature to work properly at least WooCommerce 4 with [Action Scheduler 3](https://woocommerce.wordpress.com/2020/01/08/action-scheduler-3-0/) are required – see [bug fixes from PR #333](https://github.com/woocommerce/action-scheduler/pull/333) for details.

= 1.1.6 =
Added support for EUR and USD currencies

= 1.1.5 =
Fixed transaction reversal status check

= 1.1.3 =
Minor improvements

= 1.1.2 =
Minor improvements

= 1.1.1 =
Basic and Advanced settings configuration modes

= 1.1 =
* Simplified payment gateway setup
* Added client certificate upload
* Added payment method logo image selection
* Added close business day scheduled action
* Added validations for certificates, private key and settings

= 1.0.1 =
* Added total refunds via payment gateway calculation (since WooCommerce 3.4)
* Improved logging and unsupported store settings diagnostics
* Check WooCommerce is active during plugin initialization

= 1.0 =
Initial release

== Upgrade Notice ==

= 1.4.3 =
Updated Tested up to 6.8 and WC tested up to 9.8.2

= 1.4.2 =
Improved reliability of the `maib_close_day` scheduled action registration on certain systems.

= 1.4.1 =
* Improved [Composer packages versions compatibility](https://vanrossum.dev/37-wordpress-and-composer) by using [Jetpack Autoloader by Automattic](https://github.com/Automattic/jetpack-autoloader)
* Updated [MAIB Payment PHP SDK](https://github.com/maibank/maibapi) to the latest version [v3.0.5](https://packagist.org/packages/maib/maibapi)

= 1.4.0 =
Added support for WooCommerce [Cart and Checkout Blocks](https://woocommerce.com/document/cart-checkout-blocks-status/).

= 1.3.2 =
Added support for WooCommerce [High-Performance Order Storage (HPOS)](https://woocommerce.com/document/high-performance-order-storage/).
