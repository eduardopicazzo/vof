<?php
/**
 * @author     RadiusTheme
 * @package    classified-listing-store/templates
 * @version    1.0.0
 *
 * @var \WP_User $current_user
 */

use Rtcl\Helpers\Functions;
use Rtcl\Helpers\Link;
use Rtcl\Resources\Options;
use RtclStore\Helpers\Functions as StoreFunctions;

$member = rtclStore()->factory->get_membership();
// ADD A SHOULD NUDGE FLAG TO CONTINUE ABANDONED CHECKOUT. 
// IF TRUE, ADD TO !$member conditional to continue with checkout procees and previous built listing.
// IF FALSE, REDIRECT TO LISTING FORM PAGE.
$should_nudge = false; // develop this flag's logic based on the abandoned checkout.

?>
<div class="membership-statistic-report-outer">
    <div class="membership-statistic-report-wrap">
        <h4><?php esc_html_e( "Membership Report", "classified-listing-store" ) ?></h4>
        <div class="statistic-report">
            <div class="reports">
				<?php
				if ( $member ):?>
                    <div class="report-item rtcl-membership-status">
                        <label><?php esc_html_e( 'Status', 'classified-listing-store' ) ?></label>
                        <div class="value">
							<?php if ( $member->is_expired() ): ?>
                                <span class="expired"><?php esc_html_e( "Expired", "classified-listing-store" ) ?></span>
							<?php else: ?>
                                <span class="active"><?php esc_html_e( "Active", "classified-listing-store" ) ?></span>
							<?php endif; ?>
                        </div>
                    </div>
                    <div class="report-item rtcl-membership-validity">
                        <label><?php esc_html_e( 'Validity', 'classified-listing-store' ) ?></label>
                        <div class="value">
							<?php
							printf( '<strong>%s:</strong> %s',
								$member->is_expired() ? __( "Expired at", "classified-listing-store" ) : __( "Until", "classified-listing-store" ),
								Functions::datetime( 'rtcl', $member->get_expiry_date() )
							);
							?>
                        </div>
                    </div>
                    <div class="report-item rtcl-membership-remaining-ads">
                        <label><?php esc_html_e( 'Remaining Ads', 'classified-listing-store' ) ?></label>
                        <div class="value"><?php echo absint( $member->get_remaining_ads() ); ?></div>
                    </div>
                    <div class="report-item rtcl-membership-posted-ads">
                        <label><?php esc_html_e( 'Posted Ads', 'classified-listing-store' ) ?></label>
                        <div class="value"><?php echo absint( $member->get_posted_ads() ); ?></div>
                    </div>
					<?php if ( StoreFunctions::is_enable_free_ads() ) { ?>
                        <div class="report-item rtcl-membership-free-ads">
                            <!-- <label>//<?php esc_html_e( 'Free Ads', 'classified-listing-store' ) ?></label> -->
                            <!-- <div class="value"><//?php $ads = $member->get_remaining_free_ads(); -->
								<!-- echo - 1 === $ads ? esc_html__( 'Unlimited', 'classified-listing-store' ) : absint( $ads ); ?></div> -->
                        </div>
					<?php } ?>
					<?php if ( ! empty( $promotions = $member->get_promotions() ) ): ?>
                        <div class="report-item rtcl-membership-promotions rtcl-table-scroll-x">
                            <table class="rtcl-table-border">
                                <tr class="promotion-item">
                                    <th class="promotion-label"><?php esc_html_e( "Promotions", "classified-listing-store" ); ?></th>
                                    <th><?php esc_html_e( "Remaining ads", "classified-listing-store" ); ?></th>
                                    <th class="promotion-validity"><?php esc_html_e( "Validation Duration", "classified-listing-store" ); ?><small><?php esc_html_e('(# Days)', 'classified-listing-store');  ?></></th>
                                </tr>
								<?php foreach ( $promotions as $promotion_key => $promotion ): ?>
                                    <tr class="promotion-item">
                                        <td data-label="<?php esc_html_e( "Promotions:", "classified-listing-store" ); ?>"><?php esc_html_e( Options::get_listing_promotions()[ $promotion_key ] ); ?></td>
                                        <td data-label="<?php esc_html_e( "Remaining ads:", "classified-listing-store" ); ?>"><?php esc_html_e( $promotion['ads'] ); ?></td>
                                        <td data-label="<?php esc_html_e( 'Validation Duration:', 'classified-listing-store' ) ?>"><?php esc_html_e( $promotion['validate'] ); ?></td>
                                    </tr>
								<?php endforeach; ?>
                            </table>
                        </div>
					<?php endif; ?>
				<?php else: ?>
					<?php if ( StoreFunctions::is_enable_free_ads() ) { ?>
                        <div class="report-item rtcl-membership-free-ads">
                            <!-- <label><//?php esc_html_e( 'Free Ads', 'classified-listing-store' ) ?></label> -->
                            <!-- <div class="value"><//?php echo absint( StoreFunctions::user_is_valid_to_post_as_free() ) ?></div> -->
                        </div>
					<?php } ?>
                    <p><?php esc_html_e( "You have no membership.", "classified-listing-store" ) ?></p>
				<?php endif ?>
            </div>
            <?php if (!$member ):?>
                <?php if ($should_nudge) :?>
                    <p><?php printf( __( "Continue where you left-off <a href='%s'>here</a>.", "classified-listing-store" ), Link::get_regular_submission_end_point('') ) ?></p>
                <?php else: ?>
                    <p><?php printf( __( "Start posting and get your membership <a href='%s'>here</a>", "classified-listing-store" ), Link::get_listing_form_page_link() ) ?></p>
                <?php endif;?>
            <?php endif; ?>
        </div>
    </div>
</div>