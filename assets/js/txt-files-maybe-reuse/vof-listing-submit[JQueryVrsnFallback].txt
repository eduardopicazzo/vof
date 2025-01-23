(function($) {
    'use strict';

    // Helper to sanitize gallery data for logging
    function vof_sanitize_gallery_data(data) {
        if (!data) return null;

        const sanitizedData = [];
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
        
        // Log raw form data for debugging
        const debugFormData = function(formData) {
            const formDataObj = {};
            for (const [key, value] of formData.entries()) {
                formDataObj[key] = value;
            }
            return formDataObj;
        };

        // Get form reference using jQuery
        const $button = $(e?.target || '.vof-guest-submit-btn, .vof-subscription-submit-btn');
        const $form = $button.closest('form');
        
        if (!$form.length) {
            console.error('VOF: Form not found');
            return;
        }

        // Add our custom flag
        const vofFlowInput = document.createElement('input');
        vofFlowInput.type = 'hidden';
        vofFlowInput.name = 'vof_flow';
        vofFlowInput.value = 'true';
        $form.append(vofFlowInput);

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
        const formData = new FormData($form[0]); // Note: FormData needs DOM element
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

        // Log form and gallery data
        vof_logs.submission_data = {
            timestamp: new Date().toISOString(),
            form_data: debugFormData(formData)
        };
        
        vof_logs.gallery_data = {
            timestamp: new Date().toISOString(),
            gallery_info: galleryInfo,
            RTCL_File_Registered: RTCL.File.Registered
        };

        const $msgHolder = $("<div class='alert rtcl-response'></div>");
        
        // Set action
        formData.set('action', 'rtcl_post_new_listing');

        // Add our flag
        formData.set('vof_flow', 'true');

        console.log('Submitting form data:', formData);

        // Make the AJAX request
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
                console.log('VOF Debug: Response received:', response);
                
                // Log response
                // vof_logs.responses.success = {
                //     timestamp: new Date().toISOString(),
                //     response: response
                // };

                // Download logs 
                // vof_download_logs(vof_logs);

                $form.find('button[type=submit], button[type=button]').prop("disabled", false);
                $form.rtclUnblock();

                let msg = '';
                if (response.message?.length) {
                    response.message.map(function(message) {
                        msg += "<p>" + message + "</p>";
                    });
                }

                // if (response.success || (response.data && response.data.success)) {
                if (response.success) {
                    // const redirectUrl = response.redirect_url || (response.data && response.data.redirect_url);
                    
                    const checkoutUrl = response.data?.data?.checkout_url;
                    console.log('VOF Debug: Checkout URL:', checkoutUrl);

                    // $form[0].reset();
                    if (msg) {
                        $msgHolder.removeClass('alert-danger')
                                  .addClass('alert-success')
                                  .html(msg)
                                  .appendTo($form);
                    }
                    
                    // Handle Stripe checkout redirect
                    if (checkoutUrl) {
                        console.log('VOF Debug: Redirecting to Stripe checkout:', checkoutUrl);
                        setTimeout(function() {
                            window.location.href = checkoutUrl;
                        }, 1000);
                    } else {
                        console.error('VOF Debug: No checkout URL found in response');
                        // Fallback to regular redirect
                        // const redirectUrl = response.redirect_url || (response.data && response.data.redirect_url);
                        const redirectUrl = response.redirect_url || response.data?.redirect_url;
                        if (redirectUrl) {
                            window.location.href = redirectUrl;
                        }
                    }
                } else {
                    if (msg) {
                        $msgHolder.removeClass('alert-success')
                                  .addClass('alert-danger')
                                  .html(msg)
                                  .appendTo($form);
                    }
                }
            },
            error: function(e) {
                console.error('VOF: Submission error:', e);
                
                // Log error
                vof_logs.responses.error = {
                    timestamp: new Date().toISOString(),
                    error: e.responseText
                };

                // Download logs on error
                vof_download_logs(vof_logs);

                $form.find('button[type=submit], button[type=button]').prop("disabled", false);
                $form.rtclUnblock();
                
                $msgHolder.removeClass('alert-success')
                          .addClass('alert-danger')
                          .html(e.responseText)
                          .appendTo($form);
            }
        });
    };
})(jQuery);