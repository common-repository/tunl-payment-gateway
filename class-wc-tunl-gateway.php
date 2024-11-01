<?php
/**
Plugin Name: Tunl Payment Gateway for WooCommerce
Plugin URI: https://merchant.tunl.com/session/signin
Description: Accept credit card payments on your WooCommerce store using the Tunl payment gateway.
Author: Tunl
Author URI: https://www.tunl.com
Version: 1.0.24
*/

/** Define the Tunl Payment Method Url */



define('TUNL_TEST_URL', 'https://test-api.tunl.com/api');
define('TUNL_LIVE_URL', 'https://api.tunl.com/api');

/**  Check whether the woocommerce plugin is active or not */

add_action('plugins_loaded', 'woocommerce_gateway_tunl_check_wc');

/**
 * Function Name : woocommerce_gateway_tunl_check_wc
 */
function woocommerce_gateway_tunl_check_wc()
{
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', 'tunl_gateway_woocommerce_tunl_missing_wc_notice');
		return true;
	}
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	return;
}

/**
 * Function Name : tunl_gateway_woocommerce_plugin_activate
 */
function tunl_gateway_woocommerce_plugin_activate()
{
	require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
	header('Clear-Site-Data: "cache"');
	tunl_gateway_v108_to_v109_upgrade();
}

register_activation_hook(__FILE__, 'tunl_gateway_woocommerce_plugin_activate');

/**
 * Function Name : tunl_gateway_woocommerce_plugin_deactivate
 */
function tunl_gateway_woocommerce_plugin_deactivate()
{
	/**  Reset the tunl payment method form field */
	// delete_option('woocommerce_tunl_settings');
}

register_deactivation_hook(__FILE__, 'tunl_gateway_woocommerce_plugin_deactivate');

function tunl_gateway_v108_to_v109_upgrade()
{
	// code to fix installs using the old deprecated encryption functions
	$myOptions = get_option("woocommerce_tunl_settings");
	$currentKey = $myOptions['secret_encryption_key'];
	if (!$currentKey) {
		$myOptions['secret_encryption_key'] = bin2hex(random_bytes(32));
		$currentKey = $myOptions['secret_encryption_key'];
		update_option("woocommerce_tunl_settings", $myOptions);
	}

	$valuesToFix = ['saved_password', 'saved_live_password'];

	foreach ($valuesToFix as $valueToFix) {
		$value = apply_filters('deprecated_tunl_gateway_decrypt_filter', $myOptions[$valueToFix]);
		if ($value === "" || $value === false) continue;
		$myOptions[$valueToFix] = apply_filters('tunl_gateway_encrypt_filter', $value);
	}

	update_option("woocommerce_tunl_settings", $myOptions);
}

/**  Initialize Tunl Class */

add_action('plugins_loaded', 'tunl_gateway_initialize_woocommerce_gateway_class');

/**
 * Function Name : tunl_gateway_initialize_woocommerce_gateway_class
 */
