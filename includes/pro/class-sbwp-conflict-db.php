<?php
/**
 * Conflict Scanner Database
 * 
 * Handles storage and retrieval of conflict scan sessions.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_Conflict_DB
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sbwp_conflict_sessions';
    }

    /**
     * Create the database table
     */
    public function create_table()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            clone_id VARCHAR(32) NOT NULL,
            user_context TEXT,
            status VARCHAR(20) DEFAULT 'queued',
            progress_json TEXT,
            result_json LONGTEXT,
            error_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_clone_id (clone_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create a new conflict scan session
     */
    public function create_session($clone_id, $user_context = '')
    {
        global $wpdb;

        $wpdb->insert(
            $this->table_name,
            array(
                'clone_id' => $clone_id,
                'user_context' => $user_context,
                'status' => 'queued',
                'progress_json' => json_encode(array(
                    'step' => 'queued',
                    'message' => 'Waiting to start...',
                    'percent' => 0
                )),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $wpdb->insert_id;
    }

    /**
     * Get a session by ID
     */
    public function get_session($session_id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $session_id
        ));
    }

    /**
     * Update session status
     */
    public function update_status($session_id, $status, $error_message = null)
    {
        global $wpdb;

        $data = array(
            'status' => $status,
            'updated_at' => current_time('mysql')
        );
        $format = array('%s', '%s');

        if ($error_message !== null) {
            $data['error_message'] = $error_message;
            $format[] = '%s';
        }

        return $wpdb->update(
            $this->table_name,
            $data,
            array('id' => $session_id),
            $format,
            array('%d')
        );
    }

    /**
     * Update session progress
     */
    public function update_progress($session_id, $step, $message, $percent, $extra = array())
    {
        global $wpdb;

        $progress = array_merge(array(
            'step' => $step,
            'message' => $message,
            'percent' => $percent,
            'updated_at' => time()
        ), $extra);

        // Also store in transient for fast polling
        set_transient('sbwp_conflict_progress_' . $session_id, $progress, HOUR_IN_SECONDS);

        return $wpdb->update(
            $this->table_name,
            array(
                'progress_json' => json_encode($progress),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $session_id),
            array('%s', '%s'),
            array('%d')
        );
    }

    /**
     * Get current progress (from transient for speed)
     */
    public static function get_progress($session_id)
    {
        return get_transient('sbwp_conflict_progress_' . $session_id);
    }

    /**
     * Update session result
     */
    public function update_result($session_id, $result)
    {
        global $wpdb;

        // Clear progress transient
        delete_transient('sbwp_conflict_progress_' . $session_id);

        return $wpdb->update(
            $this->table_name,
            array(
                'result_json' => json_encode($result),
                'status' => 'completed',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $session_id),
            array('%s', '%s', '%s'),
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
            "SELECT id, clone_id, status, created_at, updated_at 
             FROM {$this->table_name} 
             ORDER BY created_at DESC 
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Delete old sessions (cleanup)
     */
    public function cleanup_old_sessions($days = 7)
    {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }
}
