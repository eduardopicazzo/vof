<?php
/**
 * Handles the synchronization between Stripe subscriptions and RTCL membership tiers.
 * 
 * This class serves as a bridge between Stripe's subscription system and RTCL's membership
 * functionality. It performs the following key operations:
 * 
 * 1. Subscription Creation & Mapping:
 *    - Matches Stripe products with RTCL membership tiers based on name and price
 *    - Creates local subscription records in RTCL tables
 *    - Maintains metadata relationships between Stripe and RTCL
 * 
 * 2. Subscription Lifecycle Management:
 *    - Handles subscription status updates (active, cancelled, etc.)
 *    - Manages subscription expiration dates
 *    - Processes subscription cancellations
 * 
 * 3. Data Synchronization:
 *    - Syncs Stripe product/price IDs with RTCL membership tiers
 *    - Updates subscription metadata for tracking purposes
 *    - Maintains consistency between Stripe and local subscription states
 */

namespace VOF\Includes\Fulfillment;

use Rtcl\Helpers\Functions;
use RtclPro\Models\Subscription;
use RtclPro\Models\Subscriptions;
use WP_Error;
use RtclPro\Helpers\Options;

class VOF_Subscription_Handler {
    private static $instance = null;
    private $table;
    private $table_meta;
    private $cache_group = 'vof_subscriptions';
    private $max_retries = 3;
    private $retry_delay = 1; // seconds

    // Status mappings between Stripe and RTCL
    private $status_map = [
        'active' => 'active',
        'past_due' => 'past_due',
        'unpaid' => 'unpaid',
        'canceled' => 'cancelled',
        'incomplete' => 'pending',
        'incomplete_expired' => 'expired',
        'trialing' => 'trialing'
    ];

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'rtcl_subscriptions';
        $this->table_meta = $wpdb->prefix . 'rtcl_subscription_meta';
        
        // Initialize caching
        wp_cache_add_global_groups([$this->cache_group]);
        
