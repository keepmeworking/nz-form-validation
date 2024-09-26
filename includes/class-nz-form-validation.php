<?php
class NZFormValidation {
    private $db;
    private $settings;
    private $debug = true;

    public function __construct($db) {
        $this->db = $db;
    }
    
    private function debug_log($message) {
        if ($this->debug) {
            error_log($message);
            // Optionally, you can also echo the message if you're testing locally
            // echo $message . "<br>";
        }
    }

    public function init() {
        $this->settings = get_option('nz_form_validation_settings', [
            'min_words' => 5,
            'max_words' => 500,
            'allow_urls' => false,
            'url_limit' => 2,
            'custom_error_message' => '',
            'rate_limit' => 5,
            'rate_limit_period' => 3600,
        ]);

        $this->add_form_hooks();
    }

    private function add_form_hooks() {
        // Contact Form 7
        add_filter('wpcf7_validate_textarea', [$this, 'validate_cf7'], 10, 2);
        add_filter('wpcf7_validate_text', [$this, 'validate_cf7'], 10, 2);
        add_filter('wpcf7_validate_email', [$this, 'validate_cf7'], 10, 2);
        add_filter('wpcf7_validate_tel', [$this, 'validate_cf7'], 10, 2);
        
        add_filter('wpcf7_validate', [$this, 'validate_all_cf7_fields'], 10, 2);
        
        // Forminator
        add_filter('forminator_custom_form_submit_errors', [$this, 'validate_forminator'], 10, 3);
        
        // WPForms
        add_filter('wpforms_process_before_form_data', [$this, 'validate_wpforms'], 10, 2);
        
        // Elementor Forms
        add_action('elementor_pro/forms/validation', [$this, 'validate_elementor_form'], 10, 2);
        
        // Gravity Forms
        add_filter('gform_validation', [$this, 'validate_gravity_forms']);
        
        // Ninja Forms
        add_filter('ninja_forms_submit_data', [$this, 'validate_ninja_forms']);
        
        // Fluent Forms
        add_filter('fluentform_validation_errors', [$this, 'validate_fluent_forms'], 10, 2);
    }
    
    public function validate_all_cf7_fields($result, $tags) {
        foreach ($tags as $tag) {
            $this->validate_cf7($result, $tag);
        }
        return $result;
    }
    
    public function validate_cf7($result, $tag) {
        $field_name = $tag->name;
        $field_value = isset($_POST[$field_name]) ? $_POST[$field_name] : '';
        $field_type = $tag->type;
    
        // Debug logging for reference
        // $this->debug_log("Msg: {$this->settings['custom_error_message']}");

    
        $validPhoneFieldRegex = '/^(tel|tel\*)$/';
        $validPhoneNameRegex = '/^(your-phone|phone|mobile)$/';
    
        if (strncmp($field_type, 'email', 5) === 0) {
            if (!$this->is_valid_email($field_value)) {
                $error_message = $this->get_error_message($field_name, 'invalid_email');
                $result->invalidate($tag, $error_message);
            }
        }
        elseif (preg_match($validPhoneFieldRegex, $field_type) || preg_match($validPhoneNameRegex, $field_name)) {
            if (!$this->is_valid_nz_phone($field_value)) {
                $error_message = $this->get_error_message($field_name, 'invalid_phone');
                $result->invalidate($tag, $error_message);
            }
        }
        else {
            $errors = $this->validate_field($field_name, $field_value, $field_type);
            if (!empty($errors)) {
                $result->invalidate($tag, $errors[0]);
            }
        }
    
        return $result;
    }



    public function validate_forminator($submit_errors, $form_id, $field_data_array) {
        foreach ($field_data_array as $field) {
            $field_name = $field['name'];
            $field_value = $field['value'];
            $field_type = $field['type'];
            
            if ($field_type == 'email') {
                if (!$this->is_valid_email($field_value)) {
                    $submit_errors[$field_name] = "Please enter a valid email address.";
                }
            } elseif ($field_type == 'phone') {
                if (!$this->is_valid_nz_phone($field_value)) {
                    $errMsg = $this->get_error_message($field_name, 'invalid_phone');
                    $submit_errors[$field_name] = $errMsg;
                }
            } else {
                $errors = $this->validate_field($field_name, $field_value, $field_type);
                if (!empty($errors)) {
                    $submit_errors[$field_name] = $errors[0];
                }
            }
        }
        return $submit_errors;
    }

    public function validate_wpforms($errors, $form_data) {
        foreach ($form_data['fields'] as $field_id => $field) {
            $field_name = $field['name'];
            $field_value = $field['value'];
            $field_type = $field['type'];
            
            if ($field_type == 'email') {
                if (!$this->is_valid_email($field_value)) {
                    $errors[$field_id] = "Please enter a valid email address.";
                }
            } elseif ($field_type == 'phone') {
                if (!$this->is_valid_nz_phone($field_value)) {
                    $errors[$field_id] = "Please enter a valid New Zealand phone number.";
                }
            } else {
                $field_errors = $this->validate_field($field_name, $field_value, $field_type);
                if (!empty($field_errors)) {
                    $errors[$field_id] = $field_errors[0];
                }
            }
        }
        return $errors;
    }

