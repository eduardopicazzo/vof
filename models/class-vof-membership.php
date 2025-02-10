<?php
namespace VOF\Models;

use Rtcl\Models\Payment as RtclPayment;
use RtclStore\Models\Membership;
use VOF\Traits\VOF_Stripe_Data_Mapper;

class VOF_Membership extends Membership {
    use VOF_Stripe_Data_Mapper;

    private $stripe_data;

    public function __construct($user_id, $stripe_data) {
        $this->stripe_data = $stripe_data;
        parent::__construct($user_id);
    }

    /**
     * VOF specific method to handle Stripe memberships
     */
    public function vof_apply_stripe_membership(VOF_Payment $vof_payment) {
        try {
            // Validate Stripe subscription data
            if (!$this->vof_validate_subscription_data()) {
                throw new \Exception('Invalid subscription data');
            }
            
            // Convert VOF payment to RTCL payment format
            $rtcl_payment = $this->vof_convert_to_rtcl_payment($vof_payment);
            
            // Call parent's original method with converted data
            return parent::apply_membership($rtcl_payment);
            
        } catch (\Exception $e) {
            error_log('VOF Membership Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * VOF specific method to handle subscription updates
     */
    public function vof_update_stripe_membership(VOF_Payment $vof_payment) {
        try {
            // Convert VOF payment to RTCL format
            $rtcl_payment = $this->vof_convert_to_rtcl_payment($vof_payment);
            
            // Map Stripe status
            $this->vof_update_membership_status(
                $this->stripe_data['status']
            );
            
            // Call parent's method with converted data
            return parent::update_membership($rtcl_payment);
            
        } catch (\Exception $e) {
            error_log('VOF Membership Update Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validates Stripe subscription data
     */
    private function vof_validate_subscription_data() {
        if (empty($this->stripe_data)) {
            return false;
        }

        $required_fields = [
            'subscription',
            'customer',
            'status',
            'current_period_end',
            'price_id'
        ];

        foreach ($required_fields as $field) {
            if (!isset($this->stripe_data[$field])) {
                error_log("VOF Membership Error: Missing required field: {$field}");
                return false;
            }
        }

        // Validate status
        $valid_statuses = ['active', 'past_due', 'unpaid', 'canceled'];
        if (!in_array($this->stripe_data['status'], $valid_statuses)) {
            error_log("VOF Membership Error: Invalid status: {$this->stripe_data['status']}");
            return false;
        }

        return true;
    }

    /**
     * Converts VOF payment to RTCL payment format
     */
    private function vof_convert_to_rtcl_payment(VOF_Payment $vof_payment) {
        // Get underlying RTCL payment ID from VOF payment
        $rtcl_payment_id = $vof_payment->vof_get_rtcl_payment_id();
        
        if (!$rtcl_payment_id) {
            throw new \Exception('Could not get RTCL payment ID from VOF payment');
        }
        
        // Return RTCL payment instance
        return new RtclPayment($rtcl_payment_id);
    }

    /**
     * Updates membership status based on Stripe status
     */
    private function vof_update_membership_status($stripe_status) {
        $rtcl_status = $this->vof_map_stripe_status_to_rtcl($stripe_status);
        update_post_meta($this->id, 'status', $rtcl_status);
        
        // Update expiration if status is active
        if ($stripe_status === 'active' && isset($this->stripe_data['current_period_end'])) {
            $this->vof_update_expiration_date();
        }
    }

    /**
     * Updates expiration date based on Stripe period
     */
    private function vof_update_expiration_date() {
        if (empty($this->stripe_data['current_period_end'])) {
            return;
        }

        $expiry_date = date('Y-m-d H:i:s', $this->stripe_data['current_period_end']);
        update_post_meta($this->id, 'expiry_date', $expiry_date);
    }

    /**
     * Gets membership type from Stripe price ID
     */
    private function vof_get_membership_type() {
        global $wpdb;
        
        $price_id = $this->stripe_data['price_id'];
        
        $pricing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_stripe_price_id' 
            AND meta_value = %s",
            $price_id
        ));

        if (!$pricing_id) {
            throw new \Exception('Could not find RTCL pricing for Stripe price: ' . $price_id);
        }

        return get_post_meta($pricing_id, 'pricing_type', true);
    }
}