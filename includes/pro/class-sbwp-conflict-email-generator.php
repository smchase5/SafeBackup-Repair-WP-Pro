<?php
/**
 * Conflict Email Generator
 * 
 * AI-powered email generator for contacting plugin developers
 * with bug reports from conflict scans.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_Conflict_Email_Generator
{
    /**
     * Generate a developer email from scan results
     */
    public function generate($result)
    {
        if (!isset($result['culprit']) || !$result['culprit']) {
            return new WP_Error('no_culprit', 'No culprit plugin found');
        }

        $culprit = $result['culprit'];

        // Try AI generation first
        $ai_email = $this->generate_with_ai($result);

        if (!is_wp_error($ai_email)) {
            return $ai_email;
        }

        // Fallback to template-based generation
        return $this->generate_from_template($result);
    }

    /**
     * Generate email using AI
     */
    private function generate_with_ai($result)
    {
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-ai-service.php';
        $ai_service = new SBWP_AI_Service();

        if (!$ai_service->is_configured()) {
            return new WP_Error('no_api_key', 'AI not configured');
        }

        $prompt = $this->build_email_prompt($result);
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
                    array('role' => 'system', 'content' => $this->get_system_prompt()),
                    array('role' => 'user', 'content' => $prompt),
                ),
                'max_tokens' => 800,
                'temperature' => 0.7,
            )),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', 'Invalid AI response');
        }

        $content = $body['choices'][0]['message']['content'];
        return $this->parse_email_response($content, $result);
    }

    /**
     * Get system prompt for email generation
     */
    private function get_system_prompt()
    {
        return <<<PROMPT
You are helping a WordPress user write a professional bug report email to a plugin developer.

Write a clear, polite, and professional email that includes:
1. A brief description of the issue
2. The environment details (WordPress version, PHP version)
3. The error or behavior observed
4. Steps that might help reproduce
5. A polite request for assistance or fix

Keep the tone friendly but professional. Don't be overly technical - the developer knows their plugin.
Format your response as:
SUBJECT: [subject line]
BODY:
[email body]
PROMPT;
    }

    /**
     * Build prompt for email generation
     */
    private function build_email_prompt($result)
    {
        $culprit = $result['culprit'];

        $prompt = "Write a bug report email for this WordPress plugin issue:\n\n";
        $prompt .= "Plugin: {$culprit['name']} v{$culprit['version']}\n";

        if (isset($culprit['is_outdated']) && $culprit['is_outdated']) {
            $prompt .= "Note: User is on v{$culprit['version']}, latest is v{$culprit['latest_version']}\n";
        }

        if (!empty($result['user_context'])) {
            $prompt .= "\nUser's description of the issue:\n{$result['user_context']}\n";
        }

        if (!empty($result['ai_analysis']['diagnosis'])) {
            $prompt .= "\nAutomated diagnosis:\n{$result['ai_analysis']['diagnosis']}\n";
        }

        if ($result['theme_conflict']) {
            $prompt .= "\nNote: This issue only occurs with a specific theme, works fine with twentytwentyfour.\n";
        }

        $prompt .= "\nEnvironment:\n";
        $prompt .= "- WordPress: {$result['environment']['wp_version']}\n";
        $prompt .= "- PHP: {$result['environment']['php_version']}\n";
        $prompt .= "- Active plugins: {$result['environment']['active_plugins_count']}\n";
        $prompt .= "- Theme: {$result['environment']['active_theme']}\n";

        if (!empty($result['debug_log_excerpt'])) {
            $log_excerpt = substr($result['debug_log_excerpt'], 0, 500);
            $prompt .= "\nRelevant debug log:\n```\n{$log_excerpt}\n```\n";
        }

        return $prompt;
    }

    /**
     * Parse AI response into structured email
     */
    private function parse_email_response($content, $result)
    {
        $subject = '';
        $body = $content;

        // Try to extract subject line
        if (preg_match('/SUBJECT:\s*(.+?)(?:\n|BODY:)/is', $content, $matches)) {
            $subject = trim($matches[1]);
        }

        // Try to extract body
        if (preg_match('/BODY:\s*(.+)$/is', $content, $matches)) {
            $body = trim($matches[1]);
        }

        // Default subject if not found
        if (empty($subject)) {
            $culprit = $result['culprit'];
            $subject = "Bug Report: Issue with {$culprit['name']} v{$culprit['version']}";
        }

        // Try to get developer email from plugin headers
        $to_email = $this->get_plugin_support_email($result['culprit']['slug']);

        return array(
            'subject' => $subject,
            'body' => $body,
            'to_email' => $to_email
        );
    }

    /**
     * Generate email from template (fallback)
     */
    private function generate_from_template($result)
    {
        $culprit = $result['culprit'];

        $subject = "Bug Report: Issue with {$culprit['name']} v{$culprit['version']}";

        $body = "Hello,\n\n";
        $body .= "I'm experiencing an issue with your plugin \"{$culprit['name']}\" and wanted to report it.\n\n";

        if (!empty($result['user_context'])) {
            $body .= "**Issue Description:**\n{$result['user_context']}\n\n";
        }

        if (!empty($result['ai_analysis']['diagnosis'])) {
            $body .= "**Automated Diagnosis:**\n{$result['ai_analysis']['diagnosis']}\n\n";
        }

        $body .= "**Environment:**\n";
        $body .= "- Plugin version: {$culprit['version']}\n";
        $body .= "- WordPress version: {$result['environment']['wp_version']}\n";
        $body .= "- PHP version: {$result['environment']['php_version']}\n";
        $body .= "- Theme: {$result['environment']['active_theme']}\n";
        $body .= "- Total active plugins: {$result['environment']['active_plugins_count']}\n\n";

        if ($result['theme_conflict']) {
            $body .= "**Note:** This issue only occurs with my current theme. The plugin works correctly with the Twenty Twenty-Four theme.\n\n";
        }

        if (!empty($result['debug_log_excerpt'])) {
            $log_excerpt = substr($result['debug_log_excerpt'], 0, 500);
            $body .= "**Debug Log Excerpt:**\n```\n{$log_excerpt}\n```\n\n";
        }

        $body .= "I identified this through conflict isolation testing (deactivating plugins one by one).\n\n";
        $body .= "Please let me know if you need any additional information to help resolve this.\n\n";
        $body .= "Thank you for your time!\n";

        $to_email = $this->get_plugin_support_email($culprit['slug']);

        return array(
            'subject' => $subject,
            'body' => $body,
            'to_email' => $to_email
        );
    }

    /**
     * Get plugin support email from headers
     */
    private function get_plugin_support_email($plugin_file)
    {
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

        if (!file_exists($plugin_path)) {
            return '';
        }

        $plugin_data = get_plugin_data($plugin_path);

        // Try to extract email from Author URI or Plugin URI
        $author_uri = $plugin_data['AuthorURI'] ?? '';
        $plugin_uri = $plugin_data['PluginURI'] ?? '';

        // Try to find a support/contact page
        // For now, return empty and let user find it
        return '';
    }
}
