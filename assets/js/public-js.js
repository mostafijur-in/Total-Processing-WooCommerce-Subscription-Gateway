var wpwlOptions = {
    iframeStyles: {
        'card-number-placeholder': {
            'text-align': 'left'
        },
        'cvv-placeholder': {
            'text-align': 'left'
        }
    }
};

(function($){
    $('body').on('init_checkout', function(e){
        console.log(e);
        console.log('init_checkout triggered');
        // now.do.whatever();
    });

    $('body').on('update_checkout', function(e){
        console.log(e);
        console.log('update_checkout triggered');
        // now.do.whatever();
    });

    $('body').on('updated_checkout', function(e){
        console.log(e);
        console.log('updated_checkout triggered');
        // now.do.whatever();
    });

    $('body').on('checkout_error', function(e){
        console.log(e);
        console.log('checkout_error triggered');
        // now.do.whatever();
    });

    
    function pciFormSubmit(iFrameId, paymentContainer){
        var iFrame = document.getElementById(iFrameId);
        var iFrameDoc = (iFrame.contentWindow || iFrame.contentDocument);
        if (iFrameDoc.document) iFrameDoc = iFrameDoc.document;
        var obj = {funcs:[
            {name:"executePayment",args:[paymentContainer]}
        ]};
        var event;
        if(typeof window.CustomEvent === "function") {
            event = new CustomEvent('frameLog', {detail:obj});
        } else {
            event = document.createEvent('Event');
            event.initEvent('frameLog', true, true);
            event.detail = obj;
        }
        iFrameDoc.dispatchEvent(event);
    }


    function fetchOrderTpCards(endpoint,checkoutData){
        fetch(endpoint , {
            method:'POST', 
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            cache: 'no-cache',
            body: checkoutData
        }).then(function(response){
            return response.json();
        }).then(function(json){
            if(json.hasOwnProperty('result')){
                if(json.result === 'success'){
                    if(json.redirect !== false){
                        if(-1 === (json.redirect).indexOf('https://') || -1 === (json.redirect).indexOf('http://') ) {
                            window.location = decodeURI(json.redirect);
                        } else {
                            window.location = (json.redirect);
                        }
                    }
                }
            }
            return json;
        }).then(function(json){
            jQuery('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
            return json;
        }).then(function(json){
            if(json.hasOwnProperty('messages')){
                jQuery('form.checkout').prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + json.messages + '</div>');
            }
            return json;
        }).then(function(json){
            document.querySelector('form.woocommerce-checkout').classList.remove('processing');
            if(json.reload !== false){
                window.location.reload();
            } else if(json.refresh !== false){
                jQuery('body').trigger("update_checkout");
                jQuery('#tpCardsBtnReplace').remove();
                jQuery('#place_order').show();
                againShowPlaceOrderButton();
            }
            return json;
        }).then(function(json){
            if(json.result === 'failure'){
                var err = jQuery(".woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout");
                if(err.length > 0 || (err = jQuery(".form.checkout"))){
                    jQuery.scroll_to_notices(err);
                }
                jQuery('form.checkout').find(".input-text, select, input:checkbox").trigger("validate").blur();
                jQuery('body').trigger("checkout_error");
                jQuery('#tpCardsBtnReplace').remove();
                jQuery('#place_order').show();
                againShowPlaceOrderButton();
            } else if(json.hasOwnProperty('pending')){
                return json;
            }
            return false;
        }).then(function(json){
            if(json === false) return;

            console.log(json);
            console.log(checkoutData);

            // Unload widget first
            unloadWidget();

            // After unloading the widget, the form markup is gone
            // So we need to again place the form
            if ( 'form_markup' in window && !$('.card-wrap .paymentWidgets').length ) {
                $('.card-wrap').append(window.form_markup);
            }

            if ( 'checkoutScirptUrl' in json ) {
                var checkoutScirptUrl = json.checkoutScirptUrl;
                $('body').append('<script src="'+checkoutScirptUrl+'"></script>');
            }

            Swal.fire({
                html: '<div class="card-holder"></div>',
                denyButtonText: 'cancel',
                showConfirmButton: false,
                showDenyButton: false,
                didOpen: function(){
                    $('.card-holder').append($('.card-wrap'));
                    $('.card-wrap').show();
                },
                willClose: function() {
                    $('.card-wrap').insertAfter('body');
                    $('.card-wrap').hide();

                    againShowPlaceOrderButton();
                }
            });
            
        });
    }

    $(document).ready(function(){
        // Save the form renderer widget markup
        if ( $('.card-wrap .paymentWidgets').length ) {
            window.form_markup = $('.card-wrap .paymentWidgets')[0].outerHTML;
        }

        // auto populate extra fields for 3dsecure v2
        $('input#acceptheader').val('text/html');

        var language = window.navigator.userLanguage || window.navigator.language;
        $('input#browserlanguage').val(language);

        $('input#screenheight').val(screen.height);
        $('input#screenwidth').val(screen.width);

        let currentLocalDate = new Date();
        var tzoffset = currentLocalDate.getTimezoneOffset();
        $('input#browsertimezone').val(tzoffset);

        $('input#useragent').val(navigator.userAgent);

        $('input#javaenabled').val(navigator.javaEnabled());

        $('input#colordepth').val(screen.colorDepth);
    });

    // On click place order button, hide it and show processing
    $(document).on('click', '#place_order', function(){
        $(this).hide();
        $('<div class="processing-notice">Processing...</div>').insertAfter('#place_order');
    });


    function againShowPlaceOrderButton() {
        $('#place_order').show();
        $('.processing-notice').remove();
    }

    var tpCardsHandoff = function() {
        if(jQuery('form.woocommerce-checkout').find('input[name^="payment_method"]:checked').val() !== tpCardVars.pluginId){
            return;
        }
        var checkoutData = jQuery("form.woocommerce-checkout").serialize();
        document.querySelector('form.woocommerce-checkout').classList.add('processing');
        fetchOrderTpCards(wc_checkout_params.checkout_url,checkoutData);
        return false;
    };

    var unloadWidget = function() {
        if (window.wpwl !== undefined && window.wpwl.unload !== undefined) {
            window.wpwl.unload();
            $('script').each(function () {
                if (this.src.indexOf('paymentWidgets.js') !== -1) {
                    $(this).remove();
                }
            });
        }
    };



    var checkout_form = $( 'form.woocommerce-checkout' );
    checkout_form.on( 'checkout_place_order', tpCardsHandoff );

})(jQuery);
