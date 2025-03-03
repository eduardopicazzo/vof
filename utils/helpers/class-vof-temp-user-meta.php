<?php
namespace VOF\Utils\Helpers;
// path: wp-content/plugins/vendor-onboarding-flow/utils/helpers/class-vof-temp-user-meta.php
class VOF_Temp_User_Meta {
    private static $instance = null;
    private $table_name;
    private static $table_created = false;

    public static function vof_get_temp_user_meta_instance() {
       if (self::$instance === null) {
           self::$instance = new self();
       }
       return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'vof_temp_user_meta';
        if (!self::$table_created) {
            $this->vof_maybe_create_table();
            $this->vof_maybe_create_custom_meta_table();
            self::$table_created = true;
        }
    }

    private function vof_maybe_create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
    
        // Updated schema with normalized columns
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table_name}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `uuid` varchar(36) NOT NULL,
            `post_id` bigint(20) unsigned NOT NULL,
            `vof_email` varchar(255) NOT NULL,
            `vof_phone` varchar(20) NOT NULL,
            `vof_whatsapp` varchar(20) DEFAULT NULL,
            `post_status` varchar(20) NOT NULL DEFAULT 'vof_temp',
            `vof_tier` varchar(50) DEFAULT NULL,
            `post_parent_cat` bigint(20) unsigned NOT NULL,
            `user_type` enum('returning', 'newcomer') DEFAULT NULL,
            `true_user_id` bigint(20) unsigned DEFAULT NULL,
            `password` varchar(255) DEFAULT NULL,
            `vof_flow_status` enum('started', 'completed') DEFAULT NULL,
            -- old modified columns
            `vof_flow_started_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `vof_flow_completed_at` datetime DEFAULT NULL,
            `vof_flow_time_elapsed` int(11) DEFAULT NULL,
            -- new columns
            `stripe_user_name` varchar(255) DEFAULT NULL,
            `stripe_customer_id` varchar(255) DEFAULT NULL,
            `stripe_sub_id` varchar(255) DEFAULT NULL,
            `stripe_sub_status` varchar(50) DEFAULT NULL,
            `stripe_sub_start_date` datetime DEFAULT NULL,
            `stripe_sub_expiry_date` datetime DEFAULT NULL,
            `stripe_prod_name` varchar(255) DEFAULT NULL,
            `stripe_prod_lookup_key` varchar(255) DEFAULT NULL,
            `stripe_period_interval` varchar(50) DEFAULT NULL,
            `price_purchased_at` int(11) DEFAULT NULL,
            -- `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            -- `expires_at` datetime,
            -- `days_elapsed` int(11),
            PRIMARY KEY (`uuid`),
            UNIQUE KEY `id` (`id`),
            KEY `post_id` (`post_id`),
            KEY `post_parent_cat` (`post_parent_cat`),
            -- KEY `expires_at` (`expires_at`),
            KEY `true_user_id` (`true_user_id`)
        ) $charset_collate;";
    
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Create trigger to update days_elapsed
        // Drop existing trigger if it exists
        // $wpdb->query("DROP TRIGGER IF EXISTS update_days_elapsed");
        
        // Create new trigger
        // $trigger_sql = "CREATE TRIGGER update_days_elapsed 
        //     BEFORE INSERT ON {$this->table_name}
        //     FOR EACH ROW
        //     SET NEW.days_elapsed = DATEDIFF(NOW(), NEW.created_at)";
        
        // $wpdb->query($trigger_sql);
    }

    /**
     * Create custom meta table for flexible data storage
     */
    private function vof_maybe_create_custom_meta_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $custom_meta_table = $wpdb->prefix . 'vof_custom_meta';

        $sql = "CREATE TABLE IF NOT EXISTS `{$custom_meta_table}` (
            `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `uuid` varchar(36) NOT NULL,
            `meta_key` varchar(255) NOT NULL,
            `meta_value` longtext,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`meta_id`),
            KEY `uuid` (`uuid`),
            KEY `meta_key` (`meta_key`(191))
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // START GETTERS / SETTERS: for custom_meta table

/**
 * Add custom meta for a user
 * 
 * @param string $uuid The user UUID
 * @param string $key The meta key
 * @param mixed $value The meta value
 * @return bool Success or failure
 */
public function vof_add_custom_meta($uuid, $key, $value) {
    global $wpdb;
    $custom_meta_table = $wpdb->prefix . 'vof_custom_meta';
    
    // Serialize arrays and objects
    if (is_array($value) || is_object($value)) {
        $value = maybe_serialize($value);
    }
    
    $result = $wpdb->insert(
        $custom_meta_table,
        [
            'uuid' => $uuid,
            'meta_key' => $key,
            'meta_value' => $value
        ],
        ['%s', '%s', '%s']
    );
    
    return $result !== false;
}

/**
 * Get custom meta for a user
 * 
 * @param string $uuid The user UUID
 * @param string $key The meta key
 * @param bool $single Whether to return a single value
 * @return mixed The meta value
 */
public function vof_get_custom_meta($uuid, $key, $single = true) {
    global $wpdb;
    $custom_meta_table = $wpdb->prefix . 'vof_custom_meta';
    
    if ($single) {
        $query = $wpdb->prepare(
            "SELECT meta_value FROM {$custom_meta_table} WHERE uuid = %s AND meta_key = %s LIMIT 1",
            $uuid,
            $key
        );
        
        $result = $wpdb->get_var($query);
        return maybe_unserialize($result);
    } else {
        $query = $wpdb->prepare(
            "SELECT meta_value FROM {$custom_meta_table} WHERE uuid = %s AND meta_key = %s",
            $uuid,
            $key
        );
        
        $results = $wpdb->get_col($query);
        return array_map('maybe_unserialize', $results);
    }
}

/**
 * Update custom meta for a user
 * 
 * @param string $uuid The user UUID
 * @param string $key The meta key
 * @param mixed $value The meta value
 * @return bool Success or failure
 */
public function vof_update_custom_meta($uuid, $key, $value) {
    global $wpdb;
    $custom_meta_table = $wpdb->prefix . 'vof_custom_meta';
    
    // Check if meta key exists
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$custom_meta_table} WHERE uuid = %s AND meta_key = %s",
            $uuid,
            $key
        )
    );
    
    // Serialize arrays and objects
    if (is_array($value) || is_object($value)) {
        $value = maybe_serialize($value);
    }
    
    if ($exists) {
        $result = $wpdb->update(
            $custom_meta_table,
            ['meta_value' => $value],
            ['uuid' => $uuid, 'meta_key' => $key],
            ['%s'],
            ['%s', '%s']
        );
    } else {
        $result = $this->vof_add_custom_meta($uuid, $key, $value);
    }
    
    return $result !== false;
}

/**
 * Delete custom meta for a user
 * 
 * @param string $uuid The user UUID
 * @param string $key The meta key
 * @return bool Success or failure
 */
public function vof_delete_custom_meta($uuid, $key) {
    global $wpdb;
    $custom_meta_table = $wpdb->prefix . 'vof_custom_meta';
    
    $result = $wpdb->delete(
        $custom_meta_table,
        ['uuid' => $uuid, 'meta_key' => $key],
        ['%s', '%s']
    );
    
    return $result !== false;
}

// END SETTERS: for custom_meta table

    // GETTERS
    public function vof_get_temp_user_by_uuid($uuid) {
        global $wpdb;
        // WHERE uuid = %s AND expires_at > NOW()",
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                WHERE uuid = %s",
                $uuid
            ),
            ARRAY_A
        );

        if (!$result) {
            error_log('VOF Debug: No temp user found for UUID: ' . $uuid);
            return false;
        }

        return $result;
    }

    public function vof_get_email_by_uuid($uuid) {
        global $wpdb;
        
        // WHERE uuid = %s AND expires_at > NOW()",
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT vof_email FROM {$this->table_name} 
                WHERE uuid = %s",
                $uuid
            )
        );
    
        if ($result === null) {
            error_log('VOF Debug: No email found for UUID: ' . $uuid);
            return false;
        }
    
        return $result;
    }
    
    public function vof_get_phone_by_uuid($uuid) {
        global $wpdb;
        // WHERE uuid = %s AND expires_at > NOW()",
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT vof_phone FROM {$this->table_name} 
                WHERE uuid = %s",
                $uuid
            )
        );
    
        if ($result === null) {
            error_log('VOF Debug: No phone found for UUID: ' . $uuid);
            return false;
        }
    
        return $result;
    }
    
    public function vof_get_whatsapp_by_uuid($uuid) {
        global $wpdb;
        // WHERE uuid = %s AND expires_at > NOW()",
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT vof_whatsapp FROM {$this->table_name} 
                WHERE uuid = %s",
                $uuid
            )
        );
    
        return $result; // Can be null/false as whatsapp is optional
    }
    
    public function vof_get_post_status_by_uuid($uuid) {
        global $wpdb;
        // WHERE uuid = %s AND expires_at > NOW()",
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_status FROM {$this->table_name} 
                WHERE uuid = %s",
                $uuid
            )
        );
    
        if ($result === null) {
            error_log('VOF Debug: No status found for UUID: ' . $uuid);
            return false;
        }
    
        return $result;
    }
    
    public function vof_get_tier_by_uuid($uuid) {
        global $wpdb;
        // WHERE uuid = %s AND expires_at > NOW()",
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT vof_tier FROM {$this->table_name} 
                WHERE uuid = %s",
                $uuid
            )
        );
    
        return $result; // Can be null as tier might not be set yet
    }
    
    public function vof_get_parent_cat_by_uuid($uuid) {
        global $wpdb;
        // WHERE uuid = %s AND expires_at > NOW()",
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_parent_cat FROM {$this->table_name} 
                WHERE uuid = %s",
                $uuid
            )
        );
    
        if ($result === null) {
            error_log('VOF Debug: No parent category found for UUID: ' . $uuid);
            return false;
        }
    
        return absint($result);
    }
    
    public function vof_get_post_id_by_uuid($uuid) {
        global $wpdb;
        // WHERE uuid = %s AND expires_at > NOW()",
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$this->table_name} 
                WHERE uuid = %s",
                $uuid
            )
        );
    
        if ($result === null) {
            error_log('VOF Debug: No post ID found for UUID: ' . $uuid);
            return false;
        }
    
        return absint($result);
    }
    
    public function vof_get_flow_started_at_by_uuid($uuid) {
        global $wpdb;
        // WHERE uuid = %s AND expires_at > NOW()",
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT vof_flow_started_at FROM {$this->table_name} 
                WHERE uuid = %s",
                $uuid
            )
        );
    
        if ($result === null) {
            error_log('VOF Debug: No creation date found for UUID: ' . $uuid);
            return false;
        }
    
        return $result;
    }
    
    public function vof_get_expires_at_by_uuid($uuid) {
        global $wpdb;
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT expires_at FROM {$this->table_name} 
                WHERE uuid = %s",
                $uuid
            )
        );
    
        if ($result === null) {
            error_log('VOF Debug: No expiration date found for UUID: ' . $uuid);
            return false;
        }
    
        return $result;
    }

    public function vof_get_temp_user_by_post_id($post_id) {
        global $wpdb;
        // WHERE post_id = %d AND expires_at > NOW()",

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                WHERE post_id = %d",
                $post_id
            ),
            ARRAY_A
        );

        if (!$result) {
            error_log('VOF Debug: No temp user found for post_id: ' . $post_id);
            return false;
        }

        return $result;
    }

    public function vof_get_table_name() {
        return $this->table_name;
    }

    public function vof_get_create_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE IF NOT EXISTS `{$this->table_name}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `uuid` varchar(36) NOT NULL,
            `post_id` bigint(20) unsigned NOT NULL,
            `vof_email` varchar(255) NOT NULL,
            `vof_phone` varchar(20) NOT NULL,
            `vof_whatsapp` varchar(20) DEFAULT NULL,
            `post_status` varchar(20) NOT NULL DEFAULT 'vof_temp',
            `vof_tier` varchar(50) DEFAULT NULL,
            `post_parent_cat` bigint(20) unsigned NOT NULL,
            `user_type` enum('returning', 'newcomer') DEFAULT NULL,
            `true_user_id` bigint(20) unsigned DEFAULT NULL,
            `password` varchar(255) DEFAULT NULL,
            `vof_flow_status` enum('started', 'completed') DEFAULT NULL,
            -- old modified columns
            `vof_flow_started_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `vof_flow_completed_at` datetime DEFAULT NULL,
            `vof_flow_time_elapsed` int(11) DEFAULT NULL,
            -- new columns
            `stripe_user_name` varchar(255) DEFAULT NULL,
            `stripe_customer_id` varchar(255) DEFAULT NULL,
            `stripe_sub_id` varchar(255) DEFAULT NULL,
            `stripe_sub_status` varchar(50) DEFAULT NULL,
            `stripe_sub_start_date` datetime DEFAULT NULL,
            `stripe_sub_expiry_date` datetime DEFAULT NULL,            
            `stripe_prod_name` varchar(255) DEFAULT NULL,
            `stripe_prod_lookup_key` varchar(255) DEFAULT NULL,
            `stripe_period_interval` varchar(50) DEFAULT NULL,
            `price_purchased_at` int(11) DEFAULT NULL,
            -- `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            -- `expires_at` datetime,
            -- `days_elapsed` int(11),
            PRIMARY KEY (`uuid`),
            UNIQUE KEY `id` (`id`),
            KEY `post_id` (`post_id`),
            KEY `post_parent_cat` (`post_parent_cat`),
            -- KEY `expires_at` (`expires_at`),
            KEY `true_user_id` (`true_user_id`)
        ) $charset_collate;";

        // Create trigger to update days_elapsed
        // Drop existing trigger if it exists
        // $wpdb->query("DROP TRIGGER IF EXISTS update_days_elapsed");
        
        // // Create new trigger
        // $trigger_sql = "CREATE TRIGGER update_days_elapsed 
        //     BEFORE INSERT ON {$this->table_name}
        //     FOR EACH ROW
        //     SET NEW.days_elapsed = DATEDIFF(NOW(), NEW.created_at)";
        
        // $wpdb->query($trigger_sql);
    }

    public function vof_get_all_records() {
        global $wpdb;
    
        $query = "SELECT * FROM {$this->table_name} ORDER BY created_at DESC";
        error_log('VOF Debug: Running query: ' . $query);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (!$results) {
            error_log('VOF Debug: No records found or error occurred. MySQL Error: ' . $wpdb->last_error);
            return [];
        }
    
        error_log('VOF Debug: Found ' . count($results) . ' records');
        return $results;
    }

    // SETTERS / MODIFIERS
    public function vof_update_tier($uuid, $tier) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            ['vof_tier' => $tier],
            ['uuid' => $uuid],
            ['%s'],
            ['%s']
        );

        if ($result === false) {
            error_log('VOF Debug: Failed to update tier for UUID: ' . $uuid);
            return false;
        }

        return true;
    }

    public function vof_update_post_status_OLD($uuid, $status) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            ['post_status' => $status],
            ['uuid' => $uuid],
            ['%s'],
            ['%s']
        );

        if ($result === false) {
            error_log('VOF Debug: Failed to update post status for UUID: ' . $uuid);
            return false;
        }

        return true;
    }

    public function vof_update_post_status($uuid, $vof_updated_data) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            $vof_updated_data,
            array('uuid' => $uuid),
            array(
                '%s',   // vof_flow_status
                '%s',   // vof_flow_completed_at
                '%d',   // vof_flow_time_elapsed
                '%s',   // stripe_user_name
                '%s',   // stripe_customer_id
                '%s',   // stripe_sub_id
                '%s',   // stripe_sub_status
                '%s',   // stripe_prod_name
                '%s',   // stripe_prod_lookup_key
                '%s',   // stripe_period_interval
                '%d',   // price_purchased_at
                '%s',   // stripe_sub_start_date
                '%s',   // stripe_sub_expiry_date
            ),
            array('%s') // uuid format
        );

        if ($result === false) {
            error_log('VOF Debug: Failed to update credentials for UUID: ' . $uuid);
            return false;
        }

        return true;
    }

    public function vof_create_test_record() {
        $post_id = 123; // Test post ID
        $data = array(
            'vof_email' => 'test@example.com',
            'vof_phone' => '1234567890',
            'vof_whatsapp_number' => '1234567890',
            'post_parent_cat' => 1,
            'post_status' => 'vof_temp',
            'vof_tier' => null  // Optional but good to include
        );
    
        error_log('VOF Debug: Creating test record with data: ' . print_r($data, true));
        
        $uuid = $this->vof_create_temp_user($post_id, $data);
        
        if ($uuid) {
            error_log('VOF Debug: Created test record with UUID: ' . $uuid);
        } else {
            error_log('VOF Debug: Failed to create test record');
        }
        
        return $uuid;
    }

    public function vof_create_temp_user($post_id, $data) {
        global $wpdb;

        // Generate UUID v4
        $uuid = wp_generate_uuid4();

        // Set expiration (3 days from now) 
        $expires_at = date('Y-m-d H:i:s', strtotime('+3 days'));

        // Insert data into normalized columns
        $result = $wpdb->insert(
            $this->table_name, 
            array( 
                'uuid' => $uuid,
                'post_id' => $post_id,
                'vof_email' => $data['vof_email'],
                'vof_phone' => $data['vof_phone'],
                'vof_whatsapp' => $data['vof_whatsapp_number'] ?? null,
                'post_status' => $data['post_status'],
                'vof_tier' => $data['vof_tier'],
                'post_parent_cat' => $data['post_parent_cat'] ?? 0,
                // 'expires_at' => $expires_at
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d')
            // array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );

        if ($result === false) {
            error_log('VOF Debug: Failed to create temp user. MySQL Error: ' . $wpdb->last_error);
            return false;
        }

        return $uuid;
    }

    public function vof_set_temp_user_data_credentials_by_uuid($uuid, $temp_data) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            array(
                'user_type'       => $temp_data['user_type'],
                'true_user_id'    => $temp_data['true_user_id'],
                'password'        => $temp_data['password'],
                'vof_flow_status' => $temp_data['vof_flow_status']
            ),
            array('uuid' => $uuid),
            array('%s', '%d', '%s', '%s'),
            array('%s')
        );

        if ($result === false) {
            error_log('VOF Debug: Failed to update credentials for UUID: ' . $uuid);
            return false;
        }

        return true;
    }

    public function vof_delete_expired_data() {
        global $wpdb;

        $result = $wpdb->query(
            "DELETE FROM {$this->table_name} WHERE expires_at <= NOW()"
        );

        if ($result === false) {
            error_log('VOF Debug: Failed to delete expired data. MySQL Error: ' . $wpdb->last_error);
        }

        return $result !== false;
    }
}