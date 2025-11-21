jQuery(document).ready(function ($) {
    const termsCheckbox = $('#tailoredpay-terms-agreement');
    const initialLoader = $("#tailoredpay-initial-loader");
    let fieldsReady = false;
    let fieldValidation = {
        ccnumber: false,
        ccexp: false,
        cvv: false
    };

    $('#payButton').prop('disabled', true);
    $('#tailoredpay-terms-agreement').prop('disabled', true).prop('checked', false);

    function hideInitialLoader() {
        initialLoader.fadeOut(200, function () {
            $(this).remove();
        });
    }

    function showError(message) {
        $('#tailoredpay-js-errors')
            .html('<strong>Error:</strong> ' + message)
            .fadeIn(300);
    }

    function hideError() {
        $('#tailoredpay-js-errors').fadeOut(200, function () {
            $(this).html('');
        });
    }

    function initializeTailoredPay() {
        if (typeof CollectJS === 'undefined' || typeof tailoredpay_vars === 'undefined') {
            console.error('TailoredPay Error: Required variables or CollectJS object not found.');
            showError('A critical error occurred. Please refresh the page.');
            hideInitialLoader();
            return;
        }

        console.log('✅ tailoredpay_vars and CollectJS are loaded.');

        CollectJS.configure({
            'variant': 'inline',
            'invalidCss': {
                'color': '#c33',
                'border': '1px solid #fcc'
            },
            'validCss': {
                'color': '#28a745',
                'border': '1px solid #28a745'
            },
            'focusCss': {
                'border': '1px solid #4CAF50'
            },
            'fieldsAvailableCallback': function () {
                console.log('✅ CollectJS fields are available.');
                hideInitialLoader();
                fieldsReady = true;
                $('#tailoredpay-terms-agreement').prop('disabled', false);
                $('#payButton').prop('disabled', false); // Enable button by default
            },
            'validationCallback': function (field, status, message) {
                fieldValidation[field] = status;
                console.log('Field validation:', field, status);
            },
            'callback': function (response) {
                console.log('💳 Token received:', response.token);

                // Re-validate checkbox before processing payment
                if (!termsCheckbox.is(':checked')) {
                    showError('Please accept the terms and conditions to continue.');
                    $('#payButton').prop('disabled', false).text('Complete Your Order');
                    return;
                }

                hideError();

                $.ajax({
                    url: tailoredpay_vars.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'tailoredpay_process_payment',
                        nonce: tailoredpay_vars.nonce,
                        entry_id: tailoredpay_vars.entryId,
                        payment_token: response.token
                    },
                    success: function (ajax_response) {
                        if (ajax_response.success) {
                            console.log('✅ Payment successful. Redirecting...');
                            if (ajax_response.data.redirect_url) {
                                window.location.href = ajax_response.data.redirect_url;
                            } else {
                                showError('Payment successful! Your order is complete.');
                            }
                        } else {
                            var errorMsg = ajax_response.data || 'Payment failed. Please try again.';
                            showError(errorMsg);
                            $('#payButton').prop('disabled', false).text('Complete Your Order');
                        }
                    },
                    error: function (jqXHR) {
                        var errorMessage = 'Payment processing failed. Please try again.';
                        if (jqXHR.responseJSON && jqXHR.responseJSON.data) {
                            errorMessage = jqXHR.responseJSON.data;
                        }
                        showError(errorMessage);
                        $('#payButton').prop('disabled', false).text('Complete Your Order');
                    }
                });
            },
            'timeoutCallback': function () {
                showError('Payment request timed out. Please try again.');
                $('#payButton').prop('disabled', false).text('Complete Your Order');
            }
        });

        $('#payButton').on('click', function (e) {
            e.preventDefault();
            hideError();

            if (!fieldsReady) {
                showError('Payment system is still loading. Please wait.');
                return;
            }

            if (!termsCheckbox.is(':checked')) {
                showError('Please accept the terms and conditions to continue.');
                // Highlight the checkbox
                termsCheckbox.closest('.tailoredpay-terms-checkbox-container').css('border', '2px solid #c33');
                setTimeout(function () {
                    termsCheckbox.closest('.tailoredpay-terms-checkbox-container').css('border', '');
                }, 2000);
                return;
            }

            if (!fieldValidation.ccnumber) {
                showError('Please enter a valid card number.');
                return;
            }

            if (!fieldValidation.ccexp) {
                showError('Please enter a valid expiry date (MM/YY).');
                return;
            }

            if (!fieldValidation.cvv) {
                showError('Please enter a valid CVV code.');
                return;
            }

            console.log('🚀 Starting payment...');

            // Disable button and checkbox immediately to prevent changes
            $(this).prop('disabled', true).text('Processing Payment...');
            termsCheckbox.prop('disabled', true);

            CollectJS.startPaymentRequest();
        });

        // Add this after the $('#payButton').on('click'...) handler

        termsCheckbox.on('change', function () {
            if ($(this).is(':checked') && fieldsReady) {
                $('#payButton').prop('disabled', false);
            } else {
                $('#payButton').prop('disabled', true);
            }
        });

        console.log('✅ TailoredPay initialization complete!');
    }

    // Reduced polling interval and timeout
    var checkAttempts = 0;
    var maxAttempts = 50; // 5 seconds max wait

    var checkCollectJS = setInterval(function () {
        checkAttempts++;

        if (typeof CollectJS !== 'undefined') {
            clearInterval(checkCollectJS);
            initializeTailoredPay();
        } else if (checkAttempts >= maxAttempts) {
            clearInterval(checkCollectJS);
            hideInitialLoader();
            showError('Payment system failed to load. Please refresh the page.');
            console.error('CollectJS failed to load within timeout period');
        }
    }, 100);
});