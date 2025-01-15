<?php
namespace VOF\API;

class VOF_API {
    /**
     * @var string The namespace for our REST API endpoints
     */
    private $namespace = 'vof/v1';

    /**
     * @var VOF_API|null The single instance of this class
     */
    private static $instance = null;

    /**
     * Initialize the API
     */
    public function __construct() {
        // Add CORS headers for API requests
        add_action('rest_api_init', function() {
            remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
            add_filter('rest_pre_serve_request', [$this, 'vof_add_cors_headers']);
        }, 15);

        error_log('VOF Debug: API initialized');
    }

    /**
     * Get the singleton instance
     */
    public static function vof_get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register API routes
     */
    public function vof_register_routes() {
        error_log('VOF Debug: Registering VOF API routes with namespace: ' . $this->namespace);

        // Test endpoint
        register_rest_route($this->namespace, '/test', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'vof_test_endpoint'],
                'permission_callback' => '__return_true'
            ]
        ]);

        // Checkout endpoint - GET available subscription tiers
        register_rest_route($this->namespace, '/checkout', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'vof_get_checkout_options'],
                'permission_callback' => [$this, 'vof_check_permissions'],
                'args'               => [
                    'listing_id' => [
                        'required'          => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        }
                    ]
                ]
            ]
        ]);

        // Checkout endpoint - POST initiate checkout
        register_rest_route($this->namespace, '/checkout', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'vof_process_checkout'],
                'permission_callback' => [$this, 'vof_check_permissions'],
                'args'               => [
                    'listing_id' => [
                        'required'          => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        }
                    ],
                    'tier_id' => [
                        'required'          => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        }
                    ]
                ]
            ]
        ]);

        // Webhook endpoint
        register_rest_route($this->namespace, '/webhook', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'vof_handle_webhook'],
                'permission_callback' => [$this, 'vof_validate_webhook']
            ]
        ]);

        error_log('VOF Debug: VOF API routes registered successfully');
    }

    /**
     * Test endpoint callback
     */
    public function vof_test_endpoint() {
        return rest_ensure_response([
            'success' => true,
            'message' => 'VOF API is working!',
            'version' => VOF_VERSION
        ]);
    }

    /**
     * Get available checkout options
     */
    public function vof_get_checkout_options($request) {
        try {
            $listing_id = $request->get_param('listing_id');
            
            return rest_ensure_response([
                'success' => true,
                'data'    => [
                    'listing_id' => $listing_id,
                    'tiers'     => []
                ]
            ]);

        } catch (\Exception $e) {
            error_log('VOF API Error: ' . $e->getMessage());
            return new \WP_Error(
                'server_error',
                'An error occurred processing your request',
                ['status' => 500]
            );
        }
    }

    /**
     * Process checkout request
     */
    public function vof_process_checkout($request) {
        try {
            $listing_id = $request->get_param('listing_id');
            $tier_id = $request->get_param('tier_id');

            // For now return dummy response
            return rest_ensure_response([
                'success' => true,
                'data'    => [
                    'checkout_url' => 'https://checkout.stripe.com/dummy-session'
                ]
            ]);

        } catch (\Exception $e) {
            error_log('VOF API Error: ' . $e->getMessage());
            return new \WP_Error(
                'server_error',
                'An error occurred processing your request',
                ['status' => 500]
            );
        }
    }

    /**
     * Handle Stripe webhook
     */
    public function vof_handle_webhook($request) {
        try {
            return rest_ensure_response([
                'success' => true,
                'message' => 'Webhook processed successfully'
            ]);

        } catch (\Exception $e) {
            error_log('VOF Webhook Error: ' . $e->getMessage());
            return new \WP_Error(
                'webhook_error',
                'Error processing webhook',
                ['status' => 400]
            );
        }
    }

    /**
     * Add CORS headers
     */
    public function vof_add_cors_headers($value) {
        $origin = get_http_origin();
        if ($origin) {
            header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
            header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
        }
        return $value;
    }

    /**
     * Check API permissions
     */
    public function vof_check_permissions() {
        return true;
    }

    /**
     * Validate webhook request
     */
    public function vof_validate_webhook() {
        return true;
    }
}