        // Add hooks for subscription lifecycle events
        add_action('vof_subscription_created', [$this, 'vof_process_subscription'], 10, 3);
        add_action('vof_subscription_cancelled', [$this, 'vof_handle_subscription_cancelled'], 10, 2);
        add_action('vof_subscription_updated', [$this, 'vof_handle_subscription_updated'], 10, 2);
    }

    /**
     * Main method to process new subscriptions
     */
    public function vof_process_subscription($stripe_data, $temp_user_id, $subscription_id) {
        global $wpdb;
        
        try {
            // Start transaction
            $wpdb->query('START TRANSACTION');

            // Validate input data
            $this->vof_validate_subscription_data($stripe_data);

            // Check cache to prevent duplicate processing
            $cache_key = "vof_sub_{$subscription_id}";
            if (wp_cache_get($cache_key, $this->cache_group)) {
                throw new \Exception('Subscription already processed');
            }

            // Find matching RTCL membership tier
            $rtcl_membership_tier_id = $this->vof_find_matching_rtcl_membership_tier_with_retry($stripe_data);

            // Create subscription record
            $subscription_data = [
                'user_id' => $temp_user_id,
                'name' => $stripe_data['product_name'],
                'sub_id' => $subscription_id,
                'occurrence' => 1,
                'gateway_id' => 'stripe',
                'status' => $this->vof_map_stripe_status_to_rtcl($stripe_data['status']),
                'product_id' => $rtcl_membership_tier_id,
                'quantity' => 1,
                'price' => $stripe_data['amount'],
                'meta' => null,
                'expiry_at' => date('Y-m-d H:i:s', $stripe_data['current_period_end']),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];

            // Create subscription using RTCL's model
            $subscriptions = new Subscriptions();
            $new_sub = $subscriptions->create($subscription_data);

            if (is_wp_error($new_sub)) {
                throw new \Exception($new_sub->get_error_message());
            }

            // Add subscription meta
            $this->vof_add_subscription_meta($new_sub, [
                'cc_data' => [
                    'type' => 'card',
                    'last4' => $stripe_data['payment_method']['card']['last4'],
                    'expiry' => $stripe_data['payment_method']['card']['exp_month'] . '/' . 
                               $stripe_data['payment_method']['card']['exp_year']
                ],
                'stripe_customer_id' => $stripe_data['customer'],
                'stripe_subscription_id' => $subscription_id,
                'stripe_price_id' => $stripe_data['price_id'],
                'stripe_product_id' => $stripe_data['product_id']
            ]);

            // Cache subscription data
            $this->vof_cache_subscription_data($subscription_id, $subscription_data);

            // Commit transaction
            $wpdb->query('COMMIT');

            do_action('vof_after_subscription_processed', $new_sub, $stripe_data);

            return $new_sub;

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('VOF Subscription Error: ' . $e->getMessage());
            
            // Cleanup any partial data
            if (!empty($new_sub)) {
                $this->vof_cleanup_failed_subscription($new_sub->getId());
            }
            
            throw $e;
        }
    }

    /**
     * Find matching RTCL membership tier with retry logic
     */
    private function vof_find_matching_rtcl_membership_tier_with_retry($stripe_data) {
        $attempt = 1;
        $last_error = null;

        while ($attempt <= $this->max_retries) {
            try {
                return $this->vof_find_matching_rtcl_membership_tier($stripe_data);
            } catch (\Exception $e) {
                $last_error = $e;
                if ($attempt === $this->max_retries) break;
                sleep($this->retry_delay);
                $attempt++;
            }
        }

        throw new \Exception(
            'Failed to find matching membership tier after ' . $this->max_retries . ' attempts: ' . 
            $last_error->getMessage()
        );
    }

    /**
     * Find matching RTCL membership tier
     */
    private function vof_find_matching_rtcl_membership_tier($stripe_data) {
        global $wpdb;
        
        // First look for published membership tiers with matching names
        $title_query = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'rtcl_pricing' 
            AND post_status = 'publish' 
            AND ID IN (
                SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_pricing_type' 
                AND meta_value = 'membership'
            )
            AND (
                post_title LIKE %s 
                OR post_name LIKE %s
            )",
            '%' . $wpdb->esc_like($stripe_data['product_name']) . '%',
            '%' . $wpdb->esc_like($stripe_data['product_name']) . '%'
        );
    
        $id_via_title_matches = $wpdb->get_col($title_query);
    
        // Look for membership tiers with matching prices
        $price_query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_price' 
            AND meta_value = %f 
            AND post_id IN (
                SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'rtcl_pricing' 
                AND post_status = 'publish'
                AND post_id IN (
                    SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_pricing_type' 
                    AND meta_value = 'membership'
                )
            )",
            $stripe_data['amount'] / 100
        );
    
        $id_via_price_matches = $wpdb->get_col($price_query);
    
        // Find the common IDs between both matches
        $rtcl_membership_id = array_intersect($id_via_title_matches, $id_via_price_matches);
    
        if (count($rtcl_membership_id) === 1) {
            $this->vof_sync_stripe_data(reset($rtcl_membership_id), $stripe_data);
            return reset($rtcl_membership_id);
        }

        throw new \Exception(
            sprintf(
                'Found %d matching membership tiers for Stripe product "%s" with price %.2f',
                count($rtcl_membership_id),
                $stripe_data['product_name'],
                $stripe_data['amount'] / 100
            )
        );
    }

    /**
     * Sync Stripe data with RTCL membership tier
     */
    private function vof_sync_stripe_data($rtcl_membership_tier_id, $stripe_data) {
        // Only set Stripe IDs if they haven't been set before
        if (!get_post_meta($rtcl_membership_tier_id, '_stripe_product_id', true)) {
            update_post_meta($rtcl_membership_tier_id, '_stripe_product_id', $stripe_data['product_id']);
        }
    
        if (!get_post_meta($rtcl_membership_tier_id, '_stripe_price_id', true)) {
            update_post_meta($rtcl_membership_tier_id, '_stripe_price_id', $stripe_data['price_id']);
        }
    
        // Ensure pricing type is set to membership
        update_post_meta($rtcl_membership_tier_id, '_pricing_type', 'membership');
    }

    /**
     * Add subscription meta
     */
    private function vof_add_subscription_meta($subscription, $meta_data) {
        foreach ($meta_data as $key => $value) {
            $subscription->update_meta($key, $value);
        }
    }

    /**
     * Cache subscription data
     */
    private function vof_cache_subscription_data($stripe_sub_id, $data) {
        $cache_key = "vof_sub_{$stripe_sub_id}";
        wp_cache_set($cache_key, $data, $this->cache_group, HOUR_IN_SECONDS);
    }

    /**
     * Validate subscription data
     */
    private function vof_validate_subscription_data($data) {
        $required = [
            'product_name' => 'Product name is required',
            'amount' => 'Amount is required',
            'customer' => 'Customer ID is required',
            'status' => 'Status is required',
            'current_period_end' => 'Period end is required'
        ];

        foreach ($required as $field => $message) {
            if (empty($data[$field])) {
                throw new \Exception($message);
            }
        }
    }

    /**
     * Map Stripe status to RTCL status
     */
    private function vof_map_stripe_status_to_rtcl($stripe_status) {
        return isset($this->status_map[$stripe_status]) 
            ? $this->status_map[$stripe_status] 
            : 'pending';
    }

    /**
     * Handle subscription cancellation
     */
    public function vof_handle_subscription_cancelled($subscription_id, $stripe_event) {
        global $wpdb;
        
        $wpdb->update(
            $this->table, 
            [
                'status' => 'cancelled',
                'updated_at' => current_time('mysql')
            ],
            ['sub_id' => $subscription_id]
        );

        do_action('vof_after_subscription_cancelled', $subscription_id, $stripe_event);
    }

    /**
     * Handle subscription updates
     */
    public function vof_handle_subscription_updated($subscription_id, $stripe_event) {
        global $wpdb;
        
        $subscription = $stripe_event->data->object;
        
        $wpdb->update(
            $this->table, 
            [
                'status' => $this->vof_map_stripe_status_to_rtcl($subscription->status),
                'expiry_at' => date('Y-m-d H:i:s', $subscription->current_period_end),
                'updated_at' => current_time('mysql')
            ],
            ['sub_id' => $subscription_id]
        );

        do_action('vof_after_subscription_updated', $subscription_id, $stripe_event);
    }

    /**
     * Cleanup failed subscription
     */
    private function vof_cleanup_failed_subscription($subscription_id) {
        global $wpdb;
        
        $wpdb->delete($this->table, ['id' => $subscription_id]);
        $wpdb->delete($this->table_meta, ['subscription_id' => $subscription_id]);
        
        // Clear cache
        $cache_key = "vof_sub_{$subscription_id}";
        wp_cache_delete($cache_key, $this->cache_group);
    }

    /**
     * Helper methods for accessing subscription data
     */
    public function vof_get_subscription_by_stripe_id($stripe_subscription_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE sub_id = %s",
            $stripe_subscription_id
        ));
    }

    public function vof_get_subscription_meta($subscription_id, $meta_key) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$this->table_meta} 
            WHERE subscription_id = %d AND meta_key = %s",
            $subscription_id, $meta_key
        ));
    }
}