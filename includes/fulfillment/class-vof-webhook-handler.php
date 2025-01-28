<?php
namespace VOF\Includes\Fulfillment;

use Rtcl\Helpers\Functions;
use VOF\Includes\VOF_Core;
use WP_Error;
use Stripe\Event;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class VOF_Webhook_Handler {

    private static $instance = null;
    private $secret;
    private $fulfillment_handler;
    private $subscription_handler;

    // Webhook verification constants
    const VALIDATION_SUCCEEDED = 'succeeded';
    const VALIDATION_FAILED_EMPTY_HEADERS = 'empty_headers';
    const VALIDATION_FAILED_EMPTY_BODY = 'empty_body';
    const VALIDATION_FAILED_SIGNATURE_INVALID = 'signature_invalid';
    const VALIDATION_FAILED_TIMESTAMP_MISMATCH = 'timestamp_mismatch';
    const VALIDATION_FAILED_SIGNATURE_MISMATCH = 'signature_mismatch';

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->secret = get_option('vof_stripe_webhook_secret');
        $this->fulfillment_handler = VOF_Fulfillment_Handler::getInstance();
        $this->subscription_handler = VOF_Subscription_Handler::getInstance();

        // Register webhook endpoint
        add_action('rest_api_init', [$this, 'vof_register_webhook_endpoint']);
    }

    /**
     * Register webhook endpoint
     */
    public function vof_register_webhook_endpoint() {
        register_rest_route('vof/v1', '/webhook/stripe', [
            'methods' => 'POST',
            'callback' => [$this, 'vof_handle_webhook'],
            'permission_callback' => '__return_true'
        ]);
    }

    /**
     * Main webhook handler
     */
    public function vof_handle_webhook(\WP_REST_Request $request) {
        try {
            $payload = $request->get_body();
            $sig_header = $request->get_header('stripe-signature');

            // Validate webhook
            $validation_result = $this->vof_validate_webhook($sig_header, $payload);
            if ($validation_result !== self::VALIDATION_SUCCEEDED) {
                $this->vof_log_webhook_error('Webhook validation failed: ' . $validation_result);
                return new \WP_REST_Response(['status' => 'invalid'], 400);
            }

            $event = Event::constructFrom(json_decode($payload, true));
            
            // Process the event
            $processed = $this->vof_process_webhook_event($event);
            
            if (is_wp_error($processed)) {
                $this->vof_log_webhook_error($processed->get_error_message());
                return new \WP_REST_Response(['status' => 'error'], 400);
            }

            return new \WP_REST_Response(['status' => 'success'], 200);

        } catch (\Exception $e) {
            $this->vof_log_webhook_error($e->getMessage());
            return new \WP_REST_Response(['status' => 'error'], 400);
        }
    }

    /**
     * Process webhook event
     */
    private function vof_process_webhook_event($event) {
        switch ($event->type) {
            case 'checkout.session.completed':
                return $this->vof_handle_checkout_completed($event->data->object);

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                return $this->vof_handle_subscription_update($event->data->object);

            case 'customer.subscription.deleted':
                return $this->vof_handle_subscription_cancelled($event->data->object);

            case 'invoice.payment_succeeded':
                return $this->vof_handle_invoice_payment($event->data->object);

            case 'invoice.payment_failed':
                return $this->vof_handle_invoice_failed($event->data->object);

            default:
                // Log unhandled event type
                $this->vof_log_webhook_error('Unhandled event type: ' . $event->type);
                return new WP_Error('unhandled_event', 'Unhandled event type');
        }
    }

    /**
     * Handle checkout.session.completed
     */
    private function vof_handle_checkout_completed($session) {
        try {
            // Get VOF order data
            $order_id = $session->metadata->vof_order_id ?? null;
            if (!$order_id) {
                throw new \Exception('No VOF order ID found in session metadata');
            }

            // Process subscription if exists
            if ($session->subscription) {
                $this->vof_process_subscription_creation(
                    $session->subscription,
                    $session->customer,
                    $order_id
                );
            }

            // Trigger fulfillment
            $payment_data = [
                'transaction_id' => $session->payment_intent,
                'amount' => $session->amount_total,
                'currency' => $session->currency,
                'price_id' => $session->metadata->price_id ?? null
            ];

            return $this->fulfillment_handler->vof_process_fulfillment(
                $order_id, 
                $payment_data
            );

        } catch (\Exception $e) {
            return new WP_Error('checkout_processing_error', $e->getMessage());
        }
    }

    /**
     * Handle subscription updates
     */
    private function vof_handle_subscription_update($subscription) {
        try {
            return $this->subscription_handler->vof_handle_subscription_updated(
                $subscription->id,
                [
                    'status' => $subscription->status,
                    'current_period_end' => $subscription->current_period_end,
                    'cancel_at_period_end' => $subscription->cancel_at_period_end
                ]
            );
        } catch (\Exception $e) {
            return new WP_Error('subscription_update_error', $e->getMessage());
        }
    }

    /**
     * Handle subscription cancellation
     */
    private function vof_handle_subscription_cancelled($subscription) {
        try {
            $reason = $subscription->cancellation_details->reason ?? 'Unknown';
            
            return $this->subscription_handler->vof_handle_subscription_cancelled(
                $subscription->id,
                $reason
            );
        } catch (\Exception $e) {
            return new WP_Error('subscription_cancel_error', $e->getMessage());
        }
    }

    /**
     * Handle successful invoice payment
     */
    private function vof_handle_invoice_payment($invoice) {
        try {
            if ($invoice->subscription) {
                // Update subscription status
                return $this->subscription_handler->vof_handle_subscription_updated(
                    $invoice->subscription,
                    [
                        'status' => 'active',
                        'current_period_end' => $invoice->period_end
                    ]
                );
            }
            return true;
        } catch (\Exception $e) {
            return new WP_Error('invoice_processing_error', $e->getMessage());
        }
    }

    /**
     * Handle failed invoice payment
     */
    private function vof_handle_invoice_failed($invoice) {
        try {
            if ($invoice->subscription) {
                return $this->subscription_handler->vof_handle_subscription_updated(
                    $invoice->subscription,
                    [
                        'status' => 'past_due'
                    ]
                );
            }
            return true;
        } catch (\Exception $e) {
            return new WP_Error('invoice_processing_error', $e->getMessage());
        }
    }

    /**
     * Process new subscription creation
     */
    private function vof_process_subscription_creation($subscription_id, $customer_id, $order_id) {
        try {
            // Get Stripe subscription
            $stripe = \Stripe\Stripe::setApiKey(get_option('vof_stripe_secret_key'));
            $subscription = \Stripe\Subscription::retrieve($subscription_id);

            // Get WordPress user ID from customer metadata
            $user_id = $this->vof_get_user_id_from_customer($customer_id);
            if (!$user_id) {
                throw new \Exception('No WordPress user found for Stripe customer');
            }

            // Process subscription
            return $this->subscription_handler->vof_process_subscription(
                $user_id,
                $subscription->toArray(),
                [
                    'vof_order_id' => $order_id,
                    'customer_id' => $customer_id
                ]
            );

        } catch (\Exception $e) {
            return new WP_Error('subscription_creation_error', $e->getMessage());
        }
    }

    /**
     * Validate webhook signature
     */
    private function vof_validate_webhook($sig_header, $payload) {
        if (empty($sig_header)) {
            return self::VALIDATION_FAILED_EMPTY_HEADERS;
        }

        if (empty($payload)) {
            return self::VALIDATION_FAILED_EMPTY_BODY;
        }

        try {
            Webhook::constructEvent(
                $payload, $sig_header, $this->secret
            );
            return self::VALIDATION_SUCCEEDED;
        } catch (SignatureVerificationException $e) {
            return self::VALIDATION_FAILED_SIGNATURE_MISMATCH;
        } catch (\Exception $e) {
            return self::VALIDATION_FAILED_SIGNATURE_INVALID;
        }
    }

    /**
     * Get WordPress user ID from Stripe customer
     */
    private function vof_get_user_id_from_customer($customer_id) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}vof_customer_meta 
            WHERE stripe_customer_id = %s",
            $customer_id
        ));
    }

    /**
     * Log webhook error
     */
    private function vof_log_webhook_error($message) {
        error_log('VOF Webhook Error: ' . $message);
        
        // Maybe store in database for admin viewing
        do_action('vof_webhook_error', $message);
    }
}