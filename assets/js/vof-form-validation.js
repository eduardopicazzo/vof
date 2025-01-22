// File: vof-form-validation.js
window.VOFFormValidation = (function() {
    'use strict';

    const requiredFields = {
        'rtcl-title': {
            message: 'Title is required',
            validate: value => value.trim().length > 0
        },
        // 'rtcl-gallery': {
        //     message: 'At least one image is required',
        //     validate: () => {
        //         if (!window.vofGalleryValidator) {
        //             console.error('Gallery validator not initialized');
        //             return false;
        //         }
        //         return window.vofGalleryValidator.validateGallery();
        //     }
        // },
        'description': {
            message: 'Description is required',
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
            message: 'Price is required',
            validate: value => {
                const pricingDisabled = document.getElementById('_rtcl_listing_pricing_disabled');
                if (pricingDisabled && pricingDisabled.checked) return true;
                return !isNaN(value) && parseFloat(value) >= 0;
            }
        },
        'rtcl-price-type': {
            message: 'Price type is required',
            validate: value => value.trim().length > 0
        },
        'rtcl-phone': {
            message: 'Valid phone number is required',
            validate: value => /^\+?[\d\s-]{10,}$/.test(value.trim())
        },
        'rtcl-whatsapp-number': {
            message: 'Valid WhatsApp number is required',
            validate: value => /^\+?[\d\s-]{10,}$/.test(value.trim())
        },
        'rtcl-email': {
            message: 'Valid email is required',
            validate: value => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim())
        }
    };

    return {
        requiredFields,
        
        validateForm: function(form) {
            let isValid = true;
            let firstError = null;
            this.clearErrors(form);

            // Validate gallery first
            // const galleryValid = this.requiredFields['rtcl-gallery'].validate();
            // if (!galleryValid) {
            //     isValid = false;
            //     const galleryWrapper = document.querySelector('.rtcl-gallery-uploads');
            //     if (galleryWrapper) {
            //         this.showError(galleryWrapper, this.requiredFields['rtcl-gallery'].message);
            //     }
            // }

            // Validate other fields
            Object.entries(this.requiredFields).forEach(([fieldId, config]) => {
                if (fieldId === 'rtcl-gallery') return;
                const field = document.getElementById(fieldId);
                if (!field) return;

                if (!config.validate(field.value)) {
                    isValid = false;
                    const wrapper = this.showError(field, config.message);
                    if (!firstError) firstError = wrapper;
                }
            });

            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
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
            style.textContent = `
                .vof-error input,
                .vof-error select,
                .vof-error textarea,
                .vof-error .rtcl-gallery-uploads {
                    border-color: #dc3545 !important;
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