function tunl_gateway_initialize_woocommerce_gateway_class()
{

	/**
	 * Class Name : WCTUNLGateway
	 */
	class WCTUNLGateway extends WC_Payment_Gateway
	{
		private $tunl_merchant_id = null;
		/**
		 * Function Name : __construct
		 */
		public function __construct()
		{

			/** Set the fields text for payment methods  */
			$this->id = 'tunl';
			$this->icon = '';
			$this->has_fields = true;
			$this->title = __('Tunl', 'tunlwoopay');
			$this->method_title = __('Tunl', 'tunlwoopay');
			$desp = 'Tunl works by adding payment fields on the checkout and then sending the details to Tunl for verification.';
			$this->method_description = __($desp, 'tunlwoopay');

			/** Load the default credit card form */
			$this->supports = array('default_credit_card_form', 'refunds');

			/** Load backend options fields */
			$this->init_form_fields();

			/** Load the settings. */
			$this->init_settings();
			$this->enabled = $this->get_option('enabled');
			$this->title = $this->get_option('title');

			/** Action hook to save the settings */
			if (is_admin()) {
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'save_tunlpayment'));
			}

		}

		/**  Save Tunl Payment Methods Fields */
		public function save_tunlpayment()
		{
			$myOptions = get_option('woocommerce_tunl_settings');

			// set defaults
			$myOptions['enabled'] = 'no';
			$myOptions['api_mode'] = 'no';
			$myOptions['title'] = '';
			$username = '';
			$password = '';
			$liveusername = '';
			$livepassword = '';

			if (isset($_POST['woocommerce_tunl_enabled']))
				$myOptions['enabled'] = 'yes';

			if (isset($_POST['woocommerce_tunl_api_mode']))
				$myOptions['api_mode'] = 'yes';

			if (isset($_POST['woocommerce_tunl_title']))
				$myOptions['title'] = sanitize_text_field(wp_unslash($_POST['woocommerce_tunl_title']));

			if (isset($_POST['woocommerce_tunl_username']))
				$username = sanitize_text_field(wp_unslash($_POST['woocommerce_tunl_username']));

			if (isset($_POST['woocommerce_tunl_password']))
				$password = sanitize_text_field(wp_unslash($_POST['woocommerce_tunl_password']));

			if (isset($_POST['woocommerce_tunl_live_username']))
				$liveusername = sanitize_text_field(wp_unslash($_POST['woocommerce_tunl_live_username']));

			if (isset($_POST['woocommerce_tunl_live_password']))
				$livepassword = sanitize_text_field(wp_unslash($_POST['woocommerce_tunl_live_password']));


			$passwordIsNotMasked = strlen(str_replace('*', '', $password)) > 4;
			$livePassIsNotMasked = strlen(str_replace('*', '', $livepassword)) > 4;

			$myOptions['username'] = $username;
			$myOptions['password'] = tunl_gateway_woocommerce_plugin_mask_secret($password);
			$myOptions['live_username'] = $liveusername;
			$myOptions['live_password'] = tunl_gateway_woocommerce_plugin_mask_secret($livepassword);

			if ($passwordIsNotMasked)
				$myOptions['saved_password'] = apply_filters('tunl_gateway_encrypt_filter', $password);

			if ($livePassIsNotMasked)
				$myOptions['saved_live_password'] = apply_filters('tunl_gateway_encrypt_filter', $livepassword);

			do_action('woocommerce_update_option', array('id' => 'woocommerce_tunl_settings'));
			update_option('woocommerce_tunl_settings', $myOptions);
		}

		/** Load backend options fields */
		public function init_form_fields()
		{
			$arrayfields = array();
			$arrayfields['enabled'] = array(
				'title' => __('Enable/Disable', 'tunlwoopay'),
				'label' => __('Enable Tunl', 'tunlwoopay'),
				'type' => 'checkbox',
				'description' => __('When enabled, the Tunl payment method will appear on the checkout page.', 'tunlwoopay'),
				'default' => 'no',
				'desc_tip' => true,
			);
			$arrayfields['title'] = array(
				'title' => __('Title', 'tunlwoopay'),
				'type' => 'text',
				'description' => __('Enter the payment method name to appear on the checkout page.', 'tunlwoopay'),
				'default' => __('Credit Card via Tunl', 'tunlwoopay'),
				'desc_tip' => true,
			);
			$arrayfields['live_username'] = array(
				'title' => __('Live API Key', 'tunlwoopay'),
				'label' => __('Live API Key', 'tunlwoopay'),
				'type' => 'text',
				'desc_tip' => false,
			);
			$arrayfields['live_password'] = array(
				'title' => __('Live Secret', 'tunlwoopay'),
				'label' => __('Live Secret', 'tunlwoopay'),
				'type' => 'text',
				'desc_tip' => false,
			);
			$arrayfields['live_connect_button'] = array(
				'title' => __('', 'tunlwoopay'),
				'type' => 'hidden',
				'desc_tip' => false,
			);
			$arrayfields['api_mode'] = array(
				'title' => __('Test Mode', 'tunlwoopay'),
				'label' => __('Test Mode', 'tunlwoopay'),
				'type' => 'checkbox',
				'description' => __('If you have a demo account, you can process a test transaction by enabling Test Mode.', 'tunlwoopay'),
				'default' => 'no',
				'desc_tip' => true,
			);
			
			$arrayfields['username'] = array(
				'title' => __('Test API Key', 'tunlwoopay'),
				'label' => __('Test API Key', 'tunlwoopay'),
				'type' => 'text',
				'desc_tip' => false,
			);
			$arrayfields['password'] = array(
				'title' => __('Test Secret', 'tunlwoopay'),
				'label' => __('Test Secret', 'tunlwoopay'),
				'type' => 'text',
				'desc_tip' => false,
			);
			
			$arrayfields['test_connect_button'] = array(
				'title' => __('', 'tunlwoopay'),
				'type' => 'hidden',
				'desc_tip' => false,
			);


			$this->form_fields = $arrayfields;
		}

		/** Load the credit card form fields */
		public function payment_fields()
		{
			$wp_kses_whitelist = array(
				'label' => true,
				'div' => array(
					'class' => array(),
				),
				'span' => array(
					'class' => array(),
				),
				'input' => array(
					'type' => array(),
					'name' => array(),
					'value' => array(),
					'autocomplete' => array(),
					'class' => array(),
					'maxlength' => array(),
					'placeholder' => array(),
					'id' => array(),
				),
			);

			$cardNumberInput = wp_kses(
				sprintf(
					__('<label>Card Number <span class="required">*</span></label>
							<div class="card_number_input">
							<input placeholder="0000 0000 0000 0000" id="tunl_ccno" type="text" autocomplete="off" class="input-text" name="tunl_cardnumber">
							</div>')
				),
				$wp_kses_whitelist
			);

			$expireInput = wp_kses(
				sprintf(
					__('<label>Expiration Date <span class="required">*</span></label>
							<input id="tunl_expdate" type="text" placeholder="MM / YY" class="input-text" name="tunl_expirydate">')
				),
				$wp_kses_whitelist
			);

			$cvvInput = wp_kses(
				sprintf(
					__('<label>Security Code <span class="required">*</span></label>
						<input id="tunl_cvc" type="password" placeholder="CVV" class="input-text" name="tunl_cardcode">')
				),
				$wp_kses_whitelist
			);

			?>
			<fieldset id="wc-<?php echo esc_attr($this->id); ?>-cc-form" class="wc-credit-card-form wc-payment-form tunlcform">
				<?php
				do_action('woocommerce_credit_card_form_start', $this->id);
				echo sprintf(
					'<div class="form-row form-row-wide cardNumberInput"> %s </div>
					 <div class="form-row form-row-first"> %s </div>
					 <div class="form-row form-row-last"> %s </div>
					 ',
					$cardNumberInput,
					$expireInput,
					$cvvInput
				);
				do_action('woocommerce_credit_card_form_end', $this->id);
				?>
			</fieldset>
			<?php
		}

		function auth_get_token($errors = null)
		{

			$myOptions = get_option('woocommerce_tunl_settings');

			$testApiKey = $myOptions['username'];
			$testSecret = apply_filters('tunl_gateway_decrypt_filter', $myOptions['saved_password']);
			$testUrl = TUNL_TEST_URL . '/auth';
			$liveApiKey = $myOptions['live_username'];
			$liveSecret = apply_filters('tunl_gateway_decrypt_filter', $myOptions['saved_live_password']);
			$liveUrl = TUNL_LIVE_URL . '/auth';
			$prodMode = (empty($myOptions['api_mode']) || ($myOptions['api_mode'] == 'no'));

			$apiKey = $prodMode ? $liveApiKey : $testApiKey;
			$secret = $prodMode ? $liveSecret : $testSecret;
			$url = $prodMode ? $liveUrl : $testUrl;

			$body = array(
				'username' => $apiKey,
				'password' => $secret,
				'scope' => 'PAYMENT_WRITE',
				'lifespan' => 15,
			);

			/** authentication process with tunl payment api */
			$request = wp_remote_post(
				$url,
				array(
					'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
					'body' => wp_json_encode($body),
					'method' => 'POST',
					'data_format' => 'body',
				)
			);

			if (is_wp_error($request))
				throw new Exception(__('Unknown Wordpress Error in Get Auth Token', 'woocommerce'));

			if (wp_remote_retrieve_response_code($request) == 401)
				throw new Exception(__('Authentication error. Please check your Tunl API Key/Secret and try again.', 'woocommerce'));

			$resultData = json_decode($request['body'], true);

			$token = $resultData['token'];
			$this->tunl_merchant_id = $resultData['user']['id'];
			$tokenSet = isset($token);

			if (!$tokenSet)
				throw new Exception(__('Tunl is temporarily unavailable. Please try again later.', 'woocommerce'));

			return $token;
		}

		function sanitize_tax_array($jsonString = ""){
			$unslashed = wp_unslash($jsonString);
			$taxArray = json_decode($unslashed, true);
			if (!is_array($taxArray)) return 0;
		
			$refundSumsArray = array_map(function($refundArray){
				if (!is_array($refundArray)) return 0;
				$sanitizedArray = array_filter($refundArray, 'is_numeric');
				return array_sum($sanitizedArray);
			}, $taxArray);
			
			return array_sum($refundSumsArray);
		}

		/**
		 * When place order complete then function work
		 *
		 * @param int $orderid orderid.
		 */
		public function process_refund($orderId, $amount = null, $reason = '')
		{

			if (empty($_POST['refund_amount']))
				return false;

			$taxRefund = 0;
			$totalAmountRefund = floatval(sanitize_text_field($_POST['refund_amount']));
			$setReason = '';

			if (!empty($_POST['refund_reason']))
				$setReason = sanitize_text_field(wp_unslash($_POST['refund_reason']));

			if ($_POST['line_item_tax_totals'])
				$taxRefund = $this->sanitize_tax_array($_POST['line_item_tax_totals']);

			$tunlPaymentId = get_post_meta($orderId, 'tunl_paymentid');
			$checkPayment = get_post_meta($orderId, 'check_tunlpayment');

			if (empty($checkPayment[0]))
				return false;

			$myOptions = get_option('woocommerce_tunl_settings');

			$prodMode = empty($myOptions['api_mode']) || ($myOptions['api_mode'] == 'no');
			$apiUrl = $prodMode ? TUNL_LIVE_URL : TUNL_TEST_URL;
	
			$auth = $this->auth_get_token();

			/** Get the payment details using tunl payment api */
			$apiPath = '/payments/merchant/' . $this->tunl_merchant_id . '/' . $tunlPaymentId[0];
			$url = $apiUrl . $apiPath;
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array('Content-Type' => 'application/json; charset=utf-8', 'Authorization' => 'Bearer ' . $auth),
					'method' => 'GET',
					'data_format' => 'body',
				)
			);

			$resutData = json_decode($response['body'], true);
			
			$body = array(
				'accountId' => $resutData['contactAccount']['id'],
				'contactId' => $resutData['contact']['id'],
				'amount' => $totalAmountRefund,
				'tax' => $taxRefund,
				'ordernum' => $orderId,
				'action' => 'return'
			);

			/** Issue refund via Tunl API */
			$apiPath = '/payments/merchant/' . $this->tunl_merchant_id;
			$url = $apiUrl . $apiPath;
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array('Content-Type' => 'application/json; charset=utf-8', 'Authorization' => 'Bearer ' . $auth),
					'body' => wp_json_encode($body),
					'method' => 'POST',
					'data_format' => 'body',
				)
			);

			$resultData = json_decode($response['body'], true);

			if ($resultData['code'] != 'PaymentException') {
				/** Some notes to customer (replace true with false to make it private) */
				$order = new WC_Order($orderId);
				$note = "Refunded $" . $totalAmountRefund . " - Refund ID: " . $resultData['ttid'] . " - Reason: " . $setReason;
				$order->add_order_note(esc_html($note));
				$order->save();
				return add_action('woocommerce_order_refunded', 'tunl_gateway_action_woocommerce_order_refunded', 10, 2);
			} else {
				$order = new WC_Order($orderId);
				$order->save();
				throw new Exception(__('Refund failed. Tunl refund was unsuccessful.', 'woocommerce'));
			}
		}

		private function validate_card_post_data($account, $expiryDate, $cardCode)
		{
			$account = str_replace(' ', '', $account);
			$accountIsValid = preg_match('/^\d{15,16}$/', $account) === 1;
			$expdatIsValid = preg_match('/^(0[1-9]|1[0-2])\/?([0-9]{2})$/', $expiryDate) === 1;
			$cvIsValid = preg_match('/^\d{3,4}$/', $cardCode) === 1;

			$error_messages = [];
			!$accountIsValid && array_push($error_messages, 'Invalid Credit Account Number');
			!$expdatIsValid && array_push($error_messages, 'Invalid Expiration Date');
			!$cvIsValid && array_push($error_messages, 'Invalid CVV');

			return $error_messages;

		}

		private function card_validation_errors($error_messages)
		{
			$validationErrorsMessage = implode(" - ", $error_messages);
			wc_add_notice($validationErrorsMessage, 'error');
			return array(
				'result' => 'error',
				'message' => 'Payment failed. Please try again.',
			);
		}

		public function process_payment($orderid)
		{

			global $woocommerce;

			/** We need it to get any order details */
			$order = wc_get_order($orderid);
			$name = $order->get_billing_first_name();
			$lname = $order->get_billing_last_name();
			$addressorder = $order->get_billing_address_1();
			$city = $order->get_billing_city();
			$state = $order->get_billing_state();
			$country = $order->get_billing_country();
			$postcode = $order->get_billing_postcode();

			if (empty($postcode))
				$postcode = $order->get_shipping_postcode();

			$orderid = $order->get_id();
			$gettotal = $order->get_total();
			$gettotaltax = $order->get_total_tax();
			$orderaddress = $addressorder;
			$myOptions = get_option('woocommerce_tunl_settings');

			try {
				$auth = $this->auth_get_token();
			}
			catch (exception $e) {
				error_log($e);
				throw new Exception(__('Tunl gateway cannot be authenticated. Please contact the merchant for further assistance.', 'woocommerce'));
			}

			$prodMode = empty($myOptions['api_mode']) || ($myOptions['api_mode'] == 'no');
			$apiUrl = $prodMode ? TUNL_LIVE_URL : TUNL_TEST_URL;

			$account = sanitize_text_field($_POST['tunl_cardnumber']);
			$expiryDate = sanitize_text_field($_POST['tunl_expirydate']);
			$cardCode = sanitize_text_field($_POST['tunl_cardcode']);
			$comments = sanitize_text_field($_POST['order_comments']);

			$validationErrors = $this->validate_card_post_data($account, $expiryDate, $cardCode);
			if (count($validationErrors) > 0)
				return $this->card_validation_errors($validationErrors);

			$body = array(
				'account' => $account,
				'autovault' => 'Y',
				'expdate' => $expiryDate,
				'cv' => $cardCode,
				'ordernum' => $orderid,
				'amount' => $gettotal,
				'tax' => $gettotaltax,
				'cardholdername' => $name . ' ' . $lname,
				'street' => $orderaddress,
				'zip' => $postcode,
				'comments' => $comments,
				'contactId' => null,
				'custref' => null,
				'accountId' => null,
				'action' => 'sale',
			);

			$apiPath = '/payments/merchant/' . $this->tunl_merchant_id;
			$url = $apiUrl . $apiPath;
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'Content-Type' => 'application/json; charset=utf-8',
						'Authorization' => 'Bearer ' . $auth,
					),
					'body' => wp_json_encode($body),
					'method' => 'POST',
					'data_format' => 'body',
				)
			);

			if (is_wp_error($response)) {
				wc_add_notice('Unknown Wordpress Error in Processing Payment.', 'error');
				return array(
					'result' => 'error',
					'message' => 'Unknown Wordpress Error in Processing Payment.',
				);
			}

			$resultData = json_decode($response['body'], true);

			/** Once Payment is complete and success then save paymentID ( ttid ) */
			if ($resultData['code'] != 'PaymentException') {
				$order->payment_complete();

				update_post_meta($orderid, 'tunl_paymentid', $resultData['ttid']);
				update_post_meta($orderid, 'check_tunlpayment', 1);

				/** Some notes to customer (replace true with false to make it private) */
				$note = "Tunl payment complete - Payment ID: " . $resultData['ttid'];
				$order->add_order_note($note, true);

				/** Empty cart */
				$woocommerce->cart->empty_cart();

				/** Redirect to the thank you page */
				return array(
					'result' => 'success',
					'redirect' => $this->get_return_url($order),
				);

			} else {
				error_log(print_r($resultData, true));
				/** If Payment process is failed */
				wc_add_notice('Payment failed. Please try again.', 'error');
				return array(
					'result' => 'error',
					'message' => 'Payment failed. Please try again.',
				);
			}


		}
	}
}

