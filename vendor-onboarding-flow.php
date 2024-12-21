<?php
/**
 * Plugin Name: Vendor Onboarding Flow
 * Description: Streamlined vendor onboarding with Stripe integration
 * Version: 1.0.0
 * Author: TheNoise
 * Text Domain: vendor-onboarding-flow
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

namespace VOF;

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-vof-core.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-vof-dependencies.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-vof-listing.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-vof-subscription.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-vof-form-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-vof-stripe.php';

// Initialize the plugin
function VOF() {
    return VOF_Core::instance();
}

VOF();








// // Early hook to modify category validation
// add_action('init', function() {
//     // Remove original category validation
//     remove_filter('rtcl_listing_form_validate', ['\RtclStore\Helpers\Functions', 'validate_listing_form'], 10);
    
//     // Add our custom validation
//     add_filter('rtcl_listing_form_validate', function($errors, $data) {
//         // Skip category validation for our custom flows
//         if (!is_user_logged_in() || !VOF_Subscription::has_active_subscription()) {
//             return $errors;
//         }
        
//         // Apply original validation for subscribed users
//         if (class_exists('\RtclStore\Helpers\Functions')) {
//             return \RtclStore\Helpers\Functions::validate_listing_form($errors, $data);
//         }
        
//         return $errors;
//     }, 10, 2);
// }, 5);




/**
 * Override StoreFunctions class for selective category validation based on user state
 * 
 * File location: wp-content/plugins/vendor-onboarding-flow/vendor-onboarding-flow.php
 * 
 * Key points:
 * 1. Register early with plugins_loaded priority 5
 * 2. Only override is_valid_to_post_at_category() method
 * 3. Proxy all other methods to original class
 * 4. Implement conditional logic:
 *    - CASE 1/2: Allow posting for new/wandering users (override)
 *    - CASE 3: Use original validation for logged in users with active subscription
 */
// Extend the original StoreFunctions class
/**
 * Override StoreFunctions class for selective category validation based on user state
 *
 * 1. Register early with plugins_loaded priority 0 (or use an mu-plugin if needed)
 * 2. Only override is_valid_to_post_at_category() method
 * 3. Proxy all other methods to original class
 * 4. Implement conditional logic:
 *    - CASE 1/2: Allow posting for new/wandering users
 *    - CASE 3: Use original validation for logged-in users with active subscription
 */
// add_action('plugins_loaded', function() {
//     // Load before classified-listing-store
//     if (!class_exists('RtclStore\Helpers\Functions')) {
//         return;
//     }

//     class VOF_StoreFunctions extends \RtclStore\Helpers\Functions {

//         // Override category validation
//         public static function is_valid_to_post_at_category(int $cat_id): bool {
//             // CASE 3: Logged-in with active subscription -> use original
//             if (is_user_logged_in() && VOF_Subscription::has_active_subscription()) {
//                 return parent::is_valid_to_post_at_category($cat_id);
//             }
//             // CASE 1/2: New or wandering users -> always allow
//             return true;
//         }

//         // Renamed to follow .cursorrules.md
//         private static function vof_has_active_subscription() {
//             $member = rtclStore()->factory->get_membership();
//             return ($member && !$member->is_expired());
//         }
//     }

//     // Replace the original class with our custom one
//     class_alias('VOF\VOF_StoreFunctions', 'RtclStore\Helpers\Functions');
// },-2000);


// Alternative approach without mu-plugins:
// 1. Use class_exists check and autoloader registration instead

// Register autoloader before plugins load
// spl_autoload_register(function($class) {
//     // Only handle RtclStore\Helpers\Functions class
//     if ($class === 'RtclStore\Helpers\Functions') {
//         // Define our override class
//         class VOF_StoreFunctions {
//             // Store original class methods
//             private static $originalClass = null;

//             public static function is_valid_to_post_at_category(int $cat_id): bool {
//                 // CASE 3: Logged-in with active subscription -> use original validation
//                 if (is_user_logged_in() && self::has_active_subscription()) {
//                     // Load original class if needed
//                     if (!self::$originalClass) {
//                         require_once WP_PLUGIN_DIR . '/classified-listing-store/app/Helpers/Functions.php';
//                         self::$originalClass = new \ReflectionClass('RtclStore\Helpers\Functions');
//                     }

