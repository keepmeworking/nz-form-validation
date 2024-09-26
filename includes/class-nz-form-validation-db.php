<?php
class NZFormValidationDB {
    private $cache;

    public function __construct() {
        $this->cache = [];
    }

    public function get_spam_keywords() {
        return $this->get_cached_data('spam_keywords', function() {
            $file = plugin_dir_path(__FILE__) . '../data/spam_keywords.json';
            return json_decode(file_get_contents($file), true) ?? [];
        });
    }

    public function get_temp_email_domains() {
        return $this->get_cached_data('temp_email_domains', function() {
            $file = plugin_dir_path(__FILE__) . '../data/temp_email_domains.json';
            return json_decode(file_get_contents($file), true) ?? [];
        });
    }

    private function get_cached_data($key, $callback) {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $data = $callback();
        $this->cache[$key] = $data;

        return $data;
    }

    public function update_spam_data($spam_keywords, $temp_email_domains) {
        file_put_contents(plugin_dir_path(__FILE__) . '../data/spam_keywords.json', json_encode($spam_keywords));
        file_put_contents(plugin_dir_path(__FILE__) . '../data/temp_email_domains.json', json_encode($temp_email_domains));
        $this->cache = []; // Clear cache after update
    }

    public function log_spam_attempt($data) {
        $log_file = plugin_dir_path(__FILE__) . '../data/spam_attempts.log';
        file_put_contents($log_file, json_encode($data) . "\n", FILE_APPEND);
    }

    // This method needs to be implemented
    public function update_spam_data_from_source() {
        // Implementation for updating spam data from a source
        // This could involve fetching data from an API or another source
    }
}
