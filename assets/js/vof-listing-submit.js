document.addEventListener('DOMContentLoaded', function() {
    const button = document.querySelector('.vof-guest-submit-btn') || document.querySelector('.vof-subscription-submit-btn');
    if (button) {
        button.addEventListener('click', handleTempListing);
    }
});

function handleTempListing(event) {
    event.preventDefault();
    const button = event.target;
    const form = button.closest('form');

    if (!form) {
        console.error('Form not found');
        return;
    }

    const formData = new FormData(form);
    formData.append('action', 'vof_save_temp_listing');
    formData.append('security', vofSettings.security);

    button.innerHTML = 'Processing...';
    button.disabled = true;

    // First try REST API
    fetch(`${vofSettings.root}vof/v1/save-temp-listing`, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {
            'X-WP-Nonce': vofSettings.nonce
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = vofSettings.redirectUrl;
        } else {
            throw new Error(data.message || 'Submission failed');
        }
    })
    .catch(error => {
        // Fallback to admin-ajax if REST fails
        jQuery.ajax({
            url: vofSettings.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    window.location.href = vofSettings.redirectUrl;
                } else {
                    alert(response.data.message || 'Submission failed');
                }
            },
            error: function() {
                alert('There was an error saving your listing. Please try again.');
            }
        });
    })
    .finally(() => {
        button.innerHTML = vofSettings.buttonText;
        button.disabled = false;
    });
}