(function($) {
    'use strict';

    window.handleTempListing = function(e) {
        if (e) {
            e.preventDefault();
        }

        // Get the phone field
        // const phoneField = document.getElementById('rtcl-phone');
        // const phonePattern = phoneField.getAttribute('data-validation-pattern');
        // const phoneMessage = phoneField.getAttribute('data-validation-message');

        // // Validate phone
        // if (phoneField && phoneField.value) {
        //     const phoneRegex = new RegExp(phonePattern);
        //     if (!phoneRegex.test(phoneField.value)) {
        //         alert(phoneMessage);
        //         phoneField.focus();
        //         return false;
        //     }
        // }


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
            console.log('TinyMCE not available', e);
        }

        // Handle reCAPTCHA if enabled
        if (typeof rtcl !== 'undefined' && rtcl.recaptcha && rtcl.recaptcha.on) {
            if (rtcl.recaptcha.v === 3) {
                grecaptcha.ready(function() {
                    $(form).rtclBlock();
                    grecaptcha.execute(rtcl.recaptcha.site_key, {
                        action: 'listing'
                    }).then(function(token) {
                        submitToOriginalEndpoint(form, token);
                    });
                });
                return false;
            }
        }

        submitToOriginalEndpoint(form);
    };

    function submitToOriginalEndpoint(form, reCaptcha_token = null) {
        const formData = new FormData(form);
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
                        setTimeout(function() {
                            window.location.href = response.redirect_url;
                        }, 500);
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
                
                msgHolder.removeClass('alert-success')
                        .addClass('alert-danger')
                        .html(e.responseText)
                        .appendTo(form);
            }
        });
    }
})(jQuery);