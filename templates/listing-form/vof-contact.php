<?php
error_log('VOF Debug: Custom VOF contact template loaded');
/**
 * Listing Form Contact
 *
 * @author        RadiusTheme
 * @package       classified-listing/templates
 * @version       1.0.0
 *
 * @var array $hidden_fields
 * @var string $state_text
 * @var string $city_text
 * @var string $town_text
 * @var string $zipcode
 * @var string $phone
 * @var string $whatsapp_number
 * @var boolean $enable_post_for_unregister
 * @var string $website
 * @var bool $latitude
 * @var bool $longitude
 * @var bool $has_map
 * @var bool $hide_map
 * @var string $email
 * @var string $address
 * @var string $geo_address
 * @var integer $selected_locations
 */

// originally from wp-content/plugins/classified-listing/templates/listing-form/contact.php

// This template is included by wp-content/plugins/classified-listing/templates/listing-form/form.php
// which loads all the listing form sections via do_action('rtcl_listing_form_{$section}')
//
// The variables ($hidden_fields, $state_text etc) are extracted from an array passed to 
// rtcl_get_template() MOST LIKELY TEMPLATEHOOKS.PHP  AND IT'S  
// Functions::get_template( "listing-form/contact", apply_filters( 'rtcl_listing_form_contact_tpl_attributes', $data, $post_id ) );
// before including this template, preventing undefined variable errors
// also look for this hook 'rtcl_listing_form_contact_tpl_attributes'
// This template handles contact details for:
// 1. New listing submission form - When users create listings
// 2. Edit listing form - When users edit existing listings 
// 3. Admin listing edit form - When admins edit listings in wp-admin

// This template is overridden by wp-content/plugins/vendor-onboarding-flow/templates/listing-form/vof-contact.php
// when the VOF plugin is active and certain conditions are met.

// Hook into 'rtcl_get_template' filter to swap template paths

//  add_filter('rtcl_get_template', function($template, $template_name, $args) {
//         // Only override the contact.php template
//         if ($template_name === 'listing-form/contact.php') {

//             // Check if VOF plugin is active and conditions are met
//             if (defined('VOF_VERSION') && vof_should_use_custom_template()) {
//                 // Return path to VOF contact template instead
//                 return VOF_PLUGIN_DIR . 'templates/listing-form/vof-contact.php';
//             }
//         }
//         return $template;
//     }, 10, 3);

//     // Hook into 'rtcl_get_template_args' to ensure template variables are available 
//     add_filter('rtcl_get_template_args', function($args, $template_name) {
//         if ($template_name === 'listing-form/contact.php' && 
//             defined('VOF_VERSION') && 
//             vof_should_use_custom_template()) {

//             // Ensure all required variables exist to prevent fatal errors
//             $required_vars = [
//                 'hidden_fields' => [],
//                 'state_text' => '',
//                 'city_text' => '',
//                 'town_text' => '',
//                 'zipcode' => '',
//                 'phone' => '',
//                 'whatsapp_number' => '',
//                 'enable_post_for_unregister' => false,
//                 'website' => '',
//                 'latitude' => false,
//                 'longitude' => false, 
//                 'has_map' => false,
//                 'hide_map' => false,
//                 'email' => '',
//                 'address' => '',
//                 'geo_address' => '',
//                 'selected_locations' => 0
//             ];

//             // Merge with existing args, keeping existing values if set
//             $args = wp_parse_args($args, $required_vars);

//             // Add any VOF-specific variables
//             $args['vof_custom_var'] = 'value';
//         }
//         return $args;
//     }, 10, 2);

//     // Helper function to check conditions
//     function vof_should_use_custom_template() {
//         // Add your conditions here, for example:
//         // - Check if user is vendor
//         // - Check if specific settings are enabled
//         // - etc.
//         return true; // Return true to use VOF template
//     }


use Rtcl\Helpers\Functions;
use RtclPro\Helpers\Fns;

// Add this near the top after error_log statements you already have
error_log('VOF Debug: Email field value at template load: ' . (isset($vof_email) ? $vof_email : 'not set'));

error_log('VOF Debug: vof-contact.php template loaded');
error_log('VOF Debug: Template variables: ' . print_r(get_defined_vars(), true));

