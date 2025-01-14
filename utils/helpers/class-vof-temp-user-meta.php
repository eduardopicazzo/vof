<?php
namespace VOF\Utils\Helpers;
// this class is used to store temporary user meta data during the onboarding process.
class VOF_Temp_User_Meta {
    /** 
     * vof_temp_user_meta: 
     * 
     * proposed schema: 
     * 
     * CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}vof_temp_user_meta` (
     * `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
     * `uuid` char(36) NOT NULL,                    # Unique identifier for the temp user
     * `post_id` bigint(20) unsigned NOT NULL,      # ID of the temporary listing
     * `meta_key` varchar(255) NOT NULL,            # Field name (e.g., 'vof_email')
     * `meta_value` longtext,                       # Field value
     * `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
     * `expires_at` datetime,                       # When this temp data should expire
     * PRIMARY KEY (`id`),
     * KEY `uuid` (`uuid`),
     * KEY `post_id` (`post_id`),
     * KEY `meta_key` (`meta_key`),
     * KEY `expires_at` (`expires_at`)
     * ) {$charset_collate};
     * 
     * A new table vof_temp_user_meta that will store temporary user data 
     * and establish relationships between temp users, 
     * their metadata, and their listings.
     * 
     * This table is used to store temporary user meta data during the onboarding process.
     * It is used to store data that is not yet ready to be saved to the database, 
     * but needs to be retained for the duration of the onboarding process.
     * 
     * @since 1.0.0
     */ 

     private static $instance = null;
     private $table_name;

    //  singleton
    public static function vof_get_temp_user_meta_instance() {
       if ( self::$instance === null ) {
           self::$instance = new self();
       }
       return self::$instance;
    }
    
    private function __construct() {
       global $wpdb;
       $this->table_name = $wpdb->prefix. 'vof_temp_user_meta';
       $this->vof_maybe_create_table();
    }

    private function vof_maybe_create_table() {
        global $wpdb;
    
        $charset_collate = $wpdb->get_charset_collate();
    
        // Notice removed space between { and $this
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table_name}` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `uuid` char(36) NOT NULL,
            `post_id` bigint(20) unsigned NOT NULL,
            `meta_key` varchar(255) NOT NULL,
            `meta_value` longtext,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `expires_at` datetime,
            PRIMARY KEY (`id`),
            KEY `uuid` (`uuid`),
            KEY `post_id` (`post_id`),
            KEY `meta_key` (`meta_key`),
            KEY `expires_at` (`expires_at`)
        ) $charset_collate;";
    
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);     
    }

    public function vof_create_temp_user( $post_id, $meta_data ) {
        global $wpdb;

        // Generate UUID v4
        $uuid = wp_generate_uuid4();

        // Set expiration (3 days from now) 
        $expires_at = date('Y-m-d H:i:s', strtotime('+3 days'));

        // Store each piece of metadata
        foreach ($meta_data as $key => $value) {
            $wpdb->insert(
                $this->table_name, 
                array( 
                    'uuid' => $uuid,
                    'post_id' => $post_id,
                    'meta_key' => $key,
                    'meta_value' => $value,
                    'expires_at' => $expires_at
                ),
                array('%s', '%d', '%s', '%s', '%s')
            );
        }
        return $uuid;
    }

    public function vof_get_temp_user_by_uuid( $uuid ) {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$this->table_name}
                WHERE uuid = %s AND expires_at > NOW()",
                $uuid
            ),
            ARRAY_A
        );

        if (! $results) {
            return false;
        }

        $meta_data = array();
        foreach ($results as $row) {
            $meta_data[$row['meta_key']] = $row['meta_value'];
        }
        return $meta_data;
    }

    public function vof_get_temp_user_by_post_id( $post_id ) {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare (
                "SELECT meta_key, meta_value FROM {$this->table_name}
                WHERE post_id = %d AND expires_at > NOW()",
                $post_id
            ),
            ARRAY_A
        );

        if (! $results) {
            return false;
        }

        $meta_data = array();
        foreach ($results as $row) {
            $meta_data[$row['meta_key']] = $row['meta_value'];
        }
        return $meta_data;
    }

    public function vof_delete_expired_data() {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$this->table_name} WHERE expires_at <= NOW()"
        );  
    }

    // ### DEBUG SECTION (admin menu) ###

    public function vof_get_table_name() {
        return $this->table_name;
    }

    public function vof_get_create_table_sql() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE IF NOT EXISTS `{ $this->table_name }` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `uuid` char(36) NOT NULL,
            `post_id` bigint(20) unsigned NOT NULL,
            `meta_key` varchar(255) NOT NULL,
            `meta_value` longtext,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `expires_at` datetime,
            PRIMARY KEY (`id`),
            KEY `uuid` (`uuid`),
            KEY `post_id` (`post_id`),
            KEY `meta_key` (`meta_key`),
            KEY `expires_at` (`expires_at`)
        ) $charset_collate;";
    }

    public function vof_get_all_records() {
        global $wpdb;
    
        $query = "SELECT * FROM {$this->table_name}";
        error_log('VOF Debug: Running query: ' . $query);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        error_log('VOF Debug: Query results: ' . print_r($results, true));
    
        if (!$results) {
            error_log('VOF Debug: No records found');
            return [];
        }
    
        // Group results by UUID
        $grouped_results = [];
        foreach ($results as $row) {
            $uuid = $row['uuid'];
            if (!isset($grouped_results[$uuid])) {
                $grouped_results[$uuid] = [
                    'uuid' => $uuid,
                    'post_id' => $row['post_id'],
                    'created_at' => $row['created_at'],
                    'expires_at' => $row['expires_at'],
                    'meta_data' => []
                ];
            }
            $grouped_results[$uuid]['meta_data'][$row['meta_key']] = $row['meta_value'];
        }
    
        error_log('VOF Debug: Grouped results: ' . print_r($grouped_results, true));
        return array_values($grouped_results);
    }

    public function vof_create_test_record() {
        // Sample data
        $post_id = 123; // Use a real post ID from your system
        $meta_data = array(
            'vof_email' => 'test@example.com',
            'vof_phone' => '1234567890',
            'vof_whatsapp_number' => '1234567890'
        );
    
        // Debug log before creation
        error_log('VOF Debug: Creating test record with data: ' . print_r($meta_data, true));
        
        // Create record and return uuid
        $uuid = $this->vof_create_temp_user($post_id, $meta_data);
        
        // Debug log after creation
        error_log('VOF Debug: Created test record with UUID: ' . $uuid);
        
        return $uuid;
    }

}