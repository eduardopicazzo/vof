<?php
namespace VOF\API;
use VOF\Utils\Helpers\VOF_Temp_User_Meta;
use VOF\VOF_Core;

class VOF_API {
    private $namespace = 'vof/v1';
    private static $instance = null;

    public function __construct() {
        add_action('rest_api_init', function() {
            remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
            add_filter('rest_pre_serve_request', [$this, 'vof_add_cors_headers']);
        }, 15);

        error_log('VOF Debug: API initialized');
    }

    public static function vof_api_get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function vof_register_routes() {
        error_log('VOF Debug: Registering VOF API routes');

        // Test endpoint
        register_rest_route($this->namespace, '/test', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'vof_test_endpoint'],
            'permission_callback' => '__return_true'
        ]);

        // Add new validation endpoint
        register_rest_route($this->namespace, '/checkout/start', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'vof_validate_checkout_start'],
            'permission_callback' => [$this, 'vof_check_permissions'],
            'args' => [
                'uuid' => [
                    'required' => true,
                    'validate_callback' => [$this, 'vof_validate_uuid']
                ],
                'vof_email' => ['required' => true],
                'vof_phone' => ['required' => true],
                'post_id'   => ['required' => true]
            ]
        ]);

        // Get checkout options based on abandoned onboarding flows (passing UUID on query param)
        register_rest_route($this->namespace, '/checkout/options/(?P<uuid>[a-zA-Z0-9-]+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'vof_get_checkout_options'],
            'permission_callback' => [$this, 'vof_check_permissions'],
            'args' => [
                'uuid' => [
                    'required' => true,
                    'validate_callback' => [$this, 'vof_validate_uuid']
                ]
            ]
        ]);

        // Process checkout on user Tier selection
        register_rest_route($this->namespace, '/checkout', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'vof_process_checkout'],
            'permission_callback' => [$this, 'vof_check_permissions'],
            'args' => [
                'uuid' => [
                    'required' => true,
                    'validate_callback' => [$this, 'vof_validate_uuid']
                ]
            ]
        ]);

        // Stripe webhook handler
        register_rest_route($this->namespace, '/webhook', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'vof_handle_webhook'],
            'permission_callback' => [$this, 'vof_validate_webhook']
        ]);

        error_log('VOF Debug: Routes registered successfully');
    }

    public function vof_validate_uuid($uuid) {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
    }

    public function vof_test_endpoint() { // CAN DELETE LATER
        return rest_ensure_response([
            'success' => true,
            'message' => 'VOF API is working!',
            'version' => VOF_VERSION
        ]);
    }

    public function vof_validate_checkout_start($request) {
        try {
            $uuid = $request->get_param('uuid');
            $email = $request->get_param('vof_email');
            $phone = $request->get_param('vof_phone');
            $post_id = $request->get_param('post_id');
            
            // Get temp user meta instance
            $temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
            
            // Get stored user data
            $stored_data = $temp_user_meta->vof_get_temp_user_by_uuid($uuid);
            
            if (!$stored_data) {
                return new \WP_Error(
                    'invalid_uuid',
                    'Invalid or expired UUID',
                    ['status' => 400]
                );
            }
            
            // Validate email and phone match
            if ($stored_data['vof_email'] !== $email || $stored_data['vof_phone'] !== $phone) {
                return new \WP_Error(
                    'validation_failed',
                    'User data validation failed',
                    ['status' => 400]
                );
            }
            
            // Validate post ID
            if ($stored_data['post_id'] != $post_id) {
                return new \WP_Error(
                    'invalid_post',
                    'Invalid post ID',
                    ['status' => 400]
                );
            }
            
            // Get available pricing data based on category
            $tier_slot = $stored_data['vof_tier'];
            $pricing_data = $this->vof_get_checkout_options($tier_slot);
            
            return rest_ensure_response([
                'success' => true,
                'customer_meta' => [
                    'uuid' => $uuid,
                    'email' => $stored_data['vof_email'],
                    'phone' => $stored_data['vof_phone'],
                ],
                'pricing_data' => $pricing_data
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

    private function vof_get_checkout_options($vof_tier) {
        $pricing_data = null;

        switch($vof_tier) {
            case'limit_tiers':
                // TODO: add conditional checking for multiprice...
                $pricing_data = [
                    'is_multi_pricing_on' => false,
                    'tiers' => [ 
                        [ // tier biz
                            'name' => 'biz',
                            'description' => 'Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.',
                            'price' => 44,
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '8 listados/mes',
                                'Publica en la mayoría de categorías excepto autos, inmuebles y maquinaria',
                                '2 destacadores Top/mes',
                                '3 destacadores BumpUp/mes',
                                '2 destacadores Destacados/mes'
                            ],
                            'isRecommended' => false,
                            'isGrayOut' => true,
                        ],
                        [ // tier noise
                            'name' => 'noise',
                            'description' => 'Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local y conectar con su comunidad con toda flexibilidad.',
                            'price' => 444,
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '16 Listados/mes',
                                'Publica en todas las categorías',
                                '5 destacadores Top/mes',
                                '3 destacadores BumpUp/mes',
                                '2 destacadores Destacados/mes'
                            ],
                            'isRecommended' => true,
                            'isGrayOut' => false,
                        ],
                        [ // tier noise+
                            'name' => 'noise+',
                            'description' => 'Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.',
                            'price' => 4444, // hardcode for now since has not called stripe yet (later retrieve from DB)
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '30 listados/mes',
                                'Publica en todas las categorías',
                                '10 destacadores Top/mes',
                                '6 destacadores BumpUp/mes',
                                '6 destacadores Destacados/mes'
                            ],
                            'isRecommended' => false,
                            'isGrayOut' => false,
                        ],
                    ]
                ];
                break;
            default: 
                // TODO: add conditional checking for multiprice...
                $pricing_data = [
                    'is_multi_pricing_on' => false,
                    'tiers' => [ 
                        [ // tier biz, 
                            'name' => 'biz',
                            'description' => 'Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.',
                            'price' => 44,
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '8 listados/mes',
                                'Publica en la mayoría de categorías excepto autos, inmuebles y maquinaria',
                                '2 destacadores Top/mes',
                                '3 destacadores BumpUp/mes',
                                '2 destacadores Destacados/mes'
                            ],
                            'isRecommended' => true,
                            'isGrayOut' => false,
                        ],
                        [ // tier noise
                            'name' => 'noise',
                            'description' => 'Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local y conectar con su comunidad con toda flexibilidad.',
                            'price' => 444,
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '16 Listados/mes',
                                'Publica en todas las categorías',
                                '5 destacadores Top/mes',
                                '3 destacadores BumpUp/mes',
                                '2 destacadores Destacados/mes'
                            ],
                            'isRecommended' => false,
                            'isGrayOut' => false,
                        ],
                        [ // tier noise+
                            'name' => 'noise+',
                            'description' => 'Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.',
                            'price' => 4444, // hardcode for now since has not called stripe yet (later retrieve from DB)
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '30 listados/mes',
                                'Publica en todas las categorías',
                                '10 destacadores Top/mes',
                                '6 destacadores BumpUp/mes',
                                '6 destacadores Destacados/mes'
                            ],
                            'isRecommended' => false,
                            'isGrayOut' => false,
                        ],
                    ]
                ];
                break;
        }

        return $pricing_data;
    }

    public function vof_process_checkout($request) {
        try {
            $uuid = $request->get_param('uuid');
            $tier_selected = $request->get_param('tier_selected');

            // Get user data with request UUID
            $temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
            $user_data = $temp_user_meta->vof_get_temp_user_by_uuid($uuid);

            if (!$user_data) {
                return new \WP_Error(
                    'invalid_uuid',
                    'Invalid or expired UUID',
                    ['status' => 400]
                );
            }

            // Get Stripe config from VOF core
            $stripe_config = VOF_Core::instance()->vof_get_stripe_config();
            if (!$stripe_config) {
                throw new \Exception('Stripe configuration not available');
            }

            // Build checkout session
            $session = $this->vof_build_stripe_checkout($tier_selected, $user_data);

            if (!$session || !$session->url) {
                throw new \Exception('Failed to create checkout session');
            }

            // Return successful response
            return rest_ensure_response([
                'success' => true,
                'data' => [
                    'checkout_url'=> $session->url,
                    'session_id'=> $session->id
                ]
            ]);

        } catch (\Exception $e){
            error_log('VOF API Error: ' . $e->getMessage());
            return new \WP_Error(
                'checkout_error',
                'Error creating checkout session: ' . $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    public function vof_build_stripe_checkout($tier_selected, $user_data) {
        try {
            // Get Stripe instance from config
            $stripe = VOF_Core::instance()->vof_get_stripe_config()->vof_get_stripe();

            // Get proper checkout data for selected tier
            $checkout_data = $this->vof_get_stripe_checkout_data($tier_selected);

            // Create Stripe checkout session
            $session = $stripe->checkout->sessions->create([
                'success_url' => site_url('/my-account?checkout=success&session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => site_url('/my-account?checkout=cancelled'),
                'mode' => 'subscription',
                'customer_email' => $user_data['vof_email'],
                'metadata' => [
                    'uuid' => $user_data['uuid'],
                    'tier' => $tier_selected['name'],
                    'post_id' => $user_data['post_id']
                ],
                'line_items' => [$checkout_data['line_items']],
                'allow_promotion_codes' => true,
                'billing_address_collection' => 'required',
                'phone_number_collection' => [
                    'enabled' => true,
                ],
                // 'customer_creation' => 'always', // available in payment mode only
                'payment_method_types' => ['card'],
                'locale' => 'es'
            ]);

            return $session;

        } catch (\Exception $e) {
            error_log('VOF API Error: Failed to build checkout - ' . $e->getMessage());
            throw $e;
        }
    }

    public function vof_get_stripe_checkout_data($tier_selected) {
        // Normalize tier name by reaplacing + with _plus
        $tier_name = str_replace('+', '_plus', $tier_selected['name']);

        // Get proper price ID and configuration based on tier
        switch($tier_selected['name']) {
            case 'biz':
                return [
                    'line_items' => [
                        'price' => $this->vof_get_price_id('biz'),
                        'quantity' => 1,
                        'adjustable_quantity' => [
                            'enabled' => false
                        ]
                    ],
                    'features' => [
                        'listing_limit' => 8,
                        'top_promoters' => 2,
                        'bump_promoters' => 3,
                        'highlight_promoters' => 2,
                        'category_access' => 'basic'
                    ]
                ];
            
            case 'noise':
                return [
                    'line_items' => [
                        'price' => $this->vof_get_price_id('noise'),
                        'quantity' => 1,
                        'adjustable_quantity' => [
                            'enabled' => false
                        ]
                    ],
                    'features' => [
                        'listing_limit' => 16,
                        'top_promoters' => 5,
                        'bump_promoters' => 3,
                        'highlight_promoters' => 2,
                        'category_access' => 'all'
                    ]
                ];
            
            case 'noise_plus': // changed from noise+ to normalized name noise_plus
                return [
                    'line_items' => [
                        'price' => $this->vof_get_price_id('noise_plus'),
                        'quantity' => 1,
                        'adjustable_quantity' => [
                            'enabled' => false
                        ]
                    ],
                    'features' => [
                        'listing_limit' => 30,
                        'top_promoters' => 10,
                        'bump_promoters' => 6,
                        'highlight_promoters' => 6,
                        'category_access' => 'all',
                        'landing_page' => true
                    ]
                ];
            
            default:
                throw new \Exception('Invalid tier selected');
        }
    }

    // Helper to get price IDs based on environment
    private function vof_get_price_id($tier) {
        $is_test = VOF_Core::instance()->vof_get_stripe_config()->vof_is_stripe_test_mode();
        
        // Price IDs for each environment and tier
        $price_ids = [
            'test' => [
                'biz' => 'price_1QhSfAF1Da8bBQoXOMYG2Kb3',
                'noise' => 'price_1QhSnRF1Da8bBQoXGxUNerFq',
                'noise_plus' => 'price_1QhSsJF1Da8bBQoXzYViJiS2'
            ],
            'live' => [
                'biz' => 'price_1Pa4qHF1Da8bBQoXBrnH9I98',
                'noise' => 'price_1PPtRLF1Da8bBQoXsqXkk1XK',
                'noise_plus' => 'price_1PPtUTF1Da8bBQoXhtr8xZnd'
            ]
        ];

        $env = $is_test ? 'test' : 'live';
        return $price_ids[$env][$tier] ?? throw new \Exception('Invalid tier or environment');
    }

    public function vof_handle_webhook(\WP_REST_Request $request) {
        $webhook_handler = VOF_Webhook_Handler::getInstance();
        return $webhook_handler->vof_handle_webhook($request);
    }
    

    public function vof_handle_webhookOLD($request) { // KEEP FOR REFERENCE (or fallback)
        try {
            $stripe = VOF_Core::instance()->vof_get_stripe_config();
            $endpoint_secret = $stripe->vof_get_stripe_webhook_secret();
            
            $payload = @file_get_contents('php://input');
            $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
            $event = null;

            try {
                $event = \Stripe\Webhook::constructEvent(
                    $payload, $sig_header, $endpoint_secret
                );
            } catch(\UnexpectedValueException $e) {
                error_log('VOF Webhook Error: Invalid payload - ' . $e->getMessage());
                return new \WP_Error('invalid_payload', 'Invalid payload', ['status' => 400]);
            } catch(\Stripe\Exception\SignatureVerificationException $e) {
                error_log('VOF Webhook Error: Invalid signature - ' . $e->getMessage());
                return new \WP_Error('invalid_signature', 'Invalid signature', ['status' => 400]);
            }

            $temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();

            switch ($event->type) {
                case 'checkout.session.completed':
                    $session = $event->data->object;
                    $uuid = $session->metadata->uuid;
                    
                    // Get user data
                    $user_data = $temp_user_meta->vof_get_temp_user_by_uuid($uuid);
                    if (!$user_data) {
                        throw new \Exception('Invalid UUID in webhook: ' . $uuid);
                    }

                    // Create WordPress user
                    $user_id = $this->vof_create_user($user_data);
                    
                    // Update post author and status
                    wp_update_post([
                        'ID' => $user_data['post_id'],
                        'post_author' => $user_id,
                        'post_status' => 'publish'
                    ]);

                    // Update temp user record
                    $temp_user_meta->vof_update_post_status($uuid, 'publish');
                    
                    // Add subscription data
                    update_user_meta($user_id, '_vof_stripe_customer_id', $session->customer);
                    update_user_meta($user_id, '_vof_stripe_subscription_id', $session->subscription);

                    break;

                case 'customer.subscription.updated':
                    // Handle subscription updates
                    break;

                case 'customer.subscription.deleted':
                    // Handle subscription cancellation
                    break;
            }

            return rest_ensure_response([
                'success' => true,
                'message' => 'Webhook processed successfully'
            ]);

        } catch (\Exception $e) {
            error_log('VOF Webhook Error: ' . $e->getMessage());
            return new \WP_Error(
                'webhook_error',
                'Error processing webhook: ' . $e->getMessage(),
                ['status' => 400]
            );
        }
    }

    private function vof_create_user($user_data) { // Maybe KEEP TODO: develop
        // Generate username from email
        $username = explode('@', $user_data['vof_email'])[0];
        $base_username = $username;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }

        // Generate random password
        $password = wp_generate_password();

        // Create user
        $user_id = wp_create_user($username, $password, $user_data['vof_email']);

        if (is_wp_error($user_id)) {
            throw new \Exception('Failed to create user: ' . $user_id->get_error_message());
        }

        // Update user meta
        update_user_meta($user_id, 'vof_phone', $user_data['vof_phone']);
        if (!empty($user_data['vof_whatsapp'])) {
            update_user_meta($user_id, 'vof_whatsapp', $user_data['vof_whatsapp']);
        }

        // Send email with login credentials
        wp_mail(
            $user_data['vof_email'],
            'Your Account Details',
            sprintf(
                'Username: %s\nPassword: %s\nPlease login and change your password.',
                $username,
                $password
            )
        );

        return $user_id;
    }

    public function vof_add_cors_headers($value) { // KEEP TODO: develop
        $origin = get_http_origin();
        if ($origin) {
            header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
            header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
        }
        return $value;
    }

    public function vof_check_permissions() { // KEEP TODO: develop
        return true; // TODO: Implement proper permission checks
    }

    public function vof_validate_webhook() { // KEEP TODO: develop
        return true; // Signature is validated in handler
    }
}