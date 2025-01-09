<?php

namespace VOF;

use Depicter\GuzzleHttp\Promise\Is;
use Rtcl\Controllers\FormHandler;
use Rtcl\Helpers\Functions;
use VOF_Helper_Functions;

class VOF_Listing {
    public function __construct() {
		error_log('VOF Debug: VOF_Listing constructor called');


        
		    // Add registration validation filter
			add_filter('rtcl_process_registration_errors', function($validation_error, $email) {
				error_log('VOF Debug: Registration validation filter running');
				
				if (isset($_POST['vof_email'])) {
					$email = $_POST['vof_email'];
					
					// Basic email format validation
					if (!is_email($email)) {
						error_log('VOF Debug: Email validation result: Invalid format');
						return $validation_error;
					}
					
					// If email exists, let the core handle it
					if (email_exists($email)) {
						error_log('VOF Debug: Email validation result: Email exists');
						return $validation_error;
					}
					
					// For new valid emails, explicitly clear any validation errors
					if ($validation_error->get_error_code()) {
						error_log('VOF Debug: Clearing validation errors for new valid email');
						$validation_error = new \WP_Error();
					}
					
					error_log('VOF Debug: Email validation result: Valid new email');
				}
				
				return $validation_error;
			}, 1, 2);
		
			// Add response modification filter
			add_filter('rtcl_listing_form_after_save_or_update_responses', function($response) {
				error_log('VOF Debug: Response before modification: ' . print_r($response, true));
				
				if (isset($_POST['vof_email']) && is_email($_POST['vof_email'])) {
					// Remove any email-related error messages
					if (isset($response['message']) && is_array($response['message'])) {
						$response['message'] = array_filter($response['message'], function($msg) {
							return strpos($msg, 'email address') === false;
						});
					}
				}
				
				return $response;
			}, 1, 1);

        // Add registration validation filter
        add_filter('rtcl_process_registration_errors', function($validation_error, $email, $username=null, $password=null, $userData=null) {
            error_log('VOF Debug: Registration validation filter running');
            
            if (isset($_POST['vof_email'])) {
                $email = $_POST['vof_email'];
                
                // Validate email format
                if (!is_email($email)) {
                    $validation_error->add('registration-error-invalid-email', 
                        __('Please provide a valid email address.', 'vendor-onboarding-flow')
                    );
                }
                
                // Check if email exists
                if (email_exists($email)) {
                    $validation_error->add('registration-error-email-exists', 
                        __('An account is already registered with your email address. Please log in.', 'vendor-onboarding-flow')
                    );
                }
                
                error_log('VOF Debug: Email validation result: ' . ($validation_error->get_error_code() ? 'Invalid' : 'Valid'));
            }
            
            return $validation_error;
        }, 1, 5);


		// Add validation before listing submission
		add_action('rtcl_post_new_listing', function() {
		    error_log('VOF Debug: New listing validation');
		
		    if (isset($_POST['vof_email']) && !is_email($_POST['vof_email'])) {
		        Functions::add_notice(__('Please provide a valid email address.', 'vendor-onboarding-flow'), 'error');
		        wp_send_json_error(['message' => ['Please provide a valid email address.']]);
		    }
		}, 1);	


        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // Intercept the AJAX submission
        // add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'vof_handle_onboarding_flow'], 1);
        add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'cursor_handle_listing_submission'], 1);
		
        // Custom submit button 
        remove_action('rtcl_listing_form_end', 
		['Rtcl\Controllers\Hooks\TemplateHooks', 'listing_form_submit_button'], 50);
        add_action('rtcl_listing_form_end', [$this, 'custom_submit_button']);

	    // Add email validation filter with high priority (1)
		add_filter('rtcl_process_registration_errors', function($validation_error, $email, $username=null, $password=null, $userData=null) {
			error_log('VOF Debug: Registration validation filter running');
			
			if (isset($_POST['vof_email'])) {
				// Override the email being validated
				$email = $_POST['vof_email'];
				$_POST['email'] = $_POST['vof_email'];
				$_REQUEST['email'] = $_POST['vof_email'];
				
				error_log('VOF Debug: Email values in registration validation:');
				error_log('Email param: ' . $email);
				error_log('POST[email]: ' . $_POST['email']);
			}
			
			return $validation_error;
		}, 1, 5);  // Priority 1 to run early	

    	// Add filter to modify the response after listing save/update
    	add_filter('rtcl_listing_form_after_save_or_update_responses', function($response) {
    	    error_log('VOF Debug: Response before modification: ' . print_r($response, true));
		
    	    // If we have vof_email in the POST data, ensure it's used
    	    if (isset($_POST['vof_email'])) {
    	        $_POST['email'] = $_POST['vof_email'];
    	        $_REQUEST['email'] = $_POST['vof_email'];
			
    	        error_log('VOF Debug: Email set to: ' . $_POST['email']);
    	    }
		
    	    return $response;
    	}, 1, 1);

        // Register post status and admin filters
        // add_action('init', [$this, 'vof_register_temp_post_status']);
        add_action('admin_init', [$this, 'vof_add_temp_status_to_dropdown']);
        add_filter('parse_query', [$this, 'vof_extend_admin_search']);

		error_log('VOF Debug: VOF handler hooked with priority 1');
		
        // add_action('wp_ajax_rtcl_post_new_listing', [$this, 'vof_handle_listing_submission'], 9);
        // add_action('wp_ajax_rtcl_post_new_listing', [$this, 'vof_handle_onboarding_flow'], 9);
        // add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'vof_handle_listing_submission'], 9);
	    // Add email validation filter
		// add_filter('rtcl_process_registration_errors', function($validation_error, $email, $username=null, $password=null, $userData=null) {
		// 	if (isset($_POST['vof_email'])) {
		// 		// Override the email being validated
		// 		$email = $_POST['vof_email'];
		// 		if (email_exists($email)) {
		// 			$validation_error->add('registration-error-email-exists', 
		// 				__('An account is already registered with your email address. Please log in.', 'vendor-onboarding-flow')
		// 			);
		// 		}
		// 	}
		// 	return $validation_error;
		// }, -10, 5);	

            // Intercept form submission action
                // add_filter('rtcl_listing_save_data', [$this, 'modify_listing_data'], 10, 2);

                // // Intercept user creation rtcl_before_user_registration_form
                // add_filter('rtcl_before_user_registration', [$this, 'prevent_user_registration'], 10, 1);

                // // Modify redirect URL
                // add_filter('rtcl_listing_form_submit_redirect_url', [$this, 'modify_redirect_url'], 10, 2); 
            // end of intercept form submission
    }


    public function enqueue_scripts() {
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


    public function custom_submit_button($post_id) {
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

    private function render_guest_submit_button() {
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

    private function render_subscription_required_button() {
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


/**
 * Register temporary listing status
 */
// public function vof_register_temp_post_status() {
//     register_post_status('rtcl-temp', array(
//         'label' => _x('Temporary', 'vof'),
//         'public' => false,
//         'exclude_from_search' => true,
//         'show_in_admin_all_list' => true,
//         'show_in_admin_status_list' => true,
//         'label_count' => _n_noop('Temporary <span class="count">(%s)</span>',
//                                 'Temporary <span class="count">(%s)</span>')
//     ));
// }

/**
 * Add temporary status to status dropdown in admin
 */
public function vof_add_temp_status_to_dropdown() {
    global $post;
    if($post && $post->post_type === rtcl()->post_type){
        $complete = '';
        if($post->post_status === 'rtcl-temp'){
            $complete = ' selected="selected"';
        }
        echo '<script>
        jQuery(document).ready(function($){
            $("select#post_status").append(\'<option value="rtcl-temp"'.$complete.'>Temporary</option>\');
        });
        </script>';
    }
}

/**
 * Extend admin search to include temporary posts
 */
public function vof_extend_admin_search($query) {
    if(is_admin() && $query->is_main_query()) {
        $post_status = $query->get('post_status');
        if($post_status === '' || $post_status === 'any') {
            $post_status = array('publish', 'pending', 'draft', 'rtcl-temp');
            $query->set('post_status', $post_status);
        }
    }
    return $query;
}    

    public function prevent_user_registration($user_data) {
        if ($this->is_vof_submission()) {
            // Store user data in transient for later
            $listing_id = $_POST['listing_id'] ?? 0;
            set_transient('vof_pending_user_' . $listing_id, $user_data, 24 * HOUR_IN_SECONDS);
            throw new \Exception('User registration deferred');
        }
        return $user_data;
    }

    public function modify_listing_data($data, $listing_id) {
        // Force draft status for our custom flow
        if ($this->is_vof_submission()) {
            $data['post_status'] = 'draft';
        }
        return $data;
    }
    
    public function modify_redirect_url($url, $default = '/') {
        // Validate the URL
        if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
            // Safely redirect
            header("Location: $url");
            exit;
        }
    
        // Fallback to default if validation fails
        header("Location: $default");
        exit;
        // if ($this->is_vof_submission()) {
        //     return 'https://thenoise.io'; // Test URL
        // }
        // return $url;
    }

    private function is_vof_submission() {
        return isset($_POST['vof_flow']) && $_POST['vof_flow'] === 'true';
    }

    private function is_post_ad_page() {
        return strpos($_SERVER['REQUEST_URI'], '/post-an-ad/') !== false;
    }


    /**
     * Retrieve temporary listing data
     */
    // public function get_temp_listing_data($listing_id) {
    //     return get_transient('vof_temp_listing_' . $listing_id);
    // }

    /**
     * Clean up temporary data
     */
    public function cleanup_temp_data($listing_id) {
        delete_transient('vof_temp_listing_' . $listing_id);
        delete_transient('vof_pending_user_' . $listing_id);
    }

    
    public function cursor_handle_listing_submission() {
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

        // Maybe add Ad Type validation SECTION?

        // SECTION: Category validation
        $raw_cat_id = isset($_POST['_category_id']) ? absint($_POST['_category_id']) : 0;
        if (!$raw_cat_id) {
            wp_send_json([
                'success' => false,
                'message' => [__('Category not selected', 'classified-listing')]
            ]);
            return;
        }
    
        $category = get_term_by('id', $raw_cat_id, rtcl()->category);
        if (!is_a($category, \WP_Term::class)) {
            wp_send_json([
                'success' => false,
                'message' => [__('Category is not valid', 'classified-listing')]
            ]);
            return;
        }

        /**
         * This code gets the selected category's ID and finds its 
         * topmost parent category ID in the category hierarchy. The 
         * get_term_top_most_parent_id() function traverses up the 
         * category tree until it reaches a category with no parent 
         * ensuring we know the root-level category for the listing.
         * 
         * Reason: This is important for proper category validation and 
         * organization of listings in a hierarchical category 
         * structure.
         */
        $category_id = $category->term_id;
        // $parent_id = Functions::get_term_top_most_parent_id($category_id, rtcl()->category);

        // SECTION: Get the existing temp post ID (important: required for gallery images with post sync) 
        // - if post_id is set, we're updating an existing post
        // - already set post_id triggered by front-end on image upload
            // - already set post_id is used to sync gallery images
        $post_id = isset($_POST['_post_id']) ? absint($_POST['_post_id']) : 0;
        $cats = [$category_id]; // just in case we need to use it
        $post_arg = [];
        $meta = [];
        
        /**
         * Builds BASE POST. 
         * - collects standard base post arguments 
         * - syncs with gallery images (if any)
         * - updates existing temp post if it exists
         * - sets to 'vof_temp' post status 
         */
        if ($post_id) {
            // Update existing temp post instead of creating new one
            $post = get_post($post_id);
            $title = isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : '';
            $post_arg['ID']           = $post_id;
            // $post_arg['post_title']   = isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : '';
            $post_arg['post_title']   = $title;
            $post_arg['post_name']    = sanitize_title($title);
            $post_arg['post_content'] = isset($_POST['description']) ? Functions::sanitize($_POST['description'], 'content') : '';
            $post_arg['post_status']  = 'vof_temp'; // Keep as temp until subscription
            $post_arg['post_excerpt'] = isset($_POST['excerpt']) ? Functions::sanitize($_POST['excerpt'], 'excerpt') : '';
                      
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
    
        // SECTION: Required Images Check (move up)
        if ( Functions::is_gallery_image_required() && ( !$post_id || !count(Functions::get_listing_images($post_id)))) {
            Functions::add_notice(
                esc_html__('Image is required. Please select an image.', 'classified-listing'),
                'error',
                'rtcl_listing_gallery_image_required'
            );
        }
    

        // NEW: meta data collection from publicUser.php (rtcl_post_new_listing() code reuse)
            
        // Pricing information post_arg
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
        if ( isset( $_POST['rtcl_listing_tag'] ) ) {
            $tags          = Functions::sanitize( $_POST['rtcl_listing_tag'] );
            $tags_as_array = ! empty( $tags ) ? explode( ',', $tags ) : [];
            wp_set_object_terms( $post_id, $tags_as_array, rtcl()->tag );
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

        // TODO: REFACTOR AND ADD COMPLEX FORM RECOLECTION AND OTHER MISSING FIELDS: 

            ////////////// NEW  //////////////////////
		/**
		 * 
		 * Process custom fields (most likely the changing fields 
         * given the category selected or ad type selected)
		 * 
		 * Yes, you're correct. This comment section precedes code that 
         * processes custom fields which are dynamically 
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
		 * This is evident from the code that follows it (rtcl_fields) which 
         * processes these dynamic custom fields and saves their 
         * values to the database.
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
										
			    // TODO: (maybe not here but in the user creation section)
                //CREATE A NEW DB TABLE AND UPDATE VOF-TEMP USER'S METADATA 
                // WITH UUIDS AND OTHER USER METADATA (vof db table for user metadata linked to post_id)
			    // $current_user_id = get_current_user_id();
			    // $ads             = absint( get_user_meta( $current_user_id, '_rtcl_ads', true ) );
			    // update_user_meta( $current_user_id, '_rtcl_ads', $ads + 1 );
            }
        } else {
            Functions::add_notice(
                apply_filters( 'rtcl_listing_error_message', esc_html__( 'Error!!', 'classified-listing' ), $_REQUEST ),
                'error'
            );
        }							            
            

            ////////////// END OF NEW  //////////////////////



            ////////////// OLD  //////////////////////    

        // // Set location terms if provided
        // if (!empty($_POST['location']) && is_array($_POST['location'])) {
        //     wp_set_object_terms($post_id, array_map('absint', $_POST['location']), rtcl()->location);
        // }
    
        // TODO in use: REFACTOR TO PROGRAMMATICALLY COLLECT AND 'rtcl_fields'
            // SECTION: Define? and Save all available meta data
            // $meta_fields = [
            //     // Pricing fields
            //     'price', 
            //     'price_type', 
            //     '_rtcl_price_unit', 
            //     '_rtcl_max_price', 
            //     '_rtcl_listing_pricing',
            
            //     // Contact fields with VOF prefix  
            //     'vof_email', 
            //     'vof_phone', 
            //     'vof_whatsapp_number', 
            //     'website', 
            //     'telegram',
            
            //     // Location fields
            //     'address', 
            //     'zipcode', 
            //     'latitude', 
            //     'longitude', 
            //     '_rtcl_geo_address',
            
            //     // Media fields
            //     '_rtcl_video_urls',
            
            //     // Custom fields (if any)
            //     '_rtcl_custom_fields',
            
            //     // Business hours
            //     '_rtcl_bhs',
            
            //     // Ad type
            //     'ad_type',
            
            //     // Featured listing
            //     '_rtcl_featured',
            
            //     // Other meta fields
            //     '_rtcl_mark_as_sold'
        // ];

        // Define all meta fields that need to be processed
        // $meta_fields = [
            // 'price',
            // '_rtcl_max_price',
            // 'zipcode',
            // 'address',
            // '_rtcl_geo_address',
            // 'phone',
            // '_rtcl_whatsapp_number',
            // 'email',
            // 'website',
            // 'latitude',
            // 'longitude',
            // '_rtcl_price_unit',
            // '_rtcl_price_type',
            // 'price_type',
            // 'ad_type',
            // '_rtcl_listing_pricing',
            // '_rtcl_video_urls',
            // 'rtcl_social_profiles',
            // VOF specific fields (comment)
            // 'vof_email',
            // 'vof_phone',
            // 'vof_whatsapp_number'
        // ];
    
        // TODO: REFACTOR TO ORIGNAL codeblock in "rtcl_post_new_listing()" 
        // @classified-listing/app/Controllers/Ajax/PublicUser.php

        // SECTION: Update the meta fields
            // foreach ($meta_fields as $field) {
            //     if (isset($_POST[$field])) {
            //         $value = $field === 'price' || $field === '_rtcl_max_price' 
            //             ? Functions::format_decimal($_POST[$field])
            //             : Functions::sanitize($_POST[$field]);
    
            //         // Store both VOF and standard versions for contact fields
            //         if (in_array($field, ['vof_email', 'vof_phone', 'vof_whatsapp_number'])) {
            //             // Store VOF version
            //             update_post_meta($post_id, $field, $value);
    
            //             // Store standard version without vof_ prefix
            //             $standard_field = str_replace('vof_', '', $field);
            //             if ($field === 'vof_whatsapp_number') {
            //                 $standard_field = '_rtcl_whatsapp_number'; // Match the core plugin's meta key
            //             }
            //             update_post_meta($post_id, $standard_field, $value);
            //         } else {
            //             update_post_meta($post_id, $field, $value);
            //         }
            //     }
            // }
        
            // // TODO: REFACTOR TO PROGRAMMATICALLY COLLECT AND 'rtcl_fields'
            // // SECTION: Handle custom fields if any
            // if (!empty($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            //     foreach ($_POST['custom_fields'] as $key => $value) {
            //         update_post_meta($post_id, $key, Functions::sanitize($value));
            //     }
        // }

            ////////////// END OF OLD  //////////////////////


        // TODO: REFACTOR TO ORIGNAL codeblock in "rtcl_post_new_listing()" 
        // @classified-listing/app/Controllers/Ajax/PublicUser.php 
        // with customization for 'vof_temp_listing_' (probably use transiennt)

        // SECTION: Store complete form data in transient 
        set_transient('vof_temp_listing_' . $post_id, $_POST, DAY_IN_SECONDS * 3); // 3 days
    
        // SECTION: Send success response
        // Works as expected, no changes needed
        wp_send_json_success([
            'success' => true,
            'listing_id' => $post_id,
            'redirect_url' => VOF_Constants::REDIRECT_URL,
            'message' => [__('Listing saved successfully', 'classified-listing')]
        ]);
    }    


	public function vof_handle_onboarding_flow() {
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