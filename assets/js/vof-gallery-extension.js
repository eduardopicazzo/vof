/** 
 * 
 * vof-gallery-extension.js
 * Reuses existing Gallery System for vendor onboarding.
 * Extends without modifying existing original code @rtcl-gallery.js
 * 
 */

class VOFGalleryValidator {
    constructor() {
        // hook into vof buttons
        jQuery('.vof-guest-submit-btn, .vof-subscription-submit-btn' ).on('click', this.validateGallery);

        // Reuse existing uploader
        this.initGalleryUploader();
    }

    initGalleryUploader() {
        // Reuse RTCL.File system with custom config.
        new RTCL.File.Uploader({
            browse_button: 'rtcl-gallery-upload', // the button on the upload box
            container: 'rtcl-gallery-container', // the div

            // Reuse existing AJAX endpoint
            multipart_params: {
                action: 'rtcl_gallery_upload',
                _ajax_nonce: rtcl_gallery.nonce
            } 
        });
    }

    validateGallery(e) {
        // Check if gallery has images
        const hasImages = Object.keys(RTCL.File.Registered[0].Item).length > 0;

        if (!hasImages) {
            e.preventDefault();
            alert('Please upload at least one image to proceed.');
            return false;
        }
        return true;
    }
}

jQuery(document).ready(() => new VOFGalleryValidator());