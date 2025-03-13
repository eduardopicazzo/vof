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
require_once VOF_PLUGIN_DIR . 'utils/vof-pricing-modal/class-vof-pricing-modal-settings.php';

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

// Add this filter to prevent logout after password change
// Use a higher priority (999) to ensure it runs after WP's own handlers
add_filter('send_auth_cookies', function($send_cookies, $expire, $expiration, $user_id) {
    global $vof_prevent_auth_cookie_clear;
    
    // Check if we're in a password reset context
    if (doing_action('password_reset') || doing_action('after_password_reset')) {
        error_log('VOF Debug: Preserving authentication during password reset for user ID: ' . $user_id);
        $vof_prevent_auth_cookie_clear = true;
        
        // Force set cookies with cross-domain support
        vof_set_cross_domain_auth_cookies($user_id);
    }
    
    return $send_cookies;
}, 999, 4);

// Add an early hook to prevent cookie clearing during password reset
add_action('clear_auth_cookie', function() {
    global $vof_prevent_auth_cookie_clear;
    
    if (!empty($vof_prevent_auth_cookie_clear)) {
        error_log('VOF Debug: Preventing auth cookie clearing during password reset');
        
        // Get current user ID before cookies are cleared
        $user_id = get_current_user_id();
        if ($user_id) {
            // Schedule immediate re-authentication
            add_action('set_logged_in_cookie', function() use ($user_id) {
                vof_set_cross_domain_auth_cookies($user_id);
            }, 0, 0);
        }
    }
}, 0);

// Override the WordPress password reset behavior
add_action('after_password_reset', function($user, $new_password) {
    error_log('VOF Debug: After password reset for user: ' . $user->ID);
    
    // Force reauth with new password
    wp_set_auth_cookie($user->ID, true);
    
    // Set cross-domain cookies
    vof_set_cross_domain_auth_cookies($user->ID, true);
    
    // Store password reset info for later login attempts
    update_user_meta($user->ID, 'vof_last_password_reset', time());
    
    // Add a hook to the end of the request to ensure user stays logged in
    add_action('shutdown', function() use ($user) {
        error_log('VOF Debug: Final authentication check at shutdown for user: ' . $user->ID);
        if (!is_user_logged_in()) {
            error_log('VOF Debug: User not logged in at shutdown, forcing login');
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true);
        }
    }, 999);
}, 1, 2);

// Special handler for profile password changes
add_action('profile_update', function($user_id, $old_user_data) {
    // Get the updated user data
    $user = get_userdata($user_id);
    
    // Check if the password has changed
    if ($user && $user->user_pass !== $old_user_data->user_pass) {
        error_log('VOF Debug: Password changed in profile update for user: ' . $user_id);
        
        // Save the last password change time
        update_user_meta($user_id, 'vof_password_changed', time());
        
        // Ensure the user stays logged in
        wp_set_auth_cookie($user_id, true);
        
        // Also set cross-domain cookies
        vof_set_cross_domain_auth_cookies($user_id, true);
        
        // Disable any hooks that might log the user out
        add_filter('send_password_change_email', '__return_false', 999);
    }
}, 1, 2);

// Add this to override wp_password_change_notification to prevent logout
if (!function_exists('wp_password_change_notification')) {
    function wp_password_change_notification($user) {
        // Do nothing - prevent WordPress from sending notification which can cause logout
        error_log('VOF Debug: Preventing standard password change notification for user: ' . $user->ID);
        return;
    }
}

// Helper function to set cross-domain authentication cookies
function vof_set_cross_domain_auth_cookies($user_id, $remember = true) {
    // Avoid infinite recursion
    static $processing = false;
    if ($processing) {
        error_log('VOF Debug: Avoiding recursion in cookie setting for user ID: ' . $user_id);
        return;
    }
    $processing = true;
    
    error_log('VOF Debug: Setting cross-domain auth cookies for user ID: ' . $user_id);
    
    // Force cookie domain setup
    $host = parse_url(get_site_url(), PHP_URL_HOST);
    
    // Skip for localhost/IP
    if (preg_match('/^(?:localhost|(?:\d{1,3}\.){3}\d{1,3})$/', $host)) {
        error_log('VOF Debug: Local environment detected, using standard cookies');
        wp_set_auth_cookie($user_id, $remember);
        $processing = false;
        return;
    }
    
    $domain_parts = explode('.', $host);
    $root_domain = count($domain_parts) > 2 ? 
        implode('.', array_slice($domain_parts, -2)) : 
        $host;
        
    error_log('VOF Debug: Using root domain for cookies: .' . $root_domain);
        
    // Define cookie parameters
    $secure = is_ssl();
    // Calculate expiration based on remember preference
    $expiration = $remember ? time() + MONTH_IN_SECONDS : 0; // 0 = browser session
    if ($expiration === 0) {
        $expiration = time() + DAY_IN_SECONDS; // Force at least one day even for session cookies
    }
    
    // Generate standard WordPress auth cookies (uses WP's format)
    $auth_cookie = wp_generate_auth_cookie($user_id, $expiration, 'auth');
    $logged_in_cookie = wp_generate_auth_cookie($user_id, $expiration, 'logged_in');
    
    // Common cookie options
    $cookie_options = [
        'expires' => $expiration,
        'path' => COOKIEPATH,
        'domain' => '.' . $root_domain, // Note the leading dot for cross-subdomain support
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax' // Balance security with cross-domain functionality
    ];
    
    // Set auth cookie - only if headers not sent
    if (!headers_sent()) {
        // First set standard cookies via WordPress to ensure compatibility
        wp_set_auth_cookie($user_id, $remember);
        
        // Then set cross-domain cookies
        if (!setcookie(AUTH_COOKIE, $auth_cookie, $cookie_options)) {
            error_log('VOF Error: Failed to set cross-domain AUTH_COOKIE');
        }
        
        // Set logged in cookie
        if (!setcookie(LOGGED_IN_COOKIE, $logged_in_cookie, $cookie_options)) {
            error_log('VOF Error: Failed to set cross-domain LOGGED_IN_COOKIE');
        }
        
        // Set secure auth cookie if needed
        if ($secure) {
            if (!setcookie(SECURE_AUTH_COOKIE, $auth_cookie, $cookie_options)) {
                error_log('VOF Error: Failed to set cross-domain SECURE_AUTH_COOKIE');
            }
        }
        
        // Also set cookies at site root path
        if (SITECOOKIEPATH != COOKIEPATH) {
            $site_options = $cookie_options;
            $site_options['path'] = SITECOOKIEPATH;
            
            setcookie(AUTH_COOKIE, $auth_cookie, $site_options);
            setcookie(LOGGED_IN_COOKIE, $logged_in_cookie, $site_options);
            if ($secure) {
                setcookie(SECURE_AUTH_COOKIE, $auth_cookie, $site_options);
            }
        }
        
        error_log('VOF Debug: Successfully set both standard and cross-domain cookies');
    } else {
        error_log('VOF Debug: Headers already sent, cannot set cookies');
    }
    
    // Update current user
    wp_set_current_user($user_id);
    
    // Set special user meta to indicate we've set custom cookies
    update_user_meta($user_id, 'vof_custom_auth_cookies_set', time());
    
    // Force no caching
    nocache_headers();
    
    // Reset static flag
    $processing = false;
}

