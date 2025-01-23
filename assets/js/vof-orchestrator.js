// File: vof-orchestrator.js
(function() {
    'use strict';

    // Configuration flags (set via PHP localization)
    window.vofConfig = {
        enableValidation: false,
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
                } else if (response.data?.uuid) {
                    console.log('VOF Debug: Opening Pricing Modal on real data condition');
                    // setTimeout(() => {
                        // window.location.href = response.data.checkout_url;
                        window.openModal(response);
                    // }, 50000);
                } else {
                    console.log('VOF Debug: no data found in response... please retry');
                }
                
                // // Call original success handler if exists
                // if (typeof originalOnSuccess === 'function') {
                //     originalOnSuccess(response);
                // }
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

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        initializeValidation();
        
        // Add event listeners to both button types
        document.querySelectorAll('.vof-guest-submit-btn, .vof-subscription-submit-btn').forEach(button => {
            button.addEventListener('click', handleSubmission);
        });

        // Optional: Add debug button
        // const debugHTML = `<div style="position:fixed;bottom:20px;right:20px;z-index:9999;background:#fff;padding:10px;border:1px solid #ccc;">
            // <button onclick="window.openModal(false)">Test Modal</button>
        // </div>`;
        document.body.insertAdjacentHTML('beforeend', debugHTML);
    });

})();