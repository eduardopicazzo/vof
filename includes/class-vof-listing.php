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
                 return true; // Always allow posting do cleanup
        }, 10, 2);
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

    private function is_post_ad_page() {
        return strpos($_SERVER['REQUEST_URI'], '/post-an-ad/') !== false;
    }

    private function bypass_category_check() {

    }
}