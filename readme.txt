=== WooCommerce Moldova Agroindbank Payment Gateway ===
Contributors: alexminza
Donate link: https://www.paypal.me/AlexMinza
Tags: WooCommerce, Moldova, Agroindbank, MAIB, payment, gateway
Requires at least: 4.8
Tested up to: 4.9.4
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
* Admin order actions - complete or reverse authorized transaction

== Frequently Asked Questions ==

= How can I configure the plugin settings? =

Use the WooCommerce -> Settings -> Payments -> Moldova Agroindbank screen to configure the plugin.

= Where can I get the Connection Settings data? =

The connection settings and merchant data are provided by Moldova Agroindbank. This data is used by the plugin to connect to the Moldova Agroindbank payment gateway and process the card transactions. Please see [www.maib.md](https://www.maib.md) and contact [cards@maib.md](mailto:cards@maib.md) for details.

= What store settings are supported? =

Moldova Agroindbank currently supports transactions in MDL (Moldovan Leu).

= How can I contribute to the plugin? =

If you're a developer and you have some ideas to improve the plugin or to solve a bug, feel free to raise an issue or submit a pull request in the [Github repository for the plugin](https://github.com/alexminza/wc-moldovaagroindbank).

You can also contribute to the plugin by translating it. Simply visit [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/wc-moldovaagroindbank) to get started.

== Screenshots ==

1. Plugin settings
2. Connection settings
3. Order actions

== Changelog ==

= 1.0.1 =
* Added total refunds via payment gateway calculation (since WooCommerce 3.4)
* Improved logging and unsupported store settings diagnostics
* Check WooCommerce is active during plugin initialization

= 1.0 =
Initial release

== Upgrade Notice ==

= 1.0.1 =
See Changelog for details
