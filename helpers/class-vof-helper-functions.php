<?php

namespace VOF\Helpers;
use Rtcl\Helpers\Functions;

class VOF_Helper_Functions {
    public function __construct() {
		// add_filter('rtcl_verification_listing_form_phone_field', [$this, 'vof_validate_phone_field'], 10, 2);

		// Add filter for form submission validation
		// add_filter('rtcl_listing_form_validate', [$this, 'vof_validate_listing_phone'], 10, 2);

		// Add filter for AJAX submission validation
		// add_filter('rtcl_ajax_listing_form_validate', [$this, 'vof_validate_ajax_phone'], 10, 1); 

		// New email validation hooks
		// add_filter('rtcl_listing_form_validate', [$this, 'vof_validate_listing_email'], 10, 2);
		// add_filter('rtcl_ajax_listing_form_validate', [$this, 'vof_validate_ajax_email'], 10, 1);
    }


	// Add new email validation methods
	public static function vof_validate_email($email) { 
		error_log('VOF Debug: vof_validate_email called with data: ' . print_r($email, true));

		// TODO: Add validation for the email field
		$is_valid = null ;/** your validation logic */

		$email = sanitize_email($email);
		$is_email_confirmed = null;

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new \WP_Error( 'registration-error-invalid-email', esc_html__( 'Please provide a valid email address.', 'classified-listing' ) );
		}

		// check if email is confirmed
		// $is_email_confirmed = \VOF\Helpers\VOF_Helper_Functions::vof_validate_email_confirmed($email);

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

	// // Existing render validation
	// public static function vof_validate_phone_field($field, $phone) {
	// 	// Add data attributes for frontend validation
	// 	$phone_pattern = '/^\+?[\d\s-]{10,}$/';
	// 	$field = str_replace('/>', 
	// 		'data-validation-pattern="' . $phone_pattern . '" ' .
	// 		'data-validation-message="Please enter a valid phone number with country code (e.g., +1xxxxxxxxxx)" />', 
	// 		$field
	// 	);
	// 	return $field;
	// }

	// New submission validation
	// public function vof_validate_listing_phone($data) {
	// 	if (empty($data['phone'])) {
	// 		return $data;
	// 	}

	// 	$phone = sanitize_text_field($data['phone']);
	// 	if (!preg_match('/^\+?[\d\s-]{10,}$/', $phone)) {
	// 		throw new \Exception(esc_html__('Please enter a valid phone number with country code (e.g., +1xxxxxxxxxx)', 'vendor-onboarding-flow'));
	// 	}

	// 	return $data;
	// }

	// New AJAX validation
	// public function vof_validate_ajax_phone($response) {
	// 	if (isset($_POST['phone'])) {
	// 		$phone = sanitize_text_field($_POST['phone']);
	// 		if (!preg_match('/^\+?[\d\s-]{10,}$/', $phone)) {
	// 			$response['success'] = false;
	// 			$response['message'] = [__('Please enter a valid phone number with country code (e.g., +1xxxxxxxxxx)', 'vendor-onboarding-flow')];
	// 		}
	// 	}
	// 	return $response;
	// }

    /**
	 * @return bool
	 */
	// public static function vof_do_registration_from_lsting_form() {}

    // public static function vof_listingFormPhoneIsRequired() {
	// 	return apply_filters( 'rtcl_listing_form_phone_is_required', true );
	// }

    // public static function vof_email_exists($email) {
    //     if ( email_exists( sanitize_email( $email ) ) ) {
	// 		return new WP_Error( 'registration-error-email-exists', apply_filters( 'rtcl_registration_error_email_exists',
	// 			esc_html__( 'An account is already registered with your email address. Please log in.', 'classified-listing' ), $email ) );
	// 	}
	// }

}

new VOF_Helper_Functions();