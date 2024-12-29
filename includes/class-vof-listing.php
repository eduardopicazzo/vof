<?php

namespace VOF;

use Rtcl\Controllers\FormHandler;

class VOF_Listing {
    public function __construct() {
            // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        // Intercept the AJAX submission
        add_action('wp_ajax_rtcl_post_new_listing', [$this, 'vof_handle_listing_submission'], 9);
        add_action('wp_ajax_nopriv_rtcl_post_new_listing', [$this, 'vof_handle_listing_submission'], 9);

        // // Intercept form submission action
        // add_filter('rtcl_listing_save_data', [$this, 'modify_listing_data'], 10, 2);

        // // Intercept user creation rtcl_before_user_registration_form
        // add_filter('rtcl_before_user_registration', [$this, 'prevent_user_registration'], 10, 1);
        
        // // Modify redirect URL
        // add_filter('rtcl_listing_form_submit_redirect_url', [$this, 'modify_redirect_url'], 10, 2);
        
        // Custom submit button 
        remove_action('rtcl_listing_form_end', 
            ['Rtcl\Controllers\Hooks\TemplateHooks', 'listing_form_submit_button'], 50);
        add_action('rtcl_listing_form_end', [$this, 'custom_submit_button']);
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


    public function vof_handle_listing_submission() {
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
    
    public function modify_redirect_url($url, $listing_id) {
        if ($this->is_vof_submission()) {
            return 'https://thenoise.io'; // Test URL
        }
        return $url;
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
}