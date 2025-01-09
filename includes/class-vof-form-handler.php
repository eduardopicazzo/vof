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
				'type'       => 'text',
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
		
		$result = array_merge($fields, $vof_fields);
		error_log('VOF Debug: Returning fields: ' . print_r($result, true));
		return $result;
	}

    public static function vof_is_vof_conditions() : bool { 
        return !is_user_logged_in() || !VOF_Subscription::has_active_subscription();
    }

	/**
	 * TODO: This is the original function, we need to modify it to use the new fields? 
	 * maybe not cause vof_fields are for new users only. and we know they will be synced with the rtcl fields. 
	 * eventually... on validation most likely.
	 */ 
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

	/**
	 * Core listing submission handler for the RTCL (Real-Time Classified Listing) plugin
	 * 
	 * This comprehensive function manages the entire lifecycle of a classified listing submission,
	 * from validation through creation/update to final response. It handles both new listings
	 * and updates to existing ones.
	 * 
	 * Major Processing Steps:
	 * 1. Form Validation & Security
	 *    - Nonce verification for security
	 *    - reCAPTCHA validation if enabled
	 *    - Terms & conditions acceptance verification
	 * 
	 * 2. Data Collection & Validation
	 *    - Category validation with hierarchy checks
	 *    - Required fields validation (images, pricing, etc)
	 *    - Location data processing (supports both geo and traditional)
	 *    - Contact information validation
	 * 
	 * 3. User Management
	 *    - Handles both logged-in and guest users
	 *    - Automatic account creation for guests if enabled
	 *    - Permission verification for post updates
	 * 
	 * 4. Content Processing
	 *    - Handles post content and meta data
	 *    - Processes custom fields
	 *    - Manages taxonomies (categories, locations, tags)
	 * 
	 * 5. Post-Processing
	 *    - Status management (pending/published)
	 *    - Expiration date setting
	 *    - User statistics updates
	 *    - Notification handling
	 * 
	 * @return void Sends JSON response with status and redirect information
	 */
	// COMMENT WHEN NOT COPYING THIS CODE HAHA
	public function vof_do_rtcl_black_box() {
		// Initialize state and clear any existing notices
		Functions::clear_notices();
		$success = false;
		$post_id = 0;
		$type    = 'new';

		// SECTION: Security Validation
		// Verify nonce unless explicitly disabled by filter
		if ( apply_filters( 'rtcl_listing_form_remove_nonce', false )
		     || wp_verify_nonce( isset( $_REQUEST[ rtcl()->nonceId ] ) ? $_REQUEST[ rtcl()->nonceId ] : null, rtcl()->nonceText )
		) {
			// SECTION: CAPTCHA Validation
			// Verify human submission if CAPTCHA is enabled
			if ( ! Functions::is_human( 'listing' ) ) {
				Functions::add_notice(
					apply_filters(
						'rtcl_listing_form_recaptcha_error_text',
						esc_html__( 'Invalid Captcha: Please try again.', 'classified-listing' ),
						$_REQUEST
					),
					'error'
				);
			}

			// SECTION: Terms & Conditions Validation
			// Check if terms acceptance is required and provided
			$agree = isset( $_POST['rtcl_agree'] ) ? 1 : null;
			if ( Functions::is_enable_terms_conditions() && ! $agree ) {
				Functions::add_notice(
					apply_filters(
						'rtcl_listing_form_terms_conditions_text_responses',
						esc_html__( 'Please agree with the terms and conditions.', 'classified-listing' ),
						$_REQUEST
					),
					'error'
				);
			} else { // Start validation logic and data collection
				// SECTION: Basic Data Collection
				// Extract and sanitize primary listing data
				$raw_cat_id   = isset( $_POST['_category_id'] ) ? absint( $_POST['_category_id'] ) : 0;
				$listing_type = isset( $_POST['_ad_type'] ) && in_array( $_POST['_ad_type'], array_keys( Functions::get_listing_types() ) )
					? esc_attr( $_POST['_ad_type'] ) : '';
				$post_id      = absint( Functions::request( '_post_id' ) );

				// SECTION: Category Validation
				// Ensure category is selected for new listings
				if ( ! $raw_cat_id && ! $post_id ) {
					Functions::add_notice(
						apply_filters(
							'rtcl_listing_form_category_not_select_responses',
							sprintf(
								esc_html__( 'Category not selected. <a href="%s">Click here to set category</a>', 'classified-listing' ),
								\Rtcl\Helpers\Link::get_listing_form_page_link()
							)
						),
						'error'
					);
				} else {
					// Allow pre-processing hooks before category validation
					do_action( 'rtcl_before_add_edit_listing_before_category_condition', $post_id );
					$category_id = 0;

					// SECTION: Required Images Check
					// Verify gallery images if required by settings
					if ( Functions::is_gallery_image_required() && ( ! $post_id || ! count( Functions::get_listing_images( $post_id ) ) ) ) {
						Functions::add_notice(
							esc_html__( 'Image is required. Please select an image.', 'classified-listing' ),
							'error',
							'rtcl_listing_gallery_image_required'
						);
					}

					// Continue only if no errors encountered
					if ( ! Functions::notice_count( 'error' ) ) {
						// SECTION: Advanced Category Validation
						// Complex validation for category hierarchy and listing type compatibility
						if ( ( ! $post_id || ( ( $post = get_post( $post_id ) ) && $post->post_type == rtcl()->post_type ) && $post->post_status = 'rtcl-temp' )
						     && $raw_cat_id
						) {
							$category = get_term_by( 'id', $raw_cat_id, rtcl()->category );
							if ( is_a( $category, \WP_Term::class ) ) {
								$category_id = $category->term_id;
								$parent_id   = Functions::get_term_top_most_parent_id( $category_id, rtcl()->category );
								
								// Validate category leaf node (no children allowed)
								if ( Functions::term_has_children( $category_id ) ) {
									Functions::add_notice( esc_html__( 'Please select ad type and category', 'classified-listing' ), 'error' );
								}
								
								// Ensure listing type is selected if required
								if ( ! Functions::is_ad_type_disabled() && ! $listing_type ) {
									Functions::add_notice( esc_html__( 'Please select an ad type', 'classified-listing' ), 'error' );
								}
								
								// Verify category compatibility with listing type
								$cats_on_type = wp_list_pluck( Functions::get_one_level_categories( 0, $listing_type ), 'term_id' );
								if ( ! in_array( $parent_id, $cats_on_type ) ) {
									Functions::add_notice( esc_html__( 'Please select correct type and category', 'classified-listing' ), 'error' );
								}
								
								do_action( 'rtcl_before_add_edit_listing_into_category_condition', $post_id, $category_id );
							} else {
								Functions::add_notice( esc_html__( 'Category is not valid', 'classified-listing' ), 'error' );
							}
						}

						// Final category existence check
						if ( ! $post_id && ! $category_id ) {
							Functions::add_notice( __( 'Category not selected', 'classified-listing' ), 'error' );
						}
					}

					// SECTION: Listing Data Processing
					// Process all listing data if validation passed
					if ( ! Functions::notice_count( 'error' ) ) {
						$cats = [ $category_id ];
						$meta = [];

						// SECTION: Meta Data Collection
						// Build comprehensive meta array for all listing details
						
						// Terms acceptance meta
						if ( Functions::is_enable_terms_conditions() && $agree ) {
							$meta['rtcl_agree'] = 1;
						}

						// Pricing information meta
						if ( isset( $_POST['_rtcl_listing_pricing'] ) && $listing_pricing_type = sanitize_text_field( $_POST['_rtcl_listing_pricing'] ) ) {
							$meta['_rtcl_listing_pricing'] = in_array( $listing_pricing_type, array_keys( \Rtcl\Resources\Options::get_listing_pricing_types() ) )
								? $listing_pricing_type : 'price';
							// Handle range pricing if applicable
							if ( isset( $_POST['_rtcl_max_price'] ) && 'range' === $listing_pricing_type ) {
								$meta['_rtcl_max_price'] = Functions::format_decimal( $_POST['_rtcl_max_price'] );
							}
						}

						// SECTION: Location Processing
						// Handle location data based on configured location type
						if ( 'geo' === Functions::location_type() ) {
							// Geo-location data
							if ( isset( $_POST['rtcl_geo_address'] ) ) {
								$meta['_rtcl_geo_address'] = Functions::sanitize( $_POST['rtcl_geo_address'] );
							}
						} else {
							// Traditional location data
							if ( isset( $_POST['zipcode'] ) ) {
								$meta['zipcode'] = Functions::sanitize( $_POST['zipcode'] );
							}
							if ( isset( $_POST['address'] ) ) {
								$meta['address'] = Functions::sanitize( $_POST['address'] );
							}
						}

						// SECTION: Contact Information
						// Process all contact-related fields
						if ( isset( $_POST['phone'] ) ) {
							$meta['phone'] = Functions::sanitize( $_POST['phone'] );
						}
						if ( isset( $_POST['_rtcl_whatsapp_number'] ) ) {
							$meta['_rtcl_whatsapp_number'] = Functions::sanitize( $_POST['_rtcl_whatsapp_number'] );
						}
						if ( isset( $_POST['_rtcl_telegram'] ) ) {
							$meta['_rtcl_telegram'] = Functions::sanitize( $_POST['_rtcl_telegram'] );
						}
						if ( isset( $_POST['email'] ) ) {
							$meta['email'] = Functions::sanitize( $_POST['email'], 'email' );
						}

						// SECTION: Post Content Preparation
						// Prepare main post content data
						$title               = isset( $_POST['title'] ) ? Functions::sanitize( $_POST['title'], 'title' ) : '';
						$post_arg            = [
							'post_title'   => $title,
							'post_content' => isset( $_POST['description'] ) ? Functions::sanitize( $_POST['description'], 'content' ) : '',
						];

						// SECTION: User Management
						// Handle user authentication and possible registration
						$post                = get_post( $post_id );
						$user_id             = get_current_user_id();
						$post_for_unregister = Functions::is_enable_post_for_unregister();
						
						// Create account for unregistered users if enabled
						if ( ! is_user_logged_in() && $post_for_unregister ) {
							$new_user_id = Functions::do_registration_from_listing_form( [ 'email' => $meta['email'] ] );
							if ( $new_user_id && is_numeric( $new_user_id ) ) {
								$user_id = $new_user_id;
								Functions::add_notice(
									apply_filters(
										'rtcl_listing_new_registration_success_message',
										sprintf(
											esc_html__( 'A new account is registered, password is sent to your email(%s).', 'classified-listing' ),
											$meta['email']
										),
										$meta['email']
									)
								);
							}
						}

						// SECTION: Listing Creation/Update
						// Process the actual listing creation or update
						if ( $user_id ) {
							$new_listing_status = Functions::get_option_item( 'rtcl_moderation_settings', 'new_listing_status', 'pending' );
							
							// Update existing listing
							if ( $post_id && is_object( $post ) && $post->post_type == rtcl()->post_type ) {
								// Verify user has permission to update
								if ( ( $post->post_author > 0 && in_array($post->post_author, [ apply_filters( 'rtcl_listing_post_user_id', get_current_user_id() ), get_current_user_id() ] ) )
								     || ( $post->post_author == 0 && $post_for_unregister )
								) {
									// Handle temporary vs published status
									if ( $post->post_status === 'rtcl-temp' ) {
										$post_arg['post_name']   = $title;
										$post_arg['post_status'] = $new_listing_status;
									} else {
										$type              = 'update';
										$status_after_edit = Functions::get_option_item( 'rtcl_moderation_settings', 'edited_listing_status' );
										if ( 'publish' === $post->post_status && $status_after_edit && $post->post_status !== $status_after_edit ) {
											$post_arg['post_status'] = $status_after_edit;
										}
									}

									// Update author for guest posts if needed
									if ( $post->post_author == 0 && $post_for_unregister ) {
										$post_arg['post_author'] = $user_id;
									}
									$post_arg['ID'] = $post_id;
									$success        = wp_update_post( apply_filters( 'rtcl_listing_save_update_args', $post_arg, $type ) );
								}
							} else {
								// Create new listing
								$post_arg['post_status'] = $new_listing_status;
								$post_arg['post_author'] = $user_id;
								$post_arg['post_type']   = rtcl()->post_type;
								$post_id                 = $success = wp_insert_post( apply_filters( 'rtcl_listing_save_update_args', $post_arg, $type ) );
							}

							// SECTION: Additional Data Processing
							// Process additional listing data if creation/update was successful
							if ( $post_id ) {
								// Process tags
								if ( isset( $_POST['rtcl_listing_tag'] ) ) {
									$tags          = Functions::sanitize( $_POST['rtcl_listing_tag'] );
									$tags_as_array = ! empty( $tags ) ? explode( ',', $tags ) : [];
									wp_set_object_terms( $post_id, $tags_as_array, rtcl()->tag );
								}

								// Set initial categories and type for new listings [ DISREGARD THIS SECTION FOR NOW - CHECK IF THIS IS NEEDED AFTER TESTING ]
								if ( $type == 'new' && $post_id ) {
									wp_set_object_terms( $post_id, $cats, rtcl()->category );
									$meta['ad_type'] = $listing_type;
								}

								// Process hierarchical location data
								if ( 'local' === Functions::location_type() ) {
									$locations = [];
									if ( $loc = Functions::request( 'location' ) ) {
										$locations[] = absint( $loc );
									}
									if ( $loc = Functions::request( 'sub_location' ) ) {
										$locations[] = absint( $loc );
									}
									if ( $loc = Functions::request( 'sub_sub_location' ) ) {
										$locations[] = absint( $loc );
									}
									wp_set_object_terms( $post_id, $locations, rtcl()->location );
								}

								/**
								 * 
								 * Process custom fields (most likely the changing fields given the category selected or ad type selected)
								 * 
								 * Yes, you're correct. This comment section precedes code that processes custom fields which are dynamically 
								 * loaded based on:
								 * 	- Selected category (category-specific fields)
								 * 	- Ad type (type-specific fields)
								 * 	- Other conditional form logic
								 * 
								 * Process custom fields that dynamically change based on:
								 * 	- The category selected (different categories can have different fields)
								 * 	- The ad type chosen (different ad types can have different fields)
								 * 	- Other conditional form logic (fields that depend on other form inputs)
								 * 
								 * This is evident from the code that follows it (rtcl_fields) which processes these dynamic custom 
								 * fields and saves their values to the database.
								 * 
								 */
								if ( isset( $_POST['rtcl_fields'] ) && $post_id ) {
									foreach ( $_POST['rtcl_fields'] as $key => $value ) {
										$field_id = (int) str_replace( '_field_', '', $key );
										if ( $field = rtcl()->factory->get_custom_field( $field_id ) ) {
											$field->saveSanitizedValue( $post_id, $value );
										}
									}
								}


								/**
								 * 
								 * This code block saves all the metadata that was collected earlier in the $meta array 
								 * to the database using WordPress's update_post_meta() function. 
								 * Yes, it's part of the larger form processing section above, specifically after processing custom fields. 
								 * It's the final step that persists all collected metadata (like prices, contact info, locations) 
								 * to the database for this listing.
								 * 
								 * Reason: The code's position and context show it's the culmination of all the metadata collection 
								 * that happened in previous sections.
								 * 
								 */

								// Save all collected meta data
								if ( ! empty( $meta ) && $post_id ) {
									foreach ( $meta as $key => $value ) {
										update_post_meta( $post_id, $key, $value );
									}
								}

								// SECTION: Post-Processing [ TODO: IMPLEMENT PARTS OF THIS SECTION ]
								// Handle successful listing creation/update
								if ( $success && $post_id && ( $listing = rtcl()->factory->get_listing( $post_id ) ) ) {
									if ( $type == 'new' ) {
										// Initialize new listing metadata
										update_post_meta( $post_id, 'featured', 0 ); 
										update_post_meta( $post_id, '_views', 0 );
										
										// Update user's ad count
										$current_user_id = get_current_user_id();
										$ads             = absint( get_user_meta( $current_user_id, '_rtcl_ads', true ) );
										update_user_meta( $current_user_id, '_rtcl_ads', $ads + 1 );
										
										// Set expiration for published listings [ DISREGARD THIS SECTION FOR NOW]
										if ( 'publish' === $new_listing_status ) {
											Functions::add_default_expiry_date( $post_id );
										}
										
										Functions::add_notice(
											apply_filters(
												'rtcl_listing_success_message',
												esc_html__( 'Thank you for submitting your ad!', 'classified-listing' ),
												$post_id,
												$type,
												$_REQUEST
											)
										);
									} elseif ( $type == 'update' ) {
										Functions::add_notice(
											apply_filters(
												'rtcl_listing_success_message',
												esc_html__( 'Successfully updated !!!', 'classified-listing' ),
												$post_id,
												$type,
												$_REQUEST
											)
										);
									}

									// Final processing hook
									do_action(
										'rtcl_listing_form_after_save_or_update',
										$listing,
										$type,
										$category_id,
										$new_listing_status,
										[
											'data'  => $_REQUEST,
											'files' => $_FILES,
										]
									);
								} else {
									Functions::add_notice(
										apply_filters( 'rtcl_listing_error_message', esc_html__( 'Error!!', 'classified-listing' ), $_REQUEST ),
										'error'
									);
								}
							}
						}
					}
				}
			}
		} else {
			Functions::add_notice(
				apply_filters( 'rtcl_listing_session_error_message', esc_html__( 'Session Error !!', 'classified-listing' ), $_REQUEST ),
				'error'
			);
		}

		// SECTION: Response Preparation
		// Prepare final response data
		$message = Functions::get_notices( 'error' );
		if ( $success ) {
			$message = Functions::get_notices( 'success' );
		}
		Functions::clear_notices(); // Clear all notices

		// Send JSON response with complete status information
		wp_send_json(
			apply_filters(
				'rtcl_listing_form_after_save_or_update_responses',
				[
					'message'      => $message,
					'success'      => $success,
					'post_id'      => $post_id,
					'type'         => $type,
					'redirect_url' => apply_filters(
						'rtcl_listing_form_after_save_or_update_responses_redirect_url',
						Functions::get_listing_redirect_url_after_edit_post( $type, $post_id, $success ),
						$type,
						$post_id,
						$success,
						$message
					),
				]
			)
		);
	}
	
}

// Initialize the form handler
new VOF_Form_Handler();