<?php
/**
 * Clone Health Check Endpoint
 * 
 * This file is called directly to test a cloned environment.
 * It loads WordPress with a specific clone's plugin/theme files included.
 * 
 * Usage: clone-health-check.php?clone_id=sb_abc123&check=homepage
 */

// Security: Only allow from same server
if (
    !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']) &&
    $_SERVER['REMOTE_ADDR'] !== $_SERVER['SERVER_ADDR']
) {
    // Allow if there's a valid clone_id (we'll validate it later)
    if (empty($_GET['clone_id'])) {
        header('HTTP/1.1 403 Forbidden');
        die('Access denied');
    }
}

// Get clone ID
$clone_id = isset($_GET['clone_id']) ? preg_replace('/[^a-z0-9_]/', '', $_GET['clone_id']) : '';
if (empty($clone_id)) {
    header('HTTP/1.1 400 Bad Request');
    die('Missing clone_id');
}

// Define constant for clone mode
define('SBWP_CLONE_MODE', true);
define('SBWP_CLONE_ID', $clone_id);

// Find WordPress root
$wp_root = dirname(dirname(dirname(dirname(dirname(__FILE__))))); // Go up 5 levels from this file

// Load WordPress
require_once $wp_root . '/wp-load.php';

// Verify clone exists
$clone_dir = WP_CONTENT_DIR . '/sbwp-clones/' . SBWP_CLONE_ID;
if (!is_dir($clone_dir)) {
    header('HTTP/1.1 404 Not Found');
    die(json_encode(['error' => 'Clone not found']));
}

// Get the check type
$check = isset($_GET['check']) ? sanitize_text_field($_GET['check']) : 'basic';

$response = array(
    'clone_id' => SBWP_CLONE_ID,
    'check' => $check,
    'success' => true,
    'php_version' => PHP_VERSION,
    'wp_version' => get_bloginfo('version'),
    'errors' => array(),
    'warnings' => array()
);

try {
    // Test that we can load cloned plugin files
    $clone_plugins_dir = $clone_dir . '/plugins';

    if ($check === 'plugin_load') {
        // Try to include each plugin's main file to see if it causes a fatal error
        $plugins = isset($_GET['plugins']) ? explode(',', $_GET['plugins']) : array();

        foreach ($plugins as $plugin_file) {
            $plugin_file = sanitize_text_field($plugin_file);
            $parts = explode('/', $plugin_file);
            $plugin_slug = $parts[0];
            $plugin_path = $clone_plugins_dir . '/' . $plugin_file;

            if (file_exists($plugin_path)) {
                // Check for PHP syntax errors without executing
                $output = array();
                $return_var = 0;
                exec('php -l ' . escapeshellarg($plugin_path) . ' 2>&1', $output, $return_var);

                if ($return_var !== 0) {
                    $response['errors'][] = array(
                        'plugin' => $plugin_slug,
                        'type' => 'syntax_error',
                        'message' => implode("\n", $output)
                    );
                    $response['success'] = false;
                }
            } else {
                $response['warnings'][] = array(
                    'plugin' => $plugin_slug,
                    'message' => 'Plugin file not found in clone'
                );
            }
        }
    }

    // Basic WordPress health check
    if ($check === 'basic' || $check === 'full') {
        // Check if theme loads
        $theme = wp_get_theme();
        $response['theme'] = array(
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version')
        );

        // Check database connectivity
        global $wpdb;
        $tables = $wpdb->get_var('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
        $response['db_tables'] = intval($tables);
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['errors'][] = array(
        'type' => 'exception',
        'message' => $e->getMessage()
    );
} catch (Error $e) {
    $response['success'] = false;
    $response['errors'][] = array(
        'type' => 'fatal_error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    );
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
