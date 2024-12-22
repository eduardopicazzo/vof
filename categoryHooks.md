
### Filter Hooks(9)

|			**Hook**									   |      **Priority**		|    					**Registered callbacks**								           |
|----------------------------------------------------------|------------------------|------------------------------------------------------------------------------------------|
|`rtcl_ajax_category_selection_before_post`				   |        10				|	[class] `RtclStore\Controllers\Ajax\Membership::is_valid_to_post_at_category`		   |
|`rtcl_ajax_filter_before_query_modify_data`			   |        10				|   [class] `RtclPro\Controllers\Hooks\FilterHooks::ajax_filter_modify_data`			   |
|`rtcl_before_add_edit_listing_before_category_condition`  |        1				| [closure]			         															   |
|														   |        10				|	[class] `RtclStore\Controllers\Hooks\MembershipHook::verify_membership_before_category`|
|`rtcl_before_add_edit_listing_into_category_condition`	   |        1				| [closure]		    		     														   |
|														   |        10				|	[class] `RtclStore\Controllers\Hooks\MembershipHook::verify_membership_into_category`  |
|`rtcl_category_validation`								   |        10				| [closure]	    				        												   |
|`rtcl_is_enable_post_for_unregister`					   |        100				|   [class] `RtclPro\Controllers\Hooks\FilterHooks::is_enable_post_for_unregister`		   |
|`rtcl_rest_api_form_category_before_post`				   |        10				|	[class] `RtclStore\Controllers\Ajax\Membership::is_valid_to_post_at_category_rest_api` |
|`rtcl_widget_ajax_filter_render_category`				   |        10				|	[class] `Rtcl\Controllers\Hooks\TemplateHooks::ajax_filter_render_category`			   |
|`rtcl_listing_form`									   |        5			    |	[class] `Rtcl\Controllers\Hooks\TemplateHooks::listing_category` 					   |







| **List of Action Hooks (4)**							   |		
|----------------------------------------------------------|
|`rtcl_before_add_edit_listing_before_category_condition`   |      
|`rtcl_before_template_part`  							   |      
|`rtcl_after_template_part`   							   |      
|`registered_taxonomy_store_category`					   |      