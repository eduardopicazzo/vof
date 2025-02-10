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
        $this->temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
        $this->subscription_handler = VOF_Subscription_Handler::getInstance();
        
        // Hook into subscription events
        add_action('vof_subscription_created', [$this, 'vof_initiate_fulfillment'], 10, 3);
        add_action('vof_before_fulfillment', [$this, 'vof_validate_data'], 10, 2);
        add_action('vof_after_fulfillment', [$this, 'vof_cleanup_temp_data'], 10, 2);
    }

    /**
     * Main fulfillment process entry point
     */
    public function vof_initiate_fulfillment($stripe_data, $customer_id, $subscription_id) {
        try {
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            // Get temp user data
            $temp_user = $this->temp_user_meta->vof_get_temp_user_by_uuid(
                $stripe_data['metadata']['uuid']
            );

            if (!$temp_user) {
                throw new \Exception('No temporary user data found');
            }

            do_action('vof_before_fulfillment', $stripe_data, $temp_user);

            // Process membership fulfillment
            $result = $this->vof_process_fulfillment($stripe_data, $temp_user);
            
            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            do_action('vof_after_fulfillment', $stripe_data, $temp_user);

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
     * Processes the actual fulfillment with new VOF classes
     */
    private function vof_process_fulfillment($stripe_data, $temp_user) {
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