<?php

namespace VOF;

class VOF_Dependencies {
    private static $required_plugins = [
        'classified-listing/classified-listing.php' => 'Classified Listing',
        'classified-listing-pro/classified-listing-pro.php' => 'Classified Listing Pro',
        'classified-listing-store/classified-listing-store.php' => 'Classified Listing Store',
        'classima-core/classima-core.php' => 'Classima Core',
        'rt-framework/rt-framework.php' => 'RT Framework',
        'review-schema/review-schema.php' => 'Review Schema',
        'review-schema-pro/review-schema-pro.php' => 'Review Schema Pro',
        'contact-form-7/wp-contact-form-7.php' => 'Contact Form 7'
    ];

    public static function check() {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Add store functions check
        add_action('admin_notices', function() {
            if (class_exists('RtclStore\Helpers\Functions') && 
                !is_subclass_of('RtclStore\Helpers\Functions', '\VOF\VOF_Store_Functions')) {
                echo '<div class="notice notice-error"><p>' . 
                     __('VOF: Store Functions override failed to load properly.', 'vendor-onboarding-flow') . 
                     '</p></div>';
            }
        });

        foreach (self::$required_plugins as $plugin => $name) {
            if (!is_plugin_active($plugin)) {
                 add_action('admin_notices', function() use ($name) {
                     echo '<div class="notice notice-error"><p>' . sprintf(
                         __('The %s plugin is required for Vendor Onboarding Flow to work.', 'vendor-onboarding-flow'), 
                         $name
                     ) . '</p></div>';
                });
            }
        }
    }      
}

// Initialize immediately
VOF_Dependencies::check();

    // public static function check() {
    //     foreach (self::$required_plugins as $plugin => $name) {
    //         if (!is_plugin_active($plugin)) {
    //             add_action('admin_notices', function() use ($name) {
    //                 echo '<div class="notice notice-error"><p>' . sprintf(__('The %s plugin is required for Vendor Onboarding Flow to work.', 'vendor-onboarding-flow'), $name) . '</p></div>';
    //             });
    //         }
    //     }
    // }
