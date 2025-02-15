<?php
namespace VOF\Includes\Fulfillment;

use Rtcl\Helpers\Functions;
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
    }

    /**
     * Main fulfillment process entry point
     * 
     * @param array $stripe_data Stripe webhook data
     * @param int|null $customer_id Optional customer ID
     * @param string|null $subscription_id Optional subscription ID
     * @return bool
     * @throws \Exception
     */
    public function vof_initiate_fulfillment($stripe_data, $customer_id = null, $subscription_id = null) {
        try {
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            error_log('VOF Debug: Starting fulfillment with stripe data-> ' . print_r($stripe_data, true));

            // Get temp user data
            $temp_user = $this->temp_user_meta->vof_get_temp_user_by_uuid(
                $stripe_data['metadata']['uuid']
            );

            if (!$temp_user) {
                throw new \Exception('No temporary user data found for UUID: ' . $stripe_data['metadata']['uuid']);
            }

            // Create RTCL payment post first
            $payment_id = $this->vof_create_rtcl_payment($stripe_data, $temp_user);
            if (!$payment_id) {
                throw new \Exception('Failed to create RTCL payment record');
            }

            // Process membership fulfillment
            $result = $this->vof_process_fulfillment($stripe_data, $temp_user, $payment_id);
            
            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            // Cleanup temporary data
            $this->vof_cleanup_temp_data($stripe_data, $temp_user);

            $wpdb->query('COMMIT');
            return true;

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('VOF Fulfillment Error: ' . $e->getMessage());
            error_log('VOF Fulfillment Error Stack: ' . $e->getTraceAsString());
            do_action('vof_fulfillment_failed', $stripe_data, $e->getMessage());
            throw $e;
        }
    }

    private function vof_create_rtcl_payment($stripe_data, $temp_user, $pricing_id) {
        // Format stripe amount to RTCL format
        $amount = $stripe_data['amount'] / 100;
    
        $VOF_Fulfillment_DS = [
            'checkout_data' => [
                'type'                      => 'membership',
                'listing_id'                => 0,
                'pricing_id'                => $pricing_id,
                'payment_method'            => 'stripe',
                'rtcl_privacy_policy'       => 'on',
                'rtcl_checkout_nonce'       => wp_create_nonce('rtcl_checkout'),
                '_wp_http_referer'          => '/checkout/membership/',
                'action'                    => 'rtcl_ajax_checkout_action',
                'stripe_payment_method'     => $stripe_data['payment_method'] ?? null
            ],
            'meta_input' => [
                'customer_id'               => $temp_user['true_user_id'],
                'customer_ip_address'       => Functions::get_ip_address(),
                '_order_key'                => apply_filters('rtcl_generate_order_key', uniqid('rtcl_order_')),
                '_pricing_id'               => $pricing_id,
                // 'amount'                    => Functions::get_payment_formatted_price($amount),
                'amount'                    => $amount,
                '_tax_amount'               => 0.00,
                // '_subtotal'                 => Functions::get_payment_formatted_price($amount),
                '_subtotal'                 => $amount,
                '_payment_method'           => 'stripe',
                '_payment_method_title'     => 'Stripe',
                '_order_currency'           => strtoupper($stripe_data['currency']),
                '_billing_email'            => $temp_user['vof_email'],
                'payment_type'              => 'membership',
                '_stripe_customer_id'       => $stripe_data['customer'],
                '_stripe_subscription_id'   => $stripe_data['subscription'] // missing in stripe data
            ],
            'new_order_args' => [
                'post_title'                => esc_html__('Order on', 'classified-listing') . ' ' . current_time("l jS F Y h:i:s A"),
                'post_status'               => 'rtcl-created',
                'post_parent'               => '0',
                'ping_status'               => 'closed',
                'post_author'               => 1,
                'post_type'                 => rtcl()->post_type_payment,
                'meta_input'                => null  // Will be filled with meta_input array
            ]
        ];
    
        // Set meta_input in new_order_args
        $VOF_Fulfillment_DS['new_order_args']['meta_input'] = $VOF_Fulfillment_DS['meta_input'];
    
        // Get RTCL objects
        $pricing = rtcl()->factory->get_pricing($VOF_Fulfillment_DS['checkout_data']['pricing_id']);
        $gateway = Functions::get_payment_gateway($VOF_Fulfillment_DS['checkout_data']['payment_method']);
        $new_order_args = $VOF_Fulfillment_DS['new_order_args'];
        $checkout_data  = $VOF_Fulfillment_DS['checkout_data'];
    
        // Validate checkout data
        $errors = new WP_Error();
        do_action('rtcl_checkout_data', $VOF_Fulfillment_DS['checkout_data'], $pricing, $gateway, [], $errors);
        $errors = apply_filters('rtcl_checkout_validation_errors', $errors, $VOF_Fulfillment_DS['checkout_data'], $pricing, $gateway, []);
    
        if (is_wp_error($errors) && $errors->has_errors()) {
            error_log('VOF Error: Validation failed - ' . $errors->get_error_message());
            return false;
        }
    
        // Create Order ID
        $order_id = wp_insert_post(apply_filters('rtcl_checkout_process_new_order_args', $new_order_args, $pricing, $gateway, $checkout_data));
    
        if (!$order_id || is_wp_error($order_id)) {
            error_log('VOF Error: Failed to create payment post. ' . ($order_id instanceof WP_Error ? $order_id->get_error_message() : ''));
            return false;
        }
    
        // Set order key and trigger actions
        $order = rtcl()->factory->get_order($order_id);
        $order->set_order_key();
        do_action('rtcl_checkout_process_new_payment_created', $order_id, $order);
        do_action('rtcl_checkout_process_success', $order, []);
    
        return $order_id;
    }

    /**
     * Creates RTCL payment post type record
     * 
     * @param array $stripe_data Stripe webhook data
     * @param array $temp_user Temporary user data
     * @return int|false Post ID on success, false on failure
     */
    private function vof_create_rtcl_payment_OLD($stripe_data, $temp_user) {
        // Format stripe amount to RTCL format
        $amount = $stripe_data['amount'] / 100;

        $VOF_Fulfillment_DS = [
            'checkout_data' => [
                'type'                  => 'membership',
                'listing_id'            => 0,
                'pricing_id'            => $pricing_id,                    // From wp_posts
                'payment_method'        => 'stripe',
                'rtcl_privacy_policy'   => 'on',
                'rtcl_checkout_nonce'   => wp_create_nonce('rtcl_checkout'),
                '_wp_http_referer'      => '/checkout/membership/',
                'action'                => 'rtcl_ajax_checkout_action',
                'stripe_payment_method' => $stripe_payment_method_id      // From Stripe
            ],
            'meta_input' => [
                'customer_id'           => $temp_user['true_user_id'],
                'customer_ip_address'   => Functions::get_ip_address(),
                '_order_key'            => apply_filters('rtcl_generate_order_key', uniqid('rtcl_order_')),
                '_pricing_id'           => $stripe_data['metadata']['pricing_id'],
                'amount'                => Functions::get_payment_formatted_price($amount),
                '_tax_amount'           => 0.00,
                '_subtotal'             => Functions::get_payment_formatted_price($amount),
                '_payment_method'       => 'stripe',
                '_payment_method_title' => 'Stripe',
                '_order_currency'       => strtoupper($stripe_data['currency']),
                '_billing_email'        => $temp_user['vof_email']
            ],
            'new_order_args' => [
                'post_title'  => esc_html__('Order on', 'classified-listing') . ' ' . current_time("l jS F Y h:i:s A"),
                'post_status' => 'rtcl-created',
                'post_parent' => '0',
                'ping_status' => 'closed',
                'post_author' => 1,
                'post_type'   => rtcl()->post_type_payment,
                'meta_input'  => null  // This will be filled with meta_input array above
            ]
        ]; 

        // Define RTCL's key variables merged with VOF's
        $pricing = rtcl()->factory->get_pricing($VOF_Fulfillment_DS['checkout_data']['pricing_id']);
        $gateway = Functions::get_payment_gateway($VOF_Fulfillment_DS['checkout_data']['payment_method']);
        $checkout_data = $VOF_Fulfillment_DS['checkout_data'];
        $meta_input = $VOF_Fulfillment_DS['new_order_args']['meta_input'];

        // maybe add maybe not START...
            // maybe implmement getting the name if available like @Checkout.php
            // if ( $current_user->first_name ) {
            //     $metaInputs['_billing_first_name'] = $current_user->first_name;
            // }
            // if ( $current_user->last_name ) {
            //     $metaInputs['_billing_last_name'] = $current_user->last_name;
            // }
            // if ( ! Functions::is_billing_address_disabled() ) {
            //     $checkout      = rtcl()->checkout();
            //     $billingFields = $checkout->get_checkout_fields( 'billing' );
            //     if ( ! empty( $billingFields ) ) {
            //         foreach ( $billingFields as $_key => $field ) {
            //             if ( $_value = $checkout->get_value( $_key ) ) {
            //                 if ( 'billing_email' === $_key ) {
            //                     if ( is_email( $_value ) ) {
            //                         $metaInputs[ '_' . $_key ] = $_value;
            //                         update_user_meta( $current_user->ID, '_' . $_key, $_value );
            //                     }
            //                 } else {
            //                     $metaInputs[ '_' . $_key ] = $_value;
            //                     update_user_meta( $current_user->ID, '_' . $_key, $_value );
            //                 }
            //             }
            //         }
            //     }
            // }

            // Add membership promotions if present in pricing [ AUGMENTED VERSION - VOF Proactive V ]
            // $membership_promotions = get_post_meta($stripe_data['metadata']['pricing_id'], '_rtcl_membership_promotions', true);
            // if (!empty($membership_promotions)) {
            //     $meta_input['_rtcl_membership_promotions'] = $membership_promotions;
            // }
        // maybe add maybe not END
        

        $new_order_args = $VOF_Fulfillment_DS['new_order_args'];
        // Set meta_input in new_order_args
        $meta_input = $VOF_Fulfillment_DS['meta_input'];   
        
        // RTCL's Insertion Method $payment_id === $order_id

        $order_id = wp_insert_post(apply_filters('rtcl_checkout_process_new_order_args', $new_order_args, $pricing, $gateway, $checkout_data));

        if (!$order_id || is_wp_error($order_id)) {
            error_log('VOF Error: Failed to create payment post. ' . ($order_id ? $order_id->get_error_message() : ''));
            return false;
        }

        error_log('VOF Debug: Created payment post ID: ' . $order_id);
        return $order_id;
    }

    /**
     * Processes the membership fulfillment using RTCL's native functionality
     * 
     * @param array $stripe_data Stripe webhook data
     * @param array $temp_user Temporary user data
     * @param int $payment_id Payment post ID
     * @return true|WP_Error True on success, WP_Error on failure
     */
    private function vof_process_fulfillment($stripe_data, $temp_user, $payment_id) {
        try {
            // Get RTCL payment object
            $payment = rtcl()->factory->get_order($payment_id);
            if (!$payment) {
                throw new \Exception('Invalid payment record');
            }

            // Apply membership using RTCL's native function
            Functions::apply_membership($payment);

            // Publish the temporary listing
            $this->vof_publish_listing($temp_user, $payment->get_customer_id());

            return true;

        } catch (\Exception $e) {
            error_log('VOF Fulfillment Process Error: ' . $e->getMessage());
            return new WP_Error(
                'fulfillment_failed',
                $e->getMessage()
            );
        }
    }

    /**
     * Publishes the temporary listing
     * 
     * @param array $temp_user Temporary user data
     * @param int $user_id User ID
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    private function vof_publish_listing($temp_user, $user_id) {
        $post_data = [
            'ID' => $temp_user['post_id'],
            'post_status' => 'publish',
            'post_author' => $user_id,
            'meta_input' => [
                '_rtcl_membership_assigned' => true,
                '_rtcl_listing_owner' => $user_id,
                '_rtcl_manager_id' => $user_id
            ]
        ];

        $result = wp_update_post($post_data);
        if (is_wp_error($result)) {
            error_log('VOF Error: Failed to publish listing: ' . $result->get_error_message());
            throw new \Exception('Failed to publish listing: ' . $result->get_error_message());
        }

        error_log('VOF Debug: Published listing ID: ' . $result);
        return $result;
    }

    /**
     * Cleanup temporary data after successful fulfillment
     * 
     * @param array $stripe_data Stripe webhook data
     * @param array $temp_user Temporary user data
     */
    private function vof_cleanup_temp_data($stripe_data, $temp_user) {
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

        error_log('VOF Debug: Cleaned up temporary data for UUID: ' . $temp_user['uuid']);
    }
}