/** Script enqueue for plugin on admin */
function tunl_gateway_custom_scripts_enqueue()
{
	wp_enqueue_script('tunlpaymentJs', plugin_dir_url(__FILE__) . 'assets/js/tunl-payment.js', array('jquery'), '1.0.24', true);
	wp_enqueue_script('toastrJs', plugin_dir_url(__FILE__) . 'assets/js/toastr.min.js', array('jquery'), '1.0.24', true);
	wp_enqueue_script('maskJs', plugin_dir_url(__FILE__) . 'assets/js/jquery.mask.min.js', array('jquery'), '1.0.24', true);
	wp_enqueue_style('tunlpaymentCss', plugin_dir_url(__FILE__) . 'assets/css/tunl-payment.css', array(), '1.0.24', 'all');
	wp_enqueue_style('toastrCss', plugin_dir_url(__FILE__) . 'assets/css/toastr.min.css', array(), '1.0.24', 'all');
	wp_localize_script(
		'tunlpaymentJs',
		'adminAjax',
		array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'ajaxloader' => plugin_dir_url(__FILE__) . 'assets/images/loader.gif'
		)
	);
}
add_action('admin_enqueue_scripts', 'tunl_gateway_custom_scripts_enqueue');

/**  Script enqueue for frontend */
function tunl_gateway_custom_frontend_scripts_enqueue()
{
	wp_enqueue_script('maskJs', plugin_dir_url(__FILE__) . 'assets/js/jquery.mask.min.js', array('jquery'), '1.0.24', true);
	wp_enqueue_script('frontTunl', plugin_dir_url(__FILE__) . 'assets/js/tunl-front-payment.js', array('jquery'), '1.0.24', true);
	wp_enqueue_style('tunlFrontCss', plugin_dir_url(__FILE__) . 'assets/css/tunl-front-payment.css', array(), '1.0.24', 'all');
	wp_localize_script('frontTunl', 'cardDetail', array('cardfolder' => plugin_dir_url(__FILE__) . 'assets/images'));
}
add_action('wp_enqueue_scripts', 'tunl_gateway_custom_frontend_scripts_enqueue');