//                     // Call original validation method
//                     return self::$originalClass->getMethod('is_valid_to_post_at_category')
//                                             ->invoke(null, $cat_id);
//                 }
                
//                 // CASE 1/2: New or wandering users -> always allow
//                 return true;
//             }

//             private static function has_active_subscription() {
//                 return class_exists('\VOF\VOF_Subscription') && 
//                        \VOF\VOF_Subscription::has_active_subscription();
//             }

//             // Proxy other static methods to original class
//             public static function __callStatic($name, $arguments) {
//                 if (!self::$originalClass) {
//                     require_once WP_PLUGIN_DIR . '/classified-listing-store/app/Helpers/Functions.php';
//                     self::$originalClass = new \ReflectionClass('RtclStore\Helpers\Functions');
//                 }
//                 return self::$originalClass->getMethod($name)->invokeArgs(null, $arguments);
//             }
//         }

//         // Map the original class name to our override
//         class_alias('VOF_StoreFunctions', $class);
//         return true;
//     }
// }, true, true); // Register with prepend=true to load before other autoloaders

/*
// Debug helper - uncomment to verify override
add_action('init', function() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $reflection = new \ReflectionClass('RtclStore\Helpers\Functions');
        error_log('Functions class file: ' . $reflection->getFileName());
    }
});
*/





// The override needs to happen in mu-plugins to load before all other plugins
// Create file: wp-content/mu-plugins/vof-store-functions-override.php with:

/*
// Debug helper 
add_action('init', function() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (class_exists('RtclStore\Helpers\Functions')) {
            error_log('RtclStore\Helpers\Functions still exists after override attempt');
            $reflection = new \ReflectionClass('RtclStore\Helpers\Functions'); 
            error_log('Class file location: ' . $reflection->getFileName());
        }
    }
});
*/

// Move the class override to mu-plugins instead
// Create wp-content/mu-plugins/vof-store-functions-override.php containing:
/*
<?php
namespace VOF;

class VOF_StoreFunctions extends \RtclStore\Helpers\Functions {
    public static function is_valid_to_post_at_category(int $cat_id): bool {
        if (is_user_logged_in() && VOF_Subscription::has_active_subscription()) {
            return parent::is_valid_to_post_at_category($cat_id);
        }
        return true; 
    }
}

class_alias('VOF\VOF_StoreFunctions', 'RtclStore\Helpers\Functions');
*/







// add_action('plugins_loaded', function() {

//     // Define our override class with VOF prefix per naming rules
//     class VOF_StoreFunctions {
        
//         public static function is_valid_to_post_at_category(int $cat_id): bool {
//             // CASE 3: User logged in with active subscription - use original validation
//             if (is_user_logged_in() && self::has_active_subscription()) {
//                 if (class_exists('RtclStore\Helpers\Functions')) {
//                     return forward_static_call_array(
//                         ['RtclStore\Helpers\Functions', 'is_valid_to_post_at_category'], 
//                         [$cat_id]
//                     );
//                 }
//                 return true;
//             }

//             // CASE 1/2: New or wandering users - allow posting
//             return true;
//         }

//         // Helper to check subscription status
//         private static function has_active_subscription() {
//             if (!class_exists('RtclStore\Helpers\Functions')) {
//                 return false;
//             }
//             // Leverage original subscription check logic
//             $member = rtclStore()->factory->get_membership();
//             return ($member && !$member->is_expired());
//         }

//         // Proxy other method calls to original class
//         public static function __callStatic($name, $arguments) {
//             if (class_exists('RtclStore\Helpers\Functions')) {
//                 return forward_static_call_array(
//                     ['RtclStore\Helpers\Functions', $name], 
//                     $arguments
//                 );
//             }
//             return null;
//         }
//     }

//     // Register class alias early
//     class_alias('VOF\VOF_StoreFunctions', 'RtclStore\Helpers\Functions');

// }, 5);

// =============================
// ============================= do not delete below
// =============================

