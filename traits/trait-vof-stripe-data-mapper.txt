<?php
namespace VOF\Traits;

/**
 * Trait VOF_Stripe_Data_Mapper
 * Handles mapping between Stripe and RTCL data formats
 */
trait VOF_Stripe_Data_Mapper {
    /**
     * Maps Stripe subscription period to RTCL days
     */
    protected function vof_map_subscription_period() {
        return 30; // Force RTCL compatibility
    }

    /**
     * Maps Stripe data to RTCL membership format
     */
    protected function vof_map_to_rtcl_format() {
        $pricing = $this->vof_get_pricing();
        
        return [
            'user_id' => $this->user_id,
            'visible' => $this->vof_map_subscription_period(),
            'status' => $this->vof_map_stripe_status_to_rtcl(
                $this->stripe_data['status']
            ),
            'categories' => $this->vof_map_categories($pricing),
            'promotions' => $this->vof_map_promotions($pricing)
        ];
    }

    /**
     * Maps subscription statuses
     */
    protected function vof_map_stripe_status_to_rtcl($stripe_status) {
        $status_map = [
            'active' => 'active',
            'past_due' => 'expired',
            'canceled' => 'cancelled',
            'unpaid' => 'expired'
        ];
        
        return $status_map[$stripe_status] ?? 'pending';
    }

    /**
     * Maps promotions from Stripe metadata
     */
    protected function vof_map_promotions($pricing) {
        if (!$pricing) return [];
        
        return [
            'featured' => $pricing->hasPromotion('featured') ? 
                $this->vof_map_subscription_period() : 0,
            '_top' => $pricing->hasPromotion('_top') ? 
                $this->vof_map_subscription_period() : 0,
            '_bump_up' => $pricing->hasPromotion('_bump_up') ? 
                $this->vof_map_subscription_period() : 0
        ];
    }
}