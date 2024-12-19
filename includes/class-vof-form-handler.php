<?php
namespace VOF;

if (!defined('ABSPATH')) exit;


// 3. Form Data Handling Implementation

class VOF_Form_Handler {
    public function __construct() {
        // Override AJAX submission handlers
        add_action('wp_ajax_rtcl_update_listing', [$this, 'handle_submission'], 9);
        add_action('wp_ajax_nopriv_rtcl_update_listing', [$this, 'handle_submission'], 9);  
    }


    public function handle_submission() {
        // Get form data
        $form_data = $this->get_sanitized_form_data(); // where is this implementation?	
        // CASE 1: Guest user
        if (!is_user_logged_in()) {
            $this->handle_guest_submission($form_data);
            return;
        }	
        // CASE 2: No active subscription
        if (!VOF_Subscription::has_active_subscription()) { // ask for has active subcription logic
            $this->handle_no_subscription_submission($form_data);
            return;  
        }	
        // CASE 3: Active subscription - use original flow
        $this->handle_regular_submission($form_data);
    }

    private function handle_guest_submission($data) {
        // Store temporary listing
        $listing_id = $this->store_temporary_listing($data);	

        // Create Stripe checkout session
        $session = VOF_Stripe::create_checkout_session([
            'listing_id' => $listing_id,
            'mode' => 'subscription',
            'success_url' => home_url("/listing-success?id={$listing_id}"),
            'cancel_url' => home_url("/listing-cancel?id={$listing_id}")
        ]);	
        wp_send_json_success([
            'redirect' => $session->url
        ]);
    }

    private function handle_no_subscription_submission($data) {
        // Similar to guest flow but skip user creation
        $listing_id = $this->store_temporary_listing($data);	

        $session = VOF_Stripe::create_checkout_session([
            'listing_id' => $listing_id,
            'customer_id' => get_current_user_id(),
            'mode' => 'subscription'
          ]);

        wp_send_json_success([
            'redirect' => $session->url
        ]);
    }

    private function handle_regular_submission($data) {
        // Use original submission logic
        do_action('rtcl_listing_form_submit', $data);
    }
  
	private function get_sanitized_form_data() {
		// Leverage core sanitization if available
		//if (method_exists('Rtcl\Controllers\Forms\Form_Handler', 'get_sanitized_listing_data')) {
		//	return rtcl()->factory->get_form('listing')->get_sanitized_listing_data();
		//}

            // Use RTCL's Form Handler directly
        if (class_exists('Rtcl\Controllers\Forms\Form_Handler')) {
            $form_handler = new \Rtcl\Controllers\Forms\Form_Handler();
            return $form_handler->get_sanitized_listing_data();
        }
		// Fallback sanitization
		return array(
			'title' => sanitize_text_field($_POST['title'] ?? ''),
			'description' => wp_kses_post($_POST['description'] ?? ''),
			'price' => sanitize_text_field($_POST['price'] ?? ''),
			'category' => absint($_POST['category'] ?? 0),
			'location' => absint($_POST['location'] ?? 0),
			'email' => sanitize_email($_POST['email'] ?? ''),
			'phone' => sanitize_text_field($_POST['phone'] ?? '')
		);
	}


    private function store_temporary_listing($data) {
        $post_data = array(
            'post_title'    => $data['title'],
            'post_content'  => $data['description'],
            'post_status'   => 'draft',
            'post_type'     => 'rtcl_listing'
        );
        
        $listing_id = wp_insert_post($post_data);
        
        // Store additional listing meta
        foreach ($data as $key => $value) {
            if (!in_array($key, ['title', 'description'])) {
                update_post_meta($listing_id, $key, $value);
            }
        }
        
        return $listing_id;
    }
} 