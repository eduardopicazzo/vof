<?php
namespace VOF;

use RtclStore\Helpers\Functions as StoreFunctions;

class VOF_Store_Functions extends StoreFunctions {
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;

        // Force our methods to be used
        if (!method_exists('RtclStore\Helpers\Functions', 'is_valid_to_post_at_category')) {
            class_alias(__CLASS__, 'RtclStore\Helpers\Functions');
        }
    }

    public static function is_valid_to_post_at_category(int $cat_id): bool {
        // Add debugging
        error_log('VOF: Category validation called for cat_id: ' . $cat_id);
        
        
        // CASE 3: User with active subscription - use original validation
        if (is_user_logged_in() && class_exists('\VOF\VOF_Subscription') && VOF_Subscription::has_active_subscription()) {
            return parent::is_valid_to_post_at_category($cat_id);
        }

        // CASE 1 & 2: Non-logged users or users without subscription
        // Always return true during initial form load
        return true;
    }
}

// Initialize immediately
VOF_Store_Functions::init();