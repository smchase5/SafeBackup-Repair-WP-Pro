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

        // Mock AI Response
        // In a real implementation, this would send $error_log to OpenAI API

        $mock_responses = array(
            'syntax' => array(
                'summary' => 'This looks like a PHP syntax error.',
                'cause' => 'A missing semicolon or curly brace in your theme functions.php file.',
                'solution' => 'Open the file mentioned in the error log and ensure all lines end with a semicolon.'
            ),
            'generic' => array(
                'summary' => 'I detected a fatal error caused by a plugin conflict.',
                'cause' => 'Two plugins are trying to declare the same function name.',
                'solution' => 'Deactivate the most recently added plugin. If that fixes it, report the issue to the plugin developer.'
            )
        );

        // Simple keyword matching for mock variety
        $response = $mock_responses['generic'];
        if (strpos(strtolower($error_log), 'syntax error') !== false) {
            $response = $mock_responses['syntax'];
        }

        return rest_ensure_response(array(
            'success' => true,
            'analysis' => $response
        ));
    }
}
