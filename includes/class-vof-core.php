<?php

namespace VOF;

class VOF_Core {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->define_constants();
        $this->init_hooks();
    }

    private function define_constants() {
        define('VOF_VERSION', '1.0.0');
        define('VOF_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('VOF_PLUGIN_URL', plugin_dir_url(__FILE__));
    }

    private function init_hooks() {
        add_action('plugins_loaded', [$this, 'init'], 0);
    }

    public function init() {
        // Load dependencies
        VOF_Dependencies::check(); // Check for required plugins

        // Initialize Store Functions override first
        VOF_Store_Functions::init(); // Ensure our override is loaded

        // Initialize components
        new VOF_Listing(); // Initialize listing management
        new VOF_Subscription(); // Initialize subscription checks
        new VOF_Form_Handler(); // Initialize form handling
        new VOF_Stripe(); // Initialize Stripe integration
    }
}
