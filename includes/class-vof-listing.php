<?php
namespace VOF;

use Rtcl\Helpers\Functions;                 
use VOF\Utils\Helpers\VOF_Helper_Functions;
use Rtcl\Models\RtclEmails;
use Rtcl\Emails\UserNewRegistrationEmailToUser;

class VOF_Listing {
    public function __construct() {
        error_log('VOF Debug: VOF_Listing constructor called');

        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'vof_enqueue_scripts']);

        // Intercept the AJAX submission
        // add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'vof_cursor_handle_listing_submission'], 1); // commented for testing using STUB or MODAL
        // add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'vof_cursor_handle_listing_submission_STUB'], 1);
        
        // Make sure WP is fully loaded before checking subscriptions
        // add_action('init', function() {
            //     if (!is_user_logged_in()) { // fails on ajax response
            //         add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'vof_cursor_handle_listing_submission_MODAL'],   1);
            //     }
            //     if (is_user_logged_in() && !VOF_Helper_Functions::vof_has_active_subscription()) { // fails on ajax response
            //         add_action('wp_ajax_rtcl_post_new_listing', [ $this, 'vof_cursor_handle_listing_submission_MODAL' ], 1);
            //     }
            //     if (is_user_logged_in() && VOF_Helper_Functions::vof_has_active_subscription())  { // WORKS
            //         remove_action('wp_ajax_rtcl_post_new_listing', [ $this, 'vof_cursor_handle_listing_submission_MODAL' ], 1);
            //     }
            // });
            
        add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'vof_cursor_handle_listing_submission_MODAL'],   1);
        add_action('wp_ajax_rtcl_post_new_listing', [ $this, 'vof_cursor_handle_listing_submission_MODAL' ], 1);
        
        // Custom submit button 
        remove_action('rtcl_listing_form_end', ['Rtcl\Controllers\Hooks\TemplateHooks', 'listing_form_submit_button'], 50);
        add_action('rtcl_listing_form_end', [$this, 'vof_custom_submit_button']);

        error_log('VOF Debug: VOF handler hooked with priority 1');
    }

    public function vof_enqueue_scripts() {
        if ($this->vof_is_post_ad_page()) {
            wp_enqueue_script(
                'vof-listing-submit',
                plugin_dir_url(VOF_PLUGIN_FILE) . 'assets/js/vof-listing-submit.js',
                ['jquery', 'rtcl-public'],
                VOF_VERSION,
                true
            );
        }
    }

    public function vof_custom_submit_button($post_id) {
        $form_handler = new \VOF\VOF_Form_Handler();
        if (!is_user_logged_in() && $this->vof_is_post_ad_page()) {
            $is_guest = true;
            $this->vof_render_guest_submit_button($is_guest);
            $form_handler->vof_show_pricing_modal(); // MAYBE REMOVE??
            return;
        }

        if (is_user_logged_in() && !VOF_Helper_Functions::vof_has_active_subscription() && $this->vof_is_post_ad_page()) {
            $is_guest = false;           
            // $this->vof_render_subscription_required_button();
            $this->vof_render_guest_submit_button($is_guest); 
            $form_handler->vof_show_pricing_modal(); // MAYBE REMOVE??
            return;
        }

        if (is_user_logged_in() && VOF_Helper_Functions::vof_has_active_subscription() && $this->vof_is_post_ad_page()) {
            ?>
            <button type="submit" class="btn btn-primary rtcl-submit-btn">
                <?php echo esc_html($post_id > 0 ? __('Update', 'classified-listing') : __('Submit', 'classified-listing')); ?>
            </button>
            <?php
            return;
        }
    }

    private function vof_render_guest_submit_button($is_guest) {
        wp_enqueue_script('vof-listing-submit');
        wp_enqueue_script('rtcl-gallery');
        ?>
        <div class="form-group">
            <button type="button" 
                    class="vof-guest-submit-btn btn btn-primary" 
                    >
                    <!-- onclick="handleTempListing()"> -->
                <?php echo esc_html($is_guest == true ? __('Continue to Create Account', 'vendor-onboarding-flow') : __('Continue to Select Plan', 'vendor-onboarding-flow')); ?>
                <!-- <//?php esc_html_e('Continue to Create Account', 'vendor-onboarding-flow'); ?> -->
            </button>
        </div>
        <?php
    }

    private function vof_render_subscription_required_button() { // not used
        wp_enqueue_script('vof-listing-submit');
        wp_enqueue_script('rtcl-gallery');
        ?>
        <div class="form-group">
            <button type="button" 
                    class="vof-subscription-submit-btn btn btn-primary" 
                    >
                    <!-- onclick="handleTempListing()"> -->
                <?php esc_html_e('Continue to Select Plan', 'vendor-onboarding-flow'); ?>
            </button>
        </div>
        <?php
    }

    private function vof_is_post_ad_page() {
        return strpos($_SERVER['REQUEST_URI'], '/post-an-ad/') !== false;
    }
   
    public function vof_cursor_handle_listing_submission() { // REMOVE: NOT USED
        Functions::clear_notices();
        $success = false;
        $post_id = 0;
        $type = 'new'; 

        // Verify nonce and security checks
        if (!apply_filters('rtcl_listing_form_remove_nonce', false) 
            && !wp_verify_nonce(isset($_REQUEST[rtcl()->nonceId]) ? $_REQUEST[rtcl()->nonceId] : null, rtcl()->nonceText)) {
            wp_send_json([
                'success' => false,
                'message' => [__('Session Expired!', 'classified-listing')]
            ]);
            return;
        }

        // Collect and validate basic data
        $raw_cat_id = isset($_POST['_category_id']) ? absint($_POST['_category_id']) : 0;
        $listing_type = isset($_POST['_ad_type']) && in_array($_POST['_ad_type'], array_keys(Functions::get_listing_types()))
            ? esc_attr($_POST['_ad_type']) : '';
        $post_id = isset($_POST['_post_id']) ? absint($_POST['_post_id']) : 0;

        // Validate category selection
        if (!$raw_cat_id && !$post_id) {
            Functions::add_notice(
                apply_filters(
                    'rtcl_listing_form_category_not_select_responses',
                    sprintf(
                        esc_html__('Category not selected. <a href="%s">Click here to set category</a>', 'classified-listing'),
                        \Rtcl\Helpers\Link::get_listing_form_page_link()
                    )
                ),
                'error'
            );
            return;
        }

        // Validate required images
        if (Functions::is_gallery_image_required() && (!$post_id || !count(Functions::get_listing_images($post_id)))) {
            Functions::add_notice(
                esc_html__('Image is required. Please select an image.', 'classified-listing'),
                'error',
                'rtcl_listing_gallery_image_required'
            );
            return;
        }

        // Process category and validation (REMOVE)
        $category = get_term_by('id', $raw_cat_id, rtcl()->category);
        if (!is_a($category, \WP_Term::class)) {
            Functions::add_notice(esc_html__('Category is not valid', 'classified-listing'), 'error');
            return;
        }
        // Add back the advanced category validation
        if (!Functions::notice_count('error')) {
            if ((!$post_id || (($post = get_post($post_id)) && $post->post_type == rtcl()->post_type) && $post->post_status = 'vof_temp') 
                && $raw_cat_id
            ) {
                $category = get_term_by('id', $raw_cat_id, rtcl()->category);
                if (is_a($category, \WP_Term::class)) {
                    $category_id = $category->term_id;
                    $parent_id = Functions::get_term_top_most_parent_id($category_id, rtcl()->category);

                    // Validate category leaf node (no children allowed)
                    if (Functions::term_has_children($category_id)) {
                        Functions::add_notice(esc_html__('Please select ad type and category', 'classified-listing'), 'error');
                    }

                    // Ensure listing type is selected if required
                    if (!Functions::is_ad_type_disabled() && !$listing_type) {
                        Functions::add_notice(esc_html__('Please select an ad type', 'classified-listing'), 'error');
                    }

                    // Verify category compatibility with listing type
                    $cats_on_type = wp_list_pluck(Functions::get_one_level_categories(0, $listing_type), 'term_id');
                    if (!in_array($parent_id, $cats_on_type)) {
                        Functions::add_notice(esc_html__('Please select correct type and category', 'classified-listing'), 'error');
                    }

                    do_action('rtcl_before_add_edit_listing_into_category_condition', $post_id, $category_id);
                } else {
                    Functions::add_notice(esc_html__('Category is not valid', 'classified-listing'), 'error');
                }
            }
        
            // Final category existence check
            if (!$post_id && !$category_id) {
                Functions::add_notice(__('Category not selected', 'classified-listing'), 'error');
            }
        }
        
        // Create or update post
        $post_data = [
            'post_title' => isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : '',
            'post_name' => sanitize_title(isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : ''),
            'post_content' => isset($_POST['description']) ? Functions::sanitize($_POST['description'], 'content') : '',
            'post_excerpt' => isset($_POST['excerpt']) ? Functions::sanitize($_POST['excerpt'], 'excerpt') : '',
            'post_status' => 'vof_temp',
            'post_type' => rtcl()->post_type,
        ];

        if ($post_id) {
            $post_data['ID'] = $post_id;
            $post_id = wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data);
        }
        
        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()]);
            return;
        }
        
        VOF_Listing::vof_get_set_extra_listing_fields($post_id);
        VOF_Listing::vof_handle_gallery_attachments($post_id);
        VOF_Listing::vof_set_ad_type($post_id, $listing_type, $category_id);
        
        // Initialize new listing metadata (test if need conditionals...)
        update_post_meta( $post_id, 'featured', 0 ); 
        update_post_meta( $post_id, '_views', 0 );

        // Set category
        wp_set_object_terms($post_id, [$category->term_id], rtcl()->category);

        // Collect user data for new schema
        $vof_user_data = [
            'vof_email' => isset($_POST['vof_email']) ? sanitize_email($_POST['vof_email']) : '',
            'vof_phone' => isset($_POST['vof_phone']) ? sanitize_text_field($_POST['vof_phone']) : '',
            'vof_whatsapp_number' => isset($_POST['vof_whatsapp_number']) ? sanitize_text_field($_POST['vof_whatsapp_number']) : '',
            'post_parent_cat' => $parent_id,
            'vof_tier' => sanitize_text_field(self::vof_get_available_tiers($parent_id)),
            'post_status' => $post_data['post_status']
        ];

        // Create temp user entry with new schema
        $vof_temp_user_meta = VOF_Core::instance()->temp_user_meta();
        $uuid = $vof_temp_user_meta->vof_create_temp_user($post_id, $vof_user_data);

        if (!$uuid) {
            wp_send_json_error([
                'message' => [__('Failed to create temporary user record', 'vendor-onboarding-flow')]
            ]);
            return;
        }

        // Store temporary form data
        set_transient('vof_temp_listing_' . $post_id, $_POST, DAY_IN_SECONDS);

        try {
            // Get API instance from VOF core
            $api = VOF_Core::instance()->vof_get_vof_api();
            
            // Create checkout session
            $request = new \WP_REST_Request('POST', '/vof/v1/checkout');
            $request->set_param('uuid', $uuid);
            $request->set_param('listing_id', $post_id);
            
            $checkout_response = $api->vof_process_checkout($request);
            
            if (is_wp_error($checkout_response)) {
                throw new \Exception($checkout_response->get_error_message());
            }
            
            $response_data = $checkout_response->get_data();
            
            if (empty($response_data['data']['checkout_url'])) {
                throw new \Exception('No checkout URL returned from Stripe');
            }
            
            wp_send_json_success([
                'success' => true,
                'listing_id' => $post_id,
                'uuid' => $uuid,
                'data' => [
                    'checkout_url' => $response_data['data']['checkout_url'],
                    'session_id' => $response_data['data']['session_id']
                ],
                'message' => [__('Listing saved successfully. Redirecting to checkout...', 'vendor-onboarding-flow')]
            ]);
            
        } catch (\Exception $e) {
            error_log('VOF Error: Checkout process failed - ' . $e->getMessage());
            
            wp_send_json_success([
                'success' => true,
                'listing_id' => $post_id,
                'uuid' => $uuid,
                'error' => true,
                'message' => [__('Listing saved but checkout setup failed. Please try again.', 'vendor-onboarding-flow')]
            ]);
        }
    }

    /**
     * helpers for post section: move to helpers file soon
     */
    public function vof_get_set_extra_listing_fields($post_id) {
        $meta = [];

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
		if ( isset( $_POST['vof_phone'] ) ) {
			$meta['phone'] = Functions::sanitize( $_POST['vof_phone'] );
		}
        if ( isset( $_POST['vof_whatsapp_number'] ) ) {
			$meta['_rtcl_whatsapp_number'] = Functions::sanitize( $_POST['vof_whatsapp_number'] );
		}
        // if ( isset( $_POST['_rtcl_telegram'] ) ) {
		// 	$meta['_rtcl_telegram'] = Functions::sanitize( $_POST['_rtcl_telegram'] );
		// }
		if ( isset( $_POST['vof_email'] ) ) {
			$meta['email'] = Functions::sanitize( $_POST['vof_email'], 'email' );
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

        // Save and set all collected meta data
        if ( ! empty( $meta ) && $post_id ) {
            foreach ( $meta as $key => $value ) {
                update_post_meta( $post_id, $key, $value );
            }
        }

        // Save and set rtcl fields
        if ( isset( $_POST['rtcl_fields'] ) && $post_id ) {
            foreach ( $_POST['rtcl_fields'] as $key => $value ) {
                $field_id = (int) str_replace( '_field_', '', $key );
                if ( $field = rtcl()->factory->get_custom_field( $field_id ) ) {
                    $field->saveSanitizedValue( $post_id, $value );
                }
            }
        }        

        // wp_set_object_terms
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
        // end wp_set_object_terms


        // SECTION: save an set Business Hours Processing
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


        // SECTION: save and set Social Profiles Processing
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
    }

    public function vof_handle_gallery_attachments($post_id) {
        $gallery_ids = isset($_POST['rtcl_gallery_ids']) ? array_map('absint', explode(',', $_POST['rtcl_gallery_ids'])) : [];
        if (!empty($gallery_ids)) {
            foreach ($gallery_ids as $attachment_id) {
                wp_update_post([
                    'ID' => $attachment_id,
                    'post_parent' => $post_id
                ]);
            }
            
            if (isset($_POST['featured_image_id'])) {
                set_post_thumbnail($post_id, absint($_POST['featured_image_id']));
            } else if (!empty($gallery_ids[0])) {
                set_post_thumbnail($post_id, $gallery_ids[0]);
            }
        }

    }

    public function vof_set_ad_type($post_id, $listing_type, $category_id, $type = 'new') {

        if ( $type == 'new' && $post_id ) {
            wp_set_object_terms( $post_id, [$category_id], rtcl()->category );
            $meta['ad_type'] = $listing_type;
        }
         // Save all collected meta data
        if ( ! empty( $meta ) && $post_id ) {
            foreach ( $meta as $key => $value ) {
                update_post_meta( $post_id, $key, $value );
            }
        }
    }

    public function vof_get_available_tiers($parent_cat_id) {
        switch($parent_cat_id) {
            case '415': // bicicletas
            case '413': // autopartes, acc, refaccs
                return 'all_tiers';
                    break;
            case '258': // inmuebles
            case '242': // autos y vehiculos
            case '480': // maquinaria
                return 'limit_tiers';
                break;
            default:
                return 'all_tiers';
                break;
        }
    }

    public function vof_cursor_handle_listing_submission_STUB() {
        error_log('VOF Debug: Using STUB submission handler');
        
        // Simulate successful response with correct structure
        wp_send_json_success([
            'stub_mode' => true, // Directly under the main data object
            'show_modal' => true, // Explicit modal trigger
            'checkout_url' => null, // Prevent redirect
            'message' => [__('STUB: Ready for pricing selection', 'vendor-onboarding-flow')]
        ]);
    }

    public function vof_cursor_handle_listing_submission_MODAL() {
        // Check user status and subscription at the start
        if (is_user_logged_in()) {
            if (VOF_Helper_Functions::vof_has_active_subscription()) {
                // Let the default handler take over
                return;
            }
            // Continue processing for logged-in users without subscription
        }

        Functions::clear_notices();
        $success = false;
        $post_id = 0;
        $type = 'new'; 

        // Verify nonce and security checks
        if (!apply_filters('rtcl_listing_form_remove_nonce', false) 
            && !wp_verify_nonce(isset($_REQUEST[rtcl()->nonceId]) ? $_REQUEST[rtcl()->nonceId] : null, rtcl()->nonceText)) {
            wp_send_json([
                'success' => false,
                'message' => [__('Session Expired!', 'classified-listing')]
            ]);
            return;
        }

        // Collect and validate basic data
        $raw_cat_id = isset($_POST['_category_id']) ? absint($_POST['_category_id']) : 0;
        $listing_type = isset($_POST['_ad_type']) && in_array($_POST['_ad_type'], array_keys(Functions::get_listing_types()))
            ? esc_attr($_POST['_ad_type']) : '';
        $post_id = isset($_POST['_post_id']) ? absint($_POST['_post_id']) : 0;

        // Validate category selection
        if (!$raw_cat_id && !$post_id) {
            Functions::add_notice(
                apply_filters(
                    'rtcl_listing_form_category_not_select_responses',
                    sprintf(
                        esc_html__('Category not selected. <a href="%s">Click here to set category</a>', 'classified-listing'),
                        \Rtcl\Helpers\Link::get_listing_form_page_link()
                    )
                ),
                'error'
            );
            return;
        }

        // Validate required images
        if (Functions::is_gallery_image_required() && (!$post_id || !count(Functions::get_listing_images($post_id)))) {
            Functions::add_notice(
                esc_html__('Image is required. Please select an image.', 'classified-listing'),
                'error',
                'rtcl_listing_gallery_image_required'
            );
            return;
        }

        // Process category and validation (REMOVE)
        $category = get_term_by('id', $raw_cat_id, rtcl()->category);
        if (!is_a($category, \WP_Term::class)) {
            Functions::add_notice(esc_html__('Category is not valid', 'classified-listing'), 'error');
            return;
        }
        // Add back the advanced category validation
        if (!Functions::notice_count('error')) {
            if ((!$post_id || (($post = get_post($post_id)) && $post->post_type == rtcl()->post_type) && $post->post_status = 'vof_temp') 
                && $raw_cat_id
            ) {
                $category = get_term_by('id', $raw_cat_id, rtcl()->category);
                if (is_a($category, \WP_Term::class)) {
                    $category_id = $category->term_id;
                    $parent_id = Functions::get_term_top_most_parent_id($category_id, rtcl()->category);

                    // Validate category leaf node (no children allowed)
                    if (Functions::term_has_children($category_id)) {
                        Functions::add_notice(esc_html__('Please select ad type and category', 'classified-listing'), 'error');
                    }

                    // Ensure listing type is selected if required
                    if (!Functions::is_ad_type_disabled() && !$listing_type) {
                        Functions::add_notice(esc_html__('Please select an ad type', 'classified-listing'), 'error');
                    }

                    // Verify category compatibility with listing type
                    $cats_on_type = wp_list_pluck(Functions::get_one_level_categories(0, $listing_type), 'term_id');
                    if (!in_array($parent_id, $cats_on_type)) {
                        Functions::add_notice(esc_html__('Please select correct type and category', 'classified-listing'), 'error');
                    }

                    do_action('rtcl_before_add_edit_listing_into_category_condition', $post_id, $category_id);
                } else {
                    Functions::add_notice(esc_html__('Category is not valid', 'classified-listing'), 'error');
                }
            }
        
            // Final category existence check
            if (!$post_id && !$category_id) {
                Functions::add_notice(__('Category not selected', 'classified-listing'), 'error');
            }
        }
        
        // Create or update post
        $post_data = [
            'post_title' => isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : '',
            'post_name' => sanitize_title(isset($_POST['title']) ? Functions::sanitize($_POST['title'], 'title') : ''),
            'post_content' => isset($_POST['description']) ? Functions::sanitize($_POST['description'], 'content') : '',
            'post_excerpt' => isset($_POST['excerpt']) ? Functions::sanitize($_POST['excerpt'], 'excerpt') : '',
            'post_status' => 'vof_temp',
            'post_type' => rtcl()->post_type,
        ];

        // Collect user data for new schema
        $vof_user_data = [
            'vof_email' => isset($_POST['vof_email']) ? sanitize_email($_POST['vof_email']) : '',
            'vof_phone' => isset($_POST['vof_phone']) ? sanitize_text_field($_POST['vof_phone']) : '',
            'vof_whatsapp_number' => isset($_POST['vof_whatsapp_number']) ? sanitize_text_field($_POST['vof_whatsapp_number']) : '',
            'post_parent_cat' => $parent_id,
            'vof_tier' => sanitize_text_field(self::vof_get_available_tiers($parent_id)),
            'post_status' => $post_data['post_status']
        ];

        // Create temp user entry with new schema
        $vof_temp_user_meta = VOF_Core::instance()->temp_user_meta();
        $uuid = $vof_temp_user_meta->vof_create_temp_user($post_id, $vof_user_data);

        if (!$uuid) {
            wp_send_json_error([
                'message' => [__('Failed to create temporary user record', 'vendor-onboarding-flow')]
            ]);
            return;
        }
        
        $new_user_id = VOF_Listing::vof_handle_user_creation($vof_user_data['vof_email'], $post_id, $uuid);
        $post_data['post_author'] = $new_user_id;

        if ($post_id) { // probably fix this... make it clearer or something
            $post_data['ID'] = $post_id;
            $post_id = wp_update_post( apply_filters( 'rtcl_listing_save_update_args', $post_data, $type ) );
        } else {
            $post_id = wp_insert_post( apply_filters( 'rtcl_listing_save_update_args', $post_data, $type ) );
        }
        
        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()]);
            return;
        }
        
        VOF_Listing::vof_get_set_extra_listing_fields($post_id);
        VOF_Listing::vof_handle_gallery_attachments($post_id);
        VOF_Listing::vof_set_ad_type($post_id, $listing_type, $category_id);

        // Initialize new listing metadata (test if need conditionals...)
        update_post_meta( $post_id, 'featured', 0 ); 
        update_post_meta( $post_id, '_views', 0 );

        // Set category
        wp_set_object_terms($post_id, [$category->term_id], rtcl()->category);

        // Store temporary form data
        // set_transient('vof_temp_listing_' . $post_id, $_POST, DAY_IN_SECONDS);

        try {
            // Get API instance from VOF core
            $api = VOF_Core::instance()->vof_get_vof_api();
            
            // Create validation request instead of checkout session
            $request = new \WP_REST_Request('POST', '/vof/v1/checkout/start');
            $request->set_param('uuid', $uuid);
            $request->set_param('vof_email', $vof_user_data['vof_email']);
            $request->set_param('vof_phone', $vof_user_data['vof_phone']);
            $request->set_param('post_id', $post_id);
            
            $validation_response = $api->vof_validate_checkout_start($request);
            
            if (is_wp_error($validation_response)) {
                throw new \Exception($validation_response->get_error_message());
            }
            
            $response_data = $validation_response->get_data();
            
            wp_send_json_success([
                'success' => true,
                'listing_id' => $post_id,
                'uuid' => $response_data['customer_meta']['uuid'],
                'customer_meta' => $response_data['customer_meta'],
                'pricing_data' => $response_data['pricing_data'],
                'stub_mode' => false,
                'show_modal' => true,
                'message' => [__('Ready for pricing selection', 'vendor-onboarding-flow')]
            ]);
            
        } catch (\Exception $e) {
            error_log('VOF Error: Validation process failed - ' . $e->getMessage());
            
            wp_send_json_error([
                'success' => false,
                'error' => true,
                'message' => [__('Validation failed. Please try again.', 'vendor-onboarding-flow')]
            ]);
        }
    }

    private function vof_handle_user_creation($email, $post_id, $uuid) {
        error_log('VOF Debug: Starting user creation for email: ' . $email);

        // Get temp user meta instance
        $temp_user_meta = VOF_Core::instance()->temp_user_meta();

        // Check if user exists by email
        $existing_user = email_exists($email);

        if ($existing_user) {
            // Check membership status
            $has_active_membership = VOF_Helper_Functions::vof_has_active_subscription($existing_user);

            if (!$has_active_membership) {
                // Existing user with inactive membership
                $temp_data = [
                    'user_type' => 'returning',
                    'true_user_id' => $existing_user,
                    'password' => '',
                    'vof_flow_status' => 'started'
                ];

                $temp_user_meta->vof_set_temp_user_data_credentials_by_uuid($uuid, $temp_data);

                return $existing_user;
            } else { // TEST FOR THIS CASE
                // User exists with active membership
                throw new \Exception(__('You already have an account with an active membership. Please log in to continue.', 'vendor-onboarding-flow'));
            }
        } else {
            // Create new user
            $password = wp_generate_password();

            error_log('VOF Debug: Temp password generated: ' . $password);

            $user_data = [
                'email' => $email,
                'password' => $password,
                'first_name' => '', // Can be updated later
                'last_name' => '',  // Can be updated later
                'phone' => isset($_POST['vof_phone']) ? sanitize_text_field($_POST['vof_phone']) : ''
            ];

            // Create user using adapted RTCL method
            $new_user_id = $this->vof_create_new_user($user_data);

            if (is_wp_error($new_user_id)) {
                throw new \Exception($new_user_id->get_error_message());
            }

            if (!$new_user_id || !is_numeric($new_user_id)) {
                error_log('VOF Debug: user creation -> $new_user_id not valid or non-numeric: ', $new_user_id);
            }
            
            error_log('VOF Debug: New user created with ID: ' . $new_user_id);

            // Get the user data object with the newly created user id
            $new_user_data = get_userdata($new_user_id);
            
            if (!$new_user_data) {
                error_log('VOF Error: Failed to get user data for new user ID: ' . $new_user_id);
                throw new \Exception(__('Failed to retrieve user data.', 'vendor-onboarding-flow'));
            }
            
            error_log('VOF Debug: New-user user data retrieved: ' . print_r($new_user_data, true));


            // Store temp user data
            $temp_data = [
                'user_type' => 'newcomer',
                'true_user_id' => $new_user_id,
                'password' => $password,
                'vof_flow_status' => 'started'
            ];

            $temp_user_meta->vof_set_temp_user_data_credentials_by_uuid($uuid, $temp_data);

            // Send welcome email with credentials
            error_log('VOF Debug: Attempting to send email to: ' . $email);
            $email_result = $this->vof_send_new_user_notification($new_user_data, $password);
            if (!$email_result) {
                error_log('VOF Warning: Failed to send welcome email to new user: ' . $email);
            }

            return $new_user_id;
        }
    }

    private function vof_create_new_user($user_data) {
        // Adapted from RTCL's create_new_user method
        $email = $user_data['email'];
        $password = $user_data['password'];

        // Build args array for username creation
        $new_user_args = [
        'first_name' => isset($user_data['first_name']) ? $user_data['first_name'] : '',
        'last_name' => isset($user_data['last_name']) ? $user_data['last_name'] : '',
        'phone' => isset($user_data['phone']) ? $user_data['phone'] : ''
        ];

        // Generate username from email (or use \RTCL\Helpers\Functions::create_new_user_username)
        $username = VOF_Helper_Functions::vof_create_username(
            $email,
            $new_user_args
        );

        // Basic validation
        if (empty($email) || !is_email($email)) {
            return new \WP_Error('registration-error-invalid-email', __('Please provide a valid email address.', 'vendor-onboarding-flow'));
        }

        // Create user with minimal data
        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_pass' => $password,
            'user_email' => $email,
            'first_name' => $new_user_args['first_name'],
            'last_name' => $new_user_args['last_name'],
            'role' => 'subscriber' // Base role - can be upgraded later
        ]);

        if (!is_wp_error($user_id)) {
            // Add phone if provided
            if (!empty($user_data['phone'])) {
                update_user_meta($user_id, '_vof_phone', $user_data['phone']);
            }

            do_action('vof_new_user_created', $user_id, $user_data);
        }

        return $user_id;
    }
    
    private function vof_send_new_user_notification($new_user_data, $password) {
        if (!is_a($new_user_data, 'WP_User')) {
            error_log('VOF Error: Invalid user object passed to vof_send_new_user_notification');
            return false;
        }

        $mailer = rtcl()->mailer();

        if (!$mailer) {
            error_log('VOF Error: RTCL mailer not initialized');
            return false;
        }

        // Set HTML headers
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        // Use RTCL's mailer with custom template
        $email_content = apply_filters(
            'vof_new_user_email_notification',
            [
                'to' => $new_user_data->user_email,
                'subject' => __('Your new account information', 'vendor-onboarding-flow'),
                'message' => $this->vof_get_new_user_email_content($new_user_data, $password),
                'headers' => $headers
            ]
        );
        
        // Send the email with proper parameters
        $result = $mailer->send(
            $email_content['to'], 
            $email_content['subject'],
            $email_content['message'],
            $email_content['headers']
        );
    
        // Log the result
        error_log('VOF Debug: Email send attempt to ' . $new_user_data->user_email . ' result: ' . ($result ? 'success' : 'failed'));
        
        return $result;
    }
    
    private function vof_get_new_user_email_content($new_user_data, $password) {
        
        $lost_password_url = \Rtcl\Helpers\Link::lostpassword_url();

        $message = sprintf(
            /* translators: %s: Customer username */
            '<p>' . esc_html__('Hi %s,', 'vendor-onboarding-flow') . '</p>', 
            esc_html($new_user_data->display_name)
        );
        
        $message .= sprintf(
            /* translators: %1$s: Site title */
            '<p>' . esc_html__('Thanks for creating an account on %1$s. Your account has been created with the following credentials:', 'vendor-onboarding-flow') . '</p>',
            esc_html(get_bloginfo('name'))
        );
        
        $message .= '<p>' . sprintf(
            /* translators: %s: Username */
            esc_html__('Username: %s', 'vendor-onboarding-flow'),
            esc_html($new_user_data->user_login)
        ) . '</p>';
        
        $message .= '<p>' . sprintf(
            /* translators: %s: Password */
            esc_html__('Password: %s', 'vendor-onboarding-flow'),
            esc_html($password)
        ) . '</p>';
    
        $message .= '<p>' . sprintf(
            /* translators: %s: Password change link */
            esc_html__('Click here to change your password: %s', 'vendor-onboarding-flow'),
            '<a href="' . esc_url($lost_password_url) . '">' . 
            esc_html__('Change Password', 'vendor-onboarding-flow') . '</a>'
        ) . '</p>';
    
        return apply_filters('vof_new_user_email_content', $message, $new_user_data, $password);
    }
}