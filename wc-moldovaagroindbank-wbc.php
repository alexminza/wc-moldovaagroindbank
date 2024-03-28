<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_MoldovaAgroindbank_WBC extends AbstractPaymentMethodType
{
    /**
     * The gateway instance.
     *
     * @var WC_MoldovaAgroindbank
     */
    protected $gateway;

    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = WC_MoldovaAgroindbank::MOD_ID;

    /**
     * Initializes the payment method type.
     */
    public function initialize()
    {
        $this->settings = get_option("woocommerce_{$this->name}_settings", []);

        $gateways       = WC()->payment_gateways->payment_gateways();
        $this->gateway  = $gateways[$this->name];
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active()
    {
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles()
    {
        $script_id = 'wc-moldovaagroindbank-block-frontend';

        wp_register_script(
            $script_id,
            plugin_dir_url(__FILE__) . 'assets/js/blocks.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($script_id, 'wc-moldovaagroindbank', dirname(plugin_basename(__FILE__)) . '/languages');
        }

        return [$script_id];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data()
    {
        return [
            'id'          => $this->gateway->id,
            'title'       => $this->gateway->title,
            'description' => $this->gateway->description,
            'icon'        => $this->gateway->icon,
            'supports'    => array_filter($this->gateway->supports, [$this->gateway, 'supports'])
        ];
    }
}
