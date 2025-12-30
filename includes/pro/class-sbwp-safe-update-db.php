<?php
/**
 * Safe Update Sessions Database Table
 * 
 * Handles creation and management of the safe update sessions table.
 */

class SBWP_Safe_Update_DB
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sb_safe_update_sessions';
    }

    /**
     * Create the sessions table
     */
    public function create_table()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            initiator_user_id bigint(20) DEFAULT NULL,
            clone_id varchar(100) NOT NULL,
            backup_id bigint(20) DEFAULT NULL,
            items_json longtext DEFAULT NULL,
            status varchar(20) DEFAULT 'queued',
            result_json longtext DEFAULT NULL,
            error_message text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY clone_id (clone_id),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get table name
     */
    public function get_table_name()
    {
        return $this->table_name;
    }

    /**
     * Insert a new session
     */
    public function create_session($clone_id, $items, $user_id = null)
    {
        global $wpdb;

        $wpdb->insert(
            $this->table_name,
            array(
                'clone_id' => $clone_id,
                'items_json' => json_encode($items),
                'initiator_user_id' => $user_id ?: get_current_user_id(),
                'status' => 'queued'
            ),
            array('%s', '%s', '%d', '%s')
        );

        return $wpdb->insert_id;
    }

    /**
     * Get session by ID
     */
    public function get_session($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
    }

    /**
     * Update session status
     */
    public function update_status($id, $status, $error_message = null)
    {
        global $wpdb;
        $data = array('status' => $status);
        $format = array('%s');

        if ($error_message !== null) {
            $data['error_message'] = $error_message;
            $format[] = '%s';
        }

        return $wpdb->update(
            $this->table_name,
            $data,
            array('id' => $id),
            $format,
            array('%d')
        );
    }

    /**
     * Update session result
     */
    public function update_result($id, $result)
    {
        global $wpdb;
        return $wpdb->update(
            $this->table_name,
            array('result_json' => json_encode($result), 'status' => 'completed'),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
    }

    /**
     * Get recent sessions
     */
    public function get_recent_sessions($limit = 10)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }

    /**
     * Delete session
     */
    public function delete_session($id)
    {
        global $wpdb;
        return $wpdb->delete($this->table_name, array('id' => $id), array('%d'));
    }
}
