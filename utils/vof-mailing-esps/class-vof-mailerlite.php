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

        $this->api_key = $this->vof_get_api_key();
        $this->vof_init_client();
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
            
            // Create or update subscriber
            $response = $this->api_client->subscribers->create($subscriber_data);
            
            // Add to groups if needed
            if (!empty($groups) && isset($response['data']['id'])) {
                foreach ($groups as $group_id) {
                    $this->api_client->groups->addSubscriber($group_id, $subscriber_data);
                }
            }
            
            return $response;
            
        } catch (\Exception $e) {
            error_log('VOF Error: Failed to add subscriber to MailerLite - ' . $e->getMessage());
            return false;
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
}