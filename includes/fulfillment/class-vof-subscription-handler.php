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
        $this->table = $wpdb->prefix . 'rtcl_subscriptions';
        $this->table_meta = $wpdb->prefix . 'rtcl_subscription_meta';
        $this->temp_user_meta = new VOF_Temp_User_Meta();
        
        add_action('vof_subscription_created', [$this, 'vof_process_subscription'], 10, 3);
        add_action('vof_subscription_cancelled', [$this, 'vof_handle_subscription_cancelled'], 10, 2);
        add_action('vof_subscription_updated', [$this, 'vof_handle_subscription_updated'], 10, 2);
    }

    private function vof_find_matching_membership_tier($stripe_data) {
        global $wpdb;

        // First try by exact price match
        $price_query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_price' 
            AND meta_value = %f 
            AND post_id IN (
                SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'rtcl_pricing' 
                AND post_status = 'publish'
            )",
            $stripe_data['amount'] / 100
        );

        $pricing_id = $wpdb->get_var($price_query);

        if (!$pricing_id) {
            $title_query = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'rtcl_pricing' 
                AND post_status = 'publish' 
                AND (
                    post_title LIKE %s 
                    OR post_name LIKE %s
                )",
                '%' . $wpdb->esc_like($stripe_data['product_name']) . '%',
                '%' . $wpdb->esc_like($stripe_data['product_name']) . '%'
            );

            $potential_matches = $wpdb->get_col($title_query);

            if ($potential_matches) {
                foreach ($potential_matches as $post_id) {
                    $stored_price = get_post_meta($post_id, '_price', true);
                    if (abs($stored_price - ($stripe_data['amount'] / 100)) <= 0.01) {
                        $pricing_id = $post_id;
                        break;
                    }
                }
            }
        }

        return $pricing_id;
    }

    private function vof_sync_stripe_data($pricing_id, $stripe_data) {
        if (!get_post_meta($pricing_id, '_stripe_product_id', true)) {
            update_post_meta($pricing_id, '_stripe_product_id', $stripe_data['product_id']);
        }

        if (!get_post_meta($pricing_id, '_stripe_price_id', true)) {
            update_post_meta($pricing_id, '_stripe_price_id', $stripe_data['price_id']);
        }

        update_post_meta($pricing_id, '_pricing_type', 'membership');

        return true;
    }

    public function vof_process_subscription($user_id, $stripe_subscription, $meta_data = []) {
        try {
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            $pricing_id = $this->vof_find_matching_membership_tier([
                'amount' => $stripe_subscription['plan']['amount'],
                'product_name' => $stripe_subscription['plan']['product']['name'],
                'product_id' => $stripe_subscription['plan']['product'],
                'price_id' => $stripe_subscription['plan']['id']
            ]);

            if (!$pricing_id) {
                throw new \Exception('Could not match Stripe product with RTCL membership tier');
            }

            $this->vof_sync_stripe_data($pricing_id, [
                'product_id' => $stripe_subscription['plan']['product'],
                'price_id' => $stripe_subscription['plan']['id']
            ]);

            $subscription_data = [
                'sub_id'      => $stripe_subscription['id'],
                'user_id'     => $user_id,
                'product_id'  => $pricing_id,
                'gateway_id'  => 'stripe',
                'status'      => $this->vof_map_subscription_status($stripe_subscription['status']),
                'expiry_at'   => $this->vof_calculate_expiry_date($stripe_subscription),
                'created_at'  => current_time('mysql'),
                'updated_at'  => current_time('mysql')
            ];

            $subscription_id = $this->vof_create_subscription($subscription_data);
            if (!$subscription_id) {
                throw new \Exception('Failed to create subscription record');
            }

            $this->vof_add_subscription_meta($subscription_id, array_merge($meta_data, [
                '_stripe_customer_id' => $stripe_subscription['customer'],
                '_stripe_subscription_id' => $stripe_subscription['id']
            ]));

            $wpdb->query('COMMIT');
            return $subscription_id;

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('subscription_error', $e->getMessage());
        }
    }

    private function vof_create_subscription($data) {
        global $wpdb;
        
        $result = $wpdb->insert($this->table, $data);
        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    private function vof_add_subscription_meta($subscription_id, $meta_data) {
        global $wpdb;

        foreach ($meta_data as $meta_key => $meta_value) {
            $wpdb->insert($this->table_meta, [
                'subscription_id' => $subscription_id,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value
            ]);
        }
    }

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