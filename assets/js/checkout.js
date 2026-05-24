jQuery(document).ready(function ($) {
    $('.wc-isbank-checkout').submit(function (e) {
        e.preventDefault();
        e.stopPropagation();

        let $form = $(this);

        if ($form.is('.processing')) {
            return false;
        }

        $form.addClass('processing');

        $form.block({
            message: null,
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });

        let data = $form.serializeArray();
        data.push({name: 'action', value: 'validate_isbank_form'});
        data.push({name: 'card_expiry', value: $form.find('#isbank-card-expiry').val()});
        data.push({name: 'card_cvc', value: $form.find('input[name="cv2"]').val()});

        $.ajax({
            type: 'POST',
            url: WCIsbank.ajaxUrl,
            data: data,
            dataType: 'json',
            success: function (result) {
                if ('success' === result.result) {

                    $form.find('input[name="Ecom_Payment_Card_ExpDate_Month"], input[name="Ecom_Payment_Card_ExpDate_Year"]').remove();
                    $form.find('#wc-isbank-cc-form').append('<input type="hidden" value="' + result.month + '" name="Ecom_Payment_Card_ExpDate_Month">');
                    $form.find('#wc-isbank-cc-form').append('<input type="hidden" value="' + result.year + '" name="Ecom_Payment_Card_ExpDate_Year">');
                    $form.find('input[name="pan"]').val($form.find('input[name="pan"]').val().replace(/\D/g, ''));
                    $form.find('input[name="cv2"]').val($form.find('input[name="cv2"]').val().replace(/\D/g, ''));
                    $form.find('input[name="hash"]').val(result.hash);

                    e.currentTarget.submit();
                } else if ('failure' === result.result) {
                    submit_error(result.msg);
                }
            },
            error: function () {
                submit_error(WCIsbank.genericError);
            }
        });
    });

    function submit_error(error_message) {
        let $form = $('.wc-isbank-checkout');
        let safe_error_message = $('<div/>').text(error_message).html();
        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        $form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><ul class="woocommerce-error"><li>' + safe_error_message + '</li></ul></div>');
        $form.removeClass('processing').unblock();
        $form.find('.input-text, select, input:checkbox').trigger('validate').blur();
        $('html, body').animate({
            scrollTop: ($('form.wc-isbank-checkout').offset().top - 100)
        }, 1000);
        $(document.body).trigger('checkout_error');
    }
});
