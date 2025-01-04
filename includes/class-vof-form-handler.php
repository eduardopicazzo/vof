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
		    // Move all hook registrations to init
			add_action('init', [$this, 'vof_register_hooks']);

    	// // Add this filter early to prevent default registration
    	// // Intercept the AJAX action that handles form submission
    	// add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'vof_intercept_listing_submission'], 1);
    	// add_action('wp_ajax_rtcl_post_new_listing', [$this, 'vof_intercept_listing_submission'], 1);
	

        // // Hook into template loading with correct priority and argument count
        // remove_action("rtcl_listing_form", [ \Rtcl\Controllers\Hooks\TemplateHooks::class ,'listing_contact' ], 30);
        // add_action("rtcl_listing_form", [self::class ,'vof_listing_contact' ], 30);
		// add_filter('rtcl_listing_contact_details_fields', [self::class, 'vof_listing_contact_details_fields']);
    }


	public function vof_register_hooks() {
	
		// Add this filter early to prevent default registration
    	// Intercept the AJAX action that handles form submission
    	add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'vof_intercept_listing_submission'], 1);
    	add_action('wp_ajax_rtcl_post_new_listing', [$this, 'vof_intercept_listing_submission'], 1);
	

        // Hook into template loading with correct priority and argument count
        remove_action("rtcl_listing_form", [ \Rtcl\Controllers\Hooks\TemplateHooks::class ,'listing_contact' ], 30);
        add_action("rtcl_listing_form", [self::class ,'vof_listing_contact' ], 30);
		
		// Use the correct filter name consistently??
		add_filter('rtcl_listing_contact_details_fields', [self::class, 'vof_listing_contact_details_fields']);
		// add_filter('rtcl_listing_form_contact_tpl_attributes', [self::class, 'vof_listing_contact_details_fields']);
		  // Add debug to verify filter registration
		error_log('VOF Debug: rtcl_listing_contact_details_fields hooked: ' . 
			(has_filter('rtcl_listing_contact_details_fields', [self::class, 'vof_listing_contact_details_fields']) ? 'Yes' : 'No'));
	  	// error_log('VOF Debug: rtcl_listing_form_contact_tpl_attributes hooked: ' . 
		// 	(has_filter('rtcl_listing_form_contact_tpl_attributes', [self::class, 'vof_listing_contact_details_fields']) ? 'Yes' : 'No'));

		// Add debug log to verify hooks are registered
		error_log('VOF Debug: Hooks registered in init');

		// Add debug log to check if the filter is actually being applied
		add_action('init', function() {
			error_log('VOF Debug: Filter registration status: ' . 
				(has_filter('rtcl_listing_form_contact_tpl_attributes', [self::class, 'vof_listing_contact_details_fields']) ? 'Yes' : 'No'));
		});
	}

	public function vof_intercept_listing_submission() {
		if (self::vof_is_vof_conditions()) {
			error_log('VOF Debug: Form submission data: ' . print_r($_POST, true));

			// Get field configurations
			$fields = apply_filters('rtcl_listing_contact_details_fields', []);

        	// Validate each field
        	foreach ($fields as $field_key => $field) {
        	    if (isset($field['validation']) && isset($_POST[$field_key])) {
        	        $validation_callback = $field['validation'];
        	        if (is_callable($validation_callback)) {
        	            call_user_func($validation_callback, $_POST[$field_key]);
        	        }
        	    }
        	}			


			error_log('VOF Debug: Intercepting listing submission');
			// Prevent the default registration process
			wp_send_json_error([
				'message' => __('Please complete the registration process first.', 'vendor-onboarding-flow')
			]);
			exit;
		}
	}


	public static function vof_listing_contact_details_fields( $fields ) {
		error_log('VOF Debug: vof_listing_contact_details_fields ENTRY POINT');
		error_log('VOF Debug: Received fields: ' . print_r($fields, true));
		
		$vof_fields = [
			'vof_email' => [
				'type' 	   	 => 'email',
				'label'    	 => '[VOF test] Email',
				'id' 	   	 => 'vof-email',
				'required' 	 => true,
				/** The validation attribute specifies a callback function that will be used to validate this field 
				 * However, 'custom_validation_callback' is not a real function name and won't trigger any validation
				 * To properly validate this field, we should specify an actual validation callback function, 
				 * for example: [self::class, 'validate_vof_email']
				 */
				'validation' => [ '\VOF\Helpers\VOF_Helper_Functions', 'vof_validate_email' ], 
				'class' 	 => ''
			],
			'vof_email_confirm' => [
				'type'       => 'email',
				'label'      => '[VOF test] Email Confirm',
				'id'         => 'vof-email-confirm',
				'required'   => true,
				'validation' => [ '\VOF\Helpers\VOF_Helper_Functions', 'vof_validate_email_confirm' ],
				'class' 	 => ''
			],
			'vof_phone' => [
				'type'  	 => 'text',
				'label' 	 => '[VOF test] Phone',
				'id' 		 => 'vof-phone',
				'required'   => true,
				'validation' => [ '\VOF\Helpers\VOF_Helper_Functions', 'vof_validate_phone' ],
				'class' 	 => ''
			],
			'vof_whatsapp_number' => [
				'type'       => 'text',
				'label'      => '[VOF test] Whatsapp Number',
				'id'         => 'vof-whatsapp-number',
				'required'   => true,
				'validation' => [ '\VOF\Helpers\VOF_Helper_Functions', 'vof_validate_whatsapp_number' ],
				'class' 	 => ''
				]
		];

		// Remove any existing email fields that trigger validation
		// if (isset($fields['email'])) {
		// 	unset($fields['email']);
		// }
		
		$result = array_merge($fields, $vof_fields);
		error_log('VOF Debug: Returning fields: ' . print_r($result, true));
		return $result;
	}

    public static function vof_is_vof_conditions() : bool { 
        return !is_user_logged_in() || !VOF_Subscription::has_active_subscription();
    }

	// TODO: This is the original function, we need to modify it to use the new fields
	public static function vof_listing_contact( $post_id ) {
		// Initialize default values for a new listing
		$location_id = $sub_location_id = $sub_sub_location_id = 0;
		
		// Get current user info if logged in
		$user_id = get_current_user_id();
		$user = get_userdata($user_id);
		
		// Get user's saved contact/location details from user meta
		// These act as default values that can be overridden if post_id exists (see code below)
		$email 				= $user ? $user->user_email : '';
		$phone 				= get_user_meta($user_id, '_rtcl_phone', true); 		   // notice the _rtcl_ prefix convention on the meta key... defined somewhere within their whole fucking code mess
		$whatsapp_number 	= get_user_meta($user_id, '_rtcl_whatsapp_number', true);  // notice the _rtcl_ prefix convention on the meta key... defined somewhere within their whole fucking code mess
		$telegram 			= get_user_meta($user_id, '_rtcl_telegram', true); 		   // notice the _rtcl_ prefix convention on the meta key... defined somewhere within their whole fucking code mess
		$website 			= get_user_meta($user_id, '_rtcl_website', true); 		   // notice the _rtcl_ prefix convention on the meta key... defined somewhere within their whole fucking code mess
		$selected_locations = (array) get_user_meta($user_id, '_rtcl_location', true); // notice the _rtcl_ prefix convention on the meta key... defined somewhere within their whole fucking code mess
		$zipcode 			= get_user_meta($user_id, '_rtcl_zipcode', true); 		   // notice the _rtcl_ prefix convention on the meta key... defined somewhere within their whole fucking code mess
		$geo_address 		= get_user_meta($user_id, '_rtcl_geo_address', true); 	   // notice the _rtcl_ prefix convention on the meta key... defined somewhere within their whole fucking code mess
		$address 			= get_user_meta($user_id, '_rtcl_address', true);     	   // notice the _rtcl_ prefix convention on the meta key... defined somewhere within their whole fucking code mess
		$latitude 			= get_user_meta($user_id, '_rtcl_latitude', true);    	   // notice the _rtcl_ prefix convention on the meta key... defined somewhere within their whole fucking code mess
		$longitude 			= get_user_meta($user_id, '_rtcl_longitude', true);   	   // notice the _rtcl_ prefix convention on the meta key... defined somewhere within their whole fucking code mess	
		
		/** 
		 * should not look for vof custom fields (above) since they will be synced with the rtcl fields. eventually...
		 * and we know vof fields are for new users only.
		 */
		
		// If editing an existing post, override the default user meta values with the post's saved values
		if ( $post_id ) {
			// Get location taxonomy terms for this post
			$selected_locations = 'local' === Functions::location_type() ? 
				wp_get_object_terms( $post_id, rtcl()->location, [ 'fields' => 'ids' ] ) : [];
			
			// Get all the post meta values
			$latitude           	= get_post_meta( $post_id, 'latitude', true );
			$longitude          	= get_post_meta( $post_id, 'longitude', true );
			$zipcode            	= get_post_meta( $post_id, 'zipcode', true );
			$address            	= get_post_meta( $post_id, 'address', true );
			$geo_address        	= get_post_meta( $post_id, '_rtcl_geo_address', true );
			$phone              	= get_post_meta( $post_id, 'phone', true ); //
			$vof_phone              = get_post_meta( $post_id, 'vof_phone', true ); // fix keys
			$whatsapp_number    	= get_post_meta( $post_id, '_rtcl_whatsapp_number', true ); //
			$vof_whatsapp_number    = get_post_meta( $post_id, 'vof_whatsapp_number', true ); // fix keys
			$telegram           	= get_post_meta( $post_id, '_rtcl_telegram', true );
			$email              	= get_post_meta( $post_id, 'email', true ); //
			$vof_email              = get_post_meta( $post_id, 'vof_email', true ); // fix keys
			$vof_email_confirm      = get_post_meta( $post_id, 'vof_email_confirm', true ); // fix keys
			$website            	= get_post_meta( $post_id, 'website', true );
		}
		// Get moderation settings to determine which fields should be hidden
		$moderation_settings = Functions::get_option( 'rtcl_moderation_settings' );
		
		// Get the field configurations
		$fields = apply_filters('rtcl_listing_contact_details_fields', []);

		// Prepare data array for template rendering
		// This combines all the previously gathered user/post data with additional display settings
		$data = [
			// Post ID for form handling
			'post_id'              => $post_id,
			
			// Location level text labels
			'state_text' 		  => \Rtcl\Helpers\Text::location_level_first(),
			'city_text'  		  => \Rtcl\Helpers\Text::location_level_second(), 
			'town_text'  		  => \Rtcl\Helpers\Text::location_level_third(),
			
			// Location data gathered above
			'selected_locations'  => $selected_locations,
			'location_id'         => $location_id,
			'sub_location_id'     => $sub_location_id,
			'sub_sub_location_id' => $sub_sub_location_id,
			
			// Address/location details gathered above
			'latitude'     		  => $latitude,
			'longitude'    		  => $longitude, 
			'zipcode'      		  => $zipcode,
			'address'      		  => $address,
			'geo_address'  		  => $geo_address,
			
			// Contact details gathered above
			'phone'               => $phone,
			'vof_phone'           => $vof_phone,
			'whatsapp_number'     => $whatsapp_number,
			'vof_whatsapp_number' => $vof_whatsapp_number,
			'telegram'            => $telegram,
			'email'               => $email,
			'vof_email'           => $vof_email,
			'vof_email_confirm'   => $vof_email_confirm,
			'website'             => $website,
			
			// Form display settings
			'hidden_fields'       => (!empty($moderation_settings['hide_form_fields'])) ? 
				$moderation_settings['hide_form_fields'] : [],
			'enable_post_for_unregister' => !is_user_logged_in() && Functions::is_enable_post_for_unregister(),
			'fields' => $fields // Add the field vondfigurations to the data array.
		];

        if (self::vof_is_vof_conditions()) { 
            
            $template_name = 'listing-form/vof-contact';
            $plugin_template_path = VOF_PLUGIN_DIR . 'templates/';                            
            
            Functions::get_template( 
                $template_name,                                                               
                $data, // don't filter this again 
                '',                                                                           
                $plugin_template_path                                                         
            );
        } else {
            Functions::get_template("listing-form/contact", $data);
        }


        // if (self::vof_is_vof_conditions()) { 
            
        //     $template_name = 'listing-form/vof-contact';
        //     $plugin_template_path = VOF_PLUGIN_DIR . 'templates/';                            // path to the plugin's template directory.
            
        //     Functions::get_template( 
        //         $template_name,                                                               // template name to be rendered
        //         apply_filters( 'rtcl_listing_form_contact_tpl_attributes', $data, $post_id ), // template args 
        //         '',                                                                           // default template path (empty to use plugin default) 
        //         $plugin_template_path                                                         // your plugin's template path 
        //     );
        // } else {
        //     Functions::get_template( "listing-form/contact", apply_filters( 'rtcl_listing_form_contact_tpl_attributes', $data, $post_id ) );
        // }
	}
}

// Initialize the form handler
new VOF_Form_Handler();