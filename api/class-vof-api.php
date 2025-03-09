<?php
namespace VOF\API;
use VOF\Utils\Helpers\VOF_Temp_User_Meta;
use VOF\VOF_Core;
use VOF\Includes\Fulfillment\VOF_Webhook_Handler;

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
        register_rest_route($this->namespace, '/webhook/gateway/stripe', [
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
            
            // Get the category ID from stored data
            $posted_category = $stored_data['post_parent_cat'];
            error_log("VOF Debug API: Post category for tier limits: " . print_r($posted_category, true));
            
            // Get pricing modal settings helper
            $pricing_settings = new \VOF\Utils\PricingModal\VOF_Pricing_Modal_Settings();
            
            // Get base pricing data from admin settings
            $pricing_config = get_option('vof_pricing_modal_config', $pricing_settings->vof_get_default_pricing_config());
            
            // Combine monthly and yearly tiers 
            $all_tiers = [];
            
            // Add monthly tiers
            if (isset($pricing_config['tiersMonthly'])) {
                foreach ($pricing_config['tiersMonthly'] as $tier) {
                    $tier['interval'] = 'month';
                    $all_tiers[] = $tier;
                }
            }
            
            // Add yearly tiers if multi-pricing is enabled
            if (isset($pricing_config['isMultiPricingOn']) && $pricing_config['isMultiPricingOn'] && isset($pricing_config['tiersYearly'])) {
                foreach ($pricing_config['tiersYearly'] as $tier) {
                    $tier['interval'] = 'year';
                    $all_tiers[] = $tier;
                }
            }
            
            // Apply tier limitations based on category compatibility
            $tier_limits_meta = $pricing_settings->vof_apply_tier_limits($all_tiers, $posted_category);
            
            error_log("VOF Debug API: Tier limits applied: " . print_r($tier_limits_meta, true));
            
            return rest_ensure_response([
                'success' => true,
                'customer_meta' => [
                    'uuid' => $uuid,
                    'email' => $stored_data['vof_email'],
                    'phone' => $stored_data['vof_phone'],
                ],
                'post_category_data' => $posted_category,
                'pricing_data' => [
                    'is_multi_pricing_on' => isset($pricing_config['isMultiPricingOn']) ? $pricing_config['isMultiPricingOn'] : false,
                    'tier_limits' => $tier_limits_meta
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

    private function vof_get_checkout_options($vof_tier) {
        $pricing_data = null;

        switch($vof_tier) {
            case'limit_tiers':
                $pricing_data = [
                    'is_multi_pricing_on' => true,
                    'monthly_tiers' => [ 
                        [ // tier biz (monthly)
                            // 'name' => 'biz',
                            // 'name' => 'biz Test Subscription Handler', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'name' => 'biz Multiprice Test', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'description' => 'Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.',
                            'price' => 44, // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
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
                            'interval' => 'month', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx',
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx'
                        ],
                        [ // tier noise (monthly)
                            // 'name' => 'noise',
                            // 'name' => 'noise Test Subscription Handler', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'name' => 'noise Multiprice Test', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'description' => 'Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local y conectar con su comunidad con toda flexibilidad.',
                            'price' => 444, // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
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
                            'interval' => 'month', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx',
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx'
                        ],
                        [ // tier noise+ (monthly)
                            // 'name' => 'noise+',
                            // 'name' => 'noise+ Test Subscription Handler', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'name' => 'noise+ Multiprice Test', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'description' => 'Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.',
                            'price' => 4444, // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
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
                            'interval' => 'month', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx',
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx'
                        ],
                    ],
                    'yearly_tiers' => [ 
                        [ // tier biz (yearly)
                            // 'name' => 'biz',
                            // 'name' => 'biz Test Subscription Handler', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'name' => 'biz Multiprice Test', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'description' => 'Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.',
                            'price' => 440, // * 10 2 months free retrieved pre-emptively from admin dashboard (in the future) via stripe api call
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
                            'interval' => 'year', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx',
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx'
                        ],
                        [ // tier noise (yearly)
                            // 'name' => 'noise',
                            // 'name' => 'noise Test Subscription Handler', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'name' => 'noise Multiprice Test', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'description' => 'Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local y conectar con su comunidad con toda flexibilidad.',
                            'price' => 4440, // * 10 2 months free  // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
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
                            'interval' => 'year', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx',
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx'
                        ],
                        [ // tier noise+ (yearly)
                            // 'name' => 'noise+',
                            // 'name' => 'noise+ Test Subscription Handler', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'name' => 'noise+ Multiprice Test', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'description' => 'Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.',
                            'price' => 44400,  // * 10 2 months free // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
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
                            'interval' => 'year', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx',
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx'
                        ],
                    ]
                ];
                break;
            default: 
                $pricing_data = [
                    'is_multi_pricing_on' => true,
                    'monthly_tiers' => [ 
                        [ // tier biz (monthly) 
                            // 'name' => 'biz',
                            // 'name' => 'biz Test Subscription Handler', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'name' => 'biz Multiprice Test', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'description' => 'Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.',
                            'price' => 44, // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
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
                            'interval' => 'month', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx' // set by user via admin dashboard (in the future)  
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx // set by user via admin dashboard (in the future) 
                        ],
                        [ // tier noise (monthly)
                            // 'name' => 'noise',
                            // 'name' => 'noise Test Subscription Handler',  // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'name' => 'noise Multiprice Test',  // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'description' => 'Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local y conectar con su comunidad con toda flexibilidad.',
                            'price' => 444,  // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
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
                            'interval' =>'month', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx' // set by user via admin dashboard (in the future) 
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx  // set by user via admin dashboard (in the future) 
                        ],
                        [ // tier noise+ (monthly)
                            // 'name' => 'noise+',
                            // 'name' => 'noise+ Test Subscription Handler',
                            'name' => 'noise+ Multiprice Test',
                            'description' => 'Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.',
                            'price' => 4444, // hardcode for now since has not called stripe yet (later retrieve from DB)   // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
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
                            'interval' =>'month', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx' // set by user via admin dashboard (in the future) 
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx  // set by user via admin dashboard (in the future) 
                        ],
                    ],
                    'yearly_tiers' => [ 
                        [ // tier biz (yearly)
                            // 'name' => 'biz',
                            // 'name' => 'biz Test Subscription Handler', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'name' => 'biz Multiprice Test', // retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'description' => 'Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.',
                            'price' => 440, // x10 2 months free; Retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '8 listados/mes',
                                'Publica en la mayoría de categorías excepto autos, inmuebles y maquinaria',
                                '2 destacadores Top/mes',
                                '3 destacadores BumpUp/mes',
                                '2 destacadores Destacados/mes'
                            ],
                            'isRecommended' => false,
                            'isGrayOut' => false,
                            'interval' => 'year', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx',
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx'
                        ],
                        [ // tier noise (yearly)
                            // 'name' => 'noise',
                            // 'name' => 'noise Test Subscription Handler', // Retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'name' => 'noise Multiprice Test', // Retrieved pre-emptively from admin dashboard (in the future) via stripe api call
                            'description' => 'Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local y conectar con su comunidad con toda flexibilidad.',
                            'price' => 4440, // * 10 2 months free; Retrieved pre-emptively from admin dashboard (in the future) via stripe api call
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
                            'interval' => 'year', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx',
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx'
                        ],
                        [ // tier noise+ (yearly)
                            // 'name' => 'noise+',
                            // 'name' => 'noise+ Test Subscription Handler',
                            'name' => 'noise+ Multiprice Test',
                            'description' => 'Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.',
                            'price' => 44400,  // * 10 2 months free
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
                            'interval' => 'year', // set by user via admin dashboard (in the future) -> explicit interval to be passed on to stripe (will be use as function arg)
                            // 'stripe_price_id' => 'price_xxxxxxxxx',
                            // 'stripe_lookup_key' => 'xxxxxxxxxxxxx'
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
            // debug
            if ($stripe) {
                error_log('VOF DEBUG API: Stripe Instance OK ' . print_r($stripe, true));
            }

            // Get proper checkout data for selected tier
            $line_items = $this->vof_get_stripe_line_items($tier_selected);
            error_log('VOF DEBUG API: Line Items (OUTER)'. print_r($line_items, true));

            // Add interval metadata
            $interval = isset($tier_selected['interval']) ? $tier_selected['interval']: 'month';

            // Create Stripe checkout session
            $session = $stripe->checkout->sessions->create([
                'success_url' => site_url('/my-account?checkout=success&session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => site_url('/my-account?checkout=cancelled'),
                'mode' => 'subscription', // TO DO: can change this to handle one-time payments
                'customer_email' => $user_data['vof_email'],
                'subscription_data' => [ // test iff uuid persists
                    'metadata' => [
                        'uuid'       => $user_data['uuid'],
                        'tier'       => $tier_selected['name'],
                        'post_id'    => $user_data['post_id'],
                        'phone'      => $user_data['vof_phone'],
                        'wp_user_id' => $user_data['true_user_id'],
                        'interval'   => $interval
                    ],
                ],
                'metadata' => [
                    'uuid'       => $user_data['uuid'],
                    'tier'       => $tier_selected['name'],
                    'post_id'    => $user_data['post_id'],
                    'phone'      => $user_data['vof_phone'],
                    'wp_user_id' => $user_data['true_user_id'],
                    'interval'   => $interval
                ],
                'line_items' => [$line_items['line_items']],
                'allow_promotion_codes' => true,
                // 'payment_method_types' => ['card', 'apple_pay', 'google_pay'],
                'payment_method_types' => ['card'],
                'locale' => 'es'
            ]);

            return $session;

        } catch (\Exception $e) {
            error_log('VOF API Error: Failed to build checkout - ' . $e->getMessage());
            throw $e;
        }
    }

    public function vof_get_stripe_line_items($tier_selected) {
        error_log('VOF API Debug: Getting Stripe line items for tier - '. print_r($tier_selected, true));

        $is_test_env = VOF_Core::instance()->vof_get_stripe_config()->vof_is_stripe_test_mode();
        error_log('VOF API Debug: Getting Stripe stripe test environment for tier - '. print_r($is_test_env, true));
        
        if($is_test_env) {
            $lookup_key = $tier_selected['sLookupKeyLive'];
            error_log('VOF API Debug: Test lookup key - '. print_r($lookup_key, true));
        } else {
            $lookup_key = $tier_selected['sLookupKeyTest'];
            error_log('VOF API Debug: Live lookup key - '. print_r($lookup_key, true));
        }
        
        if (!$is_test_env) {
            $price_id = $tier_selected['sPriceIdLive'];
            error_log('VOF API Debug: Live Price Id - '. print_r($price_id, true));
        }  else {
            $price_id = $tier_selected['sPriceIdTest'];
            error_log('VOF API Debug: Test Price Id - '. print_r($price_id, true));
        }
        
        error_log('VOF API Debug: Final Price_id is: '. print_r($price_id, true));
        if (!$price_id || empty($price_id)) {
            $price_id = $this->vof_get_price_id_by_name_or_lookup_key($tier_selected['name'], $tier_selected['interval'], $is_test_env, $lookup_key);
        }

        $line_items = [
            'line_items' => [
                'price' => $price_id,
                'quantity' => 1,
                'adjustable_quantity' => [
                    'enabled' => false
                ]
            ]
        ];

        error_log('VOF API Debug: Retrieving line items (inner) with: '. print_r($line_items, true));

        return $line_items;
    }

    /**
     * Get a Stripe price ID by product name, interval, and environment
     * 
     * @param string $pricing_tier_name The name of the pricing tier
     * @param string $interval The billing interval ('month' or 'year')
     * @param bool $is_test_env Whether to use test or live environment
     * @param string $lookup_key Optional lookup key to find the price
     * @return string The Stripe price ID
     * @throws \Exception If the price cannot be found
     */
    public function vof_get_price_id_by_name_or_lookup_key($pricing_tier_name, $interval, $is_test_env, $lookup_key = '') {
        $stripe = VOF_Core::instance()->vof_get_stripe_config()->vof_get_stripe();

        try {
            // If lookup key is provided, use it to find the price directly
            if (!empty($lookup_key)) {
                error_log("VOF Debug: Attempting to find price with lookup key: {$lookup_key}");

                $prices = $stripe->prices->all([
                    'lookup_keys' => [$lookup_key],
                    'active' => true,
                    'expand' => ['data.product']
                ]);

                if (!empty($prices->data)) {
                    foreach ($prices->data as $price) {
                        // Verify this price belongs to the expected product and has the correct interval
                        if (isset($price->recurring) && 
                            $price->recurring->interval === $interval && 
                            $price->active) {
                            
                            // Check if this price matches our test/live environment
                            // livemode = true means it's in the live environment, false means test
                            $is_live_price = $price->livemode;
                            // The correct comparison should be:
                            // - If we're in test env ($is_test_env is true), we want test prices (!$is_live_price is true)
                            // - If we're in live env ($is_test_env is false), we want live prices ($is_live_price is true)
                            $price_matches_env = ($is_test_env) ? (!$is_live_price) : $is_live_price;
                            
                            if ($price_matches_env) {
                                error_log("VOF Debug: Found price ID {$price->id} using lookup key {$lookup_key}");
                                return $price->id;
                            } else {
                                error_log("VOF Debug: Found price ID {$price->id} but environment mismatch. Price is " . 
                                    ($is_live_price ? "live" : "test") . ", but we need " . 
                                    ($is_test_env ? "test" : "live"));
                            }
                        }
                    }
                }

                error_log("VOF Debug: Could not find price with lookup key {$lookup_key} matching environment and interval");
            }

            // If we get here, either no lookup key was provided or it didn't find a match
            // Fall back to searching by product name

            // Step 1: Retrieve products by name
            $products = $stripe->products->all([
                'limit' => 100,
                'active' => true
            ]);
        
            $productId = null;
        
            foreach ($products->data as $product) {
                if ($product->name === $pricing_tier_name) {
                    $productId = $product->id;
                    error_log("VOF Debug: Found product ID {$productId} for tier {$pricing_tier_name}");
                    break;
                }
            }
        
            if ($productId) {
                // Step 2: Retrieve prices for the matching product
                $prices = $stripe->prices->all([
                    'product' => $productId,
                    'active' => true
                ]);

                $matching_prices = [];
            
                foreach ($prices->data as $price) {
                    if (isset($price->recurring) && 
                        $price->recurring->interval === $interval && 
                        $price->active) {
                        
                        // Check if this price matches our test/live environment
                        $is_live_price = $price->livemode;
                        // The correct comparison should be:
                        // - If we're in test env ($is_test_env is true), we want test prices (!$is_live_price is true)
                        // - If we're in live env ($is_test_env is false), we want live prices ($is_live_price is true)
                        $price_matches_env = ($is_test_env) ? (!$is_live_price) : $is_live_price;
                        
                        if ($price_matches_env) {
                            $matching_prices[] = $price;
                            error_log("VOF Debug: Found matching price ID {$price->id} for tier {$pricing_tier_name} with interval {$interval} in " . 
                                ($is_test_env ? 'test' : 'live') . " environment");
                        } else {
                            error_log("VOF Debug: Found price ID {$price->id} but environment mismatch. Price is " . 
                                ($is_live_price ? "live" : "test") . ", but we need " . 
                                ($is_test_env ? "test" : "live"));
                        }
                    }
                }

                if (!empty($matching_prices)) {
                    // Return the first matching price by default
                    // This could be enhanced to pick the most appropriate price based on other criteria
                    return $matching_prices[0]->id;
                }

                error_log("VOF Debug: No price found for product {$productId} with interval {$interval} in " . 
                    ($is_test_env ? 'test' : 'live') . " environment");
            } else {
                error_log("VOF Debug: No product found with name {$pricing_tier_name}");
            }

            // If we get here, no matching price was found
            throw new \Exception("No price found for tier {$pricing_tier_name} with interval {$interval} in " . 
                ($is_test_env ? 'test' : 'live') . " environment");

        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("VOF Error: Stripe API error - " . $e->getMessage());
            throw new \Exception("Stripe API error: " . $e->getMessage());
        }
    }

    public function vof_get_stripe_checkout_data_OLD($tier_selected) {
        // Normalize tier name by reaplacing + with _plus if needed
        $tier_name = str_replace('+', '_plus', $tier_selected['name']);
        $interval = isset($tier_selected['interval']) ? $tier_selected['interval']: 'month';

        // Get proper price ID and configuration based on tier
        switch($tier_selected['name']) {
            // case 'biz':
            // case 'biz Test Subscription Handler':
            case 'biz Multiprice Test':
                return [
                    'line_items' => [
                        // 'price' => $this->vof_get_price_id('biz'),
                        'price' => $this->vof_get_price_id('biz Multiprice Test', $interval),
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
            
            // case 'noise':
            // case 'noise Test Subscription Handler':
            case 'noise Multiprice Test':
                return [
                    'line_items' => [
                        // 'price' => $this->vof_get_price_id('noise'),
                        'price' => $this->vof_get_price_id('noise Multiprice Test', $interval),
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
            
            // case 'noise_plus': // changed from noise+ to normalized name noise_plus
            // case 'noise_plus Test Subscription Handler': // changed from noise+ to normalized name noise_plus
            case 'noise_plus Multiprice Test': // changed from noise+ to normalized name noise_plus
                return [
                    'line_items' => [
                        'price' => $this->vof_get_price_id('noise_plus Multiprice Test', $interval),
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
                throw new \Exception('Invalid tier selected: ' . $tier_selected['name']);
        }
    }

    // Updated to handle different pricing scheme intervals
    private function vof_get_price_id_OLD($tier, $interval='month') {
        $is_test = VOF_Core::instance()->vof_get_stripe_config()->vof_is_stripe_test_mode();
        
        // Price IDs for each environment and tier
        $price_ids = [
            'test' => [ // this is $env
                'monthly' => [
                    // 'biz Test Subscription Handler'        => 'price_1QushhF1Da8bBQoXx6owQdne',
                    // 'noise Test Subscription Handler'      => 'price_1QusjwF1Da8bBQoXRXJTb8ie',
                    // 'noise_plus Test Subscription Handler' => 'price_1QuslaF1Da8bBQoXmDNcAzuM'
                    'biz Multiprice Test'        => 'price_1Qxbg5F1Da8bBQoXprg5jwDY',
                    'noise Multiprice Test'      => 'price_1QxmWRF1Da8bBQoXt5W5DbIe',
                    'noise_plus Multiprice Test' => 'price_1QxmsRF1Da8bBQoXncWbeoxf'

                ],
                'yearly' => [
                    // 'biz Test Subscription Handler'        => 'price_1QxSS0F1Da8bBQoXOvRkOMoP',
                    // 'noise Test Subscription Handler'      => 'price_xxxxxxxxxx',
                    // 'noise_plus Test Subscription Handler' => 'price_xxxxxxxxxx'
                    'biz Multiprice Test'        => 'price_1QxboiF1Da8bBQoX0tNfCRDt',
                    'noise Multiprice Test'      => 'price_1Qxmn5F1Da8bBQoX99zHdEYu',
                    'noise_plus Multiprice Test' => 'price_1QxmsRF1Da8bBQoXFYS9rqmL'
                ],
            ],
            'live' => [
                'monthly' => [
                    'biz'        => 'price_1Pa4qHF1Da8bBQoXBrnH9I98',
                    'noise'      => 'price_1PPtRLF1Da8bBQoXsqXkk1XK',
                    'noise_plus' => 'price_1PPtUTF1Da8bBQoXhtr8xZnd'
                ],
                'yearly' => [
                    'biz'        => 'price_xxxxxxxxxx',
                    'noise'      => 'price_xxxxxxxxxx',
                    'noise_plus' => 'price_xxxxxxxxxx'
                ]
            ]
        ];

        $env = $is_test ? 'test' : 'live';
        $interval_type = $interval === 'year' ? 'yearly' : 'monthly';
        if(!isset($price_ids[$env][$interval_type][$tier])) {
            throw new \Exception('Invalid tier or interval: ' .$tier . '-' . $interval);
        }

        return $price_ids[$env][$interval_type][$tier];
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