=== WooCommerce Moldova Agroindbank Payment Gateway ===
Contributors: alexminza
Donate link: https://www.paypal.me/AlexMinza
Tags: WooCommerce, Moldova, Agroindbank, MAIB, payment, gateway
Requires at least: 4.8
Tested up to: 5.2.1
Stable tag: trunk
Requires PHP: 7.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WooCommerce Payment Gateway for Moldova Agroindbank

== Description ==

WooCommerce Payment Gateway for Moldova Agroindbank

= Features =

* Charge and Authorization card transaction types
* Reverse transactions - partial or complete refunds
* Admin order actions - complete authorized transaction
* Close business day scheduled action

= Getting Started =

* [Installation Instructions](./installation/)
* [Frequently Asked Questions](./faq/)

== Installation ==

1. Configure the plugin Connection Settings by performing one of the following steps:
    * **BASIC**: Upload the PFX client certificate file received from the bank
    * **ADVANCED**: Convert and copy the private key and certificates PEM files to the server, securely set up the owner and file system permissions, configure the paths to the files
2. Set the certificate / private key password (or leave the field empty if not encrypted)
3. Provide the *Callback URL* to the bank to enable online payment notifications
4. Perform a test payment, refund and verify they are processed correctly
5. Review the *Close day* scheduled action settings on the WooCommerce Status page
6. Disable *Test* and *Debug* modes when ready to accept live payments

== Frequently Asked Questions ==

= How can I configure the plugin settings? =

Use the *WooCommerce > Settings > Payments > Moldova Agroindbank* screen to configure the plugin.

= Where can I get the Connection Settings data? =

The merchant data and connection settings are provided by Moldova Agroindbank. This data is used by the plugin to connect to the Moldova Agroindbank payment gateway and process the card transactions. Please see [www.maib.md](https://www.maib.md) and contact [cards@maib.md](mailto:cards@maib.md) for details.

= What store settings are supported? =

Moldova Agroindbank currently supports transactions in MDL (Moldovan Leu).

= What is the difference between transaction types? =

* **Charge** submits all transactions for settlement.
* **Authorization** simply authorizes the order total for capture later. Use the *Complete transaction* order action to settle the previously authorized transaction.

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

= 1.1 =
Simplified payment gateway setup.
See Changelog for details.
