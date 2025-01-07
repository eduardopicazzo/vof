(function($) {
    'use strict';

    // Helper to sanitize gallery data for logging
    function vof_sanitize_gallery_data(data) {
        if (!data) return null;

        const sanitizedData = [];

        // Only take what we need from each registered uploader
        data.forEach(uploader => {
            if (uploader && uploader.Item) {
                const items = {};
                Object.entries(uploader.Item).forEach(([key, item]) => {
                    if (item && item.result) {
                        items[key] = {
                            id: item.file?.id,
                            result: {
                                attach_id: item.result.attach_id,
                                caption: item.result.caption,
                                featured: item.result.featured,
                                url: item.result.url,
                                sizes: item.result.sizes
                            }
                        };
                    }
                });
                sanitizedData.push({ Item: items });
            }
        });

        return sanitizedData;
    }

    // Helper to download logs
    function vof_download_logs(logs, fileName = 'vof-submission-logs.json') {
        // Create a sanitized copy of the logs
        const sanitizedLogs = {
            ...logs,
            gallery_data: {
                ...logs.gallery_data,
                RTCL_File_Registered: vof_sanitize_gallery_data(logs.gallery_data.RTCL_File_Registered)
            }
        };

        const blob = new Blob([JSON.stringify(sanitizedLogs, null, 2)], { type: 'application/json' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    }

    // Capture logs in memory
    const vof_logs = {
        submission_data: {},
        gallery_data: {},
        responses: {},
        timestamps: {}
    };

    window.handleTempListing = function(e) {
        if (e) {
            e.preventDefault();
        }

        const button = e?.target || 
                    document.querySelector('.vof-guest-submit-btn, .vof-subscription-submit-btn');
        const form = button?.closest('form');

        // Add our custom flag to identify VOF submissions
        const vofFlowInput = document.createElement('input');
        vofFlowInput.type = 'hidden';
        vofFlowInput.name = 'vof_flow';
        vofFlowInput.value = 'true';
        form.appendChild(vofFlowInput);

        // Handle TinyMCE if active
        try {
            if (typeof tinymce !== 'undefined') {
                tinymce.triggerSave();
                const editor = tinymce.get("description");
                if (editor) {
                    editor.save();
                }
            }
        } catch (e) {
            vof_logs.tinymce_error = e.message;
            console.log('TinyMCE not available', e);
        }

        // Handle gallery data
        const formData = new FormData(form);
        let galleryInfo = { attachments: [], featured: null };

        if (RTCL.File.Registered && RTCL.File.Registered[0]) {
            const galleryData = [];
            Object.values(RTCL.File.Registered[0].Item).forEach(item => {
                if (item.result && item.result.attach_id) {
                    galleryData.push(item.result.attach_id);
                    galleryInfo.attachments.push(item.result);
                }
            });

            if (galleryData.length) {
                formData.set('rtcl_gallery_ids', galleryData.join(','));
                
                // Set featured image if available
                const featuredImage = Object.values(RTCL.File.Registered[0].Item).find(item => 
                    item.result && item.result.featured
                );
                if (featuredImage) {
                    formData.set('featured_image_id', featuredImage.result.attach_id);
                    galleryInfo.featured = featuredImage.result;
                } else if (galleryData[0]) {
                    // Use first image as featured if none set
                    formData.set('featured_image_id', galleryData[0]);
                    galleryInfo.featured = galleryInfo.attachments[0];
                }
            }
        }

        // Log gallery data
        vof_logs.gallery_data = {
            timestamp: new Date().toISOString(),
            gallery_info: galleryInfo,
            RTCL_File_Registered: RTCL.File.Registered
        };

        // Handle reCAPTCHA if enabled
        if (typeof rtcl !== 'undefined' && rtcl.recaptcha && rtcl.recaptcha.on) {
            if (rtcl.recaptcha.v === 3) {
                grecaptcha.ready(function() {
                    $(form).rtclBlock();
                    grecaptcha.execute(rtcl.recaptcha.site_key, {
                        action: 'listing'
                    }).then(function(token) {
                        vof_submit_to_original_endpoint(form, token);
                    });
                });
                return false;
            }
        }

        vof_submit_to_original_endpoint(form);
    };

    function vof_submit_to_original_endpoint(form, reCaptcha_token = null) {
        const formData = new FormData(form);

        // Handle gallery data
        if (RTCL.File.Registered && RTCL.File.Registered[0]) {
            const galleryData = [];
            Object.values(RTCL.File.Registered[0].Item).forEach(item => {
                if (item.result && item.result.attach_id) {
                    galleryData.push(item.result.attach_id);
                }
            });

            if (galleryData.length) {
                // Important: This was missing - set the gallery IDs as a comma-separated string
                formData.set('rtcl_gallery_ids', galleryData.join(','));

                // Set featured image
                const featuredImage = Object.values(RTCL.File.Registered[0].Item).find(item => 
                    item.result && item.result.featured
                );
                if (featuredImage) {
                    formData.set('featured_image_id', featuredImage.result.attach_id);
                } else if (galleryData[0]) {
                    // Use first image as featured if none set
                    formData.set('featured_image_id', galleryData[0]);
                }
            }
        }

        // Debug log before sending
        console.log('Gallery Data being sent:');
        console.log('Gallery IDs:', formData.get('rtcl_gallery_ids'));
        console.log('Featured Image:', formData.get('featured_image_id'));



        // Set the action 
        formData.set('action', 'rtcl_post_new_listing');
        
        if (reCaptcha_token) {
            formData.set('g-recaptcha-response', reCaptcha_token);
        }

        // Add our flag
        formData.set('vof_flow', 'true');

        const msgHolder = $("<div class='alert rtcl-response'></div>");
        const $form = $(form);

        $.ajax({
            url: rtcl.ajaxurl,
            type: "POST",
            dataType: 'json',
            cache: false,
            contentType: false,
            processData: false,
            data: formData,
            beforeSend: function() {
                $form.find('.alert.rtcl-response').remove();
                $form.find('button[type=submit], button[type=button]').prop("disabled", true);
                $form.rtclBlock();
            },
            success: function(response) {
                $form.find('button[type=submit], button[type=button]').prop("disabled", false);
                $form.rtclUnblock();

                // Log response
                vof_logs.responses.success = {
                    timestamp: new Date().toISOString(),
                    response: response
                };

                // Download logs before potential redirect
                vof_download_logs(vof_logs);

                let msg = '';
                if (response.message?.length) {
                    response.message.map(function(message) {
                        msg += "<p>" + message + "</p>";
                    });
                }

                if (response.success) {
                    form.reset();
                    if (msg) {
                        msgHolder.removeClass('alert-danger')
                                .addClass('alert-success')
                                .html(msg)
                                .appendTo(form);
                    }
                    if (response.redirect_url) {
                        // Add small delay to ensure log download completes
                        setTimeout(function() {
                            window.location.href = response.redirect_url;
                        }, 1000);
                    }
                } else {
                    if (msg) {
                        msgHolder.removeClass('alert-success')
                                .addClass('alert-danger')
                                .html(msg)
                                .appendTo(form);
                    }
                }
            },
            error: function(e) {
                $form.find('button[type=submit], button[type=button]').prop("disabled", false);
                $form.rtclUnblock();
                
                // Log error
                vof_logs.responses.error = {
                    timestamp: new Date().toISOString(),
                    error: e.responseText
                };

                // Download logs on error
                vof_download_logs(vof_logs);
                
                msgHolder.removeClass('alert-success')
                        .addClass('alert-danger')
                        .html(e.responseText)
                        .appendTo(form);
            }
        });
    }
})(jQuery);