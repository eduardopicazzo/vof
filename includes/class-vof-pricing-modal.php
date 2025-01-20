<?php

namespace VOF;

class VOF_Pricing_Modal {

    public function __construct() {
        add_action('wp_footer', [$this, 'vof_render_modal']);
    }
    
    public function vof_render_modal() {
        // ob_start();
        ?>
        <div id="vofPricingModal" class="vof-modal">
            <!-- html here -->
            <div id="pricingModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">Upgrade Plan</h2>
                        <button id="closeModalBtn" class="close-btn">×</button>
                    </div>
                    <div id="tabsContainer" class="tabs">
                        <button class="tab-btn active" data-tab="monthly">Mensualmente</button>
                        <button class="tab-btn" data-tab="yearly">Anualmente</button>
                    </div>
                    <div id="monthlyContent" class="tab-content active">
                        <div class="tier-container">
                            <!-- Tier content will be dynamically inserted here -->
                        </div>
                    </div>
                    <div id="yearlyContent" class="tab-content">
                        <div class="yearly-message">Precios anuales próximamente disponibles</div>
                    </div>
                    <div class="modal-footer">
                        <button id="cancelBtn" class="btn-footer btn-ghost">Cancelar</button>
                        <button id="contactSalesBtn" class="btn-footer btn-contact">Contactar Ventas</button>
                    </div>
                </div>
            </div>             
        </div>
        <?php
        // return ob_get_clean();
    }
}