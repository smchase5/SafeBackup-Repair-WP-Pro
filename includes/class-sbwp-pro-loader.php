<?php

class SBWP_Pro_Loader
{

    private $gdrive;
    private $update_tester;
    private $session_db;

    public function init()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-pro-remote-storage.php';
        $this->gdrive = new SBWP_Pro_Remote_GDrive();

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-pro-schedules-manager.php';
        $schedules = new SBWP_Pro_Schedules_Manager();
        $schedules->init();

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-pro-safe-update.php';
        $safe_update = new SBWP_Pro_Safe_Update_Manager();
        $safe_update->init();

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-pro-conflict-scanner.php';
        $scanner = new SBWP_Pro_Conflict_Scanner();
        $scanner->init();

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sbwp-pro-ai.php';
        $ai = new SBWP_Pro_AI_Analyzer();
        $ai->init();

        // Initialize JS Error Monitor for capturing frontend errors
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/pro/class-sbwp-js-monitor.php';
        $js_monitor = new SBWP_JS_Monitor();
        $js_monitor->init();

        // Load Safe Update System classes
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/pro/class-sbwp-safe-update-db.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/pro/class-sbwp-clone-manager.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/pro/class-sbwp-update-tester.php';

        $this->session_db = new SBWP_Safe_Update_DB();
        $this->update_tester = new SBWP_Update_Tester();

        // Hook into Free version to indicate Pro is active
        add_filter('sbwp_is_pro_active', '__return_true');

        // Register Pro-only API routes
        add_action('rest_api_init', array($this, 'register_pro_routes'));

        // Add Cloud Providers
        add_filter('sbwp_cloud_providers', array($this, 'register_cloud_providers'));

