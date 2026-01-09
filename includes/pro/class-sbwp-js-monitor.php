<?php
/**
 * JavaScript Error Monitor
 * 
 * Captures frontend JavaScript errors and beacons them back to WordPress
 * for inclusion in conflict scanning and AI diagnosis.
 * 
 * @package SafeBackup_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_JS_Monitor
{

    /**
     * Table name for storing JS errors
     */
    private $table_name;

    /**
     * Transient key for quick access to recent errors
     */
    const ERRORS_TRANSIENT = 'sbwp_js_errors';

    /**
     * Maximum errors to store
     */
    const MAX_ERRORS = 100;

    /**
     * Initialize the monitor
     */
    public function init()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'sbwp_js_errors';

        // Inject the error capture script on frontend and admin
        add_action('wp_enqueue_scripts', [$this, 'inject_monitor'], 1);
        add_action('admin_enqueue_scripts', [$this, 'inject_monitor'], 1);

        // Register REST endpoint for receiving errors
        add_action('rest_api_init', [$this, 'register_endpoints']);
    }

    /**
     * Inject the error monitoring script
     */
    public function inject_monitor()
    {
        // Get the beacon URL
        $beacon_url = rest_url('sbwp/v1/js-error');
        $nonce = wp_create_nonce('sbwp_js_error');

        // Minimal inline script - must load FIRST to catch all errors
        $script = $this->get_monitor_script($beacon_url, $nonce);

        // Output early in head
        wp_register_script('sbwp-js-monitor', false);
        wp_enqueue_script('sbwp-js-monitor');
        wp_add_inline_script('sbwp-js-monitor', $script);
    }

    /**
     * Get the monitor script
     */
    private function get_monitor_script($beacon_url, $nonce)
    {
        return <<<JS
(function(){
    var errors = [];
    var beaconUrl = '{$beacon_url}';
    var nonce = '{$nonce}';
    var maxErrors = 10;
    var sent = 0;
    
    function sendError(data) {
        if (sent >= maxErrors) return;
        sent++;
        
        data.page = location.href;
        data.timestamp = Date.now();
        data.userAgent = navigator.userAgent;
        
        // Use sendBeacon (non-blocking) - no nonce needed for this endpoint
        if (navigator.sendBeacon) {
            var blob = new Blob([JSON.stringify(data)], {type: 'application/json'});
            navigator.sendBeacon(beaconUrl, blob);
        } else {
            // Fallback to fetch
            fetch(beaconUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data),
                keepalive: true
            }).catch(function(){});
        }
    }
    
    // Capture uncaught errors
    window.onerror = function(msg, url, line, col, error) {
        sendError({
            type: 'error',
            message: msg,
            source: url,
            line: line,
            column: col,
            stack: error ? error.stack : null
        });
        return false;
    };
    
    // Capture unhandled promise rejections
    window.onunhandledrejection = function(e) {
        sendError({
            type: 'promise',
            message: e.reason ? (e.reason.message || String(e.reason)) : 'Promise rejected',
            stack: e.reason ? e.reason.stack : null
        });
    };
    
    // Patch console.error to capture logged errors
    var origError = console.error;
    console.error = function() {
        var args = Array.prototype.slice.call(arguments);
        var msg = args.map(function(a) {
            return typeof a === 'object' ? JSON.stringify(a) : String(a);
        }).join(' ');
        
        // Only send if it looks like a real error
        if (msg.length > 10 && !msg.includes('sbwp')) {
            sendError({
                type: 'console',
                message: msg.substring(0, 500)
            });
        }
        
        origError.apply(console, arguments);
    };
})();
JS;
    }

    /**
     * Register REST endpoints
     */
    public function register_endpoints()
    {
        register_rest_route('sbwp/v1', '/js-error', [
            'methods' => ['POST'],
            'callback' => [$this, 'receive_error'],
            'permission_callback' => '__return_true', // Public endpoint (nonce verified manually)
        ]);

        register_rest_route('sbwp/v1', '/js-errors', [
            'methods' => 'GET',
            'callback' => [$this, 'get_errors'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('sbwp/v1', '/js-errors/clear', [
            'methods' => 'POST',
            'callback' => [$this, 'clear_errors'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Receive an error from the frontend
     */
    public function receive_error($request)
    {
        // Verify nonce
        $nonce = $request->get_param('_wpnonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'sbwp_js_error')) {
            // Still store it, but mark as unverified (could be from a cached page)
        }

        // Get error data
        $body = $request->get_body();
        $data = json_decode($body, true);

        if (empty($data) || empty($data['message'])) {
            return new WP_REST_Response(['stored' => false], 200);
        }

        // Attribute error to plugin/theme
        $data['attribution'] = $this->attribute_source($data['source'] ?? '');

        // Store error
        $this->store_error($data);

        return new WP_REST_Response(['stored' => true], 200);
    }

    /**
     * Attribute error source to a plugin or theme
     */
    private function attribute_source($source_url)
    {
        if (empty($source_url)) {
            return ['type' => 'unknown', 'name' => 'Unknown'];
        }

        // Check if it's a plugin
        if (strpos($source_url, '/wp-content/plugins/') !== false) {
            preg_match('/\/plugins\/([^\/]+)\//', $source_url, $matches);
            if (!empty($matches[1])) {
                $plugin_slug = $matches[1];
                $plugin_name = $this->get_plugin_name($plugin_slug);
                return [
                    'type' => 'plugin',
                    'slug' => $plugin_slug,
                    'name' => $plugin_name
                ];
            }
        }

        // Check if it's a theme
        if (strpos($source_url, '/wp-content/themes/') !== false) {
            preg_match('/\/themes\/([^\/]+)\//', $source_url, $matches);
            if (!empty($matches[1])) {
                $theme_slug = $matches[1];
                $theme = wp_get_theme($theme_slug);
                return [
                    'type' => 'theme',
                    'slug' => $theme_slug,
                    'name' => $theme->exists() ? $theme->get('Name') : $theme_slug
                ];
            }
        }

        // Check if it's WordPress core
        if (
            strpos($source_url, '/wp-includes/') !== false ||
            strpos($source_url, '/wp-admin/') !== false
        ) {
            return ['type' => 'core', 'name' => 'WordPress Core'];
        }

        // Inline script or unknown source
        return ['type' => 'inline', 'name' => 'Inline Script'];
    }

    /**
     * Get plugin name from slug
     */
    private function get_plugin_name($slug)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        foreach ($plugins as $file => $data) {
            if (strpos($file, $slug . '/') === 0 || $file === $slug . '.php') {
                return $data['Name'];
            }
        }

        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * Store an error
     */
    private function store_error($data)
    {
        $errors = get_transient(self::ERRORS_TRANSIENT);
        if (!is_array($errors)) {
            $errors = [];
        }

        // Add the new error
        array_unshift($errors, [
            'type' => sanitize_text_field($data['type'] ?? 'error'),
            'message' => sanitize_text_field(substr($data['message'] ?? '', 0, 1000)),
            'source' => esc_url_raw($data['source'] ?? ''),
            'line' => absint($data['line'] ?? 0),
            'column' => absint($data['column'] ?? 0),
            'stack' => sanitize_textarea_field(substr($data['stack'] ?? '', 0, 2000)),
            'page' => esc_url_raw($data['page'] ?? ''),
            'attribution' => $data['attribution'] ?? ['type' => 'unknown'],
            'timestamp' => $data['timestamp'] ?? time() * 1000,
            'recorded_at' => current_time('mysql'),
        ]);

        // Keep only recent errors
        $errors = array_slice($errors, 0, self::MAX_ERRORS);

        // Store with 24-hour expiry
        set_transient(self::ERRORS_TRANSIENT, $errors, DAY_IN_SECONDS);
    }

    /**
     * Get recent errors
     */
    public function get_errors($request = null)
    {
        $limit = 50;
        $since = null;

        if ($request) {
            $limit = min(100, absint($request->get_param('limit') ?? 50));
            $since = $request->get_param('since'); // Timestamp in ms
        }

        $errors = get_transient(self::ERRORS_TRANSIENT);
        if (!is_array($errors)) {
            $errors = [];
        }

        // Filter by time if specified
        if ($since) {
            $errors = array_filter($errors, function ($e) use ($since) {
                return ($e['timestamp'] ?? 0) > $since;
            });
        }

        $errors = array_slice($errors, 0, $limit);

        // Group by attribution for summary
        $summary = [];
        foreach ($errors as $error) {
            $key = ($error['attribution']['type'] ?? 'unknown') . ':' .
                ($error['attribution']['slug'] ?? $error['attribution']['name'] ?? 'unknown');
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'attribution' => $error['attribution'],
                    'count' => 0,
                    'latest' => $error['message']
                ];
            }
            $summary[$key]['count']++;
        }

        return new WP_REST_Response([
            'errors' => array_values($errors),
            'total' => count($errors),
            'summary' => array_values($summary)
        ], 200);
    }

    /**
     * Get errors since a specific time (for use in scans)
     */
    public function get_errors_since($timestamp_ms)
    {
        $errors = get_transient(self::ERRORS_TRANSIENT);
        if (!is_array($errors)) {
            return [];
        }

        return array_filter($errors, function ($e) use ($timestamp_ms) {
            return ($e['timestamp'] ?? 0) > $timestamp_ms;
        });
    }

    /**
     * Clear all stored errors
     */
    public function clear_errors()
    {
        delete_transient(self::ERRORS_TRANSIENT);
        return new WP_REST_Response(['cleared' => true], 200);
    }

    /**
     * Get error count (for quick status checks)
     */
    public function get_error_count($hours = 1)
    {
        $errors = get_transient(self::ERRORS_TRANSIENT);
        if (!is_array($errors)) {
            return 0;
        }

        $cutoff = (time() - ($hours * 3600)) * 1000;

        return count(array_filter($errors, function ($e) use ($cutoff) {
            return ($e['timestamp'] ?? 0) > $cutoff;
        }));
    }
}
