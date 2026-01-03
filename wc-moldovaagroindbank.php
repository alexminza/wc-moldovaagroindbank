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
 */

// Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/wc-moldovaagroindbank
// This plugin is based on MAIB Payment PHP SDK https://github.com/maibank/maibapi (https://packagist.org/packages/maib/maibapi)

if(!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// https://vanrossum.dev/37-wordpress-and-composer
// https://github.com/Automattic/jetpack-autoloader
require_once __DIR__ . '/vendor/autoload_packages.php';

use Maib\MaibApi\MaibClient;

add_action('plugins_loaded', 'maib_plugins_loaded_init', 0);

function maib_plugins_loaded_init() {
	// https://developer.woocommerce.com/docs/features/payments/payment-gateway-plugin-base/
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

	class WC_Gateway_MAIB extends WC_Payment_Gateway {
		#region Constants
		const MOD_ID      = 'moldovaagroindbank';
		const MOD_PREFIX  = 'maib_';
		const MOD_TITLE   = 'maib';
		const MOD_VERSION = '1.5.0';

		const TRANSACTION_TYPE_CHARGE = 'charge';
		const TRANSACTION_TYPE_AUTHORIZATION = 'authorization';

		const LOGO_TYPE_BANK       = 'bank';
		const LOGO_TYPE_SYSTEMS    = 'systems';
		const LOGO_TYPE_NONE       = 'none';

		const MOD_TRANSACTION_TYPE = self::MOD_PREFIX . 'transaction_type';
		const MOD_TRANSACTION_ID   = self::MOD_PREFIX . 'transaction_id';
		const MOD_CLOSEDAY_ACTION  = self::MOD_PREFIX . 'close_day';

		const SUPPORTED_CURRENCIES = ['MDL', 'EUR', 'USD'];
		const ORDER_TEMPLATE       = 'Order #%1$s';

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

		const MAIB_ERROR                = 'error';
		#endregion

		protected $logo_type, $testmode, $debug, $logger, $transaction_type, $order_template;
		protected $base_url, $redirect_url, $maib_pfxcert, $maib_pcert, $maib_key, $maib_key_password;

		public function __construct() {
			$this->id                 = self::MOD_ID;
			$this->method_title       = self::MOD_TITLE;
			$this->method_description = 'Payment Gateway for maib for WooCommerce';
			$this->has_fields         = false;
			$this->supports           = array('products', 'refunds');

			#region Initialize user set variables
			$this->enabled            = $this->get_option('enabled', 'no');
			$this->title              = $this->get_option('title', $this->method_title);
			$this->description        = $this->get_option('description');

			$this->logo_type          = $this->get_option('logo_type', self::LOGO_TYPE_BANK);
			$this->icon               = apply_filters('woocommerce_moldovaagroindbank_icon', self::get_logo_icon($this->logo_type));

			$this->testmode           = wc_string_to_bool($this->get_option('testmode', 'no'));
			$this->debug              = wc_string_to_bool($this->get_option('debug', 'no'));
			$this->logger             = new WC_Logger(null, $this->debug ? WC_Log_Levels::DEBUG : WC_Log_Levels::INFO);

			if($this->testmode)
				$this->description = $this->get_test_message($this->description);

			$this->transaction_type   = $this->get_option('transaction_type', self::TRANSACTION_TYPE_CHARGE);
			$this->order_template     = $this->get_option('order_template', self::ORDER_TEMPLATE);

			#https://github.com/maibank/maibapi/blob/main/src/MaibApi/MaibClient.php
			$this->base_url           = $this->testmode ? MaibClient::MAIB_TEST_BASE_URI : MaibClient::MAIB_LIVE_BASE_URI;
			$this->redirect_url       = $this->testmode ? MaibClient::MAIB_TEST_REDIRECT_URL : MaibClient::MAIB_LIVE_REDIRECT_URL;

			$this->maib_pfxcert       = $this->get_option('maib_pfxcert');
			$this->maib_pcert         = $this->get_option('maib_pcert');
			$this->maib_key           = $this->get_option('maib_key');
			$this->maib_key_password  = $this->get_option('maib_key_password');

			$this->init_form_fields();
			$this->init_settings();

			$this->initialize_certificates();
			#endregion

			if(is_admin())
				add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));

			add_action("woocommerce_api_wc_{$this->id}", array($this, 'check_response'));
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'         => array(
					'title'       => __('Enable/Disable', 'wc-moldovaagroindbank'),
					'type'        => 'checkbox',
					'label'       => __('Enable this gateway', 'wc-moldovaagroindbank'),
					'default'     => 'yes'
				),
				'title'           => array(
					'title'       => __('Title', 'wc-moldovaagroindbank'),
					'type'        => 'text',
					'description' => __('Payment method title that the customer will see during checkout.', 'wc-moldovaagroindbank'),
					'desc_tip'    => true,
					'default'     => self::MOD_TITLE
				),
				'description'     => array(
					'title'       => __('Description', 'wc-moldovaagroindbank'),
					'type'        => 'textarea',
					'description' => __('Payment method description that the customer will see during checkout.', 'wc-moldovaagroindbank'),
					'desc_tip'    => true,
					'default'     => ''
				),
				'logo_type' => array(
					'title'       => __('Logo', 'wc-moldovaagroindbank'),
					'type'        => 'select',
					'class'       => 'wc-enhanced-select',
					'description' => __('Payment method logo image that the customer will see during checkout.', 'wc-moldovaagroindbank'),
					'desc_tip'    => true,
					'default'     => self::LOGO_TYPE_BANK,
					'options'     => array(
						self::LOGO_TYPE_BANK    => __('Bank logo', 'wc-moldovaagroindbank'),
						self::LOGO_TYPE_SYSTEMS => __('Payment systems logos', 'wc-moldovaagroindbank'),
						self::LOGO_TYPE_NONE    => __('No logo', 'wc-moldovaagroindbank')
					)
				),

				'testmode'        => array(
					'title'       => __('Test mode', 'wc-moldovaagroindbank'),
					'type'        => 'checkbox',
					'label'       => __('Enabled', 'wc-moldovaagroindbank'),
					'description' => __('Use Test or Live bank gateway to process the payments. Disable when ready to accept live payments.', 'wc-moldovaagroindbank'),
					'desc_tip'    => true,
					'default'     => 'no'
				),
				'debug'           => array(
					'title'       => __('Debug mode', 'wc-moldovaagroindbank'),
					'type'        => 'checkbox',
					'label'       => __('Enable logging', 'wc-moldovaagroindbank'),
					'default'     => 'no',
					'description' => sprintf('<a href="%2$s">%1$s</a>', esc_html__('View logs', 'wc-moldovaagroindbank'), esc_url(self::get_logs_url())),
					'desc_tip'    => __('Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', 'wc-moldovaagroindbank')
				),

				'transaction_type' => array(
					'title'        => __('Transaction type', 'wc-moldovaagroindbank'),
					'type'         => 'select',
					'class'        => 'wc-enhanced-select',
					'description'  => __('Select how transactions should be processed. Charge submits all transactions for settlement, Authorization simply authorizes the order total for capture later.', 'wc-moldovaagroindbank'),
					'desc_tip'     => true,
					'default'      => self::TRANSACTION_TYPE_CHARGE,
					'options'      => array(
						self::TRANSACTION_TYPE_CHARGE        => __('Charge', 'wc-moldovaagroindbank'),
						self::TRANSACTION_TYPE_AUTHORIZATION => __('Authorization', 'wc-moldovaagroindbank')
					)
				),
				'order_template'  => array(
					'title'       => __('Order description', 'wc-moldovaagroindbank'),
					'type'        => 'text',
					'description' => __('Format: <code>%1$s</code> - Order ID, <code>%2$s</code> - Order items summary', 'wc-moldovaagroindbank'),
					'desc_tip'    => __('Order description that the customer will see on the bank payment page.', 'wc-moldovaagroindbank'),
					'default'     => self::ORDER_TEMPLATE
				),

				'connection_settings' => array(
					'title'       => __('Connection Settings', 'wc-moldovaagroindbank'),
					'description' => sprintf('%1$s<br /><br /><a href="#" id="woocommerce_moldovaagroindbank_basic_settings" class="button">%2$s</a> <a href="#" id="woocommerce_moldovaagroindbank_advanced_settings" class="button">%3$s</a>',
						esc_html__('Use Basic settings to upload the certificate file received from the bank or configure manually using Advanced settings below.', 'wc-moldovaagroindbank'),
						esc_html__('Basic settings&raquo;', 'wc-moldovaagroindbank'),
						esc_html__('Advanced settings&raquo;', 'wc-moldovaagroindbank')),
					'type'        => 'title'
				),
				'maib_pfxcert' => array(
					'title'       => __('Client certificate (PFX)', 'wc-moldovaagroindbank'),
					'type'        => 'file',
					'description' => __('Uploaded PFX certificate will be processed and converted to PEM format. Advanced settings will be overwritten and configured automatically.', 'wc-moldovaagroindbank'),
					'desc_tip'    => true,
					'custom_attributes' => array(
						'accept' => '.pfx'
					)
				),

				'maib_pcert'      => array(
					'title'       => __('Client certificate file', 'wc-moldovaagroindbank'),
					'type'        => 'text',
					'description' => '<code>/path/to/pcert.pem</code>',
					'default'     => ''
				),
				'maib_key'        => array(
					'title'       => __('Private key file', 'wc-moldovaagroindbank'),
					'type'        => 'text',
					'description' => '<code>/path/to/key.pem</code>',
					'default'     => ''
				),
				'maib_key_password' => array(
					'title'       => __('Certificate / private key passphrase', 'wc-moldovaagroindbank'),
					'type'        => 'password',
					'description' => __('Leave empty if certificate / private key is not encrypted.', 'wc-moldovaagroindbank'),
					'desc_tip'    => true,
					'placeholder' => __('Optional', 'wc-moldovaagroindbank'),
					'default'     => ''
				),

				'payment_notification' => array(
					'title'       => __('Payment Notification', 'wc-moldovaagroindbank'),
					'description' => sprintf('%1$s<br /><br /><b>%2$s:</b> <code>%3$s</code>',
						esc_html__('Provide this URL to the bank to enable online payment notifications.', 'wc-moldovaagroindbank'),
						esc_html__('Callback URL', 'wc-moldovaagroindbank'),
						esc_url($this->get_callback_url())),
					'type'        => 'title'
				)
			);
		}

		protected static function get_logo_icon($logo_type) {
			$plugin_dir = plugin_dir_url(__FILE__);

			switch($logo_type) {
				case self::LOGO_TYPE_BANK:
					return "{$plugin_dir}assets/img/maib.png";
					break;
				case self::LOGO_TYPE_SYSTEMS:
					return "{$plugin_dir}assets/img/paymentsystems.png";
					break;
				case self::LOGO_TYPE_NONE:
					return '';
					break;
			}

			return '';
		}

		public function is_valid_for_use() {
			if(!in_array(get_option('woocommerce_currency'), self::SUPPORTED_CURRENCIES)) {
				return false;
			}

			return true;
		}

		public function is_available() {
			if(!$this->is_valid_for_use())
				return false;

			if(!$this->check_settings())
				return false;

			return parent::is_available();
		}

		public function needs_setup() {
			return !$this->check_settings();
		}

		public function admin_options() {
			$this->validate_settings();
			$this->display_errors();

			//https://developer.woocommerce.com/2025/11/19/deprecation-of-wc_enqueue_js-in-10-4/
			$script_handle = self::MOD_PREFIX . 'connection_settings';
			wp_register_script($script_handle, '', array('jquery'), false, true);
			wp_enqueue_script($script_handle);

			wp_add_inline_script($script_handle,
				'jQuery(function() {
					var basic_fields_ids    = "#woocommerce_moldovaagroindbank_maib_pfxcert, #woocommerce_moldovaagroindbank_maib_key_password";
					var advanced_fields_ids = "#woocommerce_moldovaagroindbank_maib_pcert, #woocommerce_moldovaagroindbank_maib_key, #woocommerce_moldovaagroindbank_maib_key_password";

					var basic_fields    = jQuery(basic_fields_ids).closest("tr");
					var advanced_fields = jQuery(advanced_fields_ids).closest("tr");

					jQuery(document).ready(function() {
						basic_fields.hide();
						advanced_fields.hide();
					});

					jQuery("#woocommerce_moldovaagroindbank_basic_settings").on("click", function() {
						advanced_fields.hide();
						basic_fields.show();
						return false;
					});

					jQuery("#woocommerce_moldovaagroindbank_advanced_settings").on("click", function() {
						basic_fields.hide();
						advanced_fields.show();
						return false;
					});
				});'
			);

			parent::admin_options();
		}

		public function process_admin_options() {
			$this->process_pfx_setting('woocommerce_moldovaagroindbank_maib_pfxcert', $this->maib_pfxcert, 'woocommerce_moldovaagroindbank_maib_key_password');

			return parent::process_admin_options();
		}

		protected function check_settings() {
			return !self::string_empty($this->maib_pcert)
				&& !self::string_empty($this->maib_key);
		}

		protected function validate_settings() {
			$validate_result = true;

			if(!$this->is_valid_for_use()) {
				$this->add_error(sprintf('<strong>%1$s: %2$s</strong>. %3$s: %4$s',
					esc_html__('Unsupported store currency', 'wc-moldovaagroindbank'),
					esc_html(get_option('woocommerce_currency')),
					esc_html__('Supported currencies', 'wc-moldovaagroindbank'),
					esc_html(join(', ', self::SUPPORTED_CURRENCIES))));

				$validate_result = false;
			}

			if(!$this->check_settings()) {
				$message_instructions = sprintf(__('See plugin documentation for <a href="%1$s" target="_blank">installation instructions</a>.', 'wc-moldovaagroindbank'), 'https://wordpress.org/plugins/wc-moldovaagroindbank/#installation');
				$this->add_error(sprintf('<strong>%1$s</strong>: %2$s. %3$s', esc_html__('Connection Settings', 'wc-moldovaagroindbank'), esc_html__('Not configured', 'wc-moldovaagroindbank'), wp_kses_post($message_instructions)));
				$validate_result = false;
			} else {
				$result = $this->validate_certificate($this->maib_pcert);
				if(!self::string_empty($result)) {
					$this->add_error(sprintf('<strong>%1$s</strong>: %2$s', esc_html__('Client certificate file', 'wc-moldovaagroindbank'), esc_html($result)));
					$validate_result = false;
				}

				$result = $this->validate_private_key($this->maib_pcert, $this->maib_key, $this->maib_key_password);
				if(!self::string_empty($result)) {
					$this->add_error(sprintf('<strong>%1$s</strong>: %2$s', esc_html__('Private key file', 'wc-moldovaagroindbank'), esc_html($result)));
					$validate_result = false;
				}
			}

			return $validate_result;
		}

		protected function logs_admin_website_notice() {
			if(current_user_can('manage_woocommerce')) {
				$message = $this->get_logs_admin_message();
				wc_add_notice($message, 'error');
			}
		}

		protected function logs_admin_notice() {
			$message = $this->get_logs_admin_message();
			WC_Admin_Notices::add_custom_notice(self::MOD_ID . '_logs_admin_notice', $message);
		}

		protected function settings_admin_notice() {
			$message = $this->get_settings_admin_message();
			WC_Admin_Notices::add_custom_notice(self::MOD_ID . '_settings_admin_notice', $message);
		}

		protected function get_settings_admin_message() {
			$message = sprintf(wp_kses_post(__('%1$s is not properly configured. Verify plugin <a href="%2$s">Connection Settings</a>.', 'wc-moldovaagroindbank')), esc_html($this->method_title), esc_url(self::get_settings_url()));
			return $message;
		}

		protected function get_logs_admin_message() {
			$message = sprintf(wp_kses_post(__('See <a href="%2$s">%1$s settings</a> page for log details and setup instructions.', 'wc-moldovaagroindbank')), esc_html($this->method_title), esc_url(self::get_settings_url()));
			return $message;
		}

		#region Certificates
		protected function process_pfx_setting($pfxFieldId, $pfxOptionValue, $passFieldId) {
			try {
				if(array_key_exists($pfxFieldId, $_FILES)) {
					$pfxFile = $_FILES[$pfxFieldId];
					$tmpName = $pfxFile['tmp_name'];

					if($pfxFile['error'] == UPLOAD_ERR_OK && is_uploaded_file($tmpName)) {
						$pfxData = file_get_contents($tmpName);

						if($pfxData !== false) {
							$pfxPassphrase = $_POST[$passFieldId];

							$result = $this->process_export_certificates($pfxData, $pfxPassphrase);

							$resultPCert = isset($result['pcert']) ? $result['pcert'] : null;
							$resultKey = isset($result['key']) ? $result['key'] : null;

							if(!self::string_empty($resultPCert) && !self::string_empty($resultKey)) {
								//Overwrite advanced settings values
								$_POST['woocommerce_moldovaagroindbank_maib_pcert'] = $resultPCert;
								$_POST['woocommerce_moldovaagroindbank_maib_key'] = $resultKey;

								//Certificates export success - save PFX bundle to settings
								$_POST[$pfxFieldId] = base64_encode($pfxData);

								return;
							}
						}
					}
				}
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			//Preserve existing value
			$_POST[$pfxFieldId] = $pfxOptionValue;
		}

		protected function initialize_certificates() {
			try {
				if(!is_readable($this->maib_pcert) || !is_readable($this->maib_key)) {
					if(self::is_overwritable($this->maib_pcert) && self::is_overwritable($this->maib_key)) {
						if(!self::string_empty($this->maib_pfxcert)) {
							$pfxCertData = base64_decode($this->maib_pfxcert);
							if($pfxCertData !== false) {
								$result = $this->process_export_certificates($pfxCertData, $this->maib_key_password);

								$resultPCert = isset($result['pcert']) ? $result['pcert'] : null;
								$resultKey = isset($result['key']) ? $result['key'] : null;

								if(!self::string_empty($resultPCert) && !self::string_empty($resultKey)) {
									$this->update_option('maib_pcert', $resultPCert);
									$this->update_option('maib_key', $resultKey);

									$this->maib_pcert = $resultPCert;
									$this->maib_key   = $resultKey;
								}
							}
						}
					}
				}
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}
		}

		protected function validate_certificate($certFile) {
			try {
				$validateResult = $this->validate_file($certFile);
				if(!self::string_empty($validateResult))
					return $validateResult;

				$certData = file_get_contents($certFile);
				$cert = openssl_x509_read($certData);

				if(false !== $cert) {
					$certInfo = openssl_x509_parse($cert);

					//https://php.watch/versions/8.0/OpenSSL-resource
					//https://stackoverflow.com/questions/69559775/php-openssl-free-key-deprecated
					if(\PHP_VERSION_ID < 80000)
						openssl_x509_free($cert);

					if(false !== $certInfo) {
						$valid_until = $certInfo['validTo_time_t'];

						if($valid_until < (time() - 2592000)) //Certificate already expired or expires in the next 30 days
							return sprintf(esc_html__('Certificate valid until %1$s', 'wc-moldovaagroindbank'), esc_html(date_i18n(get_option('date_format'), $valid_until)));

						return null;
					}
				}

				$this->log_openssl_errors();
				return esc_html__('Invalid certificate', 'wc-moldovaagroindbank');
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
				return esc_html__('Could not validate certificate', 'wc-moldovaagroindbank');
			}
		}

		protected function validate_private_key($certFile, $keyFile, $keyPassphrase) {
			try {
				$validateResult = $this->validate_file($keyFile);
				if(!self::string_empty($validateResult))
					return $validateResult;

				$keyData = file_get_contents($keyFile);
				$privateKey = openssl_pkey_get_private($keyData, $keyPassphrase);

				if(false === $privateKey) {
					$this->log_openssl_errors();
					return esc_html__('Invalid private key or wrong private key passphrase', 'wc-moldovaagroindbank');
				}

				$certData = file_get_contents($certFile);
				$keyCheckData = array(
					0 => $keyData,
					1 => $keyPassphrase
				);

				$validateResult = openssl_x509_check_private_key($certData, $keyCheckData);
				if(false === $validateResult) {
					$this->log_openssl_errors();
					return esc_html__('Private key does not correspond to client certificate', 'wc-moldovaagroindbank');
				}

			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
				return esc_html__('Could not validate private key', 'wc-moldovaagroindbank');
			}
		}

		protected function validate_file($file) {
			try {
				if(self::string_empty($file))
					return esc_html__('Invalid value', 'wc-moldovaagroindbank');

				if(!file_exists($file))
					return esc_html__('File not found', 'wc-moldovaagroindbank');

				if(!is_readable($file))
					return esc_html__('File not readable', 'wc-moldovaagroindbank');
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
				return esc_html__('Could not validate file', 'wc-moldovaagroindbank');
			}
		}

		protected function process_export_certificates($pfxCertData, $pfxPassphrase) {
			$result = array();
			$pfxCerts = array();
			$error = null;

			if(openssl_pkcs12_read($pfxCertData, $pfxCerts, $pfxPassphrase)) {
				if(isset($pfxCerts['pkey'])) {
					$pfxPkey = null;
					if(openssl_pkey_export($pfxCerts['pkey'], $pfxPkey, $pfxPassphrase)) {
						$result['key'] = self::save_temp_file($pfxPkey, 'key.pem');

						if(isset($pfxCerts['cert']))
							$result['pcert'] = self::save_temp_file($pfxCerts['cert'], 'pcert.pem');
					}
				}
			} else {
				$error = esc_html__('Invalid certificate or wrong passphrase', 'wc-moldovaagroindbank');
			}

			if(!self::string_empty($error)) {
				$this->log($error, WC_Log_Levels::ERROR);
				$this->log_openssl_errors();
			}

			return $result;
		}

		protected function log_openssl_errors() {
			while($opensslError = openssl_error_string())
				$this->log($opensslError, WC_Log_Levels::ERROR);
		}

		protected static function save_temp_file($fileData, $fileSuffix = '') {
			//http://www.pathname.com/fhs/pub/fhs-2.3.html#TMPTEMPORARYFILES
			$tempFileName = sprintf('%1$s%2$s_', self::MOD_PREFIX, $fileSuffix);
			$temp_file = tempnam(get_temp_dir(),  $tempFileName);

			if(!$temp_file) {
				self::static_log(sprintf(__('Unable to create temporary file: %1$s', 'wc-moldovaagroindbank'), $temp_file), WC_Log_Levels::ERROR);
				return null;
			}

			if(false === file_put_contents($temp_file, $fileData)) {
				self::static_log(sprintf(__('Unable to save data to temporary file: %1$s', 'wc-moldovaagroindbank'), $temp_file), WC_Log_Levels::ERROR);
				return null;
			}

			return $temp_file;
		}

		protected static function is_temp_file($fileName) {
			$temp_dir = get_temp_dir();
			return strncmp($fileName, $temp_dir, strlen($temp_dir)) === 0;
		}

		protected static function is_overwritable($fileName) {
			return self::string_empty($fileName) || self::is_temp_file($fileName);
		}
		#endregion

		#region Payment
		protected function init_maib_client() {
			#http://docs.guzzlephp.org/en/stable/request-options.html
			#https://www.php.net/manual/en/function.curl-setopt.php
			#https://github.com/maibank/maibapi/blob/main/README.md#usage
			$options = [
				'base_uri' => $this->base_url,
				'verify'   => true,
				'cert'     => $this->maib_pcert,
				'ssl_key'  => [$this->maib_key, $this->maib_key_password],
				'config'   => [
					'curl' => [
						CURLOPT_SSL_VERIFYHOST => 2,
						CURLOPT_SSL_VERIFYPEER => true,
					]
				]
			];

			if($this->debug) {
				$log = new \Monolog\Logger('maib_guzzle_request');
				$logFileName = WC_Log_Handler_File::get_log_file_path(self::MOD_ID . '_guzzle');
				$log->pushHandler(new \Monolog\Handler\StreamHandler($logFileName, \Monolog\Logger::DEBUG));
				$stack = \GuzzleHttp\HandlerStack::create();
				$stack->push(\GuzzleHttp\Middleware::log($log, new \GuzzleHttp\MessageFormatter(\GuzzleHttp\MessageFormatter::DEBUG)));

				$options['handler'] = $stack;
			}

			$guzzleClient = new \GuzzleHttp\Client($options);
			$client = new MaibClient($guzzleClient);

			return $client;
		}

		public function process_payment($order_id) {
			$order = wc_get_order($order_id);
			$order_total = $order->get_total();
			$order_currency_numcode = self::get_currency_numcode($order->get_currency());
			$order_description = $this->get_order_description($order);
			$client_ip = self::get_client_ip();
			$lang = self::get_language();
			$register_result = null;

			try {
				$client = $this->init_maib_client();
				$register_result = $this->transaction_type === self::TRANSACTION_TYPE_CHARGE
					? $client->registerSmsTransaction($order_total, $order_currency_numcode, $client_ip, $order_description, $lang)
					: $client->registerDmsAuthorization($order_total, $order_currency_numcode, $client_ip, $order_description, $lang);

				$this->log(self::print_var($register_result));
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			if(!empty($register_result)) {
				$trans_id = $register_result[self::MAIB_TRANSACTION_ID];
				if(!self::string_empty($trans_id)) {
					#region Update order payment transaction metadata
					//https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#apis-for-gettingsetting-posts-and-postmeta
					//https://developer.woocommerce.com/docs/hpos-extension-recipe-book/#2-supporting-high-performance-order-storage-in-your-extension
					$order->add_meta_data(self::MOD_TRANSACTION_TYPE, $this->transaction_type, true);
					$order->add_meta_data(self::MOD_TRANSACTION_ID, $trans_id, true);
					$order->save();
					#endregion

					$message = sprintf(esc_html__('Payment initiated via %1$s: %2$s', 'wc-moldovaagroindbank'), esc_html($this->method_title), esc_html(self::print_http_query($register_result)));
					$message = $this->get_test_message($message);
					$this->log($message, WC_Log_Levels::INFO);
					$order->add_order_note($message);

					$redirect = add_query_arg(self::MAIB_TRANS_ID, urlencode($trans_id), $this->redirect_url);

					return array(
						'result'   => 'success',
						'redirect' => $redirect
					);
				}
			}

			$message = sprintf(esc_html__('Payment initiation failed via %1$s: %2$s', 'wc-moldovaagroindbank'), esc_html($this->method_title), esc_html(self::print_http_query($register_result)));
			$message = $this->get_test_message($message);
			$order->add_order_note($message);
			$this->log($message, WC_Log_Levels::ERROR);

			$message = sprintf(esc_html__('Order #%1$s payment initiation failed via %2$s.', 'wc-moldovaagroindbank'), esc_html($order_id), esc_html($this->method_title));

			//https://github.com/woocommerce/woocommerce/issues/48687#issuecomment-2186475264
			$is_store_api_request = method_exists(WC(), 'is_store_api_request') && WC()->is_store_api_request();
			if($is_store_api_request) {
				throw new Exception($message);
			}

			wc_add_notice($message, 'error');
			$this->logs_admin_website_notice();

			return array(
				'result'   => 'failure',
				'messages' => $message
			);
		}

		public function complete_transaction($order) {
			if(!$this->check_settings()) {
				$this->settings_admin_notice();
				return false;
			}

			$trans_id = self::get_order_transaction_id($order);
			$order_total = self::get_order_net_total($order);
			$order_currency_numcode = self::get_currency_numcode($order->get_currency());
			$order_description = $this->get_order_description($order);
			$client_ip = self::get_client_ip();
			$lang = self::get_language();
			$complete_result = null;

			try {
				$client = $this->init_maib_client();
				$complete_result = $client->makeDMSTrans($trans_id, $order_total, $order_currency_numcode, $client_ip, $order_description, $lang);

				$this->log(self::print_var($complete_result));
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			if(!empty($complete_result)) {
				$result = $complete_result[self::MAIB_RESULT];
				if($result === self::MAIB_RESULT_OK) {
					$message = sprintf(esc_html__('Payment completed via %1$s: %2$s', 'wc-moldovaagroindbank'), esc_html($this->method_title), esc_html(self::print_http_query($complete_result)));
					$message = $this->get_test_message($message);
					$this->log($message, WC_Log_Levels::INFO);
					$order->add_order_note($message);

					$rrn = $complete_result[self::MAIB_RESULT_RRN];
					$order->payment_complete($rrn);

					return true;
				}
			}

			$message = sprintf(esc_html__('Payment completion failed via %1$s: %2$s', 'wc-moldovaagroindbank'), esc_html($this->method_title), esc_html(self::print_http_query($complete_result)));
			$message = $this->get_test_message($message);
			$order->add_order_note($message);
			$this->log($message, WC_Log_Levels::ERROR);

			$this->logs_admin_notice();

			return false;
		}

		public function verify_transaction($order) {
			if(!$this->check_settings()) {
				$this->settings_admin_notice();
				return false;
			}

			$order_id = $order->get_id();
			$trans_id = self::get_order_transaction_id($order);

			if(self::string_empty($trans_id)) {
				$message = sprintf(esc_html__('%1$s Transaction ID not found for order #%2$s.', 'wc-moldovaagroindbank'), esc_html($this->method_title), esc_html($order_id));
				$message = $this->get_test_message($message);
				$this->log($message, WC_Log_Levels::ERROR);
				$order->add_order_note($message);

				return false;
			}

			$transaction_result = $this->get_transaction_result($trans_id);
			if(!empty($transaction_result)) {
				$message = sprintf(esc_html__('Transaction status from %1$s for order #%2$s: %3$s', 'wc-moldovaagroindbank'), esc_html($this->method_title), esc_html($order_id), esc_html(self::print_http_query($transaction_result)));
				$message = $this->get_test_message($message);
				$this->log($message, WC_Log_Levels::INFO);
				$order->add_order_note($message);

				return true;
			}

			$message = sprintf(esc_html__('Could not retrieve transaction status from %1$s for order #%2$s.', 'wc-moldovaagroindbank'), esc_html($this->method_title), esc_html($order_id));
			$message = $this->get_test_message($message);
			$order->add_order_note($message);
			$this->log($message, WC_Log_Levels::ERROR);

			$this->logs_admin_notice();

			return false;
		}

		protected function get_transaction_result($trans_id) {
			$client_ip = self::get_client_ip();
			$transaction_result = null;

			try {
				$client = $this->init_maib_client();
				$transaction_result = $client->getTransactionResult($trans_id, $client_ip);

				$this->log(self::print_var($transaction_result));
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			return $transaction_result;
		}

		public function check_response() {
			if($_SERVER['REQUEST_METHOD'] === 'GET') {
				$message = sprintf(esc_html__('This %1$s Callback URL works and should not be called directly.', 'wc-moldovaagroindbank'), esc_html($this->method_title));
				wc_add_notice($message, 'notice');

				wp_safe_redirect(wc_get_cart_url());
				return false;
			}

			$trans_id = $_POST[self::MAIB_TRANS_ID];
			$trans_id = wc_clean($trans_id);

			if(self::string_empty($trans_id)) {
				$message = sprintf(esc_html__('Payment verification failed: Transaction ID not received from %1$s.', 'wc-moldovaagroindbank'), esc_html($this->method_title));
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');
				$this->logs_admin_website_notice();

				wp_safe_redirect(wc_get_cart_url());
				return false;
			}

			$order = self::get_order_by_trans_id($trans_id);
			if(!$order) {
				$message = sprintf(esc_html__('Order not found by Transaction ID: %1$s received from %2$s.', 'wc-moldovaagroindbank'), esc_html($trans_id), esc_html($this->method_title));
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');
				$this->logs_admin_website_notice();

				wp_safe_redirect(wc_get_cart_url());
				return false;
			}

			$order_id = $order->get_id();
			$transaction_result = $this->get_transaction_result($trans_id);
			if(!empty($transaction_result)) {
				$result = $transaction_result[self::MAIB_RESULT];
				if($result === self::MAIB_RESULT_OK) {
					#region Update order payment metadata
					//https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#apis-for-gettingsetting-posts-and-postmeta
					foreach($transaction_result as $key => $value)
						$order->add_meta_data(strtolower(self::MOD_PREFIX . $key), $value, true);

					$order->save();
					#endregion

					$transaction_type = self::get_order_transaction_type($order);
					$message_action = $transaction_type === self::TRANSACTION_TYPE_CHARGE
						? esc_html__('Payment completed via %1$s: %2$s', 'wc-moldovaagroindbank')
						: esc_html__('Payment authorized via %1$s: %2$s', 'wc-moldovaagroindbank');

					$message = sprintf($message_action, esc_html($this->method_title), esc_html(self::print_http_query($transaction_result)));
					$message = $this->get_test_message($message);
					$this->log($message, WC_Log_Levels::INFO);
					$order->add_order_note($message);

					$rrn = $transaction_result[self::MAIB_RESULT_RRN];
					$order->payment_complete($rrn);
					WC()->cart->empty_cart();

					$message = sprintf(esc_html__('Order #%1$s paid successfully via %2$s.', 'wc-moldovaagroindbank'), esc_html($order_id), esc_html($this->method_title));
					$this->log($message, WC_Log_Levels::INFO);
					wc_add_notice($message, 'success');

					wp_safe_redirect($this->get_return_url($order));
					return true;
				}
			}

			$message = sprintf(esc_html__('Payment failed via %1$s: %2$s', 'wc-moldovaagroindbank'), esc_html($this->method_title), esc_html(self::print_http_query($transaction_result)));
			$message = $this->get_test_message($message);
			$order->add_order_note($message);
			$this->log($message, WC_Log_Levels::ERROR);

			$message = sprintf(esc_html__('Order #%1$s payment failed via %2$s.', 'wc-moldovaagroindbank'), esc_html($order_id), esc_html($this->method_title));
			wc_add_notice($message, 'error');
			$this->logs_admin_website_notice();

			wp_safe_redirect($order->get_checkout_payment_url());
			return false;
		}

		public function process_refund($order_id, $amount = null, $reason = '') {
			if(!$this->check_settings()) {
				$message = $this->get_settings_admin_message();
				return new WP_Error('error', $message);
			}

			$order = wc_get_order($order_id);
			$trans_id = self::get_order_transaction_id($order);
			$order_total = $order->get_total();
			$order_currency = $order->get_currency();
			$revert_result = null;

			if(!isset($amount)) {
				//Refund entirely if no amount is specified
				$amount = $order_total;
			}

			try {
				$client = $this->init_maib_client();
				$revert_result = $client->revertTransaction($trans_id, $amount);

				$this->log(self::print_var($revert_result));
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			if(!empty($revert_result)) {
				$result = $revert_result[self::MAIB_RESULT];
				if($result === self::MAIB_RESULT_REVERSED || $result === self::MAIB_RESULT_OK) {
					$message = sprintf(esc_html__('Refund of %1$s %2$s via %3$s approved: %4$s', 'wc-moldovaagroindbank'), esc_html($amount), esc_html($order_currency), esc_html($this->method_title), esc_html(self::print_http_query($revert_result)));
					$message = $this->get_test_message($message);
					$this->log($message, WC_Log_Levels::INFO);
					$order->add_order_note($message);

					return true;
				}
			}

			$message = sprintf(esc_html__('Refund of %1$s %2$s via %3$s failed: %4$s', 'wc-moldovaagroindbank'), esc_html($amount), esc_html($order_currency), esc_html($this->method_title), esc_html(self::print_http_query($revert_result)));
			$message = $this->get_test_message($message);
			$order->add_order_note($message);
			$this->log($message, WC_Log_Levels::ERROR);

			$this->logs_admin_notice();

			return new WP_Error('error', $message);
		}

		public function close_day() {
			$closeday_result = null;

			if($this->check_settings()) {
				try {
					$client = $this->init_maib_client();
					$closeday_result = $client->closeDay();

					$this->log(self::print_var($closeday_result));
				} catch(Exception $ex) {
					$message_result = $ex->getMessage();
					$this->log($ex, WC_Log_Levels::ERROR);
				}

				if(!empty($closeday_result)) {
					$message_result = self::print_http_query($closeday_result);
					$result = $closeday_result[self::MAIB_RESULT];
					if($result === self::MAIB_RESULT_OK) {
						$message = sprintf(esc_html__('Close business day via %1$s succeeded: %2$s', 'wc-moldovaagroindbank'), esc_html($this->method_title), esc_html($message_result));
						$this->log($message, WC_Log_Levels::INFO);

						return $message;
					}
				}
			} else {
				$message_result = sprintf(esc_html__('%1$s is not properly configured.', 'wc-moldovaagroindbank'), esc_html($this->method_title));
			}

			$message = sprintf(esc_html__('Close business day via %1$s failed: %2$s', 'wc-moldovaagroindbank'), esc_html($this->method_title), esc_html($message_result));
			$this->log($message, WC_Log_Levels::ERROR);

			return $message;
		}
		#endregion

		#region Order
		protected static function get_order_net_total($order) {
			//https://github.com/woocommerce/woocommerce/issues/17795
			//https://github.com/woocommerce/woocommerce/pull/18196
			$total_refunded = 0;
			$order_refunds = $order->get_refunds();
			foreach($order_refunds as $refund) {
				if($refund->get_refunded_payment())
					$total_refunded += $refund->get_amount();
			}

			$order_total = $order->get_total();
			return $order_total - $total_refunded;
		}

		protected static function get_order_by_trans_id($trans_id) {
			//NOTE: MAIB Payment Gateway API does not currently support passing Order ID for transactions
			#https://stackoverflow.com/questions/71438717/extend-wc-get-orders-with-a-custom-meta-key-and-meta-value
			$args = array(
				'meta_key'   => self::MOD_TRANSACTION_ID,
				'meta_value' => $trans_id
			);

			$orders = wc_get_orders($args);
			if(count($orders) == 1) {
				return $orders[0];
			}

			self::static_log(self::print_var($orders));
			return false;
		}

		protected static function get_order_transaction_id($order) {
			//https://woocommerce.github.io/code-reference/classes/WC-Data.html#method_get_meta
			$trans_id = $order->get_meta(self::MOD_TRANSACTION_ID, true);
			return $trans_id;
		}

		protected static function get_order_transaction_type($order) {
			$transaction_type = $order->get_meta(self::MOD_TRANSACTION_TYPE, true);
			return $transaction_type;
		}

		protected function get_order_description($order) {
			$description = sprintf($this->order_template,
				$order->get_id(),
				self::get_order_items_summary($order)
			);

			return apply_filters(self::MOD_ID . '_order_description', $description, $order);
		}

		protected static function get_order_items_summary($order) {
			$items = $order->get_items();
			$items_names = array_map(function($item) { return $item->get_name(); }, $items);

			return join(', ', $items_names);
		}
		#endregion

		#region Utility
		protected function get_test_message($message) {
			if($this->testmode)
				$message = sprintf(esc_html__('TEST: %1$s', 'wc-moldovaagroindbank'), esc_html($message));

			return $message;
		}

		//https://en.wikipedia.org/wiki/ISO_4217
		private static $currency_numcodes = array(
			'EUR' => 978,
			'USD' => 840,
			'MDL' => 498
		);

		protected static function get_currency_numcode($currency) {
			return self::$currency_numcodes[$currency];
		}

		protected static function get_language() {
			$lang = get_locale();
			return substr($lang, 0, 2);
		}

		protected static function get_client_ip() {
			return WC_Geolocation::get_ip_address();
		}

		protected function get_callback_url() {
			//https://developer.woo.com/docs/woocommerce-plugin-api-callbacks/
			return WC()->api_request_url("wc_{$this->id}");
		}

		protected static function get_logs_url() {
			return add_query_arg(
				array(
					'page'   => 'wc-status',
					'tab'    => 'logs',
					'source' => self::MOD_ID
				),
				admin_url('admin.php')
			);
		}

		public static function get_settings_url() {
			return add_query_arg(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => self::MOD_ID
				),
				admin_url('admin.php')
			);
		}

		protected function log($message, $level = WC_Log_Levels::DEBUG) {
			//https://developer.woo.com/docs/logging-in-woocommerce/
			//https://stackoverflow.com/questions/1423157/print-php-call-stack
			$log_context = array('source' => self::MOD_ID);
			$this->logger->log($level, $message, $log_context);
		}

		protected static function static_log($message, $level = WC_Log_Levels::DEBUG) {
			$logger = wc_get_logger();
			$log_context = array('source' => self::MOD_ID);
			$logger->log($level, $message, $log_context);
		}

		protected static function print_var($var) {
			//https://woocommerce.github.io/code-reference/namespaces/default.html#function_wc_print_r
			return wc_print_r($var, true);
		}

		protected static function print_http_query($var) {
			if(empty($var))
				return $var;

			return is_array($var)
				? http_build_query($var)
				: $var;
		}

		protected static function string_empty($string) {
			return is_null($string) || strlen($string) === 0;
		}
		#endregion

		#region Admin
		public static function plugin_links($links) {
			$plugin_links = array(
				sprintf('<a href="%1$s">%2$s</a>', esc_url(self::get_settings_url()), esc_html__('Settings', 'wc-moldovaagroindbank'))
			);

			return array_merge($plugin_links, $links);
		}

		public static function order_actions($actions) {
			global $theorder;
			if($theorder->get_payment_method() !== self::MOD_ID) {
				return $actions;
			}

			if($theorder->is_paid()) {
				$transaction_type = self::get_order_transaction_type($theorder);
				if($transaction_type === self::TRANSACTION_TYPE_AUTHORIZATION) {
					$actions['moldovaagroindbank_complete_transaction'] = sprintf(esc_html__('Complete %1$s transaction', 'wc-moldovaagroindbank'), esc_html(self::MOD_TITLE));
				}
			} elseif ($theorder->has_status('pending')) {
				$actions['moldovaagroindbank_verify_transaction'] = sprintf(esc_html__('Verify %1$s transaction', 'wc-moldovaagroindbank'), esc_html(self::MOD_TITLE));
			}

			return $actions;
		}

		public static function action_complete_transaction($order) {
			$plugin = new self();
			return $plugin->complete_transaction($order);
		}

		public static function action_verify_transaction($order) {
			$plugin = new self();
			return $plugin->verify_transaction($order);
		}

		public static function action_close_day() {
			$plugin = new self();
			$result = $plugin->close_day();

			//https://github.com/woocommerce/action-scheduler/issues/215
			$action_id = self::find_scheduled_action(ActionScheduler_Store::STATUS_RUNNING);
			$logger = ActionScheduler::logger();
			$logger->log($action_id, $result);
		}

		public static function register_scheduled_actions() {
			if(false !== as_next_scheduled_action(self::MOD_CLOSEDAY_ACTION)) {
				$message = sprintf(esc_html__('Scheduled action %1$s is already registered.', 'wc-moldovaagroindbank'), esc_html(self::MOD_CLOSEDAY_ACTION));
				self::static_log($message, WC_Log_Levels::WARNING);

				self::unregister_scheduled_actions();
			}

			$timezoneId = wc_timezone_string();
			$timestamp = as_get_datetime_object('tomorrow - 1 minute', $timezoneId);
			$timestamp->setTimezone(new DateTimeZone('UTC'));

			$cronSchedule = $timestamp->format('i H * * *'); #'59 23 * * *'
			$action_id = as_schedule_cron_action(null, $cronSchedule, self::MOD_CLOSEDAY_ACTION, array(), self::MOD_ID);

			$message = sprintf(esc_html__('Registered scheduled action %1$s in timezone %2$s with ID %3$s.', 'wc-moldovaagroindbank'), esc_html(self::MOD_CLOSEDAY_ACTION), esc_html($timezoneId), esc_html($action_id));
			self::static_log($message, WC_Log_Levels::INFO);
		}

		public static function unregister_scheduled_actions() {
			as_unschedule_all_actions(self::MOD_CLOSEDAY_ACTION);

			$message = sprintf(esc_html__('Unregistered scheduled action %1$s.', 'wc-moldovaagroindbank'), esc_html(self::MOD_CLOSEDAY_ACTION));
			self::static_log($message, WC_Log_Levels::INFO);
		}

		protected static function find_scheduled_action($status = null) {
			$params = $status ? array('status' => $status) : null;
			$action_id = ActionScheduler::store()->find_action(self::MOD_CLOSEDAY_ACTION, $params);
			return $action_id;
		}
		#endregion

		#region WooCommerce
		public static function add_gateway($methods) {
			$methods[] = self::class;
			return $methods;
		}
		#endregion
	}

	//Add gateway to WooCommerce
	add_filter('woocommerce_payment_gateways', array(WC_MoldovaAgroindbank::class, 'add_gateway'));

	#region Admin init
	if(is_admin()) {
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_MoldovaAgroindbank::class, 'plugin_links'));

		//Add WooCommerce order actions
		add_filter('woocommerce_order_actions', array(WC_MoldovaAgroindbank::class, 'order_actions'));
		add_action('woocommerce_order_action_moldovaagroindbank_complete_transaction', array(WC_MoldovaAgroindbank::class, 'action_complete_transaction'));
		add_action('woocommerce_order_action_moldovaagroindbank_verify_transaction', array(WC_MoldovaAgroindbank::class, 'action_verify_transaction'));
	}
	#endregion

	add_action(WC_MoldovaAgroindbank::MOD_CLOSEDAY_ACTION, array(WC_MoldovaAgroindbank::class, 'action_close_day'));
}

