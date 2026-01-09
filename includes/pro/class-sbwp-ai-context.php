<?php
/**
 * AI Context Manager
 * 
 * Manages diagnostic history and context for AI-powered conflict analysis.
 * Stores past scan results, user feedback, and what fixes were tried.
 * 
 * @package SafeBackup_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_AI_Context
{

    /**
     * Option name for storing context history
     */
    const HISTORY_OPTION = 'sbwp_diagnostic_history';

    /**
     * Maximum history entries to keep
     */
    const MAX_HISTORY = 20;

    /**
     * Get a unique fingerprint for the current site state
     * Used to correlate scans across sessions
     */
    public function get_site_fingerprint()
    {
        $active_plugins = get_option('active_plugins', array());
        $theme = get_stylesheet();

        $data = array(
            'plugins' => array_map(function ($p) {
                return dirname($p) ?: basename($p, '.php');
            }, $active_plugins),
            'theme' => $theme,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        );

        return md5(json_encode($data));
    }

    /**
     * Save a diagnostic session to history
     */
    public function save_session($data)
    {
        $history = $this->get_history();

        $session = array(
            'id' => uniqid('diag_'),
            'timestamp' => time(),
            'date' => current_time('Y-m-d H:i:s'),
            'fingerprint' => $this->get_site_fingerprint(),
            'type' => $data['type'] ?? 'unknown', // quick or deep
            'user_context' => $data['user_context'] ?? '',
            'issues_found' => $data['issues_found'] ?? 0,
            'culprit' => $data['culprit'] ?? null,
            'ai_diagnosis' => $data['ai_diagnosis'] ?? null,
            'action_taken' => $data['action_taken'] ?? null,
            'action_result' => $data['action_result'] ?? null, // fixed, not_fixed, pending
        );

        // Add to beginning of history
        array_unshift($history, $session);

        // Keep only recent entries
        $history = array_slice($history, 0, self::MAX_HISTORY);

        update_option(self::HISTORY_OPTION, $history, false);

        return $session['id'];
    }

    /**
     * Update an existing session (e.g., when fix is applied)
     */
    public function update_session($session_id, $updates)
    {
        $history = $this->get_history();

        foreach ($history as &$session) {
            if ($session['id'] === $session_id) {
                $session = array_merge($session, $updates);
                break;
            }
        }

        update_option(self::HISTORY_OPTION, $history, false);
    }

    /**
     * Get diagnostic history
     */
    public function get_history($limit = 10)
    {
        $history = get_option(self::HISTORY_OPTION, array());
        if (!is_array($history)) {
            $history = array();
        }
        return array_slice($history, 0, $limit);
    }

    /**
     * Get history for the current site fingerprint
     * (Same plugin/theme configuration)
     */
    public function get_related_history($limit = 5)
    {
        $current_fingerprint = $this->get_site_fingerprint();
        $history = $this->get_history(self::MAX_HISTORY);

        $related = array_filter($history, function ($s) use ($current_fingerprint) {
            return ($s['fingerprint'] ?? '') === $current_fingerprint;
        });

        return array_slice(array_values($related), 0, $limit);
    }

    /**
     * Build context prompt for AI including history
     */
    public function build_context_prompt()
    {
        $history = $this->get_related_history(3);

        if (empty($history)) {
            return '';
        }

        $prompt = "\n\n=== DIAGNOSTIC HISTORY ===\n";
        $prompt .= "Previous scans on this site (same plugin configuration):\n\n";

        foreach ($history as $i => $session) {
            $num = $i + 1;
            $prompt .= "Session {$num} ({$session['date']}):\n";

            if ($session['user_context']) {
                $prompt .= "  User reported: \"{$session['user_context']}\"\n";
            }

            if ($session['culprit']) {
                $culprit = is_array($session['culprit']) ? ($session['culprit']['name'] ?? 'Unknown') : $session['culprit'];
                $prompt .= "  Found: {$culprit}\n";
            }

            if ($session['action_taken']) {
                $prompt .= "  Action: {$session['action_taken']}\n";
            }

            if ($session['action_result']) {
                $result = $session['action_result'] === 'fixed' ? '✓ Fixed the issue' :
                    ($session['action_result'] === 'not_fixed' ? '✗ Did NOT fix the issue' : 'Pending');
                $prompt .= "  Result: {$result}\n";
            }

            $prompt .= "\n";
        }

        // Add AI note if there are patterns
        $patterns = $this->detect_patterns($history);
        if (!empty($patterns)) {
            $prompt .= "Patterns detected:\n";
            foreach ($patterns as $pattern) {
                $prompt .= "- {$pattern}\n";
            }
        }

        return $prompt;
    }

    /**
     * Detect patterns in diagnostic history
     */
    public function detect_patterns($history = null)
    {
        if ($history === null) {
            $history = $this->get_history(10);
        }

        $patterns = array();

        // Check for recurring culprits
        $culprits = array();
        foreach ($history as $session) {
            if (!empty($session['culprit'])) {
                $name = is_array($session['culprit']) ? ($session['culprit']['name'] ?? 'Unknown') : $session['culprit'];
                $culprits[$name] = ($culprits[$name] ?? 0) + 1;
            }
        }

        foreach ($culprits as $name => $count) {
            if ($count >= 2) {
                $patterns[] = "{$name} has been flagged {$count} times - may need deeper investigation";
            }
        }

        // Check for fixes that didn't work
        $failed_fixes = array();
        foreach ($history as $session) {
            if (($session['action_result'] ?? '') === 'not_fixed' && !empty($session['action_taken'])) {
                $failed_fixes[] = $session['action_taken'];
            }
        }

        if (count($failed_fixes) >= 2) {
            $patterns[] = "Multiple fixes have been tried without success - the root cause may be elsewhere";
        }

        // Check for recurring issue descriptions
        $descriptions = array_filter(array_column($history, 'user_context'));
        if (count($descriptions) >= 2) {
            // Simple similarity check
            $first = strtolower($descriptions[0] ?? '');
            $similar = 0;
            foreach (array_slice($descriptions, 1) as $desc) {
                if (similar_text($first, strtolower($desc)) > strlen($first) * 0.5) {
                    $similar++;
                }
            }
            if ($similar >= 1) {
                $patterns[] = "User has reported similar issues multiple times - may be an intermittent problem";
            }
        }

        return $patterns;
    }

    /**
     * Clear all history
     */
    public function clear_history()
    {
        delete_option(self::HISTORY_OPTION);
    }

    /**
     * Get summary for display in UI
     */
    public function get_summary()
    {
        $history = $this->get_history(10);

        return array(
            'total_scans' => count($history),
            'issues_fixed' => count(array_filter($history, function ($s) {
                return ($s['action_result'] ?? '') === 'fixed';
            })),
            'recurring_issues' => count($this->detect_patterns($history)),
            'last_scan' => !empty($history) ? $history[0]['date'] : null,
        );
    }
}