/**
 * Allow the payment gateway on woocommerce payment setting
 *
 * @param array $gateways gateways.
 */
function tunl_gateway_add_custom_gateway_class($gateways)
{
	$gateways[] = 'WCTUNLGateway';
	return $gateways;
}
add_filter('woocommerce_payment_gateways', 'tunl_gateway_add_custom_gateway_class');

/** Ajax functionality for connection with tunl payment api */
function tunl_gateway_wc_admin_connect_to_api()
{
	$apiMode = sanitize_text_field($_POST['api_mode']);
	$username = sanitize_text_field($_POST['username']);
	$password = sanitize_text_field($_POST['password']);
	$prodMode = empty($apiMode) || ($apiMode == 'no');

	$myOptionsData = get_option('woocommerce_tunl_settings');
	$decryptedLivePW = apply_filters('tunl_gateway_decrypt_filter', $myOptionsData['saved_live_password']);
	$decryptedTestPW = apply_filters('tunl_gateway_decrypt_filter', $myOptionsData['saved_password']);

	$last4ofSubmittedPW = substr($password, -4);
	$last4ofCurrentPW = $prodMode ? substr($decryptedLivePW, -4) : substr($decryptedTestPW, -4);
	$last4Match = $last4ofCurrentPW === $last4ofSubmittedPW;

	$url = $prodMode ? TUNL_LIVE_URL . '/auth' : TUNL_TEST_URL . '/auth';

	if ($last4Match)
		$password = $prodMode ? $decryptedLivePW : $decryptedTestPW;

	$body = array(
		'username' => $username,
		'password' => $password,
		'scope' => 'PAYMENT_WRITE',
		'lifespan' => 15,
	);
	$response = wp_remote_post(
		$url,
		array(
			'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
			'body' => wp_json_encode($body),
			'method' => 'POST',
			'data_format' => 'body',
		)
	);

	/** Authentication process with tunl payment api */
	$resultData = json_decode($response['body'], true);
	if (!isset($resultData['token'])) {
		$testMsg = "Validation failed. Please check your test API Key & Secret and try again."; 
		$liveMsg = "Validation failed. Please check your live API Key & Secret and try again."; 
		$message = $prodMode ? $liveMsg : $testMsg;
		$resultingData = array(
			'status' => false,
			'message' => $message,
			'data' => array(),
		);
		return wp_send_json($resultingData);
	}


	// $testMsg = "Your Test Keys are working.";
	// $liveMsg = "Your Live Keys are working.";
	// $message = $prodMode ? $liveMsg : $testMsg;
	$message = "Validation Successful.";

	$resultingData = array(
		'status' => true,
		'message' => $message,
		'data' => $resultData,
	);

	wp_send_json($resultingData);
}
add_action('wp_ajax_tunl_gateway_wc_admin_connect_to_api', 'tunl_gateway_wc_admin_connect_to_api');
add_action('wp_ajax_nopriv_tunl_gateway_wc_admin_connect_to_api', 'tunl_gateway_wc_admin_connect_to_api');


