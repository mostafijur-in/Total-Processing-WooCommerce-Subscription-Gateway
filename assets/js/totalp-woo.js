var wpwlOptions = {
    style: tp_data.style || 'card',
    onReady: function () {
        if (tp_data.registration) {
            var createRegistrationHtml = '<div class="wpwl-group wpwl-group-registration wpwl-clearfix"><div class="wpwl-label wpwl-label-registration" name="wpwl-label-registration">Store payment details?</div><div class="wpwl-wrapper wpwl-wrapper-createRegistration"><input class="wpwl-control wpwl-control-createRegistration" name="createRegistration" type="checkbox" value="true" /></div></div>';
            jQuery('form.wpwl-form-card').find('.wpwl-group-cvv').after(createRegistrationHtml);

            jQuery('button:contains(Show other payment methods)').html('Use a new card');
        }
        jQuery('.woocommerce-notice.woocommerce-notice--info.woocommerce-info').css({
            'margin-bottom': '1.4em'
        });
    },
    registrations: {
        requireCvv: true
    }
}

if(tp_data.custom_iframe_styling) {
    wpwlOptions.iframeStyles = JSON.parse(tp_data.custom_iframe_styling);
}

if (tp_data.google_pay) {
    wpwlOptions.googlePay = {
        gatewayMerchantId: tp_data.gp_entity_id
    }
}

jQuery(document).ready(function () {

    var formRow = jQuery('.woocommerce #order_review').first().parent();

    jQuery('#order_review #payment').remove();

    formRow.append('<form action="' + tp_data.redirect_url + '" class="paymentWidgets" data-brands="' + tp_data.scheme_checkout + '"></form>');
});

