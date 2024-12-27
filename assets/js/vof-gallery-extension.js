/** 
 * vof-gallery-extension.js
 * Reuses existing Gallery System for vendor onboarding.
 * Extends without modifying existing original code @rtcl-gallery.js
 */

class VOFGalleryValidator {
    constructor() {
        // Wait for RTCL to be fully initialized
        if (typeof RTCL === 'undefined' || !RTCL.File) {
            console.error('RTCL Gallery system not loaded');
            return;
        }

        this.validateGallery = this.validateGallery.bind(this);
        
        // Wait for rtcl_gallery to be available
        if (typeof rtcl_gallery === 'undefined') {
            jQuery(document).on('rtcl_gallery_loaded', () => {
                this.initGalleryUploader();
            });
        } else {
            this.initGalleryUploader();
        }

        // hook into vof buttons
        jQuery('.vof-guest-submit-btn, .vof-subscription-submit-btn').on('click', this.validateGallery);
    }

    initGalleryUploader() {
        if (!RTCL.File.Uploader) {
            console.error('RTCL Uploader not available');
            return;
        }

        try {
            new RTCL.File.Uploader({
                browse_button: 'rtcl-gallery-upload',
                container: 'rtcl-gallery-container',
                multipart_params: {
                    action: 'rtcl_gallery_upload',
                    _ajax_nonce: window.rtcl_gallery?.nonce || ''
                } 
            });
        } catch (error) {
            console.error('Failed to initialize gallery uploader:', error);
        }
    }

    validateGallery(e) {
        if (!RTCL.File.Registered || !RTCL.File.Registered[0]) {
            if (e) {
                e.preventDefault();
                alert('Please upload at least one image to proceed.');
            }
            return false;
        }

        const hasImages = RTCL.File.Registered[0].Item && 
                         Object.keys(RTCL.File.Registered[0].Item).length > 0;

        if (!hasImages && e) {
            e.preventDefault();
            alert('Please upload at least one image to proceed.');
        }

        return hasImages;
    }
}

// Initialize validator after DOM and RTCL are ready
jQuery(document).ready(() => {
    // Check if RTCL is loaded
    if (typeof RTCL === 'undefined') {
        jQuery(document).on('rtcl_loaded', () => {
            window.vofGalleryValidator = new VOFGalleryValidator();
        });
    } else {
        window.vofGalleryValidator = new VOFGalleryValidator();
    }
});