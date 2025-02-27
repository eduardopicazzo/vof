<?php
/**
 * MailerLite Integration for Vendor Onboarding Flow
 *
 * @package VOF
 * @subpackage Utils\MailingESPs
 */

namespace VOF\Utils\MailingESPs;

use MailerLite\MailerLite;

/**
 * Class VOF_MailerLite
 * 
 * Handles integration with MailerLite API for email marketing functionality.
 */
class VOF_MailerLite {
    /**
     * MailerLite API client instance
     *
     * @var MailerLite
     */
    private $api_client = null;
    
    /**
     * API key for MailerLite
     *
     * @var string
     */
    private $api_key = '';
    
    /**
     * Singleton instance
     *
     * @var VOF_MailerLite
     */
    private static $instance = null;
    
    /**
     * Get singleton instance
     *
     * @return VOF_MailerLite
     */
    public static function vof_get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Check if the MailerLite SDK is available
        if (!class_exists('\\MailerLite\\MailerLite')) {
            error_log('VOF Error: MailerLite SDK not found. Please run composer require mailerlite/mailerlite-php');
            return;
        }

        // Check if the integration is enabled
        if (!get_option('vof_mailerlite_enabled', false)) {
            error_log('VOF Debug: MailerLite integration is disabled in settings');
            return;
        }

        $this->api_key = $this->vof_get_api_key();
        $this->vof_init_client();

        // Call debug method to log available methods. (only in development debugging environments)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->vof_debug_available_methods();
        }
    }
    
    /**
     * Get API key from WordPress options
     *
     * @return string
     */
    private function vof_get_api_key() {
        return get_option('vof_mailerlite_api_key', '');
    }
    
    /**
     * Initialize the MailerLite API client
     *
     * @return void
     */
    private function vof_init_client() {
        if (empty($this->api_key)) {
            error_log('VOF Error: MailerLite API key not configured');
            return;
        }
        
        try {
            $this->api_client = new MailerLite([
                'api_key' => $this->api_key,
            ]);
        } catch (\Exception $e) {
            error_log('VOF Error: Failed to initialize MailerLite client - ' . $e->getMessage());
        }
    }
    
    /**
     * Check if MailerLite client is properly initialized
     *
     * @return bool
     */
    public function vof_is_connected() {
        // First check if the integration is enabled
        if (!get_option('vof_mailerlite_enabled', false)) {
            return false;
        }
                
        return ($this->api_client !== null);
    }

/**
 * Add or update subscriber in MailerLite
 *
 * @param string $email      Subscriber email
 * @param array  $fields     Additional fields like name, etc.
 * @param array  $groups     Groups to add subscriber to
 * @return bool|array        True on success or response data
 */
public function vof_add_subscriber($email, $fields = [], $groups = []) {
    if (!$this->vof_is_connected()) {
        error_log('VOF Error: Cannot add subscriber - MailerLite not connected');
        return false;
    }

    try {
        $subscriber_data = [
            'email' => $email,
            'fields' => $fields
        ];
        
        // First, create or update the subscriber in the main list
        $response = $this->api_client->subscribers->create($subscriber_data);
        error_log('VOF Debug: Subscriber create response: ' . print_r($response, true));
        
        // Now assign the subscriber to each group
        if (!empty($groups)) {
            foreach ($groups as $group_id) {
                try {
                    // FIXED: The method expects the email directly as a string, not in an array
                    $group_response = $this->api_client->groups->assignSubscriber($group_id, $email);
                    error_log('VOF Debug: Group assignment response for group ' . $group_id . ': ' . print_r($group_response, true));
                } catch (\Exception $e) {
                    error_log('VOF Warning: Failed to assign subscriber to group ' . $group_id . ' - ' . $e->getMessage());
                }
            }
        }
        
        return $response;
    } catch (\Exception $e) {
        error_log('VOF Error: Failed to add/update subscriber - ' . $e->getMessage());
        return false;
    }
}


/**
 * Debug group assignment
 * 
 * @param string $email Email to assign
 * @param string $group_id Group ID to assign to
 */
public function vof_debug_group_assignment($email, $group_id) {
    if (!$this->vof_is_connected()) {
        error_log('VOF Error: Cannot debug group assignment - MailerLite not connected');
        return;
    }
    
    try {
        // First check if the subscriber exists
        $subscriber_response = $this->api_client->subscribers->find($email);
        error_log('VOF Debug: Subscriber find response: ' . print_r($subscriber_response, true));
        
        // Then try to assign to group - passing email directly, not in an array
        $group_response = $this->api_client->groups->assignSubscriber($group_id, $email);
        error_log('VOF Debug: Group assignment response: ' . print_r($group_response, true));
        
        return $group_response;
    } catch (\Exception $e) {
        error_log('VOF Error: Debug group assignment failed - ' . $e->getMessage());
        error_log('VOF Error: Exception trace: ' . $e->getTraceAsString());
        return false;
    }
}

/**
 * Debug helper to check available methods
 */
