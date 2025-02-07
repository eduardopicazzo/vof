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
require_once VOF_PLUGIN_DIR . 'includes/fulfillment/class-vof-subscription-handler.php';
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

// Add auth check for checkout success page NEED TO STUDY HOW AND WHY THIS WORKS!!!
add_action('template_redirect', function() {
    if (isset($_GET['checkout']) && $_GET['checkout'] === 'success' && !is_user_logged_in()) {
        // Get session ID
        $session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
        
        if ($session_id) {
            // Get user from session
            $stripe = VOF_Core::instance()->vof_get_stripe_config()->vof_get_stripe();
            $session = $stripe->checkout->sessions->retrieve($session_id);
            
            if ($session && isset($session->metadata->uuid)) {
                $temp_user_meta = VOF_Core::instance()->temp_user_meta();
                $temp_data = $temp_user_meta->vof_get_temp_user_by_uuid($session->metadata->uuid);
                
                if ($temp_data && !empty($temp_data['true_user_id'])) {
                    error_log('VOF Debug: Force logging in user ID: ' . $temp_data['true_user_id']);
                    wp_set_auth_cookie($temp_data['true_user_id']);
                    wp_redirect(remove_query_arg(['session_id', 'checkout']));
                    exit;
                }
            }
        }
    }
});

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