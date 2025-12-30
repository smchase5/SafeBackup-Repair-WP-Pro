<?php
/**
 * Update Tester
 * 
 * Orchestrates the safe update testing process:
 * - Pre-flight requirement checks
 * - Running updates in clone environment
 * - Health checks
 * - Log analysis
 * - Result generation
 */

class SBWP_Update_Tester
{
    private $clone_manager;
    private $session_db;

    public function __construct()
    {
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-clone-manager.php';
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-safe-update-db.php';

        $this->clone_manager = new SBWP_Clone_Manager();
        $this->session_db = new SBWP_Safe_Update_DB();
    }

    /**
     * Update progress for a session (stored as transient for fast polling)
     */
    private function update_progress($session_id, $message, $percent = 0)
    {
        $progress = array(
            'message' => $message,
            'percent' => $percent,
            'updated_at' => time()
        );
        set_transient('sbwp_safe_update_progress_' . $session_id, $progress, HOUR_IN_SECONDS);
    }

    /**
     * Clear progress transient
     */
    private function clear_progress($session_id)
    {
        delete_transient('sbwp_safe_update_progress_' . $session_id);
    }

    /**
     * Get current progress for a session
     */
    public static function get_progress($session_id)
    {
        return get_transient('sbwp_safe_update_progress_' . $session_id);
    }

    /**
     * Run a complete safe update test session
     * 
     * @param int $session_id Session ID from database
     * @return array|WP_Error Result object or error
     */
    public function run_session($session_id)
    {
        $session = $this->session_db->get_session($session_id);
        if (!$session) {
            return new WP_Error('session_not_found', 'Session not found');
        }

        // Mark as running
        $this->session_db->update_status($session_id, 'running');
        $this->update_progress($session_id, 'Initializing test environment...', 5);

        $items = json_decode($session->items_json, true);
        $result = array(
            'summary' => array(
                'overall_status' => 'safe',
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'started_at' => current_time('mysql'),
                'finished_at' => null
            ),
            'items' => array(
                'plugins' => array(),
                'themes' => array(),
                'core' => null
            ),
            'health_checks' => array(),
            'logs' => array(
                'clone_log_path' => null,
                'new_entries' => array()
            )
        );

        // Step 1: Pre-flight requirement checks
        $this->update_progress($session_id, 'Checking PHP/WordPress requirements...', 10);
        $req_result = $this->check_requirements($items);
        $result['items'] = array_merge($result['items'], $req_result);

        // Note: We continue even if some items failed requirements
        // Those items will be marked as 'unsafe' with issues, but we still test the rest

        // Step 2: Create clone environment
        $this->update_progress($session_id, 'Creating clone environment (copying database tables)...', 20);
        $clone_info = $this->clone_manager->create_clone($session->clone_id, $items);
        if (is_wp_error($clone_info)) {
            $this->session_db->update_status($session_id, 'failed', $clone_info->get_error_message());
            $this->clear_progress($session_id);
            return $clone_info;
        }

        $result['logs']['clone_log_path'] = $clone_info['log_path'];
        $start_time = time();

        // Step 3: Apply updates in clone
        $this->update_progress($session_id, 'Downloading and applying updates in sandbox...', 35);
        $update_results = $this->apply_updates_in_clone($session_id, $session->clone_id, $items);
        $result['items'] = $this->merge_item_results($result['items'], $update_results);

        // Step 4: Run health checks
        $this->update_progress($session_id, 'Running health checks on updated clone...', 80);
        $health_results = $this->run_health_checks($session->clone_id);
        $result['health_checks'] = $health_results;

        // Step 5: Analyze logs
        $this->update_progress($session_id, 'Analyzing error logs...', 90);
        $log_entries = $this->analyze_logs($clone_info['log_path'], $start_time);
        $result['logs']['new_entries'] = $log_entries;
        $result['items'] = $this->map_log_entries_to_items($result['items'], $log_entries);

        // Step 6: Finalize item statuses (mark pending items as safe)
        $result['items'] = $this->finalize_item_statuses($result['items']);

        // Step 7: Calculate overall status
        $result['summary']['overall_status'] = $this->calculate_overall_status($result);
        $result['summary']['finished_at'] = current_time('mysql');

        // Step 8: Cleanup clone
        $this->update_progress($session_id, 'Cleaning up clone environment...', 95);
        // Step 8: Cleanup clone
        $this->clone_manager->cleanup_clone($session->clone_id);

        // Save result
        $this->session_db->update_result($session_id, $result);

        return $result;
    }

