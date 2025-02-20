<?php

// path: wp-content/plugins/vendor-onboarding-flow/includes/fulfillment/class-vof-subscription-handler.php

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
        'active'             => 'active',
        'past_due'           => 'past_due',
        'unpaid'             => 'unpaid',
        'canceled'           => 'cancelled',
        'incomplete'         => 'pending',
        'incomplete_expired' => 'expired',
        'trialing'           => 'trialing'
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

        // Verify RTCL classes exist
        if (!class_exists('RtclPro\Models\Subscriptions')) {
            error_log('VOF Error: RTCL Subscriptions class not found');
        }
        
        // Initialize caching
        wp_cache_add_global_groups([$this->cache_group]);
        
        // Add hooks for subscription lifecycle events
        // add_action('vof_subscription_created', [$this, 'vof_process_subscription'], 10, 3);
        add_action('vof_subscription_cancelled', [$this, 'vof_handle_subscription_cancelled'], 10, 2);
        add_action('vof_subscription_updated', [$this, 'vof_handle_subscription_updated'], 10, 2);
    }

    public function vof_process_subscription($stripe_data) {
        global $wpdb;
        
        try {
            error_log('VOF Debug: Starting subscription processing for ID: ' . $stripe_data['subscription_id']);

            // Validate user exists
            if (!get_user_by('ID', $stripe_data['wp_user_id'])) {
                throw new \Exception('Invalid user ID: ' . $stripe_data['wp_user_id']);
            }            
    
            // First check if subscription already exists
            $existing_subscription = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$this->table} WHERE sub_id = %s",
                $stripe_data['subscription_id']
            ));
    
            if ($existing_subscription) {
                error_log("VOF Debug: Subscription {$stripe_data['subscription_id']} already exists - skipping creation");
                return $existing_subscription;
            }
    
            // START TRANSACTION
            $wpdb->query('START TRANSACTION');
    
            // Double-check within transaction to prevent race conditions
            $existing_subscription = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE sub_id = %s FOR UPDATE",
                $stripe_data['subscription_id']
            ));
    
            if ($existing_subscription) {
                $wpdb->query('COMMIT');
                error_log("VOF Debug: Subscription {$stripe_data['subscription_id']} already exists (caught in transaction) - skipping creation");
                return $existing_subscription;
            }
    
            // Validate input data
            $this->vof_validate_subscription_data($stripe_data);
    
            // Find matching RTCL membership tier
            $rtcl_membership_tier_id = $this->vof_find_matching_rtcl_membership_tier_with_retry($stripe_data);

            // Store current user context
            $current_user = wp_get_current_user();            

            // Switch to target user context - Required for RTCL subscription creation
            wp_set_current_user($stripe_data['wp_user_id']);            
    
            // Create subscription data
            $subscription_data = [
                'user_id'      => $stripe_data['wp_user_id'],
                'name'         => $stripe_data['product_name'],
                'sub_id'       => $stripe_data['subscription_id'],
                'occurrence'   => 1,
                'gateway_id'   => 'stripe',
                'status'       => $this->vof_map_stripe_status_to_rtcl($stripe_data['status']),
                'product_id'   => $rtcl_membership_tier_id,
                'quantity'     => 1,
                'price'        => (float) ($stripe_data['amount']/100 == floor($stripe_data['amount']/100) ? 
                                         number_format($stripe_data['amount']/100, 0, '.', '') : 
                                         number_format($stripe_data['amount']/100, 2, '.', '')),
                'meta'         => null,
                'expiry_at'    => date('Y-m-d H:i:s', $stripe_data['current_period_end']),
                'created_at'   => current_time('mysql'),
                'updated_at'   => current_time('mysql')
            ];
    
            error_log('VOF Debug: Subscription data before creation: ' . print_r($subscription_data, true));
            error_log('VOF Debug: Before subscription creation - Current user ID: ' . get_current_user_id());
            error_log('VOF Debug: Attempting to create subscription for user ID: ' . $stripe_data['wp_user_id']);
            
            // Create subscription using RTCL's model
            $subscriptions = new Subscriptions();
            $new_sub = $subscriptions->create($subscription_data);

            // Restore original user context
            wp_set_current_user($current_user->ID);        
    
            if (!$new_sub) {
                throw new \Exception('Failed to create subscription - RTCL returned null');
            }
    
            if (is_wp_error($new_sub)) {
                throw new \Exception($new_sub->get_error_message());
            }
    
            error_log('VOF Debug: Subscription created successfully: ' . print_r($new_sub, true));
    
            // Add subscription meta with with credit card info if available
            if (!empty($stripe_data['payment_method']['cc'])) {
                // Format credit card meta consistently
                $cc_data = [
                    'type'   => "card", // Always "card" for card payments
                    'last4'  => (string)$stripe_data['payment_method']['cc']['last4'], // Cast to string
                    'expiry' => sprintf(
                        '%d/%d',  // Format: month/year without leading zeros
                        (int)$stripe_data['payment_method']['cc']['exp_month'],
                        (int)$stripe_data['payment_method']['cc']['exp_year']
                    )
                ];

                if (!method_exists($new_sub, 'update_meta')) {
                    throw new \Exception('Subscription object does not have update_meta method');
                }                
                
                // Log the data before saving
                error_log('VOF Debug: CC Data to be saved: ' . print_r($cc_data, true));
                
                // Update meta
                $this->vof_add_subscription_meta($new_sub, ['cc' => $cc_data]);
            }
    
            // Cache subscription data
            $this->vof_cache_subscription_data($subscription_data);
    
            // Commit transaction
            $wpdb->query('COMMIT');
            error_log('VOF Debug: Transaction committed successfully');

            try { // Start Fulfillment Handling and hand-off

                $vof_fulfillment_handler = \VOF\Includes\Fulfillment\VOF_Fulfillment_Handler::getInstance();
                
                // Create a copy of stripe_data with the additional product_id
                $stripe_data_merged = array_merge($stripe_data, ['rtcl_pricing_tier_id' => $subscription_data['product_id']]);
                error_log('VOF Debug: About to fulfill membership with $stripe_data_merged data: ' . print_r($stripe_data_merged, true));

                $vof_fulfillment_handler->vof_initiate_fulfillment($stripe_data_merged);

            } catch(\Exception $e) {
                error_log('VOF Debug: Could not process membership fulfillment - ' . $e->getMessage());
                // Don't throw here - we want the subscription creation to succeed even if fulfillment fails
            }

            return $new_sub;
    
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');

            // Ensure we restore the original user context even if an error occurs
            if (isset($current_user)) {
                wp_set_current_user($current_user->ID);
            }            

            error_log('VOF Error: Failed to process subscription - ' . $e->getMessage());
            error_log('VOF Error: Stack trace - ' . $e->getTraceAsString());
            
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

    private function vof_find_matching_rtcl_membership_tier($stripe_data) {
        global $wpdb;
        
        // Clean and normalize the strings
        $product_name = preg_replace('/\s+/', ' ', trim($stripe_data['product_name']));
        $lookup_key = preg_replace('/\s+/', ' ', trim($stripe_data['lookup_key']));
        
        error_log('VOF Debug: Searching for membership tier - Product Name: [' . $product_name . '], Lookup Key: [' . $lookup_key . '], Price: ' . ($stripe_data['amount'] / 100));
    
        // First query: Find by exact title/name match and membership type
        $title_query = $wpdb->prepare(
            "SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_type ON (p.ID = pm_type.post_id)
            WHERE p.post_type = 'rtcl_pricing'
            AND p.post_status = 'publish'
            AND pm_type.meta_key = 'pricing_type'
            AND pm_type.meta_value = 'membership'
            AND (
                BINARY p.post_title = %s
                OR BINARY p.post_name = %s
            )",
            $product_name,
            $lookup_key
        );
    
        // Debug query results
        $debug_query = $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_name, p.post_status,
                    pm_type.meta_key as type_key, pm_type.meta_value as type_value,
                    pm_price.meta_key as price_key, pm_price.meta_value as price_value
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_type ON (p.ID = pm_type.post_id AND pm_type.meta_key = 'pricing_type')
            LEFT JOIN {$wpdb->postmeta} pm_price ON (p.ID = pm_price.post_id AND pm_price.meta_key = 'price')
            WHERE p.post_type = 'rtcl_pricing'
            AND p.post_status = 'publish'"
        );
    
        $debug_results = $wpdb->get_results($debug_query);
        error_log('VOF Debug: Database values for rtcl_pricing posts: ' . json_encode($debug_results));
    
        $id_via_title_matches = $wpdb->get_col($title_query);
        error_log('VOF Debug: Title matches found: ' . json_encode($id_via_title_matches));
        error_log('VOF Debug: Title query: ' . $wpdb->last_query);
    
        // Second query: Find by exact price match
        $price_query = $wpdb->prepare(
            "SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_type ON (p.ID = pm_type.post_id)
            INNER JOIN {$wpdb->postmeta} pm_price ON (p.ID = pm_price.post_id)
            WHERE p.post_type = 'rtcl_pricing'
            AND p.post_status = 'publish'
            AND pm_type.meta_key = 'pricing_type'
            AND pm_type.meta_value = 'membership'
            AND pm_price.meta_key = 'price'
            AND CAST(pm_price.meta_value AS DECIMAL(10,2)) = %f",
            $stripe_data['amount'] / 100
        );
    
        $id_via_price_matches = $wpdb->get_col($price_query);
        error_log('VOF Debug: Price matches found: ' . json_encode($id_via_price_matches));
        error_log('VOF Debug: Price query: ' . $wpdb->last_query);
    
        // Find intersection of matches
        $rtcl_membership_id = array_intersect($id_via_title_matches, $id_via_price_matches);
        error_log('VOF Debug: Intersection matches found: ' . json_encode($rtcl_membership_id));
    
        if (count($rtcl_membership_id) === 1) {
            $matched_id = reset($rtcl_membership_id);
            error_log('VOF Debug: Found matching membership tier ID: ' . $matched_id);
            $this->vof_sync_stripe_data($matched_id, $stripe_data);
            return $matched_id;
        }
    
        $error_msg = sprintf(
            'Found %d matching membership tiers for Stripe product "%s" with price %.2f',
            count($rtcl_membership_id),
            $product_name,
            $stripe_data['amount'] / 100
        );
        error_log('VOF Debug: Error - ' . $error_msg);
        throw new \Exception($error_msg);
    }

    /**
     * Sync Stripe data with RTCL membership tier
     */
    private function vof_sync_stripe_data($rtcl_membership_tier_id, $stripe_data) {
        error_log('VOF: Starting sync for RTCL membership tier ID: ' . $rtcl_membership_tier_id);

        // Check existing product ID
        $existing_product_id = get_post_meta($rtcl_membership_tier_id, '_stripe_product_id', true);
        error_log('VOF: Existing Stripe product ID: ' . ($existing_product_id ?: 'none'));

        // Check existing price ID
        $existing_price_id = get_post_meta($rtcl_membership_tier_id, '_stripe_price_id', true);
        error_log('VOF: Existing Stripe price ID: ' . ($existing_price_id ?: 'none'));

        // Only set Stripe IDs if they haven't been set before
        if (!$existing_product_id) {
            update_post_meta($rtcl_membership_tier_id, '_stripe_product_id', $stripe_data['product_id']);
            error_log('VOF: Set new Stripe product ID: ' . $stripe_data['product_id']);
        }
    
        if (!$existing_price_id) {
            update_post_meta($rtcl_membership_tier_id, '_stripe_price_id', $stripe_data['price_id']);
            error_log('VOF: Set new Stripe price ID: ' . $stripe_data['price_id']);
        }
    
        // Ensure pricing type is set to membership
        // update_post_meta($rtcl_membership_tier_id, '_pricing_type', 'membership');
        error_log('VOF: Confirmed pricing type is set to membership');
    }

    private function vof_add_subscription_meta($subscription, $meta_data) {
        if (isset($meta_data['cc'])) {
            // Validate credit card data format
            $cc_meta = [
                'type' => 'card',  // Ensure type is always "card"
                'last4' => (string)($meta_data['cc']['last4'] ?? ''), // Ensure last4 is a string
                'expiry' => $meta_data['cc']['expiry'] ?? ''  // Keep expiry format from sprintf above
            ];
    
            // Remove any empty values
            $cc_meta = array_filter($cc_meta, function($value) {
                return $value !== '' && $value !== null;
            });
    
            // Log before final save
            error_log('VOF Debug: Final CC meta being saved: ' . print_r($cc_meta, true));
    
            // Save to database
            $subscription->update_meta('cc', $cc_meta);
        }
    }

    /**
     * Cache subscription data
     */
    private function vof_cache_subscription_data($data) {
        $cache_key = "vof_sub_{$data['sub_id']}";
        wp_cache_set($cache_key, $data, $this->cache_group, HOUR_IN_SECONDS);
    }

    /**
     * Validate subscription data
     */
    private function vof_validate_subscription_data($data) {
        $required = [
            'product_name'       => 'Product name is required',
            'product_id'         => 'Product ID is required',
            'price_id'           => 'Price ID is required',
            'amount'             => 'Amount is required',
            'customer'           => 'Customer ID is required',
            'status'             => 'Status is required',
            'current_period_end' => 'Period end is required'
        ];
    
        foreach ($required as $field => $message) {
            if (empty($data[$field])) {
                error_log('VOF: Missing required field: ' . $field);
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