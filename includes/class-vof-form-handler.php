<?php
/**
 * Form Handler Class
 * 
 * Handles form modifications and validations for the vendor onboarding flow
 */

namespace VOF;

use Rtcl\Helpers\Functions;
use RtclStore\Models\Membership;

class VOF_Form_Handler {
    
    public function __construct() {
        // Hook into template loading with correct priority and argument count
        // add_filter('rtcl_locate_template', array($this, 'vof_maybe_override_template'), 10, 3);
        remove_action("rtcl_listing_form", [ \Rtcl\Controllers\Hooks\TemplateHooks::class ,'listing_contact' ], 30);
        add_action("rtcl_listing_form", [self::class ,'vof_listing_contact' ], 30);
        

        // if (!is_user_logged_in() || !VOF_Subscription::has_active_subscription()) {
        //     rtcl_listing_form
        // }
        
        // Add validation for confirm email
       // add_filter('rtcl_fb_extra_form_validation', array($this, 'vof_validate_confirm_email'), 10, 2);
    }

    public static function vof_is_vof_conditions() : bool { 
        return !is_user_logged_in() || !VOF_Subscription::has_active_subscription();
    }


	public static function vof_listing_contact( $post_id ) {
		$location_id        = $sub_location_id = $sub_sub_location_id = 0;
		$user_id            = get_current_user_id();
		$user               = get_userdata( $user_id );
		$email              = $user ? $user->user_email : '';
		$phone              = get_user_meta( $user_id, '_rtcl_phone', true );
		$whatsapp_number    = get_user_meta( $user_id, '_rtcl_whatsapp_number', true );
		$telegram           = get_user_meta( $user_id, '_rtcl_telegram', true );
		$website            = get_user_meta( $user_id, '_rtcl_website', true );
		$selected_locations = (array) get_user_meta( $user_id, '_rtcl_location', true );
		$zipcode            = get_user_meta( $user_id, '_rtcl_zipcode', true );
		$geo_address        = get_user_meta( $user_id, '_rtcl_geo_address', true );
		$address            = get_user_meta( $user_id, '_rtcl_address', true );
		$latitude           = get_user_meta( $user_id, '_rtcl_latitude', true );
		$longitude          = get_user_meta( $user_id, '_rtcl_longitude', true );

		if ( $post_id ) {
			$selected_locations = 'local' === Functions::location_type() ? wp_get_object_terms( $post_id, rtcl()->location, [ 'fields' => 'ids' ] ) : [];
			$latitude           = get_post_meta( $post_id, 'latitude', true );
			$longitude          = get_post_meta( $post_id, 'longitude', true );
			$zipcode            = get_post_meta( $post_id, 'zipcode', true );
			$address            = get_post_meta( $post_id, 'address', true );
			$geo_address        = get_post_meta( $post_id, '_rtcl_geo_address', true );
			$phone              = get_post_meta( $post_id, 'phone', true );
			$whatsapp_number    = get_post_meta( $post_id, '_rtcl_whatsapp_number', true );
			$telegram           = get_post_meta( $post_id, '_rtcl_telegram', true );
			$email              = get_post_meta( $post_id, 'email', true );
			$website            = get_post_meta( $post_id, 'website', true );
		}
		$moderation_settings = Functions::get_option( 'rtcl_moderation_settings' );
		$data                = [
			'post_id'                    => $post_id,
			'state_text'                 => \Rtcl\Helpers\Text::location_level_first(),
			'city_text'                  => \Rtcl\Helpers\Text::location_level_second(),
			'town_text'                  => \Rtcl\Helpers\Text::location_level_third(),
			'selected_locations'         => $selected_locations,
			'latitude'                   => $latitude,
			'longitude'                  => $longitude,
			'zipcode'                    => $zipcode,
			'address'                    => $address,
			'geo_address'                => $geo_address,
			'phone'                      => $phone,
			'whatsapp_number'            => $whatsapp_number,
			'telegram'                   => $telegram,
			'email'                      => $email,
			'website'                    => $website,
			'location_id'                => $location_id,
			'sub_location_id'            => $sub_location_id,
			'sub_sub_location_id'        => $sub_sub_location_id,
			'hidden_fields'              => ( ! empty( $moderation_settings['hide_form_fields'] ) ) ? $moderation_settings['hide_form_fields'] : [],
			'enable_post_for_unregister' => ! is_user_logged_in() && Functions::is_enable_post_for_unregister()
		];

        if (self::vof_is_vof_conditions()) { 
            // $vof_template_path = 'wp-content/plugins/vendor-onboarding-flow/templates/listing-form/vof-contact.php';
            // $vof_template_path = plugin_dir_path(dirname(__FILE__)) . 'templates/listing-form/vof-contact.php';
            $template_name = 'listing-form/vof-contact';
            $plugin_template_path = VOF_PLUGIN_DIR . 'templates/';                            // path to the plugin's template directory.
            Functions::get_template( 
                $template_name,                                                               // template name to be rendered
                apply_filters( 'rtcl_listing_form_contact_tpl_attributes', $data, $post_id ), // template args 
                '',                                                                           // default template path (empty to use plugin default) 
                $plugin_template_path                                                         // your plugin's template path 
            );
        } else {
            Functions::get_template( "listing-form/contact", apply_filters( 'rtcl_listing_form_contact_tpl_attributes', $data, $post_id ) );
        }
	}


    /**
     * Maybe override the template based on conditions
     * 
     * @param string $template      Template path
     * @param string $template_name Template name
     * @param string $template_path Template path
     * 
     * @return string Modified template path
     */
    public function vof_maybe_override_template($template, $template_name, $template_path = '') {
        // Only override the contact template
        if ($template_name !== 'listing-form/contact.php') {
            return $template;
        }

        // Check if we need to override (not logged in or no active subscription)
        if (!function_exists('is_user_logged_in') || !function_exists('wp_get_current_user')) {
            return $template;
        }

        // Check if we're on the listing submission page
        if (!class_exists('Rtcl\Helpers\Functions') || !method_exists('Rtcl\Helpers\Functions', 'is_listing_form_page')) {
            return $template;
        }

        // Only override on the listing form page
        if (!Functions::is_listing_form_page()) {
            return $template;
        }

        if (!is_user_logged_in() || !VOF_Subscription::has_active_subscription()) {
            // Get our custom template path
            $plugin_path = plugin_dir_path(dirname(__FILE__));
            $our_template = $plugin_path . 'templates/listing-form/vof-contact.php';

            // Only override if our template exists
            if (file_exists($our_template)) {
                return $our_template;
            }
        }

        return $template;
    }

    /**
     * Validate confirm email field
     */
    public function vof_validate_confirm_email($errors, $form) {
        if (!is_user_logged_in() || !VOF_Subscription::has_active_subscription()) {
            $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
            $confirm_email = isset($_POST['confirm_email']) ? sanitize_email($_POST['confirm_email']) : '';
            
            if (empty($confirm_email)) {
                $errors->add('confirm_email', esc_html__('Please confirm your email address', 'vendor-onboarding-flow'));
            } elseif ($email !== $confirm_email) {
                $errors->add('confirm_email_mismatch', esc_html__('Email addresses do not match', 'vendor-onboarding-flow'));
            }
        }
        
        return $errors;
    }
}

// Initialize the form handler
new VOF_Form_Handler();