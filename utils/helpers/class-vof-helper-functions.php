<?php

namespace VOF\Utils\Helpers;
use Rtcl\Helpers\Functions;

class VOF_Helper_Functions {
    public function __construct() {
		// Register temporary post status
		add_action('init', [$this, 'vof_register_temp_post_status']);

		// add_filter('rtcl_verification_listing_form_phone_field', [$this, 'vof_validate_phone_field'], 10, 2);

		// Add filter for form submission validation
		// add_filter('rtcl_listing_form_validate', [$this, 'vof_validate_listing_phone'], 10, 2);

		// Add filter for AJAX submission validation
		// add_filter('rtcl_ajax_listing_form_validate', [$this, 'vof_validate_ajax_phone'], 10, 1); 

		// New email validation hooks
		// add_filter('rtcl_listing_form_validate', [$this, 'vof_validate_listing_email'], 10, 2);
		// add_filter('rtcl_ajax_listing_form_validate', [$this, 'vof_validate_ajax_email'], 10, 1);
    }

	// MANUAL FULFILLMENT UTILITY FUNCTION
	// Path: wp-content/plugins/vendor-onboarding-flow/utils/helpers/class-vof-helper-functions.php

	// Path: wp-content/plugins/vendor-onboarding-flow/utils/helpers/class-vof-helper-functions.php

	/**
	 * Manually fulfill membership for a user
	 * 
	 * @param int $user_id The user ID to fulfill membership for
	 * @param string $subscription_id The Stripe subscription ID
	 * @param string $product_name The product name (optional)
	 * @param int $pricing_id The RTCL pricing tier ID (optional)
	 * @return bool|WP_Error Success or failure
	 */
	public static function vof_manually_fulfill_subscription($user_id, $subscription_id, $product_name = null, $pricing_id = null) {
	    // Basic validation
	    if (!$user_id || !$subscription_id) {
	        return new \WP_Error('invalid_parameters', 'User ID and subscription ID are required');
	    }
	
	    // Build subscription data
	    $subscription_data = [
	        'subscription_id' => $subscription_id,
	        'product_name' => $product_name ?: 'Manually Restored Subscription',
	        'status' => 'active',
	        'current_period_end' => strtotime('+1 month')
	    ];
	
	    // Add pricing tier ID if provided
	    if ($pricing_id) {
	        $subscription_data['rtcl_pricing_tier_id'] = $pricing_id;
	    }
	
	    // Get fulfillment handler and call manual fulfillment
	    $fulfillment_handler = \VOF\Includes\Fulfillment\VOF_Fulfillment_Handler::getInstance();
	    return $fulfillment_handler->vof_manual_fulfill_membership($user_id, $subscription_data);
	}

	public static function vof_register_temp_post_status() {
		register_post_status('vof_temp', [
			'label' => _x('Temporary Listing','post status', 'vof'),
			'public' => false, // not publicly accessible
			'exclude_from_search' => true, // exclude from search
			'show_in_admin_all_list' => true, // visible in admin "ALL" posts
			'show_in_admin_status_list' => true, // visible in admin "Status" dropdown
			'label_count' => _n_noop(
				'VOF Temps <span class="count">(%s)</span>', 
				'VOF Temps <span class="count">(%s)</span>', 
				'vof'
			)
		]);
	}

	// Add new email validation methods
	public static function vof_validate_email($email) { // maybe not used... 
		error_log('VOF Debug: vof_validate_email called with data: ' . print_r($email, true));

		// TODO: Add validation for the email field
		$is_valid = null ;/** your validation logic */

		$email = sanitize_email($email);
		$is_email_confirmed = null;

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new \WP_Error( 'registration-error-invalid-email', esc_html__( 'Please provide a valid email address.', 'classified-listing' ) );
		}

		// check if email is confirmed
		// $is_email_confirmed = \VOF\Utils\Helpers\VOF_Helper_Functions::vof_validate_email_confirmed($email);

		if ( email_exists( $email )) {
			return new \WP_Error( 'registration-error-email-exists', apply_filters( 'rtcl_registration_error_email_exists',
				esc_html__( 'An account has already registered with this email address. Please log in instead.', 'classified-listing' ), $email ) );
		}


		// if (!$is_email_confirmed) {
		// 	return new \WP_Error( 'registration-error-email-not-confirmed', apply_filters( 'rtcl_registration_error_email_not_confirmed',
		// 		esc_html__( 'Please confirm your email address.', 'classified-listing' ), $email ) );
		// }

		// if (checkout_complete()) { MAYBE THIS IS THE PLACE TO CREATE USER
			// 	create user
		// }

		$is_valid = true;		

		// TODO: Sync the email field with the rtcl fields if validations are successful
		// Sync to original field
		// $_POST['email'] = $data['vof_email'];  // or however you're handling form data

