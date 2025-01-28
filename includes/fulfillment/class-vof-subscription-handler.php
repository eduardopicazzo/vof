<?php
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
        $this->temp_user_meta = new VOF_Temp_User_Meta();
        
        add_action('vof_subscription_created', [$this, 'vof_process_subscription'], 10, 3);
        add_action('vof_subscription_cancelled', [$this, 'vof_handle_subscription_cancelled'], 10, 2);
        add_action('vof_subscription_updated', [$this, 'vof_handle_subscription_updated'], 10, 2);
    }

    private function vof_find_matching_rtcl_membership_tier($stripe_data) {
        global $wpdb;
        
        // First look for published membership tiers with matching names
        $title_query = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'rtcl_pricing' 
            AND post_status = 'publish' 
            AND post_id IN (
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

    public function vof_process_subscription($user_id, $stripe_subscription, $meta_data = []) {
        try {
            /**
             * Finding the correct predefined membership tier (meta product ID) 
             * in the "classified listing" plugin ecosystem:
             * 
             * 1.1: Identifying the correct post "ID":
             * 
             * Approach:
             * - Use a series of database queries across:
             *   - Classified listing's "post" and "postmeta" tables.
             *   - "vof_temp_user" and "vof_stripe_transactions" tables. 
             *      (not used in this impl but rather the stripe data...)
             * 
             * Process:
             * - Match records by comparing:
             *   - "post_status" = published.
             *   - "post_name" or "post_title" (whichever is more unique).
             *   - "post_type".
             * - Cross-reference with VOF's Stripe transaction metadata (collected via webhooks):
             *   - Compare transaction details (e.g., title, identifiers) stored in "vof_stripe_transactions".
             * 
             * Outcome:
             * - Retrieve a list of potential post "IDs".
             * - Fetch corresponding prices from the "postmeta" table.
             * - Validate by comparing "postmeta" price with Stripe transaction price.
             * - Determine the correct post "ID" for the membership tier.
             */
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            $rtcl_membership_tier_id = $this->vof_find_matching_rtcl_membership_tier([
                'amount' => $stripe_subscription['plan']['amount'],
                'product_name' => $stripe_subscription['plan']['product']['name'],
                'product_id' => $stripe_subscription['plan']['product'],
                'price_id' => $stripe_subscription['plan']['id']
            ]);

            if (!$rtcl_membership_tier_id) {
                throw new \Exception('Could not match Stripe product with RTCL membership tier');
            }
            
            $this->vof_sync_stripe_data($rtcl_membership_tier_id, [
                'product_id' => $stripe_subscription['plan']['product'],
                'price_id' => $stripe_subscription['plan']['id']
            ]);

            // TODO: DOUBLE CHECK THIS TABLE...
            // Create subscription using RTCL's model
            $subscription = new Subscription();
            $subscription_data = [
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
                '_pricing_id' => $rtcl_membership_tier_id,
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

    private function vof_sync_stripe_data($pricing_id, $stripe_data) {
        // Verify this is a membership type pricing
        $current_type = get_post_meta($pricing_id, '_pricing_type', true);
        if ($current_type && $current_type !== 'membership') {
            throw new \Exception('Cannot sync Stripe data with non-membership pricing type');
        }
    
        // Only set Stripe IDs if they haven't been set before
        if (!get_post_meta($pricing_id, '_stripe_product_id', true)) {
            update_post_meta($pricing_id, '_stripe_product_id', $stripe_data['product_id']);
        }
    
        if (!get_post_meta($pricing_id, '_stripe_price_id', true)) {
            update_post_meta($pricing_id, '_stripe_price_id', $stripe_data['price_id']);
        }
    
        // Ensure pricing type is set to membership
        update_post_meta($pricing_id, '_pricing_type', 'membership');
    
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