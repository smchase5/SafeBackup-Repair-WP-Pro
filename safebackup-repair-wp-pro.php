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

// Activation hook: Create DB tables and install MU-plugin
register_activation_hook(__FILE__, function () {
    require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-safe-update-db.php';
    $db = new SBWP_Safe_Update_DB();
    $db->create_table();

    require_once SBWP_PRO_DIR . 'includes/pro/class-sbwp-conflict-db.php';
    $conflict_db = new SBWP_Conflict_DB();
    $conflict_db->create_table();

    // Install MU-plugin for clone bootstrapping
    sbwp_install_mu_plugin();
});

/**
 * Install clone bootstrapper as MU-plugin
 */
function sbwp_install_mu_plugin()
{
    $mu_plugins_dir = WPMU_PLUGIN_DIR;

    // Create mu-plugins directory if it doesn't exist
    if (!file_exists($mu_plugins_dir)) {
        wp_mkdir_p($mu_plugins_dir);
    }

    $mu_plugin_path = $mu_plugins_dir . '/sbwp-clone-bootstrap.php';

    // MU-plugin content
    $mu_plugin_content = <<<'PHP'
<?php
/**
 * SafeBackup Clone Bootstrapper (MU-Plugin)
 * Auto-installed by SafeBackup Pro
 */

if (!isset($_GET['sbwp_clone']) || empty($_GET['sbwp_clone'])) {
    return;
}

$sbwp_clone_id = preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['sbwp_clone']));
if (!preg_match('/^sb_[a-f0-9]{6}$/', $sbwp_clone_id)) {
    return;
}

define('SBWP_CLONE_ID', $sbwp_clone_id);
define('SBWP_CLONE_PREFIX', $sbwp_clone_id . '_');

add_filter('pre_option_active_plugins', function($value) {
    global $wpdb;
    static $cached = null;
    if ($cached !== null) return $cached;
    
    $table = SBWP_CLONE_PREFIX . 'options';
    $result = $wpdb->get_var("SELECT option_value FROM `{$table}` WHERE option_name = 'active_plugins'");
    $cached = $result ? maybe_unserialize($result) : $value;
    return $cached;
}, 1);

add_filter('pre_option_stylesheet', function($value) {
    global $wpdb;
    static $cached = null;
    if ($cached !== null) return $cached;
    
    $table = SBWP_CLONE_PREFIX . 'options';
    $cached = $wpdb->get_var("SELECT option_value FROM `{$table}` WHERE option_name = 'stylesheet'") ?: $value;
    return $cached;
}, 1);

add_filter('pre_option_template', function($value) {
    global $wpdb;
    static $cached = null;
    if ($cached !== null) return $cached;
    
    $table = SBWP_CLONE_PREFIX . 'options';
    $cached = $wpdb->get_var("SELECT option_value FROM `{$table}` WHERE option_name = 'template'") ?: $value;
    return $cached;
}, 1);

error_log('SBWP Clone: Bootstrap active for ' . SBWP_CLONE_ID);
PHP;

    // Write the MU-plugin file
    file_put_contents($mu_plugin_path, $mu_plugin_content);

    error_log('SBWP: Installed MU-plugin at ' . $mu_plugin_path);
}

// Also check on admin_init in case activation didn't run
add_action('admin_init', function () {
    if (!file_exists(WPMU_PLUGIN_DIR . '/sbwp-clone-bootstrap.php')) {
        sbwp_install_mu_plugin();
    }
});

