window.VOFFormValidation = (function() {
    'use strict';

    // Add gallery validation directly in the main validation object
    function validateGallery() {
        if (!RTCL.File.Registered || !RTCL.File.Registered[0]) {
            return false;
        }

        const hasImages = RTCL.File.Registered[0].Item && 
                         Object.keys(RTCL.File.Registered[0].Item).length > 0;

        const uploadsComplete = Object.values(RTCL.File.Registered[0].Item).every(item => 
            item.result && item.result.attach_id
        );

        return hasImages && uploadsComplete;
    }

    // Smooth scroll function with dynamic header height calculation
    function smoothScrollToElement(element) {
        // Get header element - adjust selector if needed
        const header = document.querySelector('.site-header') || document.querySelector('header');
        
        // Calculate header height dynamically
        const headerHeight = header ? header.getBoundingClientRect().height : 0;
        
        // Add some padding for better visual spacing
        const additionalPadding = 60;
        
        // Calculate total offset
        const totalOffset = headerHeight + additionalPadding;
        
        // Get element's position relative to viewport
        const elementRect = element.getBoundingClientRect();
        const absoluteElementTop = elementRect.top + window.pageYOffset;
        
        // Calculate final scroll position
        const offsetPosition = absoluteElementTop - totalOffset;

        // Smooth scroll to element
        window.scrollTo({
            top: offsetPosition,
            behavior: 'smooth'
        });

        // Optional: Ensure element is visible after scrolling
        // setTimeout(() => {
        //     const finalRect = element.getBoundingClientRect();
        //     if (finalRect.top < 0) {
        //         window.scrollBy({
        //             top: finalRect.top - totalOffset,
        //             behavior: 'smooth'
        //         });
        //     }
        // }, 800); // Adjust timeout based on your scroll animation duration
    }

    const requiredFields = {
        'rtcl-title': {
            message: '☝️ aquí tu título. PRO TIP: sé claro, pero déjalos con curiosidad',
            validate: value => value.trim().length > 0
        },
        'rtcl-gallery': {
            message: '☝️ ojos que no ven, corazón que no compra',
            validate: validateGallery
        },
        'description': {
            message: '☝️ aquí tu descripción. PRO TIP: lúcete con esto, ¡es LA clave para destacar!',
            validate: () => {
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('description')) {
                    const content = tinyMCE.get('description').getContent();
                    return content.trim().length > 0;
                }
                const iframe = document.getElementById('description_ifr');
                if (iframe) {
                    return iframe.contentWindow.document.body.textContent.trim().length > 0;
                }
                return false;
            }
        },
        'rtcl-price': {
            message: '☝️ heyyyy! no te saltes precio. ¿sabes por qué? el precio es la base de tu marketing',
            validate: value => {
                const pricingDisabled = document.getElementById('_rtcl_listing_pricing_disabled');
                if (pricingDisabled && pricingDisabled.checked) return true;
                return !isNaN(value) && parseFloat(value) >= 0;
            }
        },
        'rtcl-price-type': {
            message: 'no olvides el tipo de precio ☝️',
            validate: value => value.trim().length > 0
        },
        'rtcl-geo-address': {
            message: 'no seas tímido, aquí tu ubicación ☝️',
            validate: value => value.trim().length > 0
        },
        // 'rtcl-phone': { rtcl-geo-address
        'vof-phone': {
            message: 'tus clientes llegan aquí ☝️',
            validate: value => /^\+?[\d\s-]{10,}$/.test(value.trim())
        },
        // 'rtcl-whatsapp-number': {
        'vof-whatsapp-number': {
            message: 'tus clientes llegan aquí ☝️',
            validate: value => /^\+?[\d\s-]{10,}$/.test(value.trim())
        },
        // 'rtcl-email': {
        'vof_email': {
            message: 'tu email, aquí ☝️ suceden cosas muy interesantes',
            validate: value => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim())
        },
        'vof_email_confirm': {
            message: 'Los emails no coinciden ☝️',
            validate: value => value === document.getElementById('vof_email').value
        }
    };

    return {
        requiredFields,
        
        validateForm: function(form) {
            let isValid = true;
            let firstError = null;
            this.clearErrors(form);

            // Validate gallery first
            const galleryValid = this.requiredFields['rtcl-gallery'].validate();
            if (!galleryValid) {
                isValid = false;
                const galleryContainer = document.querySelector('.rtcl-gallery');
                if (galleryContainer) {
                    // Find the uploads area within the gallery container
                    const galleryWrapper = galleryContainer.querySelector('.rtcl-gallery-uploads');
                    this.showError(galleryContainer, this.requiredFields['rtcl-gallery'].message);
                    
                    // Set focus to the gallery container
                    galleryContainer.setAttribute('tabindex', '-1');
                    if (!firstError) {
                        firstError = galleryContainer;
                        // Add a small delay before focusing to ensure smooth scroll completes
                        // setTimeout(() => {
                            galleryContainer.focus();
                        // }, 600);
                    }
                }
            }

            // Validate other fields
            Object.entries(this.requiredFields).forEach(([fieldId, config]) => {
                if (fieldId === 'rtcl-gallery') return; // Skip gallery as it's already validated
                const field = document.getElementById(fieldId);
                if (!field) return;

                if (!config.validate(field.value)) {
                    isValid = false;
                    const wrapper = this.showError(field, config.message);
                    if (!firstError) {
                        firstError = wrapper;
                    }
                }
            });

            // Scroll to first error if any
            if (firstError) {
                smoothScrollToElement(firstError);
            }

            return isValid;
        },

        clearErrors: function(form) {
            form.querySelectorAll('.vof-error').forEach(el => {
                el.classList.remove('vof-error');
                el.querySelector('.vof-error-message')?.remove();
            });
        },

        showError: function(field, message) {
            const wrapper = field.closest('.form-group') || field.parentElement;
            wrapper.classList.add('vof-error');
            const errorDiv = document.createElement('div');
            errorDiv.className = 'vof-error-message';
            errorDiv.textContent = message;
            wrapper.appendChild(errorDiv);
            return wrapper;
        },

        init: function() {
            const style = document.createElement('style');
            // border: 2px dashed #dc3545 !important;
            // .vof-error .rtcl-gallery-uploads {
            // border: 2px dashed #dc3545 !important;
            style.textContent = `
                .vof-error input,
                .vof-error select,
                .vof-error textarea {
                    border-color: #dc3545 !important;
                }
                .vof-error .rtcl-gallery {
                    border-color: #dc3545 !important;
                    border-radius: 4px;
                    padding: 10px;
                    background-color: rgba(220, 53, 69, 0.05);
                    transition: all 0.3s ease;
                }
                .vof-error .rtcl-gallery-uploads:focus-within {
                    border-color: #dc3545 !important;
                    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
                    outline: none;
                }
                .vof-error-message {
                    color: #dc3545;
                    font-size: 0.875em;
                    margin-top: 4px;
                }
            `;
            document.head.appendChild(style);
        }
    };
})();