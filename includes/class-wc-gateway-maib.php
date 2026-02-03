<?php

/**
 * @package wc-moldovaagroindbank
 */

declare(strict_types=1);

namespace AlexMinza\WC_Payment_Gateway;

defined('ABSPATH') || exit;

use Maib\MaibApi\MaibClient;

class WC_Gateway_MAIB extends WC_Payment_Gateway_Base
{
    //region Constants
    const MOD_ID          = 'moldovaagroindbank';
    const MOD_TEXT_DOMAIN = 'wc-moldovaagroindbank';
    const MOD_PREFIX      = 'maib_';
    const MOD_TITLE       = 'maib';
    const MOD_VERSION     = '1.5.0';
    const MOD_PLUGIN_FILE = MAIB_MOD_PLUGIN_FILE;

    const SUPPORTED_CURRENCIES = array('MDL', 'EUR', 'USD');

    const TRANSACTION_TYPE_CHARGE        = 'charge';
    const TRANSACTION_TYPE_AUTHORIZATION = 'authorization';

    const LOGO_TYPE_BANK       = 'bank';
    const LOGO_TYPE_SYSTEMS    = 'systems';

    const MOD_TRANSACTION_TYPE = self::MOD_PREFIX . 'transaction_type';
    const MOD_TRANSACTION_ID   = self::MOD_PREFIX . 'transaction_id';

    const MAIB_TRANS_ID        = 'trans_id';
    const MAIB_TRANSACTION_ID  = 'TRANSACTION_ID';

    const MAIB_RESULT               = 'RESULT';
    const MAIB_RESULT_OK            = 'OK'; //successfully completed transaction
    const MAIB_RESULT_FAILED        = 'FAILED'; //transaction has failed
    const MAIB_RESULT_CREATED       = 'CREATED'; //transaction just registered in the system
    const MAIB_RESULT_PENDING       = 'PENDING'; //transaction is not accomplished yet
    const MAIB_RESULT_DECLINED      = 'DECLINED'; //transaction declined by ECOMM, because ECI is in blocked ECI list (ECOMM server side configuration)
    const MAIB_RESULT_REVERSED      = 'REVERSED'; //transaction is reversed
    const MAIB_RESULT_AUTOREVERSED  = 'AUTOREVERSED'; //transaction is reversed by autoreversal
    const MAIB_RESULT_TIMEOUT       = 'TIMEOUT'; //transaction was timed out

    const MAIB_RESULT_CODE          = 'RESULT_CODE';
    const MAIB_RESULT_3DSECURE      = '3DSECURE';
    const MAIB_RESULT_RRN           = 'RRN';
    const MAIB_RESULT_APPROVAL_CODE = 'APPROVAL_CODE';
    const MAIB_RESULT_CARD_NUMBER   = 'CARD_NUMBER';

    const MOD_ACTION_COMPLETE_TRANSACTION = self::MOD_PREFIX . 'complete_transaction';
    const MOD_ACTION_VERIFY_TRANSACTION   = self::MOD_PREFIX . 'verify_transaction';
    const MOD_ACTION_CLOSE_DAY            = self::MOD_PREFIX . 'close_day';
    //endregion

    protected $transaction_type;
    protected $maib_base_url, $maib_redirect_url, $maib_pfxcert, $maib_pcert, $maib_key, $maib_key_password;

    public function __construct()
    {
        $this->id                 = self::MOD_ID;
        $this->method_title       = self::MOD_TITLE;
        $this->method_description = __('Accept Visa and Mastercard through maib.', 'wc-moldovaagroindbank');
        $this->has_fields         = false;
        $this->supports           = array('products', 'refunds');

        //region Initialize settings
        $this->init_form_fields();
        $this->init_settings();

        parent::__construct();

        $this->icon              = self::get_logo_icon($this->get_option('logo_type', self::LOGO_TYPE_BANK));
        $this->transaction_type  = $this->get_option('transaction_type', self::TRANSACTION_TYPE_CHARGE);

        // https://github.com/maibank/maibapi/blob/main/src/MaibApi/MaibClient.php
        $this->maib_base_url     = $this->testmode ? MaibClient::MAIB_TEST_BASE_URI : MaibClient::MAIB_LIVE_BASE_URI;
        $this->maib_redirect_url = $this->testmode ? MaibClient::MAIB_TEST_REDIRECT_URL : MaibClient::MAIB_LIVE_REDIRECT_URL;

        $this->maib_pfxcert      = $this->get_option('maib_pfxcert');
        $this->maib_pcert        = $this->get_option('maib_pcert');
        $this->maib_key          = $this->get_option('maib_key');
        $this->maib_key_password = $this->get_option('maib_key_password');

        //endregion

        if (is_admin()) {
            add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));

