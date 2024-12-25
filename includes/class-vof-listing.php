<?php

namespace VOF;

// TODO: Rename to VOF_Listing_Form
class VOF_Listing {
    public function __construct() {
        //remove_action('rtcl_listing_form_submit_button', 
        remove_action('rtcl_listing_form_end', 
            ['Rtcl\Controllers\Hooks\TemplateHooks', 'listing_form_submit_button'], 50);
        add_action('rtcl_listing_form_end', [$this, 'custom_submit_button']);

        add_filter('rtcl_category_validation', function($is_valid, $cat_id) {
                 return true; // Always allow posting
        }, 10, 2);

        // Add wp-api settings
        add_action('wp_enqueue_scripts', [$this, 'enqueue_api_settings']);

        // Register REST route
        add_action('rest_api_init', function () {
            register_rest_route('vof/v1', '/save-temp-listing', array(
                'methods' => 'POST',
                'callback' => [$this, 'save_temp_listing'],
                'permission_callback' => '__return_true'
            ));
        });
    }
    

    public function save_temp_listing($request) {
        $params = $request->get_params();

        // creat temporary post
        $listing_data = array(
            'post_title'    => sanitize_text_field($params['title']),
            'post_content'  => wp_kses_post($params['description'] ?? ''),
            'post_status'   => 'draft',
            'post_type'     => 'rtcl_listing'
        );

        $listing_id = wp_insert_post($listing_data);

        if (is_wp_error($listing_id)) {
            return new \WP_Error('listing_error', $listing_id->get_error_message(), array('status' => 500));
        }
    
        // Save meta fields
        foreach ($params as $key => $value) {
            if (strpos($key, '_rtcl_') === 0 || strpos($key, 'rtcl_') === 0) {
                update_post_meta($listing_id, $key, sanitize_text_field($value));
            }
        }
    
        // Store category and type
        if (!empty($params['_category_id'])) {
            wp_set_object_terms($listing_id, (int)$params['_category_id'], 'rtcl_category');
        }
        
        if (!empty($params['_ad_type'])) {
            update_post_meta($listing_id, '_rtcl_ad_type', sanitize_text_field($params['_ad_type']));
        }
    
        return new \WP_REST_Response([
            'success' => true,
            'listing_id' => $listing_id,
            'message' => 'Listing saved successfully'
        ], 200);
    }

    public function enqueue_api_settings() {
        wp_enqueue_script('wp-api');
        wp_localize_script('jquery', 'vofSettings', array(
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest')
        ));
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
        // Add nonce for security
        $ajax_nonce = wp_create_nonce('vof_temp_listing_nonce');
        
        ?>
        <button type="button" class="btn vof-guest-submit-btn" data-flow="guest" onclick="handleTempListing()">
            <?php esc_html_e('Continue to Create Account', 'vendor-onboarding-flow'); ?>
        </button>
        <div class="vof-submit-help">
            <?php esc_html_e('You\'ll create an account and select a subscription plan next', 'vendor-onboarding-flow'); ?>
        </div>
        <script type="text/javascript">
            function handleTempListing() {
                const button = document.querySelector('.vof-guest-submit-btn');
                const form = button.closest('form');
            
                if (!form) {
                    console.error('Form not found');
                    return;
                }
            
                const formData = new FormData(form);
                
                button.innerHTML = 'Processing...';
                button.disabled = true;
            
                fetch(`${vofSettings.root}vof/v1/save-temp-listing`, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-WP-Nonce': vofSettings.nonce
                    }
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Response:', data);
                    if (data.success) {
                        window.location.href = 'https://thenoise.io';
                    } else {
                        throw new Error(data.message || 'Submission failed');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('There was an error saving your listing. Please try again.');
                })
                .finally(() => {
                    button.innerHTML = '<?php esc_html_e('Continue to Create Account', 'vendor-onboarding-flow'); ?>';
                    button.disabled = false;
                });
            }


            // function handleTempListing() {
               
            //     // Find the closest form element to the button
            //     const button = document.querySelector('.vof-guest-submit-btn');
            //     const form = button.closest('form');

            //     console.log('Form element:', form); // Debug log

            //     if (!form) {
            //         console.error('Form not found');
            //         return;
            //     }

            //     // Collect all form data
            //     const formData = new FormData();
            //     const formElements = form.querySelectorAll('input, select, textarea');

            //     formElements.forEach(element => {
            //         if(element.name) {
            //             if(element.type === 'file') {
            //             // handle file inputs
            //             const files = element.files;
            //             if(files.length > 0) {
            //                 formData.append(element.name, files[0]);
            //             }
            //            } else {
            //             formData.append(element.name, element.value);
            //            }
            //         }
            //     });
                
            //     // Add the nonce to the formData
            //     formData.append('security', '<?php echo $ajax_nonce; ?>');

            //     // Show loading state
            //     button.innerHTML = 'Processing...';
            //     button.disabled = true;

            //     fetch('/wp-json/vof/v1/save-temp-listing', {
            //         method: 'POST',
            //         body: formData,
            //         credentials: 'same-origin',
            //         headers: {
            //            'X-WP-Nonce': vofSettings.nonce // use our custom settings
            //         }
            //     })
            //     .then(response => {
            //         if (!response.ok) {
            //             throw new Error('Network response was not ok');
            //         }
            //         return response.json();
            //     })
            //     .then(data => {
            //         if (data.success) {
            //             window.location.href = 'https://thenoise.io';
            //         } else {
            //             throw new Error(data.message || 'Submission failed');
            //         }
            //     })
            //     .catch(error => {
            //         console.error('Error:', error);
            //         alert('There was an error saving your listing. Please try again.');
            //     })
            //     .finally(() => {
            //         // Reset button state
            //         button.innerHTML = '<?php esc_html_e('Continue to Create Account', 'vendor-onboarding-flow'); ?>';
            //         button.disabled = false;
            //     });                
            // } 
        </script>
        <?php
    }

    private function render_subscription_required_button() {
        ?>
        <button type="button" class="btn vof-subscription-submit-btn" data-flow="subscription" onclick="handleTempListing()">
            <?php esc_html_e('Continue to Select Plan', 'vendor-onboarding-flow'); ?>
        </button>
        <div class="vof-submit-help">
            <?php esc_html_e('You\'ll select a subscription plan next', 'vendor-onboarding-flow'); ?>
        </div>
        <?php
    }

    private function is_post_ad_page() {
        return strpos($_SERVER['REQUEST_URI'], '/post-an-ad/') !== false;
    }

    private function bypass_category_check() {

    }
}
