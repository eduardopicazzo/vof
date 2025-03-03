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

        // Add interim fulfillment setting
        register_setting('vof_stripe_settings', 'vof_enable_interim_fulfillment');
    }

// Path: wp-content/plugins/vendor-onboarding-flow/utils/vof-stripe/class-vof-stripe-settings.php

public function vof_render_stripe_settings_page() {
    ?>
    <div class="wrap">
        <h1>VOF Stripe Settings</h1>
        
        <!-- Main settings form -->
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
            <hr>
            <!-- Interim Fulfillment Setting -->
            <table class="form-table">
                <h2>VOF Subscription Management</h2>
                <tr>
                <th scope="row">Interim Fulfillment</th>
                <td>
                    <label for="vof_enable_interim_fulfillment">
                        <input type="checkbox" name="vof_enable_interim_fulfillment" 
                               id="vof_enable_interim_fulfillment"
                               value="1" <?php checked(1, get_option('vof_enable_interim_fulfillment', true), true); ?> />
                        Enable monthly interim fulfillment for longer billing subscriptions
                    </label>
                    <p class="description">TLDR; Prevent one-off product fulfillment on subscriptons with longer-than-monthly billing periods </p>
                    <p class="description">When enabled, subscriptions with longer-than "per month" billing period intervals (bi-monthly, yearly, etc) will keep being fulfilled monthly regardless of the longer interval periods.</p>
                </td>
                </tr>                    
            </table>
            
            <?php submit_button('Save Settings'); ?>
        </form>
        
        <!-- Separate form for manual membership restoration -->
        <hr>
        <h2>Manual Membership Restoration Tool</h2>
        <p class="description">Use this tool to manually restore or fulfill a membership for a user when the normal fulfillment process failed.</p>

        <div id="vof-manual-membership-form" style="background: #F0F0F1; padding: 15px; border: 1px solid #ccc; margin-bottom: 20px;">
            <?php 
            // Check if form was submitted and process it
            if (isset($_POST['vof_manual_fulfill_submit']) && wp_verify_nonce($_POST['vof_manual_membership_nonce'], 'vof_manual_membership')) {
                $this->vof_process_manual_membership_form();
            }
            ?>
            <form method="post" action="">
                <?php wp_nonce_field('vof_manual_membership', 'vof_manual_membership_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="vof_user_id">User ID <span style="color:red">*</span></label></th>
                        <td>
                            <input type="number" name="vof_user_id" id="vof_user_id" class="regular-text" required />
                            <p class="description">The WordPress User ID to fulfill membership for</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vof_subscription_id">Subscription ID <span style="color:red">*</span></label></th>
                        <td>
                            <input type="text" name="vof_subscription_id" id="vof_subscription_id" class="regular-text" required />
                            <p class="description">The Stripe Subscription ID (e.g., sub_1234...)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vof_product_name">Product Name</label></th>
                        <td>
                            <input type="text" name="vof_product_name" id="vof_product_name" class="regular-text" />
                            <p class="description">Optional: The name of the subscription product</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vof_pricing_id">RTCL Pricing ID</label></th>
                        <td>
                            <input type="number" name="vof_pricing_id" id="vof_pricing_id" class="regular-text" />
                            <p class="description">Optional: The RTCL pricing tier ID</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="vof_manual_fulfill_submit" class="button button-primary" value="Manually Fulfill Membership" />
                </p>
            </form>
        </div>
    </div>
    <?php
}

    public function vof_render_stripe_settings_page_OLD() {
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
                <hr>
                <!-- Interim Fulfillment Setting -->
                <table class="form-table">
                    <h2>VOF Subscription Management</h2>
                    <tr>
                    <th scope="row">Interim Fulfillment</th>
                    <td>
                        <label for="vof_enable_interim_fulfillment">
                            <input type="checkbox" name="vof_enable_interim_fulfillment" 
                                   id="vof_enable_interim_fulfillment"
                                   value="1" <?php checked(1, get_option('vof_enable_interim_fulfillment', true), true); ?> />
                            Enable monthly interim fulfillment for longer billing subscriptions
                        </label>
                        <p class="description">TLDR; Prevent one-off product fulfillment on subscriptons with longer-than-monthly billing periods </p>
                        <p class="description">When enabled, subscriptions with longer-than "per month" billing period intervals (bi-monthly, yearly, etc) will keep being fulfilled monthly regardless of the longer interval periods.</p>
                    </td>
                </tr>                    
                </table>

                <!-- NEW SECTION: Manual Membership Restoration Tool -->
                <hr>
                <h2>Manual Membership Restoration Tool</h2>
                <p class="description">Use this tool to manually restore or fulfill a membership for a user when the normal fulfillment process failed.</p>

                <div id="vof-manual-membership-form" style="background: #F0F0F1; padding: 15px; border: 1px solid #ccc; margin-bottom: 20px;">
                    <?php 
                    // Check if form was submitted and process it
                    if (isset($_POST['vof_manual_fulfill_submit']) && wp_verify_nonce($_POST['vof_manual_membership_nonce'], 'vof_manual_membership')) {
                        $this->vof_process_manual_membership_form();
                    }
                    ?>
                    <form method="post" action="">
                        <?php wp_nonce_field('vof_manual_membership', 'vof_manual_membership_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="vof_user_id">User ID <span style="color:red">*</span></label></th>
                                <td>
                                    <input type="number" name="vof_user_id" id="vof_user_id" class="regular-text" required />
                                    <p class="description">The WordPress User ID to fulfill membership for</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="vof_subscription_id">Subscription ID <span style="color:red">*</span></label></th>
                                <td>
                                    <input type="text" name="vof_subscription_id" id="vof_subscription_id" class="regular-text" required />
                                    <p class="description">The Stripe Subscription ID (e.g., sub_1234...)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="vof_product_name">Product Name</label></th>
                                <td>
                                    <input type="text" name="vof_product_name" id="vof_product_name" class="regular-text" />
                                    <p class="description">Optional: The name of the subscription product</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="vof_pricing_id">RTCL Pricing ID</label></th>
                                <td>
                                    <input type="number" name="vof_pricing_id" id="vof_pricing_id" class="regular-text" />
                                    <p class="description">Optional: The RTCL pricing tier ID</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit" name="vof_manual_fulfill_submit" class="button button-primary" value="Manually Fulfill Membership" />
                        </p>
                    </form>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Process the manual membership fulfillment form
     */
    private function vof_process_manual_membership_form() {
        // Check if user has admin capabilities
        if (!current_user_can('manage_options')) {
            echo '<div class="error"><p>You do not have permission to perform this action.</p></div>';
            return;
        }

        // Get form data
        $user_id = isset($_POST['vof_user_id']) ? intval($_POST['vof_user_id']) : 0;
        $subscription_id = isset($_POST['vof_subscription_id']) ? sanitize_text_field($_POST['vof_subscription_id']) : '';
        $product_name = isset($_POST['vof_product_name']) ? sanitize_text_field($_POST['vof_product_name']) : null;
        $pricing_id = isset($_POST['vof_pricing_id']) ? intval($_POST['vof_pricing_id']) : null;

        // Basic validation
        if (!$user_id || !$subscription_id) {
            echo '<div class="error"><p>User ID and Subscription ID are required fields.</p></div>';
            return;
        }

        // Verify user exists
        if (!get_user_by('ID', $user_id)) {
            echo '<div class="error"><p>User ID does not exist: ' . esc_html($user_id) . '</p></div>';
            return;
        }

        // Call the helper function
        $result = \VOF\Utils\Helpers\VOF_Helper_Functions::vof_manually_fulfill_subscription(
            $user_id,
            $subscription_id,
            $product_name,
            $pricing_id
        );

        if (is_wp_error($result)) {
            echo '<div class="error"><p>Error: ' . esc_html($result->get_error_message()) . '</p></div>';
            return;
        }

        // Success message
        echo '<div class="updated"><p>Membership manually fulfilled successfully for User ID: ' . esc_html($user_id) . '</p></div>';
    }

}