// Add action to properly handle logout and clear all cookies
add_action('wp_logout', 'VOF\vof_clear_all_auth_cookies', 1, 0);

// Function to properly clear all auth cookies
function vof_clear_all_auth_cookies() {
    // Check if we should skip cookie clearing (during password change)
    $user_id = get_current_user_id();
    if ($user_id) {
        $prevent_logout = get_user_meta($user_id, 'vof_prevent_pw_reset_logout', true);
        $password_reset = get_user_meta($user_id, 'vof_last_password_reset', true);
        
        // If we're in the middle of a password reset, don't clear cookies
        if ($prevent_logout || ($password_reset && time() - (int)$password_reset < 300)) {
            error_log('VOF Debug: Skipping cookie clear during password reset for user: ' . $user_id);
            return;
        }
    }
    
    error_log('VOF Debug: Explicitly clearing all auth cookies including cross-domain ones');
    
    // Get host and determine cookie domain
    $host = parse_url(get_site_url(), PHP_URL_HOST);
    $domain_parts = explode('.', $host);
    $root_domain = count($domain_parts) > 2 ? 
        implode('.', array_slice($domain_parts, -2)) : 
        $host;
    
    error_log('VOF Debug: Using root domain for clearing cookies: .' . $root_domain);
    
    // Common cookie options for clearing
    $cookie_options = [
        'expires' => time() - YEAR_IN_SECONDS, // Set to past time to expire
        'path' => COOKIEPATH,
        'domain' => '.' . $root_domain, // Note the leading dot
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    
    // Clear all WordPress auth cookies with our custom domain setting
    setcookie(AUTH_COOKIE, '', $cookie_options);
    setcookie(SECURE_AUTH_COOKIE, '', $cookie_options);
    setcookie(LOGGED_IN_COOKIE, '', $cookie_options);
    
    // Also clear with SITECOOKIEPATH
    $cookie_options['path'] = SITECOOKIEPATH;
    setcookie(AUTH_COOKIE, '', $cookie_options);
    setcookie(SECURE_AUTH_COOKIE, '', $cookie_options);
    setcookie(LOGGED_IN_COOKIE, '', $cookie_options);
    
    // Also clear cookies without domain restriction
    $cookie_options['domain'] = '';
    setcookie(AUTH_COOKIE, '', $cookie_options);
    setcookie(SECURE_AUTH_COOKIE, '', $cookie_options);
    setcookie(LOGGED_IN_COOKIE, '', $cookie_options);
}

// Handle login authentication - add better error logging and fallback
add_filter('authenticate', function($user, $username, $password) {
    if (!empty($username) && is_wp_error($user)) {
        // Try to find user by username or email
        $user_data = get_user_by('login', $username);
        if (!$user_data) {
            $user_data = get_user_by('email', $username);
        }
        
        if ($user_data) {
            error_log('VOF Debug: Login failed for user: ' . $user_data->ID . ' - Error: ' . $user->get_error_message());
            
            // Check for recent password reset
            $password_reset = get_user_meta($user_data->ID, 'vof_last_password_reset', true);
            $password_changed = get_user_meta($user_data->ID, 'vof_password_changed', true);
            
            // Special handling for recent password changes (within 5 minutes)
            if (($password_reset && time() - (int)$password_reset < 300) || 
                ($password_changed && time() - (int)$password_changed < 300)) {
                
                error_log('VOF Debug: Special handling for recent password change user: ' . $user_data->ID);
                
                // For users who recently changed passwords, provide more detailed error info
                if (!empty($password) && wp_check_password($password, $user_data->user_pass, $user_data->ID)) {
                    error_log('VOF Debug: Password is correct, login issue is related to authentication state');
                    
                    // If password is correct but login still failed, force login and fix cookies
                    wp_set_current_user($user_data->ID);
                    wp_set_auth_cookie($user_data->ID, true);
                    vof_set_cross_domain_auth_cookies($user_data->ID, true);
                    
                    return $user_data;
                }
            }
        }
    }
    
    return $user;
}, 999, 3);

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