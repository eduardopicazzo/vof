<?php
namespace VOF;

class VOF_Assets {
    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function enqueue_scripts() {

        // Only load on listing submission page
        if (!is_page('post-an-ad')) {
            return;
        }


        wp_enqueue_script(
            'vof-listing-submit',
           // plugins_url('/assets/js/vof-listing-submit.js', VOF_PLUGIN_FILE),
            plugins_url('../assets/js/vof-listing-submit.js', __FILE__),
            ['jquery'],
            VOF_VERSION,
            true
        );

        wp_localize_script('vof-listing-submit', 'vofSettings', [
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'buttonText' => esc_html__('Continue to Create Account', 'vendor-onboarding-flow'),
            'redirectUrl' => VOF_Constants::REDIRECT_URL,
            'security' => wp_create_nonce('vof_temp_listing_nonce')
        ]);
    }
}