<?php
/**
 * Quick Scan Engine
 * 
 * Fast scanning without clone/sandbox - checks for JS errors, PHP errors,
 * custom code issues, and known conflicts in about 10 seconds.
 * 
 * @package SafeBackup_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_Quick_Scan
{

    private $js_monitor;
    private $custom_code_scanner;
    private $ai_context;

    /**
     * Run a quick scan
     */
    public function run($user_context = '')
    {
        $start_time = microtime(true);

        // Initialize AI Context for history
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-ai-context.php';
        $this->ai_context = new SBWP_AI_Context();

        $result = array(
            'type' => 'quick',
            'status' => 'completed',
            'user_context' => $user_context,
            'issues' => array(),
            'js_errors' => array(),
            'php_errors' => array(),
            'custom_code' => array(),
            'known_conflicts' => array(),
            'environment' => array(
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'active_plugins_count' => count(get_option('active_plugins', array())),
                'active_theme' => get_stylesheet()
            ),
            'ai_analysis' => null,
            'diagnostic_history' => $this->ai_context->get_summary(),
            'scan_duration' => 0
        );

        try {
            // Step 1: Check for recent JS errors
            $result['js_errors'] = $this->check_js_errors();

            // Step 2: Check for recent PHP errors in debug.log
            $result['php_errors'] = $this->check_php_errors();

            // Step 3: Scan custom code sources
            $result['custom_code'] = $this->check_custom_code();

            // Step 4: Check for known plugin conflicts
            $result['known_conflicts'] = $this->check_known_conflicts();

            // Aggregate all issues
            $result['issues'] = $this->aggregate_issues($result);

            // Step 5: AI Analysis if issues found
            if (!empty($result['issues'])) {
                $result['ai_analysis'] = $this->get_ai_analysis($result);
            }

            // Save to diagnostic history
            $primary_issue = !empty($result['issues']) ? $result['issues'][0] : null;
            $this->ai_context->save_session(array(
                'type' => 'quick',
                'user_context' => $user_context,
                'issues_found' => count($result['issues']),
                'culprit' => $primary_issue ? ($primary_issue['source'] ?? null) : null,
                'ai_diagnosis' => $result['ai_analysis']['diagnosis'] ?? null,
            ));

        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }

        $result['scan_duration'] = round(microtime(true) - $start_time, 2);

        return $result;
    }

    /**
     * Check for recent JavaScript errors
     */
    private function check_js_errors()
    {
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-js-monitor.php';
        $this->js_monitor = new SBWP_JS_Monitor();

        // Get errors from last hour
        $response = $this->js_monitor->get_errors();
        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            return array(
                'errors' => $data['errors'] ?? array(),
                'summary' => $data['summary'] ?? array(),
                'count' => $data['total'] ?? 0
            );
        }
        return array('errors' => array(), 'summary' => array(), 'count' => 0);
    }

    /**
     * Check for recent PHP errors in debug.log
     */
    private function check_php_errors()
    {
        $errors = array();
        $log_path = WP_CONTENT_DIR . '/debug.log';

        if (!file_exists($log_path)) {
            return array('errors' => array(), 'count' => 0);
        }

        // Read last 50KB of log
        $size = filesize($log_path);
        $content = @file_get_contents($log_path, false, null, max(0, $size - 50000));

        if (empty($content)) {
            return array('errors' => array(), 'count' => 0);
        }

        // Filter to last hour
        $cutoff = time() - 3600;
        $lines = explode("\n", $content);
        $recent_errors = array();

        foreach ($lines as $line) {
            // Match WordPress log format: [DD-Mon-YYYY HH:MM:SS UTC]
            if (preg_match('/^\[(\d{2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2})\s+\w+\]/', $line, $m)) {
                $timestamp = strtotime($m[1]);
                if ($timestamp && $timestamp >= $cutoff) {
                    // Check if it's an error/warning
                    if (preg_match('/(Fatal error|Parse error|Warning|Notice|Deprecated)/i', $line)) {
                        $recent_errors[] = array(
                            'line' => substr($line, 0, 500),
                            'type' => $this->classify_php_error($line),
                            'timestamp' => date('Y-m-d H:i:s', $timestamp),
                            'attribution' => $this->attribute_php_error($line)
                        );
                    }
                }
            }
        }

        // Dedupe and limit
        $recent_errors = array_slice(array_unique($recent_errors, SORT_REGULAR), 0, 20);

        return array(
            'errors' => $recent_errors,
            'count' => count($recent_errors)
        );
    }

    /**
     * Classify PHP error type
     */
    private function classify_php_error($line)
    {
        if (stripos($line, 'Fatal error') !== false)
            return 'fatal';
        if (stripos($line, 'Parse error') !== false)
            return 'parse';
        if (stripos($line, 'Warning') !== false)
            return 'warning';
        if (stripos($line, 'Notice') !== false)
            return 'notice';
        if (stripos($line, 'Deprecated') !== false)
            return 'deprecated';
        return 'unknown';
    }

    /**
     * Attribute PHP error to plugin/theme
     */
    private function attribute_php_error($line)
    {
        // Check for plugin path
        if (preg_match('/wp-content\/plugins\/([^\/]+)\//', $line, $m)) {
            return array('type' => 'plugin', 'slug' => $m[1]);
        }

        // Check for theme path
        if (preg_match('/wp-content\/themes\/([^\/]+)\//', $line, $m)) {
            return array('type' => 'theme', 'slug' => $m[1]);
        }

        // Check for WordPress core
        if (preg_match('/wp-(includes|admin)\//', $line)) {
            return array('type' => 'core', 'name' => 'WordPress Core');
        }

        return array('type' => 'unknown');
    }

    /**
     * Check for custom code that could cause issues
     */
    private function check_custom_code()
    {
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-custom-code-scanner.php';
        $this->custom_code_scanner = new SBWP_Custom_Code_Scanner();

        return $this->custom_code_scanner->scan_all();
    }

    /**
     * Check for known plugin conflicts
     */
    private function check_known_conflicts()
    {
        $active_plugins = get_option('active_plugins', array());
        $conflicts = array();

        // Known conflict pairs
        $conflict_pairs = array(
            array('elementor/elementor.php', 'beaver-builder-lite-version/fl-builder.php', 'Two page builders active'),
            array('woocommerce/woocommerce.php', 'easy-digital-downloads/easy-digital-downloads.php', 'Two e-commerce plugins active'),
            array('jetpack/jetpack.php', 'autoptimize/autoptimize.php', 'Potential optimization conflicts'),
            array('wordfence/wordfence.php', 'sucuri-scanner/sucuri.php', 'Two security plugins competing'),
        );

        foreach ($conflict_pairs as $pair) {
            $plugin1 = $pair[0];
            $plugin2 = $pair[1];
            $message = $pair[2];

            if (in_array($plugin1, $active_plugins) && in_array($plugin2, $active_plugins)) {
                $conflicts[] = array(
                    'plugins' => array($plugin1, $plugin2),
                    'message' => $message,
                    'severity' => 'warning'
                );
            }
        }

        return $conflicts;
    }

    /**
     * Aggregate all issues into a unified list
     */
    private function aggregate_issues($result)
    {
        $issues = array();

        // Add JS errors as issues
        if (!empty($result['js_errors']['summary'])) {
            foreach ($result['js_errors']['summary'] as $js) {
                $issues[] = array(
                    'type' => 'javascript',
                    'severity' => 'error',
                    'source' => $js['attribution'] ?? array('type' => 'unknown'),
                    'message' => $js['latest'] ?? 'JavaScript error',
                    'count' => $js['count'] ?? 1
                );
            }
        }

        // Add PHP fatal/parse errors as high severity
        foreach ($result['php_errors']['errors'] ?? array() as $php) {
            if (in_array($php['type'], array('fatal', 'parse'))) {
                $issues[] = array(
                    'type' => 'php',
                    'severity' => 'error',
                    'source' => $php['attribution'],
                    'message' => $php['line']
                );
            }
        }

        // Add PHP warnings/notices as low severity
        foreach ($result['php_errors']['errors'] ?? array() as $php) {
            if (in_array($php['type'], array('warning', 'notice', 'deprecated'))) {
                $issues[] = array(
                    'type' => 'php',
                    'severity' => 'warning',
                    'source' => $php['attribution'],
                    'message' => $php['line']
                );
            }
        }

        // Add custom code issues
        foreach ($result['custom_code']['issues'] ?? array() as $code) {
            $issues[] = array(
                'type' => 'custom_code',
                'severity' => $code['severity'] ?? 'info',
                'source' => $code['source'] ?? array('type' => 'custom'),
                'message' => $code['message'] ?? 'Custom code issue'
            );
        }

        // Add known conflicts
        foreach ($result['known_conflicts'] ?? array() as $conflict) {
            $issues[] = array(
                'type' => 'known_conflict',
                'severity' => $conflict['severity'],
                'source' => array('type' => 'conflict', 'plugins' => $conflict['plugins']),
                'message' => $conflict['message']
            );
        }

        // Sort by severity
        usort($issues, function ($a, $b) {
            $order = array('error' => 0, 'warning' => 1, 'info' => 2);
            return ($order[$a['severity']] ?? 3) - ($order[$b['severity']] ?? 3);
        });

        return $issues;
    }

    /**
     * Get AI analysis of the issues
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
                    array('role' => 'system', 'content' => 'You are a WordPress expert analyzing a quick diagnostic scan. Identify the most likely cause of issues and provide actionable recommendations. Return JSON: {diagnosis, recommendation, culprit_plugin, confidence, fix_actions:[]}'),
                    array('role' => 'user', 'content' => $prompt),
                ),
                'max_tokens' => 400,
                'temperature' => 0.5,
            )),
        ));

        if (is_wp_error($response)) {
            return array(
                'diagnosis' => 'Could not get AI analysis.',
                'recommendation' => 'Review the issues list manually.',
                'confidence' => 'low'
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['choices'][0]['message']['content'])) {
            $content = $body['choices'][0]['message']['content'];

            // Strip markdown code blocks if present (```json ... ```)
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = preg_replace('/\s*```$/i', '', $content);
            $content = trim($content);

            // Try to parse as JSON
            $analysis = json_decode($content, true);
            if ($analysis && isset($analysis['diagnosis'])) {
                return $analysis;
            }

            // If JSON parsing failed, try to extract just the diagnosis field
            if (preg_match('/"diagnosis"\s*:\s*"([^"]+)"/', $content, $matches)) {
                return array(
                    'diagnosis' => $matches[1],
                    'recommendation' => 'See details in the issues list.',
                    'confidence' => 'medium'
                );
            }

            // Return as plain text diagnosis
            return array(
                'diagnosis' => $content,
                'recommendation' => 'See diagnosis above.',
                'confidence' => 'medium'
            );
        }

        return array(
            'diagnosis' => 'AI analysis could not be parsed.',
            'recommendation' => 'Review the issues list manually.',
            'confidence' => 'low'
        );
    }

    /**
     * Build AI prompt from scan results
     */
    private function build_ai_prompt($result)
    {
        $prompt = "Quick diagnostic scan results for a WordPress site:\n\n";

        if ($result['user_context']) {
            $prompt .= "User's problem description: {$result['user_context']}\n\n";
        }

        $prompt .= "Environment: PHP {$result['environment']['php_version']}, WordPress {$result['environment']['wp_version']}\n";
        $prompt .= "Active plugins: {$result['environment']['active_plugins_count']}, Theme: {$result['environment']['active_theme']}\n\n";

        if (!empty($result['issues'])) {
            $prompt .= "Issues found (" . count($result['issues']) . "):\n";
            foreach (array_slice($result['issues'], 0, 10) as $issue) {
                $source = '';
                if (isset($issue['source']['slug'])) {
                    $source = " [{$issue['source']['type']}: {$issue['source']['slug']}]";
                }
                $prompt .= "- [{$issue['severity']}] {$issue['type']}{$source}: " . substr($issue['message'], 0, 200) . "\n";
            }
        } else {
            $prompt .= "No obvious issues detected in quick scan.\n";
        }

        if (!empty($result['custom_code']['sources'])) {
            $prompt .= "\nCustom code sources found: " . implode(', ', array_keys($result['custom_code']['sources'])) . "\n";
        }

        // Add diagnostic history for context
        if ($this->ai_context) {
            $history_context = $this->ai_context->build_context_prompt();
            if (!empty($history_context)) {
                $prompt .= $history_context;
            }
        }

        $prompt .= "\nWhat is the most likely cause and recommended fix?";

        return $prompt;
    }
}
