<?php
namespace VOF;

use VOF\Utils\Helpers\VOF_Helper_Functions;
use VOF\Utils\Helpers\VOF_Temp_User_Meta;
use VOF\API\VOF_API;
use VOF\Utils\Stripe\VOF_Stripe_Config;
use VOF\Utils\Stripe\VOF_Stripe_Settings;
use VOF\VOF_Pricing_Modal;
use VOF\Includes\Fulfillment\VOF_Webhook_Handler;
use VOF\Includes\Fulfillment\VOF_Subscription_Handler;
use VOF\Includes\Fulfillment\VOF_Fulfillment_Handler;
use VOF\Utils\MailingESPs\VOF_MailerLite;
use VOF\Utils\MailingESPs\VOF_MailerLite_Settings;
use VOF\Utils\PricingModal\VOF_Pricing_Modal_Settings;
use RtclStore\Helpers\Functions as StoreFunctions;

class VOF_Core {
    private static $instance = null;
    private $api;
    private $assets;
    private $listing;
    private $form_handler;
    private $temp_user_meta;
    private $vof_helper;
    private $stripe_config;
    private $vof_pricing_modal;
    private $webhook_handler;
    private $fulfillment_handler;
    private $subscription_handler;
    private $mailerlite;
    private $mailerlite_settings;
    private $pricing_modal_settings;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }

    // Add activation method
    public static function vof_activate() {
        // Run DB Updates (if needed)
        // add_action('vof_DB', 'vof_run_db_updates');
        VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();

        // Schedule cleanup cron job
        // if( !wp_next_scheduled( 'vof_cleanup_temp_data' ) ) {
        //     wp_schedule_event( time(), 'daily', 'vof_cleanup_temp_data');
        // }

        // Register post status for temporary listings
        VOF_Helper_Functions::vof_register_temp_post_status();
        do_action( 'vof_activated' );
    }

    /**
     * Plugin deactivation
     */
    public static function vof_deactivate() {
        // Clear scheduled cron
        // wp_clear_scheduled_hook( 'vof_cleanup_temp_data' );
        do_action( 'vof_deactivated' );
    }

    /**
     * Initialize hooks and dependencies
     */
    private function init_hooks() {
        if (!VOF_Dependencies::check()) {
            return;
        }

        $this->api = VOF_API::vof_api_get_instance();                           // Initialize API first since other components might need it
        add_action('rest_api_init', [$this, 'vof_init_rest_api'], 15);          // Higher (later) priority (Initialize REST API with later priority)
        add_action('init', [$this, 'init_components'], 0);
        add_action('init', [$this, 'load_textdomain']);                          // Load text domain
        // add_action( 'vof_cleanup_temp_data', [$this, 'vof_cleanup_temp_data'] ); // Add cleanup hook
        
        // Add cron registration: for monthly fulfillment for larger interval billings @fulfillment-handler.php
        // add_action('init', [$this, 'vof_register_cron_schedules'], 0);

        // override myaccount dashboard templates
        add_filter('rtcl_locate_template', [$this, 'vof_override_templates'], 10, 4);
    }

    /**
     * Override default templates with VOF templates
     */
    public function vof_override_templates($template, $template_name, $args = array(), $template_path = '') {
        $vof_templates = [
            'myaccount/subscription-report.php' => 'my-account/vof-subscription-report.php',
            'myaccount/membership-statistic.php' => 'my-account/vof-membership-statistic.php'
        ];

        if (isset($vof_templates[$template_name])) {
            $vof_template = trailingslashit(VOF_PLUGIN_DIR) . 'templates/' . $vof_templates[$template_name];

            if (file_exists($vof_template)) {
                // Remove the original template loading action to prevent duplication
                remove_action('rtcl_account_dashboard_report', ['\RtclPro\Controllers\SubscriptionController', 'subscription_report'], 20);
                remove_action('rtcl_account_dashboard_report', ['RtclStore\Controllers\Hooks\TemplateHooks', 'membership_statistic_report'], 10);

                return $vof_template;
            }
        }

        return $template;
    }    

    public static function vof_membership_statistic_report_OLD($current_user) {
        \Rtcl\Helpers\Functions::get_template(
            'my-account/vof-membership-statistic',
            compact('current_user'),
            '',
            VOF_PLUGIN_DIR . 'templates/'
        );
    }

    public function init_components() {
        $this->assets         = new VOF_Assets();
        $this->listing        = new VOF_Listing();
        $this->form_handler   = new VOF_Form_Handler();
        $this->temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance(); // commenting this will break the modal
        $this->vof_helper     = new VOF_Helper_Functions();
        
        try {
            $this->stripe_config = VOF_Stripe_Config::vof_get_stripe_config_instance();
        } catch (\Exception $e) {
            error_log('VOF Warning: Could not initialize Stripe config: ' . $e->getMessage());
            $this->stripe_config = null;
        }

        // Initialize MailerLite integration if the class exists
        error_log('VOF Debug: MailerLite class exists: ' . (class_exists('\VOF\Utils\MailingESPs\VOF_MailerLite') ? 'Yes' : 'No'));
        error_log('VOF Debug: MailerLite class exists: ' . (class_exists('VOF\Utils\MailingESPs\VOF_MailerLite') ? 'Yes' : 'No'));
        if (class_exists('\VOF\Utils\MailingESPs\VOF_MailerLite')) { // check if need "\"
            $this->mailerlite = \VOF\Utils\MailingESPs\VOF_MailerLite::vof_get_instance();
        }

        // Initialize vof pricing modal only if needed 
        if($this->vof_should_init_pricing_modal()) {
            $this->vof_pricing_modal = new VOF_Pricing_Modal();
        }

        // Initialize fulfillment handlers
        $this->subscription_handler = VOF_Subscription_Handler::getInstance();
        $this->fulfillment_handler  = VOF_Fulfillment_Handler::getInstance();
        $this->webhook_handler      = VOF_Webhook_Handler::getInstance();

        // Initialize only if needed
        if (is_admin()) {
            // Admin specific initializations
            // Adds the vof_temp_post records view in admin
            add_action( 'admin_menu', [$this, 'vof_add_admin_menu'] );
            new VOF_Stripe_Settings(); // initialize vof-stripe admin dashboard
            
            // Initialize MailerLite settings if the class exists
            error_log('VOF Debug: MailerLite Settings class exists: ' . (class_exists('VOF\Utils\MailingESPs\VOF_MailerLite_Settings') ? 'Yes' : 'No'));
            error_log('VOF Debug: MailerLite Settings class exists: ' . (class_exists('VOF\Utils\MailingESPs\VOF_MailerLite_Settings') ? 'Yes' : 'No'));

            if (class_exists('\VOF\Utils\MailingESPs\VOF_MailerLite_Settings')) {
                $this->mailerlite_settings = new \VOF\Utils\MailingESPs\VOF_MailerLite_Settings();
            }
            if (class_exists('\VOF\Utils\PricingModal\VOF_Pricing_Modal_Settings')) {
                $this->pricing_modal_settings = new \VOF\Utils\PricingModal\VOF_Pricing_Modal_Settings();
            }            
        }
    }

    /**
     * Check if models should be initialized
     */
    private function vof_should_init_models() {
        // Add conditions when models should be initialized
        // e.g., during checkout, webhook processing, etc.
        return (
            isset($_GET['checkout']) && $_GET['checkout'] === 'success' ||
            strpos($_SERVER['REQUEST_URI'], '/post-an-ad/') !== false ||
            defined('DOING_AJAX') && DOING_AJAX
        );
    }

    /**
     * Initialize VOF models
     */
    private function vof_init_models() {
        // Models will be initialized on demand through getters
        // This avoids unnecessary initialization when not needed
    }

    public function vof_should_init_pricing_modal() {
        return strpos($_SERVER['REQUEST_URI'], '/post-an-ad/') !== false;
    }

    // uncomment in case need rebuild vof_temp_user_db
    public function vof_run_db_updates() {
        $this->temp_user_meta->vof_maybe_create_table();
    }

    // public function vof_cleanup_temp_data() {
    //     if ($this->temp_user_meta) {
    //         $this->temp_user_meta->vof_delete_expired_data();
    //     }
    // }

    public function vof_init_rest_api() {
        try {
            error_log('VOF Debug: Initializing VOF API from Core');
    
            if (!class_exists('\VOF\API\VOF_API')) {
                throw new \Exception('VOF API class not found');
            }
    
            // Get API instance
            $this->api = VOF_API::vof_api_get_instance();
    
            // Register Routes
            $this->api->vof_register_routes();
    
            error_log('VOF Debug: VOF API initialization complete');
        } catch (\Exception $e) {
            error_log('VOF Error: Failed to initialize API - ' . $e->getMessage());
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'vendor-onboarding-flow',
            false,
            dirname(plugin_basename(VOF_PLUGIN_FILE)) . '/languages'
        );
    }

    public function vof_get_webhook_handler() {
        return $this->webhook_handler;
    }

    public function vof_get_fulfillment_handler() {
        return $this->fulfillment_handler;
    }

    public function vof_get_subscription_handler() {
        return $this->subscription_handler;
    }

    public function vof_get_stripe_config_OLD() {
        return $this->stripe_config;
    }

