<?php
class VOF_Ajax {
    
    public function __construct() {
        add_action('wp_ajax_vof_save_temp_listing', array($this, 'vof_save_temp_listing'));
        add_action('wp_ajax_nopriv_vof_save_temp_listing', array($this, 'vof_save_temp_listing'));
    }

    public function vof_save_temp_listing() {
        // Verify nonce
        if (!check_ajax_referer('vof_temp_listing_nonce', 'security', false)) {
            wp_send_json_error(array('message' => 'Invalid security token'));
        }

        // Get form data
        $title = sanitize_text_field($_POST['title'] ?? '');
        $description = wp_kses_post($_POST['description'] ?? '');
        // Add other fields as needed

        // Create temporary post
        $listing_data = array(
            'post_title'    => $title,
            'post_content'  => $description,
            'post_status'   => 'draft', // or custom status like 'temporary'
            'post_type'     => 'rtcl_listing', // adjust if your listing post type is different
        );

        $listing_id = wp_insert_post($listing_data);

        if (is_wp_error($listing_id)) {
            wp_send_json_error(array('message' => $listing_id->get_error_message()));
        }

        // Save additional meta data
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'rtcl_') === 0) { // Only save listing-related fields
                update_post_meta($listing_id, $key, sanitize_text_field($value));
            }
        }

        // Handle file uploads if any
        if (!empty($_FILES)) {
            // You'll need to implement file handling here
            // Make sure to handle featured image and gallery images
        }

        // Store the listing ID in a session or temporary storage
        if (!session_id()) {
            session_start();
        }
        $_SESSION['temp_listing_id'] = $listing_id;

        wp_send_json_success(array(
            'listing_id' => $listing_id,
            'message' => 'Listing saved successfully'
        ));
    }
}

// Initialize the class
new VOF_Ajax();