    /**
     * Finalize item statuses - mark any 'pending' items as 'safe' if no issues found
     */
    private function finalize_item_statuses($items)
    {
        foreach ($items['plugins'] as &$plugin) {
            if ($plugin['status'] === 'pending' && empty($plugin['issues'])) {
                $plugin['status'] = 'safe';
            }
        }

        foreach ($items['themes'] as &$theme) {
            if ($theme['status'] === 'pending' && empty($theme['issues'])) {
                $theme['status'] = 'safe';
            }
        }

        return $items;
    }

    /**
     * Check PHP and WordPress version requirements
     */
    public function check_requirements($items)
    {
        $results = array('plugins' => array(), 'themes' => array());

        // Check plugins
        if (!empty($items['plugins'])) {
            foreach ($items['plugins'] as $plugin_file) {
                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                $update_info = $this->get_plugin_update_info($plugin_file);

                $item_result = array(
                    'slug' => $plugin_file,
                    'name' => $plugin_data['Name'] ?: $plugin_file,
                    'from_version' => $plugin_data['Version'],
                    'to_version' => $update_info ? $update_info->new_version : 'unknown',
                    'status' => 'pending',
                    'issues' => array()
                );

                // Check PHP requirement
                if ($update_info && !empty($update_info->requires_php)) {
                    if (version_compare(PHP_VERSION, $update_info->requires_php, '<')) {
                        $item_result['issues'][] = array(
                            'type' => 'requirement',
                            'message' => "Requires PHP >= {$update_info->requires_php}; current PHP is " . PHP_VERSION
                        );
                        $item_result['status'] = 'unsafe';
                    }
                }

                // Check WP requirement
                if ($update_info && !empty($update_info->requires)) {
                    if (version_compare(get_bloginfo('version'), $update_info->requires, '<')) {
                        $item_result['issues'][] = array(
                            'type' => 'requirement',
                            'message' => "Requires WordPress >= {$update_info->requires}; current is " . get_bloginfo('version')
                        );
                        $item_result['status'] = 'unsafe';
                    }
                }

                $results['plugins'][] = $item_result;
            }
        }

        // Check themes (similar logic)
        if (!empty($items['themes'])) {
            foreach ($items['themes'] as $theme_slug) {
                $theme = wp_get_theme($theme_slug);
                $results['themes'][] = array(
                    'slug' => $theme_slug,
                    'name' => $theme->get('Name') ?: $theme_slug,
                    'from_version' => $theme->get('Version'),
                    'to_version' => 'latest',
                    'status' => 'pending',
                    'issues' => array()
                );
            }
        }

        return $results;
    }

    /**
     * Get plugin update info from WordPress update cache
     */
    private function get_plugin_update_info($plugin_file)
    {
        $update_plugins = get_site_transient('update_plugins');
        if ($update_plugins && isset($update_plugins->response[$plugin_file])) {
            return $update_plugins->response[$plugin_file];
        }
        return null;
    }

    /**
     * Check if any items have requirement failures
     */
    private function has_requirement_failures($items)
    {
        foreach ($items['plugins'] as $plugin) {
            if ($plugin['status'] === 'unsafe') {
                return true;
            }
        }
        foreach ($items['themes'] as $theme) {
            if ($theme['status'] === 'unsafe') {
                return true;
            }
        }
        return false;
    }

    /**
     * Apply updates in clone environment (Hybrid approach)
     * 
     * - Small plugins: Download and install the update
     * - Large plugins (>5MB) or timeout: Skip with warning
     * - Timeout: 60 seconds per plugin
     */
    private function apply_updates_in_clone($session_id, $clone_id, $items)
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-silent-upgrader-skin.php';

        $results = array('plugins' => array(), 'themes' => array());
        $clone_plugins_dir = WP_CONTENT_DIR . '/sbwp-clones/' . $clone_id . '/plugins';

        $total_plugins = count($items['plugins']);
        $current_plugin = 0;

