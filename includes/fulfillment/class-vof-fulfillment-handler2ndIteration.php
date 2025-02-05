<?php
namespace VOF\Includes\Fulfillment;

use Rtcl\Helpers\Functions;
use Rtcl\Models\Payment;
use RtclStore\Models\Membership;
use WP_Error;
use VOF\Utils\Helpers\VOF_Temp_User_Meta;

class VOF_Fulfillment_Handler {
    private static $instance = null;
    private $temp_user_meta;
    private $subscription_handler;
    private $log_table;
    private $max_retries = 3;
    private $retry_delay = 1; // seconds

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->temp_user_meta       = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
        $this->subscription_handler = VOF_Subscription_Handler::getInstance();
        $this->log_table            = $wpdb->prefix . 'rtcl_posting_log';
        
        // Hook into payment, subscription and membership actions
        add_action('vof_subscription_created',         [$this, 'vof_handle_subscription_creation'], 10, 3);
        add_action('vof_payment_success',              [$this, 'vof_process_fulfillment'], 10, 2);
        add_action('vof_before_membership_grant',      [$this, 'vof_validate_membership_data'], 10, 2);
        add_action('vof_after_membership_grant',       [$this, 'vof_update_user_capabilities'], 10, 2);
        add_action('vof_membership_fulfillment_error', [$this, 'vof_handle_fulfillment_error'], 10, 3);
    }

    /**
     * Handle new subscription creation
     */
    public function vof_handle_subscription_creation($subscription_data, $user_id, $subscription_id) {
        try {
            // Get temp data
            $uuid = $subscription_data['metadata']['uuid'] ?? null;
            if (!$uuid) {
                throw new \Exception('Missing UUID in subscription data');
            }

            $temp_data = $this->temp_user_meta->vof_get_temp_user_by_uuid($uuid);
            if (!$temp_data) {
                throw new \Exception('No temporary data found for UUID: ' . $uuid);
            }

            // Process fulfillment with subscription data
            $this->vof_process_fulfillment($temp_data['post_id'], [
                'subscription_id' => $subscription_id,
                'user_id'         => $user_id,
                'pricing_tier'    => $temp_data['vof_tier'],
                'category_id'     => $temp_data['post_parent_cat'],
                'amount'          => $subscription_data['amount'],
                'customer'        => $subscription_data['customer'],
                'price_id'        => $subscription_data['price_id']
            ]);

        } catch (\Exception $e) {
            error_log('VOF Error: Subscription creation handling failed - ' . $e->getMessage());
            do_action('vof_subscription_fulfillment_error', $subscription_id, $e);
        }
    }

    /**
     * Main fulfillment processing method
     */
    public function vof_process_fulfillment($order_id, $payment_data) {
        try {
            global $wpdb;
            
            error_log('VOF Debug: Starting fulfillment process for order ' . $order_id);
            
            // Start transaction
            $wpdb->query('START TRANSACTION');

            // Get temporary listing
            $temp_listing = $this->vof_get_temp_listing($order_id);
            if (!$temp_listing) {
                throw new \Exception('No temporary listing found for order ' . $order_id);
            }

            // Validate subscription sync
            $subscription = $this->vof_validate_subscription_sync($payment_data);

            // Create RTCL payment record with retry logic
            $payment = $this->vof_create_rtcl_payment_with_retry($order_id, $payment_data);
            if (!$payment) {
                throw new \Exception('Failed to create RTCL payment for order ' . $order_id);
            }

            // Pre-fulfillment validation
            do_action('vof_before_membership_grant', $payment, $temp_listing);

            // Mark payment as completed to trigger RTCL's native fulfillment
            $payment->payment_completed();
            do_action('rtcl_membership_order_completed', $payment);

            // Post-fulfillment tasks
            $this->vof_update_user_capabilities($payment->get_user_id(), $payment);
            $this->vof_publish_listing($temp_listing, $payment->get_user_id());
            
            // Cleanup temporary data
            $this->vof_cleanup_temp_data($temp_listing->ID);

            // Commit transaction
            $wpdb->query('COMMIT');
            
            do_action('vof_fulfillment_completed', $order_id, $payment);
            error_log('VOF Debug: Fulfillment completed successfully for order ' . $order_id);

            return true;

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('VOF Error: Fulfillment failed - ' . $e->getMessage());
            
            do_action('vof_membership_fulfillment_error', $order_id, $payment_data, $e);
            
            return new WP_Error('fulfillment_failed', $e->getMessage());
        }
    }

    /**
     * Create RTCL payment record with retry logic
     */
    private function vof_create_rtcl_payment_with_retry($order_id, $payment_data) {
        $attempt = 1;
        $last_error = null;

        while ($attempt <= $this->max_retries) {
            try {
                $payment = $this->vof_create_rtcl_payment($order_id, $payment_data);
                if ($payment) {
                    return $payment;
                }
            } catch (\Exception $e) {
                $last_error = $e;
                error_log("VOF Debug: Payment creation attempt {$attempt} failed - {$e->getMessage()}");
                
                if ($attempt === $this->max_retries) break;
                sleep($this->retry_delay * $attempt);
            }
            $attempt++;
        }

        throw new \Exception(
            'Failed to create payment after ' . $this->max_retries . ' attempts: ' . 
            $last_error->getMessage()
        );
    }

    /**
     * Create RTCL payment record
     */
    private function vof_create_rtcl_payment($order_id, $payment_data) {
        $pricing_id = $this->vof_get_pricing_id($payment_data);
        if (!$pricing_id) {
            throw new \Exception('Invalid pricing ID for payment');
        }
        
        $payment_args = [
            'post_title'  => sprintf(
                esc_html__('Payment for Order #%s', 'vof'),
                $order_id
            ),
            'post_status' => 'rtcl-completed',
            'post_type'   => rtcl()->post_type_payment,
            'meta_input'  => [
                '_payment_type' => 'membership',
                '_pricing_id'   => $pricing_id,
                '_order_key'    => uniqid('rtcl_order_'),
                '_price'        => $payment_data['amount'],
                '_gateway'      => 'stripe',
                '_stripe_customer_id' => $payment_data['customer'],
                '_stripe_subscription_id' => $payment_data['subscription_id'],
                'vof_order_id'  => $order_id
            ]
        ];

        $payment_id = wp_insert_post($payment_args);
        if (is_wp_error($payment_id)) {
            throw new \Exception($payment_id->get_error_message());
        }

        return rtcl()->factory->get_order($payment_id);
    }

    /**
     * Get pricing ID from payment data
     */
    private function vof_get_pricing_id($payment_data) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_stripe_price_id' 
            AND meta_value = %s
            LIMIT 1",
            $payment_data['price_id']
        ));
    }

    /**
     * Get temporary listing data
     */
    private function vof_get_temp_listing($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'vof_temp_listings';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id = %d",
            $order_id
        ));
    }

    /**
     * Create posting log entry
     */
    private function vof_create_posting_log($post_id, $user_id, $cat_id, $status = 'new') {
        global $wpdb;

        $result = $wpdb->insert(
            $this->log_table,
            [
                'post_id' => $post_id,
                'user_id' => $user_id,
                'cat_id' => $cat_id,
                'status' => $status,
                'created_at' => current_time('mysql')
            ],
            ['%d', '%d', '%d', '%s', '%s']
        );

        if ($result === false) {
            throw new \Exception('Failed to create posting log entry');
        }

        return $result;
    }

    /**
     * Modified publish_listing method to handle posting log
     */
    private function vof_publish_listing($temp_listing, $user_id) {
        global $wpdb;
        
        $wpdb->query('START TRANSACTION');
        
        try {
            // Basic listing publication
            $post_data = [
                'ID' => $temp_listing->post_id,
                'post_status' => 'publish',
                'post_author' => $user_id
            ];

            $result = wp_update_post($post_data);
            if (is_wp_error($result)) {
                throw new \Exception('Failed to publish listing: ' . $result->get_error_message());
            }

            // Get category ID from temporary data
            $category_id = $this->temp_user_meta->vof_get_parent_cat_by_uuid($temp_listing->uuid);
            if (!$category_id) {
                throw new \Exception('Missing category ID for listing');
            }

            // Create posting log entry
            $this->vof_create_posting_log(
                $temp_listing->post_id,
                $user_id,
                $category_id
            );

            // Update membership post count if needed
            $membership = rtclStore()->factory->get_membership($user_id);
            if ($membership && !$membership->is_expired()) {
                $membership->update_post_count();
            }

            // Update meta
            update_post_meta($temp_listing->post_id, '_rtcl_membership_assigned', true);
            update_post_meta($temp_listing->post_id, '_vof_processed_at', current_time('mysql'));

            $wpdb->query('COMMIT');

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Validate membership data before granting
     */
    public function vof_validate_membership_data($payment, $temp_listing) {
        $pricing_id = get_post_meta($payment->get_id(), '_pricing_id', true);
        
        if (!$pricing_id || get_post_type($pricing_id) !== 'rtcl_pricing') {
            throw new \Exception('Invalid pricing plan');
        }

        if (!get_user_by('ID', $payment->get_user_id())) {
            throw new \Exception('Invalid user');
        }

        // Validate listing category against membership tier
        $category_id = wp_get_post_terms($temp_listing->post_id, 'rtcl_category', ['fields' => 'ids']);
        if (is_wp_error($category_id)) {
            throw new \Exception('Failed to validate listing category');
        }

        $allowed_categories = get_post_meta($pricing_id, 'membership_categories', true);
        if (!empty($allowed_categories) && !in_array($category_id[0], $allowed_categories)) {
            throw new \Exception('Category not allowed for this membership tier');
        }
    }

    /**
     * Update user capabilities after membership grant
     */
    public function vof_update_user_capabilities($user_id, $payment) {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            throw new \Exception('Invalid user ID: ' . $user_id);
        }

        // Add vendor role and update meta
        $user->add_role('vendor');
        update_user_meta($user_id, '_rtcl_membership_id', $payment->get_id());
        update_user_meta($user_id, '_rtcl_subscription_active', true);
        update_user_meta($user_id, '_vof_last_fulfillment', current_time('mysql'));
    }

    /**
     * Validate and sync subscription data
     */
    private function vof_validate_subscription_sync($payment_data) {
        // Get subscription from VOF_Subscription_Handler
        $subscription = $this->subscription_handler->vof_get_subscription_by_stripe_id(
            $payment_data['subscription_id']
        );

        if (!$subscription) {
            throw new \Exception('No subscription found for payment');
        }

        // Validate subscription status
        if ($subscription->status !== 'active') {
            throw new \Exception('Subscription is not active');
        }

        return $subscription;
    }

    /**
     * Handle fulfillment errors
     */
    public function vof_handle_fulfillment_error($order_id, $payment_data, $error) {
        error_log('VOF Error: Fulfillment failed for order ' . $order_id);
        error_log('VOF Error details: ' . $error->getMessage());
        
        // Notify admin
        $admin_email = get_option('admin_email');
        wp_mail(
            $admin_email,
            'VOF Fulfillment Error - Order #' . $order_id,
            'Error: ' . $error->getMessage() . "\n\nOrder details: " . print_r($payment_data, true)
        );
    }

    /**
     * Clean up temporary data
     */
    private function vof_cleanup_temp_data($temp_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'vof_temp_listings';
        
        $result = $wpdb->delete($table, ['ID' => $temp_id]);
        if ($result === false) {
            error_log('VOF Warning: Failed to cleanup temporary data for ID: ' . $temp_id);
        }

        // Clear any associated transients
        delete_transient('vof_temp_listing_' . $temp_id);
    }
}