        // Register Action Scheduler hooks for Safe Update
        add_action('sbwp_run_safe_update_session', array($this, 'process_safe_update_session'));
    }

    public function register_pro_routes()
    {
        register_rest_route('sbwp/v1', '/cloud/providers', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_cloud_providers'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('sbwp/v1', '/cloud/connect', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_cloud_connect'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Register Safe Update endpoints
        $this->register_safe_update_routes();
    }

    public function get_cloud_providers()
    {
        return rest_ensure_response(array(
            array(
                'id' => $this->gdrive->get_id(),
                'name' => $this->gdrive->get_name(),
                'icon' => 'google',
                'connected' => $this->gdrive->is_connected()
            ),
            // Mock S3 and Dropbox for now (placeholders)
            array(
                'id' => 'aws_s3',
                'name' => 'Amazon S3',
                'icon' => 'aws',
                'connected' => false
            ),
            array(
                'id' => 'dropbox',
                'name' => 'Dropbox',
                'icon' => 'dropbox',
                'connected' => false
            )
        ));
    }

    public function handle_cloud_connect($request)
    {
        $params = $request->get_json_params();
        $provider_id = sanitize_text_field($params['provider_id']);
        $action = sanitize_text_field($params['action']); // connect | disconnect

        if ($provider_id === 'gdrive') {
            if ($action === 'connect') {
                $this->gdrive->connect(array('mock' => 'data'));
            } else {
                $this->gdrive->disconnect();
            }
            return rest_ensure_response(array('success' => true));
        }

        return new WP_Error('not_implemented', 'Provider not implemented');
    }

    public function register_cloud_providers($providers)
    {
        $providers[] = 'google_drive';
        $providers[] = 'aws_s3';
        return $providers;
    }

    /**
     * Safe Update REST Endpoints (registered in register_pro_routes)
     */
    public function register_safe_update_routes()
    {
        // Get available updates
        register_rest_route('sbwp/v1', '/safe-update/available', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_available_updates'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Create a safe update session
        register_rest_route('sbwp/v1', '/safe-update/session', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_safe_update_session'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Get session status
        register_rest_route('sbwp/v1', '/safe-update/session/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_safe_update_session'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Apply safe updates to live site
        register_rest_route('sbwp/v1', '/safe-update/apply/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'apply_safe_updates'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Get recent sessions
        register_rest_route('sbwp/v1', '/safe-update/sessions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_recent_sessions'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // AI Settings - Get
        register_rest_route('sbwp/v1', '/settings/ai', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_ai_settings'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // AI Settings - Save
        register_rest_route('sbwp/v1', '/settings/ai', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_ai_settings'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // AI Humanize Report
        register_rest_route('sbwp/v1', '/ai/humanize-report', array(
            'methods' => 'POST',
            'callback' => array($this, 'humanize_report'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
    }

    /**
     * Get available plugin/theme updates
     */
    public function get_available_updates()
    {
        return rest_ensure_response($this->update_tester->get_available_updates());
    }

    /**
     * Create a new safe update session
     */
    public function create_safe_update_session($request)
    {
        $params = $request->get_json_params();
        $items = array(
            'plugins' => isset($params['plugins']) ? $params['plugins'] : array(),
            'themes' => isset($params['themes']) ? $params['themes'] : array(),
            'core' => isset($params['core']) ? $params['core'] : false
        );

        // Generate clone ID
        $clone_manager = new SBWP_Clone_Manager();
        $temp_id = time() . '_' . wp_rand(1000, 9999);
        $clone_id = $clone_manager->generate_clone_id($temp_id);

        // Create session in DB
        $session_id = $this->session_db->create_session($clone_id, $items);

        if (!$session_id) {
            return new WP_Error('session_create_failed', 'Failed to create session');
        }

        // Schedule background job if Action Scheduler is available
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), 'sbwp_run_safe_update_session', array($session_id));
        } else {
            // Fallback: run synchronously (with warning)
            $this->process_safe_update_session($session_id);
        }

        return rest_ensure_response(array(
            'success' => true,
            'session_id' => $session_id,
            'clone_id' => $clone_id
        ));
    }

    /**
     * Get session status and results
     */
    public function get_safe_update_session($request)
    {
        $session_id = $request['id'];
        $session = $this->session_db->get_session($session_id);

        if (!$session) {
            return new WP_Error('session_not_found', 'Session not found');
        }

        // Get real-time progress if session is running
        $progress = null;
        if ($session->status === 'running') {
            $progress = SBWP_Update_Tester::get_progress($session_id);
        }

        return rest_ensure_response(array(
            'id' => $session->id,
            'clone_id' => $session->clone_id,
            'status' => $session->status,
            'progress' => $progress,
            'items' => json_decode($session->items_json, true),
            'result' => json_decode($session->result_json, true),
            'error_message' => $session->error_message,
            'created_at' => $session->created_at,
            'updated_at' => $session->updated_at
        ));
    }

    /**
     * Get recent sessions
     */
    public function get_recent_sessions()
    {
        $sessions = $this->session_db->get_recent_sessions(10);
        $result = array();

        foreach ($sessions as $session) {
            $result[] = array(
                'id' => $session->id,
                'clone_id' => $session->clone_id,
                'status' => $session->status,
                'created_at' => $session->created_at
            );
        }

        return rest_ensure_response($result);
    }

    /**
     * Apply safe updates to live site
     */
    public function apply_safe_updates($request)
    {
        $session_id = $request['id'];
        $session = $this->session_db->get_session($session_id);

        if (!$session) {
            return new WP_Error('session_not_found', 'Session not found');
        }

        if ($session->status !== 'completed') {
            return new WP_Error('session_not_complete', 'Session must be completed before applying');
        }

        $result = json_decode($session->result_json, true);
        if ($result['summary']['overall_status'] === 'unsafe') {
            return new WP_Error('updates_unsafe', 'Updates were marked as unsafe');
        }

        // TODO: Implement actual live update application
        // For now, return success placeholder
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Safe updates applied (implementation pending)'
        ));
    }

    /**
     * Process safe update session (called by Action Scheduler)
     */
    public function process_safe_update_session($session_id)
    {
        $result = $this->update_tester->run_session($session_id);

        if (is_wp_error($result)) {
            error_log('SBWP Safe Update Error: ' . $result->get_error_message());
        }
    }

    /**
     * Get AI settings
     */
    public function get_ai_settings()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/pro/class-sbwp-ai-service.php';
        $ai_service = new SBWP_AI_Service();

        return rest_ensure_response(array(
            'is_configured' => $ai_service->is_configured(),
            'masked_key' => $ai_service->get_masked_key(),
        ));
    }

    /**
     * Save AI settings
     */
    public function save_ai_settings($request)
    {
        $params = $request->get_json_params();
        $api_key = isset($params['api_key']) ? sanitize_text_field($params['api_key']) : '';

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/pro/class-sbwp-ai-service.php';
        $ai_service = new SBWP_AI_Service();

        $result = $ai_service->save_api_key($api_key);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'success' => true,
            'is_configured' => $ai_service->is_configured(),
            'masked_key' => $ai_service->get_masked_key(),
        ));
    }

    /**
     * Humanize a report using AI
     */
    public function humanize_report($request)
    {
        $params = $request->get_json_params();
        $session_id = isset($params['session_id']) ? intval($params['session_id']) : 0;

        if (!$session_id) {
            return new WP_Error('missing_session_id', 'Session ID is required');
        }

        // Get the session result
        $session = $this->session_db->get_session($session_id);
        if (!$session) {
            return new WP_Error('session_not_found', 'Session not found');
        }

        $result = json_decode($session->result_json, true);
        if (!$result) {
            return new WP_Error('no_result', 'Session has no result data');
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/pro/class-sbwp-ai-service.php';
        $ai_service = new SBWP_AI_Service();

        $summary = $ai_service->humanize_report($result);

        if (is_wp_error($summary)) {
            return $summary;
        }

        return rest_ensure_response(array(
            'summary' => $summary,
        ));
    }
}
