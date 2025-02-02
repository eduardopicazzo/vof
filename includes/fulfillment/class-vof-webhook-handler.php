<?php
namespace VOF\Includes\Fulfillment;

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

    public function vof_handle_webhookOLD(\WP_REST_Request $request) {
        try {
            $payload = $request->get_body();
            $sig_header = $request->get_header('stripe-signature');
            
            error_log('Webhook received - Signature: ' . ($sig_header ? 'exists' : 'missing'));
            error_log('Webhook payload: ' . substr($payload, 0, 100) . '...'); // Log first 100 chars

            // Validate webhook
            $event = $this->vof_validate_and_construct_event($payload, $sig_header);
            if (is_wp_error($event)) {
                return $event;
            }

            // Process webhook event
            $result = $this->vof_process_webhook_event($event);
            if (is_wp_error($result)) {
                return $result;
            }

            return new \WP_REST_Response(['status' => 'success'], 200);

        } catch (\Exception $e) {
            error_log('Webhook Error: ' . $e->getMessage());
            return new \WP_REST_Response([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
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
            case 'checkout.session.completed': //Occurs when a Checkout Session has been successfully completed.
                // return $this->vof_handle_checkout_completed($event->data->object); // COMMENT FOR QUICK TESTING
                return true; // Return true to indicate success

            case 'checkout.session.expired': // TODO
                // SEND PERIODIC EMAIL TO CUSTOMER
                break;
            case 'customer.subscription.created': //Occurs whenever a customer is signed up for a new plan.
                error_log($event->data->object);
                return $this->vof_handle_subscription_created($event->data->object); // COMMENTED FOR TESTING
                // create / sync subscription rtcl <-> stripe (call subscription handler)
                return true; // Return true to indicate success

            case 'customer.subscription.updated': // Occurs whenever a subscription changes (e.g., switching from one plan to another, or changing the status from trial to active).
                return $this->vof_handle_subscription_updated($event->data->object);

            case 'customer.subscription.deleted':
                return $this->vof_handle_subscription_deleted($event->data->object);

            case 'invoice.payment_succeeded':
                return $this->vof_handle_invoice_payment_succeeded($event->data->object);

            case 'invoice.payment_failed':
                return $this->vof_handle_invoice_payment_failed($event->data->object);

            default:
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
            return true;

        } catch (\Exception $e) {
            return new WP_Error('checkout_error', $e->getMessage());
        }
    }

    private function vof_handle_subscription_created($subscription) {
        try {
            // Get Stripe config instance
            $stripe_config = VOF_Core::instance()->vof_get_stripe_config();
            
            // Get Stripe instance
            $stripe = $stripe_config->vof_get_stripe();
            
            // Debug log
            error_log('VOF Debug: Stripe instance initialized ' . ($stripe ? 'successfully' : 'failed'));
            
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
                
                // Extract required data with careful null checks
                $subscription_data = [
                    'product_name' => $expanded_subscription->items->data[0]->price->product->name ?? null,
                    'product_id' => $expanded_subscription->items->data[0]->price->product->id ?? null,
                    'price_id' => $expanded_subscription->items->data[0]->price->id ?? null,
                    'amount' => $expanded_subscription->items->data[0]->price->unit_amount ?? null,
                    'currency' => $expanded_subscription->currency ?? null,
                    'customer' => $expanded_subscription->customer ?? null,
                    'status' => $expanded_subscription->status ?? null,
                    'current_period_end' => $expanded_subscription->current_period_end ?? null
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
    
                error_log('VOF Debug: Extracted subscription data: ' . json_encode($subscription_data));
                error_log('VOF Subscription: Retrieved extracted subscription data (w print_r): ' . print_r($subscription_data, true));
                
                // Dispatch the subscription created action - THIS IS CRUCIAL
                do_action('vof_subscription_created', 
                    $subscription_data,
                    $subscription->customer,
                    $subscription->id
                );
                
                return true;
    
            } catch (\Exception $e) {
                error_log('VOF Subscription Error: Failed to expand subscription - ' . $e->getMessage());
                throw $e;
            }
    
        } catch (\Exception $e) {
            error_log('VOF Subscription Error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function vof_handle_subscription_createdOLDEST($subscription) {
        try {
            // Get Stripe config instance
            $stripe_config = VOF_Core::instance()->vof_get_stripe_config();
            
            // Get Stripe instance
            $stripe = $stripe_config->vof_get_stripe();
            
            // Debug log
            error_log('VOF Debug: Stripe instance initialized ' . ($stripe ? 'successfully' : 'failed'));
            
            // Retrieve expanded subscription
            try {
                $expanded_subscription = $stripe->subscriptions->retrieve(
                    $subscription->id,
                    [
                        'expand' => [
                            'items.data.price.product',
                            'default_payment_method',
                            'latest_invoice.payment_method'
                        ]
                    ]
                );
                
                error_log('VOF Debug: Expanded subscription: ' . print_r($expanded_subscription, true));
                
                // Extract required data with careful null checks
                $subscription_data = [
                    'product_name' => $expanded_subscription->items->data[0]->price->product->name ?? null,
                    'product_id' => $expanded_subscription->items->data[0]->price->product->id ?? null,
                    'price_id' => $expanded_subscription->items->data[0]->price->id ?? null,
                    'amount' => $expanded_subscription->items->data[0]->price->unit_amount ?? null,
                    'currency' => $expanded_subscription->currency ?? null,
                    'customer' => $expanded_subscription->customer ?? null,
                    'status' => $expanded_subscription->status ?? null,
                    'current_period_end' => $expanded_subscription->current_period_end ?? null
                ];
    
                // Debug log subscription data
                error_log('VOF Debug: Subscription data extracted: ' . print_r($subscription_data, true));
    
                // Add payment method details if available
                if (isset($expanded_subscription->default_payment_method)) {
                    $subscription_data['payment_method'] = [
                        'card' => [
                            'last4' => $expanded_subscription->default_payment_method->card->last4 ?? null,
                            'exp_month' => $expanded_subscription->default_payment_method->card->exp_month ?? null,
                            'exp_year' => $expanded_subscription->default_payment_method->card->exp_year ?? null
                        ]
                    ];
                }
    
                return $subscription_data;
    
            } catch (\Exception $e) {
                error_log('VOF Subscription Error: Failed to expand subscription - ' . $e->getMessage());
                throw $e;
            }
    
        } catch (\Exception $e) {
            error_log('VOF Subscription Error: ' . $e->getMessage());
            throw $e;
        }
    }

    // TODO: probably update expiration date every time on renewal for "monthly"
    private function vof_handle_subscription_updated($subscription) {
        try {
            do_action('vof_subscription_updated', 
                $subscription->id,
                $subscription
            );
            return true;
        } catch (\Exception $e) {
            return new WP_Error('subscription_error', $e->getMessage());
        }
    }

    private function vof_handle_subscription_deleted($subscription) {
        try {
            do_action('vof_subscription_cancelled', 
                $subscription->id,
                $subscription
            );
            return true;
        } catch (\Exception $e) {
            return new WP_Error('subscription_error', $e->getMessage());
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
            return true;
        } catch (\Exception $e) {
            return new WP_Error('invoice_error', $e->getMessage());
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
            return true;
        } catch (\Exception $e) {
            return new WP_Error('invoice_error', $e->getMessage());
        }
    }

    private function vof_log_webhook_error($message) {
        error_log('VOF Webhook Error: ' . $message);
        do_action('vof_webhook_error', $message);
    }
}