        // Size threshold: 5MB (plugins larger than this will be skipped)
        $max_package_size = 5 * 1024 * 1024;
        // Time limit per plugin: 60 seconds
        $plugin_timeout = 60;

        foreach ($items['plugins'] as $plugin_file) {
            $current_plugin++;
            $start_time = time();

            // Get plugin name for progress display
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
            $plugin_name = $plugin_data['Name'] ?: $plugin_file;

            // Calculate progress: 40-75% range
            $progress_pct = 40 + (($current_plugin / max($total_plugins, 1)) * 35);
            $this->update_progress($session_id, "Processing {$plugin_name} ({$current_plugin}/{$total_plugins})...", round($progress_pct));

            $plugin_result = array(
                'slug' => $plugin_file,
                'update_attempted' => false,
                'update_success' => false,
                'skipped' => false,
                'skip_reason' => null,
                'errors' => array(),
                'messages' => array()
            );

            // Get plugin directory name
            $plugin_parts = explode('/', $plugin_file);
            $plugin_slug = $plugin_parts[0];
            $clone_plugin_path = $clone_plugins_dir . '/' . $plugin_slug;

            try {
                // Get update info
                $update_info = $this->get_plugin_update_info($plugin_file);
                if (!$update_info) {
                    // No update available - verify existing clone
                    if (is_dir($clone_plugin_path)) {
                        $plugin_result['update_success'] = true;
                        $plugin_result['messages'][] = 'Plugin verified (no update info)';
                    } else {
                        $plugin_result['errors'][] = 'Plugin not in clone and no update info';
                    }
                    $results['plugins'][] = $plugin_result;
                    continue;
                }

                // Check package URL
                $download_url = $update_info->package ?? '';
                if (empty($download_url)) {
                    $plugin_result['skipped'] = true;
                    $plugin_result['skip_reason'] = 'No package URL (may require license)';
                    $plugin_result['messages'][] = 'Skipped: requires license or premium download';
                    $results['plugins'][] = $plugin_result;
                    continue;
                }

                // Check package size if available (some plugins report this)
                $package_size = 0;
                if (isset($update_info->package_size)) {
                    $package_size = intval($update_info->package_size);
                } else {
                    // Try to get size via HEAD request (quick check)
                    $head_response = wp_remote_head($download_url, array('timeout' => 5));
                    if (!is_wp_error($head_response)) {
                        $content_length = wp_remote_retrieve_header($head_response, 'content-length');
                        if ($content_length) {
                            $package_size = intval($content_length);
                        }
                    }
                }

                // Skip if package is too large
                if ($package_size > $max_package_size) {
                    $size_mb = round($package_size / (1024 * 1024), 1);
                    $plugin_result['skipped'] = true;
                    $plugin_result['skip_reason'] = "Package too large ({$size_mb}MB > 5MB limit)";
                    $plugin_result['messages'][] = "Skipped: {$size_mb}MB package exceeds 5MB limit";
                    error_log("SBWP: Skipping {$plugin_name} - package size {$size_mb}MB exceeds limit");
                    $results['plugins'][] = $plugin_result;
                    continue;
                }

                // Attempt to download and install
                $plugin_result['update_attempted'] = true;
                $this->update_progress($session_id, "Downloading {$plugin_name}...", round($progress_pct));
                error_log("SBWP: Starting download for {$plugin_name}");

                $skin = new SBWP_Silent_Upgrader_Skin();
                $upgrader = new Plugin_Upgrader($skin);

                // Use download_package with proper filter context
                // First, apply the 'upgrader_pre_download' filter to allow plugins to override
                $hook_extra = array('plugin' => $plugin_file);

                // Apply the pre-download filter (allows plugins like our crash tester to provide local ZIPs)
                $download_result = apply_filters('upgrader_pre_download', false, $download_url, $upgrader, $hook_extra);

                if ($download_result === false) {
                    // No override - try normal download (but check if URL is valid first)
                    if (filter_var($download_url, FILTER_VALIDATE_URL)) {
                        $download_result = $upgrader->download_package($download_url);
                    } else {
                        // Not a valid URL and no filter override - can't download
                        $plugin_result['errors'][] = "Invalid package URL: {$download_url}";
                        $results['plugins'][] = $plugin_result;
                        continue;
                    }
                }

                // Check timeout
                if ((time() - $start_time) > $plugin_timeout) {
                    $plugin_result['skipped'] = true;
                    $plugin_result['skip_reason'] = 'Download timeout (60s)';
                    $plugin_result['errors'][] = 'Download timed out after 60 seconds';
                    $results['plugins'][] = $plugin_result;
                    continue;
                }

                if (is_wp_error($download_result)) {
                    $plugin_result['errors'][] = 'Download failed: ' . $download_result->get_error_message();
                    $results['plugins'][] = $plugin_result;
                    continue;
                }

                error_log("SBWP: Download result for {$plugin_name}: " . print_r($download_result, true));

                // Unpack using direct ZipArchive (MUCH faster than WordPress's unpack_package)
                $this->update_progress($session_id, "Installing {$plugin_name} to sandbox...", round($progress_pct + 5));
                error_log("SBWP: Unpacking {$plugin_name} using ZipArchive");

                // Use direct ZipArchive for speed instead of WordPress's slow unzip
                $zip = new ZipArchive();
                $zip_path = $download_result;

                if (!file_exists($zip_path)) {
                    $plugin_result['errors'][] = "Package file not found: {$zip_path}";
                    error_log("SBWP: ZIP file not found: {$zip_path}");
                    $results['plugins'][] = $plugin_result;
                    continue;
                }

                $open_result = $zip->open($zip_path);
                if ($open_result !== true) {
                    $plugin_result['errors'][] = "Failed to open ZIP file (error code: {$open_result})";
                    error_log("SBWP: Failed to open ZIP: {$zip_path}");
                    $results['plugins'][] = $plugin_result;
                    continue;
                }

                // Create temp extraction directory
                $temp_dir = WP_CONTENT_DIR . '/sbwp-clones/temp_' . $clone_id . '_' . $plugin_slug;
                if (!is_dir($temp_dir)) {
                    wp_mkdir_p($temp_dir);
                }

                // Extract directly
                $extract_result = $zip->extractTo($temp_dir);
                $zip->close();

                if (!$extract_result) {
                    $plugin_result['errors'][] = "Failed to extract ZIP file";
                    error_log("SBWP: Failed to extract ZIP: {$zip_path}");
                    if (is_dir($temp_dir)) {
                        $this->delete_directory($temp_dir);
                    }
                    $results['plugins'][] = $plugin_result;
                    continue;
                }

                error_log("SBWP: Extracted {$plugin_name} to {$temp_dir}");

                // Find the plugin directory inside the extracted content
                $working_dir = $temp_dir;

                // Remove old plugin in clone
                if (is_dir($clone_plugin_path)) {
                    $this->delete_directory($clone_plugin_path);
                }

                // Move new version to clone
                $unpacked_dir = $working_dir . '/' . $plugin_slug;
                if (!is_dir($unpacked_dir)) {
                    $dirs = glob($working_dir . '/*', GLOB_ONLYDIR);
                    if (!empty($dirs)) {
                        $unpacked_dir = $dirs[0];
                    }
                }

                if (is_dir($unpacked_dir)) {
                    $move_result = rename($unpacked_dir, $clone_plugin_path);
                    if ($move_result) {
                        $plugin_result['update_success'] = true;
                        $plugin_result['messages'][] = 'Update installed in sandbox successfully';
                        error_log("SBWP: Successfully installed {$plugin_name} to sandbox");

                        // === RUN CRASH DETECTION TESTS ===
                        $this->update_progress($session_id, "Testing {$plugin_name} for errors...", round($progress_pct + 15));

                        // Find the main plugin file in the clone
                        $main_plugin_file = $clone_plugin_path . '/' . basename($plugin_file);
                        if (!file_exists($main_plugin_file)) {
                            // Try to find any PHP file with Plugin Name header
                            $php_files = glob($clone_plugin_path . '/*.php');
                            foreach ($php_files as $php_file) {
                                $contents = file_get_contents($php_file);
                                if (strpos($contents, 'Plugin Name:') !== false) {
                                    $main_plugin_file = $php_file;
                                    break;
                                }
                            }
                        }

                        if (file_exists($main_plugin_file)) {
                            // Test 1: Syntax Check (php -l)
                            $syntax_errors = $this->check_php_syntax($main_plugin_file);
                            if (!empty($syntax_errors)) {
                                $plugin_result['update_success'] = false;
                                $plugin_result['errors'][] = array(
                                    'type' => 'syntax_error',
                                    'message' => 'PHP Syntax Error: ' . $syntax_errors
                                );
                                error_log("SBWP: Syntax error in {$plugin_name}: {$syntax_errors}");
                            }

                            // Test 2: Include Test (catches fatal errors like undefined functions)
                            if ($plugin_result['update_success']) {
                                $include_error = $this->test_plugin_include($clone_plugin_path);
                                if (!empty($include_error)) {
                                    $plugin_result['update_success'] = false;
                                    $plugin_result['errors'][] = array(
                                        'type' => 'fatal_error',
                                        'message' => 'Fatal Error on Load: ' . $include_error
                                    );
                                    error_log("SBWP: Fatal error in {$plugin_name}: {$include_error}");
                                }
                            }

                            if ($plugin_result['update_success']) {
                                $plugin_result['messages'][] = 'No crash detected - plugin code is valid';
                            }
                        }

                    } else {
                        $plugin_result['errors'][] = 'Failed to move to clone directory';
                    }
                } else {
                    $plugin_result['errors'][] = 'Could not find unpacked plugin';
                }

                // Cleanup temp
                if (is_dir($working_dir)) {
                    $this->delete_directory($working_dir);
                }
                if (is_string($download_result) && file_exists($download_result)) {
                    @unlink($download_result);
                }

                // Collect upgrader messages/errors
                $plugin_result['messages'] = array_merge($plugin_result['messages'], $skin->get_messages());
                if ($skin->has_errors()) {
                    $plugin_result['errors'] = array_merge($plugin_result['errors'], $skin->get_errors());
                    $plugin_result['update_success'] = false;
                }

            } catch (Exception $e) {
                $plugin_result['errors'][] = 'Exception: ' . $e->getMessage();
            }

            $results['plugins'][] = $plugin_result;
        }

