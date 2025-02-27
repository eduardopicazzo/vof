<?php
/**
 * MailerLite Settings for Vendor Onboarding Flow
 *
 * @package VOF
 * @subpackage Utils\MailingESPs
 */

namespace VOF\Utils\MailingESPs;

/**
 * Class VOF_MailerLite_Settings
 * 
 * Handles admin settings for MailerLite integration.
 */
class VOF_MailerLite_Settings {
    /**
     * Option group name
     *
     * @var string
     */
    private $option_group = 'vof_mailerlite_options';
    
    /**
     * MailerLite client instance
     *
     * @var VOF_MailerLite
     */
    private $mailerlite;
    
    /**
     * Constructor
     */
    public function __construct_OLD() {
        $this->mailerlite = VOF_MailerLite::vof_get_instance();
        
        // Add settings page
        add_action('admin_menu', [$this, 'vof_add_settings_page'], 100);
        
        // Register settings
        add_action('admin_init', [$this, 'vof_register_settings']);
        
        // Add AJAX handler for testing API connection
        add_action('wp_ajax_vof_test_mailerlite_connection', [$this, 'vof_ajax_test_connection']);
    }

    /**
 * Constructor
 */
public function __construct() {
    $this->mailerlite = VOF_MailerLite::vof_get_instance();
    
    // Add settings page
    add_action('admin_menu', [$this, 'vof_add_settings_page'], 100);
    
    // Register settings
    add_action('admin_init', [$this, 'vof_register_settings']);
    
    // Add AJAX handlers
    add_action('wp_ajax_vof_test_mailerlite_connection', [$this, 'vof_ajax_test_connection']);
    add_action('wp_ajax_vof_toggle_mailerlite_enabled', [$this, 'vof_ajax_toggle_enabled']);
}

/**
 * AJAX handler for toggling the enabled setting
 */
public function vof_ajax_toggle_enabled() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vof_toggle_mailerlite_enabled')) {
        wp_send_json_error('Security check failed');
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }

    // Get the enabled state from the request
    $enabled = isset($_POST['enabled']) ? (bool)$_POST['enabled'] : false;
    
    // Update the option
    update_option('vof_mailerlite_enabled', $enabled);
    
    // Send success response
    wp_send_json_success([
        'enabled' => $enabled
    ]);
}
    
    /**
     * Add settings page to admin menu
     */
    public function vof_add_settings_page() {
        add_submenu_page(
            'vof_debug', // Parent slug (your main plugin menu)
            'MailerLite Settings',
            'MailerLite',
            'manage_options',
            'vof_mailerlite_settings',
            [$this, 'vof_render_settings_page']
        );
    }

/**
 * Register settings
 */
