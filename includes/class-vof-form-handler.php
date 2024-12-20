<?php

namespace VOF;

class VOF_Form_Handler {

    public function __construct() {
       add_action('wp_ajax_rtcl_update_listing', [$this, 'handle_submission']);
       add_action('wp_ajax_nopriv_rtcl_update_listing', [$this, 'handle_submission']);
    }

    public function handle_submission() {
       // Handle form submission logic here...
       // Use methods for guest submission, no subscription submission, etc.
       wp_send_json_success(); // Example response; customize as needed.
   }

   private function get_sanitized_form_data() {
       return array(
           'title' => sanitize_text_field($_POST['title'] ?? ''),
           // Other fields...
       );
   }
}
