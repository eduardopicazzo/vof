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
 * 
 * Uses WordPress's wpdb for database operations and integrates with both
 * RTCL's subscription models and Stripe's webhook events.
 * 
 * @package VOF\Includes\Fulfillment
 * @since 1.0.0
 */

namespace VOF\Includes\Fulfillment;

use Rtcl\Helpers\Functions;
use RtclPro\Models\Subscription;
use RtclPro\Models\Subscriptions;
use WP_Error;
use RtclPro\Helpers\Options;
use VOF\Utils\Helpers\VOF_Temp_User_Meta;

class VOF_Subscription_Handler {
    private static $instance = null;
    private $table;
    private $table_meta;
    private $temp_user_meta;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'rtcl_subscriptions'; // should it indirectly (with stripe helper methods) write the data instead???
        $this->table_meta = $wpdb->prefix . 'rtcl_subscription_meta'; // should it inderectly (with stripe helper methods) write the data instead???
        // should instatiate using static method due to singleton pattern
        $this->temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
        
        add_action('vof_subscription_created', [$this, 'vof_process_subscription'], 10, 3);
        add_action('vof_subscription_cancelled', [$this, 'vof_handle_subscription_cancelled'], 10, 2);
        add_action('vof_subscription_updated', [$this, 'vof_handle_subscription_updated'], 10, 2);
    }

    public function vof_process_subscription($stripe_data, $temp_user_id, $subscription_id) {
        // 1. Find matching RTCL tier
        $rtcl_membership_tier_id = $this->vof_find_matching_rtcl_membership_tier($stripe_data);
        
        if (!$rtcl_membership_tier_id) {
            error_log('VOF: No matching RTCL pricing tier found for Stripe product: ' . $stripe_data['product_name']);
            return new WP_Error('no_matching_tier', 'No matching RTCL pricing tier found');
        }
    
        // 2. Create or update RTCL subscription (13 PARAMS REQ IN DB... + Id (self created primimary key))
        $subscription_data = [
            'user_id' => $temp_user_id, //  OK -> REQUIRED IN DB && Will be updated after user creation [1/13]
            'name' => $stripe_data['product_name'], // OK -> REQUIRED IN DB (MAYBE USER WP POST'S TITLE) [2/13]
            'sub_id' => $subscription_id, // OK -> REQUIRED IN DB [3/13]
            'occurrence' => 1, // OK -> REQUIRED IN DB [4/13]
            'gateway_id' => 'stripe', // OK -> REQUIRED IN DB [5/13]
            'status' => $this->vof_map_stripe_status_to_rtcl($stripe_data['status']), // OK -> REQUIRED IN DB (maybe is mapped the other way round)  [6/13]
            'product_id' => $rtcl_membership_tier_id, // OK -> && TRUE WP POST'S REQUIRED IN DB [7/13]
            'quantity' => 1, // Added: Default to 1 as per RTCL [8/13]
            'price' => $stripe_data['amount'], // OK -> REQUIRED IN DB (MAYBE NEEDS CENTS CONVERSION) [9/13]
            'meta' => null, // Added: Always null in RTCL [10/13]
            'expiry_at' => date('Y-m-d H:i:s', $stripe_data['current_period_end']), //  OK -> REQUIRED IN DB && BUT ALWAYS SET AS 0000-00-00 00:00:00 IN RTCL [11/13]
            'created_at' => current_time('mysql'), // [12/13]
            'updated_at' => current_time('mysql') // [13/13]
        ];
    
        // 3. Add subscription meta using RTCL's format
        $cc_data = [
            'type' => 'card',
            'last4' => $stripe_data['payment_method']['card']['last4'],
            'expiry' => $stripe_data['payment_method']['card']['exp_month'] . '/' . 
                        $stripe_data['payment_method']['card']['exp_year']
        ];

        // 4. Store VOF specific metadata
        $meta_data = [
            'vof_temp_user_id' => $temp_user_id,
            'stripe_product_id' => $stripe_data['product_id'],
            'stripe_price_id' => $stripe_data['price_id'],
            'stripe_customer_id' => $stripe_data['customer'],
            'stripe_subscription_id' => $subscription_id,
            'vof_flow' => true // Flag to identify VOF-created subscriptions
        ];
    
        // Use existing Subscriptions model
        $subscriptions = new Subscriptions();
        $existing_sub = $subscriptions->findOneBySubId($subscription_id);
    
        if ($existing_sub) {
            // Potentially remove: Update logic might not be needed for VOF
            $existing_sub->update($subscription_data);
            foreach ($meta_data as $key => $value) {
                $existing_sub->update_meta($key, $value);
            }
        } else {
            $new_sub = $subscriptions->create($subscription_data);
            if (!is_wp_error($new_sub)) {
                $new_sub->update_meta('cc', $cc_data);
                // Store VOF specific data in a separate meta key to avoid conflicts
            }
        }
    
        return $new_sub;
    }

    private function vof_find_matching_rtcl_membership_tier($stripe_data) { // DONE
        global $wpdb;
        
        // First look for published membership tiers with matching names
        $title_query = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'rtcl_pricing' 
            AND post_status = 'publish' 
            -- AND post_id IN ( -- old approach (probably an error DELETE WHEN TESTED)
            AND ID IN (
            -- post_id to ID -- Changed 'post_id' to 'ID' in main query to match wp_posts table's primary key
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
    
        if (empty($id_via_title_matches)) {
            error_log("Could not match Stripe product name '" . $stripe_data['product_name'] . 
                     "' with RTCL's membership name. Attempting to match price records");
        }
    
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
                
        if (empty($id_via_price_matches)) {
            throw new \Exception(sprintf(
                'Could not match Stripe product price %.2f with RTCL price',
                $stripe_data['amount'] / 100
            ));
        }
    
        // Find the common IDs between both matches
        $rtcl_membership_id = array_intersect($id_via_title_matches, $id_via_price_matches);
    
        if (count($rtcl_membership_id) === 1) {
            return reset($rtcl_membership_id); // Returns the first (and only) element
        } else {
            error_log(sprintf(
                'Found %d matching membership tiers for Stripe product "%s" with price %.2f. Matching IDs: %s',
                count($rtcl_membership_id),
                $stripe_data['product_name'],
                $stripe_data['amount'] / 100,
                implode(', ', $rtcl_membership_id)
            ));
            throw new \Exception('Found multiple matching membership tiers');
        }
    }

    /**
     * Map Stripe status to RTCL status
     */
    private function vof_map_stripe_status_to_rtcl($stripe_status) {
        $status_map = [
            'active' => Subscription::STATUS_ACTIVE,
            'canceled' => Subscription::STATUS_CANCELED,
            'incomplete' => Subscription::STATUS_PENDING,
            'incomplete_expired' => Subscription::STATUS_EXPIRED,
            'past_due' => Subscription::STATUS_FAILED,
            'unpaid' => Subscription::STATUS_FAILED,
            'trialing' => Subscription::STATUS_ACTIVE
        ];
    
        return $status_map[$stripe_status] ?? Subscription::STATUS_PENDING;
    }

    public function vof_process_subscriptionOLD($user_id, $stripe_subscription, $meta_data = []) {
        try {

            global $wpdb;
            $wpdb->query('START TRANSACTION');

            $rtcl_membership_tier_id = $this->vof_find_matching_rtcl_membership_tier([
                'amount' => $stripe_subscription['plan']['amount'],
                'product_name' => $stripe_subscription['plan']['product']['name'],
                'product_id' => $stripe_subscription['plan']['product'],
                'price_id' => $stripe_subscription['plan']['id']
            ]);

            if (!$rtcl_membership_tier_id) {
                error_log('VOF: No matching RTCL pricing tier found for Stripe product: ' . $stripe_data['product_name']);
                throw new \Exception('Could not match Stripe product with RTCL membership tier');
            }
            
            $this->vof_sync_stripe_data($rtcl_membership_tier_id, [
                'product_id' => $stripe_subscription['plan']['product'],
                'price_id' => $stripe_subscription['plan']['id']
            ]);

            // TODO: DOUBLE CHECK THIS TABLE...
            // Create subscription using RTCL's model
            $subscription = new Subscriptions();
            $subscription_data = [
                'name' => $stripe_subscription['plan']['product']['name'], // Required field missing
                'sub_id' => $stripe_subscription['id'],
                'user_id' => $user_id,
                'product_id' => $rtcl_membership_tier_id,
                'gateway_id' => 'stripe',
                'status' => $this->vof_map_subscription_status($stripe_subscription['status']),
                'expiry_at' => $this->vof_calculate_expiry_date($stripe_subscription),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                // Additional required fields from RTCL
                'customer_id' => $user_id,
                '_order_key' => apply_filters('rtcl_generate_order_key', uniqid('rtcl_order_')), // maybe not needed...
                '_rtcl_membership_tier_id' => $rtcl_membership_tier_id,
                'amount' => $stripe_subscription['plan']['amount'] / 100,
                '_payment_method' => 'stripe',
                '_payment_method_title' => 'Stripe',
                '_order_currency' => $stripe_subscription['currency'],
                '_rtcl_recurring' => 1
            ];

            // Create the subscription using RTCL's model
            $subscription_id = $subscription->create($subscription_data);
            if (!$subscription_id) {
                throw new \Exception('Failed to create subscription record');
            }

            // Add subscription meta using RTCL's methods
            foreach (array_merge($meta_data, [
                '_stripe_customer_id' => $stripe_subscription['customer'],
                '_stripe_subscription_id' => $stripe_subscription['id']
            ]) as $meta_key => $meta_value) {
                $subscription->update_meta($meta_key, $meta_value);
            }

            // Trigger RTCL's membership completion hook
            do_action('rtcl_membership_order_completed', $subscription_id);

            $wpdb->query('COMMIT');
            return $subscription_id;

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('subscription_error', $e->getMessage());
        }
    }

    private function vof_sync_stripe_data($rtcl_membership_tier_id, $stripe_data) {
        // Verify this is a membership type pricing
        $current_type = get_post_meta($rtcl_membership_tier_id, '_pricing_type', true);
        if ($current_type && $current_type !== 'membership') {
            throw new \Exception('Cannot sync Stripe data with non-membership pricing type');
        }
    
        // Only set Stripe IDs if they haven't been set before
        if (!get_post_meta($rtcl_membership_tier_id, '_stripe_product_id', true)) {
            update_post_meta($rtcl_membership_tier_id, '_stripe_product_id', $stripe_data['product_id']);
        }
    
        if (!get_post_meta($rtcl_membership_tier_id, '_stripe_price_id', true)) {
            update_post_meta($rtcl_membership_tier_id, '_stripe_price_id', $stripe_data['price_id']);
        }
    
        // Ensure pricing type is set to membership
        update_post_meta($rtcl_membership_tier_id, '_pricing_type', 'membership');
    
        return true;
    }

    // need explanation
    private function vof_map_subscription_status($stripe_status) {
        $status_map = [
            'active' => 'active',
            'past_due' => 'past_due',
            'unpaid' => 'unpaid',
            'canceled' => 'cancelled',
            'incomplete' => 'pending',
            'incomplete_expired' => 'expired',
            'trialing' => 'trialing'
        ];

        return isset($status_map[$stripe_status]) ? $status_map[$stripe_status] : 'pending';
    }

    private function vof_calculate_expiry_date($stripe_subscription) {
        $current_period_end = $stripe_subscription['current_period_end'];
        return date('Y-m-d H:i:s', $current_period_end);
    }

    public function vof_handle_subscription_cancelled($subscription_id, $stripe_event) {
        global $wpdb;
        
        $wpdb->update($this->table, 
            ['status' => 'cancelled', 'updated_at' => current_time('mysql')],
            ['sub_id' => $subscription_id]
        );

        do_action('vof_after_subscription_cancelled', $subscription_id, $stripe_event);
    }

    public function vof_handle_subscription_updated($subscription_id, $stripe_event) {
        global $wpdb;
        
        $subscription = $stripe_event->data->object;
        
        $wpdb->update($this->table, 
            [
                'status' => $this->vof_map_subscription_status($subscription->status),
                'expiry_at' => date('Y-m-d H:i:s', $subscription->current_period_end),
                'updated_at' => current_time('mysql')
            ],
            ['sub_id' => $subscription_id]
        );

        do_action('vof_after_subscription_updated', $subscription_id, $stripe_event);
    }

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