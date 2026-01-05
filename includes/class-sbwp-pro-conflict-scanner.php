<?php
/**
 * Conflict Scanner REST API
 * 
 * Provides REST endpoints for the AI-powered conflict scanner.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_Pro_Conflict_Scanner
{
    private $isolation_engine;
    private $session_db;
    private $email_generator;

    public function init()
    {
        add_action('rest_api_init', array($this, 'register_routes'));

        // Action Scheduler hook for background scanning
        add_action('sbwp_run_conflict_scan', array($this, 'process_scan'));
    }

    public function register_routes()
    {
        // Start a new conflict scan
        register_rest_route('sbwp/v1', '/conflict-scan/start', array(
            'methods' => 'POST',
            'callback' => array($this, 'start_scan'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Get scan session status
        register_rest_route('sbwp/v1', '/conflict-scan/session/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_session'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Get recent sessions
        register_rest_route('sbwp/v1', '/conflict-scan/sessions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_sessions'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Generate developer email
        register_rest_route('sbwp/v1', '/conflict-scan/generate-email/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_email'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Check for outdated plugins (pre-scan)
        register_rest_route('sbwp/v1', '/conflict-scan/check-outdated', array(
            'methods' => 'GET',
            'callback' => array($this, 'check_outdated'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
    }

    /**
     * Start a new conflict scan
     */
    public function start_scan($request)
    {
        $params = $request->get_json_params();
        $user_context = isset($params['user_context']) ? sanitize_textarea_field($params['user_context']) : '';

        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-conflict-db.php';
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-clone-manager.php';

        $session_db = new SBWP_Conflict_DB();
        $clone_manager = new SBWP_Clone_Manager();

        // Ensure table exists (in case activation hook wasn't run)
        $session_db->create_table();

        // Generate clone ID
        $clone_id = $clone_manager->generate_clone_id('conflict_' . time());

        // Create session
        $session_id = $session_db->create_session($clone_id, $user_context);

        if (!$session_id) {
            return new WP_Error('session_failed', 'Failed to create scan session');
        }

        // Schedule background scan if Action Scheduler is available
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), 'sbwp_run_conflict_scan', array($session_id));
        } else {
            // Run synchronously (fallback)
            $this->process_scan($session_id);
        }

        return rest_ensure_response(array(
            'success' => true,
            'session_id' => $session_id,
            'clone_id' => $clone_id
        ));
    }

    /**
     * Process the conflict scan (called by Action Scheduler or synchronously)
     */
    public function process_scan($session_id)
    {
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-conflict-isolation.php';

        $isolation = new SBWP_Conflict_Isolation();
        $result = $isolation->run_scan($session_id);

        if (is_wp_error($result)) {
            error_log('SBWP Conflict Scan Error: ' . $result->get_error_message());
        }

        return $result;
    }

    /**
     * Get scan session status
     */
    public function get_session($request)
    {
        $session_id = (int) $request['id'];

        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-conflict-db.php';
        $session_db = new SBWP_Conflict_DB();

        $session = $session_db->get_session($session_id);
        if (!$session) {
            return new WP_Error('not_found', 'Session not found');
        }

        // Get real-time progress if running
        $progress = null;
        if ($session->status === 'running') {
            $progress = SBWP_Conflict_DB::get_progress($session_id);
        }

        return rest_ensure_response(array(
            'id' => (int) $session->id,
            'clone_id' => $session->clone_id,
            'status' => $session->status,
            'user_context' => $session->user_context,
            'progress' => $progress ?: json_decode($session->progress_json, true),
            'result' => $session->result_json ? json_decode($session->result_json, true) : null,
            'error_message' => $session->error_message,
            'created_at' => $session->created_at,
            'updated_at' => $session->updated_at
        ));
    }

    /**
     * Get recent scan sessions
     */
    public function get_sessions($request)
    {
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-conflict-db.php';
        $session_db = new SBWP_Conflict_DB();

        $sessions = $session_db->get_recent_sessions(10);
        $result = array();

        foreach ($sessions as $session) {
            $result[] = array(
                'id' => (int) $session->id,
                'clone_id' => $session->clone_id,
                'status' => $session->status,
                'created_at' => $session->created_at
            );
        }

        return rest_ensure_response($result);
    }

    /**
     * Generate developer email from scan result
     */
    public function generate_email($request)
    {
        $session_id = (int) $request['id'];

        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-conflict-db.php';
        require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-conflict-email-generator.php';

        $session_db = new SBWP_Conflict_DB();
        $session = $session_db->get_session($session_id);

        if (!$session) {
            return new WP_Error('not_found', 'Session not found');
        }

        if ($session->status !== 'completed') {
            return new WP_Error('not_complete', 'Scan not completed yet');
        }

        $result = json_decode($session->result_json, true);
        if (!$result || !isset($result['culprit'])) {
            return new WP_Error('no_culprit', 'No culprit found in this scan');
        }

        $email_generator = new SBWP_Conflict_Email_Generator();
        $email = $email_generator->generate($result);

        if (is_wp_error($email)) {
            return $email;
        }

        return rest_ensure_response($email);
    }

    /**
     * Check for outdated plugins (pre-scan check)
     */
    public function check_outdated()
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
                    'latest_version' => $update_info->new_version
                );
            }
        }

        return rest_ensure_response(array(
            'count' => count($outdated),
            'plugins' => $outdated
        ));
    }
}
