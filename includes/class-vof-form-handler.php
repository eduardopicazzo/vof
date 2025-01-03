<?php
/**
 * Form Handler Class
 * 
 * Handles form modifications and validations for the vendor onboarding flow
 */

namespace VOF;

use Rtcl\Helpers\Functions;
use RtclStore\Models\Membership;
use VOF\Helpers\VOF_Helper_Functions;

class VOF_Form_Handler {
    
    public function __construct() {
        // Hook into template loading with correct priority and argument count
        remove_action("rtcl_listing_form", [ \Rtcl\Controllers\Hooks\TemplateHooks::class ,'listing_contact' ], 30);
        add_action("rtcl_listing_form", [self::class ,'vof_listing_contact' ], 30);
		add_filter('rtcl_listing_contact_details_fields', [self::class, 'vof_listing_contact_details_fields']);
    }


	public static function vof_listing_contact_details_fields( $fields ) {
		$vof_fields = [
			'vof_email' => [
				'type' => 'email',
				'label' => '[VOF test] Email',
				'id' => 'vof-email',
				'required' => true,
				/** The validation attribute specifies a callback function that will be used to validate this field 
				 * However, 'custom_validation_callback' is not a real function name and won't trigger any validation
				 * To properly validate this field, we should specify an actual validation callback function, 
				 * for example: [self::class, 'validate_vof_email']
				 */
				'validation' => [ VOF_Helper_Functions::class, 'vof_validate_email' ], 
				'class' => ''
			],
			'vof_email_confirm' => [
				'type' => 'email',
				'label' => '[VOF test] Email Confirm',
				'id' => 'vof-email-confirm',
				'required' => true,
				'validation' => [ VOF_Helper_Functions::class, 'vof_validate_email_confirm' ],
				'class' => ''
			],
			'vof_phone' => [
				'type' => 'text',
				'label' => '[VOF test] Phone',
				'id' => 'vof-phone',
				'required' => true,
				'validation' => [ VOF_Helper_Functions::class, 'vof_validate_phone' ],
				'class' => ''
			],
			'vof_whatsapp_number' => [
				'type' => 'text',
				'label' => '[VOF test] Whatsapp Number',
				'id' => 'vof-whatsapp-number',
				'required' => true,
				'validation' => [ VOF_Helper_Functions::class, 'vof_validate_whatsapp_number' ],
				'class' => ''
			]
		];
		return array_merge($fields, $vof_fields);
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



}

// Initialize the form handler
new VOF_Form_Handler();