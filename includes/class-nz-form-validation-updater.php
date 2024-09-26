<?php
class NZFormValidationUpdater {
    public $plugin_slug;
    public $version;
    public $update_url;
    public $plugin_file;

    public function __construct($plugin_slug, $version, $update_url, $plugin_file) {
        $this->plugin_slug = $plugin_slug;
        $this->version = $version;
        $this->update_url = $update_url;
        $this->plugin_file = $plugin_file;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_action('upgrader_process_complete', array($this, 'after_update'), 10, 2);
        add_action('admin_init', array($this, 'handle_rollback'));

        add_action('wp_update_plugins', array($this, 'handle_auto_update'));
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote_info = $this->get_remote_info();

        if ($remote_info && isset($remote_info['updated_version']) && 
            version_compare($this->version, $remote_info['updated_version'], '<')) {
            
            // Check if auto-update is enabled
            if ($remote_info['auto_update'] ?? false) {
                // Automatic update
                $obj = new stdClass();
                $obj->slug = $this->plugin_slug;
                $obj->new_version = $remote_info['updated_version'];
                $obj->url = $this->update_url;
                $obj->package = isset($remote_info['download_url'])? $remote_info['download_url'] : '';
                $transient->response[$this->plugin_file] = $obj;
            } else {
                // Manual update
                $obj = new stdClass();
                $obj->slug = $this->plugin_slug;
                $obj->new_version = $remote_info['updated_version'];
                $obj->url = $this->update_url;
                $obj->package = isset($remote_info['download_url'])? $remote_info['download_url'] : '';
                $transient->response[$this->plugin_file] = $obj;
            }
        }

        return $transient;
    }

    public function plugin_info($false, $action, $response) {
        if ($action != 'plugin_information') {
            return $false;
        }

        if (!isset($response->slug) || $response->slug != $this->plugin_slug) {
            return $false;
        }

        $remote_info = $this->get_remote_info();

        if (!$remote_info) {
            return $false;
        }

        $response = new stdClass();
        $response->name = isset($remote_info['name']) ? $remote_info['name'] : '';
        $response->slug = $this->plugin_slug;
        $response->version = isset($remote_info['updated_version']) ? $remote_info['updated_version'] : '';
        $response->author = isset($remote_info['author']) ? $remote_info['author'] : '';
        $response->homepage = $this->update_url;
        $response->requires = '5.0';
        $response->tested = '6.0';
        $response->downloaded = 0;
        $response->last_updated = date('Y-m-d');
        $response->sections = array(
            'description' => isset($remote_info['description']) ? $remote_info['description'] : '',
            'changelog' => 'Visit the plugin homepage for the changelog.'
        );
        $response->download_link = isset($remote_info['download_url']) ? $remote_info['download_url'] : '';

        return $response;
    }

    public function after_update($upgrader_object, $options) {
        if ($options['action'] == 'update' && $options['type'] == 'plugin' && isset($options['plugins'])) {
            $our_plugin = plugin_basename($this->plugin_file);
            if (in_array($our_plugin, $options['plugins'])) {
                $this->extract_and_replace_plugin();
            }
        }
        delete_site_transient('update_plugins');
    }

    private function extract_and_replace_plugin() {
        $plugin_slug = dirname(plugin_basename($this->plugin_file));
        $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
        $temp_dir = WP_CONTENT_DIR . '/upgrade/' . $plugin_slug . '_temp';
    
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        WP_Filesystem();
        global $wp_filesystem;
    
        if (!$wp_filesystem->mkdir($temp_dir)) {
            wp_die('Failed to create temporary directory.');
        }
    
        $download_file = download_url($this->get_remote_info()['download_url']);
        if (is_wp_error($download_file)) {
            wp_die('Error downloading the update file.');
        }
    
        $unzipfile = unzip_file($download_file, $temp_dir);
        if (is_wp_error($unzipfile)) {
            $wp_filesystem->delete($temp_dir, true);
            wp_die('Error unzipping the file.');
        }
    
        $extracted_folder = $this->find_extracted_folder($temp_dir);
        if (!$extracted_folder) {
            $wp_filesystem->delete($temp_dir, true);
            wp_die('Could not find the extracted plugin folder.');
        }
    
        $renamed_folder_path = $temp_dir . '/' . $plugin_slug;
    
        if ($extracted_folder !== $renamed_folder_path && !$wp_filesystem->move($extracted_folder, $renamed_folder_path)) {
            $wp_filesystem->delete($temp_dir, true);
            wp_die('Could not rename the extracted plugin folder.');
        }
    
        $this->remove_old_plugin_versions($plugin_slug);
    
        if ($wp_filesystem->exists($plugin_dir)) {
            $this->delete_plugin_directory($plugin_dir);
        }
    
        if (!$wp_filesystem->move($renamed_folder_path, $plugin_dir)) {
            $wp_filesystem->delete($temp_dir, true);
            wp_die('Could not move the updated plugin folder.');
        }
    
        $wp_filesystem->delete($temp_dir, true);
        @unlink($download_file);
    
        $this->recursive_chmod($plugin_dir);
    
        $this->verify_single_plugin_version($plugin_slug);
    
        wp_clean_plugins_cache();
    }