$labelColumn = is_admin() ? 'col-sm-2' : 'col-sm-3';
$inputColumn = is_admin() ? 'col-sm-10' : 'col-sm-9';
?>
<div class="rtcl-post-contact-details rtcl-post-section<?php echo esc_attr( is_admin() ? " rtcl-is-admin" : '' ) ?>">
    <div class="classified-listing-form-title">
        <i class="fa fa-user" aria-hidden="true"></i>
        <h3><?php esc_html_e( "Contact Details [VOF]", 'classima' ); ?></h3>
    </div>
	<?php if ( 'local' === Functions::location_type() ) : ?>

		<?php if ( ! in_array( 'location', $hidden_fields ) ): ?>
            <div class="row" id="rtcl-location-row">
                <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                    <label class="control-label"><?php echo esc_html( $state_text ); ?><span
                                class="require-star"> *</span></label>
                </div>
                <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                    <div class="form-group">
                        <select id="rtcl-location" name="location"
                                class="rtcl-select2 rtcl-select form-control rtcl-map-field" required>
                            <option value="">--<?php esc_html_e( 'Select Location', 'classima' ) ?>--</option>
							<?php
							$locations = Functions::get_one_level_locations();
							if ( ! empty( $locations ) ) {
								foreach ( $locations as $location ) {
									$slt = '';
									if ( in_array( $location->term_id, $selected_locations ) ) {
										$location_id = $location->term_id;
										$slt         = " selected";
									}
									echo "<option value='{$location->term_id}'{$slt}>{$location->name}</option>";
								}
							}
							?>
                        </select>
                    </div>
                </div>
            </div>
			<?php
			$sub_locations = array();
			if ( $location_id ) {
				$sub_locations = Functions::get_one_level_locations( $location_id );
			}
			?>
            <div class="row <?php echo empty( $sub_locations ) ? ' rtcl-hide' : ''; ?>" id="sub-location-row">
                <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                    <label class="control-label"><?php echo esc_html( $city_text ); ?><span
                                class="require-star"> *</span></label>
                </div>
                <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                    <div class="form-group">
                        <select id="rtcl-sub-location" name="sub_location"
                                class="rtcl-select2 rtcl-select form-control rtcl-map-field" required>
                            <option value="">--<?php esc_html_e( 'Select Location', 'classima' ) ?>--</option>
							<?php
							if ( ! empty( $sub_locations ) ) {
								foreach ( $sub_locations as $location ) {
									$slt = '';
									if ( in_array( $location->term_id, $selected_locations ) ) {
										$sub_location_id = $location->term_id;
										$slt             = " selected";
									}
									echo "<option value='{$location->term_id}'{$slt}>{$location->name}</option>";
								}
							}
							?>
                        </select>
                    </div>
                </div>
            </div>
			<?php
			$sub_sub_locations = array();
			if ( $sub_location_id ) {
				$sub_sub_locations = Functions::get_one_level_locations( $sub_location_id );
			}
			?>
            <div class="row <?php echo empty( $sub_sub_locations ) ? ' rtcl-hide' : ''; ?>" id="sub-sub-location-row">
                <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                    <label class="control-label"><?php echo esc_html( $town_text ); ?><span> *</span></label>
                </div>
                <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                    <div class="form-group">
                        <select id="rtcl-sub-sub-location" name="sub_sub_location"
                                class="rtcl-select2 rtcl-select form-control rtcl-map-field" required>
                            <option value="">--<?php esc_html_e( 'Select Location', 'classima' ) ?>--</option>
							<?php
							if ( ! empty( $sub_sub_locations ) ) {
								foreach ( $sub_sub_locations as $location ) {
									$slt = '';
									if ( in_array( $location->term_id, $selected_locations ) ) {
										$slt = " selected";
									}
									echo "<option value='{$location->term_id}'{$slt}>{$location->name}</option>";
								}
							}
							?>
                        </select>
                    </div>
                </div>
            </div>
		<?php endif; ?>

		<?php if ( ! in_array( 'zipcode', $hidden_fields ) ): ?>
            <div class="row classima-form-zip-row">
                <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                    <label class="control-label"><?php esc_html_e( "Zip Code", 'classima' ); ?></label>
                </div>
                <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                    <div class="form-group">
                        <input type="text" name="zipcode" value="<?php echo esc_attr( $zipcode ); ?>"
                               class="rtcl-map-field form-control" id="rtcl-zipcode"/>
                    </div>
                </div>
            </div>
		<?php endif; ?>

		<?php if ( ! in_array( 'address', $hidden_fields ) ): ?>
            <div class="row classima-form-address-row">
                <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                    <label class="control-label"><?php esc_html_e( "Address", 'classima' ); ?></label>
                </div>
                <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                    <div class="form-group">
                        <textarea name="address" rows="2" class="rtcl-map-field form-control"
                                  id="rtcl-address"><?php echo esc_textarea( $address ); ?></textarea>
                    </div>
                </div>
            </div>
		<?php endif; ?>

	<?php else: ?>
        <div class="row">
            <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                <label class="control-label"
                       for="rtcl-geo-address"><?php esc_html_e( "Location", 'classima' ); ?></label>
            </div>
            <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                <div class="rtcl-geo-address-field form-group">
                    <input type="text" name="rtcl_geo_address" autocomplete="off"
                           value="<?php echo esc_attr( $geo_address ) ?>"
                           id="rtcl-geo-address"
                           placeholder="<?php esc_html_e( "Select a location", "classima" ) ?>"
                           class="form-control rtcl-geo-address-input rtcl_geo_address_input"/>
                    <i class="rtcl-get-location rtcl-icon rtcl-icon-target" id="rtcl-geo-loc-form"></i>
                </div>
            </div>
        </div>
	<?php endif; ?>





	    <!-- <//?php if ( ! in_array( 'phone', $hidden_fields ) ):  -->
	    <?php if ( ! in_array( 'vof_phone', $hidden_fields ) ): 
		// $PhoneIsRequired = Functions::listingFormPhoneIsRequired();
        ?>
        <div class="row classima-form-phone-row">
            <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                <!-- <label class="control-label" for="rtcl-phone"> -->
                <label class="control-label" for="vof-phone">
                    <?php esc_html_e( "Phone [VOF]", 'classima' ); ?>
	                <span class="rtcl-required">*</span>
	                <!-- <//?php if ( $phoneIsRequired ) { ?><span class="rtcl-required">*</span> <//?php } ?> -->
                </label>
            </div>
            <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                <div class="form-group">
                    <?php
                        $field = '<input type="text" name="vof_phone" id="vof-phone" value="' . esc_attr( $phone ) . '" class="form-control" />';
                        Functions::print_html( apply_filters( 'rtcl_verification_listing_form_phone_field', $field, $phone ), true );   
                    ?>
					<!-- <//?php 
					//$required_attr = $phoneIsRequired ? 'required' : '';
					// $field = '<input type="text" name="phone" id="rtcl-phone" value="' . esc_attr( $phone ) . '" class="form-control" ' . esc_attr( $required_attr ) . '/>';
					$field = '<input type="text" name="vof-phone" id="vof-phone"' . esc_attr( $phone ) . '" class="form-control" ' . esc_attr( $required_attr ) . '/>';
					// Functions::print_html( apply_filters( 'rtcl_verification_listing_form_phone_field', $field, $phone ), true );
					?>-->
					<!-- <//?php do_action( 'rtcl_listing_form_phone_warning' ); ?> --> 
                </div>
            </div>
        </div>
	<?php endif; ?>


	<!-- <//?php if ( ! in_array( 'whatsapp_number', $hidden_fields ) ): ?> -->
	<?php if ( ! in_array( 'vof_whatsapp_number', $hidden_fields ) ): ?>
        <div class="row classima-form-whatsapp-row">
            <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                <!-- <label class="control-label"> -->
                <label class="control-label" for="vof-whatsapp-number">
                    <?php esc_html_e( "Whatsapp Number [VOF]", 'classima' ); ?>
                    <span class="rtcl-required"> *</span>
                </label>
            </div>
            <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                <div class="form-group">
                    <!-- <input type="text" class="form-control" id="rtcl-whatsapp-number" name="_rtcl_whatsapp_number" -->
                    <input type="text" class="form-control" id="vof-whatsapp-number" name="vof_whatsapp_number"
                           value="<?php echo esc_attr( $vof_whatsapp_number ); ?>"/>
                    <p class="description small"><?php esc_html_e( "Whatsapp number with your country code. e.g.+1xxxxxxxxxx", 'classima' ) ?></p>
                </div>
            </div>
        </div>
	<?php endif; ?>





	<!-- <//?php if ( ! in_array( 'email', $hidden_fields ) || $enable_post_for_unregister ): ?> -->
	<?php if ( ! in_array( 'vof_email', $hidden_fields ) || $enable_post_for_unregister ): ?>
        <div class="row classima-form-email-row">
            <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                <label class="control-label"><?php esc_html_e( "Email [VOF]", 'classima' ); ?><?php if ( $enable_post_for_unregister ): ?>
                    <span> *</span><?php endif; ?></label>
            </div>
            <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                <div class="form-group">
                    <!-- <input type="email" class="form-control" id="rtcl-email" name="vof-email" -->
                    <input type="email" 
                           class="form-control" 
                           id="vof_email" 
                           name="vof_email"
                           value="<?php echo esc_attr( $vof_email ); ?>" 
                    <?php echo esc_html( $enable_post_for_unregister ? " required" : '' ); ?> />
                    
                    <!-- Add a hidden field for compatibility -->
                    <input type="hidden" 
                            name="email" 
                            value="<?php echo esc_attr( $vof_email ); ?>" />
					<?php if ( $enable_post_for_unregister ): ?>
                        <p class="description"><?php esc_html_e( "This will be your username", 'classima' ); ?></p>
					<?php endif; ?>
                </div>
            </div>
        </div>
	<?php endif; ?>








	<?php if ( ! in_array( 'website', $hidden_fields ) ): ?>
        <div class="row classima-form-website-row">
            <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                <label class="control-label" for="rtcl-website"><?php esc_html_e( "Website [VOF]", 'classima' ); ?></label>
            </div>
            <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                <div class="form-group">
                    <input type="url" class="form-control" id="rtcl-website" value="<?php echo esc_url( $website ); ?>"
                           name="website"/>
                    <p class="description small"><?php esc_html_e( "e.g. https://example.com", 'classima' ) ?></p>
                </div>
            </div>
        </div>
	<?php endif; ?>

	<?php if ( method_exists( 'Rtcl\Helpers\Functions', 'has_map' ) && Functions::has_map() ):
		$hide_map = $post_id && get_post_meta( $post_id, 'hide_map', true );
		?>
        <div class="row rtcl-listing-map">
            <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                <label class="control-label"><?php esc_html_e( 'Map [VOF]', 'classima' ); ?></label>
            </div>
            <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                <div class="form-group">
                    <div class="rtcl-map-wrap">
                        <div class="rtcl-map" data-type="input">
                            <div class="marker" data-latitude="<?php echo esc_attr( $latitude ); ?>"
                                 data-longitude="<?php echo esc_attr( $longitude ); ?>"><?php echo 'geo' === Functions::location_type() ? esc_attr( $geo_address ) : esc_html( $address ); ?></div>
                        </div>
                        <div class="rtcl-form-check">
                            <input class="rtcl-form-check-input" id="rtcl-hide-map" type="checkbox" name="hide_map"
                                   value="1" <?php checked( $hide_map, 1 ); ?>>
                            <label class="rtcl-form-check-label"
                                   for="rtcl-hide-map"><?php esc_html_e( "Don't show the Map", 'classima' ) ?></label>
                        </div>
                    </div>
                    <!-- Map Hidden field-->
                    <input type="hidden" name="latitude" value="<?php echo esc_attr( $latitude ); ?>"
                           id="rtcl-latitude"/>
                    <input type="hidden" name="longitude" value="<?php echo esc_attr( $longitude ); ?>"
                           id="rtcl-longitude"/>
                </div>
            </div>
        </div>
	<?php endif; ?>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Get references to the form fields
        const phoneField = document.getElementById('vof-phone');
        const whatsappField = document.getElementById('vof-whatsapp-number');
        const vofEmailField = document.getElementById('vof_email');
        const emailField = document.querySelector('input[name="email"]');


        // Add an event listener to the phone field
        phoneField.addEventListener('input', function () {
            // Update the value of the WhatsApp field
            whatsappField.value = phoneField.value;
        });

        vofEmailField.addEventListener('input', function () {
        emailField.value = this.value;
        });


    });
</script>