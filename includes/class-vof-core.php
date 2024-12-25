<?php
namespace VOF;

class VOF_Core {
    private static $instance = null;
    private $api;
    private $assets;
    private $listing;
    private $form_handler;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
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
        
        // Initialize REST API
        add_action('rest_api_init', [$this, 'init_rest_api']);
        
        // Load text domain
        add_action('init', [$this, 'load_textdomain']);
    }

    public function init_components() {
        $this->api = new VOF_API();
        $this->assets = new VOF_Assets();
        $this->listing = new VOF_Listing();
        $this->form_handler = new VOF_Form_Handler();
        
        // Initialize only if needed
        if (is_admin()) {
            // Admin specific initializations
        }
    }

    public function init_rest_api() {
        $this->api->register_routes();
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'vendor-onboarding-flow',
            false,
            dirname(plugin_basename(VOF_PLUGIN_FILE)) . '/languages'
        );
    }

    // Getters for components
    public function api() {
        return $this->api;
    }

    public function assets() {
        return $this->assets;
    }

    public function listing() {
        return $this->listing;
    }

    public function form_handler() {
        return $this->form_handler;
    }
}