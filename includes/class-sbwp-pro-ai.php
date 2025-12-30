<?php

class SBWP_Pro_AI_Analyzer
{
    public function init()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        register_rest_route('sbwp/v1', '/ai/analyze', array(
            'methods' => 'POST',
            'callback' => array($this, 'analyze_error'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
    }

    public function analyze_error($request)
    {
        $params = $request->get_json_params();
        $error_log = isset($params['log_snippet']) ? sanitize_textarea_field($params['log_snippet']) : '';

        if (empty($error_log)) {
            return new WP_Error('missing_log', 'No error log provided');
        }

        // Load AI Service
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-ai-service.php';
        $ai_service = new SBWP_AI_Service();

        // Check if API key is configured
        if (!$ai_service->is_configured()) {
            // Return basic pattern matching if no API key
            return rest_ensure_response(array(
                'success' => true,
                'analysis' => $this->basic_analysis($error_log),
                'ai_powered' => false
            ));
        }

        // Call OpenAI for real analysis
        $analysis = $this->call_openai_for_analysis($ai_service, $error_log);

        if (is_wp_error($analysis)) {
            // Fall back to basic analysis on error
            return rest_ensure_response(array(
                'success' => true,
                'analysis' => $this->basic_analysis($error_log),
                'ai_powered' => false,
                'ai_error' => $analysis->get_error_message()
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'analysis' => $analysis,
            'ai_powered' => true
        ));
    }

    /**
     * Call OpenAI for error analysis
     */
    private function call_openai_for_analysis($ai_service, $error_log)
    {
        $api_key = $ai_service->get_api_key();

        $system_prompt = "You are a WordPress expert helping users understand and fix errors on their websites.

When given an error log, provide a JSON response with exactly these three fields:
- summary: A brief, non-technical explanation of what went wrong (1-2 sentences)
- cause: The likely root cause of the error (1-2 sentences)
- solution: Step-by-step instructions to fix it (be specific and actionable)

Be friendly and reassuring. Avoid jargon when possible. If you mention file paths, format them clearly.

IMPORTANT: Return ONLY valid JSON with these three fields, no additional text or markdown.";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array('role' => 'system', 'content' => $system_prompt),
                    array('role' => 'user', 'content' => "Analyze this WordPress error log and provide solutions:\n\n" . $error_log),
                ),
                'max_tokens' => 500,
                'temperature' => 0.5,
            )),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('openai_error', $body['error']['message'] ?? 'OpenAI API error');
        }

        if (!isset($body['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', 'Invalid response from OpenAI');
        }

        // Parse the JSON response from OpenAI
        $content = $body['choices'][0]['message']['content'];
        $analysis = json_decode($content, true);

        if (!$analysis || !isset($analysis['summary'])) {
            // If JSON parsing fails, create structured response from raw text
            return array(
                'summary' => $content,
                'cause' => 'See above for details.',
                'solution' => 'Review the analysis above for recommended fixes.'
            );
        }

        return $analysis;
    }

    /**
     * Basic pattern-matching analysis (fallback when no API key)
     */
    private function basic_analysis($error_log)
    {
        $error_log_lower = strtolower($error_log);

        if (strpos($error_log_lower, 'syntax error') !== false) {
            return array(
                'summary' => 'This appears to be a PHP syntax error - usually a typo or missing character in code.',
                'cause' => 'A missing semicolon, quote, or bracket in the file mentioned in the error.',
                'solution' => 'Open the file mentioned in the error at the specified line number and look for missing semicolons, unclosed brackets, or typos.'
            );
        }

        if (strpos($error_log_lower, 'fatal error') !== false) {
            return array(
                'summary' => 'A fatal error occurred that stopped WordPress from running.',
                'cause' => 'This is often caused by a plugin or theme conflict, or calling a function that doesn\'t exist.',
                'solution' => 'Try deactivating your most recently installed or updated plugin. You can rename the plugin folder via FTP to disable it.'
            );
        }

        if (strpos($error_log_lower, 'memory') !== false) {
            return array(
                'summary' => 'WordPress ran out of memory while processing your request.',
                'cause' => 'A plugin or process is using too much memory, or your memory limit is set too low.',
                'solution' => 'Increase the PHP memory limit in wp-config.php by adding: define(\'WP_MEMORY_LIMIT\', \'256M\');'
            );
        }

        if (strpos($error_log_lower, 'database') !== false || strpos($error_log_lower, 'mysql') !== false) {
            return array(
                'summary' => 'There\'s a database connection or query problem.',
                'cause' => 'Database credentials may be wrong, the database server may be down, or a query is malformed.',
                'solution' => 'Check your wp-config.php database settings. Contact your host if the database server is down.'
            );
        }

        // Default generic response
        return array(
            'summary' => 'An error was detected in your WordPress installation.',
            'cause' => 'The error could be caused by a plugin conflict, theme issue, or server configuration.',
            'solution' => 'Try deactivating plugins one by one to identify the culprit. Check the error message for specific file and line references.'
        );
    }
}
