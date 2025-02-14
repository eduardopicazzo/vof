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

    private function vof_process_webhook_event($event) {
        
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
                
                return $result;
            
            // case 'checkout.session.expired':      // TODO (maybe)
                    // SEND PERIODIC EMAIL TO CUSTOMER
                // return true; // Return true to indicate success
                // break;
            // case 'customer.subscription.updated': // CURRENTLY NOT IN USE
                // Occurs whenever a subscription changes 
                // (e.g., switching from one plan to another, or changing the status from trial to active).
                // return $this->vof_handle_subscription_updated($event->data->object);

            // case 'customer.subscription.deleted': // CURRENTLY NOT IN USE
                // return $this->vof_handle_subscription_deleted($event->data->object);

            // case 'invoice.payment_succeeded':     // CURRENTLY NOT IN USE
                // return $this->vof_handle_invoice_payment_succeeded($event->data->object);

            // case 'invoice.payment_failed':        // CURRENTLY NOT IN USE
                // return $this->vof_handle_invoice_payment_failed($event->data->object);

            default:
                http_response_code(400);
                return new WP_Error(
                    'unhandled_event',
                    'Unhandled webhook event type: ' . $event->type,
                    ['status' => 400]
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
            // $post_id = $subscription->metadata->post_id ?? null;
            
            // Debug log
            error_log('VOF Debug: Stripe instance initialized ' . ($stripe ? 'successfully' : 'failed'));
            error_log('VOF Debug: handle_subscription_created with uuid: ' . print_r($uuid, true));
            // error_log('VOF Debug: handle_subscription_created with post_id: ' . print_r($post_id, true));
            
            // Retrieve expanded subscription with correct expand paths
            try {
                $expanded_subscription = $stripe->subscriptions->retrieve(
                    $subscription->id,
                    [
                        'expand' => [
                            'items.data.price.product',
                            'default_payment_method',
                            'latest_invoice',
                            'pending_setup_intent'
                        ]
                    ]
                );
                
                error_log('VOF Subscription: Retrieved expanded subscription (w print_r): ' . print_r($expanded_subscription, true));
                
                // Extract required data with careful null checks <-- THIS $subscription_data will be passed to VOF Fulfillment Handler
                $subscription_data = [
                    'product_name' => $expanded_subscription->items->data[0]->price->product->name ?? null,
                    'product_id' => $expanded_subscription->items->data[0]->price->product->id ?? null,
                    'price_id' => $expanded_subscription->items->data[0]->price->id ?? null,
                    'amount' => $expanded_subscription->items->data[0]->price->unit_amount ?? null,
                    'currency' => $expanded_subscription->currency ?? null,
                    'customer' => $expanded_subscription->customer ?? null,
                    'status' => $expanded_subscription->status ?? null,
                    'current_period_end' => $expanded_subscription->current_period_end ?? null,
                    // newly added
                    'subscription_id' => $expanded_subscription->items->data[0]->subscription ?? null,
                    'uuid' => $expanded_subscription->metadata->uuid ?? null,
                    'post_id' => $expanded_subscription->metadata->post_id ?? null,
                    'interval' => $expanded_subscription->items->data[0]->price->recurring->interval ?? null,
                    'stripe_payment_method' => $expanded_subscription->default_payment_method->id ?? null,  // pm_1QsCGlF1Da8bBQoXuayrxeTT of sorts...
                    'product_id' => $expanded_subscription->items->data[0]->plan->product ?? null,          // prod_RgJuPNg8SnYaPG of sorts...
                    'lookup_key' => $expanded_subscription->items->data[0]->price->lookup_key ?? null,
                    'customer_email' => $expanded_subscription->latest_invoice->customer_email ?? null,
                    'customer_name' => $expanded_subscription->latest_invoice->customer_name ?? null,
                    'customer_phone' => $expanded_subscription->latest_invoice->customer_phone ?? null,
                    'period_end' => $expanded_subscription->latest_invoice->period_end ?? null,
                    'period_start' => $expanded_subscription->latest_invoice->period_start ?? null
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
                
                // Dispatch the subscription created action - THIS IS CRUCIAL
                // do_action('vof_subscription_created', 
                //     $subscription_data,
                //     $subscription->customer,
                //     $subscription->id
                // );

                $vof_subscription_handler = \VOF\Includes\Fulfillment\VOF_Subscription_Handler::getInstance();
                $vof_subscription_handler->vof_process_subscription( $subscription_data, $subscription->customer, $subscription->id );

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

    private function vof_handle_subscription_updated($subscription) { // TODO: probably update expiration date every time on renewal for "monthly"
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

    private function vof_handle_subscription_deleted($subscription) {
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