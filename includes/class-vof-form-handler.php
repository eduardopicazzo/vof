<?php

namespace VOF;
/**
 * 
 * All the tricky (and helper) stuff goes here.
 * TODO: Rename to VOF_Listing_Form_Handler.
 * 
 */

/**
 * 
 * ##############################################################################################
 * #                NOT FULLY WORKING (needs checks login and subscription active)              #
 * ##############################################################################################
 *        [X] MOST LIKELY CATEGORY IS DONE OVERRIDEN 
 *        [ ] BUT MISSING: SUBSCRIPTION CHECK. OVERRIDE FOR NEW VENDORS 
 *        [ ] MISSING: IS VALID POST AS FREE OVERRIDE (NOT A PROBLEM NOW BUT WILL BE LATER) 
 * ##############################################################################################
 * 
 */

use RtclStore\Controllers\Ajax\Membership as StoreMembership;
use RtclPro\Controllers\Hooks\FilterHooks as ProFilterHooks;
use RtclStore\Helpers\Functions as StoreFunctions;
 
 class VOF_Form_Handler {
 
     public function __construct() {
         // Hook into 'plugins_loaded' to ensure all plugins are loaded first
         add_action('plugins_loaded', [$this, 'vof_init_hooks'], 20);
     }
     
     public function vof_init_hooks() {
        // Debugging: Log all current callbacks for the hook
        $this->vof_log_current_callbacks('rtcl_ajax_category_selection_before_post');
 
        // Remove original filters with exact parameters
        remove_filter('rtcl_ajax_category_selection_before_post', 
            [StoreMembership::class, 'is_valid_to_post_at_category'], 10);
 
        remove_filter('rtcl_ajax_category_selection_before_post', 
            [ProFilterHooks::class, 'ajax_filter_modify_data'], 10);
 
        // Add our filter with appropriate priority
        add_filter('rtcl_ajax_category_selection_before_post', 
            [$this, 'vof_is_valid_to_post_at_category'], -999);
 
        // Remove original REST API filter
        remove_filter('rtcl_rest_api_form_category_before_post', 
            [StoreMembership::class, 'is_valid_to_post_at_category_rest_api'], 10);
 
        // Add our REST API filter
        add_filter('rtcl_rest_api_form_category_before_post', 
             [$this, 'vof_is_valid_to_post_at_category_rest_api'], -999);

        // check if exist
             
        // Override core category validation
        add_filter('rtcl_category_validation', function($is_valid, $cat_id) {
            return true; // Always allow posting
        }, -999, 2);
     
        // Override membership category validation
        add_filter('rtcl_membership_category_validation', function($is_valid, $cat_id) {
            return true;
        }, -999, 2);

            
    }
 
     public function vof_is_valid_to_post_at_category($response) {
         // Debugging
         error_log('VOF Debug - vof_is_valid_to_post_at_category called');
 
         // Get category ID from the response array
         $cat_id = isset($response['cat_id']) ? absint($response['cat_id']) : 0;
 
         // Initialize response array if not already
         if (!is_array($response)) {
             $response = array(
                 'success' => true,
                 'message' => array(),
                 'child_cats' => '',
                 'cat_id' => $cat_id
             );
         }
 
         // CASE 3: use original validation if user is logged in and has active subscription.
         if (is_user_logged_in() && \VOF\VOF_Subscription::has_active_subscription()) {
             $is_valid = StoreFunctions::is_valid_to_post_at_category($cat_id);
             if (!$is_valid) {
                 $response['success'] = false;
                 $response['message'][] = __('You are not allowed to post in this category with your current membership.', 'vendor-onboarding-flow');
                 return $response;
             }
         }
 
         // CASE 1 & 2: Always allow posting for onboarding flow.
         $response['success'] = true;
         $response['message'] = array(); // Clear any error messages.
 
         // Debugging
         error_log('VOF Debug - Response: ' . print_r($response, true));
 
         return $response;
     }
 
     public function vof_is_valid_to_post_at_category_rest_api($response) {
         return $this->vof_is_valid_to_post_at_category($response);
     }
 
     private function vof_log_current_callbacks($hook_name) {
         global $wp_filter;
         if (isset($wp_filter[$hook_name])) {
             $callbacks = $wp_filter[$hook_name];
             error_log("VOF Debug - Registered callbacks for {$hook_name}:");
             error_log(print_r($callbacks, true));
         } else {
             error_log("VOF Debug - No callbacks registered for {$hook_name}");
         }
     }
 }