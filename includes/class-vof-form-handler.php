<?php
/**
 * Form Handler Class
 * 
 * Handles form modifications and validations for the vendor onboarding flow
 */

use Rtcl\Helpers\Functions;
use RtclStore\Models\Membership;

class VOF_Form_Handler {
    
    public function __construct() {
        // Hook into form rendering
        add_action('rtcl_listing_form', array($this, 'vof_maybe_add_confirm_email_field'), 20);
        
        // Add validation for confirm email
        add_filter('rtcl_fb_extra_form_validation', array($this, 'vof_validate_confirm_email'), 10, 2);
    }

    /**
     * Check if user has active membership
     */
    // private function vof_has_active_membership(): bool {
    //     if (!is_user_logged_in()) {
    //         return false;
    //     }
    //     try {
    //         $membership = new Membership(get_current_user_id());
    //         return $membership->has_membership() && !$membership->is_expired();
    //     } catch (Exception $e) {
    //         return false;
    //     }
    // }

    /**
     * Maybe add confirm email field based on user state
     */
    public function vof_maybe_add_confirm_email_field() {
        // Only add field if user is not logged in or has no active membership
        if (!is_user_logged_in() || !\VOF\VOF_Subscription::has_active_subscription()) {
            ?>
            <div class="row classima-form-confirm-email-row">
                <div class="col-12 col-sm-3">
                    <label class="control-label"><?php esc_html_e('Confirm Email', 'vendor-onboarding-flow'); ?><span class="require-star">*</span></label>
                </div>
                <div class="col-12 col-sm-9">
                    <div class="form-group">
                        <input type="email" class="form-control" id="rtcl-confirm-email" name="confirm_email" required />
                        <div class="help-block"><?php esc_html_e('Please confirm your email address', 'vendor-onboarding-flow'); ?></div>
                    </div>
                </div>
            </div>
            <?php
            
            // Add JavaScript validation
            add_action('wp_footer', array($this, 'vof_add_email_validation_script'));
        }
    }

    /**
     * Add client-side validation for email confirmation
     */
    public function vof_add_email_validation_script() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#rtcl-post-form').on('submit', function(e) {
                var email = $('#rtcl-email').val();
                var confirmEmail = $('#rtcl-confirm-email').val();
                
                if (email !== confirmEmail) {
                    e.preventDefault();
                    alert('<?php esc_html_e('Email addresses do not match!', 'vendor-onboarding-flow'); ?>');
                    return false;
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Validate confirm email field
     */
    public function vof_validate_confirm_email($errors, $form) {
        if (!is_user_logged_in() || !\VOF\VOF_Subscription::has_active_subscription()) {
            $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
            $confirm_email = isset($_POST['confirm_email']) ? sanitize_email($_POST['confirm_email']) : '';
            
            if (empty($confirm_email)) {
                $errors->add('confirm_email', esc_html__('Please confirm your email address', 'vendor-onboarding-flow'));
            } elseif ($email !== $confirm_email) {
                $errors->add('confirm_email_mismatch', esc_html__('Email addresses do not match', 'vendor-onboarding-flow'));
            }
        }
        
        return $errors;
    }
}

// Initialize the form handler
//new VOF_Form_Handler();