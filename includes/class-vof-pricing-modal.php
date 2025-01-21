<?php

namespace VOF;

class VOF_Pricing_Modal {

    public function __construct() {
        // only add footer hook if we're on the post-an-ad page
        add_action('template_redirect', [$this, 'vof_maybe_add_modal_footer']);
    }

    public function vof_maybe_add_modal_footer() {
        if ($this->vof_should_render_modal()) {
            add_action('wp_footer', [$this, 'vof_render_modal']);
        }
    }

    private function vof_should_render_modal() {
        // Check if we're on the post-ad page
        return strpos($_SERVER['REQUEST_URI'], '/post-an-ad/') !== false;
    }
        
    public function vof_render_modal() {
        // ob_start();
        ?>
        <div id="vofPricingModal" class="vof-pm-wrapper">
            <!-- html here -->
            <div id="vof-pm-pricingModal" class="vof-pm-modal">
                <div class="vof-pm-modal-content">
                    <div class="vof-pm-modal-header">
                        <h2 class="vof-pm-modal-title">Upgrade Plan</h2>
                        <button id="vof-pm-closeModalBtn" class="vof-pm-close-btn">×</button>
                    </div>
                    <div id="vof-pm-tabsContainer" class="vof-pm-tabs">
                        <button class="vof-pm-tab-btn vof-pm-active" data-tab="monthly">Mensualmente</button>
                        <button class="vof-pm-tab-btn" data-tab="yearly">Anualmente</button>
                    </div>
                    <div id="vof-pm-monthlyContent" class="vof-pm-tab-content vof-pm-active">
                        <div class="vof-pm-tier-container">
                            <!-- Tier content will be dynamically inserted here -->
                        </div>
                    </div>
                    <div id="vof-pm-yearlyContent" class="vof-pm-tab-content">
                        <div class="vof-pm-yearly-message">Precios anuales próximamente disponibles</div>
                    </div>
                    <div class="vof-pm-modal-footer">
                        <button id="vof-pm-cancelBtn" class="vof-pm-btn-footer vof-pm-btn-ghost">Cancelar</button>
                        <button id="vof-pm-contactSalesBtn" class="vof-pm-btn-footer vof-pm-btn-contact">Contactar Ventas</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        // return ob_get_clean();
    }
}