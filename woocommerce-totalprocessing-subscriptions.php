<?php

/**
 * WC_Gateway_totalprocessing_Subscriptions class.
 * 
 * @extends WC_Gateway_totalprocessing
 */
class WC_Gateway_totalprocessing_Subscriptions extends WC_Gateway_totalprocessing_Gateway {

	function __construct() {

		parent::__construct();

		add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'scheduled_subscription_payment'), 10, 2);
		add_action('wcs_resubscribe_order_created', array($this, 'delete_resubscribe_meta'), 10);
		add_action('woocommerce_subscription_failing_payment_method_updated_' . $this->id, array($this, 'update_failing_payment_method'), 10, 2);

		// Allow store managers to manually set Pin Payments as the payment method on a subscription
		add_filter('woocommerce_subscription_payment_meta', array($this, 'add_subscription_payment_meta'), 10, 2);
		add_filter('woocommerce_subscription_validate_payment_meta', array($this, 'validate_subscription_payment_meta'), 10, 2);
	}

	/**
	 * Checks if page is pay for order and change subs payment page.
	 *
	 * @since 1.8.2
	 * @return bool
	 */
	public function is_subs_change_payment() {
		return (isset($_GET['pay_for_order']) && isset($_GET['change_payment_method']));
	}

	/**
	 * Process the payment and return the result
	 *
	 * @since 1.0.0
	 */
	function process_payment($order_id) {
		global $woocommerce;
		$order = new WC_Order($order_id);


		$url = "$this->OPP_endPoint/v1/payments";
		$order = wc_get_order($order_id);
		$totalval = $woocommerce->cart->total;
		$currency = get_option('woocommerce_currency');
		$card_expiry = explode("/", $_POST['card_expiry']);
		//$url = "https://test.oppwa.com/v1/payments";
		$data = "entityId=" . $this->OPP_entityId .
			"&amount=" . $totalval .
			"&currency=" . $currency .
			"&paymentBrand=" . $_POST['paymentBrand'] .
			"&paymentType=DB" .
			"&card.number=" . $_POST['card_number'] .
			"&card.holder=" . $_POST['card_holder'] .
			"&card.expiryMonth=" . $card_expiry['0'] .
			"&card.expiryYear=" . $card_expiry['1'] .
			"&card.cvv=" . $_POST['card_cvv'] .
			// "&recurringType=INITIAL" .
			"&standingInstruction.mode=INITIAL" .
            "&standingInstruction.type=UNSCHEDULED" . // UNSCHEDULED|RECURRING
			"&standingInstruction.source=CIT" .
			"&createRegistration=true";

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization:Bearer ' . $this->OPP_accessToken
		));
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // this should be set to true in production
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$responseData = curl_exec($ch);
		if (curl_errno($ch)) {
			return curl_error($ch);
		}
		curl_close($ch);
		$response_data = json_decode($responseData);

		tp_debug_ngrok('subscription response', $response_data);

		$_POST['card_token'] = $response_data->registrationId;
		if ((function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order_id)) || wcs_order_contains_renewal($order_id) || (function_exists('wcs_is_subscription') && wcs_is_subscription($order_id))) {

			$card_token = isset($_POST['card_token']) ? woocommerce_clean($_POST['card_token']) : '';
			$customer_token = false;

			// Are we paying by customer token?
			if (isset($_POST['pin_customer_token']) && $_POST['pin_customer_token'] !== 'new' && is_user_logged_in()) {
				$customer_tokens = get_user_meta(get_current_user_id(), '_pin_customer_token', false);

				if (isset($customer_tokens[$_POST['pin_customer_token']]['customer_token'])) {
					$customer_token = $customer_tokens[$_POST['pin_customer_token']]['customer_token'];
				} else {
					wc_add_notice(__('Invalid card. ', 'woo_pin_payments'), 'error');
					return;
				}
			} elseif (empty($card_token)) {
				wc_add_notice(__('Please make sure your card details have been entered correctly and that your browser supports JavaScript.', 'woo_pin_payments'), 'error');
				return;
			}

			$customer_response = $this->add_customer_to_order($order, $customer_token, $card_token);

			if ($order->get_total() > 0 && !$this->is_subs_change_payment())
				//$payment_response = $this->process_subscription_payment($order,$order->get_total());

				if (is_wp_error($customer_response)) {
					wc_add_notice($customer_response->get_error_message(), 'error');
					return;
				} elseif (isset($payment_response) && is_wp_error($payment_response)) {
					wc_add_notice($payment_response->get_error_message(), 'error');
					return;
				} else {
					// Payment complete
					$order->payment_complete();
					$woocommerce->cart->empty_cart();

					// Activate subscriptions
					WC_Subscriptions_Manager::activate_subscriptions_for_order($order);

					if ($customer_token)
						$this->save_subscription_meta($order->id, $customer_token);

					if (is_woocommerce_pre_2_1()) {
						$redirect = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(get_option('woocommerce_thanks_page_id'))));
					} else { // WC 2.1+
						$redirect = $this->get_return_url($order);
					}
					return array(
						'result' => 'success',
						'redirect' => $redirect
					);
				}
		} else {
			return parent::process_payment($order_id);
		}
	}

	/**
	 * scheduled_subscription_payment function.
	 * 
	 * @param $amount_to_charge float The amount to charge.
	 * @param WC_Order $renewal_order A WC_Order object created to record the renewal payment.
	 * @access public
	 * @return void
	 */
	function scheduled_subscription_payment($amount_to_charge, $renewal_order) {

		$result = $this->process_subscription_payment($renewal_order, $amount_to_charge);

		if (is_wp_error($result)) {
			$renewal_order->update_status('failed', sprintf(__('Pin Payments Transaction Failed (%s)', 'woocommerce'), $result->get_error_message()));
		}
	}

	/**
	 * process_subscription_payment function.
	 * 
	 * @access public
	 * @param mixed $order
	 * @param int $amount (default: 0)
	 * @return void
	 */
	function process_subscription_payment($order = '', $amount = 0) {
		if (0 == $amount) {
			// Payment complete
			$order->payment_complete();
			return true;
		}

		global $woocommerce;
		$ip_address = isset($_POST['ip_address']) ? woocommerce_clean($_POST['ip_address']) : '';

		$subscription_name = sprintf(__('%s - Order #%s', 'woocommerce'), esc_html(get_bloginfo('name', 'display')), $order->get_order_number());

		$customer_token = get_post_meta($order->id, '_pin_customer_token', true);

		if (!$customer_token)
			return new WP_Error('pin_error', __('Customer token is missing.', 'woo_pin_payments'));

		$currency = get_post_meta($order->id, '_order_currency', true);
		if (!$currency || empty($currency)) $currency = get_woocommerce_currency();

		if (version_compare(WC_VERSION, '2.7', '<')) {
			$billing_email = $order->billing_email;
		} else {
			$billing_email = $order->get_billing_email();
		}
		if (in_array(get_woocommerce_currency(), array('BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'))) {
			$damount = $amount;
		} else {
			$damount = number_format((float)$amount * 100, 0, '.', '');
		}


		$post_data = array(
			'email' => $billing_email,
			'description' => $subscription_name,
			'amount' => $damount,
			'currency' => $currency,
			'ip_address' => $ip_address,
			'customer_token' => $customer_token
		);

		$result = $this->call_pin($post_data, 'charges', $order);

		if (is_wp_error($result)) {
			return $result;
		} elseif (!isset($result->response->success)) {
			return new WP_Error('pin_error', sprintf(__('Pin Payment error: %s', 'woo_pin_payments', 'woo_pin_payments'), $result->error_description));
			//return new WP_Error( 'pin_error', sprintf(__('Payment error: %s','woo_pin_payments', 'woo_pin_payments' ),print_r($result,true)));
		} elseif (isset($result->response->success) && $result->response->success != 1) {
			return new WP_Error('pin_error', sprintf(__('Pin Payment error: %s', 'woo_pin_payments'), $result->response->error_message));
			//return new WP_Error( 'pin_error', sprintf(__('Payment error: %s', 'woo_pin_payments') ,print_r($result,true)));
		} else {
			$order->payment_complete($result->response->token);
			$order->add_order_note(sprintf(__('Pin Payments subscription payment completed (Charge ID: %s)', 'woo_pin_payments'), $result->response->token));
			return true;
		}
	}

	/**
	 * add_customer_to_order function.
	 * 
	 * @access public
	 * @param mixed $order
	 * @param string $customer_token (default: '')
	 * @param string $card_token (default: '')
	 * @return void
	 */
	function add_customer_to_order($order, $customer_token = false, $card_token = false) {

		// If we have a customer id, use it for the order
		if ($customer_token) {
			$this->save_subscription_meta($order->id, $customer_token);
		}

		// If we have a token, we can create a customer with it
		elseif ($card_token) {
			if (version_compare(WC_VERSION, '2.7', '<')) {
				$billing_email = $order->billing_email;
			} else {
				$billing_email = $order->get_billing_email();
			}

			$post_data = array(
				'email' => $billing_email,
				'card_token' => $card_token
			);

			$order->add_order_note(sprintf(__('Pin customer added: %s', 'woo_pin_payments'), $card_token));

			if (is_user_logged_in()) {
				$customer_token = array(
					'customer_token' => $card_token,
					'display_number' => $billing_email,
					'scheme' => '',
					'email' => $billing_email
				);
				add_user_meta(get_current_user_id(), '_pin_customer_token', $customer_token);
			}

			$this->save_subscription_meta($order->id, $card->token);
		}
	}

	/**
	 * Store the customer and card IDs on the order and subscriptions in the order
	 *
	 * @param int $order_id
	 * @param string $customer_id
	 */
	protected function save_subscription_meta($order_id, $customer_id) {
		$customer_id = wc_clean($customer_id);
		update_post_meta($order_id, '_totalprocessing_customer_token', $customer_id);

		// Also store it on the subscriptions being purchased in the order
		foreach (wcs_get_subscriptions_for_order($order_id) as $subscription) { //print_r($subscription);
			update_post_meta($subscription->id, '_totalprocessing_customer_token', $customer_id);
		} //die;
	}


	/**
	 * Include the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions v2.0+.
	 *
	 * @param array $payment_meta associative array of meta data required for automatic payments
	 * @param WC_Subscription $subscription An instance of a subscription object
	 * @return array
	 */
	public function add_subscription_payment_meta($payment_meta, $subscription) {
		$payment_meta[$this->id] = array(
			'post_meta' => array(
				'_pin_customer_token' => array(
					'value' => get_post_meta($subscription->id, '_pin_customer_token', true),
					'label' => 'Pin Payments Customer Token',
				),
			),
		);
		return $payment_meta;
	}

	/**
	 * Validate the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions 2.0+.
	 *
	 * @param string $payment_method_id The ID of the payment method to validate
	 * @param array $payment_meta associative array of meta data required for automatic payments
	 * @return array
	 */
	public function validate_subscription_payment_meta($payment_method_id, $payment_meta) {
		if ($this->id === $payment_method_id) {
			if (!isset($payment_meta['post_meta']['_pin_customer_token']['value']) || empty($payment_meta['post_meta']['_pin_customer_token']['value'])) {
				throw new Exception('A "_pin_customer_token" value is required.');
			}
		}
	}

	/**
	 * Don't transfer customer meta to resubscribe orders.
	 *
	 * @access public
	 * @param int $resubscribe_order The order created for the customer to resubscribe to the old expired/cancelled subscription
	 * @return void
	 */
	public function delete_resubscribe_meta($resubscribe_order) {
		delete_post_meta($resubscribe_order->id, '_pin_customer_token');
	}

	/**
	 * Update the customer token IDs for a subscription after a customer used the gateway to successfully complete the payment
	 * for an automatic renewal payment which had previously failed.
	 *
	 * @param WC_Subscription $subscription The subscription for which the failing payment method relates.
	 * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 * @return void
	 */
	function update_failing_payment_method($subscription, $new_renewal_order) {
		//die('updating meta now: subscription id#'.$sunscription->id.', renewalorder id#'.$new_renewal_order->id);
		update_post_meta($subscription->id, '_pin_customer_token', get_post_meta($new_renewal_order->id, '_pin_customer_token', true));
	}
}
