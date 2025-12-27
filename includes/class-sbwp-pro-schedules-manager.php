<?php

class SBWP_Pro_Schedules_Manager
{
    public function init()
    {
        // Register API routes
        add_action('rest_api_init', array($this, 'register_routes'));

        // Register Cron Hook
        add_action('sbwp_run_scheduled_backup', array($this, 'run_scheduled_backup'));
    }

    public function register_routes()
    {
        register_rest_route('sbwp/v1', '/schedules', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_schedules'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('sbwp/v1', '/schedules', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_schedule'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('sbwp/v1', '/schedules/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_schedule'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
    }

    public function get_schedules()
    {
        $schedules = get_option('sbwp_pro_schedules', array());
        return rest_ensure_response(array_values($schedules));
    }

    public function create_schedule($request)
    {
        $params = $request->get_json_params();
        $schedules = get_option('sbwp_pro_schedules', array());

        $new_id = time(); // Simple ID
        $schedule = array(
            'id' => $new_id,
            'frequency' => sanitize_text_field($params['frequency']), // 'daily', 'weekly'
            'time' => sanitize_text_field($params['time']), // '00:00'
            'created_at' => current_time('mysql')
        );

        $schedules[$new_id] = $schedule;
        update_option('sbwp_pro_schedules', $schedules);

        // Schedule the event
        // Clear existing for this ID if any (not applicable for create, but good practice)
        // For MVP, we'll just schedule a daily event if 'daily'.
        // Real implementation would calculate next run time based on 'time' param.
        if (!wp_next_scheduled('sbwp_run_scheduled_backup', array('id' => $new_id))) {
            wp_schedule_event(time(), $schedule['frequency'], 'sbwp_run_scheduled_backup', array('id' => $new_id));
        }

        return rest_ensure_response($schedule);
    }

    public function delete_schedule($request)
    {
        $id = $request['id'];
        $schedules = get_option('sbwp_pro_schedules', array());

        if (isset($schedules[$id])) {
            unset($schedules[$id]);
            update_option('sbwp_pro_schedules', $schedules);

            // Clear cron
            wp_clear_scheduled_hook('sbwp_run_scheduled_backup', array('id' => $id));
        }

        return rest_ensure_response(array('success' => true));
    }

    public function run_scheduled_backup($id)
    {
        // This runs via Cron
        // 1. Load Backup Engine
        // 2. Create Backup
        // 3. Upload to Remote (if configured)

        if (!class_exists('SBWP_Backup_Engine')) {
            // Should be loaded by main plugin, but safety check
            return;
        }

        $engine = new SBWP_Backup_Engine();
        $result = $engine->create_backup();

        // Log result (TODO: Phase 7 Logger)
        if (is_wp_error($result)) {
            error_log("SBWP Scheduled Backup Failed: " . $result->get_error_message());
        } else {
            error_log("SBWP Scheduled Backup Success: ID " . $result['id']);

            // Handle Remote Upload (Mock)
            $gdrive = new SBWP_Pro_Remote_GDrive();
            if ($gdrive->is_connected()) {
                $upload_result = $gdrive->upload($result['file_path']);
                // Update backup metadata with remote info
                // (Would need a method in Backup Engine to update metadata)
            }
        }
    }
}
