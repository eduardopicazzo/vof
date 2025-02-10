<?php
namespace VOF\Models;

use Rtcl\Models\Payment;
use VOF\Traits\VOF_Stripe_Data_Mapper;
use VOF\Traits\VOF_Payment_Meta_Handler;

/**
 * Class VOF_Payment
 * Extends RTCL Payment to handle Stripe subscription data
 */
class VOF_Payment extends Payment {
    use VOF_Stripe_Data_Mapper;
    use VOF_Payment_Meta_Handler;

    private $stripe_data;
    private $temp_user_data;
    private $rtcl_payment_id;
    
    /**
     * @param array $stripe_data Stripe subscription data
     * @param array $temp_user_data VOF temporary user data
     */
    public function __construct($stripe_data, $temp_user_data) {
        $this->stripe_data = $stripe_data;
        $this->temp_user_data = $temp_user_data;
        
        // Create payment record and initialize parent
        $this->rtcl_payment_id = $this->vof_create_payment_record();
        if (!$this->rtcl_payment_id) {
            throw new \Exception('Failed to create payment record');
        }
        
        parent::__construct($this->rtcl_payment_id);
    }

    /**
     * Gets the underlying RTCL payment ID
     */
    public function vof_get_rtcl_payment_id() {
        return $this->rtcl_payment_id;
    }

    /**
     * Creates the initial payment record in RTCL format
     */
    private function vof_create_payment_record() {
        $payment_args = [
            'post_type' => rtcl()->post_type_payment,
            'post_status' => 'rtcl-completed',
            'post_title' => sprintf(
                __('Payment for Order #%s', 'vof'),
                $this->stripe_data['payment_intent']
            ),
            'post_author' => $this->temp_user_data['true_user_id'],
            'meta_input' => $this->vof_get_payment_meta()
        ];

        return wp_insert_post($payment_args);
    }

    /**
     * Override to handle Stripe-specific data
     */
    public function set_status($status) {
        if ($status === 'rtcl-completed') {
            $this->vof_handle_subscription_meta();
        }
        return parent::set_status($status);
    }

    /**
     * Override to include Stripe data
     */
    public function get_payment_method() {
        return 'stripe';
    }
}