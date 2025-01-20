<?php
namespace VOF;

class VOF_Assets {
    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts'], 20); // Higher priority to ensure RTCL loads first
    }

    public function enqueue_scripts() {
        // Only load on listing submission page
        if (!is_page('post-an-ad')) {
            return;
        }

        // Ensure RTCL core and gallery scripts are loaded first
        wp_enqueue_script('rtcl-common');
        wp_enqueue_script('public-add-post');
        wp_enqueue_script('rtcl-gallery');

        // Add our custom gallery extension
        // wp_enqueue_script(
        //     'vof-gallery-extension',
        //     plugins_url('../assets/js/vof-gallery-extension.js', __FILE__),
        //     ['jquery', 'rtcl-common', 'rtcl-gallery', 'public-add-post'],
        //     VOF_VERSION,
        //     true
        // );

        // Add form validation script
        // wp_enqueue_script(
        //     'vof-form-validation',
        //     plugins_url('../assets/js/vof-form-validation.js', __FILE__),
        //     ['jquery', 'vof-gallery-extension'],
        //     VOF_VERSION,
        //     true
        // );

        // Add listing submission script
        wp_enqueue_script(
            'vof-listing-submit',
            plugins_url('../assets/js/vof-listing-submit.js', __FILE__),
            // ['jquery', 'vof-form-validation'],
            // ['jquery', 'public-add-post'],
            ['jquery'],
            VOF_VERSION,
            true
        );

        // Add type="module" to scripts that need it
        // add_filter('script_loader_tag', function($tag, $handle) {
        //     if (in_array($handle, ['vof-form-validation', 'vof-listing-submit'])) {
        //         return str_replace('<script', '<script type="module"', $tag);
        //     }
        //     return $tag;
        // }, 10, 2);

        // Localize script with settings
        // wp_localize_script('vof-gallery-extension', 'vofGallerySettings', [
        //     'nonce' => wp_create_nonce('rtcl-gallery'),
        //     'messages' => [
        //         'uploadRequired' => __('Please upload at least one image to proceed.', 'vendor-onboarding-flow'),
        //     ]
        // ]);

        // Original vofSettings for form submission
        wp_localize_script('vof-listing-submit', 'vofSettings', [
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'buttonText' => esc_html__('Continue to Create Account', 'vendor-onboarding-flow'),
            'redirectUrl' => VOF_Constants::REDIRECT_URL,
            'security' => wp_create_nonce('vof_temp_listing_nonce')
        ]);

        self::vof_enqueue_pricing_modal_assets();
    }

    public function vof_enqueue_pricing_modal_assets() {
        error_log('VOF Debug: Enqueueing modal assets');

        wp_enqueue_style(
            'vof-pricing-modal-style', 
            plugins_url('../assets/css/vof-pricing-modal-style.css', __FILE__),
            array(),
            VOF_VERSION,
            'all' // add media type
        );

        wp_enqueue_script(
            'vof-pricing-modal-script',
            plugins_url('../assets/js/vof-pricing-modal-script.js', __FILE__), 
            array('jquery'), 
            VOF_VERSION,
            true
        );

        // Add localization for modal JavaScript
        wp_localize_script('vof-pricing-modal-script', 'vofPricingModal', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('vof-pricing-modal'),
            'strings' => array(
                'errorMessage' => __('Error loading pricing data', 'vendor-onboarding-flow'),
                'successMessage' => __('Pricing data loaded successfully', 'vendor-onboarding-flow')
            )
        ));

        error_log('VOF Debug: Modal assets enqueued');
    }
}