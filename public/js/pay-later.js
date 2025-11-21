jQuery(document).ready(function($) {
    if (typeof tailoredpay_pay_later_vars === 'undefined') {
        return;
    }

    $('#tailoredpay-retrieve-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var email = $('#retrieve-email').val();
        var resultsDiv = $('#retrieve-results');
        var button = form.find('button');
        
        resultsDiv.html('<p>Searching...</p>').show();
        button.prop('disabled', true);
        
        $.ajax({
            url: tailoredpay_pay_later_vars.ajaxUrl,
            type: 'POST',
            data: {
                action: 'tailoredpay_retrieve_applications',
                email: email,
                nonce: tailoredpay_pay_later_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultsDiv.html(response.data);
                } else {
                    resultsDiv.html('<p style="color:red;">' + response.data + '</p>');
                }
            },
            error: function() {
                resultsDiv.html('<p style="color:red;">An error occurred. Please try again.</p>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
});