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
        
        // Hook into subscription events
        // add_action('vof_subscription_created', [$this, 'vof_initiate_fulfillment'], 10, 3);
        // add_action('vof_after_subscription_processed', [$this, 'vof_initiate_fulfillment'], 10, 3);
        // add_action('vof_before_fulfillment',           [$this, 'vof_validate_data'], 10, 2);
        // add_action('vof_after_fulfillment',            [$this, 'vof_cleanup_temp_data'], 10, 2);
    }

    /**
     * Main fulfillment process entry point
     */
    public function vof_initiate_fulfillment($stripe_data, $customer_id = null, $subscription_id = null) {
        try {
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            // Get temp user data
            error_log('VOF Debug: Fulfillment with stripe data-> ' . print_r($stripe_data, true));

            // change this read.. or pass the uuid
            $temp_user = $this->temp_user_meta->vof_get_temp_user_by_uuid(
                $stripe_data['metadata']['uuid']
            );

            if (!$temp_user) {
                throw new \Exception('No temporary user data found');
            }

            // Create RTCL payment post first (THE ORDER ID CREATION MISSING)
            $payment_id = $this->vof_create_rtcl_payment($stripe_data, $temp_user);
            if (!$payment_id) {
                throw new \Exception('Failed to create payment record');
            }

            // do_action('vof_before_fulfillment', $stripe_data, $temp_user);
            // $this->vof_validate_data($stripe_data, $temp_user); maybe not needed?

            // Process membership fulfillment
            $result = $this->vof_process_fulfillment($stripe_data, $temp_user, $payment_id);
            
            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            // do_action('vof_after_fulfillment', $stripe_data, $temp_user);
            $this->vof_cleanup_temp_data($stripe_data, $temp_user);

            $wpdb->query('COMMIT');
            return true;

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('VOF Fulfillment Error: ' . $e->getMessage());
            do_action('vof_fulfillment_failed', $stripe_data, $e->getMessage());
            throw $e;
        }
    }

     /**
     * Creates RTCL payment post type record
     */
    private function vof_create_rtcl_payment($stripe_data, $temp_user) {
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
    private function vof_process_fulfillment($stripe_data, $temp_user, $payment_id) { // MAYBE CAN GET AWAY WITH NATIVE RTCL's Functions
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
    private function vof_process_fulfillmentOLD($stripe_data, $temp_user) {
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
    public function vof_validate_data($stripe_data, $temp_user) {
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