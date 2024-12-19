<?php
/**
 * Plugin Name: Vendor Onboarding Flow
 * Description: Streamlined vendor onboarding with Stripe integration
 * Version: 1.0.0
 * Author: TheNoise
 * Text Domain: vendor-onboarding-flow
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

namespace VOF;

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-vof-dependencies.php';

class VOF_Core {
    private static $instance = null;
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Define constants first
        $this->define_constants();
        
        // Check dependencies after plugins are fully loaded
        add_action('plugins_loaded', function() {
            $dependency_check = VOF_Dependencies::check();
            
            if (is_wp_error($dependency_check)) {
                add_action('admin_notices', function() use ($dependency_check) {
                    echo '<div class="error"><p>' . $dependency_check->get_error_message() . '</p></div>';
                });
                return;
            }
            
            // Initialize plugin only if dependencies are met
            $this->init_hooks();
        }, 15); // Priority 15 ensures all required plugins are loaded
    }

    private function define_constants() {
        define('VOF_VERSION', '1.0.0');
        define('VOF_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('VOF_PLUGIN_URL', plugin_dir_url(__FILE__));
        define('VOF_PLUGIN_BASENAME', plugin_basename(__FILE__));
    }
    
    private function init_hooks() {
        add_action('init', [$this, 'init'], 20);
    }
    
    public function init() {
        if (!class_exists('RtclStore\\Helpers\\Functions')) {
            return;
        }
        
        // Load components
        require_once VOF_PLUGIN_DIR . 'includes/class-vof-subscription.php';
        require_once VOF_PLUGIN_DIR . 'includes/class-vof-listing.php';
        require_once VOF_PLUGIN_DIR . 'includes/class-vof-form-handler.php';
        
        new VOF_Subscription();
        new VOF_Listing();
        new VOF_Form_Handler();
    }
}

function VOF() {
    return VOF_Core::instance();
}

// Initialize plugin
add_action('plugins_loaded', 'VOF\\VOF', 5);