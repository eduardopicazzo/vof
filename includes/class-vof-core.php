<?php
namespace VOF;

use VOF\Utils\Helpers\VOF_Helper_Functions;
use VOF\Utils\Helpers\VOF_Temp_User_Meta;
use VOF\API\VOF_API;

class VOF_Core {
    private static $instance = null;
    private $api;
    private $assets;
    private $listing;
    private $form_handler;
    private $temp_user_meta;
    private $vof_helper;


    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Add activation method
    public static function vof_activate() {
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

    // Add deactivation method
    public static function vof_deactivate() {
        // Clear scheduled cron
        wp_clear_scheduled_hook( 'vof_cleanup_temp_data' );

        // Maybe add other cleanup tasks
        do_action( 'vof_deactivated' );
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Check dependencies first
        if (!VOF_Dependencies::check()) {
            return;
        }

        // Initialize components
        add_action('init', [$this, 'init_components'], 0);
        
        // Initialize REST API with later priority
        add_action('rest_api_init', [$this, 'vof_init_rest_api'], 15); // higher (later) priority 
        
        // Load text domain
        add_action('init', [$this, 'load_textdomain']);

        // Add cleanup hook
        add_action( 'vof_cleanup_temp_data', [$this, 'vof_cleanup_temp_data'] );
    }

    // Add cleanup method
    public function vof_cleanup_temp_data() {
        if ($this->temp_user_meta) {
            $this->temp_user_meta->vof_delete_expired_data();
        }
    }

    public function init_components() {
        $this->assets = new VOF_Assets();
        $this->listing = new VOF_Listing();
        $this->form_handler = new VOF_Form_Handler();
        $this->temp_user_meta = VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
        $this->vof_helper = new VOF_Helper_Functions();
        
        // Initialize only if needed
        if (is_admin()) {
            // Admin specific initializations

            // Adds the vof_temp_post records view in admin
            add_action( 'admin_menu', [$this, 'vof_add_admin_menu'] );
        }
    }

    public function vof_init_rest_api() {
        try {
            error_log('VOF Debug: Initializing VOF API from Core');
    
            if (!class_exists('\VOF\API\VOF_API')) {
                throw new \Exception('VOF API class not found');
            }
    
            // Get API instance
            $this->api = VOF_API::vof_get_instance();
    
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

    public function vof_get_vof_api() {
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
        return VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
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
        if (isset($_POST['vof_create_test_record'])) {
            $uuid = $temp_user_meta->vof_create_test_record();
            echo '<div class="notice notice-success"><p>Created test record with UUID: '. esc_html($uuid). '</p></div>';
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
                    <input type="submit" name="vof_create_test_record" 
                           class="button button-primary" 
                           value="Create Test Record">
                </form>
            </div>
        
            <!-- Display records -->
            <div class="card" style="margin-top: 20px;">
                <h2>Current Records</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>UUID</th>
                            <th>Post ID</th>
                            <th>Created</th>
                            <th>Expires</th>
                            <th>Meta Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="5">No records found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $record): ?>
                                <tr>
                                    <td><?php echo esc_html($record['uuid']); ?></td>
                                    <td><?php echo esc_html($record['post_id']); ?></td>
                                    <td><?php echo esc_html($record['created_at']); ?></td>
                                    <td><?php echo esc_html($record['expires_at']); ?></td>
                                    <td>
                                        <pre><?php print_r($record['meta_data']); ?></pre>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        
            <!-- Database Info -->
            <div class="card" style="margin-top: 20px;">
                <h2>Database Information</h2>
                <p>Table Name: <?php echo esc_html($temp_user_meta->vof_get_table_name()); ?></p>
                <pre>
                CREATE TABLE Query:
                <?php echo esc_html($temp_user_meta->vof_get_create_table_sql()); ?>
                </pre>
            </div>
        </div>
        <?php
    }
}