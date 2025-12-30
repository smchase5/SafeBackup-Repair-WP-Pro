<?php
/**
 * AI Service for OpenAI Integration
 * 
 * Provides AI-powered features like report humanization.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_AI_Service
{
    private $option_key = 'sbwp_openai_api_key';

    /**
     * Check if OpenAI API is configured
     */
    public function is_configured()
    {
        $api_key = $this->get_api_key();
        return !empty($api_key);
    }

    /**
     * Get the stored API key
     */
    public function get_api_key()
    {
        return get_option($this->option_key, '');
    }

    /**
     * Save the API key
     */
    public function save_api_key($api_key)
    {
        if (empty($api_key)) {
            delete_option($this->option_key);
            return true;
        }

        // Basic validation - OpenAI keys start with 'sk-'
        if (strpos($api_key, 'sk-') !== 0) {
            return new WP_Error('invalid_key', 'Invalid OpenAI API key format');
        }

        update_option($this->option_key, sanitize_text_field($api_key));
        return true;
    }

    /**
     * Get masked API key for display (shows last 4 chars)
     */
    public function get_masked_key()
    {
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return '';
        }

        $last_four = substr($api_key, -4);
        return 'sk-...' . $last_four;
    }

    /**
     * Humanize a Safe Update test report using OpenAI
     * 
     * @param array $result The test result JSON
     * @return string|WP_Error Plain-language explanation
     */
    public function humanize_report($result)
    {
        $api_key = $this->get_api_key();

        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'OpenAI API key not configured');
        }

        // Build the prompt
        $prompt = $this->build_report_prompt($result);

        // Call OpenAI API
        $response = $this->call_openai($prompt);

        return $response;
    }

    /**
     * Build the prompt for report humanization
     */
    private function build_report_prompt($result)
    {
        $summary = $result['summary'] ?? array();
        $items = $result['items'] ?? array();
        $health_checks = $result['health_checks'] ?? array();

        // Format the data for the AI
        $report_text = "Safe Update Test Results:\n\n";
        $report_text .= "Overall Status: " . ($summary['overall_status'] ?? 'unknown') . "\n";
        $report_text .= "PHP Version: " . ($summary['php_version'] ?? 'N/A') . "\n";
        $report_text .= "WordPress Version: " . ($summary['wp_version'] ?? 'N/A') . "\n\n";

        $report_text .= "Plugins Tested:\n";
        foreach ($items['plugins'] ?? array() as $plugin) {
            $report_text .= "- " . ($plugin['name'] ?? $plugin['slug']) . ": " . ($plugin['status'] ?? 'unknown');
            if (!empty($plugin['issues'])) {
                $report_text .= "\n  Issues:";
                foreach ($plugin['issues'] as $issue) {
                    $msg = is_array($issue) ? ($issue['message'] ?? json_encode($issue)) : $issue;
                    $report_text .= "\n    * " . $msg;
                }
            }
            $report_text .= "\n";
        }

        $report_text .= "\nHealth Checks:\n";
        foreach ($health_checks as $check) {
            $status = ($check['status_code'] === 200 && !$check['wsod_detected']) ? 'OK' : 'FAILED';
            $report_text .= "- " . $check['label'] . ": " . $status . "\n";
        }

        return $report_text;
    }

    /**
     * Call OpenAI API
     */
    private function call_openai($report_text)
    {
        $api_key = $this->get_api_key();

        $system_prompt = "You are a friendly WordPress expert explaining update test results to someone who isn't technical. 

Your job is to:
1. Explain what was tested in simple terms
2. Clearly state if it's safe to proceed with the updates or not
3. If there are issues, explain what they mean and what the user should do
4. Keep it concise - aim for 3-5 sentences maximum
5. Use a reassuring, helpful tone

Avoid technical jargon. Instead of saying 'PHP syntax error', say something like 'the plugin has a coding mistake that would crash your site'.";

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
                    array('role' => 'user', 'content' => "Please explain these WordPress update test results to a non-technical user:\n\n" . $report_text),
                ),
                'max_tokens' => 300,
                'temperature' => 0.7,
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

        return $body['choices'][0]['message']['content'];
    }
}
