<?php
/**
 * Silent Upgrader Skin
 * 
 * A WordPress upgrader skin that captures output and errors silently
 * without displaying anything to the user.
 */

if (!class_exists('WP_Upgrader_Skin')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
}

class SBWP_Silent_Upgrader_Skin extends WP_Upgrader_Skin
{
    public $messages = array();
    public $errors = array();
    public $done_header = false;
    public $done_footer = false;

    public function __construct($args = array())
    {
        parent::__construct($args);
    }

    public function header()
    {
        $this->done_header = true;
    }

    public function footer()
    {
        $this->done_footer = true;
    }

    public function feedback($string, ...$args)
    {
        if (!empty($string)) {
            if ($args) {
                $string = vsprintf($string, $args);
            }
            $this->messages[] = $string;
        }
    }

    public function error($errors)
    {
        if (is_string($errors)) {
            $this->errors[] = $errors;
        } elseif (is_wp_error($errors)) {
            foreach ($errors->get_error_messages() as $message) {
                $this->errors[] = $message;
            }
        }
    }

    public function before()
    {
        // Silent
    }

    public function after()
    {
        // Silent
    }

    public function get_errors()
    {
        return $this->errors;
    }

    public function get_messages()
    {
        return $this->messages;
    }

    public function has_errors()
    {
        return !empty($this->errors);
    }
}
