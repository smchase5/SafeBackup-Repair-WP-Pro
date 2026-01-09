<?php
/**
 * Clone Manager
 * 
 * Handles creation, bootstrapping, and cleanup of isolated WordPress clones
 * for safe update testing.
 */

class SBWP_Clone_Manager
{
    private $clone_id;
    private $clone_dir;
    private $clone_prefix;

    /**
     * Generate a unique clone ID (kept short to avoid MySQL 64-char table name limit)
     * Format: sb_{short_hash} (max 10 chars to leave room for long table names)
     */
    public function generate_clone_id($session_id)
    {
        // Use just 6 chars to keep total prefix under 10 chars: sb_XXXXXX_
        return 'sb_' . substr(md5($session_id . uniqid()), 0, 6);
    }

    /**
     * Create a full clone environment
     * 
     * @param string $clone_id Unique clone identifier
     * @param array $items Items to clone (plugins, themes)
     * @return array|WP_Error Clone info or error
     */
    public function create_clone($clone_id, $items = array())
    {
        $this->clone_id = $clone_id;
        $this->clone_prefix = $clone_id . '_';
        $this->clone_dir = WP_CONTENT_DIR . '/sbwp-clones/' . $clone_id;

        // Create clone directories
        $dirs_result = $this->create_clone_directories();
        if (is_wp_error($dirs_result)) {
            return $dirs_result;
        }

        // Clone database tables
        $db_result = $this->clone_database_tables();
        if (is_wp_error($db_result)) {
            $this->cleanup_clone($clone_id);
            return $db_result;
        }

        // Clone plugin files
        if (!empty($items['plugins'])) {
            $plugins_result = $this->clone_plugins($items['plugins']);
            if (is_wp_error($plugins_result)) {
                $this->cleanup_clone($clone_id);
                return $plugins_result;
            }
        }

        // Clone theme files
        if (!empty($items['themes'])) {
            $themes_result = $this->clone_themes($items['themes']);
            if (is_wp_error($themes_result)) {
                $this->cleanup_clone($clone_id);
                return $themes_result;
            }
        }

        // Create clone-specific log file
        $this->setup_clone_log();

        return array(
            'clone_id' => $clone_id,
            'clone_prefix' => $this->clone_prefix,
            'clone_dir' => $this->clone_dir,
            'plugins_dir' => $this->clone_dir . '/plugins',
            'themes_dir' => $this->clone_dir . '/themes',
            'log_path' => WP_CONTENT_DIR . '/sbwp-logs/clone-' . $clone_id . '.log'
        );
    }

