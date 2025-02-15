<?php
namespace VOF\Includes\Fulfillment;

use Rtcl\Helpers\Functions;
use VOF\Models\VOF_Payment;
use VOF\Models\VOF_Membership;
use VOF\Utils\Helpers\VOF_Temp_User_Meta;
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
     */
    public function vof_initiate_fulfillment($stripe_data) { // $new_sub: has protected data, $subscription_id: not used nor needed
        try {
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            error_log('VOF Debug: INITIATING FULFILLMENT with $stripe_data -> ' . print_r($stripe_data, true));

            // change this read.. or pass the uuid
            $temp_user = $this->temp_user_meta->vof_get_temp_user_by_uuid($stripe_data['uuid']);

            if (!$temp_user) {
                throw new \Exception('No temporary user data found');
            }

            // Build Fulfillment Data Structure
            $vof_fulfillment_ds = $this->vof_build_fulfillment_data_structure($stripe_data, $temp_user);
            error_log('VOF Debug: From VOF Fulfillment Handler; returning vof_fulfillment_ds with data: ' . print_r($vof_fulfillment_ds, true));

            // Create RTCL payment post first (THE ORDER ID CREATION MISSING)
            $order_id = $this->vof_create_rtcl_order_id($vof_fulfillment_ds);
        
            if (!$order_id) {
                throw new \Exception('Failed to create VOF->RTCL payment record');
            }

            // $this->vof_validate_data($stripe_data, $temp_user); // REMOVE

            // Process membership fulfillment
            // $result = $this->vof_fulfill_and_handoff_product($order_id); // NEEDS IMPLEMENT
            
            // if (is_wp_error($result)) {
            //     throw new \Exception($result->get_error_message());
            // }

            // $this->vof_cleanup_temp_data($stripe_data, $temp_user); // maybe not needed?

            $wpdb->query('COMMIT');
            return true;

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('VOF Fulfillment Error: ' . $e->getMessage());
            do_action('vof_fulfillment_failed', $stripe_data, $e->getMessage());
            throw $e;
        }
    }

    private function vof_create_rtcl_order_id($vof_fulfillment_ds) { // KEEP
        // Get RTCL objects
        $pricing = rtcl()->factory->get_pricing( $vof_fulfillment_ds['checkout_data']['pricing_id'] );
        $gateway = Functions::get_payment_gateway( $vof_fulfillment_ds['checkout_data']['payment_method'] );
        $checkout_data  = $vof_fulfillment_ds['checkout_data'];
        $new_order_args = $vof_fulfillment_ds['new_order_args'];

        // ##############

        // Validate checkout data
        $errors = new WP_Error();
        do_action('rtcl_checkout_data', $vof_fulfillment_ds['checkout_data'], $pricing, $gateway, [], $errors);
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
            error_log('VOF Error: Failed to create payment post [order_id]. ' . ($order_id instanceof WP_Error ? $order_id->get_error_message() : ''));
            return false;
        }

        // Set order key and trigger actions
        $order = rtcl()->factory->get_order($order_id);
        $order->set_order_key(); // already set in data structure... or with hook
        do_action('rtcl_checkout_process_new_payment_created', $order_id, $order); // HOOK NOT USED (!ADD_ACTION) 
        do_action('rtcl_checkout_process_success', $order, []);                    // HOOK USED (ADD_ACTION @wp-content/plugins/classified-listing/app/Controllers/Hooks/ActionHooks.php 
                                                                                   // & ADD_ACTION @wp-content/plugins/classified-listing/app/Controllers/Hooks/AppliedBothEndHooks.php)
        return $order_id;        
    }

    private function vof_build_fulfillment_data_structure($stripe_data, $temp_user) { // KEEP 
        // Format stripe amount to RTCL format
        $amount  = $stripe_data['amount'] / 100;
    
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
                'payment_type'              => 'membership',
                // VOF External Provided:
                '_stripe_customer_id'       => $stripe_data['customer'],                // Check
                '_stripe_subscription_id'   => $stripe_data['subscription_id']          // Missing in stripe data
                // Let RTCL handle these:
                    // '_applied'          => 1,
                    // 'date_paid'         => current_time('mysql'),
                    // 'date_completed'    => current_time('mysql')
                    // transaction_id
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
     * Creates RTCL payment post type record
     */
    private function vof_create_rtcl_payment_REMOVE($stripe_data, $temp_user) {
        // Format stripe amount to RTCL format
        $amount = $stripe_data['amount'] / 100;

        $meta_input = [
            'customer_id' => $temp_user['true_user_id'],                                         // CHECK
            'customer_ip_address' => Functions::get_ip_address(),                                // CHECK
            // '_order_key' => apply_filters('rtcl_generate_order_key', uniqid('vof_order_')),   // CHECK
            '_order_key' => apply_filters( 'rtcl_generate_order_key', uniqid( 'rtcl_oder_' ) ),
            '_pricing_id' => $stripe_data['metadata']['pricing_id'],                             // CHECK
            'amount' => Functions::get_payment_formatted_price($amount),                         // CHECK
            '_tax_amount' => 0.00,
            '_subtotal' => Functions::get_payment_formatted_price($amount),
            '_payment_method' => 'stripe',                                                       // CHECK
            '_payment_method_title' => 'Stripe',                                                 // CHECK
            '_order_currency' => strtoupper($stripe_data['currency']),                           // CHECK
            // '_billing_email'      => RETRIEVE FROM TEMP_USER_META                             // MISSING
            // '_billing_first_name' => RETRIEVE FROM TEMP_USER_META or ''                       // MISSING
            // '_billing_last_name'  => RETRIEVE FROM TEMP_USER_META or ''                       // MISSING
            'payment_type' => 'membership', // Critical for RTCL membership handling             // CHECK
            'transaction_id' => $stripe_data['payment_intent'],                                  // EXTRA not on initial example (postmeta)
            '_stripe_customer_id' => $stripe_data['customer'],                                   // EXTRA not on initial example (postmeta) -> CHECK ON rtcl_complete
            // 'stripe_intent_id'=>,                                                             // MISSING (appears on rtcl_complete)
            // 'stripe_charge_captured'=>,                                                       // MISSING (appears on rtcl_complete)
            // 'stripe_fee'=>,                                                                   // MISSING (appears on rtcl_complete)
            // 'stripe_net'=>,                                                                   // MISSING (appears on rtcl_complete)
            // 'stripe_currency'=>,                                                              // MISSING (appears on rtcl_complete)
            '_stripe_subscription_id' => $stripe_data['subscription'],                           // EXTRA not on initial example (postmeta)
            '_applied' => 1, // Mark as applied immediately                                      // EXTRA not on initial example (postmeta)
            'date_paid' => current_time('mysql'),                                                // EXTRA not on initial example (postmeta)
            'date_completed' => current_time('mysql')                                            // EXTRA not on initial example (postmeta)
        ];

        // Add membership promotions if any                                                      // WRONG FIXXXX!!! PROMOTIONS DON'T COME FROM STRIPE DATA
        if (!empty($stripe_data['metadata']['promotions'])) {                                    // WRONG FIXXXX!!! PROMOTIONS DON'T COME FROM STRIPE DATA
            $meta_input['_rtcl_membership_promotions'] = $stripe_data['metadata']['promotions']; // WRONG FIXXXX!!! PROMOTIONS DON'T COME FROM STRIPE DATA
        }

        $payment_args = [                                                                        // WRONG POST TITLE FIXXX!!!
            'post_author' => 1,                                                                  // CHECK
            // 'post_date' =>,                                                                   // MISSING
            // 'post_date_gmt' =>,                                                               // MISSING 
            // 'post_content' =>,                                                                // MISSING (but always blank)
            // 'post_title' => sprintf(__('Payment for Order via VOF #%s', 'vendor-onboarding-flow'), $stripe_data['payment_intent']), // WRONG VALUE FIXXX!!!
            'post_title' =>  esc_html__( 'Order on', 'classified-listing' ) . ' ' . current_time( "l jS F Y h:i:s A" ),
            // 'post_excerpt' =>                                                                 // MISSING (but always blank)
            // 'post_status' => 'rtcl-completed',                                                   // CHECK (rtcl_created at first, then rtcl_completed)
            'post_status' => 'rtcl-created',
            // 'comment_status' =>,                                                              // MISSING (but always open) on rtcl_completed
            'ping_status' => 'closed',                                                           // CHECK
            // 'post_password' =>,                                                               // MISSING (but always blank)
            // 'post_name' =>,                                                                   // MISSING
            // 'to_ping' =>,                                                                     // MISSING (but always blank)
            // 'pinged' =>,                                                                      // MISSING (but always blank)
            // 'post_modified' =>,                                                               // MISSING
            // 'post_modified_gmt' =>,                                                           // MISSING
            // 'post_content_filtered' =>,                                                       // MISSING (but always blank)
            'post_parent' => '0',                                                                // CHECK
            // 'guid' =>,                                                                        // MISSING
            // 'menu_order' =>,                                                                  // MISSING
            'post_type' => rtcl()->post_type_payment,                                            // CHECK
            // 'post_mime_type' =>,                                                              // MISSING (but always blank)
            // 'comment_count' =>,                                                               // MISSING
            'meta_input' => $meta_input                                                          // WHAT'S THIS?
        ];

        return wp_insert_post($payment_args); // maybe need to use the rtcl approach: 				$order_id = wp_insert_post( apply_filters( 'rtcl_checkout_process_new_order_args', $newOrderArgs, $pricing, $gateway, $checkout_data ) );
    }

    /**
     * Processes the actual fulfillment with new VOF classes
     */
    private function vof_process_fulfillment_REMOVE_TOO($stripe_data, $temp_user, $payment_id) { // MAYBE CAN GET AWAY WITH NATIVE RTCL's Functions
        try {
            // Create VOF Payment with payment post ID
            $payment = new VOF_Payment($stripe_data, $temp_user, $payment_id); // THIS CHANGED

            // Create VOF Membership
            $membership = new VOF_Membership(
                $temp_user['true_user_id'],
                $stripe_data
            );

            // Apply membership using VOF-specific method
            $result = $membership->vof_apply_stripe_membership($payment);
            if (!$result) {
                throw new \Exception('Failed to apply membership');
            }

            // Publish the temporary listing
            $this->vof_publish_listing($temp_user, $membership->get_user_id());

            return true;

        } catch (\Exception $e) {
            return new WP_Error(
                'fulfillment_failed',
                $e->getMessage()
            );
        }
    }

    /**
     * Processes the actual fulfillment with new VOF classes
     */
    private function vof_process_fulfillment_REMOVE($stripe_data, $temp_user) {
        try {
            // Create VOF Payment
            $payment = new VOF_Payment($stripe_data, $temp_user);

            // Create VOF Membership
            $membership = new VOF_Membership(
                $temp_user['true_user_id'],
                $stripe_data
            );

            // Apply membership using VOF-specific method
            $result = $membership->vof_apply_stripe_membership($payment);
            if (!$result) {
                throw new \Exception('Failed to apply membership');
            }

            // Publish the temporary listing
            $this->vof_publish_listing($temp_user, $membership->get_user_id());

            return true;

        } catch (\Exception $e) {
            return new WP_Error(
                'fulfillment_failed',
                $e->getMessage()
            );
        }
    }

    /**
     * Validates data before fulfillment
     */
    public function vof_validate_data_REMOVE($stripe_data, $temp_user) {
        if (empty($temp_user['true_user_id'])) {
            throw new \Exception('Invalid user ID');
        }

        if (empty($temp_user['post_id'])) {
            throw new \Exception('Invalid listing ID');
        }

        if (!isset($stripe_data['status']) || $stripe_data['status'] !== 'active') {
            throw new \Exception('Invalid subscription status');
        }
    }

    /**
     * Publishes the temporary listing
     */
    private function vof_publish_listing($temp_user, $user_id) {
        $post_data = [
            'ID' => $temp_user['post_id'],
            'post_status' => 'publish',
            'post_author' => $user_id,
            'meta_input' => [
                '_rtcl_membership_assigned' => true,
                '_rtcl_listing_owner' => $user_id,
                '_rtcl_manager_id' => $user_id,
                '_vof_subscription_id' => $temp_user['subscription_id']
            ]
        ];

        $result = wp_update_post($post_data);
        if (is_wp_error($result)) {
            throw new \Exception('Failed to publish listing: ' . $result->get_error_message());
        }

        return $result;
    }

    /**
     * Cleanup temporary data after successful fulfillment
     */
    public function vof_cleanup_temp_data($stripe_data, $temp_user) {
        // Mark temp user as completed
        $this->temp_user_meta->vof_update_post_status(
            $temp_user['uuid'], 
            'completed'
        );

        // Schedule cleanup of expired temp data
        wp_schedule_single_event(
            time() + DAY_IN_SECONDS, 
            'vof_cleanup_expired_temp_data'
        );
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
}