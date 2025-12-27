<?php

abstract class SBWP_Pro_Remote_Storage
{
    protected $id;
    protected $name;

    public function __construct($id, $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    public function get_id()
    {
        return $this->id;
    }

    public function get_name()
    {
        return $this->name;
    }

    abstract public function is_connected();
    abstract public function connect($credentials);
    abstract public function disconnect();
    abstract public function upload($file_path);
    abstract public function download($remote_id, $target_path);
}

class SBWP_Pro_Remote_GDrive extends SBWP_Pro_Remote_Storage
{
    public function __construct()
    {
        parent::__construct('gdrive', 'Google Drive');
    }

    public function is_connected()
    {
        $tokens = get_option('sbwp_pro_gdrive_tokens');
        return !empty($tokens);
    }

    public function connect($credentials)
    {
        // In a real app, this would exchange code for token or save API keys.
        // For MVP Mock: Just save whatever is passed to simulate connection.
        update_option('sbwp_pro_gdrive_tokens', array(
            'access_token' => 'mock_access_token_' . time(),
            'refresh_token' => 'mock_refresh_token',
            'created_at' => time()
        ));
        return true;
    }

    public function disconnect()
    {
        delete_option('sbwp_pro_gdrive_tokens');
        return true;
    }

    public function upload($file_path)
    {
        // Mock upload
        $token = get_option('sbwp_pro_gdrive_tokens');
        if (!$token)
            return new WP_Error('not_connected', 'Google Drive is not connected');

        // Simulate network delay? 
        return array(
            'remote_id' => 'gdrive_file_' . basename($file_path),
            'url' => 'https://drive.google.com/file/d/mock_id',
            'provider' => 'gdrive'
        );
    }

    public function download($remote_id, $target_path)
    {
        // Mock download
        // In real app, we'd fetch stream. Here we can't really restore a mock file.
        return new WP_Error('mock_impl', 'This is a mock implementation. Cannot download real files.');
    }
}
