<?php

namespace VOF;

class VOF_Listing {
    public function __construct() {
        //remove_action('rtcl_listing_form_submit_button', 
        remove_action('rtcl_listing_form_end', 
            ['Rtcl\Controllers\Hooks\TemplateHooks', 'listing_form_submit_button'], 50);
            add_action('rtcl_listing_form_end', [$this, 'custom_submit_button']);
            // add_filter('rtcl_is_valid_to_post_at_category', function($is_valid, $cat_id) {
            //     return true; // Always allow posting
            // }, 1, 2);
             add_filter('rtcl_category_validation', function($is_valid, $cat_id) {
                 return true; // Always allow posting
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

        if (is_user_logged_in() && VOF_Subscription::has_active_subscription()) {
            ?>
            <button type="submit" class="btn btn-primary rtcl-submit-btn">
                <?php echo esc_html($post_id > 0 ? __('Update', 'classified-listing') : __('Submit', 'classified-listing')); ?>
            </button>
            <?php
            return;
        }
    }

    private function render_guest_submit_button() {
        ?>
        <button type="submit" class="btn vof-guest-submit-btn" data-flow="guest">
            <?php esc_html_e('Continue to Create Account', 'vendor-onboarding-flow'); ?>
        </button>
        <div class="vof-submit-help">
            <?php esc_html_e('You\'ll create an account and select a subscription plan next', 'vendor-onboarding-flow'); ?>
        </div>
        <?php
    }

    private function render_subscription_required_button() {
        ?>
        <button type="submit" class="btn vof-subscription-submit-btn" data-flow="subscription">
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
