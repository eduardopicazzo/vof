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
require_once VOF_PLUGIN_DIR . 'helpers/class-vof-helper-functions.php';

// Then load other classes
require_once VOF_PLUGIN_DIR . 'includes/class-vof-constants.php';
require_once VOF_PLUGIN_DIR . 'includes/class-vof-subscription.php';
require_once VOF_PLUGIN_DIR . 'includes/class-vof-core.php';
require_once VOF_PLUGIN_DIR . 'includes/class-vof-api.php';
require_once VOF_PLUGIN_DIR . 'includes/class-vof-assets.php';
require_once VOF_PLUGIN_DIR . 'includes/class-vof-listing.php';
require_once VOF_PLUGIN_DIR . 'includes/class-vof-form-handler.php';

// Initialize the plugin
function vof() {
    return VOF_Core::instance();
}

// Start the plugin
add_action('plugins_loaded', 'VOF\vof', 0);