    public function validate_elementor_form($record, $ajax_handler) {
        $form_data = $record->get_formatted_data();
        foreach ($form_data as $field_id => $field) {
            $field_name = $field['name'];
            $field_value = $field['value'];
            $field_type = $field['type'];
            
            if ($field_type == 'email') {
                if (!$this->is_valid_email($field_value)) {
                    $ajax_handler->add_error($field_id, "Please enter a valid email address.");
                }
            } elseif ($field_type == 'tel') {
                if (!$this->is_valid_nz_phone($field_value)) {
                    $ajax_handler->add_error($field_id, "Please enter a valid New Zealand phone number.");
                }
            } else {
                $errors = $this->validate_field($field_name, $field_value, $field_type);
                if (!empty($errors)) {
                    $ajax_handler->add_error($field_id, $errors[0]);
                }
            }
        }
    }

    public function validate_gravity_forms($validation_result) {
        $form = $validation_result['form'];
        $current_page = rgpost('gform_source_page_number_' . $form['id']) ? rgpost('gform_source_page_number_' . $form['id']) : 1;
        
        foreach ($form['fields'] as $field) {
            if ($field->pageNumber != $current_page) {
                continue;
            }
            
            $field_name = $field->label;
            $field_value = rgpost("input_{$field->id}");
            $field_type = $field->type;
            
            if ($field_type == 'email') {
                if (!$this->is_valid_email($field_value)) {
                    $validation_result['is_valid'] = false;
                    $field->failed_validation = true;
                    $field->validation_message = "Please enter a valid email address.";
                }
            } elseif ($field_type == 'phone') {
                if (!$this->is_valid_nz_phone($field_value)) {
                    $validation_result['is_valid'] = false;
                    $field->failed_validation = true;
                    $field->validation_message = "Please enter a valid New Zealand phone number.";
                }
            } else {
                $errors = $this->validate_field($field_name, $field_value, $field_type);
                if (!empty($errors)) {
                    $validation_result['is_valid'] = false;
                    $field->failed_validation = true;
                    $field->validation_message = $errors[0];
                }
            }
        }
        
        return $validation_result;
    }

    public function validate_ninja_forms($form_data) {
        foreach ($form_data['fields'] as $field_id => $field) {
            $field_name = $field['label'];
            $field_value = $field['value'];
            $field_type = $field['type'];
            
            if ($field_type == 'email') {
                if (!$this->is_valid_email($field_value)) {
                    $form_data['errors']['fields'][$field_id] = "Please enter a valid email address.";
                }
            } elseif ($field_type == 'phone') {
                if (!$this->is_valid_nz_phone($field_value)) {
                    $form_data['errors']['fields'][$field_id] = "Please enter a valid New Zealand phone number.";
                }
            } else {
                $errors = $this->validate_field($field_name, $field_value, $field_type);
                if (!empty($errors)) {
                    $form_data['errors']['fields'][$field_id] = $errors[0];
                }
            }
        }
        return $form_data;
    }

    public function validate_fluent_forms($errors, $data) {
        foreach ($data as $field_name => $field_value) {
            $field_type = $this->get_fluent_form_field_type($field_name, $data);
            
            if ($field_type == 'email') {
                if (!$this->is_valid_email($field_value)) {
                    $errors[$field_name] = "Please enter a valid email address.";
                }
            } elseif ($field_type == 'phone') {
                if (!$this->is_valid_nz_phone($field_value)) {
                    $errors[$field_name] = "Please enter a valid New Zealand phone number.";
                }
            } else {
                $field_errors = $this->validate_field($field_name, $field_value, $field_type);
                if (!empty($field_errors)) {
                    $errors[$field_name] = $field_errors[0];
                }
            }
        }
        return $errors;
    }

    private function get_fluent_form_field_type($field_name, $data) {
        // This is a basic implementation. You might need to adjust this based on how Fluent Forms structures its data.
        if (strpos($field_name, 'email') !== false) {
            return 'email';
        } elseif (strpos($field_name, 'phone') !== false || strpos($field_name, 'tel') !== false) {
            return 'phone';
        }
        return 'text';
    }

