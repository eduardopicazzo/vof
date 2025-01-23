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

    public function vof_test_endpoint() {
        return rest_ensure_response([
            'success' => true,
            'message' => 'VOF API is working!',
            'version' => VOF_VERSION
        ]);
    }

    // public function vof_get_checkout_options_ON_REQUEST($request) {
    //     try {
    //         $uuid = $request->get_param('uuid');
            
    //         // Get temp user data
    //         $temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
    //         $user_data = $temp_user_meta->vof_get_temp_user_by_uuid($uuid);
            
    //         if (!$user_data) {
    //             return new \WP_Error(
    //                 'invalid_uuid',
    //                 'Invalid or expired UUID',
    //                 ['status' => 400]
    //             );
    //         }

    //         // Get available tiers for parent category
    //         // $tiers = $this->vof_get_tiers_for_category($user_data['vof_tier']);

    //         return rest_ensure_response([
    //             'success' => true,
    //             'data' => [
    //                 'uuid' => $uuid,
    //                 // 'tiers' => $tiers
    //             ]
    //         ]);

    //     } catch (\Exception $e) {
    //         error_log('VOF API Error: ' . $e->getMessage());
    //         return new \WP_Error(
    //             'server_error',
    //             'An error occurred processing your request',
    //             ['status' => 500]
    //         );
    //     }
    // }

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

    // public function vof_start_checkout($request) {
    // }

    // private function vof_get_tiers_for_category($vof_tier) { // <- used to be called
    private function vof_get_checkout_options($vof_tier) {
        $pricing_data = null;

        switch($vof_tier) {
            case'limit_tiers':
                // TODO: add conditional checking for multiprice...
                $pricing_data = [
                    'is_multi_pricing_on' => false,
                    'tiers' => [ 
                        [ // tier biz
                            'id' =>'biz', 
                            'name' => 'biz',
                            'description' => 'Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.',
                            // 'price_id' => 'price_1QhSnRF1Da8bBQoXGxUNerFq',
                            'price_id' => 44,
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '8 listados/mes',
                                'Publica en la mayoría de categorías excepto autos, inmuebles y maquinaria',
                                '2 destacadores Top/mes',
                                '3 destacadores BumpUp/mes',
                                '2 destacadores Destacados/mes'
                            ],
                            'is_recommended' => false,
                            'is_gray_out' => true,
                        ],
                        [ // tier noise
                            'id' =>'noise', 
                            'name' => 'noise',
                            'description' => 'Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local y conectar con su comunidad con toda flexibilidad.',
                            // 'price_id' => 'price_1QhSnRF1Da8bBQoXGxUNerFq',
                            'price_id' => 444,
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '16 Listados/mes',
                                'Publica en todas las categorías',
                                '5 destacadores Top/mes',
                                '3 destacadores BumpUp/mes',
                                '2 destacadores Destacados/mes'
                            ],
                            'is_recommended' => true,
                            'is_gray_out' => false,
                        ],
                        [ // tier noise+
                            'id' =>'noise_plus', 
                            'name' => 'noise+',
                            'description' => 'Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.',
                            // 'price_id' => 'price_1QhSsJF1Da8bBQoXzYViJiS2',
                            'price_id' => 4444, // hardcode for now since has not called stripe yet (later retrieve from DB)
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '30 listados/mes',
                                'Publica en todas las categorías',
                                '10 destacadores Top/mes',
                                '6 destacadores BumpUp/mes',
                                '6 destacadores Destacados/mes'
                            ],
                            'is_recommended' => false,
                            'is_gray_out' => false,
                        ],
                    ]
                ];
                break;
            default: 
                // TODO: add conditional checking for multiprice...
                $pricing_data = [
                    'is_multi_pricing_on' => false,
                    'tiers' => [ 
                        [ // tier biz
                            'id' =>'biz', 
                            'name' => 'biz',
                            'description' => 'Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.',
                            // 'price_id' => 'price_1QhSnRF1Da8bBQoXGxUNerFq',
                            'price_id' => 44,
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '8 listados/mes',
                                'Publica en la mayoría de categorías excepto autos, inmuebles y maquinaria',
                                '2 destacadores Top/mes',
                                '3 destacadores BumpUp/mes',
                                '2 destacadores Destacados/mes'
                            ],
                            'is_recommended' => true,
                            'is_gray_out' => false,
                        ],
                        [ // tier noise
                            'id' =>'noise', 
                            'name' => 'noise',
                            'description' => 'Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local y conectar con su comunidad con toda flexibilidad.',
                            // 'price_id' => 'price_1QhSnRF1Da8bBQoXGxUNerFq',
                            'price_id' => 444,
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '16 Listados/mes',
                                'Publica en todas las categorías',
                                '5 destacadores Top/mes',
                                '3 destacadores BumpUp/mes',
                                '2 destacadores Destacados/mes'
                            ],
                            'is_recommended' => false,
                            'is_gray_out' => false,
                        ],
                        [ // tier noise+
                            'id' =>'noise_plus', 
                            'name' => 'noise+',
                            'description' => 'Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.',
                            // 'price_id' => 'price_1QhSsJF1Da8bBQoXzYViJiS2',
                            'price_id' => 4444, // hardcode for now since has not called stripe yet (later retrieve from DB)
                            'features' => [
                                'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                                '30 listados/mes',
                                'Publica en todas las categorías',
                                '10 destacadores Top/mes',
                                '6 destacadores BumpUp/mes',
                                '6 destacadores Destacados/mes'
                            ],
                            'is_recommended' => false,
                            'is_gray_out' => false,
                        ],
                    ]
                ];
                break;
        }

        return $pricing_data;

        // for reference only;
            // switch ($vof_tier) { // RETURN IN JSON... CHECK SUGGESTED V0 LOGIC
            //     case 'limit_tiers': 
            //         // GREY-OUT: BIZ
            //         // RECOMMEND: NOISE
            //         return [
            //             ['id' => 'noise', 'name' => 'noise', 'price_id' => 'price_1QhSnRF1Da8bBQoXGxUNerFq'],
            //             ['id' => 'noise_plus', 'name' => 'noise+', 'price_id' => 'price_1QhSsJF1Da8bBQoXzYViJiS2']
            //         ];
            //         break;
            //     default:
            //         // GREY-OUT: NONE
            //         // RECOMMEND: BIZ
            //         return [
            //             ['id' => 'biz', 'name' => 'biz', 'price_id' => 'price_1QhSfAF1Da8bBQoXOMYG2Kb3'],
            //             ['id' => 'noise', 'name' => 'noise', 'price_id' => 'price_1QhSnRF1Da8bBQoXGxUNerFq'],
            //             ['id' => 'noise_plus', 'name' => 'noise+', 'price_id' => 'price_1QhSsJF1Da8bBQoXzYViJiS2']
            //         ];
            //         break;
            // }
        // delete when ready
    }

    public function vof_process_checkout($request) {
        // try {
        //     $uuid = $request->get_param('uuid');
            
        //     // Get temp user data
        //     $temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
        //     $user_data = $temp_user_meta->vof_get_temp_user_by_uuid($uuid);
            
        //     if (!$user_data) {
        //         return new \WP_Error(
        //             'invalid_uuid',
        //             'Invalid or expired UUID',
        //             ['status' => 400]
        //         );
        //     }

            // Get Stripe instance
            // $stripe = VOF_Core::instance()->vof_get_stripe_config()->vof_get_stripe();
            
            // Get tiers for category
            // $tiers = $this->vof_get_tiers_for_category($user_data['post_parent_cat']);
            // $tiers = $this->vof_get_tiers_for_category($user_data['vof_tier']);
            
            // Create line items
            // $line_items = array_map(function($tier) {
            //     return [
            //         'price' => $tier['price_id'],
            //         'quantity' => 1
            //     ];
            // }, $tiers);

            // // Create checkout session
            // $session = $stripe->checkout->sessions->create([
            //     'payment_method_types' => ['card'],
            //     'customer_email' => $user_data['vof_email'],
            //     'metadata' => [
            //         'uuid' => $uuid
            //     ],
            //     'line_items' => $line_items,
            //     'mode' => 'subscription',
            //     'success_url' => home_url('/my-account?checkout=success&session_id={CHECKOUT_SESSION_ID}'),
            //     'cancel_url' => home_url('/my-account?checkout=cancelled')
            // ]);

            // return rest_ensure_response([
            //     'success' => true,
            //     'data' => [
            //         'checkout_url' => $session->url,
            //         'session_id' => $session->id
            //     ]
            // ]);

        // } catch (\Exception $e) {
        //     error_log('VOF API Error: ' . $e->getMessage());
        //     return new \WP_Error(
        //         'checkout_error',
        //         'Error creating checkout session: ' . $e->getMessage(),
        //         ['status' => 500]
        //     );
        // }
    }

    // public function vof_process_checkout_OLD_W_STRIPE_REDIRECT($request) {
    //     try {
    //         $uuid = $request->get_param('uuid');
            
    //         // Get temp user data
    //         $temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
    //         $user_data = $temp_user_meta->vof_get_temp_user_by_uuid($uuid);
            
    //         if (!$user_data) {
    //             return new \WP_Error(
    //                 'invalid_uuid',
    //                 'Invalid or expired UUID',
    //                 ['status' => 400]
    //             );
    //         }

    //         // Get Stripe instance
    //         $stripe = VOF_Core::instance()->vof_get_stripe_config()->vof_get_stripe();
            
    //         // Get tiers for category
    //         // $tiers = $this->vof_get_tiers_for_category($user_data['post_parent_cat']);
    //         // $tiers = $this->vof_get_tiers_for_category($user_data['vof_tier']);
            
    //         // Create line items
    //         // $line_items = array_map(function($tier) {
    //         //     return [
    //         //         'price' => $tier['price_id'],
    //         //         'quantity' => 1
    //         //     ];
    //         // }, $tiers);

    //         // Create checkout session
    //         $session = $stripe->checkout->sessions->create([
    //             'payment_method_types' => ['card'],
    //             'customer_email' => $user_data['vof_email'],
    //             'metadata' => [
    //                 'uuid' => $uuid
    //             ],
    //             // 'line_items' => $line_items,
    //             'mode' => 'subscription',
    //             'success_url' => home_url('/my-account?checkout=success&session_id={CHECKOUT_SESSION_ID}'),
    //             'cancel_url' => home_url('/my-account?checkout=cancelled')
    //         ]);

    //         return rest_ensure_response([
    //             'success' => true,
    //             'data' => [
    //                 'checkout_url' => $session->url,
    //                 'session_id' => $session->id
    //             ]
    //         ]);

    //     } catch (\Exception $e) {
    //         error_log('VOF API Error: ' . $e->getMessage());
    //         return new \WP_Error(
    //             'checkout_error',
    //             'Error creating checkout session: ' . $e->getMessage(),
    //             ['status' => 500]
    //         );
    //     }
    // }

    public function vof_handle_webhook($request) {
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

    private function vof_create_user($user_data) {
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

    public function vof_check_permissions() {
        return true; // TODO: Implement proper permission checks
    }

    public function vof_validate_webhook() {
        return true; // Signature is validated in handler
    }
}