/*

Hook early to override membership verification
add_action('plugins_loaded', function() {
    // Remove the original verification hooks
    remove_action('rtcl_before_add_edit_listing_form', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']);
    remove_action('rtcl_listing_form_after_save_category', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category'], 10, 2);
    
    // Add our custom verification
    add_action('rtcl_before_add_edit_listing_form', function($post_id) {
        // Allow all users to proceed
        return;
    });
    
    add_action('rtcl_listing_form_after_save_category', function($post_id, $category_id) {
        // Skip verification for non-subscribed users
        if (!is_user_logged_in() || !VOF_Subscription::has_active_subscription()) {
            return;
        }
        
        // Use original verification for subscribed users
        if (class_exists('RtclStore\Controllers\Hooks\MembershipHook')) {
            \RtclStore\Controllers\Hooks\MembershipHook::verify_membership_into_category($post_id, $category_id);
        }
    }, 10, 2);
}, 0);

*/

/*

// Hook after plugins are loaded but before init
add_action('init', function() {
    // Remove the original verification hooks
   // remove_action('rtcl_before_add_edit_listing_form', 
   //     ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']);
   // remove_action('rtcl_listing_form_after_save_category', 
   //     ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category'], 10, 2);
    remove_action('rtcl_before_add_edit_listing_before_category_condition', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']);
    remove_action('rtcl_before_add_edit_listing_into_category_condition', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category'], 10, 2);





    // Add right after the remove_action calls
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('VOF: Attempting to remove membership hooks');
        error_log('VOF: verify_membership_before_category exists: ' . 
            (has_action('rtcl_before_add_edit_listing_before_category_condition', 
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']) ? 'yes' : 'no'));
        error_log('VOF: verify_membership_into_category exists: ' . 
            (has_action('rtcl_before_add_edit_listing_into_category_condition',
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category']) ? 'yes' : 'no'));
        }



    
    // Add our custom verification
    add_action('rtcl_before_add_edit_listing_before_category_condition', function($post_id) {
        // Allow all users to proceed
        return;
    });
    
    add_action('rtcl_before_add_edit_listing_into_category_condition', function($post_id, $category_id) {
        // Skip verification for non-subscribed users
        if (!is_user_logged_in() || !VOF_Subscription::has_active_subscription()) {
            return;
        }
        
        // Use original verification for subscribed users
        if (class_exists('RtclStore\Controllers\Hooks\MembershipHook')) {
            \RtclStore\Controllers\Hooks\MembershipHook::verify_membership_into_category($post_id, $category_id);
        }
    }, 10, 2);
}, 20); // Later priority

*/

/*

// Hook into plugins_loaded to catch the initialization
add_action('plugins_loaded', function() {
    // Remove hooks as soon as the MembershipHook class is loaded
    if (class_exists('RtclStore\Controllers\Hooks\MembershipHook')) {
        // Remove the original hooks before they're added
        remove_action('rtcl_before_add_edit_listing_before_category_condition', 
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']);
        remove_action('rtcl_before_add_edit_listing_into_category_condition', 
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category'], 10, 2);

        // Debug output
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('VOF: Early hook removal attempt');
            error_log('VOF: MembershipHook class exists: yes');
        }
    }
}, 5); // Early priority

// Also try at init, just in case
add_action('init', function() {
    // Remove the hooks again
    remove_action('rtcl_before_add_edit_listing_before_category_condition', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']);
    remove_action('rtcl_before_add_edit_listing_into_category_condition', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category'], 10, 2);

    // Add our custom verification
    add_action('rtcl_before_add_edit_listing_before_category_condition', function($post_id) {
        // Allow all users to proceed
        return;
    });
    
    add_action('rtcl_before_add_edit_listing_into_category_condition', function($post_id, $category_id) {
        // Skip verification for non-subscribed users
        if (!is_user_logged_in() || !VOF_Subscription::has_active_subscription()) {
            return;
        }
        
        // Use original verification for subscribed users
        if (class_exists('RtclStore\Controllers\Hooks\MembershipHook')) {
            \RtclStore\Controllers\Hooks\MembershipHook::verify_membership_into_category($post_id, $category_id);
        }
    }, 10, 2);

    // Debug output
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('VOF: Init hook removal attempt');
        error_log('VOF: verify_membership_before_category exists: ' . 
            (has_action('rtcl_before_add_edit_listing_before_category_condition', 
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']) ? 'yes' : 'no'));
        error_log('VOF: verify_membership_into_category exists: ' . 
            (has_action('rtcl_before_add_edit_listing_into_category_condition',
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category']) ? 'yes' : 'no'));
    }
}, 5); // Early priority

*/

