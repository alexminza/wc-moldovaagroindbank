<?php
/**
 * Plugin Name: WooCommerce Moldova Agroindbank Payment Gateway
 * Description: WooCommerce Payment Gateway for Moldova Agroindbank
 * Plugin URI: https://wordpress.org/plugins/wc-moldovaagroindbank/
 * Version: 1.0
 * Author: Alexander Minza
 * Author URI: https://profiles.wordpress.org/alexminza
 * Developer: Alexander Minza
 * Developer URI: https://profiles.wordpress.org/alexminza
 * Text Domain: wc-moldovaagroindbank
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 4.8
 * Tested up to: 4.9.4
 * WC requires at least: 3.2
 * WC tested up to: 3.3.3
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
	if(!class_exists(WC_Payment_Gateway::class))
		return;

	load_plugin_textdomain('wc-moldovaagroindbank', false, dirname(plugin_basename(__FILE__)) . '/languages/');

	class WC_MoldovaAgroindbank extends WC_Payment_Gateway {
		protected $logger;

		//region Constants
		const MOD_ID             = 'moldovaagroindbank';
		const MOD_TITLE          = 'Moldova Agroindbank';
		const MOD_PREFIX         = 'MAIB_';
		const MOD_TEXT_DOMAIN    = 'wc-moldovaagroindbank';

		//Sends through sale and request for funds to be charged to cardholder's credit card.
		const TRANSACTION_TYPE_CHARGE = 'charge';
		//Sends through a request for funds to be "reserved" on the cardholder's credit card. Reservation times are determined by cardholder's bank.
		const TRANSACTION_TYPE_AUTHORIZATION = 'authorization';

		const MOD_TRANSACTION_TYPE = self::MOD_PREFIX . 'transaction_type';
		const MOD_TRANSACTION_ID   = self::MOD_PREFIX . self::MAIB_TRANSACTION_ID;

		const ORDER_TEMPLATE       = 'Order #%1$s';

		const MAIB_TRANS_ID       = 'trans_id';
		const MAIB_TRANSACTION_ID = 'TRANSACTION_ID';

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
		//endregion

		public function __construct() {
			$plugin_dir = plugin_dir_url(__FILE__);

			$this->logger = wc_get_logger();

			$this->id                 = self::MOD_ID;
			$this->method_title       = self::MOD_TITLE;
			$this->method_description = 'WooCommerce Payment Gateway for Moldova Agroindbank';
			$this->icon               = apply_filters('woocommerce_moldovaagroindbank_icon', '' . $plugin_dir . '/assets/img/moldovaagroindbank.png');
			$this->has_fields         = false;
			$this->supports           = array('products', 'refunds');

			$this->init_form_fields();
			$this->init_settings();

			//region Define user set variables
			$this->enabled           = $this->get_option('enabled');
			$this->title             = $this->get_option('title');
			$this->description       = $this->get_option('description');

			$this->testmode          = 'yes' === $this->get_option('testmode', 'no');
			$this->debug             = 'yes' === $this->get_option('debug', 'no');

			$this->log_context = array(
				'source' => $this->id
			);
			$this->log_threshold = $this->debug ? WC_Log_Levels::DEBUG : WC_Log_Levels::INFO;
			$this->logger = new WC_Logger(null, $this->log_threshold);

			$this->transaction_type   = $this->get_option('transaction_type', self::TRANSACTION_TYPE_CHARGE);
			$this->transaction_auto   = 'yes' === $this->get_option('transaction_auto', 'yes');

			$this->order_template     = $this->get_option('order_template', self::ORDER_TEMPLATE);

			$this->base_url           = ($this->testmode ? 'https://ecomm.maib.md:4499' : 'https://ecomm.maib.md:4455');
			$this->client_handler_url = 'https://ecomm.maib.md:7443/ecomm2/ClientHandler';
			$this->skip_receipt_page  = true;

			$this->maib_pcert         = $this->get_option('maib_pcert');
			$this->maib_key           = $this->get_option('maib_key');
			$this->maib_key_password  = $this->get_option('maib_key_password');
			$this->maib_cacert        = $this->get_option('maib_cacert');
			//endregion

			if(is_admin()) {
				//Save options
				add_action('woocommerce_update_options_payment_gateways_' . strtolower($this->id), array($this, 'process_admin_options'));
			}

			add_action('woocommerce_receipt_' . strtolower($this->id), array($this, 'receipt_page'));

			if($this->transaction_auto) {
				add_filter('woocommerce_order_status_completed', array($this, 'order_status_completed'));
				add_filter('woocommerce_order_status_cancelled', array($this, 'order_status_cancelled'));
			}

			//Payment listener/API hook
			add_action('woocommerce_api_wc_' . strtolower($this->id), array($this, 'check_response'));

			if(!$this->is_valid_for_use()) {
				$this->enabled = false;
			}
		}

		/**
		 * Initialize Gateway Settings Form Fields
		 */
		function init_form_fields() {
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

				'testmode'        => array(
					'title'       => __('Test mode', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					'label'       => __('Enabled', self::MOD_TEXT_DOMAIN),
					'description' => __('Use test or production bank environment/gateway', self::MOD_TEXT_DOMAIN),
					'default'     => 'no'
				),
				'debug'           => array(
					'title'       => __('Debug mode', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					'label'       => __('Enable logging', self::MOD_TEXT_DOMAIN),
					'description' => sprintf(__('Callback URL: <code>%1$s</code>', self::MOD_TEXT_DOMAIN), $this->get_callback_url()),
					'default'     => 'no'
				),

				'transaction_type' => array(
					'title'       => __('Transaction type', self::MOD_TEXT_DOMAIN),
					'type'        => 'select',
					'desc_tip'    => __('Select how transactions should be processed. Charge submits all transactions for settlement, Authorization simply authorizes the order total for capture later.', self::MOD_TEXT_DOMAIN),
					'default'     => self::TRANSACTION_TYPE_CHARGE,
					'options'     => array(
						self::TRANSACTION_TYPE_CHARGE        => __('Charge', self::MOD_TEXT_DOMAIN),
						self::TRANSACTION_TYPE_AUTHORIZATION => __('Authorization', self::MOD_TEXT_DOMAIN),
					),
				),
				'transaction_auto' => array(
					'title'       => __('Transaction auto', self::MOD_TEXT_DOMAIN),
					'type'        => 'checkbox',
					//'label'       => __('Enabled', self::MOD_TEXT_DOMAIN),
					'label'       => __('Automatically complete/reverse bank transactions when order status changes', self::MOD_TEXT_DOMAIN),
					'default'     => 'yes'
				),
				'order_template'  => array(
					'title'       => __('Order description', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => __('Format: %1$s - Order ID, %2$s - Order items summary', self::MOD_TEXT_DOMAIN),
					'default'     => self::ORDER_TEMPLATE
				),

				'connection_settings' => array(
					'title'       => __('Connection Settings', self::MOD_TEXT_DOMAIN),
					'type'        => 'title',
					'description' => __('Merchant security connection settings provided by the bank.', self::MOD_TEXT_DOMAIN)
				),

				'maib_cacert'     => array(
					'title'       => __('Certificate Authority (CA) bundle', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => 'cacert.pem',
					'default'     => 'cacert.pem'
				),
				'maib_pcert'      => array(
					'title'       => __('Client certificate file', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => 'pcert.pem',
					'default'     => 'pcert.pem'
				),
				'maib_key'        => array(
					'title'       => __('Private key file', self::MOD_TEXT_DOMAIN),
					'type'        => 'text',
					'description' => 'key.pem',
					'default'     => 'key.pem'
				),
				'maib_key_password' => array(
					'title'       => __('Private key passphrase', self::MOD_TEXT_DOMAIN),
					'type'        => 'password',
					'default'     => ''
				)
			);
		}

		protected function is_valid_for_use() {
			if(!in_array(get_option('woocommerce_currency'), array('MDL'))) {
				return false;
			}

			return true;
		}

		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 **/
		public function admin_options() {
			?>
			<h2><?php _e($this->method_title, self::MOD_TEXT_DOMAIN); ?></h2>
			<p><?php _e($this->method_description, self::MOD_TEXT_DOMAIN); ?></p>

			<?php if($this->is_valid_for_use()) : ?>
				<table class="form-table">
					<?php $this->generate_settings_html(); ?>
				</table>
			<?php else : ?>
				<div class="inline error">
					<p>
						<strong><?php _e('Payment gateway disabled', self::MOD_TEXT_DOMAIN); ?></strong>:
						<?php _e('Store settings not supported', self::MOD_TEXT_DOMAIN); ?>
					</p>
				</div>
			<?php
			endif;
		}

		protected function init_maib_client() {
			$options = [
				'base_url' => $this->base_url,
				'debug'    => $this->debug,
				'verify'   => false,
				'defaults' => [
					'verify'  => $this->maib_cacert,
					'cert'    => [$this->maib_pcert, $this->maib_key_password],
					'ssl_key' => $this->maib_key,
					'config'  => [
						'curl'  =>  [
							CURLOPT_SSL_VERIFYHOST => false,
							CURLOPT_SSL_VERIFYPEER => false,
						]
					]
				],
			];

			//region Init Client
			$guzzleClient = new Client($options);
			$client = new MaibClient($guzzleClient);

			if($this->debug) {
				//Create a log for client class (monolog/monolog required)
				$log = new Logger('maib_guzzle_request');
				$logFileName = sprintf('%1$s-%2$s_guzzle.log', $this->id, wp_hash($this->id));
				$log->pushHandler(new StreamHandler(WC_LOG_DIR . $logFileName, Logger::DEBUG));
				$subscriber = new LogSubscriber($log, Formatter::SHORT);
				$client->getHttpClient()->getEmitter()->attach($subscriber);
			}
			//endregion

			return $client;
		}

		public function process_payment($order_id) {
			if(!$order = wc_get_order($order_id)) {
				$message = sprintf(__('Order #%1$s not found', self::MOD_TEXT_DOMAIN), $order_id);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');

				return array(
					'result'    => 'failure',
					'messages'	=> $message
				);
			}

			$order_total = $this->price_format($order->get_total());
			$order_currency_numcode = $this->get_currency_numcode($order->get_currency());
			$order_description = $this->get_order_description($order);
			$client_ip = $this->get_client_ip();
			$lang = $this->get_language();

			try {
				$client = $this->init_maib_client();

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

			//region Validate response
			$trans_id = NULL;
			if(!empty($client_result)) {
				if(isset($client_result[self::MAIB_TRANSACTION_ID])) {
					$trans_id = $client_result[self::MAIB_TRANSACTION_ID];
				}
			}
			//endregion

			if(!empty($trans_id)) {
				//region Update order payment transaction metadata
				add_post_meta($order_id, strtolower(self::MOD_TRANSACTION_TYPE), $this->transaction_type);
				add_post_meta($order_id, strtolower(self::MOD_TRANSACTION_ID), $trans_id);
				//endregion

				//region Log transaction initiation
				$message = sprintf(__('Payment initiated via %1$s: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, http_build_query($client_result));
				$this->log($message, WC_Log_Levels::INFO);
				$order->add_order_note($message);
				//endregion

				$redirect = add_query_arg(self::MAIB_TRANS_ID, urlencode($trans_id), $this->skip_receipt_page
					? $this->client_handler_url
					: $order->get_checkout_payment_url(true));

				return array(
					'result'   => 'success',
					'redirect' => $redirect
				);
			}

			$message = sprintf(__('Transaction ID not received from %1$s', self::MOD_TEXT_DOMAIN), $this->method_title);
			$this->log($message, WC_Log_Levels::ERROR);
			$this->log(self::print_var($client_result), WC_Log_Levels::ERROR);

			wc_add_notice($message, 'error');

			return array(
				'result'   => 'failure',
				'messages' => $message
			);
		}

		public function order_status_completed($order_id) {
			$this->log(sprintf('order_status_completed: Order ID: %1$s', $order_id));

			if(!$this->transaction_auto)
				return;

			$order = wc_get_order($order_id);

			if($order && $order->get_payment_method() === $this->id) {
				if($order->has_status('completed') && $order->is_paid()) {
					$transaction_type = get_post_meta($order_id, strtolower(self::MOD_TRANSACTION_TYPE), true);

					if($transaction_type === self::TRANSACTION_TYPE_AUTHORIZATION) {
						return $this->complete_transaction($order_id, $order);
					}
				}
			}
		}

		public function order_status_cancelled($order_id) {
			$this->log(sprintf('order_status_cancelled: Order ID: %1$s', $order_id));

			if(!$this->transaction_auto)
				return;

			$order = wc_get_order($order_id);

			if($order && $order->get_payment_method() === $this->id) {
				if($order->has_status('cancelled') && $order->is_paid()) {
					$transaction_type = get_post_meta($order_id, strtolower(self::MOD_TRANSACTION_TYPE), true);

					if($transaction_type === self::TRANSACTION_TYPE_AUTHORIZATION) {
						return $this->refund_transaction($order_id, $order);
					}
				}
			}
		}

		public function complete_transaction($order_id, $order) {
			$this->log(sprintf('complete_transaction Order ID: %1$s', $order_id));

			$trans_id = get_post_meta($order_id, strtolower(self::MAIB_TRANS_ID), true);
			$order_total = $this->price_format($this->get_order_net_total($order));
			$order_currency_numcode = $this->get_currency_numcode($order->get_currency());
			$order_description = $this->get_order_description($order);
			$client_ip = $this->get_client_ip();
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
			}

			if(!empty($completion_result)) {
				$result = $completion_result[self::MAIB_RESULT];

				if($result === self::MAIB_RESULT_OK) {
					$message = sprintf(__('Payment completed via %1$s: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, http_build_query($completion_result));
					$this->log($message, WC_Log_Levels::INFO);

					$order->add_order_note($message);
					$this->mark_order_paid($order, $trans_id);

					return true;
				}
			}

			return false;
		}

		public function refund_transaction($order_id, $order, $amount = null) {
			$this->log(sprintf('refund_transaction Order ID: %1$s Amount: %2$s', $order_id, $amount));

			if(!isset($amount)) {
				//Refund entirely if no amount is specified ???
				$amount = $order->get_total();
			}

			$trans_id = get_post_meta($order_id, strtolower(self::MAIB_TRANS_ID), true);
			$order_currency = $order->get_currency();

			try {
				$client = $this->init_maib_client();
				$reversal_result = $client->revertTransaction($trans_id, $amount);

				$this->log(self::print_var($reversal_result));
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			if(!empty($reversal_result)) {
				$result = $reversal_result[self::MAIB_RESULT];

				if($result === self::MAIB_RESULT_REVERSED) {
					$message = sprintf(__('Refund of %1$s %2$s via %3$s approved: %4$s', self::MOD_TEXT_DOMAIN), $amount, $order_currency, $this->method_title, http_build_query($reversal_result));
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
			$client_ip = $this->get_client_ip();

			$client = $this->init_maib_client();
			$client_result = $client->getTransactionResult($trans_id, $client_ip);

			if(!empty($client_result)) {
				$result = $client_result[self::MAIB_RESULT];

				if($result === self::MAIB_RESULT_OK) {
					$result_code   = $client_result[self::MAIB_RESULT_CODE];
					$rrn           = $client_result[self::MAIB_RESULT_RRN];
					$approval_code = $client_result[self::MAIB_RESULT_APPROVAL_CODE];

					//TODO: Validate order value
					if(!empty($rrn) && !empty($approval_code)) {
						return $client_result;
					}
				}
			}

			return false;
		}

		public function check_response() {
			$trans_id = $_POST[self::MAIB_TRANS_ID];

			if(empty($trans_id)) {
				$message = sprintf(__('Transaction ID not received from %1$s', self::MOD_TEXT_DOMAIN), $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');

				wp_redirect(wc_get_cart_url());
				return false;
			}

			$order = $this->get_order_by_trans_id($trans_id);
			if(!$order) {
				$message = sprintf(__('Order not found by Transaction ID: %1$s received from %2$s', self::MOD_TEXT_DOMAIN), $trans_id, $this->method_title);
				$this->log($message, WC_Log_Levels::ERROR);

				wc_add_notice($message, 'error');

				wp_redirect(wc_get_cart_url());
				return false;
			}

			$order_id = $order->get_id();

			try {
				$client_result = $this->check_transaction($trans_id);
			} catch(Exception $ex) {
				$this->log($ex, WC_Log_Levels::ERROR);
			}

			if(!empty($client_result)) {
				//region Update order payment metadata
				foreach($client_result as $key => $value) {
					add_post_meta($order_id, strtolower(self::MOD_PREFIX . $key), $value);
				}
				//endregion

				$message = sprintf(__('Payment authorized via %1$s: %2$s', self::MOD_TEXT_DOMAIN), $this->method_title, http_build_query($client_result));
				$this->log($message, WC_Log_Levels::INFO);
				$order->add_order_note($message);

				$this->mark_order_paid($order, $trans_id);
				WC()->cart->empty_cart();

				$message = sprintf(__('Order #%1$s paid successfully via %2$s', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				$this->log($message, WC_Log_Levels::INFO);
				wc_add_notice($message, 'success');

				wp_redirect($this->get_return_url($order));
				return true;
			}
			else {
				$message = sprintf(__('Order #%1$s payment failed via %2$s', self::MOD_TEXT_DOMAIN), $order_id, $this->method_title);
				wc_add_notice($message, 'error');

				$message = sprintf(__('%1$s payment transaction check failed. Transaction ID: %2$s Order ID: %3$s', self::MOD_TEXT_DOMAIN), $this->method_title, $trans_id, $order_id);
				$this->log($message, WC_Log_Levels::ERROR);
				$order->add_order_note($message);

				wp_redirect($order->get_checkout_payment_url());
				return false;
			}
		}

		protected function mark_order_paid($order, $trans_id) {
			if(!$order->is_paid()) {
				$order->payment_complete($trans_id);
			}
		}

		protected function mark_order_refunded($order) {
			$order_note = sprintf(__('Order fully refunded via %1$s', self::MOD_TEXT_DOMAIN), $this->method_title);

			//Mark order as refunded if not already set
			if(!$order->has_status('refunded')) {
				$order->update_status('refunded', $order_note);
			} else {
				$order->add_order_note($order_note);
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
			//$trans_id = get_post_meta($order_id, strtolower(self::MOD_TRANSACTION_ID), true);
			$trans_id = $_GET[self::MAIB_TRANS_ID];

			if(empty($trans_id)) {
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

		public function process_refund($order_id, $amount = NULL, $reason = '') {
			$order = wc_get_order($order_id);
			return $this->refund_transaction($order_id, $order, $amount);
		}

		protected function get_order_net_total($order) {
			$order_total = $order->get_total();
			$total_refunded = $order->get_total_refunded();

			//https://github.com/woocommerce/woocommerce/issues/17795
			//https://github.com/woocommerce/woocommerce/pull/18196
			/*$total_refunded = 0;
			$order_refunds = $order->get_refunds();
			foreach($order_refunds as $refund) {
				if($refund->get_refunded_payment())
					$total_refunded += $refund->get_amount();
			}*/

			return $order_total - $total_refunded;
		}

		//MAIB Payment Gateway API does not currently support passing Order ID for transactions 
		protected function get_order_by_trans_id($trans_id) {
			global $wpdb;
			$query = $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key=%s AND meta_value=%s",
				strtolower(self::MOD_TRANSACTION_ID),
				$trans_id
			);

			$order_id = $wpdb->get_var($query);
			if(!$order_id) {
				return false;
			}

			return wc_get_order($order_id);
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
			//get_bloginfo('name')

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

		private $language_codes = array(
			'en_EN' => 'en',
			'ru_RU' => 'ru',
			'ro_RO' => 'ro'
		);

		protected function get_language() {
			$lang = get_locale();
			return substr($lang, 0, 2);
		}

		protected function get_client_ip() {
			//return $_SERVER['REMOTE_ADDR'];

			return WC_Geolocation::get_ip_address();
		}

		protected function get_callback_url() {
			//https://codex.wordpress.org/Function_Reference/site_url
			return add_query_arg('wc-api', get_class($this), home_url('/', 'https'));
		}

		//https://woocommerce.wordpress.com/2017/01/26/improved-logging-in-woocommerce-2-7/
		//https://stackoverflow.com/questions/1423157/print-php-call-stack
		protected function log($message, $level = WC_Log_Levels::DEBUG) {
			$this->logger->log($level, $message, $this->log_context);
		}

		static function print_var($var) {
			//https://docs.woocommerce.com/wc-apidocs/function-wc_print_r.html
			return print_r($var, true);
		}

		//region Admin
		static function plugin_links($links) {
			$settings_url = add_query_arg(
				array(
					'page'    => 'wc-settings',
					'tab'     => 'checkout',
					'section' => self::MOD_ID
				),
				admin_url('admin.php')
			);

			$plugin_links = array(
				sprintf('<a href="%1$s">%2$s</a>', esc_url($settings_url), __('Settings', self::MOD_TEXT_DOMAIN))
			);

			return array_merge($plugin_links, $links);
		}

		static function order_actions($actions) {
			global $theorder;
			if(!$theorder->is_paid() || $theorder->get_payment_method() !== self::MOD_ID) {
				return $actions;
			}

			$actions['moldovaagroindbank_complete_transaction'] = sprintf(__('Complete %1$s transaction', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
			$actions['moldovaagroindbank_reverse_transaction'] = sprintf(__('Reverse %1$s transaction', self::MOD_TEXT_DOMAIN), self::MOD_TITLE);
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
		//endregion

		static function add_gateway($methods) {
			$methods[] = self::class;
			return $methods;
		}

		//https://docs.woocommerce.com/document/create-a-plugin/
		static protected function check_wc_active() {
			//Check if WooCommerce is active
			return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
		}
	}

	//Add gateway to WooCommerce
	add_filter('woocommerce_payment_gateways', array(WC_MoldovaAgroindbank::class, 'add_gateway'));

	//region Admin init
	if(is_admin()) {
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_MoldovaAgroindbank::class, 'plugin_links'));

		//Add WooCommerce order actions
		add_filter('woocommerce_order_actions', array(WC_MoldovaAgroindbank::class, 'order_actions'));
		add_action('woocommerce_order_action_moldovaagroindbank_complete_transaction', array(WC_MoldovaAgroindbank::class, 'action_complete_transaction'));
		add_action('woocommerce_order_action_moldovaagroindbank_reverse_transaction', array(WC_MoldovaAgroindbank::class, 'action_reverse_transaction'));
	}
	//endregion
}