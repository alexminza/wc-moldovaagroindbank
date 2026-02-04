<?php

/**
 * Plugin Name: Payment Gateway for maib for WooCommerce
 * Description: Accept Visa and Mastercard directly on your store with the Payment Gateway for maib for WooCommerce.
 * Plugin URI: https://github.com/alexminza/wc-moldovaagroindbank
 * Version: 1.5.0
 * Author: Alexander Minza
 * Author URI: https://profiles.wordpress.org/alexminza
 * Developer: Alexander Minza
 * Developer URI: https://profiles.wordpress.org/alexminza
 * Text Domain: wc-moldovaagroindbank
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.2.5
 * Requires at least: 4.8
 * Tested up to: 6.9
 * WC requires at least: 3.3
 * WC tested up to: 10.4.3
 * Requires Plugins: woocommerce
 *
 * @package wc-moldovaagroindbank
 */

// Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/wc-moldovaagroindbank
// This plugin is based on MAIB Payment PHP SDK https://github.com/maibank/maibapi (https://packagist.org/packages/maib/maibapi)

declare(strict_types=1);

namespace AlexMinza\WC_Payment_Gateway;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// https://vanrossum.dev/37-wordpress-and-composer
// https://github.com/Automattic/jetpack-autoloader
require_once plugin_dir_path(__FILE__) . 'vendor/autoload_packages.php';

const MAIB_MOD_PLUGIN_FILE = __FILE__;

add_action('plugins_loaded', __NAMESPACE__ . '\maib_plugins_loaded_init');

function maib_plugins_loaded_init()
{
    // https://developer.woocommerce.com/docs/features/payments/payment-gateway-plugin-base/
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-maib.php';

    //region Init payment gateway
    add_filter('woocommerce_payment_gateways', array(WC_Gateway_MAIB::class, 'add_payment_gateway'));

    if (is_admin()) {
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_Gateway_MAIB::class, 'plugin_action_links'));
    }

    //Add maib close day action
    add_action(WC_Gateway_MAIB::MOD_ACTION_CLOSE_DAY, array(WC_Gateway_MAIB::class, 'action_close_day'));
    //endregion
}

//region Register activation hooks
function plugin_activation_deactivation(bool $activate)
{
    if (!class_exists(WC_Gateway_MAIB::class)) {
        maib_plugins_loaded_init();
    }

    if ($activate) {
        WC_Gateway_MAIB::register_scheduled_actions();
    } else {
        WC_Gateway_MAIB::unregister_scheduled_actions();
    }
}

function plugin_activation()
{
    plugin_activation_deactivation(true);
}

function plugin_deactivation()
{
    plugin_activation_deactivation(false);
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\plugin_activation');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\plugin_deactivation');
//endregion

//region Declare WooCommerce compatibility
add_action(
    'before_woocommerce_init',
    function () {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            // WooCommerce HPOS compatibility
            // https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/#declaring-extension-incompatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);

            // WooCommerce Cart Checkout Blocks compatibility
            // https://github.com/woocommerce/woocommerce/pull/36426
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }
);
//endregion

//region Register WooCommerce Blocks payment method type
add_action(
    'woocommerce_blocks_loaded',
    function () {
        if (class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
            require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-maib-wbc.php';

            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                    $payment_method_registry->register(new WC_Gateway_MAIB_WBC(WC_Gateway_MAIB::MOD_ID));
                }
            );
        }
    }
);
//endregion
