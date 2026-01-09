<?php
/**
 * Clone Bootstrapper
 * 
 * Intercepts requests with ?sbwp_clone=xxx parameter and overrides
 * key options (active_plugins, stylesheet, template) from cloned DB tables.
 * 
 * This allows conflict testing without fully switching the database prefix.
 * 
 * @package SafeBackup_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_Clone_Bootstrap
{

    private $clone_id;
    private $clone_prefix;
    private $original_prefix;
    private $overridden_options = array();

    /**
     * Initialize the bootstrapper
     */
    public function init()
    {
        // Only run on clone requests
        if (!isset($_GET['sbwp_clone']) || empty($_GET['sbwp_clone'])) {
            return false;
        }

        // Validate clone ID format (sb_XXXXXX)
        $clone_id = sanitize_key($_GET['sbwp_clone']);
        if (!preg_match('/^sb_[a-f0-9]{6}$/', $clone_id)) {
            return false;
        }

        $this->clone_id = $clone_id;
        $this->clone_prefix = $clone_id . '_';

        global $wpdb;
        $this->original_prefix = $wpdb->prefix;

        // Define constant so rest of plugin knows we're in clone mode
        if (!defined('SBWP_CLONE_ID')) {
            define('SBWP_CLONE_ID', $clone_id);
            define('SBWP_CLONE_PREFIX', $this->clone_prefix);
        }

        error_log('SBWP Clone Bootstrap: Activating for clone ' . $clone_id);

        // Override key options with values from clone tables
        $this->load_cloned_options();

        // Hook into option retrieval to return cloned values
        add_filter('pre_option_active_plugins', array($this, 'get_cloned_active_plugins'));
        add_filter('pre_option_stylesheet', array($this, 'get_cloned_stylesheet'));
        add_filter('pre_option_template', array($this, 'get_cloned_template'));

        return true;
    }

    /**
     * Load options from cloned tables
     */
    private function load_cloned_options()
    {
        global $wpdb;

        $clone_options_table = $this->clone_prefix . 'options';

        // Check if clone options table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $clone_options_table)
        );

        if (!$table_exists) {
            error_log('SBWP Clone Bootstrap: Clone options table not found: ' . $clone_options_table);
            return;
        }

        // Load key options from clone
        $options_to_load = array('active_plugins', 'stylesheet', 'template');

        foreach ($options_to_load as $option_name) {
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$clone_options_table} WHERE option_name = %s",
                $option_name
            ));

            if ($value !== null) {
                $this->overridden_options[$option_name] = maybe_unserialize($value);
                error_log("SBWP Clone Bootstrap: Loaded {$option_name} from clone");
            }
        }
    }

    /**
     * Return cloned active_plugins
     */
    public function get_cloned_active_plugins($value)
    {
        if (isset($this->overridden_options['active_plugins'])) {
            return $this->overridden_options['active_plugins'];
        }
        return $value;
    }

    /**
     * Return cloned stylesheet (child theme)
     */
    public function get_cloned_stylesheet($value)
    {
        if (isset($this->overridden_options['stylesheet'])) {
            return $this->overridden_options['stylesheet'];
        }
        return $value;
    }

    /**
     * Return cloned template (parent theme)
     */
    public function get_cloned_template($value)
    {
        if (isset($this->overridden_options['template'])) {
            return $this->overridden_options['template'];
        }
        return $value;
    }

    /**
     * Check if we're in clone mode
     */
    public static function is_clone_mode()
    {
        return defined('SBWP_CLONE_ID');
    }

    /**
     * Get current clone ID
     */
    public static function get_clone_id()
    {
        return defined('SBWP_CLONE_ID') ? SBWP_CLONE_ID : null;
    }
}

// Auto-initialize if this file is loaded
// Hook as early as possible with 'muplugins_loaded' priority
add_action('muplugins_loaded', function () {
    $bootstrap = new SBWP_Clone_Bootstrap();
    $bootstrap->init();
}, 1);

// Fallback: also try on plugins_loaded in case muplugins_loaded is too late
add_action('plugins_loaded', function () {
    if (!defined('SBWP_CLONE_ID') && isset($_GET['sbwp_clone'])) {
        $bootstrap = new SBWP_Clone_Bootstrap();
        $bootstrap->init();
    }
}, 1);
