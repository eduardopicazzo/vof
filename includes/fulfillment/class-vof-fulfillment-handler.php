<?php
namespace VOF\Includes\Fulfillment;

use Rtcl\Helpers\Functions;
use Rtcl\Models\Payment;
use RtclStore\Models\Membership;
use VOF\Includes\VOF_Core;
use VOF\Includes\Utils\Helpers\VOF_Temp_User_Meta;

class VOF_Fulfillment_Handler {
    private static $instance = null;
    private $temp_user_meta;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->temp_user_meta = new VOF_Temp_User_Meta();
        
        add_action('vof_payment_success', [$this, 'vof_process_fulfillment'], 10, 2);
        add_action('vof_before_membership_grant', [$this, 'vof_validate_membership_data'], 10, 2);
        add_action('vof_after_membership_grant', [$this, 'vof_update_user_capabilities'], 10, 2);
    }

    /**
     * Main fulfillment processing method
     * 
     * @param int $order_id Order ID 
     * @param array $payment_data Payment data from Stripe
     */
    public function vof_process_fulfillment($order_id, $payment_data) {
        try {
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            $temp_listing = $this->vof_get_temp_listing($order_id);
            if (!$temp_listing) {
                throw new \Exception('No temporary listing found for order ' . $order_id);
            }

            $payment = $this->vof_create_rtcl_payment($order_id, $payment_data);
            if (!$payment) {
                throw new \Exception('Failed to create RTCL payment for order ' . $order_id);
            }

            do_action('vof_before_membership_grant', $payment, $temp_listing);

            $payment->payment_completed();
            do_action('rtcl_membership_order_completed', $payment);

            $this->vof_update_user_capabilities($payment->get_user_id(), $payment);
            $this->vof_publish_listing($temp_listing, $payment->get_user_id());
            $this->vof_cleanup_temp_data($temp_listing->ID);

            $wpdb->query('COMMIT');
            do_action('vof_fulfillment_completed', $order_id, $payment);

            return true;

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('VOF Fulfillment Error: ' . $e->getMessage());
            do_action('vof_fulfillment_failed', $order_id, $e->getMessage());
            return false;
        }
    }

    /**
     * Create RTCL payment record
     */
    private function vof_create_rtcl_payment($order_id, $payment_data) {
        $pricing_id = $this->vof_get_pricing_id($payment_data);
        
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
                '_stripe_subscription_id' => $payment_data['subscription'],
                'vof_order_id'  => $order_id
            ]
        ];

        $payment_id = wp_insert_post($payment_args);
        
        if (is_wp_error($payment_id)) {
            return false;
        }

        return rtcl()->factory->get_order($payment_id);
    }

    /**
     * Get pricing ID from payment data
     */
    private function vof_get_pricing_id($payment_data) {
        global $wpdb;
        
        $pricing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_stripe_price_id' 
            AND meta_value = %s",
            $payment_data['price_id']
        ));

        return $pricing_id;
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
     * Publish temporary listing
     */
    private function vof_publish_listing($temp_listing, $user_id) {
        $post_data = [
            'ID'          => $temp_listing->post_id,
            'post_status' => 'publish',
            'post_author' => $user_id
        ];

        wp_update_post($post_data);
        update_post_meta($temp_listing->post_id, '_rtcl_membership_assigned', true);
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
    }

    /**
     * Update user capabilities after membership grant
     */
    public function vof_update_user_capabilities($user_id, $payment) {
        $user = get_user_by('ID', $user_id);
        if ($user) {
            $user->add_role('vendor');
            update_user_meta($user_id, '_rtcl_membership_id', $payment->get_id());
            update_user_meta($user_id, '_rtcl_subscription_active', true);
        }
    }

    /**
     * Clean up temporary data
     */
    private function vof_cleanup_temp_data($temp_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'vof_temp_listings';
        
        $wpdb->delete($table, ['ID' => $temp_id]);
    }
}