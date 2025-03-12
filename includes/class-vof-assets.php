<?php
namespace VOF;

class VOF_Assets {
    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts'], 20); // Higher priority to ensure RTCL loads first
        // Add admin styles
        add_action('admin_head', [$this, 'vof_add_admin_styles']);
    }

    public function enqueue_scripts() {

        if(!$this->vof_is_post_ad_page()) {
            return;
        }

        // Ensure RTCL core and gallery scripts are loaded first
        wp_enqueue_script('rtcl-common');
        wp_enqueue_script('public-add-post');
        wp_enqueue_script('rtcl-gallery');

        // Add form validation script
        wp_enqueue_script(
            'vof-form-validation',
            plugins_url('../assets/js/vof-form-validation.js', __FILE__),
            ['rtcl-gallery'],
            VOF_VERSION,
            true
        );

        // Add listing submission script
        wp_enqueue_script(
            'vof-listing-submit',
            plugins_url('../assets/js/vof-listing-submit.js', __FILE__),
            ['vof-form-validation'],
            VOF_VERSION,
            true
        );

        // HIDING THIS FOR NOW
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
        // HIDING THIS FOR NOW

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

        // Add Orchestrator script
        // Ensure dependencies are ordered correctly:
        wp_enqueue_script(
            'vof-orchestrator',
            plugins_url('../assets/js/vof-orchestrator.js', __FILE__),
            ['vof-listing-submit', 'vof-pricing-modal-script'], // vof-listing-submit first
            VOF_VERSION,
            true
        );

        // Localize configuration for vof-Orchestrator
        wp_localize_script('vof-orchestrator', 'vofConfig', [
            'enableValidation' => true, // can be set conditionally
            'stubMode' => false         // control via wp-config.php later
        ]);
    }

    private function vof_is_post_ad_page() {
        // return only if true.
        return strpos($_SERVER['REQUEST_URI'], '/post-an-ad/') !== false;
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
            array('vof-listing-submit'), 
            // array('jquery'), 
            VOF_VERSION,
            true
        );

        // Get pricing modal configuration from database or default
        $config = get_option('vof_pricing_modal_config');
        if (empty($config) && class_exists('\VOF\Utils\PricingModal\VOF_Pricing_Modal_Settings')) {
            $settings = new \VOF\Utils\PricingModal\VOF_Pricing_Modal_Settings();
            $config = $settings->vof_get_default_pricing_config();
        }
        
        // Format configuration for JavaScript
        $js_data = array(
            'is_multi_pricing_on' => !empty($config['isMultiPricingOn']),
            'iso_currency_code' => !empty($config['isoCurrencyCode']) ? strtoupper($config['isoCurrencyCode']) : 'USD',
            'pricing_modal_title' => !empty($config['pricingModalTitle']) ? $config['pricingModalTitle'] : 'Select Your Plan',
            'tab_label_monthly' => !empty($config['tabLabelMonthly']) ? $config['tabLabelMonthly'] : 'Monthly Plans',
            'tab_label_yearly' => !empty($config['tabLabelYearly']) ? $config['tabLabelYearly'] : 'Yearly Plans',
            'monthly_tiers' => array(),
            'yearly_tiers' => array()
        );
        
        // Format monthly tiers
        if (!empty($config['tiersMonthly'])) {
            foreach ($config['tiersMonthly'] as $tier) {
                if (empty($tier['name'])) {
                    continue;
                }
                
                $js_data['monthly_tiers'][] = array(
                    'name' => $tier['name'],
                    'description' => $tier['description'],
                    'price' => floatval($tier['price']),
                    'features' => array_filter($tier['features']), // Remove empty features
                    'isRecommended' => !empty($tier['isRecommended']),
                    'isGrayOut' => !empty($tier['isGrayOut']),
                    'billingCycleLabel' => isset($tier['billingCycleLabel']) ? $tier['billingCycleLabel'] : '',
                    'stripePriceIdTest' => isset($tier['stripePriceIdTest']) ? $tier['stripePriceIdTest'] : '',
                    'stripePriceIdLive' => isset($tier['stripePriceIdLive']) ? $tier['stripePriceIdLive'] : '',
                    'stripeLookupKeyTest' => isset($tier['stripeLookupKeyTest']) ? $tier['stripeLookupKeyTest'] : '',
                    'stripeLookupKeyLive' => isset($tier['stripeLookupKeyLive']) ? $tier['stripeLookupKeyLive'] : ''
                );
            }
        }
        
        // Format yearly tiers if multi-pricing is enabled
        if (!empty($config['isMultiPricingOn']) && !empty($config['tiersYearly'])) {
            foreach ($config['tiersYearly'] as $tier) {
                if (empty($tier['name'])) {
                    continue;
                }
                
                $js_data['yearly_tiers'][] = array(
                    'name' => $tier['name'],
                    'description' => $tier['description'],
                    'price' => floatval($tier['price']),
                    'features' => array_filter($tier['features']), // Remove empty features
                    'isRecommended' => !empty($tier['isRecommended']),
                    'isGrayOut' => !empty($tier['isGrayOut']),
                    'billingCycleLabel' => isset($tier['billingCycleLabel']) ? $tier['billingCycleLabel'] : '',
                    'interval' => 'year',
                    'stripePriceIdTest' => isset($tier['stripePriceIdTest']) ? $tier['stripePriceIdTest'] : '',
                    'stripePriceIdLive' => isset($tier['stripePriceIdLive']) ? $tier['stripePriceIdLive'] : '',
                    'stripeLookupKeyTest' => isset($tier['stripeLookupKeyTest']) ? $tier['stripeLookupKeyTest'] : '',
                    'stripeLookupKeyLive' => isset($tier['stripeLookupKeyLive']) ? $tier['stripeLookupKeyLive'] : ''                    
                );
            }
        }

        // Add pricing modal configuration to JavaScript
        wp_localize_script('vof-pricing-modal-script', 'vofPricingModalConfig', $js_data);
        
        // Add general localization for modal JavaScript
        wp_localize_script('vof-pricing-modal-script', 'vofPricingModal', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('vof-pricing-modal'),
            'strings' => array(
                'errorMessage' => __('Error loading pricing data', 'vendor-onboarding-flow'),
                'successMessage' => __('Pricing data loaded successfully', 'vendor-onboarding-flow')
            )
        ));

        error_log('VOF Debug: Modal assets enqueued with pricing config');
    }

    /**
     * Add admin styles for VOF menu and submenu
     */
    public function vof_add_admin_styles() {
        echo '<style>

            /* VOF menu folded */
            #adminmenu .wp-has-current-submenu.toplevel_page_vof_admin .wp-submenu .wp-submenu-head, 
            #adminmenu li.toplevel_page_vof_admin.current a.menu-top, 
            #adminmenu li.toplevel_page_vof_admin.wp-has-current-submenu a.wp-has-current-submenu {
                background-color: #000000;
                color: #fff;
            }
            
            /* Main VOF menu item */
            #adminmenu li.menu-top.toplevel_page_vof_admin {
                background-color: #000000;
            }

            /* VOF menu icon and text */
            #adminmenu li.menu-top.toplevel_page_vof_admin .wp-menu-name,
            #adminmenu li.menu-top.toplevel_page_vof_admin .wp-menu-image:before {
                color: white;
            }
            
            #adminmenu li.menu-top.toplevel_page_vof_admin .wp-menu-name  {
                background-color: #000000 !important;
            }

            /* VOF submenu container */
            #adminmenu li.menu-top.toplevel_page_vof_admin .wp-submenu {
                background-color: #000000;
            }

            /* VOF submenu items */
            #adminmenu li.menu-top.toplevel_page_vof_admin .wp-submenu li a {
                color: #f0f0f0;
            }

            /* VOF submenu items on hover */
            #adminmenu li.menu-top.toplevel_page_vof_admin .wp-submenu li a:hover,
            #adminmenu li.menu-top.toplevel_page_vof_admin .wp-submenu li.current a {
                color: #C2426D !important;
            }

            /* VOF menu highlight bar (replaces blue stripe) */
            #adminmenu li.menu-top.toplevel_page_vof_admin.wp-has-current-submenu ,
            #adminmenu li.menu-top.toplevel_page_vof_admin.current .wp-menu-image {
                background-color: #e0e0e0;
            }

            /* VOF menu highlight for current submenu */
            #adminmenu li.menu-top.toplevel_page_vof_admin .wp-submenu li.current a {
                font-weight: bold;
            }
        </style>';
    }
}