public function vof_get_stripe_config() {
    if (!$this->stripe_config) {
        // Return a dummy config object or null
        error_log('VOF Warning: Stripe configuration not available');
    }
    return $this->stripe_config;
}

    public function vof_get_vof_api() {
        if (!$this->api) {
            $this->api = VOF_API::vof_api_get_instance();
        }
        return $this->api;
    }
    
    /**
     * Get MailerLite integration instance
     *
     * @return VOF_MailerLite|null
     */
    public function vof_get_mailerlite() {
        return $this->mailerlite;
    }

    // TODO: prepend 'vof_get_vof_' later
    public function assets() {
        return $this->assets;
    }

    // TODO: prepend 'vof_get_vof_' later
    public function listing() {
        return $this->listing;
    }

    // TODO: prepend 'vof_get_vof_' later
    public function form_handler() {
        return $this->form_handler;
    }

    // TODO: prepend 'vof_get_' later    
    public function temp_user_meta() {
        // return VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
        return $this->temp_user_meta;
    }

    // TODO: prepend 'vof_get_vof_' later    
    public function vof_helper() {
        return $this->vof_helper;
    }

    // ##########################################################
    // ############### DEBUG SECTION (admin menu) ###############
    // ##########################################################

    // Add admin menu MENU
    public function vof_add_admin_menu() {
        // Define a condition to check if we're in a development environment
        $is_development = defined('WP_DEBUG') && WP_DEBUG;
        // Add VOF Admin Menu Page
        add_menu_page(
            'VOF',                            // Page title
            'VOF',                            // Menu title
            'manage_options',                 // Capability required
            'vof_admin',                      // Menu slug
            [$this, 'vof_render_admin_dashboard'], // callback function
            'dashicons-store',                // Icon URL
            100                               // position
        );

        // Only add debug submenu in development environments
        if ($is_development) {
            add_submenu_page(
                'vof_admin',                     // Parent slug
                'VOF Debug',                     // Page title
                'VOF Debug',                     // Menu title
                'manage_options',                // Capability required
                'vof_debug',                     // Debug-specific slug
                [$this, 'vof_render_debug_page'] // Original debug renderer
            );
        }
    }

    // In class-vof-core.php
    public function vof_render_admin_dashboard() {
        // Check if we're in a development environment
        $is_development = defined('WP_DEBUG') && WP_DEBUG;
        // If we're in development AND the debug page is requested, show debug content
        // if ($is_development && isset($_GET['page']) && $_GET['page'] === 'vof_debug') {
        //     $this->vof_render_debug_page();
        //     return;
        // }
        // Handle email submission
        $message = '';
        if (isset($_POST['vof_subscribe_email']) && wp_verify_nonce($_POST['vof_email_nonce'], 'vof_subscribe')) {
            $email = sanitize_email($_POST['vof_subscribe_email']);
            if (!empty($email) && is_email($email)) {
                // Here you would typically store the email or make an API call
                update_option('vof_latest_subscriber_email', $email);
                $message = '<div class="notice notice-success"><p>Thank you for subscribing with ' . esc_html($email) . '!</p></div>';
            } else {
                $message = '<div class="notice notice-error"><p>Please enter a valid email address.</p></div>';
            }
        }

        // Otherwise, show a production-friendly dashboard
        ?>
        <div class="wrap">
            <h1>VOF Dashboard</h1>

            <?php echo $message; ?>

            <div class="card">
                <h2>Vendor Onboarding Flow Management</h2>
                <p>Welcome to the VOF management dashboard. Use the sub-menu options on the left to manage various aspects of the vendor onboarding process.</p>
                <div>
                    <p>
                        <a href="https://www.youtube.com/@TheNoiseio" 
                            target="_blank" 
                            class="button button-secondary"
                            style="text-decoration: none;">
                            <span class="dashicons dashicons-video-alt3" style="margin-top: 3px; margin-right: 5px;"></span>
                            Video Tutorials
                        </a>
                    </p>
                </div>
                <div style="margin-bottom: 25px;"></div>
                <hr />
                <div style="margin-top: 25px;"></div>
                <h2>Cut Through with <i>TheNoise</i></h2>
                <p>Subscribe for Vendor Onboarding Flow updates—new features, fresh insights. Plus, discover more tools to make your marketing sing. </p>

                <!-- <form method="post" style="margin-top: 20px;">
                    <//?php wp_nonce_field('vof_subscribe', 'vof_email_nonce'); ?>
                    <div style="display: flex; max-width: 500px;">
                        <input type="email" 
                               name="vof_subscribe_email" 
                               placeholder="Enter your email address" 
                               required
                               style="flex: 1; padding: 8px; margin-right: 10px;" />
                        <input type="submit" 
                               value="Subscribe" 
                               class="button button-primary" />
                    </div>
                </form> -->

                <div style="margin-top: 30px;">
                    <!-- <h3>Check out more products and Follow TheNoise on Gumroad</h3> -->
                    <p>
                        <a href="https://gumroad.com/thenoise" 
                           target="_blank" 
                           class="button button-secondary"
                           style="text-decoration: none;">
                            <span class="dashicons dashicons-cart" style="margin-top: 3px; margin-right: 5px;"></span>
                            Visit our Gumroad Profile
                        </a>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    // Add a new method for rendering the admin dashboard
    public function vof_render_admin_dashboard_OLD() {
        // Check if we're in a development environment
        $is_development = defined('WP_DEBUG') && WP_DEBUG;

        // // If we're in development AND the debug page is requested, show debug content
        // if ($is_development && isset($_GET['page']) && $_GET['page'] === 'vof_debug') {
        //     $this->vof_render_debug_page();
        //     return;
        // }

        // Otherwise, show a production-friendly dashboard
        ?>
        <div class="wrap">
            <h1>VOF Dashboard</h1>

            <div class="card">
                <h2>Vendor Onboarding Flow Management</h2>
                <p>Welcome to the VOF management dashboard. Use the menu options on the left to manage various aspects of the vendor onboarding process.</p>
                <div style="margin-top: 50px;"></div>
                <h2>Follow TheNoise to receive updates on this product</h2>
                <p>Welcome to the VOF management dashboard. Use the menu options on the left to manage various aspects of the vendor onboarding process.</p>
                <!-- <div class="vof-dashboard-stats"> -->
                    <!-- <h3>Quick Stats</h3> -->
                    <!-- <//?php -->
                    <!-- // Optional: Add some basic stats about vendors, subscriptions, etc. -->
                    <!-- $user_count = count_users(); -->
                    <!-- $vendors = isset($user_count['avail_roles']['vendor']) ? $user_count['avail_roles']['vendor'] : 0; -->
                    <!-- ?> -->
                    <!-- <p><strong>Total Vendors:</strong> <//?php echo esc_html($vendors); ?></p> -->

                    <!-- Add more stats as needed -->
                <!-- </div> -->
            </div>
            <!-- <div> -->
                <!-- <h2>Follow TheNoise to receive product's and updates on this product</h2>
                <p>Welcome to the VOF management dashboard. Use the menu options on the left to manage various aspects of the vendor onboarding process.</p> -->
            <!-- </div> -->
        </div>
        <?php
    }

    // Render debug page
    public function vof_render_debug_page() {
        // Get instance of VOF temp user meta
        $temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
    
        // Handle test record creation
        if (isset($_POST['vof_create_test_record']) && check_admin_referer('vof_debug_actions')) {
            $uuid = $temp_user_meta->vof_create_test_record();
            if ($uuid) {
                echo '<div class="notice notice-success"><p>Created test record with UUID: ' . esc_html($uuid) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to create test record</p></div>';
            }
        }
    
        // Get all records 
        $records = $temp_user_meta->vof_get_all_records();
    
        // Render the page
        ?>
        <div class="wrap">
            <h1>VOF Debug Panel</h1>
    
            <!-- Create test record form -->
            <div class="card">
                <h2>Test Actions</h2>
                <form method="post">
                    <?php wp_nonce_field('vof_debug_actions'); ?>
                    <input type="submit" 
                           name="vof_create_test_record" 
                           class="button button-primary" 
                           value="Create Test Record">
                </form>
            </div>
    
            <!-- Display records -->
            <div class="card" style="margin-top: 20px;">
                <h2>Current Records</h2>
                
                <?php if (empty($records)): ?>
                    <p>No records found</p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>UUID</th>
                                <th>Post ID</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>WhatsApp</th>
                                <th>Status</th>
                                <th>Tier</th>
                                <th>Parent Category</th>
                                <th>Created</th>
                                <th>Expires</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $record): ?>
                                <tr>
                                    <td><?php echo esc_html($record['id']); ?></td>
                                    <td>
                                        <?php echo esc_html($record['uuid']); ?>
                                        <button class="button button-small copy-to-clipboard" 
                                                data-clipboard="<?php echo esc_attr($record['uuid']); ?>">
                                            Copy
                                        </button>
                                    </td>
                                    <td>
                                        <?php 
                                        $post = get_post($record['post_id']);
                                        if ($post) {
                                            echo '<a href="' . get_edit_post_link($record['post_id']) . '">' . 
                                                 esc_html($record['post_id']) . '</a>';
                                        } else {
                                            echo esc_html($record['post_id']);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($record['vof_email']); ?></td>
                                    <td><?php echo esc_html($record['vof_phone']); ?></td>
                                    <td><?php echo esc_html($record['vof_whatsapp']) ?: 'N/A'; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo esc_attr($record['post_status']); ?>">
                                            <?php echo esc_html($record['post_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($record['vof_tier']): ?>
                                            <span class="tier-badge tier-<?php echo esc_attr($record['vof_tier']); ?>">
                                                <?php echo esc_html($record['vof_tier']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="tier-badge tier-none">Not Set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $category = get_term($record['post_parent_cat'], rtcl()->category);
                                        echo $category ? esc_html($category->name) : 'N/A';
                                        ?>
                                    </td>
                                    <td><?php echo esc_html(human_time_diff(strtotime($record['created_at']))); ?> ago</td>
                                    <td>
                                        <?php 
                                        $expires = strtotime($record['expires_at']);
                                        $now = time();
                                        $time_diff = $expires - $now;
                                        
                                        if ($time_diff < 0) {
                                            echo '<span class="expired">Expired</span>';
                                        } else {
                                            echo 'Expires in ' . human_time_diff($now, $expires);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <button class="button button-small view-details" 
                                                data-uuid="<?php echo esc_attr($record['uuid']); ?>">
                                            View Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
    
            <!-- Add inline styles -->
            <style>
                .status-badge {
                    padding: 3px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: 500;
                }
                .status-vof_temp {
                    background: #ffd700;
                    color: #000;
                }
                .status-publish {
                    background: #46b450;
                    color: #fff;
                }
                .tier-badge {
                    padding: 3px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: 500;
                    background: #e0e0e0;
                }
                .tier-none {
                    background: #f0f0f0;
                    color: #666;
                }
                .copy-to-clipboard {
                    margin-left: 5px !important;
                    padding: 0 5px !important;
                }
                .expired {
                    color: #dc3232;
                    font-weight: 500;
                }
                /* Responsive table */
                @media screen and (max-width: 782px) {
                    .widefat td, .widefat th {
                        padding: 8px 10px;
                    }
                }
            </style>
    
            <!-- Add clipboard functionality -->
            <script>
            jQuery(document).ready(function($) {
                // Copy UUID to clipboard
                $('.copy-to-clipboard').click(function() {
                    const uuid = $(this).data('clipboard');
                    navigator.clipboard.writeText(uuid).then(function() {
                        alert('UUID copied to clipboard!');
                    }).catch(function(err) {
                        console.error('Could not copy UUID:', err);
                    });
                });
    
                // View details button handler
                $('.view-details').click(function() {
                    const uuid = $(this).data('uuid');
                    // TODO: Implement modal or expandable row with additional details
                    alert('Viewing details for UUID: ' + uuid);
                });
            });
            </script>
    
            <!-- Database Info -->
            <div class="card" style="margin-top: 20px;">
                <h2>Database Information</h2>
                <p><strong>Table Name:</strong> <?php echo esc_html($temp_user_meta->vof_get_table_name()); ?></p>
                <div class="table-structure">
                    <h3>Table Structure</h3>
                    <pre style="background: #f6f7f7; padding: 15px; overflow-x: auto;">
                        <?php echo esc_html($temp_user_meta->vof_get_create_table_sql()); ?>
                    </pre>
                </div>
            </div>
        </div>
        <?php
    }
}