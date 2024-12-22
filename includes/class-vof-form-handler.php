<?php

namespace VOF;
/**
 * 
 * All the tricky (and helper) stuff goes here.
 * TODO: Rename to VOF_Listing_Form_Handler.
 * 
 */

use RtclStore\Helpers\Functions;

class VOF_Form_Handler {

    public function __construct() {
        // Remove original filter.
        remove_filter( 'rtcl_ajax_category_selection_before_post', [ 'RtclStore\Controllers\Ajax\Membership', 'is_valid_to_post_at_category' ] );

        // Add our filter with proper callback reference
        add_filter( 'rtcl_ajax_category_selection_before_post', [ $this, 'vof_is_valid_to_post_at_category' ], 20, 1);

        // Remove original REST API filter.
        remove_filter( 'rtcl_rest_api_form_category_before_post', [ 'RtclStore\Controllers\Ajax\Membership', 'is_valid_to_post_at_category_rest_api' ] );

        // Add our REST API filter with proper callback reference
        add_filter( 'rtcl_rest_api_form_category_before_post', [ $this, 'vof_is_valid_to_post_at_category_rest_api' ], 20, 1);


       // TODO: check if these are needed.
       // add_action('wp_ajax_rtcl_update_listing', [$this, 'handle_submission']);
       // add_action('wp_ajax_nopriv_rtcl_update_listing', [$this, 'handle_submission']);
    }

    public function handle_submission() {
       // Handle form submission logic here...
       // Use methods for guest submission, no subscription submission, etc.
       wp_send_json_success(); // Example response; customize as needed.
   }

   private function get_sanitized_form_data() {
       return array(
           'title' => sanitize_text_field($_POST['title'] ?? ''),
           // Other fields...
       );
   }

   public function vof_is_valid_to_post_at_category($response) {
        // Get category ID from the request
        $cat_id = isset($response['cat_id']) ? absint($response['cat_id']) : 0;
       
        error_log('vof_is_valid_to_post_at_category input response: ' . print_r($response, true));

        // Initialize response array if not already
        if (!is_array($response)) {
            $response = array(
                'success' => true,
                'message' => array(),
                'child_cats' => '',
                'cat_id' => $cat_id
            );
        }

        // CASE 3: use original filter if user is logged in and membership is active.
        if(is_user_logged_in() && VOF_Subscription::has_active_subscription()) {
            $is_valid = Functions::is_valid_to_post_at_category($cat_id);
           if (!$is_valid) {
            $response['success'] = false;
            return $response;
           }
        }

        // CASE 1 & 2: Always allow for onboarding.
        $response['success'] = true;
        $response['message'] = array(); // Clear any error messages.

        error_log('vof_is_valid_to_post_at_category output [VOF 1]: ' . print_r($response, true));
        return $response;
    }

   // public static function vof_is_valid_to_post_at_category_rest_api($is_valid, $cat_id) {
    public function vof_is_valid_to_post_at_category_rest_api($response) {
        return $this->vof_is_valid_to_post_at_category($response);
        

       // // Get category ID from the request
       // $cat_id = isset($_REQUEST['cat_id']) ? absint($_REQUEST['cat_id']) : 0;
       // 
       // error_log('vof_is_valid_to_post_at_category_rest_api: ' . $is_valid . ' ' . $cat_id);

       // // CASE 3: use original filter if user is logged in and membership is active.
       // if(is_user_logged_in() && VOF_Subscription::has_active_subscription()) {
       //    //  $is_valid = Functions::is_valid_to_post_at_category($cat_id);
       //    Functions::is_valid_to_post_at_category($cat_id);
       // }  
       // 
       // // CASE 1 & 2: Return true for onboarding.
       // $is_valid = true;
       // return $is_valid;
    }
}