public function vof_register_settings() {
    // Add the new setting for activation status
    register_setting(
        $this->option_group,
        'vof_mailerlite_enabled',
        [
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false
        ]
    );
    
    // API key setting
    register_setting(
        $this->option_group,
        'vof_mailerlite_api_key',
        [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]
    );
    
    // Onboarding group setting
    register_setting(
        $this->option_group,
        'vof_mailerlite_onboarding_group',
        [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]
    );
    
    // API Key Section
    add_settings_section(
        'vof_mailerlite_api_section',
        'API Configuration',
        [$this, 'vof_render_api_section'],
        'vof_mailerlite_settings'
    );
    
    add_settings_field(
        'vof_mailerlite_api_key',
        'API Key',
        [$this, 'vof_render_api_key_field'],
        'vof_mailerlite_settings',
        'vof_mailerlite_api_section'
    );
    
    // Group Settings Section
    add_settings_section(
        'vof_mailerlite_groups_section',
        'Group Configuration',
        [$this, 'vof_render_groups_section'],
        'vof_mailerlite_settings'
    );
    
    add_settings_field(
        'vof_mailerlite_onboarding_group',
        'Onboarding Group',
        [$this, 'vof_render_onboarding_group_field'],
        'vof_mailerlite_settings',
        'vof_mailerlite_groups_section'
    );
}    
    
    /**
     * Register settings
     */
    public function vof_register_settings_OLD() {
        // Add the new setting for activation status
        register_setting(
            $this->option_group,
            'vof_mailerlite_enabled',
            [
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => false
            ]
        );

        register_setting(
            $this->option_group,
            'vof_mailerlite_api_key',
            [
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ]
        );
        
        register_setting(
            $this->option_group,
            'vof_mailerlite_onboarding_group',
            [
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ]
        );

        // Add new section for general settings
        add_settings_section(
            'vof_mailerlite_general_section',
            'General Settings',
            [$this, 'vof_render_general_section'],
            'vof_mailerlite_settings'
        );
    
        // Add the enable/disable field
        add_settings_field(
            'vof_mailerlite_enabled',
            'Enable MailerLite Integration',
            [$this, 'vof_render_enabled_field'],
            'vof_mailerlite_settings',
            'vof_mailerlite_general_section'
        );
        
        // API Key Section
        add_settings_section(
            'vof_mailerlite_api_section',
            'API Configuration',
            [$this, 'vof_render_api_section'],
            'vof_mailerlite_settings'
        );
        
        add_settings_field(
            'vof_mailerlite_api_key',
            'API Key',
            [$this, 'vof_render_api_key_field'],
            'vof_mailerlite_settings',
            'vof_mailerlite_api_section'
        );
        
        // Group Settings Section
        add_settings_section(
            'vof_mailerlite_groups_section',
            'Group Configuration',
            [$this, 'vof_render_groups_section'],
            'vof_mailerlite_settings'
        );
        
        add_settings_field(
            'vof_mailerlite_onboarding_group',
            'Onboarding Group',
            [$this, 'vof_render_onboarding_group_field'],
            'vof_mailerlite_settings',
            'vof_mailerlite_groups_section'
        );
    }
    
    /**
     * Render the general section description
     */
    public function vof_render_general_section() {
        echo '<p>Configure general settings for the MailerLite integration.</p>';
    }

    /**
    * Render the enabled checkbox field
    */
    public function vof_render_enabled_field() {
        $enabled = get_option('vof_mailerlite_enabled', false);
        ?>
        <label>
            <input type="checkbox" 
                   name="vof_mailerlite_enabled" 
                   id="vof_mailerlite_enabled" 
                   value="1" 
                   <?php checked($enabled, true); ?>>
            <?php esc_html_e('Enable MailerLite integration for the Vendor Onboarding Flow', 'vendor-onboarding-flow'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When disabled, no data will be sent to MailerLite, even if API keys are configured.', 'vendor-onboarding-flow'); ?>
        </p>
        <?php
    }

    /**
     * Render the API section description
     */
    public function vof_render_api_section() {
        echo '<p>Configure your MailerLite API settings below. You can find your API key in your MailerLite account under Integrations.</p>';
    }
    
    /**
     * Render the groups section description
     */
    public function vof_render_groups_section() {
        echo '<p>Configure MailerLite group IDs for different user flows.</p>';
        echo '<p>The plugin will automatically create the following groups if they don\'t exist:</p>';
        echo '<ul>';
        echo '<li><strong>VOF_Fallback</strong> - Used when no onboarding group is selected below</li>';
        echo '<li><strong>VOF Complete</strong> - Used for users who complete the checkout process</li>';
        echo '</ul>';
    }
    
    /**
     * Render API key field
     */
    public function vof_render_api_key_field() {
        $api_key = get_option('vof_mailerlite_api_key', '');
        ?>
        <input type="password" 
               name="vof_mailerlite_api_key" 
               id="vof_mailerlite_api_key" 
               value="<?php echo esc_attr($api_key); ?>" 
               class="regular-text" 
               autocomplete="off">
        <button type="button" class="button" id="vof_test_api_connection">Test Connection</button>
        <span id="vof_api_connection_status"></span>
        <p class="description">Enter your MailerLite API key.</p>
        <?php
    }

    public function vof_render_onboarding_group_field() {
        $onboarding_group = get_option('vof_mailerlite_onboarding_group', '');
        $groups = $this->vof_get_mailerlite_groups();
        ?>
        <select name="vof_mailerlite_onboarding_group" id="vof_mailerlite_onboarding_group">
            <option value="">-- Select Group --</option>
            <?php
            if (is_array($groups) && !empty($groups)) {
                foreach ($groups as $group) {
                    $selected = ($group['id'] == $onboarding_group) ? 'selected' : '';
                    echo '<option value="' . esc_attr($group['id']) . '" ' . $selected . '>' . esc_html($group['name']) . '</option>';
                }
            } else {
                echo '<option value="" disabled>No groups available - check API connection</option>';
            }
            ?>
        </select>
        <p class="description">
            Select the group where new vendors will be added after completing onboarding.
            <?php if (empty($groups)): ?>
                <br><strong>Note:</strong> You need a valid API connection to see available groups.
            <?php endif; ?>
        </p>
        <?php
    }
    
    /**
     * Get MailerLite groups for dropdown
     *
     * @return array
     */
    private function vof_get_mailerlite_groups() {
        if (!$this->mailerlite || !$this->mailerlite->vof_is_connected()) {
            error_log('VOF Debug: MailerLite not connected when trying to get groups');
            return [];
        }

        try {
            $response = $this->mailerlite->vof_get_groups();

            if ($response && isset($response['body']['data']) && is_array($response['body']['data'])) {
                $groups = [];
                foreach ($response['body']['data'] as $group) {
                    $groups[] = [
                        'id' => $group['id'],
                        'name' => $group['name']
                    ];
                }
                return $groups;
            }

            error_log('VOF Debug: Groups structure not as expected in response');
            return [];

        } catch (\Exception $e) {
            error_log('VOF Error: Failed to get MailerLite groups - ' . $e->getMessage());
            return [];
        }
    }

/**
 * Render the settings page
 */
public function vof_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $is_enabled = get_option('vof_mailerlite_enabled', false);
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <form action="options.php" method="post">
            <?php settings_fields($this->option_group); ?>
            
            <!-- Always show the General Settings section with the enable/disable checkbox -->
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable MailerLite Integration', 'vendor-onboarding-flow'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="vof_mailerlite_enabled" 
                                       id="vof_mailerlite_enabled" 
                                       value="1" 
                                       <?php checked($is_enabled, true); ?>>
                                <?php esc_html_e('Enable MailerLite integration for the Vendor Onboarding Flow', 'vendor-onboarding-flow'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When disabled, no data will be sent to MailerLite.', 'vendor-onboarding-flow'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Only show the API settings when enabled -->
            <div id="vof-mailerlite-settings-container" style="<?php echo $is_enabled ? '' : 'display: none;'; ?>">
                <?php 
                // Render API settings section and fields
                do_settings_sections('vof_mailerlite_settings'); 
                ?>
            </div>
            
            <!-- Save button (only shown when enabled) -->
            <div id="vof-mailerlite-submit-container" style="<?php echo $is_enabled ? '' : 'display: none;'; ?>">
                <?php submit_button(); ?>
            </div>
        </form>
    </div>
    
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Toggle visibility of settings when checkbox is changed
            $('#vof_mailerlite_enabled').on('change', function() {
                var isChecked = $(this).is(':checked');
                
                // Update visibility of settings and save button
                if (isChecked) {
                    $('#vof-mailerlite-settings-container').show();
                    $('#vof-mailerlite-submit-container').show();
                } else {
                    $('#vof-mailerlite-settings-container').hide();
                    $('#vof-mailerlite-submit-container').hide();
                }
                
                // Save the setting via AJAX without full form submission
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'vof_toggle_mailerlite_enabled',
                        enabled: isChecked ? 1 : 0,
                        nonce: '<?php echo wp_create_nonce('vof_toggle_mailerlite_enabled'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Flash a quick success message
                            var $notice = $('<div class="notice notice-success is-dismissible"><p>' + 
                                           (isChecked ? 'MailerLite integration enabled.' : 'MailerLite integration disabled.') + 
                                           '</p></div>');
                                           
                            $('.wrap h1').after($notice);
                            
                            // Auto-dismiss after 2 seconds
                            setTimeout(function() {
                                $notice.fadeOut(function() {
                                    $(this).remove();
                                });
                            }, 2000);
                        }
                    }
                });
            });
            
            // Rest of your existing JavaScript for API testing...
            $('#vof_test_api_connection').on('click', function() {
                var apiKey = $('#vof_mailerlite_api_key').val();
                var statusSpan = $('#vof_api_connection_status');
                
                if (!apiKey) {
                    statusSpan.html('<span style="color: red;">Please enter an API key</span>');
                    return;
                }
                
                statusSpan.html('<span style="color: blue;">Testing connection...</span>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'vof_test_mailerlite_connection',
                        api_key: apiKey,
                        nonce: '<?php echo wp_create_nonce('vof_test_mailerlite_connection'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            statusSpan.html('<span style="color: green;">Connection successful!</span>');
                            
                            // If we have groups, refresh the dropdown
                            if (response.data && response.data.groups) {
                                var selectField = $('#vof_mailerlite_onboarding_group');
                                var currentValue = selectField.val();
                                
                                selectField.empty();
                                selectField.append('<option value="">-- Select Group --</option>');
                                
                                $.each(response.data.groups, function(i, group) {
                                    var selected = (group.id == currentValue) ? 'selected' : '';
                                    selectField.append('<option value="' + group.id + '" ' + selected + '>' + group.name + '</option>');
                                });
                            }
                        } else {
                            statusSpan.html('<span style="color: red;">Connection failed: ' + response.data + '</span>');
                        }
                    },
                    error: function() {
                        statusSpan.html('<span style="color: red;">Connection test failed</span>');
                    }
                });
            });
        });
    </script>
    <?php
}    
    
