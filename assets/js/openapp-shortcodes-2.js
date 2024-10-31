/**
 * @typedef {Object} OpenAppVars2
 * @property {string} baseUrl - WP root url.
 * @property {string} cartId - The ID of the cart.
 * @property {string} errorTextMessage - The error message to display.
 * @property {boolean} cartIsEmpty - Flag indicating if the cart is empty.
 */

/** @type {OpenAppVars} */
var openappVars2 = openappVars2 || {};
/**
 * OA login shortcode
 */

function oaDisplayLoginQR(cartData) {
    const containers = document.querySelectorAll(".OpenAppCheckoutLogin");
    containers.forEach(container => {
        container.setAttribute("data-merchantId", cartData.merchant_id);
        container.setAttribute("data-integrationProfileId", cartData.profile_id);
        container.setAttribute("data-token", cartData.cart_id);

        container.classList.remove("isErrorOA");
        container.classList.remove("OpenAppCheckout-loading");
        container.classList.add("OpenAppCheckout");

        const OAEvent = new CustomEvent("OpenAppCheckout", {
            detail: {},
            bubbles: true,
            cancelable: true,
            composed: false,
        });

        container.dispatchEvent(OAEvent);
    });
}
function oaDisplayQaCodeError2(textMessage){
    const containers = document.querySelectorAll(".OpenAppCheckoutLogin");
    containers.forEach(container => {
        container.classList.add("isErrorOA");
        container.innerHTML = textMessage;
    });
}
(function ($) {
    $(document).ready(function($) {
        let cartId = openappVars2.cartId;
        const errorTextMessage = openappVars2.errorTextMessage;

        let fetchQrCode2 = function() {
            $.ajax({
                url: openappVars2.baseUrl + "/wp-json/openapp/v1/qr_code",
                method: "GET",
                data: {
                    cart_id: cartId
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader("X-WP-Internal", "true");
                },
                success: function(response) {
                    if(response.cart_id){
                        oaDisplayLoginQR(response);
                    } else {
                        oaDisplayQaCodeError2(errorTextMessage);
                    }
                },
                error: function(error) {
                    // console.log("Failed to update QR code: ", error);
                    oaDisplayQaCodeError2(errorTextMessage);
                }
            });
        };

        // Fetch the QR code on page load
        if(cartId){
            fetchQrCode2();
        }

        $(document).on("updated_cart_totals", fetchQrCode2WithDelay);
        $(document).on("updated_checkout", fetchQrCode2WithDelay);

        function fetchQrCode2WithDelay() {
            setTimeout(fetchQrCode2, 500);
        }

        if(cartId){
            setInterval(function () {
                $.ajax({
                    url: openappVars2.baseUrl + "/wp-json/openapp/v1/oa_login",
                    method: "GET",
                    data: {
                        cart_id: cartId,
                    },
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader("X-WP-Internal", "true");
                    },
                    success: function (response) {
                        if (response.should_login) {
                            setTimeout(function(){
                                window.location.href = response.redirect_url;
                            },1000)
                        }
                    }
                });
            }, 5000);
        }
    });
})(jQuery);
