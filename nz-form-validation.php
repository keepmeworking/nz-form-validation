<?php
/**
 * Plugin Name: NZ Form Validation
 * Plugin URI: https://github.com/keepmeworking/repository
 * Description: Validates form fields for New Zealand websites and prevents spam submissions
 * Version: 1.0.0
 * Author: Gaurav Khokher - Developer
 * Author URI: https://yourwebsite.com
 * GitHub Plugin URI: https://github.com/keepmeworking/repository
 * GitHub Branch: main
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/class-nz-form-validation.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-nz-form-validation-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-nz-form-validation-db.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-nz-form-validation-updater.php';

function nz_form_validation_init() {
    global $nz_form_validation_updater;

    $db = new NZFormValidationDB();
    $plugin = new NZFormValidation($db);
    $plugin->init();

    $plugin_slug = 'nz-form-validation';
    $version = '3.0'; // Current version
    $update_url = 'https://toprankdigital.nz/wp-plugins/plugin-version-info.json';
    $plugin_file = plugin_basename(__FILE__);

    $nz_form_validation_updater = new NZFormValidationUpdater($plugin_slug, $version, $update_url, $plugin_file);

    if (is_admin()) {
        $admin = new NZFormValidationAdmin($db, $nz_form_validation_updater);
        $admin->init();
    }
}

nz_form_validation_init();

// Schedule daily spam data update
if (!wp_next_scheduled('nz_form_validation_update_spam_data')) {
    wp_schedule_event(time(), 'daily', 'nz_form_validation_update_spam_data');
}

add_action('nz_form_validation_update_spam_data', 'nz_form_validation_update_spam_data');

function nz_form_validation_update_spam_data() {
    $db = new NZFormValidationDB();
    $db->update_spam_data_from_source();
}

// Add rewrite rule for rollback
add_action('init', function() {
    add_rewrite_rule(
        '^myplugin/rollback/([^/]+)/?$',
        'index.php?rollback_version=$matches[1]',
        'top'
    );
});

// Handle rollback request
add_action('template_redirect', function() {
    global $nz_form_validation_updater;
    $rollback_version = get_query_var('rollback_version');
    if ($rollback_version) {
        $nz_form_validation_updater->do_rollback($rollback_version);
        exit;
    }
});


// function nz_form_validation_verify_transient() {
//     $transient = get_site_transient('update_plugins');
//     $plugin_file = plugin_basename(__FILE__); 
// }
// add_action('admin_init', 'nz_form_validation_verify_transient');





// require_once plugin_dir_path(__FILE__) . 'includes/class-nz-form-validation.php';
// require_once plugin_dir_path(__FILE__) . 'includes/class-nz-form-validation-admin.php';
// require_once plugin_dir_path(__FILE__) . 'includes/class-nz-form-validation-db.php';
// require_once plugin_dir_path(__FILE__) . 'includes/class-supported-form-logic.php';

// function nz_form_validation_init() {
//     $db = new NZFormValidationDB();
//     $form_logic = new SupportedFormLogic($db);
//     $form_logic->init();
//     // $plugin = new NZFormValidation($db, $form_logic);
//     // $plugin->init();

//     if (is_admin()) {
//         $admin = new NZFormValidationAdmin($db);
//         $admin->init();
//     }
// }


// nz_form_validation_init();

// // Schedule daily spam data update
// if (!wp_next_scheduled('nz_form_validation_update_spam_data')) {
//     wp_schedule_event(time(), 'daily', 'nz_form_validation_update_spam_data');
// }

// add_action('nz_form_validation_update_spam_data', 'nz_form_validation_update_spam_data');

// function nz_form_validation_update_spam_data() {
//     $db = new NZFormValidationDB();
//     $db->update_spam_data_from_source();
// }

// // Activation hook
// register_activation_hook(__FILE__, 'nz_form_validation_activate');

// function nz_form_validation_activate() {
//     $db = new NZFormValidationDB();
//     $db->create_tables();
// }

// // Deactivation hook
// register_deactivation_hook(__FILE__, 'nz_form_validation_deactivate');

// function nz_form_validation_deactivate() {
//     wp_clear_scheduled_hook('nz_form_validation_update_spam_data');
// }





// function nz_form_validation_load_data($filename) {
//     $path = plugin_dir_path(__FILE__) . "data/{$filename}";

//     if (file_exists($path)) {
//         return json_decode(file_get_contents($path), true);
//     }

//     return [];
// }

// function custom_cf7_spam_validation($result) {
//     // Load spam keywords and temporary email domains from JSON files
//     $spam_keywords = nz_form_validation_load_data('spam_keywords.json');
//     $temp_email_domains = nz_form_validation_load_data('temp_email_domains.json');

//     // Get all submitted data
//     $submitted_data = $_POST;

//     foreach ($submitted_data as $field_name => $field_value) {
//         // Sanitize the input
//         $field_value = sanitize_textarea_field($field_value);

//         // Check for HTML tags
//         if (strip_tags($field_value) !== $field_value) {
//             $result->invalidate($field_name, "HTML tags are not allowed in this field.");
//         }

//         // Check for URLs
//         if (preg_match('/https?:\/\/[^\s]+/', $field_value)) {
//             $result->invalidate($field_name, "URLs are not allowed in this field.");
//         }

//         // Check for spammy keywords
//         foreach ($spam_keywords as $keyword) {
//             if (stripos($field_value, $keyword) !== false) {
//                 $result->invalidate($field_name, "Your submission contains spammy content.");
//                 break;
//             }
//         }

//         // Additional validations
//         if ($field_name === 'your-phone') {
//             if (!preg_match('/^(?:\+64|0)[2-9]\d{7,9}$/', $field_value)) {
//                 $result->invalidate($field_name, "Please enter a valid New Zealand phone number.");
//             }
//         }

//         if ($field_name === 'your-email') {
//             if (!filter_var($field_value, FILTER_VALIDATE_EMAIL)) {
//                 $result->invalidate($field_name, "Please enter a valid email address.");
//             } else {
//                 // Check against temporary email domains
//                 $domain = substr(strrchr($field_value, "@"), 1);
//                 if (in_array($domain, $temp_email_domains)) {
//                     $result->invalidate($field_name, "Temporary email addresses are not allowed.");
//                 }
//             }
//         }
//     }

//     return $result;
// }

// add_filter('wpcf7_validate_textarea', 'custom_cf7_spam_validation', 10, 1);
// add_filter('wpcf7_validate_text', 'custom_cf7_spam_validation', 10, 1);
// add_filter('wpcf7_validate_email', 'custom_cf7_spam_validation', 10, 1);