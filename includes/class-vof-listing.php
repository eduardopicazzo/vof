<?php

namespace VOF;

use Depicter\GuzzleHttp\Promise\Is; // maybe remove
use Rtcl\Controllers\FormHandler;   // maybe remove
use Rtcl\Helpers\Functions;         // maybe remove
use VOF_Helper_Functions;           // maybe remove

class VOF_Listing {
    public function __construct() {
		error_log('VOF Debug: VOF_Listing constructor called');

        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // Intercept the AJAX submission
        add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'cursor_handle_listing_submission'], 1);
		
        // Custom submit button 
        remove_action('rtcl_listing_form_end', ['Rtcl\Controllers\Hooks\TemplateHooks', 'listing_form_submit_button'], 50);
        add_action('rtcl_listing_form_end', [$this, 'custom_submit_button']);

		error_log('VOF Debug: VOF handler hooked with priority 1');
		
    }

    public function enqueue_scripts() { // KEEP
        if ($this->is_post_ad_page()) {
            wp_enqueue_script(
                'vof-listing-submit',
                plugin_dir_url(VOF_PLUGIN_FILE) . 'assets/js/vof-listing-submit.js',
                ['jquery', 'rtcl-public'], // Make sure we depend on jQuery and RTCL scripts
                VOF_VERSION,
                true
            );
        }
    }

    public function custom_submit_button($post_id) { // KEEP
        if (!is_user_logged_in() && $this->is_post_ad_page()) {
            $this->render_guest_submit_button();
            return;
        }

        if (is_user_logged_in() && !VOF_Subscription::has_active_subscription() && $this->is_post_ad_page()) {           
            $this->render_subscription_required_button(); 
            return;
        }

        if (is_user_logged_in() && VOF_Subscription::has_active_subscription() && $this->is_post_ad_page()) {
            ?>
            <button type="submit" class="btn btn-primary rtcl-submit-btn">
                <?php echo esc_html($post_id > 0 ? __('Update', 'classified-listing') : __('Submit', 'classified-listing')); ?>
            </button>
            <?php
            return;
        }
    }

    private function render_guest_submit_button() { // KEEP
        wp_enqueue_script('vof-listing-submit'); // Ensure script is loaded
		wp_enqueue_script('rtcl-gallery');
        ?>
        <div class="form-group">
            <button type="button" 
                    class="vof-guest-submit-btn btn btn-primary" 
                    onclick="handleTempListing()">
                <?php esc_html_e('Continue to Create Account', 'vendor-onboarding-flow'); ?>
            </button>
        </div>
        <?php
    }

    private function render_subscription_required_button() { // KEEP
        wp_enqueue_script('vof-listing-submit'); // Ensure script is loaded
		wp_enqueue_script('rtcl-gallery');

        ?>
        <div class="form-group">
            <button type="button" 
                    class="vof-subscription-submit-btn btn btn-primary" 
                    onclick="handleTempListing()">
                <?php esc_html_e('Continue to Select Plan', 'vendor-onboarding-flow'); ?>
            </button>
        </div>
        <?php
    }

    private function is_post_ad_page() { // KEEP
        return strpos($_SERVER['REQUEST_URI'], '/post-an-ad/') !== false;
    }
   
    public function cursor_handle_listing_submission() { // KEEP
        Functions::clear_notices(); // Clear previous notice
        $success = false;
        $post_id = 0;
        $type = 'new'; 

        // SECTION: Security Validation
        if (!apply_filters('rtcl_listing_form_remove_nonce', false) 
            && !wp_verify_nonce(isset($_REQUEST[rtcl()->nonceId]) ? $_REQUEST[rtcl()->nonceId] : null, rtcl()->nonceText)) {
            wp_send_json([
                'success' => false,
                'message' => [__('Session Expired!', 'classified-listing')]
            ]);
            return;
        }

        // SECTION: START VALIDATION LOGIC AND DATA COLLECTION

        // SECTION: Basic Data Collection
        // Extract and sanitize primary listing data
        $raw_cat_id = isset($_POST['_category_id']) ? absint($_POST['_category_id']) : 0;
        $listing_type = isset( $_POST['_ad_type'] ) && in_array( $_POST['_ad_type'], array_keys( Functions::get_listing_types() ) )
        ? esc_attr( $_POST['_ad_type'] ) : '';
        $post_id = isset($_POST['_post_id']) ? absint($_POST['_post_id']) : 0; // claude's form

        // Maybe add Ad Type validation SECTION?


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
			// Allow pre-processing hooks before category validation (maybe comment if not needed needs testing)
			do_action( 'rtcl_before_add_edit_listing_before_category_condition', $post_id );
			$category_id = 0;      

            // SECTION: Required Images Check
            // verify gallery images if required by settings
            if ( Functions::is_gallery_image_required() && ( !$post_id || !count(Functions::get_listing_images($post_id)))) {
                Functions::add_notice(
                    esc_html__('Image is required. Please select an image.', 'classified-listing'),
                    'error',
                    'rtcl_listing_gallery_image_required'
                );
            }

			// Continue only if no errors encountered (maybe comment if not needed needs testing)
			if ( ! Functions::notice_count( 'error' ) ) { // SECTION: Advanced Category Validation
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

            if ( ! Functions::notice_count('error') ) { // SECTION: Listing Data Processing
                $cats = [$category_id]; // just in case we need to use it
                $post_arg = [];
                $meta = [];

                // SECTION: Meta Data Collection
                // Build comprehensive meta array for all listing details
            
                // Terms acceptance meta (space for future use) (maybe not needed)
                // if ( Functions::is_enable_terms_conditions() && $agree ) {
                //     $meta['rtcl_agree'] = 1;
                // }                
            
                // Pricing information meta
                if ( isset( $_POST['_rtcl_listing_pricing'] ) && $listing_pricing_type = sanitize_text_field( $_POST['_rtcl_listing_pricing'] ) ) {
                    $meta['_rtcl_listing_pricing'] = in_array( $listing_pricing_type, array_keys( \Rtcl\Resources\Options::get_listing_pricing_types() ) )
                        ? $listing_pricing_type : 'price';
                
                    // Handle range pricing if applicable
                    if ( isset( $_POST['_rtcl_max_price'] ) && 'range' === $listing_pricing_type ) {
                        $meta['_rtcl_max_price'] = Functions::format_decimal( $_POST['_rtcl_max_price'] );
                    }
                }
                if ( isset( $_POST['price_type'] ) ) {
                    $meta['price_type'] = Functions::sanitize( $_POST['price_type'] );
                }
                if ( isset( $_POST['price'] ) ) {
                    $meta['price'] = Functions::format_decimal( $_POST['price'] );
                }
                if ( isset( $_POST['_rtcl_price_unit'] ) ) {
                    $meta['_rtcl_price_unit'] = Functions::sanitize( $_POST['_rtcl_price_unit'] );
                }
                if ( ! Functions::is_video_urls_disabled() && isset( $_POST['_rtcl_video_urls'] ) ) {
                    $meta['_rtcl_video_urls'] = Functions::sanitize( $_POST['_rtcl_video_urls'], 'video_urls' );
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
		        // if ( isset( $_POST['phone'] ) ) {
		        // 	$meta['phone'] = Functions::sanitize( $_POST['phone'] );
		        // }
                // if ( isset( $_POST['_rtcl_whatsapp_number'] ) ) {
		        // 	$meta['_rtcl_whatsapp_number'] = Functions::sanitize( $_POST['_rtcl_whatsapp_number'] );
		        // }
                // if ( isset( $_POST['_rtcl_telegram'] ) ) {
		        // 	$meta['_rtcl_telegram'] = Functions::sanitize( $_POST['_rtcl_telegram'] );
		        // }
		        // if ( isset( $_POST['email'] ) ) {
		        // 	$meta['email'] = Functions::sanitize( $_POST['email'], 'email' );
		        // }
                if ( isset( $_POST['website'] ) ) {
                    $meta['website'] = Functions::sanitize( $_POST['website'], 'url' );
                }
                if ( isset( $_POST['latitude'] ) ) {
                    $meta['latitude'] = Functions::sanitize( $_POST['latitude'] );
                }
                if ( isset( $_POST['longitude'] ) ) {
                    $meta['longitude'] = Functions::sanitize( $_POST['longitude'] );
                }
                $meta['hide_map']    = isset( $_POST['hide_map'] ) ? 1 : null;
                

                // SECTION Start: ACTUAL LISTING CREATION/UPDATE (maybe an if statement)

                    // ////// SECTION BLOCK CUSTOM CODE START //////

                   /**
                    * Builds BASE POST. 
                    * - collects standard base post arguments 
                    * - syncs with gallery images (if any)
                    * - updates existing temp post if it exists
                    * - sets to 'vof_temp' post status 
                    */
                    if ($post_id) {
                        // Update existing temp post instead of creating new one
                        $post                     = get_post($post_id);
                        $title                    = isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : '';
                        
                        $post_arg['ID']           = $post_id;
                        $post_arg['post_title']   = $title;
                        $post_arg['post_name']    = sanitize_title($title);
                        $post_arg['post_content'] = isset($_POST['description']) ? Functions::sanitize($_POST['description'], 'content') : '';
                        $post_arg['post_status']  = 'vof_temp'; // Keep as temp until subscription
                        $post_arg['post_excerpt'] = isset($_POST['excerpt']) ? Functions::sanitize($_POST['excerpt'], 'excerpt') : '';
                        // $post_arg['post_type']    = rtcl()->post_type; // uncomment if needed
                                  
                        // Update the post because we're reusing the image gallery created post id.
                        $post_id = wp_update_post(apply_filters('rtcl_listing_save_update_args', $post_arg, 'update'));
                        
                    } else {
                        // Create new temp post if none exists
                        $post_arg = [
                            'post_title'   => isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : '',
                            'post_content' => isset($_POST['description']) ? Functions::sanitize($_POST['description'], 'content') : '',
                            'post_excerpt' => isset($_POST['excerpt']) ? Functions::sanitize($_POST['excerpt'], 'excerpt') : '',
                            'post_status'  => 'vof_temp', // Keep as temp until subscription
                            'post_author'  => 0,
                            'post_type'    => rtcl()->post_type
                        ];
                        
                        $post_id = wp_insert_post(apply_filters('rtcl_listing_save_update_args', $post_arg, 'new'));
                    }
                
                    if (is_wp_error($post_id)) {
                        wp_send_json_error(['message' => $post_id->get_error_message()]);
                        return;
                    }


                    // ////// SECTION BLOCK CUSTOM CODE END //////

                    // SECTION: Additional Data Processing Process additional listing data if creation/update was successful
                  //  if ( $post_id ) { // SECTION START: Additional Data Processing (maybe an if statement)
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

                    // SECTION: Business Hours Processing
                    if ( isset( $_POST['_rtcl_active_bhs'] ) || isset( $_POST['_rtcl_active_special_bhs'] ) ) {
                        delete_post_meta( $post_id, '_rtcl_bhs' );
                        delete_post_meta( $post_id, '_rtcl_special_bhs' );
                        if ( !empty( $_POST['_rtcl_active_bhs'] ) && !empty( $_POST['_rtcl_bhs'] ) && is_array( $_POST['_rtcl_bhs'] ) ) {
                            $new_bhs = Functions::sanitize( $_POST['_rtcl_bhs'], 'business_hours' );
                            if ( !empty( $new_bhs ) ) {
                                update_post_meta( $post_id, '_rtcl_bhs', $new_bhs );
                            }
            
                            if ( !empty( $_POST['_rtcl_active_special_bhs'] ) && !empty( $_POST['_rtcl_special_bhs'] ) && is_array( $_POST['_rtcl_special_bhs'] ) ) {
                                $new_shs = Functions::sanitize( $_POST['_rtcl_special_bhs'], 'special_business_hours' );
                                if ( !empty( $new_shs ) ) {
                                    update_post_meta( $post_id, '_rtcl_special_bhs', $new_shs );
                                }
                            }
                        }
                    }

                    // SECTION: Social Profiles Processing
                    if ( isset( $_POST['rtcl_social_profiles'] ) && is_array( $_POST['rtcl_social_profiles'] ) ) {
                        $raw_profiles = $_POST['rtcl_social_profiles'];
                        $social_list  = \Rtcl\Resources\Options::get_social_profiles_list();
                        $profiles     = [];
                        foreach ( $social_list as $item => $value ) {
                            if ( ! empty( $raw_profiles[ $item ] ) ) {
                                $profiles[ $item ] = esc_url_raw( $raw_profiles[ $item ] );
                            }
                        }
                        if ( ! empty( $profiles ) ) {
                            update_post_meta( $post_id, '_rtcl_social_profiles', $profiles );
                        } else {
                            delete_post_meta( $post_id, '_rtcl_social_profiles' );
                        }
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
                     * This code block saves all the metadata that was collected earlier in the $meta array 
                     * to the database using WordPress's update_post_meta() function. 
                     * Yes, it's part of the larger form processing section above, specifically after processing custom fields. 
                     * It's the final step that persists all collected metadata (like prices, contact info, locations) 
                     * to the database for this listing.
                     * 
                     * Reason: The code's position and context show it's the culmination of all the metadata collection 
                     * that happened in previous sections. 
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
                        }
                    } else {
                        Functions::add_notice(
                            apply_filters( 'rtcl_listing_error_message', esc_html__( 'Error!!', 'classified-listing' ), $_REQUEST ),
                            'error'
                        );
                    }		
                   // } // END OF ADDITIONAL DATA PROCESSING (maybe an if statement)

                // SECTION End: ACTUAL LISTING CREATION/UPDATE (maybe an if statement)

            } // END Listing Data Processing

        } // END OF BUILDING THE POST


        // Handle gallery attachments - update their post parent
        $gallery_ids = isset($_POST['rtcl_gallery_ids']) ? array_map('absint', explode(',', $_POST['rtcl_gallery_ids'])) : [];
                      
        if (!empty($gallery_ids)) {
            foreach ($gallery_ids as $attachment_id) {
                wp_update_post([
                    'ID' => $attachment_id,
                    'post_parent' => $post_id
                ]);
            }
            
            // Set featured image if specified
            if (isset($_POST['featured_image_id'])) {
                set_post_thumbnail($post_id, absint($_POST['featured_image_id']));
            } else if (!empty($gallery_ids[0])) {
                // Use first image as featured if none set
                set_post_thumbnail($post_id, $gallery_ids[0]); 
            }
        }
    
        // Set category (move up?)
        wp_set_object_terms($post_id, [$category->term_id], rtcl()->category);

        // SECTION: Store complete form data in transient 
        set_transient('vof_temp_listing_' . $post_id, $_POST, DAY_IN_SECONDS * 3); // 3 days
    
        /**
         * SECTION: CREATE TEMP VOF USER METADATA
         * 
         * TODO: (maybe not here but in the user creation section) CREATE A NEW DB TABLE 
         * AND UPDATE VOF-TEMP USER'S METADATA WITH UUIDS AND OTHER USER METADATA 
         * (vof db table for user metadata linked to post_id)
         * 
         * $current_user_id = get_current_user_id(); 
         * $ads = absint( get_user_meta( $current_user_id, '_rtcl_ads', true ) ); 
         * update_user_meta( $current_user_id, '_rtcl_ads', $ads + 1 );
         */

         $vof_meta = array(
            'vof_email'           => isset($_POST['vof_email']) ? sanitize_email($_POST['vof_email']) : '',
            'vof_phone'           => isset($_POST['vof_phone']) ? sanitize_text_field($_POST['vof_phone']) : '',
            'vof_whatsapp_number' => isset($_POST['vof_whatsapp_number']) ? sanitize_text_field($_POST['vof_whatsapp_number']) : ''
        );

        // Create temp user entry in vof_temp_user_meta table
        $vof_temp_user_meta = \VOF\Helpers\VOF_Temp_User_Meta::vof_get_temp_user_meta_instance();
        $uuid = $vof_temp_user_meta->vof_create_temp_user($post_id, $vof_meta);

        // Store UUID in post meta for reference
        update_post_meta($post_id, '_vof_temp_user_uuid', $uuid);

        // SECTION: Send success response. Works as expected, no changes needed
        wp_send_json_success([
            'success'        => true,
            'listing_id'     => $post_id,
            'temp_user_uuid' => $uuid,
            'redirect_url'   => VOF_Constants::REDIRECT_URL,
            'message'        => [__('Listing saved successfully', 'classified-listing')]
        ]);
    }    

	public function vof_handle_onboarding_flow() { // KEEP NAME, REUSE SOME OF THIS CODE
		    // Add these debug statements at the start of the method
			error_log('VOF Debug: Full POST data: ' . print_r($_POST, true));
			error_log('VOF Debug: vof_email value: ' . (isset($_POST['vof_email']) ? $_POST['vof_email'] : 'not set'));
			error_log('VOF Debug: email value: ' . (isset($_POST['email']) ? $_POST['email'] : 'not set'));
			    // Add more detailed debugging
				error_log('VOF Debug: Starting onboarding flow handler');
				error_log('VOF Debug: POST data: ' . print_r($_POST, true));
				
				// Ensure email values are set correctly before core processing
				if (isset($_POST['vof_email'])) {
					$_REQUEST['email'] = $_POST['vof_email'];  // Also set in REQUEST
					$_POST['email'] = $_POST['vof_email'];     // Ensure POST is set
					
					error_log('VOF Debug: Email values after setting:');
					error_log('POST[email]: ' . $_POST['email']);
					error_log('REQUEST[email]: ' . $_REQUEST['email']);
				}
		//////############## DEBUGGING ENDS HERE ##############//////

		// The Housekeeping
		

		// validate email
		// Check the email address. wp function is_email() etc, can parse 'text/html' types.
		$is_valid_email    = \VOF\Helpers\VOF_Helper_Functions::vof_validate_email($_POST['vof_email']);
		$is_valid_phone    = \VOF\Helpers\VOF_Helper_Functions::vof_validate_phone($_POST['vof_phone']); 
		$is_whatsapp_phone = \VOF\Helpers\VOF_Helper_Functions::vof_validate_whatsapp_number($_POST['vof_whatsapp_number']); 

		// if validated stuff -> create temporary listing then maybe create temporary user 
		// if not validated stuff -> add error message to the form
		
		error_log('VOF Debug: VOF email is: '          . print_r($is_valid_email, true).' and POST values: '   . print_r($_POST['vof_email'], true));
		error_log('VOF Debug: VOF phone is: '          . print_r($is_valid_phone, true).' and POST values: '   . print_r($_POST['vof_phone'], true));
		error_log('VOF Debug: VOF whatsapp phone is: ' . print_r($is_whatsapp_phone, true).' and POST values: '. print_r($_POST['vof_whatsapp_number'], true));
	}
}