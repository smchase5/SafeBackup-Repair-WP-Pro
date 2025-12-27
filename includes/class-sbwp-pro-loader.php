<?php

class SBWP_Pro_Loader
{

    private $gdrive;

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

        // Hook into Free version to indicate Pro is active
        add_filter('sbwp_is_pro_active', '__return_true');

        // Register Pro-only API routes
        add_action('rest_api_init', array($this, 'register_pro_routes'));

        // Add Cloud Providers
        add_filter('sbwp_cloud_providers', array($this, 'register_cloud_providers'));
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
}