    private function find_extracted_folder($dir) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..' && is_dir($dir . '/' . $file)) {
                return $dir . '/' . $file;
            }
        }
        return false;
    }

    private function recursive_chmod($dir, $dirperms = 0755, $fileperms = 0644) {
        $dp = opendir($dir);
        while ($file = readdir($dp)) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $fullpath = $dir . '/' . $file;
            if (is_dir($fullpath)) {
                chmod($fullpath, $dirperms);
                $this->recursive_chmod($fullpath, $dirperms, $fileperms);
            } else {
                chmod($fullpath, $fileperms);
            }
        }
        closedir($dp);
    }

    private function remove_old_plugin_versions($plugin_slug) {
        $plugins_dir = WP_PLUGIN_DIR;
        $dirs = glob($plugins_dir . '/' . $plugin_slug . '-*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $this->delete_plugin_directory($dir);
            error_log("Removed old plugin version: " . $dir);
        }
    }

    private function verify_single_plugin_version($plugin_slug) {
        $plugins_dir = WP_PLUGIN_DIR;
        $matching_dirs = glob($plugins_dir . '/' . $plugin_slug . '*', GLOB_ONLYDIR);
        
        if (count($matching_dirs) > 1) {
            error_log("WARNING: Multiple versions of the plugin still exist after update:");
            foreach ($matching_dirs as $dir) {
                error_log($dir);
            }
        } else {
            error_log("Verified: Only one version of the plugin exists after update.");
        }
    }

    public function handle_rollback() {
        if (isset($_POST['nz_form_validation_rollback']) && check_admin_referer('nz_form_validation_rollback')) {
            $version = sanitize_text_field($_POST['rollback_version']);
            $this->do_rollback($version);
        }
    }

    public function do_rollback($version) {
        $remote_info = $this->get_remote_info();
        $rollback_url = '';

        if (isset($remote_info['rollback_versions'])) {
            foreach ($remote_info['rollback_versions'] as $rollback_version) {
                if ($rollback_version['version'] == $version) {
                    $rollback_url = $rollback_version['download_url'];
                    break;
                }
            }
        }

        if (empty($rollback_url)) {
            wp_die('Invalid rollback version.');
        }

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname(plugin_basename($this->plugin_file));
        $temp_dir = WP_CONTENT_DIR . '/upgrade/' . basename($plugin_dir) . '_temp';

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        WP_Filesystem();
        global $wp_filesystem;

        $wp_filesystem->mkdir($temp_dir);

        $download_file = download_url($rollback_url);
        $unzipfile = unzip_file($download_file, $temp_dir);

        if (is_wp_error($unzipfile)) {
            $wp_filesystem->delete($temp_dir, true);
            wp_die('There was an error unzipping the file.');
        }

        $extracted_folder = $this->find_extracted_folder($temp_dir);

        if (!$extracted_folder) {
            $wp_filesystem->delete($temp_dir, true);
            wp_die('Could not find the extracted plugin folder.');
        }

        $this->delete_plugin_directory($plugin_dir);

        $wp_filesystem->move($extracted_folder, $plugin_dir);

        $wp_filesystem->delete($temp_dir, true);
        @unlink($download_file);

        $this->recursive_chmod($plugin_dir);

        wp_clean_plugins_cache();

        activate_plugin($this->plugin_file);

        wp_redirect(admin_url('admin.php?page=nz-form-validation-settings&rollback=success'));
        exit;
    }
    
    public function handle_auto_update($transient) {
        if ($this->check_for_update($transient)) {
            $this->extract_and_replace_plugin();
        }
    }

    private function delete_plugin_directory($dir) {
        global $wp_filesystem;
        if ($wp_filesystem->exists($dir)) {
            $wp_filesystem->delete($dir, true);
            error_log("Deleted directory: " . $dir);
        } else {
            error_log("Directory does not exist, cannot delete: " . $dir);
        }
    }

    public function get_remote_info() {
        $request = wp_remote_get($this->update_url);
        
        if (is_wp_error($request)) {
            error_log("Error fetching remote info: " . $request->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($request);
        
        if ($response_code != 200) {
            error_log("Unexpected response code when fetching remote info: " . $response_code);
            return false;
        }
        
        $body = wp_remote_retrieve_body($request);
        
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Error decoding JSON from remote info: " . json_last_error_msg());
            return false;
        }
        
        return $data;
    }
}   