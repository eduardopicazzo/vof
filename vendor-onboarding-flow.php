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

defined('ABSPATH') || exit;

// Define plugin constants
define('VOF_VERSION', '1.0.0');
define('VOF_PLUGIN_FILE', __FILE__);
define('VOF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VOF_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load dependencies first
require_once VOF_PLUGIN_DIR . 'includes/class-vof-dependencies.php';
require_once VOF_PLUGIN_DIR . 'utils/helpers/class-vof-helper-functions.php';
require_once VOF_PLUGIN_DIR . 'utils/helpers/class-vof-temp-user-meta.php';
require_once VOF_PLUGIN_DIR . 'api/class-vof-api.php';
require_once VOF_PLUGIN_DIR . 'utils/vof-stripe/class-vof-stripe-config.php';
require_once VOF_PLUGIN_DIR . 'utils/vof-stripe/class-vof-stripe-settings.php';

// Then load fulfillment handlers
require_once VOF_PLUGIN_DIR . 'includes/fulfillment/class-vof-webhook-handler.php';
require_once VOF_PLUGIN_DIR . 'includes/fulfillment/class-vof-subcription-handler.php';
require_once VOF_PLUGIN_DIR . 'includes/fulfillment/class-vof-fulfillment-handler.php';

// Then load other classes
require_once VOF_PLUGIN_DIR . 'includes/class-vof-constants.php';
require_once VOF_PLUGIN_DIR . 'includes/class-vof-core.php';
require_once VOF_PLUGIN_DIR . 'includes/class-vof-assets.php';
require_once VOF_PLUGIN_DIR . 'includes/class-vof-form-handler.php';
require_once VOF_PLUGIN_DIR . 'includes/class-vof-listing.php';
require_once VOF_PLUGIN_DIR . 'includes/class-vof-pricing-modal.php';

// Initialize the plugin
function vof() {
    return VOF_Core::instance();
}

// Register activation / deactivation hooks
register_activation_hook(__FILE__, ['\VOF\VOF_Core', 'vof_activate']);
register_deactivation_hook(__FILE__, ['\VOF\VOF_Core', 'vof_deactivate']);

// Start the plugin
add_action('plugins_loaded', 'VOF\vof', 0);

// TO DO: Remove this after testing
add_action('init', function() {
    error_log('VOF Debug: Checking if vof_listing_contact_details_fields is hooked: ' . 
        (has_filter('rtcl_listing_form_contact_tpl_attributes') ? 'Yes' : 'No'));
});

// TO DO: MAYBE CHECK IF THIS IS NEEDED
// Add the new filter hook
add_action('init', function() {
    add_filter('rtcl_listing_form_contact_tpl_attributes', ['\VOF\VOF_Form_Handler', 'vof_listing_contact_details_fields']);
});