private function vof_debug_available_methods() {
    if (!$this->vof_is_connected()) {
        return;
    }
    
    try {
        // Get a reflection of the groups class
        $reflection = new \ReflectionClass($this->api_client->groups);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        
        $method_names = array_map(function($method) {
            return $method->getName();
        }, $methods);
        
        error_log('VOF Debug: Available Group methods: ' . print_r($method_names, true));
    } catch (\Exception $e) {
        error_log('VOF Debug: Could not get available methods - ' . $e->getMessage());
    }
}

    /**
     * Get all subscriber groups
     *
     * @return array|bool    Array of groups or false on failure
     */
    public function vof_get_groups() {
        if (!$this->vof_is_connected()) {
            error_log('VOF Error: Cannot get groups - MailerLite not connected');
            return false;
        }

        try {
            $response = $this->api_client->groups->get();

            // Log the response structure for debugging
            error_log('VOF Debug: MailerLite raw groups response: ' . json_encode(array_keys($response)));

            return $response;
        } catch (\Exception $e) {
            error_log('VOF Error: Failed to get MailerLite groups - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Add tag to subscriber
     *
     * @param string $email  Subscriber email
     * @param array  $tags   Tags to add
     * @return bool|array    True on success or response data
     */
    public function vof_add_tags($email, $tags = []) {
        if (!$this->vof_is_connected() || empty($tags)) {
            return false;
        }
        
        try {
            return $this->api_client->subscribers->addTags($email, $tags);
        } catch (\Exception $e) {
            error_log('VOF Error: Failed to add tags in MailerLite - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle onboarding flow completion
     * 
     * @param array $user_data User data including email and other details
     * @param string $tier     Subscription tier
     * @return bool            Success status
     */
    public function vof_handle_onboarding_complete($user_data, $tier) {
        if (!$this->vof_is_connected() || empty($user_data['email'])) {
            return false;
        }
        
        $fields = [
            'name' => isset($user_data['first_name']) && isset($user_data['last_name']) 
                ? $user_data['first_name'] . ' ' . $user_data['last_name'] 
                : '',
            'phone' => $user_data['phone'] ?? '',
            'subscription_tier' => $tier,
            'onboarding_complete' => 'yes',
            'onboarding_date' => date('Y-m-d H:i:s')
        ];
        
        // Add to 'Completed Onboarding' group (you'd need to set this group ID in settings)
        $onboarding_group = get_option('vof_mailerlite_onboarding_group', '');
        $groups = $onboarding_group ? [$onboarding_group] : [];
        
        // Add tags based on tier
        $this->vof_add_subscriber($user_data['email'], $fields, $groups);
        $this->vof_add_tags($user_data['email'], ['vendor', 'tier_' . strtolower($tier)]);
        
        return true;
    }
    
    /**
     * Save API key to WordPress options
     *
     * @param string $api_key MailerLite API key
     * @return bool Success status
     */
    public function vof_save_api_key($api_key) {
        if (update_option('vof_mailerlite_api_key', $api_key)) {
            $this->api_key = $api_key;
            $this->vof_init_client();
            return true;
        }
        return false;
    }

/**
 * Ensure a group exists in MailerLite, create if it doesn't
 * 
 * @param string $name Group name
 * @return string|bool Group ID or false on failure
 */
public function vof_ensure_group_exists($name) {
    if (!$this->vof_is_connected()) {
        error_log('VOF Error: Cannot ensure group exists - MailerLite not connected');
        return false;
    }

    try {
        // First check if group already exists
        $groups = $this->vof_get_groups();
        error_log('VOF Debug: Checking if group exists: ' . $name);
        error_log('VOF Debug: Groups response: ' . print_r($groups, true));
        
        if ($groups && isset($groups['body']['data'])) {
            foreach ($groups['body']['data'] as $group) {
                if ($group['name'] === $name) {
                    error_log('VOF Debug: Found existing group: ' . $name . ' with ID: ' . $group['id']);
                    return $group['id'];
                }
            }
        }
        
        // Group not found, create it
        error_log('VOF Debug: Creating new group: ' . $name);
        $response = $this->api_client->groups->create(['name' => $name]);
        error_log('VOF Debug: Group creation response: ' . print_r($response, true));
        
        if (isset($response['body']['id'])) {
            return $response['body']['id'];
        }
        
        return false;
    } catch (\Exception $e) {
        error_log('VOF Error: Failed to ensure group exists - ' . $e->getMessage());
        return false;
    }
}

/**
 * Remove a subscriber from a group
 *
 * @param string $email  Subscriber email
 * @param string $group_id Group ID
 * @return bool|array    True on success or response data
 */
public function vof_remove_subscriber_from_group($email, $group_id) {
    if (!$this->vof_is_connected()) {
        error_log('VOF Error: Cannot remove subscriber from group - MailerLite not connected');
        return false;
    }
    
    try {
        // Pass email as a string, not an array
        $response = $this->api_client->groups->unAssignSubscriber($group_id, $email);
        return $response;
    } catch (\Exception $e) {
        error_log('VOF Error: Failed to remove subscriber from group - ' . $e->getMessage());
        return false;
    }
}

}