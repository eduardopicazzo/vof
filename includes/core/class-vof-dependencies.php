<?php
/**
 * Purpose: Ensures plugin dependencies are active.
 */

namespace VOF\Core;

class VOF_Dependencies {
    public static function vof_check_dependencies() {
        $required_plugins = [
            'classified-listing/classified-listing.php' => 'Classified Listing',
            'classified-listing-pro/classified-listing-pro.php' => 'Classified Listing Pro',
            'classified-listing-store/classified-listing-store.php' => 'Classified Listing Store'
        ];

        foreach ($required_plugins as $plugin => $name) {
            if (!is_plugin_active($plugin)) {
                deactivate_plugins(plugin_basename(__FILE__));
                wp_die(sprintf(__('%s plugin is required for Vendor Onboarding Flow.', 'vendor-onboarding-flow'), $name));
            }
        }
    }
}