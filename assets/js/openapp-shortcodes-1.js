/**
 * @typedef {Object} OpenAppVars
 * @property {string} baseUrl - WP root url.
 * @property {string} cartId - The ID of the cart.
 * @property {string} errorTextMessage - The error message to display.
 * @property {boolean} cartIsEmpty - Flag indicating if the cart is empty.
 * @property {string} intervalTime - The interval time for redirection checking.
 * @property {string} sseEnabled - should we use SSE for redirection check?

/** @type {OpenAppVars} */
var openappVars = openappVars || {};

function oaDisplayQrCode(cartData) {
    const containers = document.querySelectorAll(".OpenAppCheckoutOrder");

    containers.forEach(container => {
        container.setAttribute("data-merchantId", cartData.merchant_id);
        container.setAttribute("data-integrationProfileId", cartData.profile_id);
        container.setAttribute("data-basketId", cartData.cart_id);
        container.setAttribute("data-basketValue", cartData.total_value);
        container.setAttribute("data-basketCurrency", cartData.currency);
        container.setAttribute("data-uniqueProductsCount", cartData.unique_products_count);

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

function oaDisplayQaCodeError1(textMessage){
    const containers = document.querySelectorAll(".OpenAppCheckoutOrder");
    containers.forEach(container => {
        container.classList.remove("OpenAppCheckout");
        container.classList.add("OpenAppCheckout-loading");
        container.classList.add("isErrorOA");
        container.innerHTML = textMessage;
    });

}

(function ($) {

    var useSSE = openappVars.sseEnabled;

    $(document).ready(function($) {
        let cartId = openappVars.cartId;
        const errorTextMessage = openappVars.errorTextMessage;
        let redirectionInterval;

        let fetchQrCode = function() {
            $.ajax({
                url: openappVars.baseUrl + "/wp-json/openapp/v1/qr_code",
                method: "GET",
                data: {
                    "cart_id": cartId
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader("X-WP-Internal", "true");
                },
                success: function(response) {
                    if(String(response.total_value) === "0" || String(response.total_value) === "0.00") {
                        oaDisplayQaCodeError1(errorTextMessage);
                    } else {
                        oaDisplayQrCode(response);

                        if(useSSE){
                            check_oa_redirection_sse();
                        } else {
                            check_oa_redirection();
                        }

                    }
                },
                error: function(error) {
                    oaDisplayQaCodeError1(errorTextMessage);
                }
            });
        };


        fetchQrCode();


        $(document).on("updated_cart_totals", fetchQrCodeWithDelay);
        $(document).on("updated_checkout", fetchQrCodeWithDelay);

        function fetchQrCodeWithDelay() {
            setTimeout(fetchQrCode, 500);
        }

        var sseConnectionActive = false;

        function check_oa_redirection_sse(){
            if (sseConnectionActive) {
                console.log("SSE already running.");
                return;
            }
            if (typeof(EventSource) == "undefined") {
                console.log("Error: Server-Sent Events are not supported in your browser");
                return;
            }

            sseConnectionActive = true;
            var source  = new EventSource(openappVars.baseUrl + "/wp-json/openapp/v1/oa_redirection?cart_id=" + cartId + "&use_sse=1");

            source.addEventListener('orderUpdate', function(event) {
                var data = JSON.parse(event.data);
                if (data.redirect === true) {
                    window.location.href = data.url;
                    source.close(); // Close the connection when redirecting
                    sseConnectionActive = false;
                }
            }, false);

            source.addEventListener('error', function(event) {
                console.log('SSE error:', event);

                if (event.target.readyState === EventSource.CLOSED) {
                    console.log('SSE closed (' + event.target.readyState + ')');
                    sseConnectionActive = false;
                }
                else if (event.target.readyState === EventSource.CONNECTING) {
                    console.log('SSE reconnecting (' + event.target.readyState + ')');
                }
            }, false);

            // Ensure that if the browser tab is closed, the SSE connection is also closed.
            window.onbeforeunload = function() {
                source.close();
                sseConnectionActive = false;
            };
        }

        function check_oa_redirection(){
            clearInterval(redirectionInterval);

            var intervalTime = parseInt(openappVars.intervalTime, 10);
            if (isNaN(intervalTime) || intervalTime <= 0) {
                intervalTime = 8500;
            }

            var requestInProgress = false;

            redirectionInterval = setInterval(function () {
                if (requestInProgress) {
                    return;
                }
                requestInProgress = true;

               $.ajax({
                    url: openappVars.baseUrl + "/wp-json/openapp/v1/oa_redirection",
                    method: "GET",
                    data: {
                        cart_id: cartId,
                    },
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader("X-WP-Internal", "true");
                    },
                    success: function (response) {
                        if (response.redirect) {
                            window.location.href = response.url;
                        }
                    },
                    complete: function () {
                        requestInProgress = false;
                    },
                    error: function () {
                        requestInProgress = false;
                    }
                });
            }, intervalTime);
        }

    });
})(jQuery);