/*

// Hook after MembershipHook adds its actions
add_action('init', function() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('VOF: Before removing hooks');
        error_log('VOF: verify_membership_before_category exists: ' . 
            (has_action('rtcl_before_add_edit_listing_before_category_condition', 
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']) ? 'yes' : 'no'));
    }

    // Remove the original verification hooks
    remove_action('rtcl_before_add_edit_listing_before_category_condition', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']);
    remove_action('rtcl_before_add_edit_listing_into_category_condition', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category'], 10, 2);

    // Add our custom verification
    add_action('rtcl_before_add_edit_listing_before_category_condition', function($post_id) {
        // Allow all users to proceed
        return;
    });
    
    add_action('rtcl_before_add_edit_listing_into_category_condition', function($post_id, $category_id) {
        // Skip verification for non-subscribed users
        if (!is_user_logged_in() || !VOF_Subscription::has_active_subscription()) {
            return;
        }
        
        // Use original verification for subscribed users
        if (class_exists('RtclStore\Controllers\Hooks\MembershipHook')) {
            \RtclStore\Controllers\Hooks\MembershipHook::verify_membership_into_category($post_id, $category_id);
        }
    }, 10, 2);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('VOF: After adding our hooks');
        error_log('VOF: verify_membership_before_category exists: ' . 
            (has_action('rtcl_before_add_edit_listing_before_category_condition', 
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']) ? 'yes' : 'no'));
    }
}, 999); // Much later priority to ensure we run after MembershipHook::init()

*/


/*

// Hook after MembershipHook adds its actions
add_action('init', function() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('VOF: Before removing hooks');
        error_log('VOF: verify_membership_before_category exists: ' . 
            (has_action('rtcl_before_add_edit_listing_before_category_condition', 
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']) ? 'yes' : 'no'));
        error_log('VOF: verify_membership_into_category exists: ' . 
            (has_action('rtcl_before_add_edit_listing_into_category_condition',
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category']) ? 'yes' : 'no'));
            
        // Debug the actual callbacks registered
        global $wp_filter;
        if (isset($wp_filter['rtcl_before_add_edit_listing_into_category_condition'])) {
            error_log('VOF: Callbacks for into_category_condition:');
            foreach($wp_filter['rtcl_before_add_edit_listing_into_category_condition']->callbacks as $priority => $callbacks) {
                foreach($callbacks as $id => $callback) {
                    error_log("Priority $priority: " . print_r($callback['function'], true));
                }
            }
        }
    }

    // Remove the original verification hooks
    remove_action('rtcl_before_add_edit_listing_before_category_condition', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']);
    remove_action('rtcl_before_add_edit_listing_into_category_condition', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category'], 10, 2);

    // Add our custom verification with higher priority
    add_action('rtcl_before_add_edit_listing_before_category_condition', function($post_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('VOF: Our before_category check running');
        }
        // Allow all users to proceed
        return;
    }, 5); // Higher priority
    
    add_action('rtcl_before_add_edit_listing_into_category_condition', function($post_id, $category_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('VOF: Our into_category check running');
        }
        // Skip verification for non-subscribed users
        if (!is_user_logged_in() || !VOF_Subscription::has_active_subscription()) {
            return;
        }
        
        // Use original verification for subscribed users
        if (class_exists('RtclStore\Controllers\Hooks\MembershipHook')) {
            \RtclStore\Controllers\Hooks\MembershipHook::verify_membership_into_category($post_id, $category_id);
        }
    }, 5, 2); // Higher priority

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('VOF: After adding our hooks');
        error_log('VOF: verify_membership_before_category exists: ' . 
            (has_action('rtcl_before_add_edit_listing_before_category_condition', 
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category']) ? 'yes' : 'no'));
        error_log('VOF: verify_membership_into_category exists: ' . 
            (has_action('rtcl_before_add_edit_listing_into_category_condition',
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category']) ? 'yes' : 'no'));
            
        // Debug the final callbacks
        if (isset($wp_filter['rtcl_before_add_edit_listing_into_category_condition'])) {
            error_log('VOF: Final callbacks for into_category_condition:');
            foreach($wp_filter['rtcl_before_add_edit_listing_into_category_condition']->callbacks as $priority => $callbacks) {
                foreach($callbacks as $id => $callback) {
                    error_log("Priority $priority: " . print_r($callback['function'], true));
                }
            }
        }
    }
}, 999);

*/

