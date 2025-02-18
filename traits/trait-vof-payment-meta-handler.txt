<?php
namespace VOF\Traits;

/**
 * Trait VOF_Payment_Meta_Handler
 * Handles payment metadata operations
 */
trait VOF_Payment_Meta_Handler {
    /**
     * Gets payment metadata in RTCL format
     */
    protected function vof_get_payment_meta() {
        return [
            '_payment_type' => 'membership',
            '_pricing_id' => $this->vof_get_pricing_id(),
            '_order_key' => uniqid('vof_order_'),
            '_gateway' => 'stripe',
            '_stripe_customer_id' => $this->stripe_data['customer'],
            '_stripe_subscription_id' => $this->stripe_data['subscription'],
            '_price' => $this->vof_format_price(),
            '_order_currency' => $this->stripe_data['currency'],
            'vof_uuid' => $this->temp_user_data['uuid']
        ];
    }

    /**
     * Handles subscription-specific metadata
     */
    protected function vof_handle_subscription_meta() {
        update_post_meta($this->id, '_applied', true);
        update_post_meta($this->id, 'subscription_period', 
            $this->vof_map_subscription_period());
    }

    /**
     * Gets pricing ID from Stripe product mapping
     */
    protected function vof_get_pricing_id() {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_stripe_price_id' 
            AND meta_value = %s",
            $this->stripe_data['price_id']
        ));
    }

    /**
     * Formats price for RTCL
     */
    protected function vof_format_price() {
        return number_format(
            $this->stripe_data['amount'] / 100,
            2,
            '.',
            ''
        );
    }
}