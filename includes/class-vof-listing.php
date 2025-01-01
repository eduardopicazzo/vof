<?php

namespace VOF;

use Rtcl\Controllers\FormHandler;
use Rtcl\Helpers\Functions;

class VOF_Listing {
    public function __construct() {
        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // Intercept the AJAX submission
        add_action('wp_ajax_rtcl_post_new_listing', [$this, 'vof_handle_listing_submission'], 9);
        add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'vof_handle_listing_submission'], 9);

        // Custom submit button 
        remove_action('rtcl_listing_form_end', 
            ['Rtcl\Controllers\Hooks\TemplateHooks', 'listing_form_submit_button'], 50);
        add_action('rtcl_listing_form_end', [$this, 'custom_submit_button']);

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

    // /**
    //  * Store temporary listing data
    //  */
    // private function store_temp_listing_data($listing_id, $form_data) {
    //     // Store in transient for 72 hours
    //     set_transient('vof_temp_listing_' . $listing_id, $form_data, 72 * HOUR_IN_SECONDS);
    // }

    /**
     * Retrieve temporary listing data
     */
    public function get_temp_listing_data($listing_id) {
        return get_transient('vof_temp_listing_' . $listing_id);
    }

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
    
        if (!apply_filters('rtcl_listing_form_remove_nonce', false) 
            && !wp_verify_nonce(isset($_REQUEST[rtcl()->nonceId]) ? $_REQUEST[rtcl()->nonceId] : null, rtcl()->nonceText)) {
            wp_send_json([
                'success' => false,
                'message' => [__('Session Expired!', 'classified-listing')]
            ]);
            return;
        }
        // removed is human
        $raw_cat_id = isset($_POST['_category_id']) ? absint($_POST['_category_id']) : 0; // i don't think this does anything
        if (!$raw_cat_id) {
            wp_send_json([
                'success' => false,
                'message' => [__('Category not selected', 'classified-listing')]
            ]);
            return;
        }
        // removed post remaining conditional
        $category = get_term_by('id', $raw_cat_id, rtcl()->category);
        if (!is_a($category, \WP_Term::class)) {
            wp_send_json([
                'success' => false,
                'message' => [__('Category is not valid', 'classified-listing')]
            ]);
            return;
        }
        // removed enable
        // Build post data
        $title = isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : '';
        $post_arg = [
            'post_title'   => $title,
            'post_content' => isset($_POST['description']) ? Functions::sanitize($_POST['description'], 'content') : '',
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
    
        // Save meta data
        $meta_fields = [
            'price', 'price_type', '_rtcl_price_unit', 
            'email', 'phone', 'website', 'address', 'zipcode',
            'latitude', 'longitude', '_rtcl_listing_pricing',
            '_rtcl_max_price', '_rtcl_video_urls', '_rtcl_geo_address'
        ];
    
        foreach ($meta_fields as $field) {
            if (isset($_POST[$field])) {
                $value = $field === 'price' || $field === '_rtcl_max_price' 
                    ? Functions::format_decimal($_POST[$field])
                    : Functions::sanitize($_POST[$field]);
                update_post_meta($post_id, $field, $value);
            }
        }
    
        // Store form data in transient
        set_transient('vof_temp_listing_' . $post_id, $_POST, 24 * HOUR_IN_SECONDS);
    
        // Send success response
        wp_send_json([
            'success' => true,
            'listing_id' => $post_id,
            'redirect_url' => VOF_Constants::REDIRECT_URL,
            'message' => [__('Listing saved successfully', 'classified-listing')]
        ]);
    }

    public function vof_handle_listing_submission() {
		Functions::clear_notices();// Clear previous notice
		$success = false;
		$post_id = 0;
		$type    = 'new';
		if ( apply_filters( 'rtcl_listing_form_remove_nonce', false )
		     || wp_verify_nonce( isset( $_REQUEST[ rtcl()->nonceId ] ) ? $_REQUEST[ rtcl()->nonceId ] : null, rtcl()->nonceText )
		) {
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
			} else { // some kind of corner case server validation when listing submission is triggered
				$raw_cat_id   = isset( $_POST['_category_id'] ) ? absint( $_POST['_category_id'] ) : 0;
				$listing_type = isset( $_POST['_ad_type'] ) && in_array( $_POST['_ad_type'], array_keys( Functions::get_listing_types() ) )
					? esc_attr( $_POST['_ad_type'] ) : '';
				$post_id      = absint( Functions::request( '_post_id' ) );
				if ( ! $raw_cat_id && ! $post_id ) {
					Functions::add_notice(
						apply_filters(
							'rtcl_listing_form_category_not_select_responses',
							sprintf(
							/* translators:  Category not selected */
								esc_html__( 'Category not selected. <a href="%s">Click here to set category</a>', 'classified-listing' ),
								\Rtcl\Helpers\Link::get_listing_form_page_link()
							)
						),
						'error'
					);
				} else {
					// Check if user has not any post remaining -> hook 
                    // takes you to verify_membership_before_category() callback
					do_action( 'rtcl_before_add_edit_listing_before_category_condition', $post_id );
					$category_id = 0;
                    // Check if gallery image is required A MUST
					if ( Functions::is_gallery_image_required() && ( ! $post_id || ! count( Functions::get_listing_images( $post_id ) ) ) ) {
						Functions::add_notice(
							esc_html__( 'Image is required. Please select an image.', 'classified-listing' ),
							'error',
							'rtcl_listing_gallery_image_required'
						);
					}
                    // This section is intended to check the remaining listing counts for the user, 
                    // ensuring that they have not exceeded any limits set by their membership or account type. 
                    // This validation is crucial to prevent users from submitting more listings than they are allowed, 
                    // thereby maintaining the integrity of the listing system.
					if ( ! Functions::notice_count( 'error' ) ) { // probably remove this?
						if ( ( ! $post_id || ( ( $post = get_post( $post_id ) ) && $post->post_type == rtcl()->post_type ) && $post->post_status = 'rtcl-temp' )
						     && $raw_cat_id
						) {
							// This section is intended to check the remaining listing counts for the user, 
							// ensuring that they have not exceeded any limits set by their membership or account type. 
							// However, for non-membership users and new users, there is no need to limit their listing count, 
							// as they will need to pay anyway. This is especially important for registered users who have canceled 
							// their membership but wish to post again. If users had already posted before, they should not be 
							// limited to listing counts, as this will prevent them from onboarding again.
							// It is critical to implement this logic in the validation process to ensure a smooth user experience.
							$category = get_term_by( 'id', $raw_cat_id, rtcl()->category );
							if ( is_a( $category, \WP_Term::class ) ) { // does ad type and category validation (again) as if the dropdowns in the front-end. for corner cases. Leave.
								$category_id = $category->term_id;
								$parent_id   = Functions::get_term_top_most_parent_id( $category_id, rtcl()->category ); // Checks if topmost exists.
								if ( Functions::term_has_children( $category_id ) ) { // Checks if there are children.
									Functions::add_notice( esc_html__( 'Please select ad type and category', 'classified-listing' ), 'error' ); // weird logic here, kinda seems the other way around.
								}
								if ( ! Functions::is_ad_type_disabled() && ! $listing_type ) {
									Functions::add_notice( esc_html__( 'Please select an ad type', 'classified-listing' ), 'error' );
								}
								$cats_on_type = wp_list_pluck( Functions::get_one_level_categories( 0, $listing_type ), 'term_id' );
								if ( ! in_array( $parent_id, $cats_on_type ) ) {
									Functions::add_notice( esc_html__( 'Please select correct type and category', 'classified-listing' ), 'error' );
								}
                                /**
                                 * The 'rtcl_before_add_edit_listing_into_category_condition' hook allows developers to add custom 
                                 * functionality or modify behavior right before the listing is added or edited in relation to 
                                 * category conditions, using the provided $post_id and $category_id for context.
                                 */
								do_action( 'rtcl_before_add_edit_listing_into_category_condition', $post_id, $category_id ); // overrride probably... inject code here.
							} else {
								Functions::add_notice( esc_html__( 'Category is not valid', 'classified-listing' ), 'error' );
							}
						}
						if ( ! $post_id && ! $category_id ) {
							Functions::add_notice( __( 'Category not selected', 'classified-listing' ), 'error' );
						}
					}
					if ( ! Functions::notice_count( 'error' ) ) { // probably remove this too?
						$cats = [ $category_id ];

						$meta = [];
						if ( Functions::is_enable_terms_conditions() && $agree ) { // probably remove this too?
							$meta['rtcl_agree'] = 1;
						}
                        // ensures valid pricing types. from the options.
						if ( isset( $_POST['_rtcl_listing_pricing'] ) && $listing_pricing_type = sanitize_text_field( $_POST['_rtcl_listing_pricing'] ) ) {
							$meta['_rtcl_listing_pricing'] = in_array( $listing_pricing_type, array_keys(\Rtcl\Resources\Options::get_listing_pricing_types() ) )
								? $listing_pricing_type : 'price';
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
                        /**
                         * The selected code checks if video URLs are not disabled and if the _rtcl_video_urls POST variable is set. 
                         * If both conditions are true, it sanitizes the video URLs and stores them in the $meta array under the key 
                         * _rtcl_video_urls. This ensures that any video URLs submitted are clean and safe for use.
                         */
						if ( ! Functions::is_video_urls_disabled() && isset( $_POST['_rtcl_video_urls'] ) ) {
							$meta['_rtcl_video_urls'] = Functions::sanitize( $_POST['_rtcl_video_urls'], 'video_urls' );
						}

						if ( 'geo' === Functions::location_type() ) {
							if ( isset( $_POST['rtcl_geo_address'] ) ) {
								$meta['_rtcl_geo_address'] = Functions::sanitize( $_POST['rtcl_geo_address'] );
							}
						} else {
							if ( isset( $_POST['zipcode'] ) ) {
								$meta['zipcode'] = Functions::sanitize( $_POST['zipcode'] );
							}
							if ( isset( $_POST['address'] ) ) {
								$meta['address'] = Functions::sanitize( $_POST['address'] );
							}
						}

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
						$title               = isset( $_POST['title'] ) ? Functions::sanitize( $_POST['title'], 'title' ) : '';
						
                        /**
                         * $post_arg is an associative array that holds arguments for creating or updating a post, 
                         * including properties like post_title, post_content, and potentially post_status and post_author. 
                         * It is used in the context of handling listing submissions in the plugin.
                         * 
                         * $post_arg is separate from other fields because it specifically focuses on the post's metadata and status, 
                         * while other fields in the $meta array handle additional listing details. This separation helps 
                         * organize data management for different aspects of the listing submission process.
                         * 
                         * The $meta array holds additional details related to the listing submission, such as pricing, 
                         * contact information, and other custom fields that are not part of the post's core metadata.
                         * 
                         * metadata refers to data that provides information about other data.
                         */
                        $post_arg            = [
							'post_title'   => $title,
							'post_content' => isset( $_POST['description'] ) ? Functions::sanitize( $_POST['description'], 'content' ) : '',
						];
						/**
                         * The post object is an instance of the WP_Post class in WordPress, representing a single post or page, 
                         * containing properties like ID, post_title, post_content, and post_status, among others.
                         * 
                         * We need the post object to access and manipulate the specific properties and metadata of a post, 
                         * enabling actions like updating content, checking status, or retrieving details for display.
                         */
                        $post                = get_post( $post_id ); 
						$user_id             = get_current_user_id(); // gets the current user id or 0 if not logged in.
						$post_for_unregister = Functions::is_enable_post_for_unregister();
						if ( ! is_user_logged_in() && $post_for_unregister ) {

							// TO DO: ADD TEMPORARY USER ID FUNCTION HERE IF NEEDED.

							

                            // $new_user_id = Functions::do_registration_from_listing_form( [ 'email' => $meta['email'] ] );
							// if ( $new_user_id && is_numeric( $new_user_id ) ) {
							// 	$user_id = $new_user_id;
							// 	/* translators:  Account registered email sent  */
							// 	Functions::add_notice(
							// 		apply_filters(
							// 			'rtcl_listing_new_registration_success_message',
							// 			sprintf(
							// 			// translators: Email address
							// 				esc_html__( 'A new account is registered, password is sent to your email(%s).', 'classified-listing' ),
							// 				$meta['email']
							// 			),
							// 			$meta['email']
							// 		)
							// 	);
							// }
						}
						// if ( $user_id ) {
							$new_listing_status = Functions::get_option_item( 'rtcl_moderation_settings', 'new_listing_status', 'pending' );
							if ( $post_id && is_object( $post ) && $post->post_type == rtcl()->post_type ) {

								if ( ( $post->post_author > 0
								       && in_array(
									       $post->post_author,
									       [ apply_filters( 'rtcl_listing_post_user_id', get_current_user_id() ), get_current_user_id() ]
								       ) )
								     || ( $post->post_author == 0 && $post_for_unregister )
								) {
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

									if ( $post->post_author == 0 && $post_for_unregister ) {
										$post_arg['post_author'] = $user_id; // TO DO: ADD TEMPORARY USER ID FUNCTION HERE !!!
									}
									$post_arg['ID'] = $post_id;
									$success        = wp_update_post( apply_filters( 'rtcl_listing_save_update_args', $post_arg, $type ) );
								}
							} else {
								$post_arg['post_status'] = $new_listing_status;
								$post_arg['post_author'] = $user_id;
								$post_arg['post_type']   = rtcl()->post_type;
								$post_id                 = $success = wp_insert_post( apply_filters( 'rtcl_listing_save_update_args', $post_arg, $type ) );
							}

							if ( $post_id && isset( $_POST['rtcl_listing_tag'] ) ) {
								$tags          = Functions::sanitize( $_POST['rtcl_listing_tag'] );
								$tags_as_array = ! empty( $tags ) ? explode( ',', $tags ) : [];
								wp_set_object_terms( $post_id, $tags_as_array, rtcl()->tag );
							}

							if ( $type == 'new' && $post_id ) {
								wp_set_object_terms( $post_id, $cats, rtcl()->category );
								$meta['ad_type'] = $listing_type;
							}
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

							// Custom Meta field
							if ( isset( $_POST['rtcl_fields'] ) && $post_id ) {
								foreach ( $_POST['rtcl_fields'] as $key => $value ) {
									$field_id = (int) str_replace( '_field_', '', $key );
									if ( $field = rtcl()->factory->get_custom_field( $field_id ) ) {
										$field->saveSanitizedValue( $post_id, $value );
									}
								}
							}

							/* meta data */
							if ( ! empty( $meta ) && $post_id ) {
								foreach ( $meta as $key => $value ) {
									update_post_meta( $post_id, $key, $value );
								}
							}

							// send emails

							if ( $success && $post_id && ( $listing = rtcl()->factory->get_listing( $post_id ) ) ) {
								if ( $type == 'new' ) {
									update_post_meta( $post_id, 'featured', 0 );
									update_post_meta( $post_id, '_views', 0 );
									$current_user_id = get_current_user_id();
									$ads             = absint( get_user_meta( $current_user_id, '_rtcl_ads', true ) );
									update_user_meta( $current_user_id, '_rtcl_ads', $ads + 1 );
									if ( 'publish' === $new_listing_status ) {
										Functions::add_default_expiry_date( $post_id );
									}
									Functions::add_notice(
										apply_filters(
											'rtcl_listing_success_message',
											// esc_html__( 'Thank you for submitting your ad!', 'classified-listing' ),
											esc_html__( 'HE HE HE HE HE updated!!!!', 'classified-listing' ),
											$post_id,
											$type,
											$_REQUEST
										)
									);
								} elseif ( $type == 'update' ) {
									Functions::add_notice(
										apply_filters(
											'rtcl_listing_success_message',
											esc_html__( 'HE HE HE HE HE  updated !!!', 'classified-listing' ),
											$post_id,
											$type,
											$_REQUEST
										)
									);
								}
                                // TO DO: maybe override this action?
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
						// }
					}
				}
			}
		} else {
			Functions::add_notice( // nonce failed
				apply_filters( 'rtcl_listing_session_error_message', esc_html__( 'Session Error !!', 'classified-listing' ), $_REQUEST ),
				'error'
			);
		}

		$message = Functions::get_notices( 'error' );
		if ( $success ) {
			$message = Functions::get_notices( 'success' );
		}
		Functions::clear_notices(); // Clear all notice created by checkin

		wp_send_json(
			apply_filters(
				'rtcl_listing_form_after_save_or_update_responses',
				[
					'message'      => $message,
					'success'      => $success,
					'post_id'      => $post_id,
					'type'         => $type,
					'redirect_url' => apply_filters( // TO DO: OVERRIDE TO THENOISE.IO
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