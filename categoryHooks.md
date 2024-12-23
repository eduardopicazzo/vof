
### Filter Hooks(9)

|			**Hook**									   |      **Priority**		|    					**Registered callbacks**								          |     |
|----------------------------------------------------------|------------------------|-----------------------------------------------------------------------------------------|-----|
|`rtcl_ajax_category_selection_before_post`				   |        10				| [class] `RtclStore\Controllers\Ajax\Membership::is_valid_to_post_at_category`		      | [X] |
|                                                          |                        |  wp-content/plugins/classified-listing-store/app/Controllers/Hooks/MembershipHook.php   |     |
|                                                          |                        |  wp-content/plugins/classified-listing-store/app/Controllers/Ajax/Membership.php        |     |
|                                                          |                        |  wp-content/plugins/classified-listing/app/Controllers/Ajax/PublicUser.php              |     |
|                                                          |                        |  wp-content/plugins/classified-listing/app/Controllers/Ajax/FormBuilderAjax.php         |     | 
|                                                          |                        |                                                                                         |     |
|`rtcl_ajax_filter_before_query_modify_data`			   |        10				| [class] `RtclPro\Controllers\Hooks\FilterHooks::ajax_filter_modify_data`			      | []  |
|                                                          |                        | wp-content/plugins/classified-listing/app/Controllers/Ajax/FilterAjax.php               |     |
|                                                          |                        | wp-content/plugins/classified-listing-pro/app/Controllers/Hooks/FilterHooks.php         |     |   
|`rtcl_before_add_edit_listing_before_category_condition`  |        1				| [closure]			         															  | []  |
|														   |        10				| [class] `RtclStore\Controllers\Hooks\MembershipHook::verify_membership_before_category` | []  |
|                                                          |                        | wp-content/plugins/classified-listing/app/Controllers/Ajax/PublicUser.php               |     |
|                                                          |                        | wp-content/plugins/classified-listing/app/Shortcodes/ListingForm.php                    |     |
|                                                          |                        | wp-content/plugins/classified-listing-pro/app/Api/V1/V1_CommonApi.php                   |     |
|                                                          |                        | wp-content/plugins/classified-listing-pro/app/Api/V1/V1_ListingApi.php                  |     |
|                                                          |                        | wp-content/plugins/classified-listing-store/app/Controllers/Hooks/MembershipHook.php    |     |
|`rtcl_before_add_edit_listing_into_category_condition`	   |        1				| [closure]		    		     														  | []  |
|														   |        10				| [class] `RtclStore\Controllers\Hooks\MembershipHook::verify_membership_into_category`   | []  |
|                                                          |                        | wp-content/plugins/classified-listing/app/Controllers/Ajax/PublicUser.php               |     |
|                                                          |                        | wp-content/plugins/classified-listing/app/Services/FormBuilder/FBHelper.php             |     |
|                                                          |                        | wp-content/plugins/classified-listing/app/Shortcodes/ListingForm.php                    |     |
|                                                          |                        | wp-content/plugins/classified-listing-pro/app/Api/V1/V1_ListingApi.php                  |     |
|                                                          |                        | wp-content/plugins/classified-listing-store/app/Controllers/Hooks/MembershipHook.php    |     |
|`rtcl_category_validation`								   |        10				| [closure]	    				        												  | []  |
|`rtcl_is_enable_post_for_unregister`					   |        100				| [class] `RtclPro\Controllers\Hooks\FilterHooks::is_enable_post_for_unregister`		  | []  |
|                                                          |                        | wp-content/plugins/classified-listing-pro/app/Controllers/Hooks/FilterHooks.php         |     |
|                                                          |                        | wp-content/plugins/classified-listing/app/Helpers/Functions.php                         |     |
|`rtcl_rest_api_form_category_before_post`				   |        10				| [class] `RtclStore\Controllers\Ajax\Membership::is_valid_to_post_at_category_rest_api`  | []  |
|                                                          |                        | wp-content/plugins/classified-listing-pro/app/Api/V1/V1_CommonApi.php                   |     |
|                                                          |                        | wp-content/plugins/classified-listing-store/app/Controllers/Ajax/Membership.php         |     |
|                                                          |                        | wp-content/plugins/classified-listing-store/app/Controllers/Hooks/MembershipHook.php    |     |
|`rtcl_widget_ajax_filter_render_category`				   |        10				| [class] `Rtcl\Controllers\Hooks\TemplateHooks::ajax_filter_render_category`			  | []  |
|                                                          |                        | wp-content/plugins/classified-listing/app/Controllers/Hooks/TemplateHooks.php           |     |      
|`rtcl_listing_form`									   |        5			    | [class] `Rtcl\Controllers\Hooks\TemplateHooks::listing_category` 					      | []  |
|                                                          |                        |  wp-content/plugins/classified-listing/app/Controllers/BusinessHoursController.php      |     |
|                                                          |                        |  wp-content/plugins/classified-listing/app/Controllers/Shortcodes.php                   |     |
|                                                          |                        |  wp-content/plugins/classified-listing/app/Controllers/SocialProfilesController.php     |     |
|                                                          |                        |  wp-content/plugins/classified-listing/app/Controllers/Admin/AddConfig.php              |     |
|                                                          |                        |  wp-content/plugins/classified-listing/app/Controllers/Ajax/FormBuilderAjax.php         |     |
|                                                          |                        |  wp-content/plugins/classified-listing/app/Controllers/Ajax/PublicUser.php (NR)         |     |
|                                                          |                        |  wp-content/plugins/classified-listing/app/Controllers/Hooks/TemplateHooks.php          |     |
|                                                          |                        |  wp-content/plugins/classified-listing/app/Helpers/Functions.php                        |     |
|                                                          |                        |  wp-content/plugins/classified-listing/views/settings/advanced-settings.php             |     |







| **List of Action Hooks (4)**							   |		
|----------------------------------------------------------|
|`rtcl_before_add_edit_listing_before_category_condition`   |      
|`rtcl_before_template_part`  							   |      
|`rtcl_after_template_part`   							   |      
|`registered_taxonomy_store_category`					   |      