		Functions::add_notice(esc_html__('Hello. Email validation from [ VOF validation ]'));
		return $is_valid;
	}
	
	public static function vof_validate_email_confirm($data) {
		error_log('VOF Debug: vof_validate_email_confirm called with data: ' . print_r($data, true));
		// TODO: Validate against the email field
		Functions::add_notice(esc_html__('Hello. Email confirmation validation from [ VOF validation ]'));
		return $data;
	}

	public static function vof_validate_phone($phone) {
		error_log('VOF Debug: vof_validate_phone called with data: ' . print_r($phone, true));

		$is_valid = false;
		$sanitized_phone = null;
		$is_sanitized_phone_valid = false;
		$phone_exists = false;


		if ( empty( $phone )) {
			return new \WP_Error( '', esc_html__( 'Please enter a 10 digit phone number.') );
		}

		$sanitized_phone = \Rtcl\Helpers\Functions::sanitize($phone);
		/**
		 * This line checks if the sanitized phone number mathces a specific pattern; exacctly
		 * 10 digits, no more, no less. the preg_match() function returns TRUE (1) if the pattern matches, 
		 * and FALSE (0) if it doesn't.
		 * 
		 * For example: 
		 * 
		 * 1234567890 would be valid
		 * 123-456-7890 would be invalid
		 * 12345 would be invalid
		 * 12345678901 would be invalid
		 * 
		 * The pattern is defined as '/^\d{10}$/' which means:
		 * - '^' asserts the start of the string.
		 * - '\d' matches any digit (0-9).
		 * - '{10}' specifies exactly 10 occurrences of the preceding element.
		 * - '$' asserts the end of the string.
		 * 
		 * This ensures that the phone number is exactly 10 digits long, with no additional characters or spaces.
		 */
		$is_sanitized_phone_valid = preg_match('/^\d{10}$/', $sanitized_phone);
		if (!$is_sanitized_phone_valid) {
			return new \WP_Error( '', esc_html__( 'Please enter a valid 10 digit phone number.', 'classified-listing' ) );		
		}

		$phone_exists = \Rtcl\Helpers\Functions::phone_exists( $sanitized_phone );

		if ( $phone_exists) {
			return new \WP_Error( '', esc_html__( 'An account is already registered with your phone number. Please log in.') );
		}

		$is_valid = true;

		// TODO: Sync the phone field with the rtcl fields if validations are successful
		// if ($is_valid) {
			// // Sync to original field
			// $_POST['phone'] = $data['vof_phone'];
		// }
			
		Functions::add_notice(esc_html__('Hello. Phone validation from [ VOF validation ]'));
		return $is_valid;
	}

	public static function vof_validate_whatsapp_number($data) { 
		error_log('VOF Debug: vof_validate_whatsapp_number called with data: ' . print_r($data, true));

		$is_valid = self::vof_validate_phone($data);
		
		// TODO: Sync the whatsapp number field with the rtcl fields if validations are successful
        // if ($is_valid) {
        // // Sync to original field
        	// $_POST['_rtcl_whatsapp_number'] = $data['vof_whatsapp_number'];
        // }	

		Functions::add_notice(esc_html__('Hello. Whatsapp number validation from [ VOF validation ]'));
		return $is_valid;
	}

    /**
	 * @return bool
	 */
	public static function vof_is_gallery_image_required($post_id) { 
        if ( Functions::is_gallery_image_required() && 
        ( ! $post_id || ! count( Functions::get_listing_images( $post_id ) ) ) ) {
            Functions::add_notice(
                esc_html__( 'Image is required. Please select an image.', 'classified-listing' ),
                'error',
                'rtcl_listing_gallery_image_required'
            );
        }
    }
	
	public static function vof_has_active_subscription($user_id = null) {
		// Check if rtclStore function exists and plugin is active
		if (!function_exists('rtclStore')) {
			return false;
		}

		// Get store instance safely
		$store = rtclStore();
		if (!$store) {
			return false;
		}

        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        // Check WooCommerce Subscriptions if available
        if (class_exists('WC_Subscriptions')) {
            $subscriptions = wcs_get_users_subscriptions($user_id);
            foreach ($subscriptions as $subscription) {
                if ($subscription->has_status('active')) {
                    return true;
                }
            }
        }

	    if (class_exists('\RtclStore\Models\Membership')) {
	        // $membership = rtclStore()->factory->get_membership();
	        $membership = $store->factory->get_membership();
	        if ($membership && !$membership->is_expired()) {
	            return true;
	        }
	    }
    }

    public static function vof_create_username($email, $args = [], $suffix = '') {
        // Try to use RTCL's function if available
        if (class_exists('\Rtcl\Helpers\Functions')) {
            try {
                return \Rtcl\Helpers\Functions::create_new_user_username($email, $args, $suffix);
            } catch (\Exception $e) {
                error_log('VOF: Failed to use RTCL username creation, falling back to internal method: ' . $e->getMessage());
            }
        }

        // Fallback implementation if RTCL's function is not available
        $username_parts = [];

        // Get email parts
        $email_parts = explode('@', $email);
        $email_username = $email_parts[0];

        // Filter out common prefixes
        $common_prefixes = [
            'sales',
            'hello',
            'mail',
            'contact',
            'info',
        ];

        if (in_array($email_username, $common_prefixes, true)) {
            // Use domain part instead
            $email_username = $email_parts[1];
        }

        $username_parts[] = sanitize_user($email_username, true);

        // Create base username
        $username = strtolower(implode('_', $username_parts));
        
        if ($suffix) {
            $username .= $suffix;
        }
        
        $username = sanitize_user($username, true);

        // Handle illegal usernames
        $illegal_logins = (array) apply_filters('illegal_user_logins', []);
        if (in_array(strtolower($username), array_map('strtolower', $illegal_logins), true)) {
            // Generate a random username instead
            $random_suffix = '_' . zeroise(wp_rand(0, 9999), 4);
            return self::vof_create_username(
                $email, 
                ['first_name' => 'vof_user' . $random_suffix]
            );
        }

        // Ensure uniqueness
        if (username_exists($username)) {
            $suffix = '-' . zeroise(wp_rand(0, 9999), 4);
            return self::vof_create_username($email, $args, $suffix);
        }

        return apply_filters('vof_new_user_username', $username, $email, $args, $suffix);
    }
}

new VOF_Helper_Functions();