<?php
namespace VOF\Includes\Fulfillment;

use VOF\Models\VOF_Payment;
use VOF\Models\VOF_Membership;
use VOF\Utils\Helpers\VOF_Temp_User_Meta;

use Rtcl\Gateways\Store\GatewayStore;
use Rtcl\Traits\SingletonTrait;
use Rtcl\Helpers\Functions;
use Rtcl\Models\Payment;
use WP_Error;

class VOF_Fulfillment_Handler {
    private static $instance = null;
    private $temp_user_meta;
    private $subscription_handler;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->temp_user_meta       = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
        $this->subscription_handler = VOF_Subscription_Handler::getInstance();
        // Hook the fulfillment handler to process the scheduled event
        if ($this->vof_is_interim_fulfillment_enabled()) {
            // add_action('vof_monthly_subscription_fulfillment', [$this, 'vof_process_monthly_benefits']);
            
            // Set up mesh-timer hooks
            add_action('admin_init', [$this, 'vof_check_pending_fulfillments']);
            add_action('wp', [$this, 'vof_check_pending_fulfillments']);
            add_action('rest_api_init', [$this, 'vof_check_pending_fulfillments']);
        
            // Add a 5-minute transient lock to prevent excessive checks
            add_action('vof_fulfillment_check_completed', [$this, 'vof_set_check_lock'], 10, 0);
        } else {
            // Remove any scheduled events if disabled
            wp_clear_scheduled_hook('vof_monthly_subscription_fulfillment');
        }
    }

    /**
     * Main fulfillment process entry point
     * 
     * Tries to follow RTCL's original fulfillment flow steps.
     * Tries to reuse most of RTCL's fulfillment functions.
     */
    public function vof_initiate_fulfillment($stripe_data) {
        try {
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            error_log('VOF Debug: INITIATING FULFILLMENT with $stripe_data -> ' . print_r($stripe_data, true));

            $temp_user = $this->temp_user_meta->vof_get_temp_user_by_uuid($stripe_data['uuid']);
            $post_id = $stripe_data['post_id'];

            if (!$temp_user) {
                throw new \Exception('No temporary user data found');
            }

            // Build Fulfillment Data Structure
            $vof_fulfillment_ds = $this->vof_build_fulfillment_data_structure($stripe_data, $temp_user);
            error_log('VOF Debug: From VOF Fulfillment Handler; returning vof_fulfillment_ds with data: ' . print_r($vof_fulfillment_ds, true));

            // Create RTCL payment post first
            $order_props = $this->vof_create_rtcl_order_id($vof_fulfillment_ds, $stripe_data);
            error_log('VOF Debug: From VOF Fulfillment Handler (outer calling fn); returning $order_props with $order_id value: ' . print_r($order_props['order_id'], true) . "\n\n and" . ' $order_props with $gateway value: ' . print_r($order_props['gateway'], true));
        
            if (!$order_props['order_id']) {
                throw new \Exception('Failed to create VOF->RTCL payment record');
            }

            // $this->vof_validate_data($stripe_data, $temp_user); // maybe not needed??

            $is_vof_fulfilled = $this->vof_fulfill_and_handoff_product($order_props, $post_id);
            error_log('VOF Debug: Retrieved $payment_data with data: ' . print_r($is_vof_fulfilled, true));
            
            if (is_wp_error($is_vof_fulfilled)) {
                throw new \Exception($is_vof_fulfilled->get_error_message());
            }

            // Release the captive listing!!!
            $this->vof_publish_listing($post_id);

            // update vof database with new data 
            $this->vof_update_vof_flow($stripe_data, $is_vof_fulfilled);
            // $this->vof_update_vof_temp_user_data($stripe_data, $temp_user); // DEVELOP

            $wpdb->query('COMMIT');
            return true;

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('VOF Fulfillment Error: ' . $e->getMessage());
            do_action('vof_fulfillment_failed', $stripe_data, $e->getMessage());
            throw $e;
        }
    }

    private function vof_create_rtcl_order_id($vof_fulfillment_ds, $stripe_data) { // KEEP
        // Get RTCL objects
        $pricing          = rtcl()->factory->get_pricing( $vof_fulfillment_ds['checkout_data']['pricing_id'] );
        $gateway          = Functions::get_payment_gateway( $vof_fulfillment_ds['checkout_data']['payment_method'] );
        $checkout_data    = $vof_fulfillment_ds['checkout_data'];
        $new_order_args   = $vof_fulfillment_ds['new_order_args'];
        $order_id         = null;
        $order_props      = [];


        // ##############

        // Validate checkout data
        $errors = new WP_Error();
        do_action('rtcl_checkout_data', $vof_fulfillment_ds['checkout_data'], $pricing, $gateway, [], $errors); // filter not used
        /** ####### about rtcl_checkout_validation_errors ######
         * 
         * used @wp-content/plugins/classified-listing/app/Controllers/Hooks/AppliedBothEndHooks.php
         * used @wp-content/plugins/classified-listing-pro/app/Gateways/WooPayment/WooPayment.php
         */
        $errors = apply_filters('rtcl_checkout_validation_errors', $errors, $vof_fulfillment_ds['checkout_data'], $pricing, $gateway, []);

        if (is_wp_error($errors) && $errors->has_errors()) {
            error_log('VOF Error: Validation failed - ' . $errors->get_error_message());
            return false;
        }

        // Create order id; Lots of things happen with this filter. 
		// filter hook used @wp-content/plugins/classified-listing/app/Controllers/Hooks/AppliedBothEndHooks.php 
        // & @wp-content/plugins/classified-listing-store/app/Controllers/Hooks/MembershipHook.php 
        $order_id = wp_insert_post(apply_filters('rtcl_checkout_process_new_order_args', $new_order_args, $pricing, $gateway, $checkout_data));

        if (!$order_id || is_wp_error($order_id)) {
            error_log('VOF Error: Failed to create order id. ' . ($order_id instanceof WP_Error ? $order_id->get_error_message() : ''));
            return false;
        }

        // Update Return variable
        $order_props = [
            'order_id'       => $order_id, 
            'gateway'        => $gateway, 
            'intent_id'      => $stripe_data['stripe_intent_id'], 
            'transaction_id' => $stripe_data['rtcl_transaction_id'], 
            'is_captured'    => $stripe_data['is_stripe_captured']
        ];
        
        // error_log('VOF Debug: From VOF Fulfillment Handler; returning $order_props with $order_id value: ' . print_r($order_props['order_id'], true) . "\n and" . '$order_props with $gateway value: ' . print_r($order_props['gateway'], true));
        error_log('VOF Debug: From VOF Fulfillment Handler (inner); returning $order_id with value: ' . print_r($order_id, true));
        error_log('VOF Debug: From VOF Fulfillment Handler (inner); returning $gateway with value: ' . print_r($gateway, true));

        return $order_props;
    }

    private function vof_fulfill_and_handoff_product($order_props, $post_id ){
        // ####### Fulfillment & Handoff Handling Starts Here: #######
        error_log('VOF Debug: Starting Fulfillment and Handoff with $order_props: ' . print_r($order_props, true));
        error_log('VOF Debug: Starting Fulfillment and Handoff with $order_id: ' . print_r($order_props['order_id'], true). "\n" . 'And $gateway: ' . print_r($order_props['gateway'], true));

        // Set order key and trigger actions
        Functions::clear_notices();
        $order_id             = $order_props['order_id'];
        $gateway              = $order_props['gateway'];
        $stripe_intent_id     = $order_props['intent_id'];
        $transaction_id       = $order_props['transaction_id'];
        $is_captured          = $order_props['is_captured'];

		$success              = false;
		$redirect_url         = $gateway_id = null;
		$payment_process_data = [];


        $order = rtcl()->factory->get_order($order_id);
        update_post_meta($order->get_id(), '_stripe_intent_id', $stripe_intent_id);
		$order->update_meta('_stripe_charge_captured', $is_captured);
        $order->set_order_key();                                                      // Already set in data structure... or with hook

        // do_action('rtcl_checkout_process_new_payment_created', $order_id, $order); // HOOK NOT USED (!ADD_ACTION) 
        // $gateway = new GatewayStore();
        

        // process payment
        try {
            /**    ###### The HAND-OFF happens here ######
             *
             * $gateway->process_payment($order) -> Triggers and sets payment complete 
             * then indirectly hands-off product to rtcl's -> indirectly triggers rtcl's membership fulfillment processes
             * 
             * Notes:
             *  - Core WP triggers the status change action
             *  - Which 'StatusChange.php' catches and fulfills
             */
            // $payment_process_data = $gateway->process_payment( $order );
            $payment_process_data = $order->payment_complete($transaction_id);

            error_log('VOF Debug: Payment process result: ' . print_r($payment_process_data, true));
            error_log('VOF Debug: Order status after payment: ' . $order->get_status());
            
            $payment_process_data = apply_filters( 'rtcl_checkout_process_payment_result', $payment_process_data, $order );
            $redirect_url         = ! empty( $payment_process_data['redirect'] ) ? $payment_process_data['redirect'] : null;
            error_log('VOF Debug: $redirect_url is: ' . print_r($redirect_url, true));

            // Redirect to success/confirmation/payment page
            // if ( isset( $payment_process_data['result'] ) && 'success' === $payment_process_data['result'] ) {
            if ( $payment_process_data ) {
                $success = true;

                /** ABOUT: 'rtcl_checkout_process_success'
                 * 
                 * Hook Used @:
                 *  - ADD_ACTION @wp-content/plugins/classified-listing/app/Controllers/Hooks/ActionHooks.php 
                 *  - ADD_ACTION @wp-content/plugins/classified-listing/app/Controllers/Hooks/AppliedBothEndHooks.php
                 */
                // do_action( 'rtcl_checkout_process_success', $order, $payment_process_data );
            } else {
                wp_delete_post( $order->get_id(), true );
                if ( ! empty( $payment_process_data['message'] ) ) {
                    Functions::add_notice( $payment_process_data['message'], 'error' );
                }
                // do_action( 'rtcl_checkout_process_error', $order, $payment_process_data );
            }
		} catch ( \Exception $e ) {
            error_log('VOF DEBUG: catched exception on VOF Hand-off with message: ' . $e);
        }

        error_log('VOF DEBUG: Returning $payment_process_data with data: ' .  print_r($payment_process_data, true));
        return $payment_process_data;

        // $error_message   = Functions::get_notices( 'error' );
		// $success_message = Functions::get_notices( 'success' );
		// Functions::clear_notices();

		// return $payment_process_data = [ 
        //     'error_message'   => $error_message,
        //     'success_message' => $success_message,
        //     'success'         => $success,
        //     'redirect_url'    => $redirect_url,
        //     'gateway_id'      => $gateway_id
        // ];
    }    

    private function vof_build_fulfillment_data_structure($stripe_data, $temp_user) { // KEEP 
        // Format stripe amount to RTCL format
        $amount     = $stripe_data['amount'] / 100;
        $stripe_fee = $stripe_data['stripe_fee'] / 100;
        $stripe_net = $stripe_data['stripe_net_amount'] / 100;
    
        $VOF_Fulfillment_DS = [
            'checkout_data' => [
                'type'                      => 'membership',
                'listing_id'                => 0,
                'pricing_id'                => $stripe_data['rtcl_pricing_tier_id'] ?? null, // $pricing_id MAYBE MISSING ON BOTH STRIPE'S and VOF_TEMP_META
                'payment_method'            => 'stripe',
                'rtcl_privacy_policy'       => 'on',
                'rtcl_checkout_nonce'       => wp_create_nonce('rtcl_checkout'),
                '_wp_http_referer'          => '/checkout/membership/',
                'action'                    => 'rtcl_ajax_checkout_action',
                'stripe_payment_method'     => $stripe_data['stripe_payment_method_id'] ?? null
            ],
            'meta_input' => [
                'customer_id'               => $temp_user['true_user_id'],
                'customer_ip_address'       => Functions::get_ip_address(),
                '_order_key'                => apply_filters('rtcl_generate_order_key', uniqid('rtcl_oder_')), // maybe let rtcl do on order key set...
                '_pricing_id'               => $stripe_data['rtcl_pricing_tier_id'],     // Check
                'amount'                    => $amount,
                '_tax_amount'               => 0.00,
                '_subtotal'                 => $amount,
                '_payment_method'           => 'stripe',
                '_payment_method_title'     => 'Stripe',
                '_order_currency'           => strtoupper($stripe_data['currency']),
                '_billing_email'            => $temp_user['vof_email'],
                '_billing_first_name'       => isset($stripe_data['customer_name']) ? $stripe_data['customer_name'] : '', // check if works
                '_billing_last_name'        => isset($stripe_data['last_name']) ? $stripe_data['last_name'] : '',         // check if works
                'payment_type'              => 'membership',
                // VOF External Provided:
                '_stripe_customer_id'       => $stripe_data['customer'],             // cus_xxxxxxxx
                '_stripe_subscription_id'   => $stripe_data['subscription_id'],      // not used in rtcl orglly
                // Let RTCL handle these (maybe... try complete_payment directly first else directly insert missing data):
                //  '_stripe_charge_captured'  => $stripe_data['is_stripe_captured'],
                 '_stripe_fee'              => $stripe_fee,
                 '_stripe_net'              => $stripe_net,
                 '_stripe_currency'         => strtoupper($stripe_data['currency']),  // MXN
                // '_stripe_intent_id'        => $stripe_data['transaction_id'],      // set later
                // 'transaction_id'           => $stripe_data['rtcl_transaction_id'], // set later ch_xxxxxxxx,
                 // not stripe's
                  // '_applied'                => 1,                                  // set later
                  // 'date_paid'               => current_time('mysql'),              // set later
                  // 'date_completed'          => current_time('mysql')               // don't know if necessary... 
            ],
            'new_order_args' => [
                'post_title'                => esc_html__('Order on', 'classified-listing') . ' ' . current_time("l jS F Y h:i:s A"),
                'post_status'               => 'rtcl-created',
                'post_parent'               => '0',
                'ping_status'               => 'closed',
                'post_author'               => 1,
                'post_type'                 => rtcl()->post_type_payment,
                'meta_input'                => null // Will be filled with meta_input array (above)
            ]
        ];
    
        // Set meta_input in new_order_args
        $VOF_Fulfillment_DS['new_order_args']['meta_input'] = $VOF_Fulfillment_DS['meta_input'];

        return $VOF_Fulfillment_DS;
    }

    /**
     * Publishes the temporary listing
     */
    private function vof_publish_listing($post_id) {
        $post_data = [
            'ID'          => $post_id,
            'post_status' => 'publish'
        ];

        // $result = wp_update_post($post_data);
        $result = wp_update_post( apply_filters( 'rtcl_listing_save_update_args', $post_data, 'update' ) );
        if (is_wp_error($result)) {
            throw new \Exception('Failed to publish listing: ' . $result->get_error_message());
        }

        return $result;
    }

    /**
     * Update VOF flow status and data
     * 
     * @param array $stripe_data Stripe data
     * @param bool $is_vof_fulfilled Whether fulfillment was successful
     * @return bool Success status
     */
    public function vof_update_vof_flow($stripe_data, $is_vof_fulfilled) {
        if (!$is_vof_fulfilled) {
            return false;
        }

        $vof_flow_started_at = $this->temp_user_meta->vof_get_flow_started_at_by_uuid($stripe_data['uuid']);

        $price_purchased_at    = $stripe_data['amount'] / 100 ?? null;
        $vof_flow_completed_at = current_time('mysql') ?? null;
        $vof_flow_time_elapsed = $vof_flow_completed_at - $vof_flow_started_at ?? null;
        $vof_sub_start_date    = date('Y-m-d H:i:s', $stripe_data['current_period_start']) ?? null; 
        $vof_sub_expiry_date   = date('Y-m-d H:i:s', $stripe_data['current_period_end']) ?? null;

        // Check if this is a longer-than-monthly subscription and interim fulfillment is enabled
        $is_long_term_subscription = false;
        $next_fulfillment_date = null;
        $current_timestamp = strtotime(current_time('mysql'));
        
        if ($this->vof_is_interim_fulfillment_enabled() && 
            isset($stripe_data['interval']) && 
            $stripe_data['interval'] !== 'month') {
            
            $is_long_term_subscription = true;
            // [PRODUCTION] Set next fulfillment date to 30 days from now
            // $next_fulfillment_date = date('Y-m-d H:i:s', strtotime('+30 days'));
            // [TEST ONLY] Set next fulfillment date to 2 minutes from now
            $next_fulfillment_date = date('Y-m-d H:i:s', $current_timestamp + (2 * MINUTE_IN_SECONDS));
        }

        $vof_updated_data = [
            'vof_flow_status'        => 'completed' ?? null,
            'vof_flow_completed_at'  => $vof_flow_completed_at,
            'vof_flow_time_elapsed'  => $vof_flow_time_elapsed,
            'stripe_user_name'       => $stripe_data['customer_name'] ?? null,
            'stripe_customer_id'     => $stripe_data['customer'] ?? null,
            'stripe_sub_id'          => $stripe_data['subscription_id'] ?? null,
            'stripe_sub_status'      => $stripe_data['status'] ?? null,
            'stripe_prod_name'       => $stripe_data['product_name'] ?? null,
            'stripe_prod_lookup_key' => $stripe_data['lookup_key'] ?? null,
            'stripe_period_interval' => $stripe_data['interval'] ?? null,
            'price_purchased_at'     => $price_purchased_at,
            'stripe_sub_start_date'  => $vof_sub_start_date,
            'stripe_sub_expiry_date' => $vof_sub_expiry_date
        ];

        // Update the VOF temp user data first
        self::vof_update_vof_temp_user_data($stripe_data['uuid'], $vof_updated_data);

        // Store next fulfillment date for long-term subscriptions if interim fulfillment is enabled
        if ($is_long_term_subscription && $next_fulfillment_date) {
            $this->temp_user_meta->vof_update_custom_meta(
                $stripe_data['uuid'], 
                'next_interim_fulfillment', 
                $next_fulfillment_date
            );

            // Also store last fulfillment date as current time (initial fulfillment)
            $this->temp_user_meta->vof_update_custom_meta(
                $stripe_data['uuid'], 
                'last_interim_fulfillment', 
                current_time('mysql')
            );
        }

        // Now attempt the MailerLite integration with a try/catch that won't disrupt the main flow
        try {
            $this->vof_capture_complete_lead_stage2($stripe_data, $vof_updated_data);
        } catch (\Throwable $e) {
            // Catch ANY error (including fatal errors) and log it
            error_log('VOF Warning: MailerLite lead capture failed but fulfillment continues - ' . $e->getMessage());
        }

        // Return success regardless of MailerLite results
        return true;
    }
    
    /**
     * Capture complete lead at stage 2
     */
    private function vof_capture_complete_lead_stage2($stripe_data, $vof_updated_data) {
        try {

            // Skip if MailerLite integration is disabled
            if (!get_option('vof_mailerlite_enabled', false)) {
                error_log('VOF Debug: MailerLite integration is disabled, skipping lead capture at Stage 2');
                return; // Simply return without attempting to capture
            }

            // Get MailerLite instance
            $mailerlite = \VOF\VOF_Core::instance()->vof_get_mailerlite();

            // Only proceed if MailerLite is available and flow is completed
            if ($mailerlite && 
                $mailerlite->vof_is_connected() && 
                isset($vof_updated_data['vof_flow_status']) && 
                $vof_updated_data['vof_flow_status'] === 'completed') {
                
                $this->vof_process_completed_lead($stripe_data, $vof_updated_data);
            }
        } catch (\Throwable $e) {
            // Catch ANY error and log it
            error_log('VOF Warning: MailerLite lead capture stage 2 failed - ' . $e->getMessage());
            // Re-throw to be caught by parent
            throw $e;
        }
    }

    /**
     * Process completed lead for MailerLite
     */
    private function vof_process_completed_lead($stripe_data, $vof_updated_data) {
        // Main try/catch block
        try {
            $mailerlite = \VOF\VOF_Core::instance()->vof_get_mailerlite();
            if (!$mailerlite || !$mailerlite->vof_is_connected()) {
                return;
            }
        
            // Get temp user data
            $temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
            $temp_user = $temp_user_meta->vof_get_temp_user_by_uuid($stripe_data['uuid']);
        
            if (!$temp_user || empty($temp_user['vof_email'])) {
                error_log('VOF Error: Cannot process completed lead - no temp user data');
                return;
            }
        
            // Ensure VOF Complete group exists
            $complete_group_id = $mailerlite->vof_ensure_group_exists('VOF Completed');
            if (!$complete_group_id) {
                error_log('VOF Error: Failed to ensure VOF Complete group exists');
                return;
            }
        
            // Get Stage 1 group ID
            $stage1_group_id = get_option('vof_mailerlite_onboarding_group', '');
            if (empty($stage1_group_id)) {
                $stage1_group_id = $mailerlite->vof_ensure_group_exists('__VOF_Fallback__');
            }
        
            // Add to VOF Complete group with enhanced data
            $fields = [
                'phone' => $temp_user['vof_phone'],
                'whatsapp' => $temp_user['vof_whatsapp'],
                // 'payment_status' => 'completed',
                // 'tier_purchased' => $stripe_data['product_name'],
                // 'purchase_date' => date('Y-m-d H:i:s'),
                // 'subscription_id' => $stripe_data['subscription_id'],
                // 'customer_id' => $stripe_data['customer'],
                // 'amount_paid' => ($stripe_data['amount'] / 100)
            ];
        
            // Add subscriber to the completed group
            try {
                $mailerlite->vof_add_subscriber($temp_user['vof_email'], $fields, [$complete_group_id]);
            } catch (\Exception $e) {
                error_log('VOF Warning: Could not add subscriber to completed group - ' . $e->getMessage());
                // Continue processing despite this error
            }
        
            // Try to remove from Stage 1 group if it exists
            if ($stage1_group_id) {
                try {
                    $mailerlite->vof_remove_subscriber_from_group($temp_user['vof_email'], $stage1_group_id);
                    error_log('VOF Debug: Removed subscriber from stage 1 group');
                } catch (\Exception $e) {
                    // Just log this error but continue processing
                    error_log('VOF Warning: Could not remove subscriber from stage 1 group - ' . $e->getMessage());
                }
            }
        
            error_log('VOF Debug: Lead processed at Stage 2 (successful checkout) for: ' . $temp_user['vof_email']);
        } catch (\Exception $e) {
            // Log the error but don't let it affect fulfillment
            error_log('VOF Error: Failed to process completed lead - ' . $e->getMessage());
        }
    }

    public function vof_update_vof_temp_user_data($uuid, $vof_updated_data) {
        // Mark temp user as completed
        $this->temp_user_meta->vof_update_post_status($uuid, $vof_updated_data);

        // Schedule cleanup of expired temp data
        // wp_schedule_single_event(
        //     time() + DAY_IN_SECONDS, 
        //     'vof_cleanup_expired_temp_data'
        // );
    }

    /**
     * Handles subscription status changes
     */
    public function vof_handle_subscription_status_change($subscription_id, $new_status, $stripe_data) {
        try {
            $user_id = $this->vof_get_user_by_subscription($subscription_id);
            if (!$user_id) {
                throw new \Exception('No user found for subscription');
            }

            $membership = new VOF_Membership($user_id, $stripe_data);
            
            // Create temporary payment for status update
            $payment = new VOF_Payment($stripe_data, [
                'true_user_id' => $user_id
            ]);

            // Update membership using VOF-specific method
            $membership->vof_update_stripe_membership($payment);

            do_action('vof_subscription_status_updated', $subscription_id, $new_status);

        } catch (\Exception $e) {
            error_log('VOF Status Change Error: ' . $e->getMessage());
        }
    }

    /**
     * Gets user ID by subscription ID
     */
    private function vof_get_user_by_subscription($subscription_id) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT user_id 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = '_vof_stripe_subscription_id' 
            AND meta_value = %s",
            $subscription_id
        ));
    }

    // ##################################################################
    // ################## CRON FULFILLMENT SECTION ######################
    // ##################################################################

    /**
     * Check if interim fulfillment is enabled
     * @return bool Whether interim fulfillment is enabled
     */
    public function vof_is_interim_fulfillment_enabled() {
        return get_option('vof_enable_interim_fulfillment', true); // Default to enabled
    }

    /**
     * Set a short-term lock to prevent excessive fulfillment checks
     */
    public function vof_set_check_lock() {
        // [PRODUCTION] Set check lock idle to 5 minutes
        // set_transient('vof_fulfillment_check_lock', true, 5 * MINUTE_IN_SECONDS);

        // [TEST ONLY] Set check lock idle to 30 seconds for testing purposes
        set_transient('vof_fulfillment_check_lock', true, 30);
    }

    /**
     * Check for pending fulfillments
     */
    public function vof_check_pending_fulfillments() {
        // Skip if a lock is in place
        if (get_transient('vof_fulfillment_check_lock')) {
            error_log('VOF Debug: Fulfillment check skipped due to lock');
            return;
        }

        error_log('VOF Debug: Starting fulfillment check at ' . current_time('mysql'));

        // Skip if interim fulfillment is disabled
        if (!$this->vof_is_interim_fulfillment_enabled()) {
            error_log('VOF Debug: Interim fulfillment is disabled');
            return;
        }

        // Process batch of pending fulfillments TODO: Rename to proces_pendind_interim_fulfillments
        $this->vof_process_pending_fulfillments();

        // Set a lock to prevent excessive checks
        do_action('vof_fulfillment_check_completed');
    }

    /**
     * Process pending interim fulfillments
     */
    private function vof_process_pending_fulfillments() {
        global $wpdb;

        try {
            // Get current time
            $current_time = current_time('mysql');

            // Get all custom meta entries with next_interim_fulfillment dates in the past
            $meta_table = $wpdb->prefix . 'vof_custom_meta';
            $pending_fulfillments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT m.uuid, m.meta_value AS next_fulfillment_date
                    FROM {$meta_table} m
                    WHERE m.meta_key = %s 
                    AND m.meta_value <= %s
                    LIMIT 10", // Process in batches to avoid timeouts
                    'next_interim_fulfillment',
                    $current_time
                ),
                ARRAY_A
            );

            if (empty($pending_fulfillments)) {
                return;
            }

            foreach ($pending_fulfillments as $fulfillment) {
                // Get subscription data
                $subscription = $this->temp_user_meta->vof_get_temp_user_by_uuid($fulfillment['uuid']);

                if (!$subscription || empty($subscription['stripe_sub_id'])) {
                    // Clean up orphaned meta if subscription doesn't exist
                    $this->temp_user_meta->vof_delete_custom_meta($fulfillment['uuid'], 'next_interim_fulfillment');
                    continue;
                }

                // Skip if subscription is not active
                if ($subscription['stripe_sub_status'] !== 'active') {
                    continue;
                }

                // Process the interim fulfillment
                $this->vof_process_interim_fulfillment($subscription);
            }

            error_log('VOF Debug: Processed ' . count($pending_fulfillments) . ' pending fulfillments');

        } catch (\Exception $e) {
            error_log('VOF Error: Failed to process pending fulfillments - ' . $e->getMessage());
        }
    }    

    /**
     * Process interim fulfillment for a subscription
     */
    private function vof_process_interim_fulfillment($subscription) {
        try {
            error_log('VOF Debug: Processing interim fulfillment for subscription: ' . print_r($subscription, true));

            // Validate subscription data
            if (empty($subscription['stripe_sub_id'])) {
                error_log('VOF Error: Missing stripe_sub_id in subscription data');
                return false;
            }

            if (empty($subscription['stripe_sub_status'])) {
                error_log('VOF Error: Missing stripe_sub_status in subscription data');
                return false;
            }

            $current_timestamp = strtotime(current_time('mysql'));

            // Process subscription benefits
            $result = $this->vof_process_customer_subscription_updated([
                'sub_id'      => $subscription['stripe_sub_id'],
                'status'      => $subscription['stripe_sub_status'],
                'expiry_date' => isset($subscription['stripe_sub_expiry_date']) ? $subscription['stripe_sub_expiry_date'] : null,
            ]);

            if (!$result) {
                error_log('VOF Warning: Interim fulfillment failed for subscription: ' . $subscription['stripe_sub_id']);
                return false;
            }

            // Update last fulfillment date
            $this->temp_user_meta->vof_update_custom_meta(
                $subscription['uuid'], 
                'last_interim_fulfillment', 
                current_time('mysql')
            );

            // [PRODUCTION] Set next fulfillment date to 30 days from now
            // $next_fulfillment = date('Y-m-d H:i:s', strtotime('+30 days'));
            // [TEST ONLY] Set next fulfillment date to 2 minutes from now
            $next_fulfillment = date('Y-m-d H:i:s', $current_timestamp + (2 * MINUTE_IN_SECONDS));

            $this->temp_user_meta->vof_update_custom_meta(
                $subscription['uuid'], 
                'next_interim_fulfillment', 
                $next_fulfillment
            );

            error_log('VOF Debug: Successfully processed interim fulfillment. Next fulfillment set for: ' . $next_fulfillment);
            return true;
        } catch (\Exception $e) {
            error_log('VOF Error: Failed to process interim fulfillment - ' . $e->getMessage());
            error_log('VOF Error: ' . $e->getTraceAsString());
            return false;
        }
    }


    /**
     * Process monthly benefits for yearly subscribers
     */
    public function vof_process_monthly_benefits() { // MAYBE NOT NEEDED ANYMORE
        global $wpdb;
        
        // Get all active yearly (or custom) subscriptions intervals
        $subscriptions = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}vof_temp_user_meta 
            WHERE stripe_sub_status = 'active' 
            AND stripe_sub_id IS NOT NULL
            AND stripe_period_interval IS NOT NULL 
            AND stripe_period_interval != 'month'",
            ARRAY_A
        );
        
        if (empty($subscriptions)) {
            error_log('VOF Debug: No yearly subscriptions found for monthly processing');
            return;
        }
        
        foreach ($subscriptions as $subscription) {
            // Calculate time until expiry
            $expiry_date        = strtotime($subscription['expiry_date']);
            $current_time       = current_time('timestamp');
            $current_time_mysql = date('Y-m-d H:i:s', $current_time);
            
            // Check if it's time for monthly fulfillment (within one month window)
            $one_month_before_expiry = strtotime('-1 month', $expiry_date);
            
            if ($current_time < $expiry_date && $current_time >= $one_month_before_expiry) {
                // Process monthly fulfillment
                $is_interim_fulfilled = $this->vof_process_customer_subscription_updated([
                    'sub_id'      => $subscription['stripe_sub_id'],
                    'status'      => $subscription['stripe_sub_status'],
                    'expiry_date' => $subscription['stripe_sub_expiry_date'],
                ]);
                
                if (!$is_interim_fulfilled) {
                    error_log('VOF Warning: Monthly benefits processing failed for subscription: '. print_r($subscription, true));
                    continue; // Skip to next subscription
                }

                // Update last interim fulfillment date on new cutom table
                $this->temp_user_meta->vof_update_custom_meta($subscription['uuid'], 'last_interim_fulfillment', $current_time_mysql);

                error_log('VOF Debug: Processed monthly benefits for yearly subscription: ' . $subscription['subscription_id']);
                error_log( 'VOF Debug: Date comparisons - ' . 
                'Current: ' . date('Y-m-d H:i:s', $current_time ) . 
                ', Expiry: ' . date('Y-m-d H:i:s', $expiry_date ) . 
                ', One Month Before: ' . date( 'Y-m-d H:i:s', $one_month_before_expiry ) );
            }
        }
    }


    private function vof_process_customer_subscription_updated($cron_job_param_array) {
        try {
            error_log('VOF Debug: Starting subscription update with params: ' . print_r($cron_job_param_array, true));
            
            $subscriptionIn = (new \RtclPro\Models\Subscriptions())->findOneBySubId($cron_job_param_array['sub_id']);
            
            if (!$subscriptionIn) {
                error_log('VOF Error: Could not find subscription via sub_id: ' . $cron_job_param_array['sub_id']);
                return false;
            }
            
            $result = $subscriptionIn->updateStatus($cron_job_param_array['status']);
            
            if (is_wp_error($result)) {
                error_log('VOF Error: Failed to update subscription status: ' . $result->get_error_message());
                return false;
            }
            
            error_log('VOF Debug: Successfully updated subscription status to: ' . $cron_job_param_array['status']);
            return true;
        } catch (\Exception $e) {
            error_log('VOF Error: Exception in subscription update: ' . $e->getMessage());
            return false;
        }
    }


    // ##################################################################
    // #################### MANUAL FULFILLMENT AREA #####################
    // ##################################################################

    /**
     * Manual fulfillment function that can be triggered to complete membership fulfillment
     * for any registered user when the normal webhook flow fails
     * 
     * @param int $user_id The user ID to fulfill membership for
     * @param array $subscription_data Subscription data including plan details
     * @return bool|WP_Error Success or failure
     */
    public function vof_manual_fulfill_membership($user_id, $subscription_data) {
        try {
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            
            error_log('VOF Debug: Starting manual membership fulfillment for user ID: ' . $user_id);
            error_log('VOF Debug: Subscription data: ' . print_r($subscription_data, true));
            
            // Validate user exists
            $user = get_user_by('ID', $user_id);
            if (!$user) {
                throw new \Exception('Invalid user ID: ' . $user_id);
            }
            
            // Validate subscription data
            if (empty($subscription_data['subscription_id'])) {
                throw new \Exception('Missing required subscription data: subscription_id');
            }
            
            // Set sensible defaults for optional fields
            $subscription_data = wp_parse_args($subscription_data, [
                'product_name' => 'Manually Restored Subscription',
                'product_id' => '',
                'amount' => 0,
                'status' => 'active',
                'current_period_end' => strtotime('+1 month'),
                'interval' => 'month',
                'lookup_key' => sanitize_title($subscription_data['product_name'] ?? 'manual-restore')
            ]);
            
            // Get or create RTCL pricing tier ID
            if (empty($subscription_data['rtcl_pricing_tier_id'])) {
                // If pricing_id wasn't provided, try to find an existing one
                $rtcl_pricing_tier_id = $this->vof_find_default_pricing_tier();
                
                if (!$rtcl_pricing_tier_id) {
                    throw new \Exception('Could not determine pricing tier - please specify a valid RTCL Pricing ID');
                }
                
                $subscription_data['rtcl_pricing_tier_id'] = $rtcl_pricing_tier_id;
            }
            
            error_log('VOF Debug: Using RTCL pricing tier ID: ' . $subscription_data['rtcl_pricing_tier_id']);
            
            // Step 1: Create or update RTCL Subscription record first
            $this->vof_create_or_update_rtcl_subscription($user_id, $subscription_data);
            
            // Step 2: Create payment and trigger membership creation/update
            $this->vof_trigger_rtcl_membership_fulfillment($user_id, $subscription_data);
            
            $wpdb->query('COMMIT');
            error_log('VOF Debug: Manual fulfillment completed successfully');
            return true;
            
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('VOF Error: Manual fulfillment failed - ' . $e->getMessage());
            error_log('VOF Error: ' . $e->getTraceAsString());
            return new \WP_Error('manual_fulfillment_failed', $e->getMessage());
        }
    }

    /**
     * Create or update RTCL Subscription record
     * 
     * @param int $user_id
     * @param array $subscription_data
     * @return bool|object The created or updated subscription
     */
    private function vof_create_or_update_rtcl_subscription($user_id, $subscription_data) {
        error_log('VOF Debug: Creating or updating RTCL subscription for user ID: ' . $user_id);

        // Find existing subscription by subscription ID
        $subscriptions = new \RtclPro\Models\Subscriptions();
        $subscription = $subscriptions->findOneBySubId($subscription_data['subscription_id']);

        // Set subscription details
        $status = isset($subscription_data['status']) ? $subscription_data['status'] : 'active';
        $amount = isset($subscription_data['amount']) ? $subscription_data['amount'] : 0;
        $expiry_at = $subscription_data['current_period_end'] 
            ? date('Y-m-d H:i:s', $subscription_data['current_period_end']) 
            : date('Y-m-d H:i:s', strtotime('+1 month'));

        // Set credit card data if available
        $cc_data = null;
        if (!empty($subscription_data['payment_method']['cc'])) {
            $cc_data = [
                'cc' => $subscription_data['payment_method']['cc']
            ];
        }

        // If not found, create a new subscription
        if (!$subscription) {
            error_log('VOF Debug: No existing subscription found, creating new one');

            // Store current user context
            $current_user = wp_get_current_user();            

            // Switch to target user context - Required for RTCL subscription creation
            wp_set_current_user($user_id);

            // Create subscription data
            $sub_data = [
                'user_id'      => $user_id,
                'name'         => $subscription_data['product_name'],
                'sub_id'       => $subscription_data['subscription_id'],
                'occurrence'   => 1,
                'gateway_id'   => 'stripe',
                'status'       => $status,
                'product_id'   => $subscription_data['rtcl_pricing_tier_id'],
                'quantity'     => 1,
                'price'        => $amount,
                'expiry_at'    => $expiry_at,
                'created_at'   => current_time('mysql'),
                'updated_at'   => current_time('mysql')
            ];

            error_log('VOF Debug: Creating subscription with data: ' . print_r($sub_data, true));

            // Create the subscription
            $subscription = $subscriptions->create($sub_data);

            // Restore original user context
            wp_set_current_user($current_user->ID);

            if (is_wp_error($subscription)) {
                error_log('VOF Error: Failed to create subscription - ' . $subscription->get_error_message());
                throw new \Exception('Failed to create subscription: ' . $subscription->get_error_message());
            }

            // Add credit card metadata if available
            if ($cc_data && $subscription) {
                $subscription->update_meta('cc', $cc_data['cc']);
            }

            error_log('VOF Debug: Successfully created subscription with ID: ' . $subscription->getId());
            return $subscription;
        } else {
            error_log('VOF Debug: Updating existing subscription: ' . print_r($subscription, true));

            // Update the subscription status
            $result = $subscription->updateStatus($status);
            if (is_wp_error($result)) {
                error_log('VOF Error: Failed to update subscription status - ' . $result->get_error_message());
            }

            // Update expiry date
            $subscription_data = [
                'expiry_date' => $expiry_at
            ];

            // Update the subscription through RTCL's API
            if (method_exists($subscription, 'update')) {
                $result = $subscription->update($subscription_data);
                if (is_wp_error($result)) {
                    error_log('VOF Error: Failed to update subscription expiry - ' . $result->get_error_message());
                }
            }

            // Add credit card metadata if available
            if ($cc_data) {
                $subscription->update_meta('cc', $cc_data['cc']);
            }

            error_log('VOF Debug: Successfully updated subscription with ID: ' . $subscription->getId());
            return $subscription;
        }
    }

    /**
     * Find a default pricing tier if none is specified
     */
    private function vof_find_default_pricing_tier() {
        global $wpdb;

        // Try to find any membership pricing tier
        $pricing_id = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'rtcl_pricing'
             AND p.post_status = 'publish'
             AND pm.meta_key = 'pricing_type'
             AND pm.meta_value = 'membership'
             ORDER BY p.ID DESC
             LIMIT 1"
        );

        return $pricing_id ? absint($pricing_id) : false;
    }

    /**
     * Trigger RTCL membership fulfillment directly by creating a payment
     * that will trigger StatusChange hooks to apply membership benefits
     * 
     * @param int $user_id The user ID
     * @param array $subscription_data Subscription and pricing data
     * @return bool Success or failure
     */
    private function vof_trigger_rtcl_membership_fulfillment($user_id, $subscription_data) {
        $pricing_id = $subscription_data['rtcl_pricing_tier_id'];
        $amount = $subscription_data['amount'] ?: 0;
        $subscription_id = $subscription_data['subscription_id'];

        error_log('VOF Debug: Creating manual fulfillment order for user ID: ' . $user_id . ', pricing ID: ' . $pricing_id);

        // Create a payment order to trigger membership benefits
        $order_args = [
            'post_title'  => esc_html__('Manual Membership Fulfillment', 'vendor-onboarding-flow') . ' ' . current_time("l jS F Y h:i:s A"),
            'post_status' => 'rtcl-created', // Important: Start as created, then complete it programmatically
            'post_parent' => '0',
            'ping_status' => 'closed',
            'post_author' => $user_id,
            'post_type'   => rtcl()->post_type_payment,
            'meta_input'  => [
                'customer_id'             => $user_id,
                '_order_key'              => apply_filters('rtcl_generate_order_key', uniqid('rtcl_order_')),
                '_pricing_id'             => $pricing_id,
                'amount'                  => $amount,
                '_payment_method'         => 'stripe',
                '_payment_method_title'   => 'Stripe',
                '_order_currency'         => \Rtcl\Helpers\Functions::get_order_currency(),
                'payment_type'            => 'membership',
                '_stripe_subscription_id' => $subscription_id,
                '_rtcl_membership_promotions' => get_post_meta($pricing_id, '_rtcl_membership_promotions', true)
            ]
        ];

        // Insert payment post
        $order_id = wp_insert_post($order_args);

        if (!$order_id || is_wp_error($order_id)) {
            error_log('VOF Error: Failed to create manual fulfillment order: ' . 
                (is_wp_error($order_id) ? $order_id->get_error_message() : 'Unknown error'));
            throw new \Exception('Failed to create manual fulfillment order');
        }

        // Get RTCL order object
        $order = rtcl()->factory->get_order($order_id);

        if (!$order) {
            error_log('VOF Error: Failed to get order object');
            throw new \Exception('Failed to get order object');
        }

        // Set transaction ID
        $transaction_id = 'manual-fulfill-' . $subscription_id . '-' . time();
        $order->set_transaction_id($transaction_id);

        // Mark as paid
        $order->set_date_paid(\Rtcl\Helpers\Functions::datetime());

        // Complete the payment which will trigger StatusChange hooks for membership fulfillment
        $result = $order->payment_complete($transaction_id);

        error_log('VOF Debug: Manual membership payment completion result: ' . ($result ? 'Success' : 'Failed'));

        if (!$result) {
            error_log('VOF Error: Payment completion failed');
            throw new \Exception('Failed to complete payment process');
        }

        // Verify the order status changed to completed
        $updated_order = rtcl()->factory->get_order($order_id);
        error_log('VOF Debug: Order status after payment completion: ' . $updated_order->get_status());

        if (!$updated_order->has_status('rtcl-completed')) {
            error_log('VOF Warning: Order did not complete successfully, current status: ' . $updated_order->get_status());
        }

        // Additional action that directly applies membership if hooks didn't work
        $this->vof_direct_apply_membership($user_id, $order);

        return true;
    }

    /**
     * Directly apply membership as a fallback if hooks don't trigger
     * 
     * @param int $user_id
     * @param \Rtcl\Models\Payment $payment
     * @return bool
     */
    private function vof_direct_apply_membership($user_id, $payment) {
        try {
            error_log('VOF Debug: Attempting direct membership application');

            // Check if the membership already exists and is applied
            $membership = rtclStore()->factory->get_membership($user_id);

            if ($membership && !$membership->is_expired() && $payment->is_applied()) {
                error_log('VOF Debug: Membership already exists and payment is applied');
                return true;
            }

            // Directly apply using RtclStore's method
            if (class_exists('\RtclStore\Helpers\Functions')) {
                \RtclStore\Helpers\Functions::apply_membership($payment);
                error_log('VOF Debug: Direct membership application completed');
                return true;
            }

            error_log('VOF Error: Could not find RtclStore Functions class');
            return false;
        } catch (\Exception $e) {
            error_log('VOF Error: Direct membership application failed - ' . $e->getMessage());
            return false;
        }
    }



 
}