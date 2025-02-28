// File: vof-orchestrator.js
(function() {
    'use strict';

    // Configuration flags (set via PHP localization)
    window.vofConfig = {
        enableValidation: true,
        stubMode: false // Set this via PHP to toggle stub behavior
    };

    // Initialize validation module
    function initializeValidation() {
        if (typeof VOFFormValidation !== 'undefined') {
            VOFFormValidation.init();
        }
    }

    // Main submission handler
    function handleSubmission(e) {
        e.preventDefault();
        const form = e.target.closest('form');
        if (!form) {
            console.error('VOF: Form not found');
            return;
        }

        // Step 1: Validate form
        if (window.vofConfig.enableValidation) {
            if (!VOFFormValidation.validateForm(form)) {
                console.log('VOF: Form validation failed');
                return;
            }
        }

        // Step 2: Process form data
        if (typeof window.handleTempListing === 'function') {
            // Store original success handler
            const originalOnSuccess = window.handleTempListingSuccess;
            
            // Override success handler to handle modal
            window.handleTempListingSuccess = function(response) {
                // Step 3: Handle response
                if (response.data?.stub_mode) {
                    console.log('VOF Debug: Stub mode active - opening modal via orchestrator');
                    // Open the modal
                    window.openModal(false);
                } else if (response.data?.customer_meta && response.data?.pricing_data) {
                    console.log('VOF Debug: Opening Pricing Modal with API data');
                        window.openModal({
                            customer_meta: response.data.customer_meta,
                            pricing_data: response.data.pricing_data
                        });
                } else {
                    console.log('VOF Debug: Invalid API response structure', response);
                    // Won't open modal if fallback is disabled
                    window.openModal(null);
                }
            };

            // Add temporary error handler for stub debugging
            const originalOnError = window.handleTempListingError;
            window.handleTempListingError = function(e) {
                console.log('VOF Debug: Submission error in stub mode');
                if (window.vofConfig.stubMode) {
                    window.openModal(false); // Force modal open even on errors for testing
                }
                originalOnError(e);
            };

            window.handleTempListing(e);
        }
    }

    // Checkout flow handler
    window.handleCheckoutStart = function(checkoutData) {
        console.log('VOF Debug: Starting checkout with data:', checkoutData);

        const formData = new FormData();
        formData.append('action', 'vof_start_checkout'); // how come this works (no name like this anywhere)? if in form handler ? wp_ajax_nopriv_vof_start_checkout : wp_ajax_vof_start_checkout
        formData.append('uuid', checkoutData.uuid);
        formData.append('tier_name', checkoutData.tier_selected.name);
        formData.append('tier_price', checkoutData.tier_selected.price);
        formData.append('tier_interval', checkoutData.tier_selected.interval);
        formData.append('security', vofSettings.security); // From wp_localize_script

        console.log('VOF Debug: Sending AJAX request with formData:', {
            action: formData.get('action'),
            uuid: formData.get('uuid'),
            tier_name: formData.get('tier_name'),
            tier_price: formData.get('tier_price'),
            tier_interval: formData.get('tier_interval')
        });
    
        // Submit to WordPress AJAX endpoint
        const xhr = new XMLHttpRequest();
        xhr.open('POST', vofSettings.ajaxurl, true);
        xhr.responseType = 'json';

        // Adding more detailed error handling
        xhr.onerror = function() {
            console.error('VOF Debug: Network error during checkout', xhr.statusText);
        };
    
        xhr.onload = function() {
            console.log('VOF Debug: Checkout response:', xhr.response);            
            if (xhr.status === 200) {
                const response = xhr.response;
                
                if (response.success && response.data?.data?.checkout_url) {
                    console.log('VOF Debug: Redirecting to:', response.data.data.checkout_url);                  
                    // console.log('VOF Debug: Redirecting to Stripe checkout');
                    window.location.href = response.data.data.checkout_url;
                } else {
                    console.error('VOF Debug: Invalid checkout response', response);
                }
            } else {
                console.error('VOF Debug: Checkout request failed with status', xhr.status);
            }
        };
    
        xhr.onerror = function() {
            console.error('VOF Debug: Network error during checkout');
        };
    
        xhr.send(formData);
    };

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        initializeValidation();
        
        // Add event listeners to both button types
        document.querySelectorAll('.vof-guest-submit-btn, .vof-subscription-submit-btn').forEach(button => {
            button.addEventListener('click', handleSubmission);
        });

        // Optional: Add debug button
        const debugHTML = `<div style="position:fixed;bottom:20px;right:20px;z-index:9999;background:#fff;padding:10px;border:1px solid #ccc;">
            <button onclick="window.openModal(false)">Test Modal</button>
        </div>`;
        document.body.insertAdjacentHTML('beforeend', debugHTML);
    });

})();