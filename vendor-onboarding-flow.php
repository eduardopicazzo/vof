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
// path: wp-content/plugins/vendor-onboarding-flow/vendor-onboarding-flow.php
// Define plugin constants
define('VOF_VERSION', '1.0.0');
define('VOF_PLUGIN_FILE', __FILE__);
define('VOF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VOF_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load Composer autoloader if it exists
if (file_exists(VOF_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once VOF_PLUGIN_DIR . 'vendor/autoload.php';
}

// Load dependencies first
require_once VOF_PLUGIN_DIR . 'includes/class-vof-dependencies.php';

// Load Helpers and Utils first
require_once VOF_PLUGIN_DIR . 'utils/helpers/class-vof-helper-functions.php';
require_once VOF_PLUGIN_DIR . 'utils/helpers/class-vof-temp-user-meta.php';
require_once VOF_PLUGIN_DIR . 'utils/vof-stripe/class-vof-stripe-config.php';
require_once VOF_PLUGIN_DIR . 'utils/vof-stripe/class-vof-stripe-settings.php';

// Load MailerLite integration if the directory exists
if (file_exists(VOF_PLUGIN_DIR . 'utils/vof-mailing-esps')) {
    if (file_exists(VOF_PLUGIN_DIR . 'utils/vof-mailing-esps/class-vof-mailerlite.php')) {
        require_once VOF_PLUGIN_DIR . 'utils/vof-mailing-esps/class-vof-mailerlite.php';
    }
    
    if (file_exists(VOF_PLUGIN_DIR . 'utils/vof-mailing-esps/class-vof-mailerlite-settings.php')) {
        require_once VOF_PLUGIN_DIR . 'utils/vof-mailing-esps/class-vof-mailerlite-settings.php';
    }
}

// Load VOF API (if not works put back on 4th place top-down)
require_once VOF_PLUGIN_DIR . 'api/class-vof-api.php';

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

// ################### REDIRECT STUFF [start] ###################

// First, add this debug action to see what's happening with the cookie domain
add_action('init', function() {
    $site_url = get_site_url();
    $parsed_url = parse_url($site_url);
    $host = $parsed_url['host'];
    $domain_parts = explode('.', $host);
    error_log('VOF Debug: Current host: ' . $host);
    error_log('VOF Debug: Domain parts: ' . print_r($domain_parts, true));
});

// First, add this new filter to ensure WordPress recognizes our root domain
add_filter('site_option_cookie_domain', function($cookie_domain) {
    // Get host and determine cookie domain
    $host = parse_url(get_site_url(), PHP_URL_HOST);
    
    // Skip for localhost/IP
    if (preg_match('/^(?:localhost|(?:\d{1,3}\.){3}\d{1,3})$/', $host)) {
        return $cookie_domain;
    }
    
    $domain_parts = explode('.', $host);
    if (count($domain_parts) > 2) {
        return '.' . implode('.', array_slice($domain_parts, -2));
    }
    
    return '.' . $host;
}, 10, 1);

// Then modify the template_redirect handler
add_action('template_redirect', function() {
    if (isset($_GET['checkout']) && $_GET['checkout'] === 'success' && !is_user_logged_in()) {
        error_log('VOF Debug: Starting authentication process');
        $session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : '';
        
        if ($session_id) {
            try {
                $stripe = VOF_Core::instance()->vof_get_stripe_config()->vof_get_stripe();
                $session = $stripe->checkout->sessions->retrieve($session_id);
                
                if ($session && isset($session->metadata->uuid)) {
                    $temp_user_meta = VOF_Core::instance()->temp_user_meta();
                    $temp_data = $temp_user_meta->vof_get_temp_user_by_uuid($session->metadata->uuid);
                    
                    if ($temp_data && !empty($temp_data['true_user_id'])) {
                        $user_id = $temp_data['true_user_id'];
                        error_log('VOF Debug: Processing authentication for user ID: ' . $user_id);
                        
                        // Force cookie domain setup
                        $host = parse_url(get_site_url(), PHP_URL_HOST);
                        $domain_parts = explode('.', $host);
                        $root_domain = count($domain_parts) > 2 ? 
                            implode('.', array_slice($domain_parts, -2)) : 
                            $host;
                            
                        error_log('VOF Debug: Setting cookies for root domain: .' . $root_domain);
                        
                        // Clear existing cookies and session
                        wp_clear_auth_cookie();
                        if (session_id()) {
                            session_destroy();
                        }
                        
                        // Define cookie parameters
                        $secure = is_ssl();
                        $expiration = time() + DAY_IN_SECONDS;
                        
                        // Set WordPress cookies through core function first
                        wp_set_auth_cookie($user_id, false, $secure);
                        
                        // Then set cookies manually to ensure subdomain coverage
                        $auth_cookie = wp_generate_auth_cookie($user_id, $expiration, 'auth');
                        $logged_in_cookie = wp_generate_auth_cookie($user_id, $expiration, 'logged_in');
                        
                        // Common cookie options
                        $cookie_options = [
                            'expires' => $expiration,
                            'path' => COOKIEPATH,
                            'domain' => '.' . $root_domain, // Note the leading dot
                            'secure' => $secure,
                            'httponly' => true
                        ];
                        
                        // Set auth cookie
                        if (!setcookie(AUTH_COOKIE, $auth_cookie, $cookie_options)) {
                            error_log('VOF Error: Failed to set AUTH_COOKIE');
                        }
                        
                        // Set logged in cookie
                        if (!setcookie(LOGGED_IN_COOKIE, $logged_in_cookie, $cookie_options)) {
                            error_log('VOF Error: Failed to set LOGGED_IN_COOKIE');
                        }
                        
                        // Set secure auth cookie if needed
                        if ($secure) {
                            if (!setcookie(SECURE_AUTH_COOKIE, $auth_cookie, $cookie_options)) {
                                error_log('VOF Error: Failed to set SECURE_AUTH_COOKIE');
                            }
                        }
                        
                        // Update current user
                        wp_set_current_user($user_id);
                        
                        // Force no caching
                        nocache_headers();
                        
                        error_log('VOF Debug: Authentication process completed');
                        error_log('VOF Debug: Cookie domain used: .' . $root_domain);
                        error_log('VOF Debug: Cookie path: ' . COOKIEPATH);
                        
                        // Redirect
                        $redirect_url = remove_query_arg(['session_id', 'checkout']);
                        wp_redirect($redirect_url);
                        exit;
                    } else {
                        error_log('VOF Error: No valid user data found for UUID: ' . $session->metadata->uuid);
                    }
                }
            } catch (\Exception $e) {
                error_log('VOF Error: Auth redirect failed - ' . $e->getMessage());
                error_log('VOF Error: Stack trace - ' . $e->getTraceAsString());
            }
        }
    }
}, 5); // Note the priority of 5 to ensure early execution

// ################### REDIRECT STUFF [end] ###################



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