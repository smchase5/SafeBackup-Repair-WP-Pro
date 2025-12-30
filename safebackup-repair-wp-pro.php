<?php
/**
 * Plugin Name: SafeBackup & Repair WP - Pro
 * Description: Premium add-on for SafeBackup & Repair WP. Adds Cloud Storage, Schedules, and more.
 * Version: 1.0.0
 * Author: Sterling Chase
 * Requires at least: 6.0
 * PHP Version: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SBWP_PRO_VERSION', '1.0.0');
define('SBWP_PRO_DIR', plugin_dir_path(__FILE__));
define('SBWP_PRO_URL', plugin_dir_url(__FILE__));

/**
 * Load bundled Action Scheduler if not already loaded
 */
if (!class_exists('ActionScheduler') && file_exists(SBWP_PRO_DIR . 'includes/libs/action-scheduler/action-scheduler.php')) {
    require_once SBWP_PRO_DIR . 'includes/libs/action-scheduler/action-scheduler.php';
}

/**
 * Main Pro Class
 */
require_once SBWP_PRO_DIR . 'includes/class-sbwp-pro-loader.php';

function run_safebackup_repair_wp_pro()
{
    add_action('plugins_loaded', function () {
        // Check if Free version is active
        if (!class_exists('SBWP_Admin_UI')) {
            add_action('admin_notices', function () {
                ?>
                <div class="notice notice-error">
                    <p><?php _e('SafeBackup Pro requires the Free version to be installed and active.', 'sbwp-pro'); ?></p>
                </div>
                <?php
            });
            return;
        }

        $pro = new SBWP_Pro_Loader();
        $pro->init();
    });
}
run_safebackup_repair_wp_pro();

// Activation hook: Create DB tables
register_activation_hook(__FILE__, function () {
    require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-safe-update-db.php';
    $db = new SBWP_Safe_Update_DB();
    $db->create_table();
});
