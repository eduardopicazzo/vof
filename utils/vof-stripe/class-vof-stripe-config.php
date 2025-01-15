<?php
namespace VOF\Utils\Stripe;

class VOF_Stripe_Config {
    private static $instance = null;
    private $stripe;
    private $publishable_key;
    private $secret_key;
    private $webhook_secret;
    private $is_test_mode;

    public static function vof_get_stripe_config_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->vof_load_stripe_config();
        $this->vof_init_stripe();
    }

    private function vof_load_stripe_config() {
        // Get settings from WordPress options
        $this->is_test_mode = get_option('vof_stripe_test_mode', true);
        
        if ($this->is_test_mode) {
            $this->publishable_key = get_option('vof_stripe_test_publishable_key');
            $this->secret_key = get_option('vof_stripe_test_secret_key');
            $this->webhook_secret = get_option('vof_stripe_test_webhook_secret');
        } else {
            $this->publishable_key = get_option('vof_stripe_live_publishable_key');
            $this->secret_key = get_option('vof_stripe_live_secret_key');
            $this->webhook_secret = get_option('vof_stripe_live_webhook_secret');
        }

        // Log configuration status (not the keys themselves)
        error_log('VOF Debug: Stripe config loaded. Test mode: ' . ($this->is_test_mode ? 'Yes' : 'No'));
    }

    private function vof_init_stripe() {
        try {
            // Initialize Stripe with secret key
            $this->stripe = new \Stripe\StripeClient([
                'api_key' => $this->secret_key,
                'stripe_version' => '2023-10-16' // Use latest API version
            ]);
            
            error_log('VOF Debug: Stripe client initialized successfully');
        } catch (\Exception $e) {
            error_log('VOF Error: Failed to initialize Stripe - ' . $e->getMessage());
        }
    }

    /**
     * Get Stripe client instance
     */
    public function vof_get_stripe() {
        return $this->stripe;
    }

    /**
     * Get publishable key
     */
    public function vof_get_stripe_publishable_key() {
        return $this->publishable_key;
    }

    /**
     * Get webhook secret
     */
    public function vof_get_stripe_webhook_secret() {
        return $this->webhook_secret;
    }

    /**
     * Check if test mode is enabled
     */
    public function vof_is_stripe_test_mode() {
        return $this->is_test_mode;
    }
}