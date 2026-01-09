<?php
/**
 * Custom Code Scanner
 * 
 * Detects and analyzes custom code from various sources:
 * - Code Snippets plugin
 * - WPCodeBox, Woody Snippets, etc.
 * - Theme functions.php
 * - Must-use plugins (mu-plugins)
 * - Child theme files
 * 
 * @package SafeBackup_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBWP_Custom_Code_Scanner
{

    /**
     * Known snippet plugin DB tables
     */
    private $snippet_tables = array(
        'code_snippets' => array(
            'table' => 'snippets',
            'name_col' => 'name',
            'code_col' => 'code',
            'active_col' => 'active'
        ),
        'wpcodebox' => array(
            'table' => 'wpcodebox_snippets',
            'name_col' => 'title',
            'code_col' => 'code',
            'active_col' => 'is_active'
        ),
        'woody' => array(
            'table' => 'woodysnippets',
            'name_col' => 'name',
            'code_col' => 'code',
            'active_col' => 'active'
        )
    );

    /**
     * Dangerous patterns to look for
     */
    private $dangerous_patterns = array(
        'eval(' => array('severity' => 'error', 'message' => 'Uses eval() which can be dangerous'),
        'base64_decode(' => array('severity' => 'warning', 'message' => 'Uses base64_decode which may hide malicious code'),
        'create_function(' => array('severity' => 'error', 'message' => 'Uses deprecated create_function()'),
        'extract(' => array('severity' => 'warning', 'message' => 'Uses extract() which can cause variable collisions'),
        '@file_get_contents' => array('severity' => 'warning', 'message' => 'Error suppression on external requests'),
        'mysql_' => array('severity' => 'error', 'message' => 'Uses deprecated mysql_* functions'),
        'ereg(' => array('severity' => 'error', 'message' => 'Uses deprecated ereg function'),
        'wp_remote_get' => array('severity' => 'info', 'message' => 'Makes external HTTP requests'),
    );

    /**
     * Scan all custom code sources
     */
    public function scan_all()
    {
        $sources = array();
        $issues = array();

        // Check for Code Snippets plugin
        $snippets = $this->detect_code_snippets();
        if (!empty($snippets['snippets'])) {
            $sources['code_snippets'] = $snippets;
            $issues = array_merge($issues, $this->analyze_snippets($snippets['snippets'], 'code_snippets'));
        }

        // Check for WPCodeBox
        $wpcodebox = $this->detect_wpcodebox();
        if (!empty($wpcodebox['snippets'])) {
            $sources['wpcodebox'] = $wpcodebox;
            $issues = array_merge($issues, $this->analyze_snippets($wpcodebox['snippets'], 'wpcodebox'));
        }

        // Check functions.php
        $functions = $this->scan_functions_php();
        if (!empty($functions['code'])) {
            $sources['functions_php'] = $functions;
            $issues = array_merge($issues, $this->analyze_code($functions['code'], 'functions.php', 'theme'));
        }

        // Check mu-plugins
        $mu = $this->scan_mu_plugins();
        if (!empty($mu['files'])) {
            $sources['mu_plugins'] = $mu;
            foreach ($mu['files'] as $file) {
                if (!empty($file['code'])) {
                    $issues = array_merge($issues, $this->analyze_code($file['code'], $file['name'], 'mu_plugin'));
                }
            }
        }

        // Check child theme
        $child = $this->scan_child_theme();
        if (!empty($child['files'])) {
            $sources['child_theme'] = $child;
        }

        return array(
            'sources' => $sources,
            'issues' => $issues,
            'summary' => $this->build_summary($sources, $issues)
        );
    }

    /**
     * Detect Code Snippets plugin snippets
     */
    public function detect_code_snippets()
    {
        global $wpdb;

        // Check if table exists
        $table = $wpdb->prefix . 'snippets';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");

        if (!$table_exists) {
            return array('installed' => false, 'snippets' => array());
        }

        $snippets = $wpdb->get_results("
            SELECT id, name, code, active, scope 
            FROM $table 
            ORDER BY active DESC, id DESC 
            LIMIT 50
        ", ARRAY_A);

        return array(
            'installed' => true,
            'plugin' => 'Code Snippets',
            'snippets' => array_map(function ($s) {
                return array(
                    'id' => $s['id'],
                    'name' => $s['name'],
                    'code' => $s['code'],
                    'active' => (bool) $s['active'],
                    'scope' => $s['scope'] ?? 'global',
                    'lines' => substr_count($s['code'], "\n") + 1
                );
            }, $snippets ?: array())
        );
    }

    /**
     * Detect WPCodeBox snippets
     */
    public function detect_wpcodebox()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wpcodebox_snippets';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");

        if (!$table_exists) {
            return array('installed' => false, 'snippets' => array());
        }

        $snippets = $wpdb->get_results("
            SELECT id, title, code, is_active 
            FROM $table 
            ORDER BY is_active DESC, id DESC 
            LIMIT 50
        ", ARRAY_A);

        return array(
            'installed' => true,
            'plugin' => 'WPCodeBox',
            'snippets' => array_map(function ($s) {
                return array(
                    'id' => $s['id'],
                    'name' => $s['title'],
                    'code' => $s['code'],
                    'active' => (bool) $s['is_active'],
                    'lines' => substr_count($s['code'], "\n") + 1
                );
            }, $snippets ?: array())
        );
    }

    /**
     * Scan theme functions.php
     */
    public function scan_functions_php()
    {
        $theme = wp_get_theme();
        $functions_file = get_stylesheet_directory() . '/functions.php';

        if (!file_exists($functions_file)) {
            return array('exists' => false, 'code' => '');
        }

        $code = @file_get_contents($functions_file);

        return array(
            'exists' => true,
            'theme' => $theme->get('Name'),
            'path' => $functions_file,
            'code' => $code,
            'lines' => $code ? substr_count($code, "\n") + 1 : 0,
            'size' => filesize($functions_file)
        );
    }

    /**
     * Scan mu-plugins directory
     */
    public function scan_mu_plugins()
    {
        $mu_dir = WPMU_PLUGIN_DIR;

        if (!is_dir($mu_dir)) {
            return array('exists' => false, 'files' => array());
        }

        $files = array();
        $php_files = glob($mu_dir . '/*.php');

        foreach ($php_files ?: array() as $file) {
            $code = @file_get_contents($file);
            $files[] = array(
                'name' => basename($file),
                'path' => $file,
                'code' => $code,
                'lines' => $code ? substr_count($code, "\n") + 1 : 0,
                'size' => filesize($file)
            );
        }

        return array(
            'exists' => true,
            'path' => $mu_dir,
            'files' => $files,
            'count' => count($files)
        );
    }

    /**
     * Scan child theme for custom code
     */
    public function scan_child_theme()
    {
        $parent_theme = get_template();
        $child_theme = get_stylesheet();

        // Check if it's actually a child theme
        if ($parent_theme === $child_theme) {
            return array('is_child' => false, 'files' => array());
        }

        $child_dir = get_stylesheet_directory();
        $files = array();

        // Get PHP files in child theme root
        $php_files = glob($child_dir . '/*.php');
        foreach ($php_files ?: array() as $file) {
            $name = basename($file);
            if ($name === 'functions.php')
                continue; // Scanned separately

            $files[] = array(
                'name' => $name,
                'path' => $file,
                'lines' => count(file($file)),
                'size' => filesize($file)
            );
        }

        return array(
            'is_child' => true,
            'parent_theme' => $parent_theme,
            'child_theme' => $child_theme,
            'files' => $files
        );
    }

    /**
     * Analyze snippets for issues
     */
    private function analyze_snippets($snippets, $source_type)
    {
        $issues = array();

        foreach ($snippets as $snippet) {
            if (empty($snippet['code']))
                continue;

            $snippet_issues = $this->analyze_code(
                $snippet['code'],
                $snippet['name'],
                $source_type
            );

            // Add snippet context to issues
            foreach ($snippet_issues as &$issue) {
                $issue['snippet_id'] = $snippet['id'] ?? null;
                $issue['snippet_active'] = $snippet['active'] ?? false;
            }

            $issues = array_merge($issues, $snippet_issues);
        }

        return $issues;
    }

    /**
     * Analyze code for dangerous patterns
     */
    private function analyze_code($code, $name, $source_type)
    {
        $issues = array();

        if (empty($code))
            return $issues;

        foreach ($this->dangerous_patterns as $pattern => $info) {
            if (stripos($code, $pattern) !== false) {
                $issues[] = array(
                    'source' => array('type' => $source_type, 'name' => $name),
                    'pattern' => $pattern,
                    'severity' => $info['severity'],
                    'message' => $info['message']
                );
            }
        }

        // Check for PHP syntax errors (basic)
        $syntax_check = $this->check_syntax($code);
        if ($syntax_check !== true) {
            $issues[] = array(
                'source' => array('type' => $source_type, 'name' => $name),
                'severity' => 'error',
                'message' => 'Possible syntax error: ' . $syntax_check
            );
        }

        return $issues;
    }

    /**
     * Basic syntax check using PHP's tokenizer
     */
    private function check_syntax($code)
    {
        // Add PHP opening tag if not present
        if (strpos($code, '<?php') === false && strpos($code, '<?') === false) {
            $code = '<?php ' . $code;
        }

        try {
            $tokens = @token_get_all($code);
            if ($tokens === false) {
                return 'Failed to tokenize code';
            }

            // Check for obviously unbalanced braces
            $braces = 0;
            $parens = 0;
            foreach ($tokens as $token) {
                if (is_string($token)) {
                    if ($token === '{')
                        $braces++;
                    if ($token === '}')
                        $braces--;
                    if ($token === '(')
                        $parens++;
                    if ($token === ')')
                        $parens--;
                }
            }

            if ($braces !== 0) {
                return 'Unbalanced braces (missing ' . ($braces > 0 ? 'closing }' : 'opening {') . ')';
            }
            if ($parens !== 0) {
                return 'Unbalanced parentheses';
            }

            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Build a summary of findings
     */
    private function build_summary($sources, $issues)
    {
        $summary = array(
            'custom_code_sources' => count($sources),
            'total_issues' => count($issues),
            'errors' => count(array_filter($issues, function ($i) {
                return $i['severity'] === 'error'; })),
            'warnings' => count(array_filter($issues, function ($i) {
                return $i['severity'] === 'warning'; })),
            'sources_list' => array_keys($sources)
        );

        return $summary;
    }

    /**
     * Get a code inventory (for AI context)
     */
    public function get_code_inventory()
    {
        $inventory = array();

        // Check for Code Snippets
        $snippets = $this->detect_code_snippets();
        if ($snippets['installed']) {
            $active = count(array_filter($snippets['snippets'], function ($s) {
                return $s['active']; }));
            $inventory[] = "Code Snippets plugin: {$active} active snippets";
        }

        // Check functions.php
        $functions = $this->scan_functions_php();
        if ($functions['exists'] && $functions['lines'] > 10) {
            $inventory[] = "Theme functions.php: {$functions['lines']} lines";
        }

        // Check mu-plugins
        $mu = $this->scan_mu_plugins();
        if ($mu['exists'] && $mu['count'] > 0) {
            $inventory[] = "Must-use plugins: {$mu['count']} files";
        }

        // Check child theme
        $child = $this->scan_child_theme();
        if ($child['is_child'] && count($child['files']) > 0) {
            $inventory[] = "Child theme: " . count($child['files']) . " custom files";
        }

        return $inventory;
    }
}
