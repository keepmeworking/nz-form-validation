<?php
class NZFormValidationAdmin {
    private $db;
    private $updater;

    public function __construct($db, $updater) {
        $this->db = $db;
        $this->updater = $updater;
    }

    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_admin_menu() {
        add_options_page(
            'NZ Form Validation Settings',
            'NZ Form Validation',
            'manage_options',
            'nz-form-validation-settings',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting('nz_form_validation', 'nz_form_validation_settings');
    }

    public function settings_page() {
        $settings = get_option('nz_form_validation_settings', array(
            'min_words' => 5,
            'max_words' => 500,
            'allow_urls' => false,
            'url_limit' => 2,
            'custom_error_message' => '',
            'rate_limit' => 5,
            'rate_limit_period' => 3600,
        ));

        $remote_info = $this->updater->get_remote_info();
        ?>
        <div class="wrap">
            <h1>NZ Form Validation Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('nz_form_validation');
                do_settings_sections('nz_form_validation');
                ?>
                <table class="form-table">
                    <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Minimum Words</th>
                        <td><input type="number" name="nz_form_validation_settings[min_words]" value="<?php echo esc_attr($settings['min_words']);?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Maximum Words</th>
                        <td><input type="number" name="nz_form_validation_settings[max_words]" value="<?php echo esc_attr($settings['max_words']);?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Allow URLs</th>
                        <td><input type="checkbox" name="nz_form_validation_settings[allow_urls]" value="1" <?php checked(1, $settings['allow_urls'], true);?> /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">URL Limit</th>
                        <td><input type="number" name="nz_form_validation_settings[url_limit]" value="<?php echo esc_attr($settings['url_limit']);?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Custom Error Message</th>
                        <td><textarea name="nz_form_validation_settings[custom_error_message]" rows="3" cols="50"><?php echo esc_textarea($settings['custom_error_message']);?></textarea></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Rate Limit (submissions per period)</th>
                        <td><input type="number" name="nz_form_validation_settings[rate_limit]" value="<?php echo esc_attr($settings['rate_limit']);?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Rate Limit Period (seconds)</th>
                        <td><input type="number" name="nz_form_validation_settings[rate_limit_period]" value="<?php echo esc_attr($settings['rate_limit_period']);?>" /></td>
                    </tr>
                </table>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php
                $remote_info = $this->updater->get_remote_info();
                $current_version = $this->updater->version;
                $latest_version = isset($remote_info['updated_version']) ? $remote_info['updated_version'] : 'Unknown';
            
                // Display version information
                echo "<h2>Plugin Version Management</h2>";
                echo "<p>Current Version: " . esc_html($current_version) . "</p>";
                echo "<p>Latest Version: " . esc_html($latest_version) . "</p>";
            
                if (version_compare($current_version, $latest_version, '<')) {
                    echo "<p>An update is available. Please use the WordPress update system to update this plugin.</p>";
            }?>

            <h3>Rollback to Previous Version</h3>
            <form method="post" action="">
                <?php wp_nonce_field('nz_form_validation_rollback'); ?>
                <select name="rollback_version">
                    <?php foreach ($remote_info['rollback_versions'] as $version): ?>
                        <option value="<?php echo esc_attr($version['version']); ?>">
                            <?php echo esc_html($version['version']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" name="nz_form_validation_rollback" class="button" value="Rollback">
            </form>
        </div>
        <?php
    }
}