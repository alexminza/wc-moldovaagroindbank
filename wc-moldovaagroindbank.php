<?php
/**
 * Plugin Name: WooCommerce Moldova Agroindbank Payment Gateway
 * Description: Accept Visa and Mastercard directly on your store with the Moldova Agroindbank payment gateway for WooCommerce.
 * Plugin URI: https://github.com/alexminza/wc-moldovaagroindbank
 * Version: 1.2.4
 * Author: Alexander Minza
 * Author URI: https://profiles.wordpress.org/alexminza
 * Developer: Alexander Minza
 * Developer URI: https://profiles.wordpress.org/alexminza
 * Text Domain: wc-moldovaagroindbank
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 4.8
 * Tested up to: 6.0
 * WC requires at least: 3.3
 * WC tested up to: 6.6.1
 */

//Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/wc-moldovaagroindbank
//This plugin is based on MaibApi by Fruitware https://github.com/Fruitware/MaibApi (https://packagist.org/packages/fruitware/maib-api)

if(!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

require_once(__DIR__ . '/vendor/autoload.php');

use Fruitware\MaibApi\MaibClient;
use Fruitware\MaibApi\MaibDescription;
use GuzzleHttp\Client;
use GuzzleHttp\Subscriber\Log\Formatter;
use GuzzleHttp\Subscriber\Log\LogSubscriber;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

add_action('plugins_loaded', 'woocommerce_moldovaagroindbank_init', 0);

function woocommerce_moldovaagroindbank_init() {
	if(!class_exists('WC_Payment_Gateway'))
		return;

	load_plugin_textdomain('wc-moldovaagroindbank', false, dirname(plugin_basename(__FILE__)) . '/languages');

	class WC_MoldovaAgroindbank extends WC_Payment_Gateway {
		protected $logger;

		#region Constants
		const MOD_ID             = 'moldovaagroindbank';
		const MOD_TITLE          = 'Moldova Agroindbank';
		const MOD_PREFIX         = 'maib_';
		const MOD_TEXT_DOMAIN    = 'wc-moldovaagroindbank';

		const TRANSACTION_TYPE_CHARGE = 'charge';
		const TRANSACTION_TYPE_AUTHORIZATION = 'authorization';

		const LOGO_TYPE_BANK       = 'bank';
		const LOGO_TYPE_SYSTEMS    = 'systems';

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
		#endregion

		public function __construct() {
			$plugin_dir = plugin_dir_url(__FILE__);

			$this->logger = wc_get_logger();

			$this->id                 = self::MOD_ID;
			$this->method_title       = self::MOD_TITLE;
			$this->method_description = 'WooCommerce Payment Gateway for Moldova Agroindbank';
			$this->icon               = apply_filters('woocommerce_moldovaagroindbank_icon', $plugin_dir . 'assets/img/maib.png');
			$this->has_fields         = false;
			$this->supports           = array('products', 'refunds');

			#region Initialize user set variables
			$this->enabled            = $this->get_option('enabled', 'yes');
			$this->title              = $this->get_option('title', $this->method_title);
			$this->description        = $this->get_option('description');

			$this->logo_type          = $this->get_option('logo_type', self::LOGO_TYPE_BANK);
			$this->bank_logo          = $plugin_dir . 'assets/img/maib.png';
			$this->systems_logo       = $plugin_dir . 'assets/img/paymentsystems.png';
			$plugin_icon              = ($this->logo_type === self::LOGO_TYPE_BANK ? $this->bank_logo : $this->systems_logo);
			$this->icon               = apply_filters('woocommerce_moldovaagroindbank_icon', $plugin_icon);

			$this->testmode           = 'yes' === $this->get_option('testmode', 'no');
			$this->debug              = 'yes' === $this->get_option('debug', 'no');

			$this->log_context        = array('source' => $this->id);
			$this->log_threshold      = $this->debug ? WC_Log_Levels::DEBUG : WC_Log_Levels::NOTICE;
			$this->logger             = new WC_Logger(null, $this->log_threshold);

			$this->transaction_type   = $this->get_option('transaction_type', self::TRANSACTION_TYPE_CHARGE);
			$this->transaction_auto   = false; //'yes' === $this->get_option('transaction_auto', 'no');

			$this->order_template     = $this->get_option('order_template', self::ORDER_TEMPLATE);

			$this->base_url             = ($this->testmode ? 'https://maib.ecommerce.md:21440' : 'https://maib.ecommerce.md:11440');
			$this->client_handler_url   = ($this->testmode ? 'https://maib.ecommerce.md:21443/ecomm/ClientHandler' : 'https://maib.ecommerce.md:443/ecomm01/ClientHandler');
			$this->merchant_handler_url = ($this->testmode ? '/ecomm/MerchantHandler' : '/ecomm01/MerchantHandler');
			$this->skip_receipt_page    = true;

			$this->maib_pfxcert       = $this->get_option('maib_pfxcert');
			$this->maib_pcert         = $this->get_option('maib_pcert');
			$this->maib_key           = $this->get_option('maib_key');
			$this->maib_key_password  = $this->get_option('maib_key_password');

			$this->init_form_fields();
			$this->init_settings();

			$this->initialize_certificates();

			$this->update_option('maib_callback_url', $this->get_callback_url());
			#endregion

			if(is_admin()) {
				//Save options
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			}

			add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

			if($this->transaction_auto) {
				add_filter('woocommerce_order_status_completed', array($this, 'order_status_completed'));
				add_filter('woocommerce_order_status_cancelled', array($this, 'order_status_cancelled'));
				add_filter('woocommerce_order_status_refunded', array($this, 'order_status_refunded'));
			}

			//Payment listener/API hook
			add_action('woocommerce_api_wc_' . $this->id, array($this, 'check_response'));
		}

		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'         => array(
					'title'       => __('Enable/Disable', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					'label'       => __('Enable this gateway', self::MOD_TEXT_DOMAIN),
					'default'     => 'yes'
				),
				'title'           => array(
					'title'       => __('Title', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'desc_tip'    => __('Payment method title that the customer will see during checkout.', self::MOD_TEXT_DOMAIN),
					'default'     => self::MOD_TITLE
				),
				'description'     => array(
					'title'       => __('Description', self::MOD_TEXT_DOMAIN),
					'type'        => 'textarea',
					'desc_tip'    => __('Payment method description that the customer will see during checkout.', self::MOD_TEXT_DOMAIN),
					'default'     => ''
				),
				'logo_type' => array(
					'title'       => __('Logo', self::MOD_TEXT_DOMAIN),
					'type'        => 'select',
					'class'       => 'wc-enhanced-select',
					'desc_tip'    => __('Payment method logo image that the customer will see during checkout.', self::MOD_TEXT_DOMAIN),
					'default'     => self::LOGO_TYPE_BANK,
					'options'     => array(
						self::LOGO_TYPE_BANK    => __('Bank logo', self::MOD_TEXT_DOMAIN),
						self::LOGO_TYPE_SYSTEMS => __('Payment systems logos', self::MOD_TEXT_DOMAIN)
					)
				),

				'testmode'        => array(
					'title'       => __('Test mode', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					'label'       => __('Enabled', self::MOD_TEXT_DOMAIN),
					'desc_tip'    => __('Use Test or Live bank gateway to process the payments. Disable when ready to accept live payments.', self::MOD_TEXT_DOMAIN),
					'default'     => 'no'
				),
				'debug'           => array(
					'title'       => __('Debug mode', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					'label'       => __('Enable logging', self::MOD_TEXT_DOMAIN),
					'default'     => 'no',
					'description' => sprintf('<a href="%2$s">%1$s</a>', __('View logs', self::MOD_TEXT_DOMAIN), self::get_logs_url()),
					'desc_tip'    => __('Save debug messages to the WooCommerce System Status logs. Note: this may log personal information. Use this for debugging purposes only and delete the logs when finished.', self::MOD_TEXT_DOMAIN)
				),

				'transaction_type' => array(
					'title'       => __('Transaction type', self::MOD_TEXT_DOMAIN),
					'type'        => 'select',
					'class'       => 'wc-enhanced-select',
					'desc_tip'    => __('Select how transactions should be processed. Charge submits all transactions for settlement, Authorization simply authorizes the order total for capture later.', self::MOD_TEXT_DOMAIN),
					'default'     => self::TRANSACTION_TYPE_CHARGE,
					'options'     => array(
						self::TRANSACTION_TYPE_CHARGE        => __('Charge', self::MOD_TEXT_DOMAIN),
						self::TRANSACTION_TYPE_AUTHORIZATION => __('Authorization', self::MOD_TEXT_DOMAIN)
					)
				),
				/*'transaction_auto' => array(
					'title'       => __('Transaction auto', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					//'label'       => __('Enabled', self::MOD_TEXT_DOMAIN),
					'label'       => __('Automatically complete/reverse bank transactions when order status changes', self::MOD_TEXT_DOMAIN),
					'default'     => 'no'
				),*/
				'order_template'  => array(
					'title'       => __('Order description', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => __('Format: <code>%1$s</code> - Order ID, <code>%2$s</code> - Order items summary', self::MOD_TEXT_DOMAIN),
					'desc_tip'    => __('Order description that the customer will see on the bank payment page.', self::MOD_TEXT_DOMAIN),
					'default'     => self::ORDER_TEMPLATE
				),

				'connection_settings' => array(
					'title'       => __('Connection Settings', self::MOD_TEXT_DOMAIN),
					'description' => sprintf('%1$s<br /><br /><a href="#" id="woocommerce_moldovaagroindbank_basic_settings" class="button">%2$s</a>&nbsp;%3$s&nbsp;<a href="#" id="woocommerce_moldovaagroindbank_advanced_settings" class="button">%4$s</a>',
						__('Use Basic settings to upload the certificate file received from the bank or configure manually using Advanced settings below.', self::MOD_TEXT_DOMAIN),
						__('Basic settings&raquo;', self::MOD_TEXT_DOMAIN),
						__('or', self::MOD_TEXT_DOMAIN),
						__('Advanced settings&raquo;', self::MOD_TEXT_DOMAIN)),
					'type'        => 'title'
				),
				'maib_pfxcert' => array(
					'title'       => __('Client certificate (PFX)', self::MOD_TEXT_DOMAIN),
					'type'        => 'file',
					'desc_tip'    => __('Uploaded PFX certificate will be processed and converted to PEM format. Advanced settings will be overwritten and configured automatically.', self::MOD_TEXT_DOMAIN),
					'custom_attributes' => array(
						'accept' => '.pfx'
					)
				),

				'maib_pcert'      => array(
					'title'       => __('Client certificate file', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => '<code>/path/to/pcert.pem</code>',
					'default'     => ''
				),
				'maib_key'        => array(
					'title'       => __('Private key file', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => '<code>/path/to/key.pem</code>',
					'default'     => ''
				),
				'maib_key_password' => array(
					'title'       => __('Certificate / private key passphrase', self::MOD_TEXT_DOMAIN),
					'type'        => 'password',
					'desc_tip'    => __('Leave empty if certificate / private key is not encrypted.', self::MOD_TEXT_DOMAIN),
					'placeholder' => __('Optional', self::MOD_TEXT_DOMAIN),
					'default'     => ''
				),

				'payment_notification' => array(
					'title'       => __('Payment Notification', self::MOD_TEXT_DOMAIN),
					'description' => __('Provide this URL to the bank to enable online payment notifications.', self::MOD_TEXT_DOMAIN),
					'type'        => 'title'
				),
				'maib_callback_url'  => array(
					'title'       => __('Callback URL', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					//'default'     => $this->get_callback_url(),
					//'disabled'    => true,
					'desc_tip'    => sprintf(__('Bank payment gateway URL: %1$s', self::MOD_TEXT_DOMAIN), esc_url($this->get_maib_gateway_url())),
					'custom_attributes' => array(
						'readonly' => 'readonly'
					)
				)
			);
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

			wc_enqueue_js('
				jQuery(function() {
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
				});
			');

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
					__('Unsupported store currency', self::MOD_TEXT_DOMAIN),
					get_option('woocommerce_currency'),
					__('Supported currencies', self::MOD_TEXT_DOMAIN),
					join(', ', self::SUPPORTED_CURRENCIES)));

				$validate_result = false;
			}

			if(!$this->check_settings()) {
				$this->add_error(sprintf('<strong>%1$s</strong>: %2$s', __('Connection Settings', self::MOD_TEXT_DOMAIN), __('Not configured', self::MOD_TEXT_DOMAIN)));
				$validate_result = false;
			}

			$result = $this->validate_certificate($this->maib_pcert);
			if(!self::string_empty($result)) {
				$this->add_error(sprintf('<strong>%1$s</strong>: %2$s', __('Client certificate file', self::MOD_TEXT_DOMAIN), $result));
				$validate_result = false;
			}

			$result = $this->validate_private_key($this->maib_pcert, $this->maib_key, $this->maib_key_password);
			if(!self::string_empty($result)) {
				$this->add_error(sprintf('<strong>%1$s</strong>: %2$s', __('Private key file', self::MOD_TEXT_DOMAIN), $result));
				$validate_result = false;
			}

			return $validate_result;
		}

		protected function settings_admin_notice() {
			if(current_user_can('manage_woocommerce')) {
				$message = sprintf(__('Please review the <a href="%1$s">payment method settings</a> page for log details and setup instructions.', self::MOD_TEXT_DOMAIN), self::get_settings_url());
				wc_add_notice($message, 'error');
			}
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
					openssl_x509_free($cert);

					if(false !== $certInfo) {
						$valid_until = $certInfo['validTo_time_t'];

						if($valid_until < (time() - 2592000)) //Certificate already expired or expires in the next 30 days
							return sprintf(__('Certificate valid until %1$s', self::MOD_TEXT_DOMAIN), date_i18n(get_option('date_format'), $valid_until));

						return null;
					}
				}

				$this->log_openssl_errors();
				return __('Invalid certificate', self::MOD_TEXT_DOMAIN);
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
				return __('Could not validate certificate', self::MOD_TEXT_DOMAIN);
			}
		}

		protected function validate_private_key($certFile, $keyFile, $keyPassphrase) {
			try {
				$validateResult = $this->validate_file($keyFile);
				if(!self::string_empty($validateResult))
					return $validateResult;

				$keyData = file_get_contents($keyFile);
				$privateKey = openssl_pkey_get_private($keyData, $keyPassphrase);

				if(false !== $privateKey) {
					openssl_pkey_free($privateKey);
				} else {
					$this->log_openssl_errors();
					return __('Invalid private key or wrong private key passphrase', self::MOD_TEXT_DOMAIN);
				}

				$certData = file_get_contents($certFile);
				$keyCheckData = array(
					0 => $keyData,
					1 => $keyPassphrase
				);

				$validateResult = openssl_x509_check_private_key($certData, $keyCheckData);
				if(false === $validateResult) {
					$this->log_openssl_errors();
					return __('Private key does not correspond to client certificate', self::MOD_TEXT_DOMAIN);
				}

			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
				return __('Could not validate private key', self::MOD_TEXT_DOMAIN);
			}
		}

		protected function validate_file($file) {
			try {
				if(self::string_empty($file))
					return __('Invalid value', self::MOD_TEXT_DOMAIN);

				if(!file_exists($file))
					return __('File not found', self::MOD_TEXT_DOMAIN);

				if(!is_readable($file))
					return __('File not readable', self::MOD_TEXT_DOMAIN);
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
				return __('Could not validate file', self::MOD_TEXT_DOMAIN);
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

						/*if(isset($pfxCerts['extracerts'])) {
							$pfxExtraCerts = '';
							foreach($pfxCerts['extracerts'] as $extraCert)
								$pfxExtraCerts .= $extraCert;

							$result['cacert'] = self::save_temp_file($pfxExtraCerts, 'cacert.pem');
						}*/
					}
				}
			} else {
				$error = __('Invalid certificate or wrong passphrase', self::MOD_TEXT_DOMAIN);
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

		static function save_temp_file($fileData, $fileSuffix = '') {
			//http://www.pathname.com/fhs/pub/fhs-2.3.html#TMPTEMPORARYFILES
			$tempFileName = sprintf('%1$s%2$s_', self::MOD_PREFIX, $fileSuffix);
			$temp_file = tempnam(get_temp_dir(),  $tempFileName);

			if(!$temp_file) {
				$this->log(sprintf(__('Unable to create temporary file: %1$s', self::MOD_TEXT_DOMAIN), $temp_file), WC_Log_Levels::ERROR);
				return null;
			}

			if(false === file_put_contents($temp_file, $fileData)) {
				$this->log(sprintf(__('Unable to save data to temporary file: %1$s', self::MOD_TEXT_DOMAIN), $temp_file), WC_Log_Levels::ERROR);
				return null;
			}

			return $temp_file;
		}

		static function is_temp_file($fileName) {
			$temp_dir = get_temp_dir();
			return strncmp($fileName, $temp_dir, strlen($temp_dir)) === 0;
		}

		static function is_overwritable($fileName) {
			return self::string_empty($fileName) || self::is_temp_file($fileName);
		}
		#endregion

		protected function init_maib_client() {
			#http://docs.guzzlephp.org/en/stable/request-options.html
			#https://www.php.net/manual/en/function.curl-setopt.php
			$options = [
				'base_url' => $this->base_url,
				'debug'    => $this->debug,
				'verify'   => true,
				'defaults' => [
					'verify'  => true,
					'cert'    => $this->maib_pcert,
					'ssl_key' => [$this->maib_key, $this->maib_key_password]
				]
			];

			#region Init Client
			$guzzleClient = new Client($options);
			//https://github.com/alexminza/wc-moldovaagroindbank/issues/17#issuecomment-850337174
			$maibDescription = new MaibDescription([], $this->merchant_handler_url);
			$client = new MaibClient($guzzleClient, $maibDescription);

			if($this->debug) {
				//Create a log for client class (monolog/monolog required)
				$log = new Logger('maib_guzzle_request');
				$logFileName = WC_Log_Handler_File::get_log_file_path(self::MOD_ID . '_guzzle');
				$log->pushHandler(new StreamHandler($logFileName, Logger::DEBUG));
				$subscriber = new LogSubscriber($log, Formatter::SHORT);
				$client->getHttpClient()->getEmitter()->attach($subscriber);
			}
			#endregion

			return $client;
		}

		protected function get_maib_gateway_url() {
			$gateway_url = $this->base_url . $this->merchant_handler_url;
			return $gateway_url;
		}

		public function process_payment($order_id) {
			if(!$this->check_settings()) {
				$message = sprintf(__('%1$s is not properly configured.', self::MOD_TEXT_DOMAIN), $this->method_title);

				wc_add_notice($message, 'error');
				$this->settings_admin_notice();

				return array(
					'result'   => 'failure',
					'messages' => $message
				);
			}

			$order = wc_get_order($order_id);
			$order_total = $this->price_format($order->get_total());
			$order_currency_numcode = $this->get_currency_numcode($order->get_currency());
			$order_description = $this->get_order_description($order);
			$client_ip = self::get_client_ip();
			$lang = $this->get_language();

			try {
				$client = $this->init_maib_client();
				$client_result = null;

				switch($this->transaction_type) {
					case self::TRANSACTION_TYPE_CHARGE:
						$client_result = $client->registerSmsTransaction(
							$order_total,
							$order_currency_numcode,
							$client_ip,
							$order_description,
							$lang);

						break;

					case self::TRANSACTION_TYPE_AUTHORIZATION:
						$client_result = $client->registerDmsAuthorization(
							$order_total,
							$order_currency_numcode,
							$client_ip,
							$order_description,
							$lang);

						break;

					default:
						$this->log(sprintf('Unknown transaction type: %1$s Order ID: %2$s', $this->transaction_type, $order_id), WC_Log_Levels::ERROR);
						break;
				}
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			#region Validate response
			$trans_id = null;
			if(!empty($client_result))
				$trans_id = isset($client_result[self::MAIB_TRANSACTION_ID])
					? $client_result[self::MAIB_TRANSACTION_ID]
					: null;
			#endregion

			if(!self::string_empty($trans_id)) {
				#region Update order payment transaction metadata
				self::set_post_meta($order_id, self::MOD_TRANSACTION_TYPE, $this->transaction_type);
				self::set_post_meta($order_id, self::MOD_TRANSACTION_ID, $trans_id);
				#endregion

				#region Log transaction initiation
				$message = sprintf(__('Payment initiated via %1$s: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, http_build_query($client_result));
				$message = $this->get_order_message($message);
				$this->log($message, WC_Log_Levels::INFO);
				$order->add_order_note($message);
				#endregion

				$redirect = add_query_arg(self::MAIB_TRANS_ID, urlencode($trans_id), $this->skip_receipt_page
					? $this->client_handler_url
					: $order->get_checkout_payment_url(true));

				return array(
					'result'   => 'success',
					'redirect' => $redirect
				);
			}

			$message = sprintf(__('Payment initiation failed via %1$s.', self::MOD_TEXT_DOMAIN), $this->method_title);
			$this->log($message, WC_Log_Levels::ERROR);
			$this->log(self::print_var($client_result), WC_Log_Levels::ERROR);

			wc_add_notice($message, 'error');
			$this->settings_admin_notice();

			return array(
				'result'   => 'failure',
				'messages' => $message
			);
		}

		#region Order status
		public function order_status_completed($order_id) {
			$this->log(sprintf('%1$s: OrderID=%2$s', __FUNCTION__, $order_id));

			if(!$this->transaction_auto)
				return;

			$order = wc_get_order($order_id);

			if($order && $order->get_payment_method() === $this->id) {
				if($order->has_status('completed') && $order->is_paid()) {
					$transaction_type = get_post_meta($order_id, self::MOD_TRANSACTION_TYPE, true);

					if($transaction_type === self::TRANSACTION_TYPE_AUTHORIZATION) {
						return $this->complete_transaction($order_id, $order);
					}
				}
			}
		}

		public function order_status_cancelled($order_id) {
			$this->log(sprintf('%1$s: OrderID=%2$s', __FUNCTION__, $order_id));

			if(!$this->transaction_auto)
				return;

			$order = wc_get_order($order_id);

			if($order && $order->get_payment_method() === $this->id) {
				if($order->has_status('cancelled') && $order->is_paid()) {
					$transaction_type = get_post_meta($order_id, self::MOD_TRANSACTION_TYPE, true);

					if($transaction_type === self::TRANSACTION_TYPE_AUTHORIZATION) {
						return $this->refund_transaction($order_id, $order);
					}
				}
			}
		}

		public function order_status_refunded($order_id) {
			$this->log(sprintf('%1$s: OrderID=%2$s', __FUNCTION__, $order_id));

			$order = wc_get_order($order_id);

			if($order && $order->get_payment_method() === $this->id) {
				if($order->has_status('refunded') && $order->is_paid()) {
					return $this->refund_transaction($order_id, $order);
				}
			}
		}
		#endregion

		public function complete_transaction($order_id, $order) {
			$this->log(sprintf('%1$s: OrderID=%2$s', __FUNCTION__, $order_id));

			$trans_id = $this->get_order_trans_id($order_id);
			$order_total = $this->price_format($this->get_order_net_total($order));
			$order_currency_numcode = $this->get_currency_numcode($order->get_currency());
			$order_description = $this->get_order_description($order);
			$client_ip = self::get_client_ip();
			$lang = $this->get_language();

			try {
				//Execute DMS transaction
				$client = $this->init_maib_client();
				$completion_result = $client->makeDMSTrans(
					$trans_id,
					$order_total,
					$order_currency_numcode,
					$client_ip,
					$order_description,
					$lang
				);

				$this->log(self::print_var($completion_result));
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);

				$message = sprintf(__('Payment completion failed via %1$s: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, $ex->getMessage());
				$message = $this->get_order_message($message);
				$order->add_order_note($message);

				return false;
			}

			if(!empty($completion_result)) {
				$result = $completion_result[self::MAIB_RESULT];

				if($result === self::MAIB_RESULT_OK) {
					$message = sprintf(__('Payment completed via %1$s: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, http_build_query($completion_result));
					$message = $this->get_order_message($message);
					$this->log($message, WC_Log_Levels::INFO);

					$order->add_order_note($message);
					$this->mark_order_paid($order, $trans_id);

					return true;
				}
			}

			return false;
		}

		public function refund_transaction($order_id, $order, $amount = null) {
			$this->log(sprintf('%1$s: OrderID=%2$s Amount=%3$s', __FUNCTION__, $order_id, $amount));

			$trans_id = $this->get_order_trans_id($order_id);
			$order_total = $order->get_total();
			$order_currency = $order->get_currency();

			if(!isset($amount)) {
				//Refund entirely if no amount is specified
				$amount = $order_total;
			}

			if($amount <= 0 || $amount > $order_total) {
				$message = sprintf(__('Invalid refund amount', self::MOD_TEXT_DOMAIN));
				$this->log($message, WC_Log_Levels::ERROR);

				return new WP_Error('error', $message);
			}

			try {
				$client = $this->init_maib_client();
				$reversal_result = $client->revertTransaction($trans_id, $amount);

				$this->log(self::print_var($reversal_result));
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);

				$message = sprintf(__('Refund of %1$s %2$s via %3$s failed: %4$s', self::MOD_TEXT_DOMAIN), $amount, $order_currency, $this->method_title, $ex->getMessage());
				$message = $this->get_order_message($message);
				$order->add_order_note($message);

				return new WP_Error('error', $message);
			}

			if(!empty($reversal_result)) {
				$result = $reversal_result[self::MAIB_RESULT];

				if($result === self::MAIB_RESULT_OK) {
					$message = sprintf(__('Refund of %1$s %2$s via %3$s approved: %4$s', self::MOD_TEXT_DOMAIN), $amount, $order_currency, $this->method_title, http_build_query($reversal_result));
					$message = $this->get_order_message($message);
					$this->log($message, WC_Log_Levels::INFO);
					$order->add_order_note($message);

					if($order->get_total() == $order->get_total_refunded()) {
						$this->mark_order_refunded($order);
					}

					return true;
				}
			}

			return false;
		}

		protected function check_transaction($trans_id) {
			$client_result = $this->get_transaction_result($trans_id);

			if(!empty($client_result)) {
				$result = $client_result[self::MAIB_RESULT];

				if($result === self::MAIB_RESULT_OK) {
					//$result_code   = $client_result[self::MAIB_RESULT_CODE];
					$rrn           = $client_result[self::MAIB_RESULT_RRN];
					$approval_code = $client_result[self::MAIB_RESULT_APPROVAL_CODE];

					if(!self::string_empty($rrn) && !self::string_empty($approval_code))
						return $client_result;
				}
			}

			return false;
		}

		protected function get_transaction_result($trans_id) {
			$client_ip = self::get_client_ip();

			$client_result = null;
			try {
				$client = $this->init_maib_client();
				$client_result = $client->getTransactionResult($trans_id, $client_ip);

				$this->log(self::print_var($client_result));
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			return $client_result;
		}

		public function check_response() {
			if($_SERVER['REQUEST_METHOD'] === 'GET') {
				$message = __('This Callback URL works and should not be called directly.', self::MOD_TEXT_DOMAIN);

				wc_add_notice($message, 'notice');

				wp_safe_redirect(wc_get_cart_url());
				return false;
			}

			$trans_id = $_POST[self::MAIB_TRANS_ID];
			$trans_id = wc_clean($trans_id);

			if(self::string_empty($trans_id)) {
				$message = sprintf(__('Payment verification failed: Transaction ID not received from %1$s.', self::MOD_TEXT_DOMAIN), $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');
				$this->settings_admin_notice();

				wp_safe_redirect(wc_get_cart_url());
				return false;
			}

			$order = $this->get_order_by_trans_id($trans_id);
			if(!$order) {
				$message = sprintf(__('Order not found by Transaction ID: %1$s received from %2$s.', self::MOD_TEXT_DOMAIN), $trans_id, $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');
				$this->settings_admin_notice();

				wp_safe_redirect(wc_get_cart_url());
				return false;
			}

			$order_id = $order->get_id();

			try {
				$client_result = $this->check_transaction($trans_id);
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			if(!empty($client_result)) {
				#region Update order payment metadata
				foreach($client_result as $key => $value)
					self::set_post_meta($order_id, strtolower(self::MOD_PREFIX . $key), $value);
				#endregion

				$message = sprintf(__('Payment authorized via %1$s: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, http_build_query($client_result));
				$message = $this->get_order_message($message);
				$this->log($message, WC_Log_Levels::INFO);
				$order->add_order_note($message);

				$this->mark_order_paid($order, $trans_id);
				WC()->cart->empty_cart();

				$message = sprintf(__('Order #%1$s paid successfully via %2$s.', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				$this->log($message, WC_Log_Levels::INFO);

				wc_add_notice($message, 'success');

				wp_safe_redirect($this->get_return_url($order));
				return true;
			}
			else {
				$message = sprintf(__('Order #%1$s payment failed via %2$s.', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				$message = $this->get_order_message($message);
				$this->log($message, WC_Log_Levels::ERROR);

				$order->add_order_note($message);
				wc_add_notice($message, 'error');
				$this->settings_admin_notice();

				wp_safe_redirect($order->get_checkout_payment_url()); //wc_get_checkout_url()
				return false;
			}
		}

		protected function mark_order_paid($order, $trans_id) {
			if(!$order->is_paid()) {
				$order->payment_complete($trans_id);
			}
		}

		protected function mark_order_refunded($order) {
			$message = sprintf(__('Order fully refunded via %1$s.', self::MOD_TEXT_DOMAIN), $this->method_title);
			$message = $this->get_order_message($message);

			//Mark order as refunded if not already set
			if(!$order->has_status('refunded')) {
				$order->update_status('refunded', $message);
			} else {
				$order->add_order_note($message);
			}
		}

		protected function generate_form($trans_id) {
			$form_html = '<form name="returnform" action="%1$s" method="POST">
				<input type="hidden" name="trans_id" value="%2$s">
				<input type="submit" name="submit" class="button alt" value="%3$s">
			</form>';

			return sprintf($form_html,
				$this->client_handler_url,
				$trans_id,
				__('Pay', self::MOD_TEXT_DOMAIN)
			);
		}

		public function receipt_page($order_id) {
			//$trans_id = $this->get_order_trans_id($order_id);
			$trans_id = $_GET[self::MAIB_TRANS_ID];
			$trans_id = wc_clean($trans_id);

			if(self::string_empty($trans_id)) {
				$message = sprintf(__('Transaction ID not found for order #%1$s', self::MOD_TEXT_DOMAIN), $order_id);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');

				return array(
					'result'   => 'failure',
					'messages' => $message
				);
			}

			echo $this->generate_form($trans_id);
		}

		public function process_refund($order_id, $amount = null, $reason = '') {
			$order = wc_get_order($order_id);
			return $this->refund_transaction($order_id, $order, $amount);
		}

		public function close_day() {
			if($this->check_settings()) {
				try {
					$client = $this->init_maib_client();
					$closeday_result = $client->closeDay();

					$this->log(self::print_var($closeday_result));
				} catch(Exception $ex) {
					$this->log($ex, WC_Log_Levels::ERROR);
				}

				$message_result = http_build_query($closeday_result);

				if(!empty($closeday_result)) {
					$result = $closeday_result[self::MAIB_RESULT];

					if($result === self::MAIB_RESULT_OK) {
						$message = sprintf(__('Close business day via %1$s succeeded: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, $message_result);
						$this->log($message, WC_Log_Levels::NOTICE);

						return $message;
					}
				}
			} else {
				$message_result = sprintf(__('%1$s is not properly configured.', self::MOD_TEXT_DOMAIN), $this->method_title);
			}

			$message = sprintf(__('Close business day via %1$s failed: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, $message_result);
			$this->log($message, WC_Log_Levels::ERROR);
			return $message;
		}

		protected function get_order_message($message) {
			if($this->testmode)
				$message = 'TEST: ' . $message;

			return $message;
		}

		protected function get_order_net_total($order) {
			//https://github.com/woocommerce/woocommerce/issues/17795
			//https://github.com/woocommerce/woocommerce/pull/18196
			$total_refunded = 0;
			if(method_exists(WC_Order_Refund::class, 'get_refunded_payment')) {
				$order_refunds = $order->get_refunds();
				foreach($order_refunds as $refund) {
					if($refund->get_refunded_payment())
						$total_refunded += $refund->get_amount();
				}
			}
			else
			{
				$total_refunded = $order->get_total_refunded();
			}

			$order_total = $order->get_total();
			return $order_total - $total_refunded;
		}

		//NOTE: MAIB Payment Gateway API does not currently support passing Order ID for transactions
		protected function get_order_by_trans_id($trans_id) {
			global $wpdb;
			$query = $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key=%s AND meta_value=%s",
				self::MOD_TRANSACTION_ID,
				$trans_id
			);

			$order_id = $wpdb->get_var($query);
			if(!$order_id) {
				return false;
			}

			return wc_get_order($order_id);
		}

		protected function get_order_trans_id($order_id) {
			$trans_id = get_post_meta($order_id, self::MOD_TRANSACTION_ID, true);
			return $trans_id;
		}

		/**
		 * Format prices
		 *
		 * @param  float|int $price
		 *
		 * @return float|int
		 */
		protected function price_format($price) {
			$decimals = 2;

			return number_format($price, $decimals, '.', '');
		}

		//https://en.wikipedia.org/wiki/ISO_4217
		private $currency_numcodes = array(
			'EUR' => 978,
			'USD' => 840,
			'MDL' => 498
		);

		protected function get_currency_numcode($currency) {
			return $this->currency_numcodes[$currency];
		}

		protected function get_order_description($order) {
			return sprintf(__($this->order_template, self::MOD_TEXT_DOMAIN),
				$order->get_id(),
				$this->get_order_items_summary($order)
			);
		}

		protected function get_order_items_summary($order) {
			$items = $order->get_items();
			$items_names = array_map(function($item) { return $item->get_name(); }, $items);

			return join(', ', $items_names);
		}

		protected function get_language() {
			$lang = get_locale();
			return substr($lang, 0, 2);
		}

		static function get_client_ip() {
			return WC_Geolocation::get_ip_address();
		}

		protected function get_callback_url() {
			//https://docs.woocommerce.com/document/wc_api-the-woocommerce-api-callback/
			//return get_home_url(null, 'wc-api/' . get_class($this));

			//https://codex.wordpress.org/Function_Reference/home_url
			return add_query_arg('wc-api', get_class($this), home_url('/'));
		}

		static function get_logs_url() {
			return add_query_arg(
				array(
					'page'    => 'wc-status',
					'tab'     => 'logs',
					//'log_file' => ''
				),
				admin_url('admin.php')
			);
		}

		static function get_logs_path() {
			return WC_Log_Handler_File::get_log_file_path(self::MOD_ID);
		}

		static function get_settings_url() {
			return add_query_arg(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => self::MOD_ID
				),
				admin_url('admin.php')
			);
		}

		static function set_post_meta($post_id, $meta_key, $meta_value) {
			//https://developer.wordpress.org/reference/functions/add_post_meta/#comment-465
			if(!add_post_meta($post_id, $meta_key, $meta_value, true)) {
				update_post_meta($post_id, $meta_key, $meta_value);
			 }
		}

		protected function log($message, $level = WC_Log_Levels::DEBUG) {
			//https://woocommerce.wordpress.com/2017/01/26/improved-logging-in-woocommerce-2-7/
			//https://stackoverflow.com/questions/1423157/print-php-call-stack
			$this->logger->log($level, $message, $this->log_context);
		}

		static function static_log($message, $level = WC_Log_Levels::DEBUG) {
			$logger = wc_get_logger();
			$log_context = array('source' => self::MOD_ID);
			$logger->log($level, $message, $log_context);
		}

		static function print_var($var) {
			//https://docs.woocommerce.com/wc-apidocs/function-wc_print_r.html
			return wc_print_r($var, true);
		}

		protected static function string_empty($string) {
			return strlen($string) === 0;
		}

		#region Admin
		static function plugin_links($links) {
			$plugin_links = array(
				sprintf('<a href="%1$s">%2$s</a>', esc_url(self::get_settings_url()), __('Settings', self::MOD_TEXT_DOMAIN))
			);

			return array_merge($plugin_links, $links);
		}

		static function order_actions($actions) {
			global $theorder;
			if($theorder->get_payment_method() !== self::MOD_ID) {
				return $actions;
			}

			if($theorder->is_paid()) {
				$transaction_type = get_post_meta($theorder->get_id(), self::MOD_TRANSACTION_TYPE, true);
				if($transaction_type === self::TRANSACTION_TYPE_AUTHORIZATION) {
					$actions['moldovaagroindbank_complete_transaction'] = sprintf(__('Complete %1$s transaction', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
					//$actions['moldovaagroindbank_reverse_transaction'] = sprintf(__('Reverse %1$s transaction', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
				}
			} elseif ($theorder->has_status('pending')) {
				$actions['moldovaagroindbank_verify_transaction'] = sprintf(__('Verify %1$s transaction', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
			}

			return $actions;
		}

		static function action_complete_transaction($order) {
			$order_id = $order->get_id();

			$plugin = new self();
			return $plugin->complete_transaction($order_id, $order);
		}

		static function action_reverse_transaction($order) {
			$order_id = $order->get_id();

			$plugin = new self();
			return $plugin->refund_transaction($order_id, $order);
		}

		static function action_verify_transaction($order) {
			$order_id = $order->get_id();

			$plugin = new self();
			$trans_id = $plugin->get_order_trans_id($order_id);

			if(self::string_empty($trans_id)) {
				$message = sprintf(__('%1$s Transaction ID not found for order #%2$d.', self::MOD_TEXT_DOMAIN), $plugin->method_title, $order_id);
				$message = $plugin->get_order_message($message);
				$plugin->log($message, WC_Log_Levels::ERROR);
				$order->add_order_note($message);
			}

			$client_result = $plugin->get_transaction_result($trans_id);
			if(empty($client_result)) {
				$message = sprintf(__('Could not retrieve transaction status from %1$s for order #%2$d.', self::MOD_TEXT_DOMAIN), $plugin->method_title, $order_id);
				$message = $plugin->get_order_message($message);
				$plugin->log($message, WC_Log_Levels::ERROR);
				$order->add_order_note($message);
			} else {
				$message = sprintf(__('Transaction status from %1$s for order #%2$d: %3$s', self::MOD_TEXT_DOMAIN), $plugin->method_title, $order_id, http_build_query($client_result));
				$message = $plugin->get_order_message($message);
				$plugin->log($message, WC_Log_Levels::INFO);
				$order->add_order_note($message);
			}
		}

		static function action_close_day() {
			$plugin = new self();
			$result = $plugin->close_day();

			//https://github.com/Prospress/action-scheduler/issues/215
			$action_id = self::find_scheduled_action(ActionScheduler_Store::STATUS_RUNNING);
			$logger = ActionScheduler::logger();
			$logger->log($action_id, $result);
		}

		public static function register_scheduled_actions() {
			if(false !== as_next_scheduled_action(self::MOD_CLOSEDAY_ACTION)) {
				$message = sprintf(__('Scheduled action %1$s is already registered.', self::MOD_TEXT_DOMAIN), self::MOD_CLOSEDAY_ACTION);
				self::static_log($message, WC_Log_Levels::WARNING);

				self::unregister_scheduled_actions();
			}

			$timezoneId = wc_timezone_string();
			$timestamp = as_get_datetime_object('tomorrow - 1 minute', $timezoneId);
			$timestamp->setTimezone(new DateTimeZone('UTC'));

			$cronSchedule = $timestamp->format('i H * * *'); #'59 23 * * *'
			$action_id = as_schedule_cron_action(null, $cronSchedule, self::MOD_CLOSEDAY_ACTION, array(), self::MOD_ID);

			$message = sprintf(__('Registered scheduled action %1$s in timezone %2$s with ID %3$s.', self::MOD_TEXT_DOMAIN), self::MOD_CLOSEDAY_ACTION, $timezoneId, $action_id);
			self::static_log($message, WC_Log_Levels::INFO);
		}

		public static function unregister_scheduled_actions() {
			as_unschedule_all_actions(self::MOD_CLOSEDAY_ACTION);

			$message = sprintf(__('Unregistered scheduled action %1$s.', self::MOD_TEXT_DOMAIN), self::MOD_CLOSEDAY_ACTION);
			self::static_log($message, WC_Log_Levels::INFO);
		}

		/* public static function action_upgrade_complete($upgrader_object, $options) {
			//https://wordpress.stackexchange.com/questions/144870/wordpress-update-plugin-hook-action-since-3-9
			if($options['action'] == 'update' && $options['type'] == 'plugin' && isset($options['plugins'])) {
				$this_plugin = plugin_basename(__FILE__);

				foreach($options['plugins'] as $plugin) {
					if($plugin == $this_plugin) {
						self::register_scheduled_actions();
					}
				}
			}
		} */

		static function find_scheduled_action($status = null) {
			$params = $status ? array('status' => $status) : null;
			$action_id = ActionScheduler::store()->find_action(self::MOD_CLOSEDAY_ACTION, $params);
			return $action_id;
		}
		#endregion

		static function add_gateway($methods) {
			$methods[] = self::class;
			return $methods;
		}

		public static function is_wc_active() {
			//https://docs.woocommerce.com/document/query-whether-woocommerce-is-activated/
			return class_exists('WooCommerce');
		}
	}

	//Check if WooCommerce is active
	if(!WC_MoldovaAgroindbank::is_wc_active())
		return;

	//Add gateway to WooCommerce
	add_filter('woocommerce_payment_gateways', array(WC_MoldovaAgroindbank::class, 'add_gateway'));

	#region Admin init
	if(is_admin()) {
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_MoldovaAgroindbank::class, 'plugin_links'));

		//Add WooCommerce order actions
		add_filter('woocommerce_order_actions', array(WC_MoldovaAgroindbank::class, 'order_actions'));
		add_action('woocommerce_order_action_moldovaagroindbank_complete_transaction', array(WC_MoldovaAgroindbank::class, 'action_complete_transaction'));
		//add_action('woocommerce_order_action_moldovaagroindbank_reverse_transaction', array(WC_MoldovaAgroindbank::class, 'action_reverse_transaction'));
		add_action('woocommerce_order_action_moldovaagroindbank_verify_transaction', array(WC_MoldovaAgroindbank::class, 'action_verify_transaction'));
	}
	#endregion

	#region Scheduled actions
	#add_action('upgrader_process_complete', array(WC_MoldovaAgroindbank::class, 'action_upgrade_complete'), 10, 2);
	add_action(WC_MoldovaAgroindbank::MOD_CLOSEDAY_ACTION, array(WC_MoldovaAgroindbank::class, 'action_close_day'));
	#endregion
}

#region Register activation hooks
function woocommerce_moldovaagroindbank_activation() {
	woocommerce_moldovaagroindbank_init();

	if(!class_exists('WC_MoldovaAgroindbank'))
		die('WooCommerce is required for this plugin to work');

	WC_MoldovaAgroindbank::register_scheduled_actions();
}

register_activation_hook(__FILE__, 'woocommerce_moldovaagroindbank_activation');
register_deactivation_hook(__FILE__, array(WC_MoldovaAgroindbank::class, 'unregister_scheduled_actions'));
#endregion
