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
            'vof-form-validation',
            plugins_url('../assets/js/vof-form-validation.js', __FILE__),
            ['jquery'],
            VOF_VERSION,
            true
        );

        wp_enqueue_script(
            'vof-listing-submit',
            plugins_url('../assets/js/vof-listing-submit.js', __FILE__),
            ['jquery'],
            VOF_VERSION,
            true
        );
       
        // Add type="module" to scripts
        add_filter('script_loader_tag', function($tag, $handle) {
            if ($handle === 'vof-form-validation' || $handle === 'vof-listing-submit') {
                return str_replace('<script', '<script type="module"', $tag);
            }
            return $tag;
        }, 10, 2);

        wp_localize_script('vof-listing-submit', 'vofSettings', array(
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'buttonText' => esc_html__('Continue to Create Account', 'vendor-onboarding-flow'),
            'redirectUrl' => VOF_Constants::REDIRECT_URL,
            'security' => wp_create_nonce('vof_temp_listing_nonce')
        ));
    }
}