/**
 * Render the settings page
 */
public function vof_render_settings_page_ToggleViewSave() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $is_enabled = get_option('vof_mailerlite_enabled', false);
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <form action="options.php" method="post">
            <?php settings_fields($this->option_group); ?>
            
            <!-- Always show the General Settings section with the enable/disable checkbox -->
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable MailerLite Integration', 'vendor-onboarding-flow'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="vof_mailerlite_enabled" 
                                       id="vof_mailerlite_enabled" 
                                       value="1" 
                                       <?php checked($is_enabled, true); ?>>
                                <?php esc_html_e('Enable MailerLite integration for the Vendor Onboarding Flow', 'vendor-onboarding-flow'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When disabled, no data will be sent to MailerLite.', 'vendor-onboarding-flow'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Only show the API settings when enabled -->
            <div id="vof-mailerlite-settings-container" style="<?php echo $is_enabled ? '' : 'display: none;'; ?>">
                <?php 
                // Render API settings section and fields
                do_settings_sections('vof_mailerlite_settings'); 
                ?>
            </div>
            
            <?php submit_button(); ?>
        </form>
    </div>
    
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Toggle visibility of settings when checkbox is changed
            $('#vof_mailerlite_enabled').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#vof-mailerlite-settings-container').show();
                } else {
                    $('#vof-mailerlite-settings-container').hide();
                }
            });
            
            // Rest of your existing JavaScript...
            $('#vof_test_api_connection').on('click', function() {
                var apiKey = $('#vof_mailerlite_api_key').val();
                var statusSpan = $('#vof_api_connection_status');
                
                if (!apiKey) {
                    statusSpan.html('<span style="color: red;">Please enter an API key</span>');
                    return;
                }
                
                statusSpan.html('<span style="color: blue;">Testing connection...</span>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'vof_test_mailerlite_connection',
                        api_key: apiKey,
                        nonce: '<?php echo wp_create_nonce('vof_test_mailerlite_connection'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            statusSpan.html('<span style="color: green;">Connection successful!</span>');
                            
                            // If we have groups, refresh the dropdown
                            if (response.data && response.data.groups) {
                                var selectField = $('#vof_mailerlite_onboarding_group');
                                var currentValue = selectField.val();
                                
                                selectField.empty();
                                selectField.append('<option value="">-- Select Group --</option>');
                                
                                $.each(response.data.groups, function(i, group) {
                                    var selected = (group.id == currentValue) ? 'selected' : '';
                                    selectField.append('<option value="' + group.id + '" ' + selected + '>' + group.name + '</option>');
                                });
                            }
                        } else {
                            statusSpan.html('<span style="color: red;">Connection failed: ' + response.data + '</span>');
                        }
                    },
                    error: function() {
                        statusSpan.html('<span style="color: red;">Connection test failed</span>');
                    }
                });
            });
        });
    </script>
    <?php
}

    /**
     * Render the settings page
     */
    public function vof_render_settings_page_OLD() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields($this->option_group);
                do_settings_sections('vof_mailerlite_settings');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#vof_test_api_connection').on('click', function() {
                    var apiKey = $('#vof_mailerlite_api_key').val();
                    var statusSpan = $('#vof_api_connection_status');
                    
                    if (!apiKey) {
                        statusSpan.html('<span style="color: red;">Please enter an API key</span>');
                        return;
                    }
                    
                    statusSpan.html('<span style="color: blue;">Testing connection...</span>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'vof_test_mailerlite_connection',
                            api_key: apiKey,
                            nonce: '<?php echo wp_create_nonce('vof_test_mailerlite_connection'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                statusSpan.html('<span style="color: green;">Connection successful!</span>');
                                
                                // If we have groups, refresh the dropdown
                                if (response.data && response.data.groups) {
                                    var selectField = $('#vof_mailerlite_onboarding_group');
                                    var currentValue = selectField.val();
                                    
                                    selectField.empty();
                                    selectField.append('<option value="">-- Select Group --</option>');
                                    
                                    $.each(response.data.groups, function(i, group) {
                                        var selected = (group.id == currentValue) ? 'selected' : '';
                                        selectField.append('<option value="' + group.id + '" ' + selected + '>' + group.name + '</option>');
                                    });
                                }
                            } else {
                                statusSpan.html('<span style="color: red;">Connection failed: ' + response.data + '</span>');
                            }
                        },
                        error: function() {
                            statusSpan.html('<span style="color: red;">Connection test failed</span>');
                        }
                    });
                });
            });
        </script>
        <?php
    }
    
    /**
     * AJAX handler for testing API connection
     */
    public function vof_ajax_test_connection() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vof_test_mailerlite_connection')) {
            wp_send_json_error('Security check failed');
        }

        // Get API key from request
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

        if (empty($api_key)) {
            wp_send_json_error('API key is required');
        }

        try {
            // Create a temporary client for testing
            $temp_client = new \MailerLite\MailerLite([
                'api_key' => $api_key,
            ]);

            // Try to get groups as a test
            $response = $temp_client->groups->get();

            // Check if we have a proper response with data
            if (isset($response['body']['data']) && is_array($response['body']['data'])) {
                // Process the data into a simpler format
                $groups = [];
                foreach ($response['body']['data'] as $group) {
                    $groups[] = [
                        'id' => $group['id'],
                        'name' => $group['name']
                    ];
                }

                wp_send_json_success([
                    'message' => 'Connection successful',
                    'groups' => $groups
                ]);
            } else {
                // Still return success but with a note about no groups
                wp_send_json_success([
                    'message' => 'Connection successful, but no groups found',
                    'groups' => []
                ]);
            }

        } catch (\Exception $e) {
            wp_send_json_error('Connection Error: ' . $e->getMessage());
        }
    }
}