/** Hook for checkout validation */
add_action('woocommerce_after_checkout_validation', 'tunl_gateway_checkout_validation_unique_error', 9999, 2);
function tunl_gateway_checkout_validation_unique_error($data, $errors)
{

	$myOptions = get_option('woocommerce_tunl_settings');

	/** Check for any validation errors */
	if ($data['payment_method'] !== 'tunl')
		return;

	if (empty($_POST['tunl_cardnumber']))
		$errors->add('validation', '<strong>Card Number</strong> is a required field.');

	if (empty($_POST['tunl_expirydate']))
		$errors->add('validation', '<strong>Expiration Date</strong> is a required field.');

	if (empty($_POST['tunl_cardcode']))
		$errors->add('validation', '<strong>Security Code</strong> is a required field.');
}

/** Show error message if WooCommerce is not installed and/or active */
function tunl_gateway_woocommerce_tunl_missing_wc_notice()
{
	echo '<div class="error">
			<p><strong>' . sprintf(
			esc_html__(
				'Tunl requires WooCommerce to be installed and active. You can download %s here.',
				'woocommerce-gateway-stripe'
			),
			'<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
		) . '</strong></p></div>';
}

/** Display link to plugin Settings on Plugins page */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'tunl_add_plugin_page_settings_link');
function tunl_add_plugin_page_settings_link($links)
{
	$returnArray = [];
	$returnArray[] = '<a href="' .
		admin_url('admin.php?page=wc-settings&tab=checkout&section=tunl') .
		'">' . __('Settings') . '</a>';
	$returnArray[] = $links['deactivate'];
	return $returnArray;
}