            add_filter('woocommerce_order_actions', array($this, 'order_actions'), 10, 2);
            add_action('woocommerce_order_action_' . self::MOD_ACTION_COMPLETE_TRANSACTION, array($this, 'action_complete_transaction'));
            add_action('woocommerce_order_action_' . self::MOD_ACTION_VERIFY_TRANSACTION, array($this, 'action_verify_transaction'));
        }

        add_action("woocommerce_api_wc_{$this->id}", array($this, 'check_response'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled'         => array(
                'title'       => __('Enable/Disable', 'wc-moldovaagroindbank'),
                'type'        => 'checkbox',
                'label'       => __('Enable this gateway', 'wc-moldovaagroindbank'),
                'default'     => 'yes',
            ),
            'title'           => array(
                'title'       => __('Title', 'wc-moldovaagroindbank'),
                'type'        => 'text',
                'description' => __('Payment method title that the customer will see during checkout.', 'wc-moldovaagroindbank'),
                'desc_tip'    => true,
                'default'     => $this->get_method_title(),
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            ),
            'description'     => array(
                'title'       => __('Description', 'wc-moldovaagroindbank'),
                'type'        => 'textarea',
                'description' => __('Payment method description that the customer will see during checkout.', 'wc-moldovaagroindbank'),
                'desc_tip'    => true,
                'default'     => __('Online payment with Visa / Mastercard bank cards issued by any bank in Moldova or abroad, processed through maib online payment system.', 'wc-moldovaagroindbank'),
            ),
            'logo_type' => array(
                'title'       => __('Logo', 'wc-moldovaagroindbank'),
                'type'        => 'select',
                'description' => __('Payment method logo image that the customer will see during checkout.', 'wc-moldovaagroindbank'),
                'desc_tip'    => true,
                'default'     => self::LOGO_TYPE_BANK,
                'class'       => 'wc-enhanced-select',
                'options'     => array(
                    self::LOGO_TYPE_BANK    => __('Bank logo', 'wc-moldovaagroindbank'),
                    self::LOGO_TYPE_SYSTEMS => __('Payment systems logos', 'wc-moldovaagroindbank'),
                ),
            ),

            'testmode'        => array(
                'title'       => __('Test mode', 'wc-moldovaagroindbank'),
                'type'        => 'checkbox',
                'label'       => __('Enabled', 'wc-moldovaagroindbank'),
                'description' => __('Use Test or Live bank gateway to process the payments. Disable when ready to accept live payments.', 'wc-moldovaagroindbank'),
                'desc_tip'    => true,
                'default'     => 'no',
            ),
            'debug'           => array(
                'title'       => __('Debug mode', 'wc-moldovaagroindbank'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'wc-moldovaagroindbank'),
                'description' => sprintf('<a href="%2$s">%1$s</a>', esc_html__('View logs', 'wc-moldovaagroindbank'), esc_url(self::get_logs_url())),
                'desc_tip'    => __('Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', 'wc-moldovaagroindbank'),
                'default'     => 'no',
            ),

            'transaction_type' => array(
                'title'        => __('Transaction type', 'wc-moldovaagroindbank'),
                'type'         => 'select',
                'description'  => __('Select how transactions should be processed. Charge submits all transactions for settlement, Authorization simply authorizes the order total for capture later.', 'wc-moldovaagroindbank'),
                'desc_tip'     => true,
                'default'      => self::TRANSACTION_TYPE_CHARGE,
                'class'        => 'wc-enhanced-select',
                'options'      => array(
                    self::TRANSACTION_TYPE_CHARGE        => __('Charge', 'wc-moldovaagroindbank'),
                    self::TRANSACTION_TYPE_AUTHORIZATION => __('Authorization', 'wc-moldovaagroindbank'),
                ),
            ),
            'order_template'  => array(
                'title'       => __('Order description', 'wc-moldovaagroindbank'),
                'type'        => 'text',
                /* translators: 1: Example placeholder shown to user, represents Order ID */
                'description' => __('Format: <code>%1$s</code> - Order ID', 'wc-moldovaagroindbank'),
                'desc_tip'    => __('Order description that the customer will see on the bank payment page.', 'wc-moldovaagroindbank'),
                'default'     => self::ORDER_TEMPLATE,
                'custom_attributes' => array(
                    'required'  => 'required',
                ),
            ),

            'connection_settings' => array(
                'title'       => __('Connection Settings', 'wc-moldovaagroindbank'),
                'type'        => 'title',
                'description' => sprintf(
                    '%1$s<br /><br /><a href="#" id="%4$s" class="button">%2$s</a> <a href="#" id="%5$s" class="button">%3$s</a>',
                    esc_html__('Use Basic settings to upload the key files received from the bank or configure manually using Advanced settings below.', 'wc-moldovaagroindbank'),
                    esc_html__('Basic settings&raquo;', 'wc-moldovaagroindbank'),
                    esc_html__('Advanced settings&raquo;', 'wc-moldovaagroindbank'),
                    $this->get_field_key('basic_settings'),
                    $this->get_field_key('advanced_settings')
                ),
            ),
            'maib_pfxcert' => array(
                'title'       => __('Client certificate (PFX)', 'wc-moldovaagroindbank'),
                'type'        => 'file',
                'description' => __('Uploaded PFX certificate will be processed and converted to PEM format. Advanced settings will be overwritten and configured automatically.', 'wc-moldovaagroindbank'),
                'desc_tip'    => true,
                'custom_attributes' => array(
                    'accept' => '.pfx',
                ),
            ),

            'maib_pcert'      => array(
                'title'       => __('Client certificate file', 'wc-moldovaagroindbank'),
                'type'        => 'text',
                'description' => '<code>/path/to/pcert.pem</code>',
                'class'       => 'code',
            ),
            'maib_key'        => array(
                'title'       => __('Private key file', 'wc-moldovaagroindbank'),
                'type'        => 'text',
                'description' => '<code>/path/to/key.pem</code>',
                'class'       => 'code',
            ),
            'maib_key_password' => array(
                'title'       => __('Certificate / private key passphrase', 'wc-moldovaagroindbank'),
                'type'        => 'password',
                'description' => __('Leave empty if certificate / private key is not encrypted.', 'wc-moldovaagroindbank'),
                'desc_tip'    => true,
                'placeholder' => __('Optional', 'wc-moldovaagroindbank'),
            ),

            'payment_notification' => array(
                'title'       => __('Payment Notification', 'wc-moldovaagroindbank'),
                'type'        => 'title',
                'description' => sprintf(
                    '%1$s<br /><br /><b>%2$s:</b> <code>%3$s</code>',
                    esc_html__('Provide this URL to the bank to enable online payment notifications.', 'wc-moldovaagroindbank'),
                    esc_html__('Callback URL', 'wc-moldovaagroindbank'),
                    esc_url($this->get_callback_url())
                ),
            ),
        );
    }

    protected static function get_logo_icon(string $logo_type)
    {
        switch ($logo_type) {
            case self::LOGO_TYPE_BANK:
                return plugins_url('assets/img/maib.png', self::MOD_PLUGIN_FILE);
            case self::LOGO_TYPE_SYSTEMS:
                return plugins_url('assets/img/paymentsystems.png', self::MOD_PLUGIN_FILE);
        }

        return '';
    }

    public function admin_options()
    {
        $this->initialize_certificates();

        // https://developer.woocommerce.com/2025/11/19/deprecation-of-wc_enqueue_js-in-10-4/
        $script_handle = self::MOD_PREFIX . 'connection_settings';
        wp_register_script($script_handle, plugins_url('assets/js/connection_settings.js', self::MOD_PLUGIN_FILE), array('jquery'), self::MOD_VERSION, true);
        wp_enqueue_script($script_handle);
        wp_localize_script(
            $script_handle,
            $script_handle,
            array(
                'connection_basic_fields_ids' => $this->get_field_id(array('maib_pfxcert', 'maib_key_password')),
                'connection_advanced_fields_ids' => $this->get_field_id(array('maib_pcert', 'maib_key', 'maib_key_password')),
                'basic_settings_button_id' => $this->get_field_id('basic_settings'),
                'advanced_settings_button_id' => $this->get_field_id('advanced_settings'),
            )
        );

        parent::admin_options();
    }

    public function process_admin_options()
    {
        $this->process_pfx_setting('maib_pfxcert', $this->maib_pfxcert, 'maib_key_password');

        return parent::process_admin_options();
    }

    protected function check_settings()
    {
        return parent::check_settings()
            && !empty($this->maib_pcert)
            && !empty($this->maib_key);
    }

    protected function validate_settings()
    {
        $validate_result = parent::validate_settings();

        $result = $this->validate_certificate($this->maib_pcert);
        if (!empty($result)) {
            $this->add_error(sprintf('<strong>%1$s</strong>: %2$s', $this->get_settings_field_label('maib_pcert'), esc_html($result)));
            $validate_result = false;
        }

        $result = $this->validate_certificate_private_key($this->maib_pcert, $this->maib_key, $this->maib_key_password);
        if (!empty($result)) {
            $this->add_error(sprintf('<strong>%1$s</strong>: %2$s', $this->get_settings_field_label('maib_key'), esc_html($result)));
            $validate_result = false;
        }

        return $validate_result;
    }

    //region Certificates
    protected function process_pfx_setting(string $pfx_field_id, string $pfx_option_value, string $pass_field_id)
    {
        $pfx_field_key  = $this->get_field_key($pfx_field_id);
        $pass_field_key = $this->get_field_key($pass_field_id);

        try {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by WooCommerce.
            if (array_key_exists($pfx_field_key, $_FILES)) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- File validation is performed via is_uploaded_file and error check. Nonce verification is handled by WooCommerce.
                $pfx_file = $_FILES[$pfx_field_key];
                $tmp_name = $pfx_file['tmp_name'];

                if (UPLOAD_ERR_OK === $pfx_file['error'] && is_uploaded_file($tmp_name)) {
                    $wp_filesystem = self::get_wp_filesystem();
                    $pfx_data      = $wp_filesystem->get_contents($tmp_name);

                    if (false !== $pfx_data) {
                        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by WooCommerce.
                        $pfx_passphrase = isset($_POST[$pass_field_key]) ? sanitize_textarea_field(wp_unslash($_POST[$pass_field_key])) : '';

                        $result = $this->process_export_certificates($pfx_data, $pfx_passphrase);

                        $result_p_cert = isset($result['pcert']) ? $result['pcert'] : null;
                        $result_key    = isset($result['key']) ? $result['key'] : null;

                        if (!empty($result_p_cert) && !empty($result_key)) {
                            // Overwrite advanced settings values
                            $_POST[$this->get_field_key('maib_pcert')] = $result_p_cert;
                            $_POST[$this->get_field_key('maib_key')] = $result_key;

                            // Certificates export success - save PFX bundle to settings
                            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 is required for storing binary PFX data.
                            $_POST[$pfx_field_key] = base64_encode($pfx_data);

                            return;
                        }
                    }
                }
            }
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'pfx_field_id' => $pfx_field_id,
                    'pass_field_id' => $pass_field_id,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        // Preserve existing value
        $_POST[$pfx_field_key] = $pfx_option_value;
    }

    protected function initialize_certificates()
    {
        try {
            $pcert_needs_init = !is_readable($this->maib_pcert) && self::is_overwritable($this->maib_pcert);
            $key_needs_init = !is_readable($this->maib_key) && self::is_overwritable($this->maib_key);

            if ($pcert_needs_init || $key_needs_init) {
                if (!empty($this->maib_pfxcert)) {
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Base64 is required for decoding stored binary PFX data.
                    $pfx_cert_data = base64_decode($this->maib_pfxcert);
                    if (false !== $pfx_cert_data) {
                        $result = $this->process_export_certificates($pfx_cert_data, $this->maib_key_password);

                        $result_p_cert = isset($result['pcert']) ? $result['pcert'] : null;
                        $result_key = isset($result['key']) ? $result['key'] : null;

                        if (!empty($result_p_cert) && !empty($result_key)) {
                            $this->update_option('maib_pcert', $result_p_cert);
                            $this->update_option('maib_key', $result_key);

                            $this->maib_pcert = $result_p_cert;
                            $this->maib_key   = $result_key;
                        }
                    }
                }
            }
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }
    }

    protected function validate_certificate(string $cert_file)
    {
        try {
            $validate_result = $this->validate_file($cert_file);
            if (!empty($validate_result)) {
                return $validate_result;
            }

            $wp_filesystem = self::get_wp_filesystem();
            $cert_data = $wp_filesystem->get_contents($cert_file);
            $cert = openssl_x509_read($cert_data);

            if (false !== $cert) {
                $cert_info = openssl_x509_parse($cert);

                if (false !== $cert_info) {
                    $expiry_date = new \WC_DateTime();
                    $expiry_date->setTimestamp($cert_info['validTo_time_t']);
                    $threshold_date = new \WC_DateTime('+30 days');

                    $site_timezone = wp_timezone();
                    $expiry_date->setTimezone($site_timezone);
                    $threshold_date->setTimezone($site_timezone);

                    if ($expiry_date < $threshold_date) {
                        // Certificate already expired or expires in the next 30 days
                        /* translators: 1: Date string */
                        return esc_html(sprintf(__('Certificate valid until %1$s', 'wc-moldovaagroindbank'), wc_format_datetime($expiry_date)));
                    }

                    return null;
                }
            }

            $message = esc_html__('Invalid certificate', 'wc-moldovaagroindbank');
            $this->log_openssl_errors($message);
            return $message;
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'cert_file' => $cert_file,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );

            return esc_html__('Could not validate certificate', 'wc-moldovaagroindbank');
        }
    }

    protected function validate_certificate_private_key(string $cert_file, string $key_file, string $key_passphrase)
    {
        try {
            $validate_result = $this->validate_file($key_file);
            if (!empty($validate_result)) {
                return $validate_result;
            }

            $wp_filesystem = self::get_wp_filesystem();
            $key_data = $wp_filesystem->get_contents($key_file);
            $private_key = openssl_pkey_get_private($key_data, $key_passphrase);

            if (false === $private_key) {
                $message = __('Invalid private key or wrong private key passphrase', 'wc-moldovaagroindbank');
                $this->log_openssl_errors($message);
                return $message;
            }

            $cert_data = $wp_filesystem->get_contents($cert_file);
            $key_check_data = array(
                0 => $key_data,
                1 => $key_passphrase,
            );

            $validate_result = openssl_x509_check_private_key($cert_data, $key_check_data);
            $message = __('Private key does not correspond to client certificate', 'wc-moldovaagroindbank');
            if (false === $validate_result) {
                $this->log_openssl_errors($message);
                return $message;
            }
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'key_file' => $key_file,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );

            return esc_html__('Could not validate private key', 'wc-moldovaagroindbank');
        }
    }

    protected function validate_file(string $file)
    {
        try {
            if (empty($file)) {
                return __('Invalid value', 'wc-moldovaagroindbank');
            }

            if (!file_exists($file)) {
                return __('File not found', 'wc-moldovaagroindbank');
            }

            if (!is_readable($file)) {
                return __('File not readable', 'wc-moldovaagroindbank');
            }
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'file' => $file,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );

            return __('Could not validate file', 'wc-moldovaagroindbank');
        }
    }

    protected function process_export_certificates(string $pfx_cert_data, string $pfx_passphrase)
    {
        $result = array();
        $pfx_certs = array();
        $error = null;

        if (openssl_pkcs12_read($pfx_cert_data, $pfx_certs, $pfx_passphrase)) {
            if (isset($pfx_certs['pkey'])) {
                $pfx_pkey = null;
                if (openssl_pkey_export($pfx_certs['pkey'], $pfx_pkey, $pfx_passphrase)) {
                    $result['key'] = self::save_temp_file($pfx_pkey, 'key.pem');

                    if (isset($pfx_certs['cert'])) {
                        $result['pcert'] = self::save_temp_file($pfx_certs['cert'], 'pcert.pem');
                    }
                }
            }
        } else {
            $error = esc_html__('Invalid certificate or wrong passphrase', 'wc-moldovaagroindbank');
        }

        if (!empty($error)) {
            $this->log_openssl_errors($error);
        }

        return $result;
    }

    protected function save_temp_file(string $file_data, string $file_suffix = '')
    {
        $wp_filesystem = self::get_wp_filesystem();

        $temp_file_name = sprintf('%1$s%2$s_', self::MOD_PREFIX, $file_suffix);
        $temp_file = wp_tempnam($temp_file_name);

        if (!$wp_filesystem->put_contents($temp_file, $file_data, FS_CHMOD_FILE)) {
            /* translators: 1: Temporary file name */
            $this->log(sprintf(__('Unable to save data to temporary file: %1$s', 'wc-moldovaagroindbank'), $temp_file), \WC_Log_Levels::ERROR);
            return null;
        }

        return $temp_file;
    }

    protected static function is_overwritable(string $file_name)
    {
        return empty($file_name) || self::is_temp_file($file_name);
    }
    //endregion

    //region Payment
    protected function init_maib_client()
    {
        $this->initialize_certificates();

        // http://docs.guzzlephp.org/en/stable/request-options.html
        // https://www.php.net/manual/en/function.curl-setopt.php
        // https://github.com/maibank/maibapi/blob/main/README.md#usage
        $options = array(
            'base_uri' => $this->maib_base_url,
            'verify'   => true,
            'cert'     => $this->maib_pcert,
            'ssl_key'  => array($this->maib_key, $this->maib_key_password),
        );

        if ($this->debug) {
            $log = new \Monolog\Logger('maib_guzzle_request');
            $log_file_name = \WC_Log_Handler_File::get_log_file_path(self::MOD_ID . '_guzzle');
            $log->pushHandler(new \Monolog\Handler\StreamHandler($log_file_name, \Monolog\Logger::DEBUG));
            $stack = \GuzzleHttp\HandlerStack::create();
            $stack->push(\GuzzleHttp\Middleware::log($log, new \GuzzleHttp\MessageFormatter(\GuzzleHttp\MessageFormatter::DEBUG)));

            $options['handler'] = $stack;
        }

        $guzzle_client = new \GuzzleHttp\Client($options);
        $client = new MaibClient($guzzle_client);

        return $client;
    }

    /**
     * @param int $order_id
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $order_total = floatval($order->get_total());
        $order_currency_numcode = self::get_currency_numcode($order->get_currency());
        $order_description = $this->get_order_description($order);
        $client_ip = self::get_client_ip();
        $lang = self::get_language();
        $register_result = null;

        try {
            $client = $this->init_maib_client();
            $register_result = self::TRANSACTION_TYPE_CHARGE === $this->transaction_type
                ? $client->registerSmsTransaction($order_total, $order_currency_numcode, $client_ip, $order_description, $lang)
                : $client->registerDmsAuthorization($order_total, $order_currency_numcode, $client_ip, $order_description, $lang);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        if (!empty($register_result)) {
            $trans_id = strval($register_result[self::MAIB_TRANSACTION_ID]);
            if (!empty($trans_id)) {
                //region Update order payment transaction metadata
                //https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#apis-for-gettingsetting-posts-and-postmeta
                //https://developer.woocommerce.com/docs/hpos-extension-recipe-book/#2-supporting-high-performance-order-storage-in-your-extension
                $order->update_meta_data(self::MOD_TRANSACTION_TYPE, $this->transaction_type);
                $order->update_meta_data(self::MOD_TRANSACTION_ID, $trans_id);
                $order->save();
                //endregion

                /* translators: 1: Order ID, 2: Payment method title, 3: API response details */
                $message = esc_html(sprintf(__('Order #%1$s payment initiated via %2$s: %3$s', 'wc-moldovaagroindbank'), $order_id, $this->get_method_title(), $trans_id));
                $message = $this->get_test_message($message);
                $this->log(
                    $message,
                    \WC_Log_Levels::INFO,
                    array(
                        'register_result' => $register_result,
                    )
                );

                $order->add_order_note($message);

                $redirect_url = add_query_arg(self::MAIB_TRANS_ID, $trans_id, $this->maib_redirect_url);
                return array(
                    'result'   => 'success',
                    'redirect' => $redirect_url,
                );
            }
        }

        /* translators: 1: Order ID, 2: Payment method title */
        $message = esc_html(sprintf(__('Order #%1$s payment initiation failed via %2$s.', 'wc-moldovaagroindbank'), $order_id, $this->get_method_title()));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::ERROR,
            array(
                'register_result' => $register_result,
            )
        );

        $order->add_order_note($message);

        wc_add_notice($message, 'error');
        $this->logs_admin_website_notice();

        // https://github.com/woocommerce/woocommerce/issues/48687#issuecomment-2186475264
        // https://github.com/woocommerce/woocommerce/pull/53671
        return array(
            'result'  => 'failure',
            'message' => $message,
        );
    }

    public function complete_transaction(\WC_Order $order)
    {
        if (!$this->check_settings()) {
            $this->settings_admin_notice();
            return false;
        }

        $order_id = $order->get_id();
        $trans_id = self::get_order_transaction_id($order);
        $order_total = floatval($order->get_remaining_refund_amount());
        $order_currency_numcode = self::get_currency_numcode($order->get_currency());
        $order_description = $this->get_order_description($order);
        $client_ip = self::get_client_ip();
        $lang = self::get_language();
        $complete_result = null;

        try {
            $client = $this->init_maib_client();
            $complete_result = $client->makeDMSTrans($trans_id, $order_total, $order_currency_numcode, $client_ip, $order_description, $lang);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        if (!empty($complete_result)) {
            $result = strval($complete_result[self::MAIB_RESULT]);
            if (self::MAIB_RESULT_OK === $result) {
                $rrn = $complete_result[self::MAIB_RESULT_RRN];

                /* translators: 1: Order ID, 2: Payment method title, 3: Payment data */
                $message = esc_html(sprintf(__('Order #%1$s payment completed via %2$s: %3$s', 'wc-moldovaagroindbank'), $order_id, $this->get_method_title(), $rrn));
                $message = $this->get_test_message($message);
                $this->log(
                    $message,
                    \WC_Log_Levels::INFO,
                    array(
                        'complete_result' => $complete_result,
                    )
                );

                $order->payment_complete($rrn);
                $order->add_order_note($message);
                return true;
            }
        }

        /* translators: 1: Order ID, 2: Payment method title */
        $message = esc_html(sprintf(__('Order #%1$s payment completion via %2$s failed.', 'wc-moldovaagroindbank'), $order_id, $this->get_method_title()));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::ERROR,
            array(
                'complete_result' => $complete_result,
            )
        );

        $order->add_order_note($message);
        return false;
    }

    public function verify_transaction(\WC_Order $order)
    {
        if (!$this->check_settings()) {
            $this->settings_admin_notice();
            return false;
        }

        $order_id = $order->get_id();
        $trans_id = self::get_order_transaction_id($order);

        if (empty($trans_id)) {
            /* translators: 1: Payment method title, 2: Order ID */
            $message = esc_html(sprintf(__('%1$s Transaction ID not found for order #%2$s.', 'wc-moldovaagroindbank'), $this->get_method_title(), $order_id));
            $message = $this->get_test_message($message);
            $this->log($message, \WC_Log_Levels::ERROR);
            $order->add_order_note($message);

            return false;
        }

        $transaction_result = $this->get_transaction_result($trans_id);
        if (!empty($transaction_result)) {
            $result = strval($transaction_result[self::MAIB_RESULT]);

            /* translators: 1: Payment method title, 2: Order ID, 3: Payment gateway response */
            $message = esc_html(sprintf(__('Transaction status from %1$s for order #%2$s: %3$s', 'wc-moldovaagroindbank'), $this->get_method_title(), $order_id, $result));
            $message = $this->get_test_message($message);
            $this->log(
                $message,
                \WC_Log_Levels::INFO,
                array(
                    'transaction_result' => $transaction_result,
                )
            );

            $order->add_order_note($message);
            return true;
        }

        /* translators: 1: Payment method title, 2: Order ID */
        $message = esc_html(sprintf(__('Could not retrieve transaction status from %1$s for order #%2$s.', 'wc-moldovaagroindbank'), $this->get_method_title(), $order_id));
        $message = $this->get_test_message($message);
        $this->log($message, \WC_Log_Levels::ERROR);

        $order->add_order_note($message);
        return false;
    }

    protected function get_transaction_result(string $trans_id)
    {
        $client_ip = self::get_client_ip();
        $transaction_result = null;

        try {
            $client = $this->init_maib_client();
            $transaction_result = $client->getTransactionResult($trans_id, $client_ip);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'trans_id' => $trans_id,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        return $transaction_result;
    }

    public function check_response()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callback from the bank does not include a nonce.
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ('GET' === $request_method) {
            $message = __('This Callback URL works and should not be called directly.', 'wc-moldovaagroindbank');

            wc_add_notice($message, 'notice');

            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callback from the bank does not include a nonce.
        $trans_id = isset($_POST[self::MAIB_TRANS_ID]) ? sanitize_textarea_field(wp_unslash($_POST[self::MAIB_TRANS_ID])) : '';
        if (empty($trans_id)) {
            /* translators: 1: Payment method title */
            $message = esc_html(sprintf(__('Payment verification failed: Transaction ID not received from %1$s.', 'wc-moldovaagroindbank'), $this->get_method_title()));
            $this->log($message, \WC_Log_Levels::ERROR);

            wc_add_notice($message, 'error');
            $this->logs_admin_website_notice();

            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        $order = self::get_order_by_meta_field_value(self::MOD_TRANSACTION_ID, $trans_id);
        if (empty($order)) {
            /* translators: 1: Transaction ID, 2: Payment method title */
            $message = esc_html(sprintf(__('Order not found by Transaction ID: %1$s received from %2$s.', 'wc-moldovaagroindbank'), $trans_id, $this->get_method_title()));
            $this->log($message, \WC_Log_Levels::ERROR);

            wc_add_notice($message, 'error');
            $this->logs_admin_website_notice();

            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        $order_id = $order->get_id();
        $transaction_result = $this->get_transaction_result($trans_id);
        if (!empty($transaction_result)) {
            $result = strval($transaction_result[self::MAIB_RESULT]);
            if (self::MAIB_RESULT_OK === $result) {
                //region Update order payment data
                //https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#apis-for-gettingsetting-posts-and-postmeta
                foreach ($transaction_result as $key => $value) {
                    $order->update_meta_data(strtolower(self::MOD_PREFIX . $key), $value);
                }

                $order->save();

                $rrn = $transaction_result[self::MAIB_RESULT_RRN];
                $order->payment_complete($rrn);
                //endregion

                $transaction_type = self::get_order_transaction_type($order);
                $message_action = self::TRANSACTION_TYPE_CHARGE === $transaction_type
                    /* translators: 1: Order ID, 2: Payment method title, 3: Payment data */
                    ? __('Order #%1$s payment completed via %2$s: %3$s', 'wc-moldovaagroindbank')
                    /* translators: 1: Order ID, 2: Payment method title, 3: Payment data */
                    : __('Order #%1$s payment authorized via %2$s: %3$s', 'wc-moldovaagroindbank');

                $message = esc_html(sprintf($message_action, $order_id, $this->get_method_title(), $rrn));
                $message = $this->get_test_message($message);
                $this->log(
                    $message,
                    \WC_Log_Levels::INFO,
                    array(
                        'transaction_result' => $transaction_result,
                    )
                );

                $order->add_order_note($message);
                wc_add_notice($message, 'success');

                wp_safe_redirect($this->get_return_url($order));
                exit;
            }
        }

        /* translators: 1: Order ID, 2: Payment method title */
        $message = esc_html(sprintf(__('Order #%1$s payment failed via %2$s.', 'wc-moldovaagroindbank'), $order_id, $this->get_method_title()));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::ERROR,
            array(
                'transaction_result' => $transaction_result,
            )
        );

        $order->add_order_note($message);
        wc_add_notice($message, 'error');
        $this->logs_admin_website_notice();

        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    /**
     * @param  int    $order_id
     * @param  float  $amount
     * @param  string $reason
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        if (!$this->check_settings()) {
            $message = wp_strip_all_tags($this->get_settings_admin_message());
            return new \WP_Error('check_settings', $message);
        }

        $order = wc_get_order($order_id);
        $order_total = floatval($order->get_total());
        $order_currency = $order->get_currency();
        $amount = isset($amount) ? floatval($amount) : $order_total;

        $trans_id = self::get_order_transaction_id($order);
        if (empty($trans_id)) {
            /* translators: 1: Order ID, 2: Meta field key */
            $message = esc_html(sprintf(__('Order #%1$s missing meta field %2$s.', 'wc-moldovaagroindbank'), $order_id, self::MOD_TRANSACTION_ID));
            return new \WP_Error('order_transaction_id', $message);
        }

        $revert_result = null;
        try {
            $client = $this->init_maib_client();
            $revert_result = $client->revertTransaction($trans_id, $amount);
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'order_id' => $order_id,
                    'amount' => $amount,
                    'reason' => $reason,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        if (!empty($revert_result)) {
            $result = strval($revert_result[self::MAIB_RESULT]);
            if (self::MAIB_RESULT_REVERSED === $result || self::MAIB_RESULT_OK === $result) {
                /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
                $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s approved.', 'wc-moldovaagroindbank'), $order_id, $this->format_price($amount, $order_currency), $this->get_method_title()));
                $message = $this->get_test_message($message);
                $this->log(
                    $message,
                    \WC_Log_Levels::INFO,
                    array(
                        'revert_result' => $revert_result,
                    )
                );

                $order->add_order_note($message);
                return true;
            }
        }

        /* translators: 1: Order ID, 2: Refund amount, 3: Payment method title */
        $message = esc_html(sprintf(__('Order #%1$s refund of %2$s via %3$s failed.', 'wc-moldovaagroindbank'), $order_id, $this->format_price($amount, $order_currency), $this->get_method_title()));
        $message = $this->get_test_message($message);
        $this->log(
            $message,
            \WC_Log_Levels::ERROR,
            array(
                'revert_result' => $revert_result,
            )
        );

        $order->add_order_note($message);
        return new \WP_Error('process_refund', $message);
    }

    public function close_day()
    {
        $closeday_result = null;

        if ($this->check_settings()) {
            try {
                $client = $this->init_maib_client();
                $closeday_result = $client->closeDay();

                $this->log(
                    __FUNCTION__,
                    \WC_Log_Levels::DEBUG,
                    array(
                        'closeday_result' => $closeday_result,
                        'backtrace' => true,
                    )
                );
            } catch (\Exception $ex) {
                $message_result = $ex->getMessage();
                $this->log(
                    $message_result,
                    \WC_Log_Levels::ERROR,
                    array(
                        'closeday_result' => $closeday_result,
                        'exception' => (string) $ex,
                        'backtrace' => true,
                    )
                );
            }

            if (!empty($closeday_result)) {
                $message_result = wp_json_encode($closeday_result);
                $result = strval($closeday_result[self::MAIB_RESULT]);
                if (self::MAIB_RESULT_OK === $result) {
                    /* translators: 1: Payment method title, 2: Payment gateway response */
                    $message = esc_html(sprintf(__('Close business day via %1$s succeeded: %2$s', 'wc-moldovaagroindbank'), $this->get_method_title(), $message_result));
                    $this->log($message, \WC_Log_Levels::INFO);

                    return $message;
                }
            }
        } else {
            /* translators: 1: Payment method title */
            $message_result = esc_html(sprintf(__('%1$s is not properly configured.', 'wc-moldovaagroindbank'), $this->get_method_title()));
        }

        /* translators: 1: Payment method title, 2: Payment gateway response */
        $message = esc_html(sprintf(__('Close business day via %1$s failed: %2$s', 'wc-moldovaagroindbank'), $this->get_method_title(), $message_result));
        $this->log($message, \WC_Log_Levels::ERROR);

        return $message;
    }
    //endregion

    //region Order
    protected static function get_order_transaction_id(\WC_Order $order)
    {
        //https://woocommerce.github.io/code-reference/classes/WC-Data.html#method_get_meta
        $trans_id = strval($order->get_meta(self::MOD_TRANSACTION_ID, true));
        return $trans_id;
    }

    protected static function get_order_transaction_type(\WC_Order $order)
    {
        $transaction_type = strval($order->get_meta(self::MOD_TRANSACTION_TYPE, true));
        return $transaction_type;
    }
    //endregion

    //region Utility
    //https://en.wikipedia.org/wiki/ISO_4217
    private static $currency_numcodes = array(
        'EUR' => 978,
        'USD' => 840,
        'MDL' => 498,
    );

    protected static function get_currency_numcode(string $currency)
    {
        return self::$currency_numcodes[$currency];
    }

    protected static function get_client_ip()
    {
        return \WC_Geolocation::get_ip_address();
    }

    protected static function static_log(string $message, string $level = \WC_Log_Levels::DEBUG)
    {
        $logger = wc_get_logger();
        $log_context = array('source' => self::MOD_ID);
        $logger->log($level, $message, $log_context);
    }
    //endregion

    //region Admin
    public static function order_actions(array $actions, \WC_Order $order)
    {
        if ($order->get_payment_method() !== self::MOD_ID) {
            return $actions;
        }

        if ($order->is_paid()) {
            $transaction_type = self::get_order_transaction_type($order);
            if (self::TRANSACTION_TYPE_AUTHORIZATION === $transaction_type) {
                /* translators: 1: Payment method title */
                $actions[self::MOD_ACTION_COMPLETE_TRANSACTION] = esc_html(sprintf(__('Complete %1$s transaction', 'wc-moldovaagroindbank'), self::MOD_TITLE));
            }
        } elseif ($order->needs_payment()) {
            /* translators: 1: Payment method title */
            $actions[self::MOD_ACTION_VERIFY_TRANSACTION] = esc_html(sprintf(__('Verify %1$s transaction', 'wc-moldovaagroindbank'), self::MOD_TITLE));
        }

        return $actions;
    }

    public static function action_complete_transaction(\WC_Order $order)
    {
        /** @var WC_Gateway_MAIB $plugin */
        $plugin = self::get_payment_gateway_instance();
        return $plugin->complete_transaction($order);
    }

    public static function action_verify_transaction(\WC_Order $order)
    {
        /** @var WC_Gateway_MAIB $plugin */
        $plugin = self::get_payment_gateway_instance();
        return $plugin->verify_transaction($order);
    }

    public static function action_close_day()
    {
        /** @var WC_Gateway_MAIB $plugin */
        $plugin = self::get_payment_gateway_instance();
        $result = $plugin->close_day();

        //https://github.com/woocommerce/action-scheduler/issues/215
        $action_id = self::find_scheduled_action(\ActionScheduler_Store::STATUS_RUNNING);
        $logger = \ActionScheduler::logger();
        $logger->log($action_id, $result);
    }

    public static function register_scheduled_actions()
    {
        if (false !== as_next_scheduled_action(self::MOD_ACTION_CLOSE_DAY)) {
            /* translators: 1: Scheduled action name */
            $message = esc_html(sprintf(__('Scheduled action %1$s is already registered.', 'wc-moldovaagroindbank'), self::MOD_ACTION_CLOSE_DAY));
            self::static_log($message, \WC_Log_Levels::WARNING);

            self::unregister_scheduled_actions();
        }

        $timezone_id = wc_timezone_string();
        $timestamp = as_get_datetime_object('tomorrow - 1 minute', $timezone_id);
        $timestamp->setTimezone(new \DateTimeZone('UTC'));

        $cron_schedule = $timestamp->format('i H * * *'); // '59 23 * * *'
        $action_id = as_schedule_cron_action(null, $cron_schedule, self::MOD_ACTION_CLOSE_DAY, array(), self::MOD_ID);

        /* translators: 1: Scheduled action name, 2: Timezone name, 3: Scheduled action ID */
        $message = esc_html(sprintf(__('Registered scheduled action %1$s in timezone %2$s with ID %3$s.', 'wc-moldovaagroindbank'), self::MOD_ACTION_CLOSE_DAY, $timezone_id, $action_id));
        self::static_log($message, \WC_Log_Levels::INFO);
    }

    public static function unregister_scheduled_actions()
    {
        as_unschedule_all_actions(self::MOD_ACTION_CLOSE_DAY);

        /* translators: 1: Scheduled action name */
        $message = esc_html(sprintf(__('Unregistered scheduled action %1$s.', 'wc-moldovaagroindbank'), self::MOD_ACTION_CLOSE_DAY));
        self::static_log($message, \WC_Log_Levels::INFO);
    }

    protected static function find_scheduled_action(string $status = null)
    {
        $params = $status ? array('status' => $status) : null;
        $action_id = \ActionScheduler::store()->find_action(self::MOD_ACTION_CLOSE_DAY, $params);
        return $action_id;
    }
    //endregion
}
