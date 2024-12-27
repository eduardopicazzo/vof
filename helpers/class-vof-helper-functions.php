<?php
class VOF_Helper_Functions {
    public function __construct() {
    }

    /**
	 * @return bool
	 */
	public static function vof_is_gallery_image_required($post_id) { 
        if ( Rtcl\Helpers\Functions::is_gallery_image_required() && 
        ( ! $post_id || ! count( Rtcl\Helpers\Functions::get_listing_images( $post_id ) ) ) ) {
            Rtcl\Helpers\Functions::add_notice(
                esc_html__( 'Image is required. Please select an image.', 'classified-listing' ),
                'error',
                'rtcl_listing_gallery_image_required'
            );
        }
    }   

    /**
	 * @return bool
	 */
	// public static function vof_do_registration_from_lsting_form() {}

    // public static function vof_listingFormPhoneIsRequired() {
	// 	return apply_filters( 'rtcl_listing_form_phone_is_required', true );
	// }

    public static function vof_email_exists($email) {
        if ( email_exists( sanitize_email( $email ) ) ) {
			return new WP_Error( 'registration-error-email-exists', apply_filters( 'rtcl_registration_error_email_exists',
				esc_html__( 'An account is already registered with your email address. Please log in.', 'classified-listing' ), $email ) );
		}
	}

	public static function vof_phone_exists( $phone ) {
        return Rtcl\Helpers\Functions::phone_exists( $phone );
		// $users = get_users( [
		// 	'meta_key'    => '_rtcl_phone',
		// 	'meta_value'  => $phone,
		// 	'number'      => 1,
		// 	'count_total' => false
		// ] );
		// if ( ! empty( $users ) ) {
		// 	return $users[0]->ID;
		// }

		// return false;
	}

}

new VOF_Helper_Functions();