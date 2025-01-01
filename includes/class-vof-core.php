<?php
namespace VOF;

use VOF_Helper_Functions;

class VOF_Core {
    private static $instance = null;
    private $api;
    private $assets;
    private $listing;
    private $form_handler;
    // private $vof_ajax;
    private $vof_helper;
    // private $vof_stripe;
    // private $vof_subscription;


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
        $this->form_handler = new \VOF_Form_Handler();
        // $this->vof_ajax = new VOF_Ajax();
        $this->vof_helper = new VOF_Helper_Functions();
        // $this->vof_stripe = new VOF_Stripe();
        // $this->vof_subscription = new VOF_Subscription();
        
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

    // public function vof_ajax() {
    //     return $this->vof_ajax;
    // }

    public function vof_helper() {
        return $this->vof_helper;
    }

    // public function vof_stripe() {
    //     return $this->vof_stripe;
    // }

    // public function vof_subscription() {
    //     return $this->vof_subscription;
    // }

}