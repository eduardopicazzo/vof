<?php

namespace VOF;

class VOF_Subscription {
    public static function has_active_subscription($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        // Check WooCommerce Subscriptions if available
        if (class_exists('WC_Subscriptions')) {
            $subscriptions = wcs_get_users_subscriptions($user_id);
            foreach ($subscriptions as $subscription) {
                if ($subscription->has_status('active')) {
                    return true;
                }
            }
        }

	    if (class_exists('\RtclStore\Models\Membership')) {
	        $membership = rtclStore()->factory->get_membership();
	        if ($membership && !$membership->is_expired()) {
	            return true;
	        }
	    }
    }
}


/**
 * Helper function to safely call RtclFunctions::get_option_item() with fallback
 * 
 * This function provides a safe way to access the Classified Listing plugin's options
 * with a fallback mechanism in case the plugin is deactivated or removed.
 * 
 * How it works:
 * 1. First attempts to use the Classified Listing plugin's Functions class via its 
 *    fully qualified namespace (\Rtcl\Helpers\Functions)
 * 2. If that class doesn't exist, falls back to WordPress core get_option():
 *    - Retrieves the entire option array from wp_options table
 *    - Checks if the specific item exists in that array
 *    - Returns the item value or default if not found
 * 
 * @param string $option_name Name of the option section (e.g. 'rtcl_membership_settings')
 * @param string $item_name Name of the specific option item (e.g. 'enable_free_ads')
 * @param mixed $default Default value if option not found
 * @param string $type Type of option (e.g. 'checkbox')
 * @return mixed Option value or default
 */
// function get_rtcl_option_safely($option_name, $item_name, $default = false, $type = 'checkbox') {
//     // Try to use Classified Listing plugin's Functions class if available
//     if (class_exists('\Rtcl\Helpers\Functions')) {
//         return \Rtcl\Helpers\Functions::get_option_item($option_name, $item_name, $default, $type);
//     }

//     // Fallback: Get option directly from WordPress options table
//     // This mimics the basic functionality of the plugin's get_option_item method
//     $options = get_option($option_name, array());
    
//     // For checkbox type, ensure boolean return value
//     if ($type === 'checkbox' && is_array($options) && isset($options[$item_name])) {
//         return filter_var($options[$item_name], FILTER_VALIDATE_BOOLEAN);
//     }
    
//     // For other types, return the value if it exists
//     if (is_array($options) && isset($options[$item_name])) {
//         return $options[$item_name];
//     }
    
//     return $default;
// }