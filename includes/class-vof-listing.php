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
        add_action('init', [$this, 'vof_register_temp_post_status']);
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
public function vof_register_temp_post_status() {
    register_post_status('rtcl-temp', array(
        'label' => _x('Temporary', 'vof'),
        'public' => false,
        'exclude_from_search' => true,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop('Temporary <span class="count">(%s)</span>',
                                'Temporary <span class="count">(%s)</span>')
    ));
}

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



    public function vof_handle_listing_submissionDEP() {
        try {
            // Only intercept if it's our VOF flow
            if (!$this->is_vof_submission()) {
                return;
            }
    
            // Force draft status before submission
            add_filter('wp_insert_post_data', function($data) {
                $data['post_status'] = 'draft';
                return $data;
            });
    
            // Get the PublicUser instance to handle the submission
            $public_user = new \Rtcl\Controllers\Ajax\PublicUser();
            
            // Remove any existing output buffering
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Capture the output from rtcl_post_new_listing
            ob_start();
            $public_user->rtcl_post_new_listing();
            $output = ob_get_clean();
            
            // Parse the JSON response
            $response = json_decode($output, true);
            
            if ($response && isset($response['success']) && $response['success']) {
                // Override the redirect URL
                $response['redirect_url'] = 'https://thenoise.io';
                
                // Store the listing ID in a transient if needed
                if (isset($response['listing_id'])) {
                    set_transient('vof_temp_listing_' . $response['listing_id'], $_POST, 24 * HOUR_IN_SECONDS);
                }
            }
            
            wp_send_json($response);
            
        } catch (\Exception $e) {
            error_log('VOF Listing Submission Error: ' . $e->getMessage());
            wp_send_json_error([
                'success' => false,
                'message' => [$e->getMessage()]
            ]);
        }
        exit;
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

    /**
     * Handles temporary listing submission for the Vendor Onboarding Flow
     * 
     * Key differences from rtcl_post_new_listing():
     * 1. Removes user validation/checks - allows guest submissions
     * 2. Forces 'rtcl-temp' status instead of 'publish'/'pending' 
     * 3. Sets post_author to 0 (no author) since user creation is deferred
     * 4. Removes human verification/captcha checks
     * 5. Removes terms & conditions validation
     * 6. Simplified meta handling - only essential fields
     * 7. No email notifications sent
     * 8. No expiry date set
     * 9. No featured/views meta
     * 10. No user ad count increment
     *
     * This function implements STEP-1 of the VOF flow by:
     * - Allowing temporary listing creation without user registration
     * - Storing listing data for later assignment to user
     * - Deferring actual publication until after successful payment
     * - Bypassing normal listing restrictions
     * 
     * The original rtcl_post_new_listing() assumes an authenticated user and 
     * handles the complete listing creation flow. This version splits that flow
     * to enable our guest user onboarding process.
     */
    public function cursor_handle_listing_submissionOLD() {
        Functions::clear_notices(); // Clear previous notice
        $success = false;
        $post_id = 0;
        $type = 'new';
    
        if (!apply_filters('rtcl_listing_form_remove_nonce', false) 
            && !wp_verify_nonce(isset($_REQUEST[rtcl()->nonceId]) ? $_REQUEST[rtcl()->nonceId] : null, rtcl()->nonceText)) {
            wp_send_json([
                'success' => false,
                'message' => [__('Session Expired!', 'classified-listing')]
            ]);
            return;
        }

        // Category validation
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

        // Build post data
        $title = isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : '';
        $post_arg = [
            'post_title'   => $title,
            'post_content' => isset($_POST['description']) ? Functions::sanitize($_POST['description'], 'content') : '',
            'post_excerpt' => isset($_POST['excerpt']) ? Functions::sanitize($_POST['excerpt'], 'excerpt') : '',
            'post_type'    => rtcl()->post_type,
            'post_status'  => 'rtcl-temp',
            'post_author'  => 0 // No author yet
        ];
    
        // Insert the post
        $post_id = wp_insert_post(apply_filters('rtcl_listing_save_update_args', $post_arg, $type));
    
        if (!$post_id || is_wp_error($post_id)) {
            wp_send_json([
                'success' => false,
                'message' => [__('Error creating listing', 'classified-listing')]
            ]);
            return;
        }
    
        // Set category
        wp_set_object_terms($post_id, [$category->term_id], rtcl()->category);

		// SECTION: Required Images Check

		if ( Functions::is_gallery_image_required() && ( ! $post_id || ! count( Functions::get_listing_images( $post_id ) ) ) ) {
			Functions::add_notice(
				esc_html__( 'Image is required. Please select an image.', 'classified-listing' ),
				'error',
				'rtcl_listing_gallery_image_required'
			);
		}

        //// SECTION: CLAUDE'S CODE ////
        // Handle custom fields if any
        if (!empty($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            foreach ($_POST['custom_fields'] as $key => $value) {
                update_post_meta($post_id, $key, Functions::sanitize($value));
            }
        }

        // Handle gallery images
        if (isset($_POST['rtcl_gallery_ids']) && !empty($_POST['rtcl_gallery_ids'])) {
            $gallery_ids = array_map('absint', explode(',', $_POST['rtcl_gallery_ids']));
            
            // Update gallery meta
            update_post_meta($post_id, '_rtcl_images', $gallery_ids);
            
            // Set featured image if provided
            if (isset($_POST['featured_image_id'])) {
                $featured_id = absint($_POST['featured_image_id']);
                update_post_meta($post_id, '_thumbnail_id', $featured_id);
            }
            
            // Update attachment parent IDs
            foreach ($gallery_ids as $attachment_id) {
                wp_update_post([
                    'ID' => $attachment_id,
                    'post_parent' => $post_id
                ]);
            }
        }

        //// END OF SECTION: CLAUDE'S CODE ////

        // Set location terms if provided
        if (!empty($_POST['location']) && is_array($_POST['location'])) {
            wp_set_object_terms($post_id, array_map('absint', $_POST['location']), rtcl()->location);
        }

        // Save all available meta data
        $meta_fields = [
            // Pricing fields
            'price', 'price_type', '_rtcl_price_unit', '_rtcl_max_price', '_rtcl_listing_pricing',
            
            // Contact fields with VOF prefix  
            'vof_email', 'vof_phone', 'vof_whatsapp_number', 'website', 'telegram',
            
            // Location fields
            'address', 'zipcode', 'latitude', 'longitude', '_rtcl_geo_address',
            
            // Media fields
            '_rtcl_video_urls',
            
            // Custom fields (if any)
            '_rtcl_custom_fields',
            
            // Business hours
            '_rtcl_bhs',
            
            // Ad type
            'ad_type',
            
            // Featured listing
            '_rtcl_featured',
            
            // Other meta fields
            '_rtcl_mark_as_sold'
        ];
    
		// Update the meta fields section in cursor_handle_listing_submission()
		foreach ($meta_fields as $field) {
		    if (isset($_POST[$field])) {
		        $value = $field === 'price' || $field === '_rtcl_max_price' 
		            ? Functions::format_decimal($_POST[$field])
		            : Functions::sanitize($_POST[$field]);

		        // Store both VOF and standard versions for contact fields
		        if (in_array($field, ['vof_email', 'vof_phone', 'vof_whatsapp_number'])) {
		            // Store VOF version
		            update_post_meta($post_id, $field, $value);

		            // Store standard version without vof_ prefix
		            $standard_field = str_replace('vof_', '', $field);
		            if ($field === 'vof_whatsapp_number') {
		                $standard_field = '_rtcl_whatsapp_number'; // Match the core plugin's meta key
		            }
		            update_post_meta($post_id, $standard_field, $value);
		        } else {
		            update_post_meta($post_id, $field, $value);
		        }
		    }
		}

        // Handle custom fields if any
        if (!empty($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            foreach ($_POST['custom_fields'] as $key => $value) {
                update_post_meta($post_id, $key, Functions::sanitize($value));
            }
        }

    
        // Store complete form data in transient
        set_transient('vof_temp_listing_' . $post_id, $_POST, 24 * HOUR_IN_SECONDS);
    
        // Send success response
        wp_send_json([
            'success' => true,
            'listing_id' => $post_id,
            'redirect_url' => VOF_Constants::REDIRECT_URL,
            'message' => [__('Listing saved successfully', 'classified-listing')]
        ]);
    }

    
    public function cursor_handle_listing_submission() {
        Functions::clear_notices(); // Clear previous notice
        $success = false;
        $post_id = 0;
        $type = 'new'; 
    
        if (!apply_filters('rtcl_listing_form_remove_nonce', false) 
            && !wp_verify_nonce(isset($_REQUEST[rtcl()->nonceId]) ? $_REQUEST[rtcl()->nonceId] : null, rtcl()->nonceText)) {
            wp_send_json([
                'success' => false,
                'message' => [__('Session Expired!', 'classified-listing')]
            ]);
            return;
        }
    
        // Category validation
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
    
        // Important: Get the existing temp post ID 
        $post_id = isset($_POST['_post_id']) ? absint($_POST['_post_id']) : 0;
        $post_arg = [];
        
        if ($post_id) {
            // Update existing temp post instead of creating new one
            $post = get_post($post_id);
            $post_arg['ID'] = $post_id;
            $post_arg['post_title'] = isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : '';
            $post_arg['post_content'] = isset($_POST['description']) ? Functions::sanitize($_POST['description'], 'content') : '';
            $post_arg['post_status'] = 'pending'; // Keep as temp until subscription
            // $post_arg['post_status'] = 'rtcl-temp'; // Keep as temp until subscription
            // $post_arg['post_status'] = 'pending'; // Keep as temp until subscription
            // $post_arg['post_status'] = 'draft'; // Keep as temp until subscription
            $post_arg['post_excerpt'] = isset($_POST['excerpt']) ? Functions::sanitize($_POST['excerpt'], 'excerpt') : '';
            
            // Update the post
            $post_id = wp_update_post(apply_filters('rtcl_listing_save_update_args', $post_arg, 'update'));
            
        } else {
            // Create new temp post if none exists
            $post_arg = [
                'post_title'   => isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : '',
                'post_content' => isset($_POST['description']) ? Functions::sanitize($_POST['description'], 'content') : '',
                'post_excerpt' => isset($_POST['excerpt']) ? Functions::sanitize($_POST['excerpt'], 'excerpt') : '',
                'post_status'  => 'pending',
                // 'post_status'  => 'pending',
                // 'post_status'  => 'rtcl-temp',
                // 'post_status'  => 'draft',
                'post_author'  => 0,
                'post_type'    => rtcl()->post_type
            ];
            
            $post_id = wp_insert_post(apply_filters('rtcl_listing_save_update_args', $post_arg, 'new'));
        }
    
        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()]);
            return;
        }
    
        // Add important meta to ensure visibility
        // update_post_meta($post_id, 'post_type', rtcl()->post_type);  // Or your appropriate type
        // update_post_meta($post_id, 'post_type', 'rtcl_listing');  // Or your appropriate type
        // update_post_meta($post_id, '_listing_type', '');  // Or your appropriate type
        
        // update_post_meta($post_id, '_listing_status', 'rtcl-temp');
        // update_post_meta($post_id, 'post_status', 'rtcl-temp');


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
    
        // Set category
        wp_set_object_terms($post_id, [$category->term_id], rtcl()->category);
    
        // SECTION: Required Images Check
        if ( Functions::is_gallery_image_required() && ( !$post_id || !count(Functions::get_listing_images($post_id)))) {
            Functions::add_notice(
                esc_html__('Image is required. Please select an image.', 'classified-listing'),
                'error',
                'rtcl_listing_gallery_image_required'
            );
        }
    
        // Set location terms if provided
        if (!empty($_POST['location']) && is_array($_POST['location'])) {
            wp_set_object_terms($post_id, array_map('absint', $_POST['location']), rtcl()->location);
        }
    
        // Save all available meta data
        $meta_fields = [
            // Pricing fields
            'price', 'price_type', '_rtcl_price_unit', '_rtcl_max_price', '_rtcl_listing_pricing',
            
            // Contact fields with VOF prefix  
            'vof_email', 'vof_phone', 'vof_whatsapp_number', 'website', 'telegram',
            
            // Location fields
            'address', 'zipcode', 'latitude', 'longitude', '_rtcl_geo_address',
            
            // Media fields
            '_rtcl_video_urls',
            
            // Custom fields (if any)
            '_rtcl_custom_fields',
            
            // Business hours
            '_rtcl_bhs',
            
            // Ad type
            'ad_type',
            
            // Featured listing
            '_rtcl_featured',
            
            // Other meta fields
            '_rtcl_mark_as_sold'
        ];

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
    
        // Update the meta fields
        foreach ($meta_fields as $field) {
            if (isset($_POST[$field])) {
                $value = $field === 'price' || $field === '_rtcl_max_price' 
                    ? Functions::format_decimal($_POST[$field])
                    : Functions::sanitize($_POST[$field]);
    
                // Store both VOF and standard versions for contact fields
                if (in_array($field, ['vof_email', 'vof_phone', 'vof_whatsapp_number'])) {
                    // Store VOF version
                    update_post_meta($post_id, $field, $value);
    
                    // Store standard version without vof_ prefix
                    $standard_field = str_replace('vof_', '', $field);
                    if ($field === 'vof_whatsapp_number') {
                        $standard_field = '_rtcl_whatsapp_number'; // Match the core plugin's meta key
                    }
                    update_post_meta($post_id, $standard_field, $value);
                } else {
                    update_post_meta($post_id, $field, $value);
                }
            }
        }
    
        // Handle custom fields if any
        if (!empty($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            foreach ($_POST['custom_fields'] as $key => $value) {
                update_post_meta($post_id, $key, Functions::sanitize($value));
            }
        }
    
        // Store complete form data in transient
        set_transient('vof_temp_listing_' . $post_id, $_POST, DAY_IN_SECONDS * 3); // 3 days
    
        // Send success response
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