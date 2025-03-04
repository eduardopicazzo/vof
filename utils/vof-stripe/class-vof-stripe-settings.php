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
            
            // Get membership pricing tiers for dropdown
            global $wpdb;
            $pricing_tiers = $wpdb->get_results(
                "SELECT p.ID, p.post_title 
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type = 'rtcl_pricing'
                AND p.post_status = 'publish'
                AND pm.meta_key = 'pricing_type'
                AND pm.meta_value = 'membership'
                ORDER BY p.post_title ASC"
            );
            ?>
            <form method="post" action="">
                <?php wp_nonce_field('vof_manual_membership', 'vof_manual_membership_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="vof_user_email">User Email <span style="color:red">*</span></label></th>
                        <td>
                            <input type="email" name="vof_user_email" id="vof_user_email" class="regular-text" required 
                                  placeholder="user@example.com" value="<?php echo isset($_POST['vof_user_email']) ? esc_attr($_POST['vof_user_email']) : ''; ?>" />
                            <p class="description">Enter the email address of the registered user who needs membership fulfillment</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vof_subscription_id">Subscription ID <span style="color:red">*</span></label></th>
                        <td>
                            <input type="text" name="vof_subscription_id" id="vof_subscription_id" class="regular-text" required 
                                   placeholder="sub_123456789" value="<?php echo isset($_POST['vof_subscription_id']) ? esc_attr($_POST['vof_subscription_id']) : 'manual_sub_' . time(); ?>" />
                            <p class="description">Enter existing Stripe Subscription ID or use the auto-generated ID for a new manual fulfillment</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vof_pricing_id">Membership Tier <span style="color:red">*</span></label></th>
                        <td>
                            <select name="vof_pricing_id" id="vof_pricing_id" class="regular-text" required>
                                <option value="">-- Select a membership tier --</option>
                                <?php
                                if (!empty($pricing_tiers)) {
                                    foreach ($pricing_tiers as $tier) {
                                        echo '<option value="' . esc_attr($tier->ID) . '">' . 
                                             esc_html($tier->post_title) . ' (ID: ' . esc_html($tier->ID) . ')</option>';
                                    }
                                } else {
                                    echo '<option value="" disabled>No membership tiers found</option>';
                                }
                                ?>
                            </select>
                            <p class="description">Select the membership tier to apply</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vof_product_name">Product Name</label></th>
                        <td>
                            <input type="text" name="vof_product_name" id="vof_product_name" class="regular-text" 
                                   placeholder="Manually Restored Membership" 
                                   value="<?php echo isset($_POST['vof_product_name']) ? esc_attr($_POST['vof_product_name']) : 'Manually Restored Membership'; ?>" />
                            <p class="description">Name of the subscription product (optional)</p>
                        </td>
                    </tr>
                </table>
                <div style="background: #FFF; padding: 10px; border: 1px solid #ddd; margin-top: 15px;">
                    <h3>What This Tool Does</h3>
                    <p>This tool allows you to manually fulfill a membership for a user when the normal webhook-based fulfillment process has failed. It will:</p>
                    <ol>
                        <li>Create or update the RTCL subscription record in the database</li>
                        <li>Create a payment record with completed status</li>
                        <li>Trigger membership benefits application</li>
                        <li>Set expiration date to one month from today</li>
                    </ol>
                    <p><strong>Note:</strong> This is a manual override and should only be used when the normal Stripe webhook process fails.</p>
                </div>
                <p class="submit" style="margin-top: 15px;">
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
    $user_email = isset($_POST['vof_user_email']) ? sanitize_email($_POST['vof_user_email']) : '';
    $subscription_id = isset($_POST['vof_subscription_id']) ? sanitize_text_field($_POST['vof_subscription_id']) : '';
    $product_name = isset($_POST['vof_product_name']) ? sanitize_text_field($_POST['vof_product_name']) : 'Manually Restored Subscription';
    $pricing_id = isset($_POST['vof_pricing_id']) ? intval($_POST['vof_pricing_id']) : null;
    
    // Basic validation
    if (!$user_email || !$subscription_id) {
        echo '<div class="error"><p>User Email and Subscription ID are required fields.</p></div>';
        return;
    }
    
    // Get user by email
    $user = get_user_by('email', $user_email);
    if (!$user) {
        echo '<div class="error"><p>No user found with email address: ' . esc_html($user_email) . '</p></div>';
        return;
    }
    
    $user_id = $user->ID;
    
    // If no pricing ID was provided, try to find a default one
    if (!$pricing_id) {
        global $wpdb;
        $pricing_id = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'rtcl_pricing'
             AND p.post_status = 'publish'
             AND pm.meta_key = 'pricing_type'
             AND pm.meta_value = 'membership'
             ORDER BY p.ID DESC
             LIMIT 1"
        );
        
        if (!$pricing_id) {
            echo '<div class="error"><p>No membership pricing tier found. Please specify an RTCL Pricing ID.</p></div>';
            return;
        }
    }
    
    // Build comprehensive subscription data for improved fulfillment
    $subscription_data = [
        'subscription_id' => $subscription_id,
        'product_name' => $product_name,
        'product_id' => 'manual_product_' . $pricing_id,
        'price_id' => 'manual_price_' . $pricing_id,
        'rtcl_pricing_tier_id' => $pricing_id,
        'status' => 'active',
        'amount' => 0, // Set to 0 for manual fulfillment
        'current_period_end' => strtotime('+1 month'), // Default to 1 month from now
        'interval' => 'month',
        'lookup_key' => sanitize_title($product_name),
        'customer' => 'manual_cus_' . $user_id, // Required by validation
    ];
    
    error_log('VOF Debug: Calling manual fulfillment with data: ' . print_r($subscription_data, true));
    
    try {
        // Call the fulfillment handler
        $fulfillment_handler = \VOF\Includes\Fulfillment\VOF_Fulfillment_Handler::getInstance();
        $result = $fulfillment_handler->vof_manual_fulfill_membership($user_id, $subscription_data);
        
        if (is_wp_error($result)) {
            echo '<div class="error"><p>Error: ' . esc_html($result->get_error_message()) . '</p></div>';
            return;
        }
        
        // Success message
        echo '<div class="updated"><p>Membership manually fulfilled successfully for User: ' . esc_html($user->display_name) . ' (' . esc_html($user_email) . ')</p></div>';
        
        // Show additional success details
        echo '<div class="updated"><p><strong>Details:</strong><br>';
        echo 'User ID: ' . esc_html($user_id) . '<br>';
        echo 'Subscription ID: ' . esc_html($subscription_id) . '<br>';
        echo 'Pricing ID: ' . esc_html($pricing_id) . '<br>';
        echo 'Product: ' . esc_html($product_name) . '<br>';
        echo 'Expiration: ' . date('Y-m-d H:i:s', strtotime('+1 month')) . '</p></div>';
        
    } catch (\Exception $e) {
        // Catch any other exceptions
        echo '<div class="error"><p>Error: ' . esc_html($e->getMessage()) . '</p></div>';
        error_log('VOF Error in manual fulfillment: ' . $e->getMessage());
        error_log('VOF Error trace: ' . $e->getTraceAsString());
    }
}


}