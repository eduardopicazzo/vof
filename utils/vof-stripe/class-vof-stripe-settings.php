<?php
namespace VOF\Utils\Stripe;

class VOF_Stripe_Settings {
    public function __construct() {
        add_action('admin_menu', [$this, 'vof_add_stripe_settings_page']);
        add_action('admin_init', [$this, 'vof_register_stripe_settings']);
    }

    public function vof_add_stripe_settings_page() {
        add_submenu_page(
            'vof_debug', // Parent slug
            'Stripe Settings',
            'Stripe Settings',
            'manage_options',
            'vof-stripe-settings',
            [$this, 'vof_render_stripe_settings_page']
        );
    }

    public function vof_register_stripe_settings() {
        // Test mode settings
        register_setting('vof_stripe_settings', 'vof_stripe_test_mode');
        register_setting('vof_stripe_settings', 'vof_stripe_test_publishable_key');
        register_setting('vof_stripe_settings', 'vof_stripe_test_secret_key');
        register_setting('vof_stripe_settings', 'vof_stripe_test_webhook_secret');

        // Live mode settings
        register_setting('vof_stripe_settings', 'vof_stripe_live_publishable_key');
        register_setting('vof_stripe_settings', 'vof_stripe_live_secret_key');
        register_setting('vof_stripe_settings', 'vof_stripe_live_webhook_secret');
    }

    public function vof_render_stripe_settings_page() {
        ?>
        <div class="wrap">
            <h1>VOF Stripe Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('vof_stripe_settings');
                do_settings_sections('vof_stripe_settings');
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Test Mode</th>
                        <td>
                            <input type="checkbox" name="vof_stripe_test_mode" 
                                   value="1" <?php checked(1, get_option('vof_stripe_test_mode'), true); ?> />
                            <p class="description">Enable test mode for development</p>
                        </td>
                    </tr>

                    <!-- Test Mode Settings -->
                    <tr>
                        <th scope="row">Test Publishable Key</th>
                        <td>
                            <input type="text" name="vof_stripe_test_publishable_key" 
                                   value="<?php echo esc_attr(get_option('vof_stripe_test_publishable_key')); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Test Secret Key</th>
                        <td>
                            <input type="text" name="vof_stripe_test_secret_key" 
                                   value="<?php echo esc_attr(get_option('vof_stripe_test_secret_key')); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Test Webhook Secret</th>
                        <td>
                            <input type="text" name="vof_stripe_test_webhook_secret" 
                                   value="<?php echo esc_attr(get_option('vof_stripe_test_webhook_secret')); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>

                    <!-- Live Mode Settings -->
                    <tr>
                        <th scope="row">Live Publishable Key</th>
                        <td>
                            <input type="text" name="vof_stripe_live_publishable_key" 
                                   value="<?php echo esc_attr(get_option('vof_stripe_live_publishable_key')); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Live Secret Key</th>
                        <td>
                            <input type="password" name="vof_stripe_live_secret_key" 
                                   value="<?php echo esc_attr(get_option('vof_stripe_live_secret_key')); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Live Webhook Secret</th>
                        <td>
                            <input type="password" name="vof_stripe_live_webhook_secret" 
                                   value="<?php echo esc_attr(get_option('vof_stripe_live_webhook_secret')); ?>" 
                                   class="regular-text" />
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}