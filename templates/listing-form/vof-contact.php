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
        <h3><?php esc_html_e( "Contact Details", 'classima' ); ?></h3>
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
                       for="rtcl-geo-address"><?php esc_html_e( "Location", 'classima' ); ?><span class="rtcl-required">*</span></label>
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
                <label class="control-label" for="vof-phone">
                    <?php esc_html_e( "Phone", 'classima' ); ?>
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
                </div>
            </div>
        </div>
	<?php endif; ?>

	<?php if ( ! in_array( 'vof_whatsapp_number', $hidden_fields ) ): ?>
        <div class="row classima-form-whatsapp-row">
            <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                <!-- <label class="control-label"> -->
                <label class="control-label" for="vof-whatsapp-number">
                    <?php esc_html_e( "Whatsapp Number", 'classima' ); ?>
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

	<?php if ( ! in_array( 'vof_email', $hidden_fields ) || $enable_post_for_unregister ): ?>
        <div class="row classima-form-email-row">
            <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                <label class="control-label"><?php esc_html_e( "Email", 'classima' ); ?><?php if ( $enable_post_for_unregister ): ?>
                    <span> *</span><?php endif; ?></label>
            </div>
            <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                <div class="form-group">
                    <input type="email" 
                           class="form-control" 
                           id="vof_email" 
                           name="vof_email"
                           <?php if($is_logged_in): ?>
                                value="<?php echo esc_attr( $registered_email ); ?>"
                                readonly
                           <?php else: ?>
                                value="<?php echo esc_attr( $vof_email ); ?>" 
                           <?php endif;?>
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

	<?php if ( $enable_post_for_unregister ): ?>
        <div class="row classima-form-email-row">
            <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                <label class="control-label"><?php esc_html_e( "Confirm your email", 'classima' ); ?><?php if ( $enable_post_for_unregister ): ?>
                    <span> *</span><?php endif; ?></label>
            </div>
            <div class="col-12 <?php echo esc_attr( $inputColumn ); ?>">
                <div class="form-group">
                    <input type="email" 
                           class="form-control" 
                           id="vof_email_confirm" 
                           name="vof_email_confirm"
                           value="<?php echo esc_attr( $vof_email ); ?>" 
                    <?php echo esc_html( $enable_post_for_unregister ? " required" : '' ); ?> />
                    
                    <!-- Add a hidden field for compatibility -->
                    <input type="hidden" 
                            name="email" 
                            value="<?php echo esc_attr( $vof_email ); ?>" />
					<?php if ( $enable_post_for_unregister ): ?>
                        <p class="description"><?php esc_html_e( "IMPORTANT: Confirm your email. We'll send your login credentials to this address", 'classima' ); ?></p>
					<?php endif; ?>
                </div>
            </div>
        </div>
	<?php endif; ?>

	<?php if ( ! in_array( 'website', $hidden_fields ) ): ?>
        <div class="row classima-form-website-row">
            <div class="col-12 <?php echo esc_attr( $labelColumn ); ?>">
                <label class="control-label" for="rtcl-website"><?php esc_html_e( "Website", 'classima' ); ?></label>
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
                <label class="control-label"><?php esc_html_e( 'Map', 'classima' ); ?></label>
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
        
        // Flag to track if WhatsApp field was directly modified by user
        let whatsappDirectlyModified = false;

        // Add an event listener to the WhatsApp field to detect direct user input
        whatsappField.addEventListener('input', function(e) {
            // If this event wasn't triggered programmatically
            if (e.isTrusted) {
                whatsappDirectlyModified = true;
                // Apply styling for direct user input
                whatsappField.style.color = '#495057';
            }
        });

        // Add an event listener to the phone field
        phoneField.addEventListener('input', function () {
            // Only update WhatsApp field if it hasn't been directly modified
            if (!whatsappDirectlyModified) {
                // Update the value of the WhatsApp field
                whatsappField.value = phoneField.value;
                // Apply styling for indirect input
                whatsappField.style.color = '#7b7b7b';
            }
        });

        // Add an event listener to the vofEmailField
        vofEmailField.addEventListener('input', function () {
            emailField.value = this.value;
        });
    });
</script>