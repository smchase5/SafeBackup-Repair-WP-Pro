<?php
/**
 * Conflict Isolation Engine
 * 
 * Core logic for isolating plugin/theme conflicts using binary search
 * with sequential fallback. Runs entirely in clone environment.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_Conflict_Isolation
{
    private $clone_manager;
    private $session_db;
    private $session_id;
    private $clone_id;
    private $clone_prefix;
    private $user_context;
    private $js_monitor;
    private $baseline_log_position;

    public function __construct()
    {
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-clone-manager.php';
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-conflict-db.php';

        $this->clone_manager = new SBWP_Clone_Manager();
        $this->session_db = new SBWP_Conflict_DB();
    }

    /**
     * Run the complete conflict scan
     */
    public function run_scan($session_id)
    {
        $session = $this->session_db->get_session($session_id);
        if (!$session) {
            return new WP_Error('session_not_found', 'Session not found');
        }

        $this->session_id = $session_id;
        $this->clone_id = $session->clone_id;
        $this->clone_prefix = $session->clone_id . '_';
        $this->user_context = $session->user_context ?: '';

        // Mark as running
        $this->session_db->update_status($session_id, 'running');

        // Initialize JS Monitor for checking frontend errors
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-js-monitor.php';
        $this->js_monitor = new SBWP_JS_Monitor();

        $result = array(
            'status' => 'completed',
            'user_context' => $this->user_context,
            'outdated_plugins' => array(),
            'clean_slate_passed' => null,
            'culprit' => null,
            'multi_plugin_conflict' => null,
            'theme_conflict' => false,
            'isolation_method' => null,
            'isolation_steps' => array(),
            'ai_analysis' => null,
            'environment' => array(
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'active_plugins_count' => 0,
                'active_theme' => ''
            ),
            'debug_log_excerpt' => '',
            'js_errors' => array(),
            'generated_email' => null
        );

        try {
            // Step 1: Pre-flight - Check for outdated plugins
            $this->update_progress('preflight', 'Checking for outdated plugins...', 5);
            $result['outdated_plugins'] = $this->check_outdated_plugins();

            // Step 2: Get list of active plugins and theme
            $active_plugins = get_option('active_plugins', array());
            $active_theme = get_stylesheet();
            $result['environment']['active_plugins_count'] = count($active_plugins);
            $result['environment']['active_theme'] = $active_theme;

            if (empty($active_plugins)) {
                $result['status'] = 'no_plugins';
                $result['ai_analysis'] = array(
                    'diagnosis' => 'No plugins are currently active.',
                    'recommendation' => 'The issue is likely caused by your theme or WordPress core.',
                    'confidence' => 'high'
                );
                $this->session_db->update_result($session_id, $result);
                return $result;
            }

            // Step 3: Create clone environment
            $this->update_progress('cloning', 'Creating sandbox environment...', 10);
            $clone_info = $this->create_conflict_clone($active_plugins, $active_theme);
            if (is_wp_error($clone_info)) {
                throw new Exception($clone_info->get_error_message());
            }

            // Step 4: Capture baseline
            $this->update_progress('baseline', 'Capturing baseline state...', 20);
            $baseline = $this->capture_baseline();

            // Step 5: Clean slate test
            $this->update_progress('clean_slate', 'Testing with all plugins disabled...', 30);
            $clean_slate = $this->run_clean_slate_test($active_theme);
            $result['clean_slate_passed'] = $clean_slate['passed'];

            if (!$clean_slate['passed']) {
                // Issue persists even with no plugins - it's core/server/theme
                $result['culprit'] = array(
                    'type' => 'core_or_server',
                    'name' => 'WordPress Core or Server',
                    'diagnosis' => 'The issue persists even with all plugins disabled and default theme active.'
                );
                $result['ai_analysis'] = array(
                    'diagnosis' => 'The issue is not caused by any plugin.',
                    'recommendation' => 'Check your server configuration, PHP settings, or contact your hosting provider.',
                    'confidence' => 'high'
                );
                $this->cleanup_and_finish($result);
                return $result;
            }

            // Step 6: Binary search to find culprit
            $this->update_progress('binary_search', 'Isolating conflict with binary search...', 40);
            $result['isolation_method'] = 'binary_search';
            $binary_result = $this->binary_search_plugins($active_plugins, $active_theme);
            $result['isolation_steps'] = $binary_result['steps'];

            if ($binary_result['culprit']) {
                $result['culprit'] = $binary_result['culprit'];
            } else {
                // Binary search found nothing - try sequential for multi-plugin conflicts
                $this->update_progress('sequential', 'Checking for multi-plugin conflicts...', 70);
                $result['isolation_method'] = 'sequential_fallback';
                $sequential_result = $this->sequential_search_plugins($active_plugins, $active_theme);
                $result['isolation_steps'] = array_merge($result['isolation_steps'], $sequential_result['steps']);

                if ($sequential_result['culprit']) {
                    $result['culprit'] = $sequential_result['culprit'];
                } elseif ($sequential_result['multi_conflict']) {
                    $result['multi_plugin_conflict'] = $sequential_result['multi_conflict'];
                }
            }

            // Step 7: Theme conflict test (if plugin culprit found)
            if ($result['culprit'] && $result['culprit']['type'] === 'plugin') {
                $this->update_progress('theme_test', 'Testing for theme conflict...', 85);
                $theme_test = $this->test_theme_conflict($result['culprit']['slug'], $active_theme);
                $result['theme_conflict'] = $theme_test['is_theme_conflict'];
            }

            // Step 8: Get debug log excerpt and JS errors
            $result['debug_log_excerpt'] = $this->get_new_log_entries();
            $result['js_errors'] = $this->get_js_errors_since_baseline();

            // Step 9: AI Analysis
            if ($result['culprit']) {
                $this->update_progress('ai_analysis', 'AI analyzing the conflict...', 90);
                $result['ai_analysis'] = $this->get_ai_analysis($result);
            }

            // Step 10: Cleanup
            $this->cleanup_and_finish($result);
            return $result;

        } catch (Exception $e) {
            $this->session_db->update_status($session_id, 'failed', $e->getMessage());
            $this->clone_manager->cleanup_clone($this->clone_id);
            return new WP_Error('scan_failed', $e->getMessage());
        }
    }

    /**
     * Check for outdated plugins
     */
    private function check_outdated_plugins()
    {
        $outdated = array();
        $update_plugins = get_site_transient('update_plugins');

        if ($update_plugins && !empty($update_plugins->response)) {
            foreach ($update_plugins->response as $plugin_file => $update_info) {
                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                $outdated[] = array(
                    'slug' => $plugin_file,
                    'name' => $plugin_data['Name'],
                    'current_version' => $plugin_data['Version'],
                    'latest_version' => $update_info->new_version,
                    'versions_behind' => $this->estimate_versions_behind(
                        $plugin_data['Version'],
                        $update_info->new_version
                    )
                );
            }
        }

        return $outdated;
    }

    /**
     * Estimate how many versions behind
     */
    private function estimate_versions_behind($current, $latest)
    {
        $current_parts = explode('.', $current);
        $latest_parts = explode('.', $latest);

        $major_diff = (int) ($latest_parts[0] ?? 0) - (int) ($current_parts[0] ?? 0);
        $minor_diff = (int) ($latest_parts[1] ?? 0) - (int) ($current_parts[1] ?? 0);

        if ($major_diff > 0) {
            return $major_diff . '+ major';
        } elseif ($minor_diff > 0) {
            return $minor_diff . ' minor';
        }
        return 'patch';
    }

    /**
     * Create clone for conflict testing
     */
    private function create_conflict_clone($plugins, $theme)
    {
        // Clone all plugins
        $items = array(
            'plugins' => $plugins,
            'themes' => array($theme, 'twentytwentyfour') // Include default theme
        );

        return $this->clone_manager->create_clone($this->clone_id, $items);
    }

    /**
     * Capture baseline before testing
     */
    private function capture_baseline()
    {
        $log_path = WP_CONTENT_DIR . '/debug.log';
        $this->baseline_log_position = file_exists($log_path) ? filesize($log_path) : 0;

        // Record JS error baseline timestamp
        $this->baseline_js_timestamp = time() * 1000;

        return array(
            'log_position' => $this->baseline_log_position,
            'js_timestamp' => $this->baseline_js_timestamp,
            'timestamp' => time()
        );
    }

    /**
     * Get JS errors since baseline
     */
    private function get_js_errors_since_baseline()
    {
        if (!$this->js_monitor || !isset($this->baseline_js_timestamp)) {
            return array();
        }
        return $this->js_monitor->get_errors_since($this->baseline_js_timestamp);
    }

    /**
     * Run clean slate test (all plugins off, default theme)
     */
    private function run_clean_slate_test($original_theme)
    {
        global $wpdb;

        // Deactivate all plugins in clone
        $clone_options_table = $this->clone_prefix . 'options';
        $wpdb->update(
            $clone_options_table,
            array('option_value' => serialize(array())),
            array('option_name' => 'active_plugins'),
            array('%s'),
            array('%s')
        );

        // Switch to default theme in clone
        $wpdb->update(
            $clone_options_table,
            array('option_value' => 'twentytwentyfour'),
            array('option_name' => 'stylesheet'),
            array('%s'),
            array('%s')
        );
        $wpdb->update(
            $clone_options_table,
            array('option_value' => 'twentytwentyfour'),
            array('option_name' => 'template'),
            array('%s'),
            array('%s')
        );

        // Run health check
        $health = $this->run_health_check();

        return array(
            'passed' => $health['passed'],
            'errors' => $health['errors']
        );
    }

    /**
     * Binary search for culprit plugin
     */
    private function binary_search_plugins($plugins, $theme)
    {
        $steps = array();
        $culprit = null;

        // Restore original theme first
        $this->set_clone_theme($theme);

        $search_set = $plugins;
        $step_num = 0;

        while (count($search_set) > 1) {
            $step_num++;
            $mid = (int) (count($search_set) / 2);
            $first_half = array_slice($search_set, 0, $mid);
            $second_half = array_slice($search_set, $mid);

            // Test first half
            $this->set_clone_active_plugins($first_half);
            $health = $this->run_health_check();

            $step = array(
                'step' => $step_num,
                'plugins_tested' => array_map(function ($p) {
                    return dirname($p) ?: basename($p, '.php');
                }, $first_half),
                'passed' => $health['passed']
            );
            $steps[] = $step;

            $progress_pct = 40 + min(30, $step_num * 5);
            $this->update_progress(
                'binary_search',
                "Testing batch {$step_num}: " . count($first_half) . " plugins...",
                $progress_pct
            );

            if (!$health['passed']) {
                // Problem is in first half
                $search_set = $first_half;
            } else {
                // Problem is in second half (or no problem)
                // Test second half to confirm
                $this->set_clone_active_plugins($second_half);
                $health2 = $this->run_health_check();

                if (!$health2['passed']) {
                    $search_set = $second_half;
                } else {
                    // Neither half causes issue alone - might be multi-plugin
                    break;
                }
            }
        }

        // If we narrowed to one plugin, test it
        if (count($search_set) === 1) {
            $this->set_clone_active_plugins($search_set);
            $health = $this->run_health_check();

            if (!$health['passed']) {
                $culprit = $this->get_plugin_info($search_set[0]);
            }
        }

        return array(
            'culprit' => $culprit,
            'steps' => $steps
        );
    }

    /**
     * Sequential search fallback for multi-plugin conflicts
     */
    private function sequential_search_plugins($plugins, $theme)
    {
        $steps = array();
        $active_plugins = array();
        $culprit = null;
        $multi_conflict = null;

        $this->set_clone_theme($theme);

        foreach ($plugins as $i => $plugin) {
            $active_plugins[] = $plugin;
            $this->set_clone_active_plugins($active_plugins);

            $health = $this->run_health_check();
            $plugin_name = dirname($plugin) ?: basename($plugin, '.php');

            $steps[] = array(
                'step' => $i + 1,
                'plugin_added' => $plugin_name,
                'total_active' => count($active_plugins),
                'passed' => $health['passed']
            );

            $progress_pct = 70 + min(15, ($i / count($plugins)) * 15);
            $this->update_progress('sequential', "Testing: {$plugin_name}...", $progress_pct);

            if (!$health['passed']) {
                if (count($active_plugins) === 1) {
                    // Single plugin culprit
                    $culprit = $this->get_plugin_info($plugin);
                } else {
                    // Multi-plugin conflict
                    $multi_conflict = array(
                        'plugins' => array_map(array($this, 'get_plugin_info'), $active_plugins),
                        'message' => 'These plugins conflict when active together'
                    );
                }
                break;
            }
        }

        return array(
            'culprit' => $culprit,
            'multi_conflict' => $multi_conflict,
            'steps' => $steps
        );
    }

    /**
     * Test if issue is also theme-dependent
     */
    private function test_theme_conflict($culprit_plugin, $original_theme)
    {
        // Test culprit plugin with default theme
        $this->set_clone_theme('twentytwentyfour');
        $this->set_clone_active_plugins(array($culprit_plugin));

        $health = $this->run_health_check();

        return array(
            'is_theme_conflict' => $health['passed'], // If it works with default theme, it's a theme conflict
            'tested_with_theme' => 'twentytwentyfour'
        );
    }

    /**
     * Set active plugins in clone
     */
    private function set_clone_active_plugins($plugins)
    {
        global $wpdb;
        $clone_options_table = $this->clone_prefix . 'options';

        $wpdb->update(
            $clone_options_table,
            array('option_value' => serialize($plugins)),
            array('option_name' => 'active_plugins'),
            array('%s'),
            array('%s')
        );
    }

    /**
     * Set theme in clone
     */
    private function set_clone_theme($theme)
    {
        global $wpdb;
        $clone_options_table = $this->clone_prefix . 'options';

        $wpdb->update(
            $clone_options_table,
            array('option_value' => $theme),
            array('option_name' => 'stylesheet'),
            array('%s'),
            array('%s')
        );
        $wpdb->update(
            $clone_options_table,
            array('option_value' => $theme),
            array('option_name' => 'template'),
            array('%s'),
            array('%s')
        );
    }

    /**
     * Run health check against clone
     */
    private function run_health_check()
    {
        $clone_url = $this->clone_manager->get_clone_url($this->clone_id, '/');

        error_log("SBWP Conflict: Running health check on {$clone_url}");

        $response = wp_remote_get($clone_url, array(
            'timeout' => 10, // Reduced timeout to prevent long hangs
            'sslverify' => false,
            'redirection' => 5,
            'blocking' => true
        ));

        if (is_wp_error($response)) {
            error_log("SBWP Conflict: Health check failed - " . $response->get_error_message());
            return array(
                'passed' => false,
                'errors' => array($response->get_error_message())
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $errors = array();

        error_log("SBWP Conflict: Health check response - HTTP {$status_code}, body length: " . strlen($body));

        // Check for error indicators
        $has_error = false;
        if ($status_code >= 500) {
            $has_error = true;
            $errors[] = "HTTP {$status_code} error";
        }
        if (empty($body) || strlen($body) < 100) {
            $has_error = true;
            $errors[] = "White screen / empty response";
        }
        if (preg_match('/Fatal error|Parse error|syntax error/i', $body, $matches)) {
            $has_error = true;
            $errors[] = $matches[0];
        }

        return array(
            'passed' => !$has_error,
            'status_code' => $status_code,
            'errors' => $errors
        );
    }

    /**
     * Get plugin info
     */
    private function get_plugin_info($plugin_file)
    {
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
        $update_plugins = get_site_transient('update_plugins');
        $has_update = isset($update_plugins->response[$plugin_file]);

        return array(
            'type' => 'plugin',
            'slug' => $plugin_file,
            'name' => $plugin_data['Name'] ?: $plugin_file,
            'version' => $plugin_data['Version'],
            'latest_version' => $has_update ? $update_plugins->response[$plugin_file]->new_version : $plugin_data['Version'],
            'is_outdated' => $has_update,
            'author' => $plugin_data['Author'],
            'author_uri' => $plugin_data['AuthorURI'],
            'plugin_uri' => $plugin_data['PluginURI']
        );
    }

    /**
     * Get new log entries since baseline
     */
    private function get_new_log_entries()
    {
        $log_path = WP_CONTENT_DIR . '/debug.log';
        if (!file_exists($log_path)) {
            return '';
        }

        $handle = fopen($log_path, 'r');
        if (!$handle) {
            return '';
        }

        // Seek to baseline position
        fseek($handle, $this->baseline_log_position);
        $new_content = fread($handle, 10000); // Read up to 10KB of new entries
        fclose($handle);

        return $new_content;
    }

    /**
     * Get AI analysis of the conflict
     */
    private function get_ai_analysis($result)
    {
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-ai-service.php';
        $ai_service = new SBWP_AI_Service();

        if (!$ai_service->is_configured()) {
            return array(
                'diagnosis' => 'AI analysis unavailable - no API key configured.',
                'recommendation' => 'Configure your OpenAI API key in Settings for detailed analysis.',
                'confidence' => 'low'
            );
        }

        $prompt = $this->build_ai_prompt($result);
        $api_key = $ai_service->get_api_key();

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array('role' => 'system', 'content' => 'You are a WordPress expert diagnosing plugin conflicts. Provide a concise JSON response with: diagnosis, recommendation, confidence (low/medium/high).'),
                    array('role' => 'user', 'content' => $prompt),
                ),
                'max_tokens' => 300,
                'temperature' => 0.5,
            )),
        ));

        if (is_wp_error($response)) {
            return array(
                'diagnosis' => 'Could not get AI analysis.',
                'recommendation' => 'Review the debug log manually.',
                'confidence' => 'low'
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['choices'][0]['message']['content'])) {
            $analysis = json_decode($body['choices'][0]['message']['content'], true);
            if ($analysis) {
                return $analysis;
            }
        }

        return array(
            'diagnosis' => 'AI analysis could not be parsed.',
            'recommendation' => 'Review the debug log manually.',
            'confidence' => 'low'
        );
    }

    /**
     * Build AI prompt
     */
    private function build_ai_prompt($result)
    {
        $prompt = "Analyze this WordPress plugin conflict:\n\n";

        if ($this->user_context) {
            $prompt .= "User's description: {$this->user_context}\n\n";
        }

        if ($result['culprit']) {
            $c = $result['culprit'];
            $prompt .= "Culprit plugin: {$c['name']} v{$c['version']}\n";
            if ($c['is_outdated']) {
                $prompt .= "Plugin is OUTDATED - latest version is {$c['latest_version']}\n";
            }
        }

        if ($result['theme_conflict']) {
            $prompt .= "Note: This conflict only occurs with the user's theme, not the default theme.\n";
        }

        if ($result['debug_log_excerpt']) {
            $prompt .= "\nDebug log excerpt:\n" . substr($result['debug_log_excerpt'], 0, 1000) . "\n";
        }

        $prompt .= "\nEnvironment: PHP {$result['environment']['php_version']}, WordPress {$result['environment']['wp_version']}\n";

        return $prompt;
    }

    /**
     * Update progress
     */
    private function update_progress($step, $message, $percent)
    {
        $this->session_db->update_progress($this->session_id, $step, $message, $percent);
    }

    /**
     * Cleanup and save result
     */
    private function cleanup_and_finish($result)
    {
        $this->clone_manager->cleanup_clone($this->clone_id);
        $this->session_db->update_result($this->session_id, $result);
    }
}