function tunl_gateway_action_woocommerce_order_refunded($orderId, $refundId)
{
	// just need this function here to complete the refund
	// This is a No Op Function
}


/**  Text should be encrypted as (AES-256) */
add_filter('tunl_gateway_encrypt_filter', 'tunl_gateway_encrypt_key_function', 10, 1);
add_filter('deprecated_tunl_gateway_encrypt_filter', 'deprecated_tunl_gateway_encrypt_key_function', 10, 1);

function tunl_gateway_encrypt_key_function($plaintext)
{
	$output = false;
	$encrypt_method = "AES-256-CBC";

	$myOptions = get_option("woocommerce_tunl_settings");
	$secret_key = $myOptions['secret_encryption_key'];
	$secret_iv = bin2hex(random_bytes(16));
	// hash
	$key = hash('sha256', $secret_key);
	// iv - encrypt method AES-256-CBC expects 16 bytes 
	$iv = substr(hash('sha256', $secret_iv), 0, 16);

	$output = openssl_encrypt($plaintext, $encrypt_method, $key, 0, $iv);
	$output = $secret_iv . base64_encode($output);

	return $output;

}

function deprecated_tunl_gateway_encrypt_key_function($plaintext)
{

	$iv = '';

	$ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', '956560z4abzr4eb0bew5e512b39uq4s1', OPENSSL_RAW_DATA, $iv);

	$ivCiphertext = $iv . $ciphertext;

	return base64_encode($ivCiphertext);

}