    private function validate_field($field_name, $field_value, $field_type = '') {
        $errors = [];
        
        if(!empty($field_value)){
            if (!$this->is_html_allowed_field($field_name, $field_type) && $this->contains_html_tags($field_value)) {
                $errors[] = $this->get_error_message($field_name, 'invalid_html');
            }
    
            $field_value = $this->sanitize_field($field_value);
    
            if ($this->contains_urls($field_value) && !$this->settings['allow_urls']) {
                $errors[] =  $this->get_error_message($field_name, 'invalid_url');
            }
    
            if ($this->is_spam_content($field_value)) {
                $errors[] = $this->get_error_message($field_name, 'contains_spam_keyword');
            }
    
            if ($this->is_message_field($field_name)) {
                if (!$this->is_valid_word_count($field_value)) {
                    $errors[] = $this->get_error_message($field_name, 'excessive_length');
                }
    
                if (!$this->is_valid_url_count($field_value)) {
                    $errors[] = $this->get_error_message($field_name, 'too_many_urls');
                }
            }
    
            if (!$this->is_english_text($field_value)) {
                $errors[] = $this->get_error_message($field_name, 'only_english');
            }
        }

        return $errors;
    }
    
    private function contains_html_tags($content) {
        return $content !== strip_tags($content);
    }
    
    private function is_html_allowed_field($field_name, $field_type) {
        $html_allowed_fields = ['description', 'content', 'rich_text'];
        return in_array(strtolower($field_name), $html_allowed_fields) || strpos(strtolower($field_type), 'rich') !== false;
    }

    private function sanitize_field($value) {
        if (is_array($value)) {
            return array_map([$this, 'sanitize_field'], $value);
        }
        return sanitize_textarea_field($value);
    }

    private function contains_urls($content) {
        return preg_match('/https?:\/\/[^\s]+/', $content);
    }

    private function is_spam_content($content) {
        $spam_keywords = $this->db->get_spam_keywords();
        $content_lower = strtolower($content);
        foreach ($spam_keywords as $keyword) {
            if (strpos($content_lower, strtolower($keyword)) !== false) {
                return true;
            }
        }
        return preg_match('/(.)\1{10,}/', $content) || $this->contains_suspicious_patterns($content);
    }

    private function contains_suspicious_patterns($content) {
        $patterns = [
            '/\d{16,}/', // Long number sequences
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,7}/', // Email addresses
            '/\b(?:https?|ftp):\/\/[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|\/))/', // URLs
        ];
    
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        return false;
    }

    private function is_valid_url_count($content) {
        if (!$this->settings['allow_urls']) {
            return !preg_match('/(http|https):\/\/[^\s]+/', $content);
        }
        
        $url_count = preg_match_all('/(http|https):\/\/[^\s]+/', $content, $matches);
        return $url_count <= $this->settings['url_limit'];
    }

    private function is_valid_nz_phone($phone) {
        // Remove all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);
        
        // Match NZ phone number formats
        // Local numbers can start with 02 (mobile), 03, 04 (landline), or 07 (mobile) with 7-8 digits
        // International numbers can start with 64 followed by a valid prefix and then 7-8 digits
        return preg_match('/^(0(?:2[0-9]|3[0-9]|4[0-9]|7[0-9]|8[0-9])\d{7,8}|64(?:2[0-9]|3[0-9]|4[0-9]|7[0-9]|8[0-9])\d{7,8})$/', $phone) === 1;
    }

    
    private function is_valid_email($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        list($user, $domain) = explode('@', $email);
        $temp_email_domains = $this->db->get_temp_email_domains();
        return !in_array(strtolower($domain), $temp_email_domains, true);
    }

    private function is_message_field($field_name) {
        $message_fields = ['message', 'your-message', 'comments', 'description'];
        return in_array(strtolower($field_name), $message_fields) || 
               strpos(strtolower($field_name), 'message') !== false || 
               strpos(strtolower($field_name), 'comment') !== false;
    }

    private function is_valid_word_count($content) {
        $word_count = str_word_count($content);
        return $word_count >= $this->settings['min_words'] && $word_count <= $this->settings['max_words'];
    }

    private function is_english_text($text) {
        return preg_match('/^[\p{Latin}\s\d.,;!?()\'"“”\[\]{}<>-]+$/u', $text);
    }

    private function get_error_message($field_name, $error_type) {
        $default_messages = [
            'invalid_html' => 'Enter a normal english.',
            'invalid_email' => 'Please enter a valid email address.',
            'invalid_phone' => 'Please enter a valid phone number.',
            'invalid_url' => 'Please enter a valid URL.',
            'contains_spam_keyword' => 'Your input contains suspicious content.',
            'too_many_urls' => 'Too many URLs in your message.',
            'only_english' => 'Only English text is allowed.',
            'excessive_length' => 'Your input exceeds the maximum allowed length.',
        ];
    
        if (!empty($this->settings['custom_error_message'])) {
            return $this->settings['custom_error_message'];
        }
    
        return $default_messages[$error_type] ?? 'Invalid information.';
    }


    private function is_rate_limited() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $rate_limit = $this->settings['rate_limit'];
        $rate_limit_period = $this->settings['rate_limit_period'];

        $attempts = $this->db->get_recent_attempts($ip, $rate_limit_period);
        return $attempts >= $rate_limit;
    }

}