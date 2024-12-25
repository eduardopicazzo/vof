<?php
namespace VOF;

defined('ABSPATH') || exit;

class VOF_Dependencies {
    /**
     * List of required plugins
     */
    private static $required_plugins = [
        // Main plugins
        'classified-listing/classified-listing.php' => [
            'name' => 'Classified Listing',
            'class' => 'Rtcl',
            'function' => 'rtcl'
        ],
        'classified-listing-pro/classified-listing-pro.php' => [
            'name' => 'Classified Listing Pro',
            'class' => 'RtclPro'
        ],
        'classified-listing-store/classified-listing-store.php' => [
            'name' => 'Classified Listing Store',
            'class' => 'RtclStore'
        ]
    ];

    /**
     * Check if all dependencies are available
     * 
     * @return bool
     */
    public static function check() {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $missing = [];

        foreach (self::$required_plugins as $plugin => $details) {
            if (!is_plugin_active($plugin)) {
                $missing[] = $details['name'];
                continue;
            }

            // Check for required class
            if (isset($details['class']) && !class_exists($details['class'])) {
                $missing[] = $details['name'];
            }

            // Check for required function
            if (isset($details['function']) && !function_exists($details['function'])) {
                $missing[] = $details['name'];
            }
        }

        if (!empty($missing)) {
            add_action('admin_notices', function() use ($missing) {
                ?>
                <div class="notice notice-error">
                    <p>
                        <?php 
                        printf(
                            /* translators: %s: List of plugin names */
                            esc_html__('Vendor Onboarding Flow requires the following plugins: %s', 'vendor-onboarding-flow'),
                            '<strong>' . esc_html(implode(', ', $missing)) . '</strong>'
                        ); 
                        ?>
                    </p>
                </div>
                <?php
            });
            return false;
        }

        return true;
    }
}