/**  Encrypted text should be decrypt as plain text */
add_filter('tunl_gateway_decrypt_filter', 'tunl_gateway_decrypt_key_function', 10, 1);
add_filter('deprecated_tunl_gateway_decrypt_filter', 'deprecated_tunl_gateway_decrypt_key_function', 10, 1);

function tunl_gateway_decrypt_key_function($ivCiphertextB64)
{

	$output = false;
	$encrypt_method = "AES-256-CBC";
	$myOptions = get_option("woocommerce_tunl_settings");
	$secret_key = $myOptions['secret_encryption_key'];
	$secret_iv = substr($ivCiphertextB64, 0, 32);
	// hash
	$key = hash('sha256', $secret_key);
	// iv - encrypt method AES-256-CBC expects 16 bytes 
	$iv = substr(hash('sha256', $secret_iv), 0, 16);
	$cipherText = substr($ivCiphertextB64, 32);

	$output = openssl_decrypt(base64_decode($cipherText), $encrypt_method, $key, 0, $iv);

	return $output;
}

function deprecated_tunl_gateway_decrypt_key_function($ivCiphertextB64)
{

	$ivCiphertext = base64_decode($ivCiphertextB64);

	$iv = '';

	$ciphetext = $ivCiphertext;

	return openssl_decrypt($ciphetext, "aes-256-cbc", '956560z4abzr4eb0bew5e512b39uq4s1', OPENSSL_RAW_DATA, $iv);

}

function tunl_gateway_woocommerce_plugin_mask_secret($str)
{
	if (empty($str))
		return;
	if (strlen($str) < 5)
		return;
	return str_repeat('*', strlen($str) - 4) . substr($str, -4);
}
