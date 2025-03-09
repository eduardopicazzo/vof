<?php

namespace VOF;

class VOF_Pricing_Modal {

    public function __construct() {
        // only add footer hook if we're on the post-an-ad page
        add_action('template_redirect', [$this, 'vof_maybe_add_modal_footer']);
    }

    public function vof_maybe_add_modal_footer() {
        if ($this->vof_should_render_modal()) {
            add_action('wp_footer', [$this, 'vof_render_modal']);
        }
    }

    private function vof_should_render_modal() {
        // Check if we're on the post-ad page
        return strpos($_SERVER['REQUEST_URI'], '/post-an-ad/') !== false;
    }
        
    /**
     * Get pricing modal configuration from admin settings
     * 
     * @return array Configuration for the pricing modal
     */
    public function vof_get_pricing_modal_config() {
        // Get settings from database
        $config = get_option('vof_pricing_modal_config', []);
        
        // If no config exists, use defaults from settings class
        if (empty($config) && class_exists('\VOF\Utils\PricingModal\VOF_Pricing_Modal_Settings')) {
            $settings = new \VOF\Utils\PricingModal\VOF_Pricing_Modal_Settings();
            $config = $settings->vof_get_default_pricing_config();
        }
        
        return $config;
    }
    
    /**
     * Format pricing modal data for JavaScript
     * 
     * @param array $config Raw configuration from database
     * @return array Formatted data ready for JavaScript
     */
    public function vof_format_pricing_data_for_js($config) {
        $formatted_data = [
            'is_multi_pricing_on' => !empty($config['isMultiPricingOn']),
            'iso_currency_code' => !empty($config['isoCurrencyCode']) ? strtoupper($config['isoCurrencyCode']) : 'USD',
            'monthly_tiers' => [],
            'yearly_tiers' => []
        ];
        
        // Format monthly tiers
        if (!empty($config['tiersMonthly'])) {
            foreach ($config['tiersMonthly'] as $tier) {
                // Skip empty tiers
                if (empty($tier['name'])) {
                    continue;
                }
                
                $formatted_data['monthly_tiers'][] = [
                    'name' => $tier['name'],
                    'description' => $tier['description'],
                    'price' => floatval($tier['price']),
                    'features' => array_filter($tier['features']), // Remove empty features
                    'isRecommended' => !empty($tier['isRecommended']),
                    'isGrayOut' => !empty($tier['isGrayOut'])
                ];
            }
        }
        
        // Format yearly tiers if multi-pricing is enabled
        if (!empty($config['isMultiPricingOn']) && !empty($config['tiersYearly'])) {
            foreach ($config['tiersYearly'] as $tier) {
                // Skip empty tiers
                if (empty($tier['name'])) {
                    continue;
                }
                
                $formatted_data['yearly_tiers'][] = [
                    'name' => $tier['name'],
                    'description' => $tier['description'],
                    'price' => floatval($tier['price']),
                    'features' => array_filter($tier['features']), // Remove empty features
                    'isRecommended' => !empty($tier['isRecommended']),
                    'isGrayOut' => !empty($tier['isGrayOut']),
                    'interval' => 'year'
                ];
            }
        }
        
        return $formatted_data;
    }

    public function vof_render_modal() {
        // Get configuration from admin settings
        $config = $this->vof_get_pricing_modal_config();
        $js_data = $this->vof_format_pricing_data_for_js($config);
        
        // Add settings to page as JavaScript data
        wp_localize_script('vof-pricing-modal-script', 'vofPricingModalConfig', $js_data);
        
        ?>
        <div id="vofPricingModal" class="vof-pm-wrapper">
            <!-- html here -->
            <div id="vof-pm-pricingModal" class="vof-pm-modal">
                <div class="vof-pm-modal-content">
                    <div class="vof-pm-modal-header">
                        <h2 class="vof-pm-modal-title">Upgrade Plan</h2>
                        <button id="vof-pm-closeModalBtn" class="vof-pm-close-btn">×</button>
                    </div>
                    <div id="vof-pm-tabsContainer" class="vof-pm-tabs">
                        <button class="vof-pm-tab-btn vof-pm-active" data-tab="monthly">Mensualmente</button>
                        <button class="vof-pm-tab-btn" data-tab="yearly">Anualmente</button>
                    </div>
                    <div id="vof-pm-monthlyContent" class="vof-pm-tab-content vof-pm-active">
                        <div class="vof-pm-tier-container">
                            <!-- Monthly Tier content will be dynamically inserted here -->
                        </div>
                    </div>
                    <div id="vof-pm-yearlyContent" class="vof-pm-tab-content">
                        <!-- <div class="vof-pm-yearly-message">Precios anuales próximamente disponibles</div> -->
                         <div class="vof-pm-tier-container yearly-container">
                            <!-- Yearly Tier content will be dynamically inserted here -->
                        </div>
                    </div>
                    <div class="vof-pm-modal-footer">
                        <button id="vof-pm-cancelBtn" class="vof-pm-btn-footer vof-pm-btn-ghost">Cancelar</button>
                        <!-- <button id="vof-pm-contactSalesBtn" class="vof-pm-btn-footer vof-pm-btn-contact">Contactar Ventas</button> -->
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}