<?php
namespace VOF\Includes\Fulfillment;
// path: wp-content/plugins/vendor-onboarding-flow/includes/fulfillment/class-vof-webhook-handler.php
use Rtcl\Helpers\Functions;
use VOF\VOF_Core;
use WP_Error;
use Stripe\Event;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use VOF\Utils\Stripe\VOF_Stripe_Config;

class VOF_Webhook_Handler {
    private static $instance = null;
    private $secret;
    private $fulfillment_handler;
    private $subscription_handler;
    private $stripe_config;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $config = VOF_Stripe_Config::vof_get_stripe_config_instance();
        $this->secret = $config->vof_get_stripe_webhook_secret();
        error_log('Webhook Secret: ' . ($this->secret ? 'exists' : 'missing'));

        $this->fulfillment_handler = VOF_Fulfillment_Handler::getInstance();
        $this->subscription_handler = VOF_Subscription_Handler::getInstance();
    }

    public function vof_handle_webhook(\WP_REST_Request $request) {
        try {
            $payload = $request->get_body();
            $sig_header = $request->get_header('stripe-signature');
                
            error_log('VOF Debug: Webhook received - Payload: ' . substr($payload, 0, 100));
            error_log('VOF Debug: Webhook signature: ' . ($sig_header ? 'exists' : 'missing'));
            
            // Validate webhook
            $event = $this->vof_validate_and_construct_event($payload, $sig_header);
            
            if (is_wp_error($event)) {
                error_log('VOF Error: Invalid webhook - ' . $event->get_error_message());
                return $event;
            }
            
            error_log('VOF Debug: Processing webhook event type: ' . $event->type);
            
            // Process webhook event
            $result = $this->vof_process_webhook_event($event);
            
            if (is_wp_error($result)) {
                error_log('VOF Error: Failed to process webhook - ' . $result->get_error_message());
                return $result;
            }
    
            return new \WP_REST_Response(['status' => 'success'], 200);
    
        } catch (\Exception $e) {
            error_log('VOF Error: Webhook handler exception - ' . $e->getMessage());
            return new \WP_REST_Response([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function vof_validate_and_construct_event($payload, $sig_header) {
        try {
            if (empty($sig_header) || empty($payload)) {
                return new WP_Error(
                    'invalid_webhook',
                    'Missing signature or payload',
                    ['status' => 400]
                );
            }

            return Webhook::constructEvent(
                $payload,
                $sig_header,
                $this->secret
            );

        } catch (SignatureVerificationException $e) {
            return new WP_Error(
                'invalid_signature',
                'Invalid signature',
                ['status' => 400]
            );
        } catch (\Exception $e) {
            return new WP_Error(
                'webhook_error',
                $e->getMessage(),
                ['status' => 400]
            );
        }
    }

    /**
     * Process webhook event from Stripe
     * 
     * @param object $event The Stripe event
     * @return array|WP_Error Success or error response
     */
    private function vof_process_webhook_event($event) {
        error_log('VOF Debug: Processing webhook event of type: ' . $event->type);
        
        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                // $result = $this->vof_handle_checkout_completed($session);
                $result = $this->vof_handle_checkout_completed_STUB();
                error_log('VOF Debug: Checkout completed');
                return $result;
    
            case 'customer.subscription.created':
                $subscription = $event->data->object;                
                $result = $this->vof_handle_subscription_created($subscription);
                error_log('VOF Debug: Subscription created');
                
                // Trigger action for interim fulfillment setup
                do_action('vof_subscription_created', $subscription->id, $subscription);
                
                return $result;
            
            case 'customer.subscription.updated': 
                // Occurs whenever a subscription changes 
                // (e.g., switching from one plan to another, renewal, or changing the status)
                $subscription = $event->data->object;
                $result = $this->vof_handle_subscription_updated($subscription);
                error_log('VOF Debug: Subscription updated');
                return $result;

            case 'customer.subscription.deleted': 
                // Occurs when a subscription is cancelled or expires
                $subscription = $event->data->object;
                $result = $this->vof_handle_subscription_deleted($subscription);
                error_log('VOF Debug: Subscription deleted');
                return $result;

            case 'invoice.payment_succeeded':
                // Occurs when an invoice payment succeeds
                $invoice = $event->data->object;
                
                // Only handle subscription-related invoices
                if (!empty($invoice->subscription)) {
                    error_log('VOF Debug: Invoice payment succeeded for subscription: ' . $invoice->subscription);
                    
                    // Get the subscription to process the update
                    try {
                        $stripe_config = \VOF\VOF_Core::instance()->vof_get_stripe_config();
                        $stripe = $stripe_config->vof_get_stripe();
                        
                        // Retrieve the subscription to pass to the update handler
                        $subscription = $stripe->subscriptions->retrieve($invoice->subscription);
                        
                        error_log('VOF Debug: Retrieved subscription for invoice payment success: ' . $invoice->subscription);
                        
                        // Handle the subscription update which will trigger benefits refresh
                        $result = $this->vof_handle_subscription_updated($subscription);
                        return $result;
                    } catch (\Exception $e) {
                        error_log('VOF Error: Failed to retrieve subscription for invoice: ' . $e->getMessage());
                        // Return success anyway to avoid retries
                        http_response_code(200);
                        return array('success' => true);
                    }
                }
                
                // For non-subscription invoices, just return success
                http_response_code(200);
                return array('success' => true);

            case 'invoice.payment_failed':
                // Occurs when an invoice payment fails
                $invoice = $event->data->object;
                
                // Only handle subscription-related invoices
                if (!empty($invoice->subscription)) {
                    error_log('VOF Debug: Invoice payment failed for subscription: ' . $invoice->subscription);
                    
                    // Mark subscription as past_due in our database
                    try {
                        $temp_user_meta = \VOF\Utils\Helpers\VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
                        $vof_subs = $this->vof_get_subscription_by_stripe_id($invoice->subscription);
                        
                        if (!empty($vof_subs)) {
                            foreach ($vof_subs as $vof_sub) {
                                // Update status to past_due
                                $update_data = [
                                    'stripe_sub_status' => 'past_due'
                                ];
                                
                                $temp_user_meta->vof_update_post_status($vof_sub['uuid'], $update_data);
                                error_log('VOF Debug: Updated subscription status to past_due');
                            }
                        }
                    } catch (\Exception $e) {
                        error_log('VOF Error: Failed to update subscription status: ' . $e->getMessage());
                    }
                }
                
                // Return success
                http_response_code(200);
                return array('success' => true);
                
            case 'checkout.session.expired':
                // When a checkout session expires
                error_log('VOF Debug: Checkout session expired');
                // We could potentially send a reminder email here
                
                http_response_code(200);
                return array('success' => true);

            default:
                error_log('VOF Debug: Unhandled webhook event type: ' . $event->type);
                
                // For unknown events, we should still return a 200 status
                // to acknowledge receipt and avoid Stripe retrying
                http_response_code(200);
                return array(
                    'success' => true,
                    'message' => 'Event acknowledged but not processed: ' . $event->type
                );
        }
    }

    private function vof_handle_checkout_completed($session) {
        try {
            // Extract metadata
            $uuid = $session->metadata->uuid ?? null;
            $post_id = $session->metadata->post_id ?? null;

            if (!$uuid || !$post_id) {
                throw new \Exception('Missing required metadata');
            }

            // Create payment record
            $payment_data = [
                'amount' => $session->amount_total,
                'currency' => $session->currency,
                'customer' => $session->customer,
                'subscription' => $session->subscription,
                'payment_intent' => $session->payment_intent
            ];

            // Process fulfillment
            $result = $this->fulfillment_handler->vof_process_fulfillment(
                $post_id,
                $payment_data
            );

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            do_action('vof_checkout_completed', $session, $uuid);

            http_response_code(200);
            return array('success' => true);

        } catch (\Exception $e) {
            http_response_code(400);
            return new WP_Error('checkout_error', 
            $e->getMessage(),
            array('status' => 400)
            );
        }
    }

    private function vof_handle_checkout_completed_STUB() {
        try {
            http_response_code(200);
                return array('success' => true);

        } catch (\Exception $e) {
            http_response_code(400);
            return new WP_Error('checkout_error', 
            $e->getMessage(),
            array('status' => 400)
            );
        }  
    }

    private function vof_handle_subscription_created($subscription) {
        try {
            // Get Stripe config instance
            $stripe_config = VOF_Core::instance()->vof_get_stripe_config();
            
            // Get Stripe instance
            $stripe = $stripe_config->vof_get_stripe();

            $uuid = $subscription->metadata->uuid ?? null;
            $wp_user_id = $subscription->metadata->wp_user_id ?? null;
            $post_id = $subscription->metadata->post_id ?? null;
            
            // Debug log
            error_log('VOF Debug: Stripe instance initialized ' . ($stripe ? 'successfully' : 'failed'));
            error_log('VOF Debug: handle_subscription_created with uuid: ' . print_r($uuid, true));
            error_log('VOF Debug: handle_subscription_created with user id: ' . print_r($wp_user_id, true));
            error_log('VOF Debug: handle_subscription_created with post_id: ' . print_r($post_id, true));
            
            // Retrieve expanded subscription with correct expand paths
            try {
                $expanded_subscription = $stripe->subscriptions->retrieve(
                    $subscription->id,
                    [
                        'expand' => [
                            'items.data.price.product',
                            'default_payment_method',
                            'latest_invoice',
                            'pending_setup_intent',
                            // retrieves balance transaction & charge id's
                            'latest_invoice.payment_intent',
                            'latest_invoice.payment_intent.latest_charge',
                            // retrieves stripe's net amount and stripe's fee
                            'latest_invoice.charge',
                            'latest_invoice.charge.balance_transaction',
                        ]
                    ]
                );
                
                error_log('VOF Subscription: Retrieved expanded subscription (w print_r): ' . print_r($expanded_subscription, true));
                
                // Extract required data with careful null checks <-- THIS $subscription_data will be passed to VOF Fulfillment Handler
                $subscription_data = [
                    'product_name'             => $expanded_subscription->items->data[0]->price->product->name ?? null,
                    'product_id'               => $expanded_subscription->items->data[0]->price->product->id ?? null,
                    'price_id'                 => $expanded_subscription->items->data[0]->price->id ?? null,
                    'amount'                   => $expanded_subscription->items->data[0]->price->unit_amount ?? null,
                    'currency'                 => $expanded_subscription->currency ?? null,
                    'customer'                 => $expanded_subscription->customer ?? null,
                    'status'                   => $expanded_subscription->status ?? null,
                    'current_period_end'       => $expanded_subscription->current_period_end ?? null,
                    'current_period_start'     => $expanded_subscription->current_period_start ?? null,

                    // newly added
                    'subscription_id'          => $expanded_subscription->items->data[0]->subscription ?? null,
                    'uuid'                     => $expanded_subscription->metadata->uuid ?? null,
                    'post_id'                  => $expanded_subscription->metadata->post_id ?? null,
                    'interval'                 => $expanded_subscription->items->data[0]->price->recurring->interval ?? null,
                    'stripe_payment_method_id' => $expanded_subscription->default_payment_method->id ?? null,                       // pm_1QsCGlF1Da8bBQoXuayrxeTT of sorts...
                    'product_id'               => $expanded_subscription->items->data[0]->plan->product ?? null,                    // prod_RgJuPNg8SnYaPG of sorts...
                    'lookup_key'               => $expanded_subscription->items->data[0]->price->lookup_key ?? null,
                    'customer_email'           => $expanded_subscription->latest_invoice->customer_email ?? null,
                    'customer_name'            => $expanded_subscription->latest_invoice->customer_name ?? null,
                    'customer_phone'           => $expanded_subscription->latest_invoice->customer_phone ?? null,
                    // 'period_end'               => $expanded_subscription->latest_invoice->period_end ?? null,
                    // 'period_start'             => $expanded_subscription->latest_invoice->period_start ?? null,

                    // newly very newly added
                    'rtcl_transaction_id'      => $expanded_subscription->latest_invoice->charge->id ?? null,                        // ch_xxxx
                    'stripe_fee'               => $expanded_subscription->latest_invoice->charge->balance_transaction->fee ?? null,
                    'stripe_net_amount'        => $expanded_subscription->latest_invoice->charge->balance_transaction->net ?? null,
                    'stripe_intent_id'         => $expanded_subscription->latest_invoice->payment_intent->id ?? null,                // pi_xxxx
                    'stripe_charge_captured'   => $expanded_subscription->latest_invoice->charge->amount_captured ?? null,
                    'is_stripe_captured'       => $expanded_subscription->latest_invoice->charge->captured ? 'yes' : 'no',          // yes / no
                    'wp_user_id'               => $expanded_subscription->metadata->wp_user_id ?? null
                ];
    
                // Get payment method details from either default_payment_method or latest_invoice
                if (isset($expanded_subscription->default_payment_method)) {
                    $payment_method = $expanded_subscription->default_payment_method;
                } elseif (isset($expanded_subscription->latest_invoice->payment_intent->payment_method)) {
                    // Get payment method from latest invoice if available
                    $payment_method = $stripe->paymentMethods->retrieve(
                        $expanded_subscription->latest_invoice->payment_intent->payment_method
                    );
                }
    
                // Add payment method details if we found them
                if (isset($payment_method) && isset($payment_method->card)) {
                    $subscription_data['payment_method'] = [
                        'cc' => [
                            'last4' => $payment_method->card->last4 ?? null,
                            'exp_month' => $payment_method->card->exp_month ?? null,
                            'exp_year' => $payment_method->card->exp_year ?? null
                        ]
                    ];
                }
    
                error_log('VOF Subscription: Retrieved extracted subscription data (w print_r): ' . print_r($subscription_data, true));

                $vof_subscription_handler = \VOF\Includes\Fulfillment\VOF_Subscription_Handler::getInstance();
                $vof_subscription_handler->vof_process_subscription($subscription_data);

                if(is_wp_error($vof_subscription_handler)) {
                    http_response_code(400);
                    return $vof_subscription_handler;
                } else {
                    http_response_code(200);
                    return array('success' => true);
                }
                   
            } catch (\Exception $e) {
                http_response_code(400);
                error_log('VOF Subscription Error: Failed to expand subscription - ' . $e->getMessage());
                return new WP_Error('subscription_creation_error', 
                $e->getMessage(),
                array('status' => 400)
                );
            }
    
        } catch (\Exception $e) {
            http_response_code(400);
            error_log('VOF Subscription Error: ' . $e->getMessage());
            return new WP_Error('subscription_creation_error', 
            $e->getMessage(),
            array('status' => 400)
            );
        }
    }

    /**
     * Handle subscription update webhook from Stripe
     * 
     * @param object $subscription The subscription object from Stripe
     * @return array|WP_Error Success or error response
     */
    private function vof_handle_subscription_updated($subscription) {
        try {
            error_log('VOF Debug: Handling subscription update webhook for ID: ' . $subscription->id);
            
            // Get subscription ID
            $subscription_id = $subscription->id;
            
            // Find corresponding VOF subscription
            $temp_user_meta = \VOF\Utils\Helpers\VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
            $fulfillment_handler = VOF_Fulfillment_Handler::getInstance();
            
            // Get method does not exist - we need to implement this or find the subscription another way
            // Using our own custom implementation to get subscription by stripe_sub_id
            $vof_subs = $this->vof_get_subscription_by_stripe_id($subscription_id);
            
            if (empty($vof_subs)) {
                error_log('VOF Warning: No VOF subscription found with Stripe ID: ' . $subscription_id);
                // Just return success since this might be a subscription created outside our system
                http_response_code(200);
                return array('success' => true);
            }
            
            // Process each matching subscription (in most cases, there should be only one)
            foreach ($vof_subs as $vof_sub) {
                error_log('VOF Debug: Processing subscription update for UUID: ' . $vof_sub['uuid']);
                
                // Check if this is a renewal or other status change
                $is_renewal = false;
                if (isset($subscription->current_period_end) && 
                    isset($vof_sub['stripe_sub_expiry_date']) && 
                    strtotime($vof_sub['stripe_sub_expiry_date']) < $subscription->current_period_end) {
                    $is_renewal = true;
                    error_log('VOF Debug: This is a renewal - old expiry: ' . $vof_sub['stripe_sub_expiry_date'] . 
                             ', new expiry: ' . date('Y-m-d H:i:s', $subscription->current_period_end));
                }

                // Update subscription data in our database
                $update_data = [
                    'stripe_sub_status'     => $subscription->status,
                    'stripe_sub_expiry_date' => date('Y-m-d H:i:s', $subscription->current_period_end)
                ];

                $update_result = $temp_user_meta->vof_update_post_status($vof_sub['uuid'], $update_data);
                if (!$update_result) {
                    error_log('VOF Warning: Failed to update subscription data in VOF database');
                }

                // Check if this is a yearly or other long-term subscription
                $is_long_term = isset($vof_sub['stripe_period_interval']) && 
                               $vof_sub['stripe_period_interval'] !== 'month';
                
                error_log('VOF Debug: Subscription interval: ' . 
                         (isset($vof_sub['stripe_period_interval']) ? $vof_sub['stripe_period_interval'] : 'unknown') . 
                         ', Is long-term: ' . ($is_long_term ? 'yes' : 'no'));
                
                // If this is a renewal of a long-term subscription and interim fulfillment is enabled
                if ($is_renewal && $is_long_term && $fulfillment_handler->vof_is_interim_fulfillment_enabled()) {
                    error_log('VOF Debug: Handling renewal of long-term subscription with interim fulfillment');
                    
                    // Reset the interim fulfillment schedule
                    
                    // Get interval from fulfillment handler
                    $fulfillment_handler = VOF_Fulfillment_Handler::getInstance();
                    $interval = $fulfillment_handler->vof_get_fulfillment_interval();
                    $next_fulfillment = date('Y-m-d H:i:s', strtotime(current_time('mysql')) + $interval);
                    
                    error_log('VOF Debug: Using ' . 
                             ($fulfillment_handler->vof_is_test_interval_enabled() ? '2-minute test interval' : '30-day production interval') . 
                             ' for next fulfillment');
                    
                    $meta_result = $temp_user_meta->vof_update_custom_meta(
                        $vof_sub['uuid'], 
                        'next_interim_fulfillment', 
                        $next_fulfillment
                    );
                    
                    if (!$meta_result) {
                        error_log('VOF Warning: Failed to update next_interim_fulfillment');
                    } else {
                        error_log('VOF Debug: Set next interim fulfillment to: ' . $next_fulfillment);
                    }

                    // Update last fulfillment date to now
                    $last_result = $temp_user_meta->vof_update_custom_meta(
                        $vof_sub['uuid'], 
                        'last_interim_fulfillment', 
                        current_time('mysql')
                    );
                    
                    if (!$last_result) {
                        error_log('VOF Warning: Failed to update last_interim_fulfillment');
                    }
                }
                
                // ELSE IF IT'S JUST A REGULAR MOTTHLY SUBSCRIPTION RTCL SHOULD HANDLE IT... THIS SHOULD NOT APPLY HERE...
                // Manually trigger RTCL subscription update to refresh benefits immediately
                if ($subscription->status === 'active') {
                    // For subscription updates, only pass the status and subscription ID
                    // to avoid modifying the real expiry date unintentionally during interim fulfillment
                    $params = [
                        'sub_id'  => $subscription_id,
                        'status'  => 'active'
                        // Intentionally NOT passing expiry_date to avoid changing the real end date
                    ];
                    
                    $process_result = $fulfillment_handler->vof_process_customer_subscription_updated($params);
                    error_log('VOF Debug: Immediate subscription update result: ' . ($process_result ? 'success' : 'failed'));
                }
            }

            do_action('vof_subscription_updated', $subscription_id, $subscription);

            http_response_code(200);
            return array('success' => true);

        } catch (\Exception $e) {
            error_log('VOF Error: Subscription update failed - ' . $e->getMessage());
            error_log('VOF Error: ' . $e->getTraceAsString());
            
            http_response_code(400);
            return new WP_Error('subscription_update_error', 
                $e->getMessage(),
                array('status' => 400)
            );
        }
    }
    
    /**
     * Get subscription by Stripe subscription ID
     * 
     * @param string $subscription_id The Stripe subscription ID
     * @return array Array of matching subscriptions
     */
    private function vof_get_subscription_by_stripe_id($subscription_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vof_temp_user_meta';
        
        $query = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE stripe_sub_id = %s",
            $subscription_id
        );
        
        error_log('VOF Debug: Running subscription query: ' . $query);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if ($wpdb->last_error) {
            error_log('VOF Error: Database error when looking up subscription: ' . $wpdb->last_error);
            return [];
        }
        
        if (empty($results)) {
            error_log('VOF Debug: No subscriptions found with ID: ' . $subscription_id);
            return [];
        }
        
        error_log('VOF Debug: Found ' . count($results) . ' subscriptions with ID: ' . $subscription_id);
        return $results;
    }

    /**
     * Handle subscription deletion webhook from Stripe
     * 
     * @param object $subscription The subscription object from Stripe
     * @return array|WP_Error Success or error response
     */
    private function vof_handle_subscription_deleted($subscription) {
        try {
            error_log('VOF Debug: Handling subscription deletion webhook for ID: ' . $subscription->id);
            
            // Get subscription ID
            $subscription_id = $subscription->id;

            // Find corresponding VOF subscription(s)
            $temp_user_meta = \VOF\Utils\Helpers\VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
            $vof_subs = $this->vof_get_subscription_by_stripe_id($subscription_id);

            if (empty($vof_subs)) {
                error_log('VOF Warning: No VOF subscription found with Stripe ID: ' . $subscription_id);
                // Just return success since this might be a subscription created outside our system
                http_response_code(200);
                return array('success' => true);
            }
            
            // Process each matching subscription
            foreach ($vof_subs as $vof_sub) {
                error_log('VOF Debug: Processing subscription deletion for UUID: ' . $vof_sub['uuid']);
                
                // Update subscription status
                $update_data = [
                    'stripe_sub_status' => 'cancelled',
                ];

                $update_result = $temp_user_meta->vof_update_post_status($vof_sub['uuid'], $update_data);
                if (!$update_result) {
                    error_log('VOF Warning: Failed to update subscription status to cancelled');
                } else {
                    error_log('VOF Debug: Updated subscription status to cancelled');
                }

                // Clear interim fulfillment schedule by removing both meta entries
                $meta_result1 = $temp_user_meta->vof_delete_custom_meta($vof_sub['uuid'], 'next_interim_fulfillment');
                $meta_result2 = $temp_user_meta->vof_delete_custom_meta($vof_sub['uuid'], 'last_interim_fulfillment');
                
                error_log('VOF Debug: Cleared interim fulfillment meta - next: ' . 
                         ($meta_result1 ? 'success' : 'failed') . ', last: ' . 
                         ($meta_result2 ? 'success' : 'failed'));
                
                // Update the RTCL subscription to cancelled
                $fulfillment_handler = VOF_Fulfillment_Handler::getInstance();
                $params = [
                    'sub_id'  => $subscription_id,
                    'status'  => 'cancelled'
                    // Intentionally NOT passing expiry_date to avoid changing the real end date
                ];
                
                $process_result = $fulfillment_handler->vof_process_customer_subscription_updated($params);
                error_log('VOF Debug: RTCL subscription cancellation result: ' . ($process_result ? 'success' : 'failed'));
            }

            do_action('vof_subscription_cancelled', $subscription_id, $subscription);

            http_response_code(200);
            return array('success' => true);

        } catch (\Exception $e) {
            error_log('VOF Error: Subscription deletion failed - ' . $e->getMessage());
            error_log('VOF Error: ' . $e->getTraceAsString());
            
            http_response_code(400);
            return new WP_Error('subscription_deletion_error', 
                $e->getMessage(),
                array('status' => 400)
            ); 
        }
    }

    private function vof_handle_subscription_updated_OLD($subscription) { // TODO: probably update expiration date every time on renewal for "monthly"
        try {
            do_action('vof_subscription_updated', 
                $subscription->id,
                $subscription
            );

            http_response_code(200);
            return array('success' => true);

        } catch (\Exception $e) {
            http_response_code(400);
            return new WP_Error('subscription_update_error', 
                $e->getMessage(),
                array('status' => 400)
            );
        }
    }

    private function vof_handle_subscription_deleted_OLD($subscription) {
        try {
            do_action('vof_subscription_cancelled', 
                $subscription->id,
                $subscription
            );

            http_response_code(200);
            return array('success' => true);

        } catch (\Exception $e) {
            http_response_code(400);
            return new WP_Error('subscription_deletion_error', 
                $e->getMessage(),
                array('status' => 400)
            ); 
        }
    }

    private function vof_handle_invoice_payment_succeeded($invoice) {
        try {
            if ($invoice->subscription) {
                do_action('vof_subscription_updated', 
                    $invoice->subscription,
                    ['status' => 'active']
                );
            }

            http_response_code(200);
            return array('success' => true);

        } catch (\Exception $e) {
            http_response_code(400);
            return new WP_Error('invoice_error', 
                $e->getMessage(),
                array('status' => 400)
            );
        }
    }

    private function vof_handle_invoice_payment_failed($invoice) {
        try {
            if ($invoice->subscription) {
                do_action('vof_subscription_updated', 
                    $invoice->subscription,
                    ['status' => 'past_due']
                );
            }
            
            http_response_code(200);
            return array('success' => true);

        } catch (\Exception $e) {
            http_response_code(400);
            return new WP_Error('invoice_error', 
                $e->getMessage(), 
                array('success' => true)
            );
        }
    }

    private function vof_log_webhook_error($message) {
        error_log('VOF Webhook Error: ' . $message);
        do_action('vof_webhook_error', $message);
    }
}