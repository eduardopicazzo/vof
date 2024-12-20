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

require_once plugin_dir_path(__FILE__) . 'includes/class-vof-core.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-vof-dependencies.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-vof-listing.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-vof-subscription.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-vof-form-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-vof-stripe.php';

// Initialize the plugin
function VOF() {
    return VOF_Core::instance();
}

VOF();
