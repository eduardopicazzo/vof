(function() {
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

        // Get form reference
        const button = e?.target || document.querySelector('.vof-guest-submit-btn, .vof-subscription-submit-btn');
        const form = button.closest('form');

        if (!form) {
            console.error('VOF: Form not found');
            return;
        }

        // Add our custom flag
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

        const msgHolder = document.createElement('div');
        msgHolder.className = 'alert rtcl-response';

        // Set action
        formData.set('action', 'rtcl_post_new_listing');

        // Add our flag
        formData.set('vof_flow', 'true');

        console.log('Submitting form data:', formData);

        // Make the AJAX request
        const xhr = new XMLHttpRequest();
        xhr.open('POST', rtcl.ajaxurl, true);
        xhr.responseType = 'json';

        xhr.onload = function() {
            if (xhr.status === 200) {
                const response = xhr.response;
                console.log('VOF Debug: Response received:', response);

                // Enable buttons and unblock form
                form.querySelectorAll('button[type=submit], button[type=button]').forEach(button => {
                    button.disabled = false;
                });
                form.classList.remove('rtcl-block');

                let msg = '';
                if (response.message?.length) {
                    response.message.forEach(message => {
                        msg += "<p>" + message + "</p>";
                    });
                }

                if (response.success) {
                    // production:
                    // const checkoutUrl = response.data?.data?.checkout_url;
                    // stub paths:
                    const responseUUID = response.data?.uuid; // directly under data
                    const isStub = response.data?.stub_mode;  // access via response.data

                    console.log('VOF Debug: Validated UUID:', responseUUID, 'Stub mode:', isStub);

                    if (msg) {
                        msgHolder.classList.remove('alert-danger');
                        msgHolder.classList.add('alert-success');
                        msgHolder.innerHTML = msg;
                        form.appendChild(msgHolder);
                    }

                        // Modified handling
                    if (isStub) {
                        console.log('VOF Debug: calling handleTempListingSuccess');
                        // 🔥 Critical Change: Delegate to global handler
                        if (typeof window.handleTempListingSuccess === 'function') {
                            window.handleTempListingSuccess(response);
                        } else {
                            console.error('VOF: Submission error:', xhr.statusText);
                        }
                    } else if (responseUUID) { // Handle vof API response
                        console.log('VOF Debug: (on actual handle...) Validated UUID is:', responseUUID);
                        window.handleTempListingSuccess(response);
                        // setTimeout(function() {
                        //     window.location.href = checkoutUrl;
                        // }, 100000);
                    } else {
                        console.error('VOF Debug: Error: No user UUID found in response please retry');
                        // TODO: REMOVE THIS
                        // const redirectUrl = response.redirect_url || response.data?.redirect_url;
                        // if (redirectUrl) {
                        //     window.location.href = redirectUrl;
                        // }
                    }
                } else {
                    if (msg) {
                        msgHolder.classList.remove('alert-success');
                        msgHolder.classList.add('alert-danger');
                        msgHolder.innerHTML = msg;
                        form.appendChild(msgHolder);
                    }
                }
            } else {
                console.error('VOF: Submission error on xhr.status level:', xhr.statusText);

                // Log error
                // vof_logs.responses.error = {
                //     timestamp: new Date().toISOString(),
                //     error: xhr.responseText
                // };

                // Download logs on error
                // vof_download_logs(vof_logs);

                // Enable buttons and unblock form
                form.querySelectorAll('button[type=submit], button[type=button]').forEach(button => {
                    button.disabled = false;
                });
                form.classList.remove('rtcl-block');

                msgHolder.classList.remove('alert-success');
                msgHolder.classList.add('alert-danger');
                msgHolder.innerHTML = xhr.responseText;
                form.appendChild(msgHolder);
            }
        };

        xhr.onerror = function() {
            console.error('VOF: Submission error:', xhr.statusText);

            // Log error
            // vof_logs.responses.error = {
            //     timestamp: new Date().toISOString(),
            //     error: xhr.responseText
            // };

            // Download logs on error
            // vof_download_logs(vof_logs);

            // Enable buttons and unblock form
            form.querySelectorAll('button[type=submit], button[type=button]').forEach(button => {
                button.disabled = false;
            });
            form.classList.remove('rtcl-block');

            msgHolder.classList.remove('alert-success');
            msgHolder.classList.add('alert-danger');
            msgHolder.innerHTML = xhr.responseText;
            form.appendChild(msgHolder);
        };

        // Disable buttons and block form
        form.querySelectorAll('button[type=submit], button[type=button]').forEach(button => {
            button.disabled = true;
        });
        form.classList.add('rtcl-block');

        xhr.send(formData);
    };
})();