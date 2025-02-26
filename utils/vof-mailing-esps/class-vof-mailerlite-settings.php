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
    public function __construct() {
        $this->mailerlite = VOF_MailerLite::vof_get_instance();
        
        // Add settings page
        add_action('admin_menu', [$this, 'vof_add_settings_page'], 100);
        
        // Register settings
        add_action('admin_init', [$this, 'vof_register_settings']);
        
        // Add AJAX handler for testing API connection
        add_action('wp_ajax_vof_test_mailerlite_connection', [$this, 'vof_ajax_test_connection']);
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