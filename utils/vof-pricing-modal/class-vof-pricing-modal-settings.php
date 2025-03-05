<?php
/**
 * Pricing Modal Settings for Vendor Onboarding Flow
 *
 * @package VOF
 * @subpackage Utils\PricingModal
 */

namespace VOF\Utils\PricingModal;

/**
 * Class VOF_Pricing_Modal_Settings
 * 
 * Handles admin settings for Pricing Modal configuration.
 */
class VOF_Pricing_Modal_Settings {
    /**
     * Option group name
     *
     * @var string
     */
    private $option_group = 'vof_pricing_modal_options';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add settings page
        add_action('admin_menu', [$this, 'vof_add_settings_page'], 100);
        
        // Register settings
        add_action('admin_init', [$this, 'vof_register_settings']);
        
        // Add AJAX handlers
        add_action('wp_ajax_vof_fetch_stripe_products', [$this, 'vof_ajax_fetch_stripe_products']);
        add_action('wp_ajax_vof_save_pricing_modal_settings', [$this, 'vof_ajax_save_pricing_modal_settings']);
    }

    /**
     * Add settings page to admin menu
     */
    public function vof_add_settings_page() {
        add_submenu_page(
            'vof_debug', // Parent slug
            'Pricing Modal Settings',
            'Pricing Modal',
            'manage_options',
            'vof_pricing_modal_settings',
            [$this, 'vof_render_settings_page']
        );
    }
    
    /**
     * Register plugin settings
     */
    public function vof_register_settings() {
        register_setting(
            $this->option_group,
            'vof_pricing_modal_config',
            [
                'sanitize_callback' => [$this, 'vof_sanitize_pricing_modal_config'],
                'default' => $this->vof_get_default_pricing_config()
            ]
        );
    }
    
    /**
     * Get default pricing configuration
     * 
     * @return array Default pricing configuration
     */
    public function vof_get_default_pricing_config() {
        return [
            'isMultiPricingOn' => false,
            'numberOfTiersMonthly' => 3,
            'numberOfTiersYearly' => 3,
            'tiersMonthly' => [
                [
                    'name' => 'biz',
                    'description' => 'Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza.',
                    'price' => 349,
                    'features' => [
                        'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                        '8 listados/mes',
                        'Publica en la mayoría de categorías excepto autos e inmuebles',
                        '2 destacadores Top/mes',
                        '3 destacadores BumpUp/mes', 
                        '2 destacadores Destacados/mes'
                    ],
                    'isRecommended' => true,
                    'isGrayOut' => false
                ],
                [
                    'name' => 'noise',
                    'description' => 'Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local.',
                    'price' => 549,
                    'features' => [
                        'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                        '16 Listados/mes',
                        'Publica en todas las categorías',
                        '5 destacadores Top/mes',
                        '3 destacadores BumpUp/mes',
                        '2 destacadores Destacados/mes'
                    ],
                    'isRecommended' => false,
                    'isGrayOut' => false
                ],
                [
                    'name' => 'noise+',
                    'description' => 'Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes.',
                    'price' => 1567,
                    'features' => [
                        'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                        '30 listados/mes',
                        'Publica en todas las categorías',
                        '10 destacadores Top/mes',
                        '6 destacadores BumpUp/mes',
                        '6 destacadores Destacados/mes'
                    ],
                    'isRecommended' => false,
                    'isGrayOut' => false
                ]
            ],
            'tiersYearly' => [
                [
                    'name' => 'biz',
                    'description' => 'Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza.',
                    'price' => 4188,
                    'features' => [
                        'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                        '8 listados/mes',
                        'Publica en la mayoría de categorías excepto autos e inmuebles',
                        '2 destacadores Top/mes',
                        '3 destacadores BumpUp/mes',
                        '2 destacadores Destacados/mes'
                    ],
                    'isRecommended' => true,
                    'isGrayOut' => false
                ],
                [
                    'name' => 'noise',
                    'description' => 'Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local.',
                    'price' => 6588,
                    'features' => [
                        'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                        '16 Listados/mes',
                        'Publica en todas las categorías',
                        '5 destacadores Top/mes',
                        '3 destacadores BumpUp/mes',
                        '2 destacadores Destacados/mes'
                    ],
                    'isRecommended' => false,
                    'isGrayOut' => false
                ],
                [
                    'name' => 'noise+',
                    'description' => 'Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes.',
                    'price' => 18804,
                    'features' => [
                        'Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios',
                        '30 listados/mes',
                        'Publica en todas las categorías',
                        '10 destacadores Top/mes',
                        '6 destacadores BumpUp/mes',
                        '6 destacadores Destacados/mes'
                    ],
                    'isRecommended' => false,
                    'isGrayOut' => false
                ]
            ]
        ];
    }
    
    /**
     * Sanitize pricing modal configuration
     * 
     * @param array $input The input array to sanitize
     * @return array Sanitized input
     */
    public function vof_sanitize_pricing_modal_config($input) {
        $sanitized = [];
        
        // General settings
        $sanitized['isMultiPricingOn'] = isset($input['isMultiPricingOn']) ? (bool) $input['isMultiPricingOn'] : false;
        $sanitized['numberOfTiersMonthly'] = isset($input['numberOfTiersMonthly']) ? intval($input['numberOfTiersMonthly']) : 3;
        $sanitized['numberOfTiersYearly'] = isset($input['numberOfTiersYearly']) ? intval($input['numberOfTiersYearly']) : 3;
        
        // Sanitize monthly tiers
        $sanitized['tiersMonthly'] = [];
        if (isset($input['tiersMonthly']) && is_array($input['tiersMonthly'])) {
            foreach ($input['tiersMonthly'] as $index => $tier) {
                if ($index >= $sanitized['numberOfTiersMonthly']) {
                    continue;
                }
                
                $sanitized_tier = [];
                $sanitized_tier['name'] = sanitize_text_field($tier['name']);
                $sanitized_tier['description'] = sanitize_textarea_field($tier['description']);
                $sanitized_tier['price'] = floatval($tier['price']);
                $sanitized_tier['isRecommended'] = isset($tier['isRecommended']) ? (bool) $tier['isRecommended'] : false;
                $sanitized_tier['isGrayOut'] = isset($tier['isGrayOut']) ? (bool) $tier['isGrayOut'] : false;
                
                // Sanitize features
                $sanitized_tier['features'] = [];
                if (isset($tier['features']) && is_array($tier['features'])) {
                    foreach ($tier['features'] as $feature) {
                        if (!empty($feature)) {
                            $sanitized_tier['features'][] = sanitize_text_field($feature);
                        }
                    }
                }
                
                $sanitized['tiersMonthly'][] = $sanitized_tier;
            }
        }
        
        // Sanitize yearly tiers
        $sanitized['tiersYearly'] = [];
        if (isset($input['tiersYearly']) && is_array($input['tiersYearly'])) {
            foreach ($input['tiersYearly'] as $index => $tier) {
                if ($index >= $sanitized['numberOfTiersYearly']) {
                    continue;
                }
                
                $sanitized_tier = [];
                $sanitized_tier['name'] = sanitize_text_field($tier['name']);
                $sanitized_tier['description'] = sanitize_textarea_field($tier['description']);
                $sanitized_tier['price'] = floatval($tier['price']);
                $sanitized_tier['isRecommended'] = isset($tier['isRecommended']) ? (bool) $tier['isRecommended'] : false;
                $sanitized_tier['isGrayOut'] = isset($tier['isGrayOut']) ? (bool) $tier['isGrayOut'] : false;
                
                // Sanitize features
                $sanitized_tier['features'] = [];
                if (isset($tier['features']) && is_array($tier['features'])) {
                    foreach ($tier['features'] as $feature) {
                        if (!empty($feature)) {
                            $sanitized_tier['features'][] = sanitize_text_field($feature);
                        }
                    }
                }
                
                $sanitized['tiersYearly'][] = $sanitized_tier;
            }
        }
        
        return $sanitized;
    }

    /**
     * Render the settings page
     */
    public function vof_render_settings_page() {
        $options = get_option('vof_pricing_modal_config', $this->vof_get_default_pricing_config());
        ?>
        <div class="wrap vof-pricing-modal-settings">
            <h1><?php echo esc_html__('Pricing Modal Settings', 'vof'); ?></h1>

            <form method="post" action="options.php" id="vof-pricing-modal-form">
                <?php settings_fields($this->option_group); ?>

                <div class="vof-settings-section">
                    <h2><?php echo esc_html__('General Settings', 'vof'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="vof_multi_pricing"><?php echo esc_html__('Enable Multi-Pricing', 'vof'); ?></label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="vof_multi_pricing" 
                                       name="vof_pricing_modal_config[isMultiPricingOn]" 
                                       value="1" 
                                       <?php checked(true, $options['isMultiPricingOn']); ?> />
                                <p class="description"><?php echo esc_html__('Enable to offer both monthly and yearly pricing options.', 'vof'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="vof_monthly_tiers"><?php echo esc_html__('Number of Monthly Tiers', 'vof'); ?></label>
                            </th>
                            <td>
                                <select id="vof_monthly_tiers" name="vof_pricing_modal_config[numberOfTiersMonthly]" class="vof-tier-count-selector" data-tier-type="monthly">
                                    <option value="1" <?php selected(1, $options['numberOfTiersMonthly']); ?>>1</option>
                                    <option value="2" <?php selected(2, $options['numberOfTiersMonthly']); ?>>2</option>
                                    <option value="3" <?php selected(3, $options['numberOfTiersMonthly']); ?>>3</option>
                                </select>
                            </td>
                        </tr>

                        <tr class="vof-yearly-tiers-option" <?php echo !$options['isMultiPricingOn'] ? 'style="display:none;"' : ''; ?>>
                            <th scope="row">
                                <label for="vof_yearly_tiers"><?php echo esc_html__('Number of Yearly Tiers', 'vof'); ?></label>
                            </th>
                            <td>
                                <select id="vof_yearly_tiers" name="vof_pricing_modal_config[numberOfTiersYearly]" class="vof-tier-count-selector" data-tier-type="yearly">
                                    <option value="1" <?php selected(1, $options['numberOfTiersYearly']); ?>>1</option>
                                    <option value="2" <?php selected(2, $options['numberOfTiersYearly']); ?>>2</option>
                                    <option value="3" <?php selected(3, $options['numberOfTiersYearly']); ?>>3</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>
                <hr style="margin-top: 20px;margin-bottom: 20px">

                <!-- Tabs for Monthly/Yearly Tiers -->
                <div class="vof-pricing-tabs">
                    <h2 class="nav-tab-wrapper">
                        <a href="#monthly-tiers" class="nav-tab nav-tab-active"><?php echo esc_html__('Monthly Pricing Tiers', 'vof'); ?></a>
                        <a href="#yearly-tiers" class="nav-tab" <?php echo !$options['isMultiPricingOn'] ? 'style="display:none;"' : ''; ?>><?php echo esc_html__('Yearly Pricing Tiers', 'vof'); ?></a>
                    </h2>

                    <!-- Monthly Tiers Tab -->
                    <div id="monthly-tiers" class="vof-tab-content" style="display: block;">
                        <div style="margin-top: 20px;margin-bottom: 20px" class="vof-tiers-container" id="vof-monthly-tiers-container">
                            <?php for ($i = 0; $i < 3; $i++) : 
                                $tier = isset($options['tiersMonthly'][$i]) ? $options['tiersMonthly'][$i] : [
                                    'name' => '',
                                    'description' => '',
                                    'price' => 0,
                                    'features' => [],
                                    'isRecommended' => false,
                                    'isGrayOut' => false
                                ];

                                $display = $i < $options['numberOfTiersMonthly'] ? 'block' : 'none';
                            ?>
                                <div class="vof-tier-card" id="vof-monthly-tier-<?php echo $i; ?>" style="display: <?php echo $display; ?>">
                                    <h3><?php printf(esc_html__('Tier %d Configuration', 'vof'), $i + 1); ?></h3>

                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="vof_monthly_tier_<?php echo $i; ?>_name"><?php echo esc_html__('Plan Name', 'vof'); ?></label>
                                            </th>
                                            <td>
                                                <input type="text" 
                                                       id="vof_monthly_tier_<?php echo $i; ?>_name" 
                                                       name="vof_pricing_modal_config[tiersMonthly][<?php echo $i; ?>][name]" 
                                                       value="<?php echo esc_attr($tier['name']); ?>" 
                                                       class="regular-text" />
                                            </td>
                                        </tr>

                                        <tr>
                                            <th scope="row">
                                                <label for="vof_monthly_tier_<?php echo $i; ?>_description"><?php echo esc_html__('Description', 'vof'); ?></label>
                                            </th>
                                            <td>
                                                <textarea id="vof_monthly_tier_<?php echo $i; ?>_description" 
                                                          name="vof_pricing_modal_config[tiersMonthly][<?php echo $i; ?>][description]" 
                                                          class="large-text" 
                                                          rows="3"><?php echo esc_textarea($tier['description']); ?></textarea>
                                            </td>
                                        </tr>

                                        <tr>
                                            <th scope="row">
                                                <label for="vof_monthly_tier_<?php echo $i; ?>_price"><?php echo esc_html__('Price', 'vof'); ?></label>
                                            </th>
                                            <td>
                                                <input type="number" 
                                                       id="vof_monthly_tier_<?php echo $i; ?>_price" 
                                                       name="vof_pricing_modal_config[tiersMonthly][<?php echo $i; ?>][price]" 
                                                       value="<?php echo esc_attr($tier['price']); ?>" 
                                                       step="0.01" 
                                                       min="0" />
                                            </td>
                                        </tr>

                                        <tr>
                                            <th scope="row">
                                                <?php echo esc_html__('Features (Up to 6)', 'vof'); ?>
                                            </th>
                                            <td class="vof-features-container">
                                                <?php for ($j = 0; $j < 6; $j++) : 
                                                    $feature = isset($tier['features'][$j]) ? $tier['features'][$j] : '';
                                                ?>
                                                    <div class="vof-feature-input">
                                                        <input type="text" 
                                                               name="vof_pricing_modal_config[tiersMonthly][<?php echo $i; ?>][features][<?php echo $j; ?>]" 
                                                               value="<?php echo esc_attr($feature); ?>" 
                                                               class="regular-text"
                                                               placeholder="<?php printf(esc_attr__('Feature %d', 'vof'), $j + 1); ?>" />
                                                    </div>
                                                <?php endfor; ?>
                                            </td>
                                        </tr>
                                                
                                        <tr>
                                            <th scope="row">
                                                <label for="vof_monthly_tier_<?php echo $i; ?>_recommended"><?php echo esc_html__('Recommended', 'vof'); ?></label>
                                            </th>
                                            <td>
                                                <input type="checkbox" 
                                                       id="vof_monthly_tier_<?php echo $i; ?>_recommended" 
                                                       name="vof_pricing_modal_config[tiersMonthly][<?php echo $i; ?>][isRecommended]" 
                                                       value="1" 
                                                       <?php checked(true, isset($tier['isRecommended']) ? $tier['isRecommended'] : false); ?> />
                                                <p class="description"><?php echo esc_html__('Mark this tier as recommended.', 'vof'); ?></p>
                                            </td>
                                        </tr>
                                                
                                        <tr>
                                            <th scope="row">
                                                <label for="vof_monthly_tier_<?php echo $i; ?>_grayout"><?php echo esc_html__('Gray Out', 'vof'); ?></label>
                                            </th>
                                            <td>
                                                <input type="checkbox" 
                                                       id="vof_monthly_tier_<?php echo $i; ?>_grayout" 
                                                       name="vof_pricing_modal_config[tiersMonthly][<?php echo $i; ?>][isGrayOut]" 
                                                       value="1" 
                                                       <?php checked(true, isset($tier['isGrayOut']) ? $tier['isGrayOut'] : false); ?> />
                                                <p class="description"><?php echo esc_html__('Gray out this tier to indicate unavailability.', 'vof'); ?></p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                                                
                    <!-- Yearly Tiers Tab -->
                    <div id="yearly-tiers" class="vof-tab-content" style="display: none;">
                        <div style="margin-top: 20px;margin-bottom: 20px" class="vof-tiers-container" id="vof-yearly-tiers-container">
                            <?php for ($i = 0; $i < 3; $i++) : 
                                $tier = isset($options['tiersYearly'][$i]) ? $options['tiersYearly'][$i] : [
                                    'name' => '',
                                    'description' => '',
                                    'price' => 0,
                                    'features' => [],
                                    'isRecommended' => false,
                                    'isGrayOut' => false
                                ];

                                $display = $i < $options['numberOfTiersYearly'] ? 'block' : 'none';
                            ?>
                                <div class="vof-tier-card" id="vof-yearly-tier-<?php echo $i; ?>" style="display: <?php echo $display; ?>">
                                    <h3><?php printf(esc_html__('Tier %d Configuration', 'vof'), $i + 1); ?></h3>

                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="vof_yearly_tier_<?php echo $i; ?>_name"><?php echo esc_html__('Plan Name', 'vof'); ?></label>
                                            </th>
                                            <td>
                                                <input type="text" 
                                                       id="vof_yearly_tier_<?php echo $i; ?>_name" 
                                                       name="vof_pricing_modal_config[tiersYearly][<?php echo $i; ?>][name]" 
                                                       value="<?php echo esc_attr($tier['name']); ?>" 
                                                       class="regular-text" />
                                            </td>
                                        </tr>

                                        <tr>
                                            <th scope="row">
                                                <label for="vof_yearly_tier_<?php echo $i; ?>_description"><?php echo esc_html__('Description', 'vof'); ?></label>
                                            </th>
                                            <td>
                                                <textarea id="vof_yearly_tier_<?php echo $i; ?>_description" 
                                                          name="vof_pricing_modal_config[tiersYearly][<?php echo $i; ?>][description]" 
                                                          class="large-text" 
                                                          rows="3"><?php echo esc_textarea($tier['description']); ?></textarea>
                                            </td>
                                        </tr>

                                        <tr>
                                            <th scope="row">
                                                <label for="vof_yearly_tier_<?php echo $i; ?>_price"><?php echo esc_html__('Price', 'vof'); ?></label>
                                            </th>
                                            <td>
                                                <input type="number" 
                                                       id="vof_yearly_tier_<?php echo $i; ?>_price" 
                                                       name="vof_pricing_modal_config[tiersYearly][<?php echo $i; ?>][price]" 
                                                       value="<?php echo esc_attr($tier['price']); ?>" 
                                                       step="0.01" 
                                                       min="0" />
                                            </td>
                                        </tr>

                                        <tr>
                                            <th scope="row">
                                                <?php echo esc_html__('Features (Up to 6)', 'vof'); ?>
                                            </th>
                                            <td class="vof-features-container">
                                                <?php for ($j = 0; $j < 6; $j++) : 
                                                    $feature = isset($tier['features'][$j]) ? $tier['features'][$j] : '';
                                                ?>
                                                    <div class="vof-feature-input">
                                                        <input type="text" 
                                                               name="vof_pricing_modal_config[tiersYearly][<?php echo $i; ?>][features][<?php echo $j; ?>]" 
                                                               value="<?php echo esc_attr($feature); ?>" 
                                                               class="regular-text"
                                                               placeholder="<?php printf(esc_attr__('Feature %d', 'vof'), $j + 1); ?>" />
                                                    </div>
                                                <?php endfor; ?>
                                            </td>
                                        </tr>
                                                
                                        <tr>
                                            <th scope="row">
                                                <label for="vof_yearly_tier_<?php echo $i; ?>_recommended"><?php echo esc_html__('Recommended', 'vof'); ?></label>
                                            </th>
                                            <td>
                                                <input type="checkbox" 
                                                       id="vof_yearly_tier_<?php echo $i; ?>_recommended" 
                                                       name="vof_pricing_modal_config[tiersYearly][<?php echo $i; ?>][isRecommended]" 
                                                       value="1" 
                                                       <?php checked(true, isset($tier['isRecommended']) ? $tier['isRecommended'] : false); ?> />
                                                <p class="description"><?php echo esc_html__('Mark this tier as recommended.', 'vof'); ?></p>
                                            </td>
                                        </tr>
                                                
                                        <tr>
                                            <th scope="row">
                                                <label for="vof_yearly_tier_<?php echo $i; ?>_grayout"><?php echo esc_html__('Gray Out', 'vof'); ?></label>
                                            </th>
                                            <td>
                                                <input type="checkbox" 
                                                       id="vof_yearly_tier_<?php echo $i; ?>_grayout" 
                                                       name="vof_pricing_modal_config[tiersYearly][<?php echo $i; ?>][isGrayOut]" 
                                                       value="1" 
                                                       <?php checked(true, isset($tier['isGrayOut']) ? $tier['isGrayOut'] : false); ?> />
                                                <p class="description"><?php echo esc_html__('Gray out this tier to indicate unavailability.', 'vof'); ?></p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <hr style="margin-top: 20px;margin-bottom: 20px">

                <div class="vof-actions">
                    <?php submit_button(esc_html__('Save Settings', 'vof'), 'primary', 'submit', false); ?>
                                                
                    <button type="button" class="button button-secondary vof-reset-defaults">
                        <?php echo esc_html__('Reset to Defaults', 'vof'); ?>
                    </button>
                                                
                    <?php if (class_exists('\VOF\Utils\VofStripe\VOF_Stripe_Config')) : ?>
                    <button type="button" class="button button-secondary vof-sync-stripe">
                        <?php echo esc_html__('Sync with Stripe', 'vof'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
                    
        <!-- Tab switching and tier display script -->
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Tab navigation
            $('.nav-tab-wrapper a').on('click', function(e) {
                e.preventDefault();

                // Hide all tab content
                $('.vof-tab-content').hide();

                // Remove active class from all tabs
                $('.nav-tab-wrapper a').removeClass('nav-tab-active');

                // Show selected tab content
                $($(this).attr('href')).show();

                // Add active class to selected tab
                $(this).addClass('nav-tab-active');
            });

            // Show/hide yearly tabs based on multi-pricing toggle
            $('#vof_multi_pricing').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.vof-yearly-tiers-option').show();
                    $('.nav-tab-wrapper a[href="#yearly-tiers"]').show();
                } else {
                    $('.vof-yearly-tiers-option').hide();
                    $('.nav-tab-wrapper a[href="#yearly-tiers"]').hide();

                    // If yearly tab is active when disabling multi-pricing, switch to monthly tab
                    if ($('.nav-tab-wrapper a[href="#yearly-tiers"]').hasClass('nav-tab-active')) {
                        $('.nav-tab-wrapper a[href="#monthly-tiers"]').trigger('click');
                    }
                }
            });

            // Handle tier count changes
            $('.vof-tier-count-selector').on('change', function() {
                var tierType = $(this).data('tier-type');
                var count = parseInt($(this).val());

                // Show/hide tiers based on selected count
                for (var i = 0; i < 3; i++) {
                    if (i < count) {
                        $('#vof-' + tierType + '-tier-' + i).show();
                    } else {
                        $('#vof-' + tierType + '-tier-' + i).hide();
                    }
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler for fetching Stripe products
     */
    public function vof_ajax_fetch_stripe_products() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vof_pricing_modal_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        // Check if Stripe config class exists
        if (!class_exists('\VOF\Utils\VofStripe\VOF_Stripe_Config')) {
            wp_send_json_error(['message' => 'Stripe configuration not available']);
            return;
        }
        
        // Placeholder - actual implementation would fetch from Stripe
        $products = [];
        
        wp_send_json_success(['products' => $products]);
    }
    
    /**
     * AJAX handler for saving pricing modal settings
     */
    public function vof_ajax_save_pricing_modal_settings() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vof_pricing_modal_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
            return;
        }
        
        // Get form data
        $config = isset($_POST['config']) ? $_POST['config'] : [];
        
        // Sanitize and save
        $sanitized = $this->vof_sanitize_pricing_modal_config($config);
        update_option('vof_pricing_modal_config', $sanitized);
        
        wp_send_json_success(['message' => 'Settings saved successfully']);
    }
}