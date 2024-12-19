<?php
namespace VOF;

if (!defined('ABSPATH')) exit;

class VOF_Listing {
    public function __construct() {
        // Hook into RTCL's initialization directly
        add_action('rtcl_loaded', [$this, 'init_hooks'], 30);
        add_action('init', [$this, 'log_rtcl_status'], 1);
    }

    public function log_rtcl_status() {
        error_log('[VOF Debug] RTCL function exists: ' . (function_exists('rtcl') ? 'yes' : 'no'));
        error_log('[VOF Debug] Current hook: ' . current_filter());
    }

    public function init_hooks() {
        error_log('[VOF Debug] Init hooks called from: ' . current_filter());
        
        // Remove default submit button action
        global $wp_filter;
        error_log('[VOF Debug] Current hooks: ' . print_r(array_keys($wp_filter), true));
        
        remove_action('rtcl_listing_form_submit_button', 
            ['Rtcl\Controllers\Hooks\TemplateHooks', 'listing_form_submit_button'], 1);
        
        // Add our custom hooks
        add_action('rtcl_listing_form_submit_button', [$this, 'custom_submit_button'], 10);
        add_action('rtcl_before_add_edit_listing_form', [$this, 'maybe_redirect_flow'], 5);
        add_filter('rtcl_listing_form_validation', [$this, 'validate_form'], 10, 2);
        
        error_log('[VOF Debug] Hooks initialized');
    }

    public function maybe_redirect_flow() {
        error_log('VOF: maybe_redirect_flow called');

        if (!$this->is_post_ad_page()) {
            return;
        }

        // CASE 1: Guest user - Start VOF flow
        if (!is_user_logged_in()) {
            $this->init_guest_flow();
            return;
        }

        // CASE 2: No active subscription - Start VOF flow
        if (!VOF_Subscription::has_active_subscription()) {
            $this->init_subscription_flow();
            return;
        }

        // CASE 3: Active subscription - Use original flow
        return;
    }

    private function init_guest_flow() {
        // Store current page as redirect target after registration
        update_option('vof_redirect_after_registration', $_SERVER['REQUEST_URI']);
        
        // Add notice about temporary listing creation
        add_action('rtcl_before_add_edit_listing_form', function() {
            ?>
            <div class="vof-notice">
                <?php esc_html_e('Your listing will be saved temporarily while you create an account and select a subscription plan.', 'vendor-onboarding-flow'); ?>
            </div>
            <?php
        });
    }

    private function init_subscription_flow() {
        // Store current listing data for after subscription
        update_option('vof_redirect_after_subscription', $_SERVER['REQUEST_URI']);
        
        // Add notice about subscription requirement
        add_action('rtcl_before_add_edit_listing_form', function() {
            ?>
            <div class="vof-notice">
                <?php esc_html_e('Please select a subscription plan to publish your listing.', 'vendor-onboarding-flow'); ?>
            </div>
            <?php
        });
    }

    public function custom_submit_button($post_id) {
        // CASE 1: Guest user
        if (!is_user_logged_in() && $this->is_post_ad_page()) {
            $this->render_guest_submit_button();
            return;
        }

        // CASE 2: No subscription
        if (is_user_logged_in() && !VOF_Subscription::has_active_subscription() && $this->is_post_ad_page()) {
            $this->render_subscription_required_button();
            return;
        }

        // CASE 3: Regular flow
        ?>
        <button type="submit" class="btn btn-primary rtcl-submit-btn">
            <?php echo $post_id > 0 
                ? esc_html__('Update', 'classified-listing')
                : esc_html__('Submit', 'classified-listing');
            ?>
        </button>
        <?php
    }

    public function validate_form($errors, $data) {
        // Skip additional validation for users with active subscription
        if (is_user_logged_in() && VOF_Subscription::has_active_subscription()) {
            return $errors;
        }

        // Basic validation for temporary listings
        if (empty($data['title'])) {
            $errors[] = __('Please enter a listing title', 'vendor-onboarding-flow');
        }
        if (empty($data['category'])) {
            $errors[] = __('Please select a category', 'vendor-onboarding-flow');
        }

        return $errors;
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
        // Multiple ways to detect the page
        $is_post_page = (
            strpos($_SERVER['REQUEST_URI'], '/post-an-ad/') !== false ||
            get_query_var('rtcl_action') === 'add-listing' ||
            is_page('post-an-ad')
        );

        error_log('VOF: is_post_ad_page: ' . ($is_post_page ? 'true' : 'false'));
        return $is_post_page;
    }
}