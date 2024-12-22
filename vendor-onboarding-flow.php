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

// Load dependencies first
// require_once WP_PLUGIN_DIR . '/classified-listing-store/app/Helpers/Functions.php';

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


/*
// Early hook registration on plugins_loaded
add_action('plugins_loaded', function() {
    static $registered = false;
    if ($registered) return;
    $registered = true;
    
    // Remove existing membership hooks
    if (class_exists('RtclStore\Controllers\Hooks\MembershipHook')) {
        remove_action('rtcl_before_add_edit_listing_before_category_condition', 
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category'], 10);
        remove_action('rtcl_before_add_edit_listing_into_category_condition',
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category'], 10);
        remove_action('rtcl_ajax_category_selection_before_post',
        ['RtclStore\Controllers\Ajax\Membership', 'is_valid_to_post_at_category'], 10);
    }

    // Add our custom category validation
    $category_validator = function($post_id, $category_id = null) {
        if (!is_user_logged_in()) {
            // CASE 1: Non-logged users can only post in free categories
            //return VOF_Categories::is_free_category($category_id);
            return true;
        }
        
        if (VOF_Subscription::has_active_subscription()) {
            // CASE 2: Subscribed users - use original membership validation
            if (class_exists('RtclStore\Controllers\Hooks\MembershipHook')) {
                return \RtclStore\Controllers\Hooks\MembershipHook::verify_membership_into_category($post_id, $category_id);
            }
            return true;
        }
        
        // CASE 3: Logged in but no subscription - only free categories
        //return VOF_Categories::is_free_category($category_id);
        return true;
    };

    // Register for all relevant hooks with priority 1
    add_action('rtcl_before_add_edit_listing_before_category_condition', 
        fn($post_id) => $category_validator($post_id), 1);
        
    add_action('rtcl_before_add_edit_listing_into_category_condition', 
        fn($post_id, $category_id) => $category_validator($post_id, $category_id), 1, 2);
        
    add_action('rtcl_ajax_category_selection_before_post',
        fn($category_id) => $category_validator(null, $category_id), 1);

}, 5); // Early priority on plugins_loaded
*/


