jQuery(document).ready(function($) {
    // This function will wait until the CollectJS object is available
    function initializeTailoredPay() {
        if (typeof CollectJS === 'undefined' || typeof tailoredpay_vars === 'undefined') {
            console.error('TailoredPay Error: Required variables or CollectJS object not found.');
            $('#payment-errors').html('<div class="error">A critical error occurred. Please contact support.</div>').show();
            return;
        }
        console.log('✅ tailoredpay_vars and CollectJS are loaded.');

        // FIX: The CollectJS object is already partially configured by the HTML.
        // We now just add our callback functions to it.
        CollectJS.configure({
            'fieldsAvailableCallback': function () {
                console.log('✅ CollectJS fields are available.');
                $('#payButton').prop('disabled', false); // Enable the pay button
            },
            'callback': function(response) {
                console.log('💳 Token received:', response.token);
                var token = response.token;

                $('#payment-processing').show();
                $('#payButton').prop('disabled', true);
                $('#payment-errors').hide();

                $.ajax({
                    url: tailoredpay_vars.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tailoredpay_process_payment',
                        nonce: tailoredpay_vars.nonce,
                        entry_id: tailoredpay_vars.entryId,
                        payment_token: token
                    },
                    success: function(ajax_response) {
                        if (ajax_response.success) {
                            console.log('✅ Payment successful. Redirecting...');
                            if (ajax_response.data.redirect_url) {
                                window.location.href = ajax_response.data.redirect_url;
                            } else {
                                $('#payment-processing').text('Payment successful! Your order is complete.');
                            }
                        } else {
                            console.error('❌ Server returned an error:', ajax_response.data);
                            $('#payment-errors').html('<div class="error">' + ajax_response.data + '</div>').show();
                            $('#payment-processing').hide();
                            $('#payButton').prop('disabled', false);
                        }
                    },
                    error: function(jqXHR) {
                        var errorMessage = 'An unknown AJAX error occurred. Please try again.';
                        if (jqXHR.responseJSON && jqXHR.responseJSON.data) {
                            errorMessage = jqXHR.responseJSON.data;
                        }
                        console.error('❌ AJAX request failed:', errorMessage);
                        $('#payment-errors').html('<div class="error">' + errorMessage + '</div>').show();
                        $('#payment-processing').hide();
                        $('#payButton').prop('disabled', false);
                    }
                });
            }
        });

        // The click handler remains the same
        $('#payButton').on('click', function(e) {
            e.preventDefault();
            console.log('🚀 Pay button clicked - starting tokenization process...');
            CollectJS.startPaymentRequest();
        });

        console.log('✅ TailoredPay JS initialization complete!');
    }

    // Poll to check if CollectJS is available, since it loads asynchronously.
    var checkCollectJS = setInterval(function() {
        if (typeof CollectJS !== 'undefined') {
            clearInterval(checkCollectJS);
            initializeTailoredPay();
        }
    }, 100); // Check every 100ms
});