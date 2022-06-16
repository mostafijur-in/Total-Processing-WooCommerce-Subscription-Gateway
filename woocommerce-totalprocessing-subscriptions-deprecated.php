<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * WC_Gateway_totalprocessing_Subscriptions class (Deprecated - for Subscriptions v1.x)
 * 
 * @extends WC_Gateway_totalprocessing_Subscriptions
 */
class WC_Gateway_totalprocessing_Subscriptions_Deprecated extends WC_Gateway_totalprocessing_Subscriptions
{

	function __construct()
	{

		parent::__construct();

		add_action('scheduled_subscription_payment_' . $this->id, array($this, 'process_scheduled_subscription_payment'), 10, 3);
		add_action('woocommerce_subscriptions_renewal_order_meta_query', array($this, 'remove_renewal_order_meta'), 10, 4);

		add_action('woocommerce_subscriptions_changed_failing_payment_method_' . $this->id, array($this, 'update_failing_payment_method'), 10, 3);
	}

	/**
	 * Process the payment and return the result
	 *
	 * @since 1.0.0
	 */
	function process_payment($order_id)
	{
		global $woocommerce;

		if (class_exists('WC_Subscriptions_Order') && WC_Subscriptions_Order::order_contains_subscription($order_id)) {

			$order = new WC_Order($order_id);

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

			if (method_exists('WC_Subscriptions_Order', 'get_total_initial_payment')) {
				$initial_payment = WC_Subscriptions_Order::get_total_initial_payment($order);
			} else {
				$initial_payment = WC_Subscriptions_Order::get_sign_up_fee($order) + WC_Subscriptions_Order::get_price_per_period($order);
			}
			print_r($initial_payment);
			die("des");
			$customer_response = $this->add_customer_to_order($order, $customer_token, $card_token);

			if ($initial_payment > 0)
				$payment_response = $this->process_subscription_payment($order, $initial_payment);

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
					update_post_meta($order->id, '_pin_customer_token', $customer_token);

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
	function process_scheduled_subscription_payment($amount_to_charge, $order, $product_id)
	{
		echo $amount_to_charge;
		die("dec");
		$result = $this->process_subscription_payment($order, $amount_to_charge);

		if (is_wp_error($result)) {

			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order, $product_id);
		} else {

			WC_Subscriptions_Manager::process_subscription_payments_on_order($order);
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
	function process_subscription_payment($order = '', $amount = 0)
	{
		global $woocommerce;
		$ip_address = isset($_POST['ip_address']) ? woocommerce_clean($_POST['ip_address']) : '';

		$order_items = $order->get_items();
		$product = $order->get_product_from_item(array_shift($order_items));
		$subscription_name = sprintf(__('%s - Order #%s', 'woocommerce'), esc_html(get_bloginfo('name', 'display')), $order->get_order_number());

		$customer_token = get_post_meta($order->id, '_pin_customer_token', true);

		if (!$customer_token)
			return new WP_Error('pin_error', __('Customer token is missing.', 'woo_pin_payments'));

		$currency = get_post_meta($order->id, '_order_currency', true);
		if (!$currency || empty($currency)) $currency = get_woocommerce_currency();

		$post_data = array(
			'email' => $order->billing_email,
			'description' => $subscription_name,
			'amount' => number_format((float)$amount * 100, 0, '.', ''),
			'currency' => $currency,
			'ip_address' => $ip_address,
			'customer_token' => $customer_token
		);

		$result = $this->call_pin($post_data, 'charges');

		if (is_wp_error($result)) {
			return $result;
		} elseif (!isset($result->response->success)) {
			return new WP_Error('pin_error', sprintf(__('Pin Payment error: %s', 'woo_pin_payments', 'woo_pin_payments'), $result->error_description));
		} elseif (isset($result->response->success) && $result->response->success != 1) {
			return new WP_Error('pin_error', sprintf(__('Pin Payment error: %s', 'woo_pin_payments'), $result->response->error_message));
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
	function add_customer_to_order($order, $customer_token = false, $card_token = false)
	{

		// If we have a customer id, use it for the order
		if ($customer_token) {
			update_post_meta($order->id, '_pin_customer_token', $customer_token);
		}

		// If we have a token, we can create a customer with it
		elseif ($card_token) {
			$post_data = array(
				'email' => $order->billing_email,
				'card_token' => $card_token
			);

			$result = $this->call_pin($post_data, 'customers');

			if (is_wp_error($result)) {
				return $result;
			} elseif (isset($result->response->token) && !empty($result->response->token)) {
				$order->add_order_note(sprintf(__('Pin customer added: %s', 'woo_pin_payments'), $result->response->token));

				if (is_user_logged_in()) {
					$customer_token = array(
						'customer_token' => $result->response->token,
						'display_number' => $result->response->card->display_number,
						'scheme' => $result->response->card->scheme,
						'email' => $result->response->email
					);
					add_user_meta(get_current_user_id(), '_pin_customer_token', $customer_token);
				}

				update_post_meta($order->id, '_pin_customer_token', $result->response->token);
				return $result->response->token;
			}
		}
	}

	/**
	 * Don't transfer Pin Payments customer/token meta when creating a parent renewal order.
	 * 
	 * @access public
	 * @param array $order_meta_query MySQL query for pulling the metadata
	 * @param int $original_order_id Post ID of the order being used to purchased the subscription being renewed
	 * @param int $renewal_order_id Post ID of the order created for renewing the subscription
	 * @param string $new_order_role The role the renewal order is taking, one of 'parent' or 'child'
	 * @return void
	 */
	function remove_renewal_order_meta($order_meta_query, $original_order_id, $renewal_order_id, $new_order_role)
	{

		if ('parent' == $new_order_role)
			$order_meta_query .= " AND `meta_key` NOT LIKE '_pin_customer_token' ";

		return $order_meta_query;
	}

	/**
	 * Update the customer token IDs for a subscription after a customer used the gateway to successfully complete the payment
	 * for an automatic renewal payment which had previously failed.
	 *
	 * @param WC_Order $original_order The original order in which the subscription was purchased.
	 * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 * @return void
	 */
	function update_failing_payment_method($original_order, $new_renewal_order)
	{
		update_post_meta($original_order->id, '_pin_customer_token', get_post_meta($new_renewal_order->id, '_pin_customer_token', true));
	}
}
