<?php
namespace VOF;

use VOF\Utils\Helpers\VOF_Helper_Functions;
use VOF\Utils\Helpers\VOF_Temp_User_Meta;
use VOF\API\VOF_API;
use VOF\Utils\Stripe\VOF_Stripe_Config;
use VOF\Utils\Stripe\VOF_Stripe_Settings;

class VOF_Core {
    private static $instance = null;
    private $api;
    private $assets;
    private $listing;
    private $form_handler;
    private $temp_user_meta;
    private $vof_helper;
    private $stripe_config;


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
        add_action('vof_DB', 'vof_run_db_updates');

        // Create temp user meta table
        VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();

        // Schedule cleanup cron job
        if( !wp_next_scheduled( 'vof_cleanup_temp_data' ) ) {
            wp_schedule_event( time(), 'daily', 'vof_cleanup_temp_data');
        }

        // Register post status for temporary listings
        VOF_Helper_Functions::vof_register_temp_post_status();

        // Maybe add other activation tasks
        do_action( 'vof_activated' );
    }

    /**
     * Plugin deactivation
     */
    public static function vof_deactivate() {
        // Clear scheduled cron
        wp_clear_scheduled_hook( 'vof_cleanup_temp_data' );

        // Maybe add other cleanup tasks
        do_action( 'vof_deactivated' );
    }

    /**
     * Initialize hooks and dependencies
     */
    private function init_hooks() {
        // Check dependencies first
        if (!VOF_Dependencies::check()) {
            return;
        }

        // Initialize API first since other components might need it
        $this->api = VOF_API::vof_api_get_instance();

        // Initialize REST API with later priority
        add_action('rest_api_init', [$this, 'vof_init_rest_api'], 15); // higher (later) priority 

        // Initialize components
        add_action('init', [$this, 'init_components'], 0);
          
        // Load text domain
        add_action('init', [$this, 'load_textdomain']);

        // Add cleanup hook
        add_action( 'vof_cleanup_temp_data', [$this, 'vof_cleanup_temp_data'] );
    }

    public function init_components() {
        $this->assets = new VOF_Assets();
        $this->listing = new VOF_Listing();
        $this->form_handler = new VOF_Form_Handler();
        $this->temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
        $this->vof_helper = new VOF_Helper_Functions();
        $this->stripe_config = VOF_Stripe_Config::vof_get_stripe_config_instance();
        
        // Initialize only if needed
        if (is_admin()) {
            // Admin specific initializations
            
            // Adds the vof_temp_post records view in admin
            add_action( 'admin_menu', [$this, 'vof_add_admin_menu'] );
            // initialize vof-stripe adming dashboard
            new VOF_Stripe_Settings();
        }
    }

    // uncomment in case need rebuild vof_temp_user_db
    public function vof_run_db_updates() {
        $this->temp_user_meta->vof_maybe_create_table();
    }


    // Add cleanup method
    public function vof_cleanup_temp_data() {
        if ($this->temp_user_meta) {
            $this->temp_user_meta->vof_delete_expired_data();
        }
    }

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

    // Getters for components
    /**
     * Get Stripe config instance
     */
    public function vof_get_stripe_config() {
        return $this->stripe_config;
    }

    /**
     * Get API instance
     */
    public function vof_get_vof_api() {
        if (!$this->api) {
            $this->api = VOF_API::vof_api_get_instance();
        }
        return $this->api;
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

    // ### DEBUG SECTION (admin menu) ###

    // Add admin menu MENU
    public function vof_add_admin_menu() {
        add_menu_page(
            'VOF Debug', // Page title
            'VOF Debug', // Menu title
            'manage_options', // Capability required
            'vof_debug', // Menu slug
            [$this, 'vof_render_debug_page'], // callback function
            'dashicons-bug', // Icon URL
            100 // position
        );
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