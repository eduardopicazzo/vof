<?php
namespace VOF\Utils\Helpers;

class VOF_Temp_User_Meta {
    private static $instance = null;
    private $table_name;

    public static function vof_get_temp_user_meta_instance() {
       if (self::$instance === null) {
           self::$instance = new self();
       }
       return self::$instance;
    }
    
    private function __construct() {
       global $wpdb;
       $this->table_name = $wpdb->prefix . 'vof_temp_user_meta';
       $this->vof_maybe_create_table();
    }

    private function vof_maybe_create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
    
        // Updated schema with normalized columns
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table_name}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `uuid` char(36) NOT NULL,
            `post_id` bigint(20) unsigned NOT NULL,
            `vof_email` varchar(255) NOT NULL,
            `vof_phone` varchar(20) NOT NULL,
            `vof_whatsapp` varchar(20) DEFAULT NULL,
            `post_status` varchar(20) NOT NULL DEFAULT 'vof_temp',
            `vof_tier` varchar(50) DEFAULT NULL,
            `post_parent_cat` bigint(20) unsigned NOT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `expires_at` datetime,
            PRIMARY KEY (`uuid`),
            UNIQUE KEY `id` (`id`),
            KEY `post_id` (`post_id`),
            KEY `post_parent_cat` (`post_parent_cat`),
            KEY `expires_at` (`expires_at`)
        ) $charset_collate;";
    
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);     
    }

    // Updated to work with normalized columns
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
                // 'post_status' => 'vof_temp',
                // 'vof_tier' => null, // Will be set during checkout
                'vof_tier' => $data['vof_tier'],
                'post_parent_cat' => $data['post_parent_cat'] ?? 0,
                'expires_at' => $expires_at
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );

        if ($result === false) {
            error_log('VOF Debug: Failed to create temp user. MySQL Error: ' . $wpdb->last_error);
            return false;
        }

        return $uuid;
    }

    // Updated to work with normalized columns
    public function vof_get_temp_user_by_uuid($uuid) {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                WHERE uuid = %s AND expires_at > NOW()",
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
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT vof_email FROM {$this->table_name} 
                WHERE uuid = %s AND expires_at > NOW()",
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
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT vof_phone FROM {$this->table_name} 
                WHERE uuid = %s AND expires_at > NOW()",
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
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT vof_whatsapp FROM {$this->table_name} 
                WHERE uuid = %s AND expires_at > NOW()",
                $uuid
            )
        );
    
        return $result; // Can be null/false as whatsapp is optional
    }
    
    public function vof_get_post_status_by_uuid($uuid) {
        global $wpdb;
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_status FROM {$this->table_name} 
                WHERE uuid = %s AND expires_at > NOW()",
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
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT vof_tier FROM {$this->table_name} 
                WHERE uuid = %s AND expires_at > NOW()",
                $uuid
            )
        );
    
        return $result; // Can be null as tier might not be set yet
    }
    
    public function vof_get_parent_cat_by_uuid($uuid) {
        global $wpdb;
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_parent_cat FROM {$this->table_name} 
                WHERE uuid = %s AND expires_at > NOW()",
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
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$this->table_name} 
                WHERE uuid = %s AND expires_at > NOW()",
                $uuid
            )
        );
    
        if ($result === null) {
            error_log('VOF Debug: No post ID found for UUID: ' . $uuid);
            return false;
        }
    
        return absint($result);
    }
    
    public function vof_get_created_at_by_uuid($uuid) {
        global $wpdb;
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT created_at FROM {$this->table_name} 
                WHERE uuid = %s AND expires_at > NOW()",
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

    // Updated to work with normalized columns
    public function vof_get_temp_user_by_post_id($post_id) {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                WHERE post_id = %d AND expires_at > NOW()",
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

    // Updated to handle tier assignment during checkout
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

    // Updated to handle post status updates
    public function vof_update_post_status($uuid, $status) {
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

    // Debug methods
    public function vof_get_table_name() {
        return $this->table_name;
    }

    public function vof_get_create_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE IF NOT EXISTS `{$this->table_name}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `uuid` char(36) NOT NULL,
            `post_id` bigint(20) unsigned NOT NULL,
            `vof_email` varchar(255) NOT NULL,
            `vof_phone` varchar(20) NOT NULL,
            `vof_whatsapp` varchar(20) DEFAULT NULL,
            `post_status` varchar(20) NOT NULL DEFAULT 'vof_temp',
            `vof_tier` varchar(50) DEFAULT NULL,
            `post_parent_cat` bigint(20) unsigned NOT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `expires_at` datetime,
            PRIMARY KEY (`uuid`),
            UNIQUE KEY `id` (`id`),
            KEY `post_id` (`post_id`),
            KEY `post_parent_cat` (`post_parent_cat`),
            KEY `expires_at` (`expires_at`)
        ) $charset_collate;";
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

    public function vof_create_test_record() {
        $post_id = 123; // Test post ID
        $data = array(
            'vof_email' => 'test@example.com',
            'vof_phone' => '1234567890',
            'vof_whatsapp_number' => '1234567890',
            'post_parent_cat' => 1
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
}