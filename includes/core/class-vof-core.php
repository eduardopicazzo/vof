<?php
/**
 * Purpose: Orchestrates plugin initialization, dependency management, 
 * and hook registration.
 */
namespace VOF\Core;

class VOF_Core {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->vof_define_constants();
        $this->vof_load_dependencies();
        $this->vof_register_hooks();
    }

    private function vof_define_constants() {
        define('VOF_VERSION', '1.0.0');
        define('VOF_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('VOF_PLUGIN_URL', plugin_dir_url(__FILE__));
    }

    private function vof_load_dependencies() {
        require_once VOF_PLUGIN_DIR . 'includes/core/class-vof-dependencies.php';
        require_once VOF_PLUGIN_DIR . 'includes/features/class-vof-listing.php';
        require_once VOF_PLUGIN_DIR . 'includes/features/class-vof-subscription.php';
        require_once VOF_PLUGIN_DIR . 'includes/features/class-vof-form-handler.php';
        require_once VOF_PLUGIN_DIR . 'includes/features/class-vof-stripe.php';
    }

    private function vof_register_hooks() {
        add_action('plugins_loaded', [$this, 'vof_initialize_features']);
    }

    public function vof_initialize_features() {
        new \VOF\Core\VOF_Listing();
        new \VOF\Core\VOF_Subscription();
        new \VOF\Core\VOF_Form_Handler();
        new \VOF\Core\VOF_Stripe();
    }
}

VOF_Core::instance();