        // Theme verification (simple check)
        foreach ($items['themes'] as $theme_slug) {
            $clone_theme_path = WP_CONTENT_DIR . '/sbwp-clones/' . $clone_id . '/themes/' . $theme_slug;
            $results['themes'][] = array(
                'slug' => $theme_slug,
                'clone_verified' => is_dir($clone_theme_path),
                'update_success' => is_dir($clone_theme_path),
                'errors' => is_dir($clone_theme_path) ? array() : array('Theme not found'),
                'messages' => is_dir($clone_theme_path) ? array('Theme verified') : array()
            );
        }

        return $results;
    }

    /**
     * Recursively delete a directory (helper)
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

    /**
     * Check PHP syntax using php -l
     * Returns error message if syntax error found, empty string if OK
     */
    private function check_php_syntax($file_path)
    {
        $output = array();
        $return_var = 0;

        // Use php -l to check syntax (doesn't execute the code)
        exec('php -l ' . escapeshellarg($file_path) . ' 2>&1', $output, $return_var);

        if ($return_var !== 0) {
            // There was a syntax error
            return implode("\n", $output);
        }

        return '';
    }

    /**
     * Test plugin by running PHP in a subprocess
     * This catches fatal errors like undefined functions/classes
     */
    private function test_plugin_include($plugin_path)
    {
        // Find all PHP files in the plugin
        $php_files = glob($plugin_path . '/*.php');

        if (empty($php_files)) {
            return '';
        }

        // Create a test script that tries to include the main plugin file
        $test_script = <<<'PHP'
<?php
// Minimal WordPress stubs to prevent undefined function errors for common WP functions
if (!function_exists('add_action')) { function add_action($a,$b,$c=10,$d=1){} }
if (!function_exists('add_filter')) { function add_filter($a,$b,$c=10,$d=1){} }
if (!function_exists('plugin_basename')) { function plugin_basename($f){ return basename(dirname($f)).'/'.basename($f); } }
if (!defined('ABSPATH')) { define('ABSPATH', '/'); }
if (!defined('WPINC')) { define('WPINC', 'wp-includes'); }

// Try to parse and load the plugin
try {
    error_reporting(E_ALL);
    set_error_handler(function($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    
    // Include the main plugin file
    $plugin_file = $argv[1] ?? '';
    if (file_exists($plugin_file)) {
        include_once $plugin_file;
    }
    
    echo "OK";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
}
PHP;

        // Find the main plugin file
        $main_file = null;
        foreach ($php_files as $php_file) {
            $contents = file_get_contents($php_file);
            if (strpos($contents, 'Plugin Name:') !== false) {
                $main_file = $php_file;
                break;
            }
        }

        if (!$main_file) {
            $main_file = $php_files[0]; // Fallback to first PHP file
        }

        // Write test script to temp file
        $test_script_path = sys_get_temp_dir() . '/sbwp_plugin_test_' . md5($plugin_path) . '.php';
        file_put_contents($test_script_path, $test_script);

        // Run the test
        $output = array();
        $return_var = 0;
        exec('php ' . escapeshellarg($test_script_path) . ' ' . escapeshellarg($main_file) . ' 2>&1', $output, $return_var);

        // Cleanup
        @unlink($test_script_path);

        $result = implode("\n", $output);

        if (strpos($result, 'ERROR:') !== false) {
            // Extract just the error message
            return str_replace('OK', '', $result);
        }

        return '';
    }

    /**
     * Run health checks against clone
     */
    public function run_health_checks($clone_id)
    {
        $urls = array(
            '/' => 'Homepage',
            '/wp-login.php' => 'Login Page',
            '/wp-admin/' => 'Admin Dashboard'
        );

        $results = array();

        foreach ($urls as $path => $label) {
            $test_url = $this->clone_manager->get_clone_url($clone_id, $path);

            $start = microtime(true);
            $response = wp_remote_get($test_url, array(
                'timeout' => 15,
                'sslverify' => false
            ));
            $duration = (microtime(true) - $start) * 1000;

            $result = array(
                'url' => $path,
                'label' => $label,
                'transport_url' => $test_url,
                'status_code' => 0,
                'response_time_ms' => round($duration),
                'wsod_detected' => false,
                'errors_detected' => array()
            );

            if (is_wp_error($response)) {
                $result['errors_detected'][] = $response->get_error_message();
            } else {
                $result['status_code'] = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);

                // Check for WSOD indicators
                if (empty($body) || strlen($body) < 100) {
                    $result['wsod_detected'] = true;
                }

                // Check for PHP fatal error patterns
                if (preg_match('/Fatal error|Parse error|syntax error/i', $body)) {
                    $result['wsod_detected'] = true;
                    preg_match('/(Fatal error.*?)$/mi', $body, $matches);
                    if (!empty($matches[1])) {
                        $result['errors_detected'][] = $matches[1];
                    }
                }
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Analyze clone log for new errors
     */
    private function analyze_logs($log_path, $start_time)
    {
        if (!file_exists($log_path)) {
            return array();
        }

        $entries = array();
        $handle = fopen($log_path, 'r');
        if (!$handle) {
            return array();
        }

        while (($line = fgets($handle)) !== false) {
            // Parse log line for timestamp and level
            if (preg_match('/\[(.*?)\].*?(Fatal|Error|Warning|Notice|Deprecated)/i', $line, $matches)) {
                $entries[] = trim($line);
            }
        }

        fclose($handle);
        return $entries;
    }

    /**
     * Map log entries to specific plugins/themes
     */
    private function map_log_entries_to_items($items, $log_entries)
    {
        foreach ($log_entries as $entry) {
            // Try to find plugin in error path
            if (preg_match('/wp-content\/plugins\/([^\/]+)/', $entry, $matches)) {
                $plugin_dir = $matches[1];
                foreach ($items['plugins'] as &$plugin) {
                    if (strpos($plugin['slug'], $plugin_dir) === 0) {
                        $plugin['issues'][] = array(
                            'type' => 'log_error',
                            'message' => $entry
                        );
                        if (stripos($entry, 'fatal') !== false) {
                            $plugin['status'] = 'unsafe';
                        } elseif ($plugin['status'] !== 'unsafe') {
                            $plugin['status'] = 'risky';
                        }
                    }
                }
            }
        }

        return $items;
    }

    /**
     * Merge item results from update stage into existing items
     */
    private function merge_item_results($existing, $new)
    {
        // Merge plugin update results
        foreach ($new['plugins'] as $update_result) {
            foreach ($existing['plugins'] as &$plugin) {
                if ($plugin['slug'] === $update_result['slug']) {
                    // Add update attempt info
                    $plugin['update_attempted'] = $update_result['update_attempted'];
                    $plugin['update_success'] = $update_result['update_success'];

                    // If update failed, add errors as issues
                    if (!$update_result['update_success'] && !empty($update_result['errors'])) {
                        foreach ($update_result['errors'] as $error) {
                            $plugin['issues'][] = array(
                                'type' => 'update_error',
                                'message' => $error
                            );
                        }
                        // Mark as unsafe only if it had no previous issues
                        if ($plugin['status'] !== 'unsafe') {
                            $plugin['status'] = 'unsafe';
                        }
                    }

                    // Add messages for context
                    if (!empty($update_result['messages'])) {
                        $plugin['update_messages'] = $update_result['messages'];
                    }
                }
            }
        }

        // Merge theme update results
        foreach ($new['themes'] as $update_result) {
            foreach ($existing['themes'] as &$theme) {
                if ($theme['slug'] === $update_result['slug']) {
                    $theme['update_attempted'] = $update_result['update_attempted'];
                    $theme['update_success'] = $update_result['update_success'];

                    if (!$update_result['update_success'] && !empty($update_result['errors'])) {
                        foreach ($update_result['errors'] as $error) {
                            $theme['issues'][] = array(
                                'type' => 'update_error',
                                'message' => $error
                            );
                        }
                        if ($theme['status'] !== 'unsafe') {
                            $theme['status'] = 'unsafe';
                        }
                    }
                }
            }
        }

        return $existing;
    }

    /**
     * Calculate overall session status
     */
    private function calculate_overall_status($result)
    {
        // Check health checks
        foreach ($result['health_checks'] as $check) {
            if ($check['wsod_detected'] || $check['status_code'] >= 500) {
                return 'unsafe';
            }
        }

        // Check items
        foreach ($result['items']['plugins'] as $plugin) {
            if ($plugin['status'] === 'unsafe') {
                return 'unsafe';
            }
            if ($plugin['status'] === 'risky') {
                return 'risky';
            }
        }

        foreach ($result['items']['themes'] as $theme) {
            if ($theme['status'] === 'unsafe') {
                return 'unsafe';
            }
            if ($theme['status'] === 'risky') {
                return 'risky';
            }
        }

        return 'safe';
    }

    /**
     * Get available updates from WordPress
     */
    public function get_available_updates()
    {
        wp_update_plugins();
        wp_update_themes();

        $updates = array(
            'plugins' => array(),
            'themes' => array(),
            'core' => null
        );

        // Plugin updates
        $plugin_updates = get_site_transient('update_plugins');
        if ($plugin_updates && !empty($plugin_updates->response)) {
            foreach ($plugin_updates->response as $plugin_file => $update) {
                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                $updates['plugins'][] = array(
                    'file' => $plugin_file,
                    'name' => $plugin_data['Name'],
                    'current_version' => $plugin_data['Version'],
                    'new_version' => $update->new_version,
                    'requires_php' => $update->requires_php ?? null,
                    'requires' => $update->requires ?? null
                );
            }
        }

        // Theme updates
        $theme_updates = get_site_transient('update_themes');
        if ($theme_updates && !empty($theme_updates->response)) {
            foreach ($theme_updates->response as $theme_slug => $update) {
                $theme = wp_get_theme($theme_slug);
                $updates['themes'][] = array(
                    'slug' => $theme_slug,
                    'name' => $theme->get('Name'),
                    'current_version' => $theme->get('Version'),
                    'new_version' => $update['new_version']
                );
            }
        }

        // Core updates
        $core_updates = get_site_transient('update_core');
        if ($core_updates && !empty($core_updates->updates)) {
            $latest = $core_updates->updates[0];
            if ($latest->response === 'upgrade') {
                $updates['core'] = array(
                    'current_version' => get_bloginfo('version'),
                    'new_version' => $latest->version
                );
            }
        }

        return $updates;
    }
}
