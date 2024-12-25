<?php
namespace VOF;

class VOF_API {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('vof/v1', '/save-temp-listing', [
            'methods' => 'POST',
            'callback' => [$this, 'save_temp_listing'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function save_temp_listing($request) {
        $params = $request->get_params();

        // creat temporary post
        $listing_data = array(
            'post_title'    => sanitize_text_field($params['title']),
            'post_content'  => wp_kses_post($params['description'] ?? ''),
            'post_status'   => 'draft',
            'post_type'     => 'rtcl_listing'
        );

        $listing_id = wp_insert_post($listing_data);

        if (is_wp_error($listing_id)) {
            return new \WP_Error('listing_error', $listing_id->get_error_message(), array('status' => 500));
        }
    
        // Save meta fields
        foreach ($params as $key => $value) {
            if (strpos($key, '_rtcl_') === 0 || strpos($key, 'rtcl_') === 0) {
                update_post_meta($listing_id, $key, sanitize_text_field($value));
            }
        }
    
        // Store category and type
        if (!empty($params['_category_id'])) {
            wp_set_object_terms($listing_id, (int)$params['_category_id'], 'rtcl_category');
        }
        
        if (!empty($params['_ad_type'])) {
            update_post_meta($listing_id, '_rtcl_ad_type', sanitize_text_field($params['_ad_type']));
        }
    
        return new \WP_REST_Response([
            'success' => true,
            'listing_id' => $listing_id,
            'message' => 'Listing saved successfully'
        ], 200);
    }
}