#region Register activation hooks
function woocommerce_moldovaagroindbank_activation_deactivation($activate = true) {
	if(!class_exists(WC_MoldovaAgroindbank::class)) {
		woocommerce_moldovaagroindbank_plugins_loaded();
	}

	if(class_exists(WC_MoldovaAgroindbank::class)) {
		if($activate) {
			WC_MoldovaAgroindbank::register_scheduled_actions();
		} else {
			WC_MoldovaAgroindbank::unregister_scheduled_actions();
		}
	}
}

function woocommerce_moldovaagroindbank_activation() {
	woocommerce_moldovaagroindbank_activation_deactivation(true);
}

function woocommerce_moldovaagroindbank_deactivation() {
	woocommerce_moldovaagroindbank_activation_deactivation(false);
}

register_activation_hook(__FILE__, 'woocommerce_moldovaagroindbank_activation');
register_deactivation_hook(__FILE__, 'woocommerce_moldovaagroindbank_deactivation');
#endregion

#region Declare WooCommerce compatibility
add_action('before_woocommerce_init', function() {
	if(class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		//WooCommerce HPOS compatibility
		//https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#declaring-extension-incompatibility
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);

		//WooCommerce Cart Checkout Blocks compatibility
		//https://github.com/woocommerce/woocommerce/pull/36426
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
	}
});
#endregion

#region Register WooCommerce Blocks payment method type
add_action('woocommerce_blocks_loaded', function() {
	if(class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
		require_once plugin_dir_path(__FILE__) . 'wc-moldovaagroindbank-wbc.php';

		add_action('woocommerce_blocks_payment_method_type_registration',
			function(\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
				$payment_method_registry->register(new WC_Gateway_MAIB_WBC());
			}
		);
	}
});
#endregion
