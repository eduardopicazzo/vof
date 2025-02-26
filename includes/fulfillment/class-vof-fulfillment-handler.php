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

    $price_purchased_at = $stripe_data['amount'] / 100 ?? null;
    $vof_flow_completed_at = current_time('mysql') ?? null;
    $vof_flow_time_elapsed = $vof_flow_completed_at - $vof_flow_started_at ?? null;

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
        'price_purchased_at'     => $price_purchased_at
    ];

    // Update the VOF temp user data first
    self::vof_update_vof_temp_user_data($stripe_data['uuid'], $vof_updated_data);
    
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
    // ######################### DELETE SOON ############################
    // ##################################################################
    /**
     * Validates data before fulfillment
     */
    // public function vof_validate_data_REMOVE($stripe_data, $temp_user) {
        //     if (empty($temp_user['true_user_id'])) {
        //         throw new \Exception('Invalid user ID');
        //     }

        //     if (empty($temp_user['post_id'])) {
        //         throw new \Exception('Invalid listing ID');
        //     }

        //     if (!isset($stripe_data['status']) || $stripe_data['status'] !== 'active') {
        //         throw new \Exception('Invalid subscription status');
        //     }
    // }

    /**
     * Cleanup temporary data after successful fulfillment
     */
    // public function vof_cleanup_temp_data($stripe_data, $temp_user) {
     //     // Mark temp user as completed
     //     $this->temp_user_meta->vof_update_post_status(
     //         $temp_user['uuid'], 
     //         'completed'
     //     );

     //     // Schedule cleanup of expired temp data
     //     wp_schedule_single_event(
     //         time() + DAY_IN_SECONDS, 
     //         'vof_cleanup_expired_temp_data'
     //     );
    // }
}