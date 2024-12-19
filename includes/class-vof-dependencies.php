<?php
namespace VOF;

if (!defined('ABSPATH')) exit;

class VOF_Dependencies {
    private static $required_plugins = [
        'classified-listing/classified-listing.php' => [
            'name' => 'Classified Listing',
            'min_version' => '1.0.0'
        ],
        'classified-listing-pro/classified-listing-pro.php' => [
            'name' => 'Classified Listing Pro',
            'min_version' => '1.0.0'
        ],
        'classified-listing-store/classified-listing-store.php' => [
            'name' => 'Classified Listing Store',
            'min_version' => '1.5.0'
        ]
    ];

    public static function check() {
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        foreach (self::$required_plugins as $plugin => $details) {
            if (!is_plugin_active($plugin)) {
                return new \WP_Error(
                    'missing_plugin',
                    sprintf(__('%s plugin is required', 'vendor-onboarding-flow'), $details['name'])
                );
            }

            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
            $version = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';

            if (version_compare($version, $details['min_version'], '<')) {
                return new \WP_Error(
                    'version_requirement',
                    sprintf(
                        __('%s plugin version %s or higher is required', 'vendor-onboarding-flow'),
                        $details['name'],
                        $details['min_version']
                    )
                );
            }
        }

        return true;
    }

    public static function load_dependencies() {
        // Load core dependencies after plugins are loaded
        add_action('plugins_loaded', function() {
            if (class_exists('Rtcl\\Helpers\\Functions')) {
                require_once rtcl()->plugin_path() . 'app/Helpers/Functions.php';
            }
        }, 20);
    }
}