/*

// Hook after MembershipHook adds its actions
add_action('init', function() {
    // Add our custom verification with higher priority
    add_action('rtcl_before_add_edit_listing_before_category_condition', function($post_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('VOF: Our before_category check running');
        }
        // Allow all users to proceed
        return false; // Return false to prevent original hook from running
    }, 1); // Highest priority
    
    add_action('rtcl_before_add_edit_listing_into_category_condition', function($post_id, $category_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('VOF: Our into_category check running');
        }
        // Skip verification for non-subscribed users
        if (!is_user_logged_in() || !VOF_Subscription::has_active_subscription()) {
            return false; // Return false to prevent original hook from running
        }
        
        // Let original verification run for subscribed users
        return true;
    }, 1, 2); // Highest priority

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('VOF: Hooks added with priority 1');
        
        // Debug the final callbacks
        global $wp_filter;
        if (isset($wp_filter['rtcl_before_add_edit_listing_into_category_condition'])) {
            error_log('VOF: Final callbacks for into_category_condition:');
            foreach($wp_filter['rtcl_before_add_edit_listing_into_category_condition']->callbacks as $priority => $callbacks) {
                foreach($callbacks as $id => $callback) {
                    error_log("Priority $priority: " . print_r($callback['function'], true));
                }
            }
        }
    }
}, 999);

*/


/*

// Hook after MembershipHook adds its actions
add_action('wp_loaded', function() {
    // Add our custom verification with higher priority
    add_action('rtcl_before_add_edit_listing_before_category_condition', function($post_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('VOF: Our before_category check running');
        }
        // Allow all users to proceed
        return true;
    }, 1); // Highest priority
    
    add_action('rtcl_before_add_edit_listing_into_category_condition', function($post_id, $category_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('VOF: Our into_category check running');
        }
        // Skip verification for non-subscribed users
        if (!is_user_logged_in() || !VOF_Subscription::has_active_subscription()) {
            return true;
        }
        
        // Let original verification run for subscribed users
        return null;
    }, 1, 2); // Highest priority

    // Remove the original hooks after we've added ours
    remove_action('rtcl_before_add_edit_listing_before_category_condition', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category'], 10);
    remove_action('rtcl_before_add_edit_listing_into_category_condition', 
        ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category'], 10, 2);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('VOF: Hooks modified on wp_loaded');
        
        // Debug the final callbacks
        global $wp_filter;
        if (isset($wp_filter['rtcl_before_add_edit_listing_into_category_condition'])) {
            error_log('VOF: Final callbacks for into_category_condition:');
            foreach($wp_filter['rtcl_before_add_edit_listing_into_category_condition']->callbacks as $priority => $callbacks) {
                foreach($callbacks as $id => $callback) {
                    error_log("Priority $priority: " . print_r($callback['function'], true));
                }
            }
        }
    }
});

*/


// Use plugins_loaded instead of wp_loaded
add_action('plugins_loaded', function() {
    // Add a static flag to prevent duplicate registration
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    // Remove existing hooks if they exist
    if (class_exists('RtclStore\Controllers\Hooks\MembershipHook')) {
        remove_action('rtcl_before_add_edit_listing_before_category_condition', 
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_before_category'], 10);
        
        remove_action('rtcl_before_add_edit_listing_into_category_condition',
            ['RtclStore\Controllers\Hooks\MembershipHook', 'verify_membership_into_category'], 10);
    }

    // Add our custom verification with specific priority
    add_action('rtcl_before_add_edit_listing_before_category_condition', function($post_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('VOF: Our before_category check running');
        }
        // Allow all users to proceed
        return true;
    }, 1);
    
    add_action('rtcl_before_add_edit_listing_into_category_condition', function($post_id, $category_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('VOF: Our into_category check running');
        }
        // Skip verification for non-subscribed users
        return true;
    }, 1, 2);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('VOF: Hooks modified on plugins_loaded');
        
        // Debug the final callbacks
        global $wp_filter;
        if (isset($wp_filter['rtcl_before_add_edit_listing_into_category_condition'])) {
            error_log('VOF: Final callbacks for into_category_condition:');
            foreach($wp_filter['rtcl_before_add_edit_listing_into_category_condition']->callbacks as $priority => $callbacks) {
                foreach($callbacks as $id => $callback) {
                    error_log("Priority $priority: " . print_r($callback['function'], true));
                }
            }
        }
    }
}, 20); // Higher priority to ensure other plugins are loaded