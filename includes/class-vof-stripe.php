<?php
namespace VOF;

if (!defined('ABSPATH')) exit;

class VOF_Stripe {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_webhook_endpoint']);
    }

    public static function create_checkout_session($args) {
        try {
            $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
            
            return $stripe->checkout->sessions->create([
                'payment_method_types' => ['card'],
                'mode' => $args['mode'] ?? 'subscription',
                'success_url' => $args['success_url'],
                'cancel_url' => $args['cancel_url'],
                'metadata' => [
                    'listing_id' => $args['listing_id']
                ]
            ]);
        } catch (\Exception $e) {
            return new \WP_Error('stripe_error', $e->getMessage());
        }
    }

    public function register_webhook_endpoint() {
        register_rest_route('vof/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true'
        ]);
    }
} 