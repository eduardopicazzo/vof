<?php

namespace VOF\Helpers;
use Rtcl\Helpers\Functions;

class VOF_Helper_Functions {
    public function __construct() {
		// add_filter('rtcl_verification_listing_form_phone_field', [$this, 'vof_validate_phone_field'], 10, 2);

		// Add filter for form submission validation
		add_filter('rtcl_listing_form_validate', [$this, 'vof_validate_listing_phone'], 10, 2);

		// Add filter for AJAX submission validation
		add_filter('rtcl_ajax_listing_form_validate', [$this, 'vof_validate_ajax_phone'], 10, 1); 

		// New email validation hooks
		add_filter('rtcl_listing_form_validate', [$this, 'vof_validate_listing_email'], 10, 2);
		add_filter('rtcl_ajax_listing_form_validate', [$this, 'vof_validate_ajax_email'], 10, 1);
    }


	// Add new email validation methods
	public static function vof_validate_email($data) {
		// $email = sanitize_email($data['rtcl-email']);
		$email = sanitize_email($data);
		// $email_confirm = sanitize_email($data['vof-email-confirm']);
		$email_confirm = sanitize_email($data['vof-email-confirm']);
		// $user_exists = email_exists($email);
		$user_exists = email_exists($email);

		if (empty($email)) {
			throw new \Exception(esc_html__('Email address is required [VOF validation]', 'vendor-onboarding-flow'));
		}

		$email = sanitize_email($data['vof-email']);
		if (!is_email($email)) {
			throw new \Exception(esc_html__('Please enter a valid email address [VOF validation]', 'vendor-onboarding-flow'));
		}

		if ($user_exists) {
			throw new \Exception(esc_html__('Email address is already registered [VOF validation]', 'vendor-onboarding-flow'));
		}

		// Validate email confirmation if present
		// if (!empty($data['vof-email-confirm']) && $data['rtcl-email'] !== $data['vof-email-confirm']) {
		if (!empty($data['vof-email-confirm']) && ($email !== $email_confirm)) {
			throw new \Exception(esc_html__('Email addresses do not match [VOF validation]', 'vendor-onboarding-flow'));
		}


		return $data;
	}
	
    // public function vof_validate_ajax_email($response) {
    //     if (isset($_POST['vof-email'])) {
    //         $email = sanitize_email($_POST['vof-email']);
    //         if (!is_email($email)) {
    //             $response['success'] = false;
    //             $response['message'][] = __('Please enter a valid email address', 'vendor-onboarding-flow');
    //         }

    //         // Check email confirmation
    //         if (isset($_POST['vof-email-confirm']) && $_POST['vof-email'] !== $_POST['vof-email-confirm']) {
    //             $response['success'] = false;
    //             $response['message'][] = __('Email addresses do not match', 'vendor-onboarding-flow');
    //         }
    //     }
    //     return $response;
    // }	


    /**
	 * @return bool
	 */
	public static function vof_is_gallery_image_required($post_id) { 
        if ( Rtcl\Helpers\Functions::is_gallery_image_required() && 
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

// new VOF_Helper_Functions();