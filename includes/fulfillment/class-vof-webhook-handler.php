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
    }

    public function vof_handle_webhook(\WP_REST_Request $request) {
        try {
            $payload = $request->get_body();
            $sig_header = $request->get_header('stripe-signature');
            
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
                debug_log($event->data->object);
                break;

            case 'checkout.session.expired': // TODO
                // SEND PERIODIC EMAIL TO CUSTOMER
                break;
            case 'customer.subscription.created': //Occurs whenever a customer is signed up for a new plan.
                // return $this->vof_handle_subscription_created($event->data->object); // COMMENTED FOR TESTING
                debug_log($event->data->object);
                break;

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
            do_action('vof_subscription_created', 
                $subscription->customer,
                $subscription,
                ['initial_setup' => true]
            );
            return true;
        } catch (\Exception $e) {
            return new WP_Error('subscription_error', $e->getMessage());
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