    /**
     * Create clone directories
     */
    private function create_clone_directories()
    {
        $dirs = array(
            $this->clone_dir,
            $this->clone_dir . '/plugins',
            $this->clone_dir . '/themes',
            WP_CONTENT_DIR . '/sbwp-logs'
        );

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                if (!wp_mkdir_p($dir)) {
                    return new WP_Error('mkdir_failed', "Failed to create directory: $dir");
                }
            }
        }

        return true;
    }

    /**
     * Clone database tables with new prefix
     */
    private function clone_database_tables()
    {
        global $wpdb;

        error_log('SBWP Clone: Starting database clone with prefix ' . $this->clone_prefix);

        $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        $prefix = $wpdb->prefix;
        $cloned_count = 0;

        // Tables with lots of content data that aren't needed for conflict testing
        $large_content_tables = array(
            'posts',
            'postmeta',
            'comments',
            'commentmeta',
            'wc_orders',
            'wc_order_items',
            'wc_order_product_lookup',
            'actionscheduler_actions',
            'actionscheduler_logs',
            'yoast_seo_links',
            'woocommerce_sessions'
        );

        foreach ($tables as $table) {
            $table_name = $table[0];

            // Only clone tables with our prefix
            if (strpos($table_name, $prefix) !== 0) {
                continue;
            }

            $suffix = substr($table_name, strlen($prefix));
            $clone_table = $this->clone_prefix . $suffix;

            // Drop if exists (cleanup from previous failed run)
            $wpdb->query("DROP TABLE IF EXISTS `$clone_table`");

            // Create table like original
            $result = $wpdb->query("CREATE TABLE `$clone_table` LIKE `$table_name`");
            if ($result === false) {
                error_log("SBWP Clone: Failed to create table $clone_table");
                return new WP_Error('db_clone_failed', "Failed to create clone table: $clone_table");
            }

            // For large content tables, only copy essential config data or limited rows
            if (in_array($suffix, $large_content_tables)) {
                // Just copy table structure, skip data for conflict testing
                error_log("SBWP Clone: Skipped data for large table: $suffix");
            } else {
                // Copy data for smaller config/settings tables
                $wpdb->query("INSERT INTO `$clone_table` SELECT * FROM `$table_name`");
            }

            $cloned_count++;
        }

        if ($cloned_count === 0) {
            return new WP_Error('no_tables', 'No tables were cloned');
        }

        error_log("SBWP Clone: Successfully cloned {$cloned_count} tables");

        return $cloned_count;
    }

    /**
     * Clone plugin directories
     */
    private function clone_plugins($plugins)
    {
        $plugins_dir = WP_PLUGIN_DIR;
        $clone_plugins_dir = $this->clone_dir . '/plugins';

        foreach ($plugins as $plugin_file) {
            // Get plugin directory name (e.g., "woocommerce" from "woocommerce/woocommerce.php")
            $plugin_parts = explode('/', $plugin_file);
            $plugin_slug = $plugin_parts[0];

            $source = $plugins_dir . '/' . $plugin_slug;
            $dest = $clone_plugins_dir . '/' . $plugin_slug;

            if (is_dir($source)) {
                $result = $this->copy_directory($source, $dest);
                if (!$result) {
                    return new WP_Error('plugin_copy_failed', "Failed to copy plugin: $plugin_slug");
                }
            } elseif (is_file($plugins_dir . '/' . $plugin_file)) {
                // Single file plugin
                copy($plugins_dir . '/' . $plugin_file, $clone_plugins_dir . '/' . $plugin_file);
            }
        }

        return true;
    }

    /**
     * Clone theme directories
     */
    private function clone_themes($themes)
    {
        $themes_dir = get_theme_root();
        $clone_themes_dir = $this->clone_dir . '/themes';

        foreach ($themes as $theme_slug) {
            $source = $themes_dir . '/' . $theme_slug;
            $dest = $clone_themes_dir . '/' . $theme_slug;

            if (is_dir($source)) {
                $result = $this->copy_directory($source, $dest);
                if (!$result) {
                    return new WP_Error('theme_copy_failed', "Failed to copy theme: $theme_slug");
                }
            }
        }

        return true;
    }

    /**
     * Setup clone-specific log file
     */
    private function setup_clone_log()
    {
        $log_path = WP_CONTENT_DIR . '/sbwp-logs/clone-' . $this->clone_id . '.log';
        touch($log_path);
        return $log_path;
    }

    /**
     * Cleanup a clone environment
     */
    public function cleanup_clone($clone_id)
    {
        global $wpdb;

        $this->clone_id = $clone_id;
        $this->clone_prefix = $clone_id . '_';
        $this->clone_dir = WP_CONTENT_DIR . '/sbwp-clones/' . $clone_id;

        // Drop cloned database tables
        $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        foreach ($tables as $table) {
            $table_name = $table[0];
            if (strpos($table_name, $this->clone_prefix) === 0) {
                $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
            }
        }

        // Delete cloned files
        if (is_dir($this->clone_dir)) {
            $this->delete_directory($this->clone_dir);
        }

        return true;
    }

    /**
     * Get clone bootstrap URL
     */
    public function get_clone_url($clone_id, $path = '/')
    {
        return add_query_arg('sbwp_clone', $clone_id, home_url($path));
    }

    /**
     * Check if current request is a clone request
     */
    public static function is_clone_request()
    {
        return !empty($_GET['sbwp_clone']) || defined('SBWP_CLONE_ID');
    }

    /**
     * Get current clone ID
     */
    public static function get_current_clone_id()
    {
        if (defined('SBWP_CLONE_ID')) {
            return SBWP_CLONE_ID;
        }
        return isset($_GET['sbwp_clone']) ? sanitize_text_field($_GET['sbwp_clone']) : null;
    }

    /**
     * Recursively copy a directory
     */
    private function copy_directory($source, $dest)
    {
        if (!is_dir($dest)) {
            wp_mkdir_p($dest);
        }

        $dir = opendir($source);
        if (!$dir) {
            return false;
        }

        while (($file = readdir($dir)) !== false) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            $src_path = $source . '/' . $file;
            $dest_path = $dest . '/' . $file;

            if (is_dir($src_path)) {
                $this->copy_directory($src_path, $dest_path);
            } else {
                copy($src_path, $dest_path);
            }
        }

        closedir($dir);
        return true;
    }

    /**
     * Recursively delete a directory
     */
    private function delete_directory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->delete_directory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
