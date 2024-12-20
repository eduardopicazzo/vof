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

        // Fallback to custom subscription logic
        return get_user_meta($user_id, 'vof_subscription_status', true) === 'active';
    }
}
