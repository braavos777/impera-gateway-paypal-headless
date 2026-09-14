/**
 * PayPal buttons inside the proxy iframe.
 *
 * The page is loaded in an iframe on the merchant site: all data arrives via
 * postMessage ("mecom-paypalSendOrderInfo") and every state change is reported
 * back to the parent window the same way.
 */

// Filled in by listenerPaypal() once the parent sends the checkout data.
window.wooCheckoutFormInfo = false;

// The PayPal SDK checks document.referrer; force it to the proxy site.
Object.defineProperty(document, 'referrer', {
    get: function () {
        return window.mecomProxySite;
    }
});

var paypalButtonObject = {

    createOrder: async function (data, actions) {
        var payer = {};

        if (window.wooCheckoutFormInfo.email) {
            payer.email_address = window.wooCheckoutFormInfo.email;

            if (window.wooCheckoutFormInfo.first_name && window.wooCheckoutFormInfo.last_name) {
                payer.name = {
                    surname: window.wooCheckoutFormInfo.last_name,
                    given_name: window.wooCheckoutFormInfo.first_name
                };
            }
        }

        // Deep copy so the incoming data is not mutated.
        var purchaseUnits = JSON.parse(JSON.stringify(window.wooCheckoutFormInfo.purchase_units));

        var applicationContext = {
            brand_name: 'merchant',
            user_action: 'CONTINUE',
            return_url: window.mecomProxySite + '/checkout?pp_action=returned',
            cancel_url: window.mecomProxySite + '/checkout?pp_action=canceled'
        };

        // Shipping defaults to the billing address / billing name.
        var shippingAddress = window.wooCheckoutFormInfo.address;
        var shippingName = window.wooCheckoutFormInfo.first_name + ' ' + window.wooCheckoutFormInfo.last_name;

        if (window.wooCheckoutFormInfo.shipping_address) {
            shippingAddress = window.wooCheckoutFormInfo.shipping_address;
            shippingName = window.wooCheckoutFormInfo.shipping_address.name;
        }

        var hasCountry = window.wooCheckoutFormInfo.address.country
            && window.wooCheckoutFormInfo.address.country.length !== 0;
        var hasCity = window.wooCheckoutFormInfo.address.city
            && window.wooCheckoutFormInfo.address.city.length !== 0;

        if (hasCountry && hasCity && !window.wooCheckoutFormInfo.isNotSendAddress) {
            applicationContext.shipping_preference = 'SET_PROVIDED_ADDRESS';

            payer.address = {
                country_code: window.wooCheckoutFormInfo.address.country,
                address_line_1: window.wooCheckoutFormInfo.address.line1,
                address_line_2: window.wooCheckoutFormInfo.address.line2,
                admin_area_1: window.wooCheckoutFormInfo.address.state,
                admin_area_2: window.wooCheckoutFormInfo.address.city,
                postal_code: window.wooCheckoutFormInfo.address.postal_code
            };

            purchaseUnits[0].shipping = {
                name: {
                    full_name: shippingName
                },
                address: {
                    country_code: shippingAddress.country,
                    address_line_1: shippingAddress.line1,
                    address_line_2: shippingAddress.line2,
                    admin_area_1: shippingAddress.state,
                    admin_area_2: shippingAddress.city,
                    postal_code: shippingAddress.postal_code
                }
            };
        } else {
            applicationContext.shipping_preference = 'NO_SHIPPING';
        }

        if (window.wooCheckoutFormInfo.phone && window.wooCheckoutFormInfo.phone.length) {
            payer.phone = {
                phone_type: 'HOME',
                phone_number: {
                    national_number: window.wooCheckoutFormInfo.phone.replace(/[^0-9]+/g, '')
                }
            };
        }

        // Funding source (paypal / card / ...) is stored by onClick().
        purchaseUnits[0].custom_id = window.csPaypalFundingSource;

        window.orderData = {
            intent: window.wooCheckoutFormInfo.orderIntent,
            purchase_units: purchaseUnits,
            application_context: applicationContext
        };

        if (Object.keys(payer).length > 0) {
            window.orderData.payer = payer;
        }

        const response = await fetch(window.mecomProxySite + '/?rest_route=/cs/create-paypal-order', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                order: window.orderData,
                merchant_token: window.wooCheckoutFormInfo.merchant_token,
                store_invoice_prefix: window.wooCheckoutFormInfo.store_invoice_prefix || ''
            })
        });

        const result = await response.json();

        if (!result.order_id) {
            parent.postMessage('mecom-paypalCreateOrderFail', '*');
        }

        return result.order_id;
    },

    onApprove: function (data, actions) {
        parent.postMessage({
            name: 'mecom-paypalApprovedOrder',
            value: {
                order_id: data.orderID
            }
        }, '*');

        parent.postMessage('mecom-paypalCloseCreditForm', '*');
        parent.postMessage('mecom-paypalMakeIframeCreditFormNormal', '*');

        document.getElementById('paypal-button-container').classList.remove('hide_paypal_btn');
    },

    onInit: function (data, actions) {
    },

    onClick: function (data, actions) {
        window.csPaypalFundingSource = data.fundingSource;

        // Checkout data has not arrived from the parent window yet.
        if (!window.wooCheckoutFormInfo) {
            parent.postMessage('mecom-paypalOpenCreditFormReject', '*');
            return actions.reject();
        }

        if (!isValidMerchantSite(window.wooCheckoutFormInfo.whitelist_obj.merchant_site)) {
            parent.postMessage({
                name: 'mecom-paypalOpenCreditFormFail',
                value: 'We cannot process your payment right now, please try another payment method.'
            }, '*');
            return actions.reject();
        }

        var zipcodeAndEmailOk = isValidZipcode(
                window.wooCheckoutFormInfo.whitelist_obj.postal_code,
                window.wooCheckoutFormInfo.address.country
            )
            && isValidEmail(
                window.wooCheckoutFormInfo.whitelist_obj.email,
                window.wooCheckoutFormInfo.address.country
            );

        if (!zipcodeAndEmailOk) {
            parent.postMessage({
                name: 'mecom-paypalOpenCreditFormFail',
                value: 'Unable to process your payment. Please try another payment method.[1]'
            }, '*');
            return actions.reject();
        }

        var cityAndStateOk = isValidCityAndState(
            window.wooCheckoutFormInfo.whitelist_obj.state,
            window.wooCheckoutFormInfo.whitelist_obj.city,
            window.wooCheckoutFormInfo.address.country
        );

        if (!cityAndStateOk) {
            parent.postMessage({
                name: 'mecom-paypalOpenCreditFormFail',
                value: 'Sorry, Your selected products are not available to purchase due to our policy violation.'
            }, '*');
            return actions.reject();
        }

        parent.postMessage('mecom-paypalOpenCreditForm', '*');
    },

    onCancel: function (data) {
        parent.postMessage('mecom-paypalCloseCreditForm', '*');
    },

    onError: function (error) {
        parent.postMessage('mecom-paypalCloseCreditForm', '*');

        parent.postMessage({
            name: 'mecom-paypalOpenCreditFormFail',
            value: 'Unable to process your payment. Please try another payment method.[3]'
        }, '*');

        parent.postMessage({
            name: 'mecom-paypalOpenCreditFormError',
            value: window.orderData
        }, '*');
    }
};

/**
 * Receives the checkout data from the parent (merchant) window.
 */
function listenerPaypal(event) {
    if (typeof event.data === 'object' && event.data.name === 'mecom-paypalSendOrderInfo') {
        window.wooCheckoutFormInfo = event.data.value;
    }
}

/**
 * The merchant domain must be in the whitelist (when a whitelist is configured).
 */
function isValidMerchantSite(merchantSite) {
    return !window.mecomDomainWhiteList || window.mecomDomainWhiteList.includes(merchantSite);
}

/**
 * The global blacklist matches by substring (US only), the local one by exact value.
 */
function isValidZipcode(zipcode, country) {
    if (!zipcode || zipcode.length === 0) {
        return true;
    }

    zipcode = zipcode.trim();

    var isValid = true;

    if (window.mecomZipcodeGlobalBlacklist) {
        window.mecomZipcodeGlobalBlacklist.forEach(function (blacklisted) {
            if (zipcode.toString().includes(blacklisted.trim()) && country.toLowerCase() === 'us') {
                isValid = false;
            }
        });
    }

    if (window.mecomZipcodeLocalBlacklist) {
        window.mecomZipcodeLocalBlacklist.forEach(function (blacklisted) {
            if (zipcode === blacklisted.trim()) {
                isValid = false;
            }
        });
    }

    return isValid;
}

/**
 * NOTE: `country` is accepted only for signature parity with isValidZipcode(),
 * the email blacklists are not country specific.
 */
function isValidEmail(email, country) {
    if (!email || email.length === 0) {
        return true;
    }

    email = email.trim();

    var isValid = true;

    if (window.mecomEmailGlobalBlacklist) {
        window.mecomEmailGlobalBlacklist.forEach(function (blacklisted) {
            if (email === blacklisted.trim()) {
                isValid = false;
            }
        });
    }

    if (window.mecomEmailLocalBlacklist) {
        window.mecomEmailLocalBlacklist.forEach(function (blacklisted) {
            if (email === blacklisted.trim()) {
                isValid = false;
            }
        });
    }

    return isValid;
}

function isValidCityAndState(state, city, country) {
    return isValidGlobalCityAndState(state, city, country)
        && isValidLocalCityAndState(state, city, country);
}

/**
 * Blocked only when BOTH the state and the city are blacklisted;
 * a blacklisted state with no city list at all is blocked outright.
 */
function isValidGlobalCityAndState(state, city, country) {
    if (country != 'US') {
        return true;
    }

    state = state ? state.toString().toLowerCase() : '';
    city = city ? city.toString().toLowerCase() : '';

    if (window.mecomGlobalStatesBlacklist
        && window.mecomGlobalStatesBlacklist.includes(state)
        && state.toString().length > 0
    ) {
        if (!window.mecomGlobalCitiesStatesBlacklist) {
            return false;
        }

        if (window.mecomGlobalCitiesStatesBlacklist.includes(city) && city.toString().length > 0) {
            return false;
        }
    }

    return true;
}

/**
 * Same rule as isValidGlobalCityAndState(), but for the per-store lists.
 */
function isValidLocalCityAndState(state, city, country) {
    if (country != 'US') {
        return true;
    }

    state = state ? state.toString().toLowerCase() : '';
    city = city ? city.toString().toLowerCase() : '';

    if (window.mecomLocalStatesBlacklist
        && window.mecomLocalStatesBlacklist.includes(state)
        && state.toString().length > 0
    ) {
        if (!window.mecomLocalCitiesStatesBlacklist) {
            return false;
        }

        if (window.mecomLocalCitiesStatesBlacklist.includes(city) && city.toString().length > 0) {
            return false;
        }
    }

    return true;
}

// When the PayPal button itself is disabled, render the card button only.
if (window.csDisablePaypalButton) {
    paypalButtonObject.fundingSource = paypal.FUNDING.CARD;
}

paypal.Buttons(paypalButtonObject).render('#paypal-button-container');

if (window.addEventListener) {
    window.addEventListener('message', listenerPaypal);
} else {
    window.attachEvent('onmessage', listenerPaypal);
}

/**
 * Standard user-agent mobile sniff (detectmobilebrowsers.com).
 * Not referenced anywhere else in this file.
 */
var mobileCheck = function () {
    let check = false;
    var userAgent;

    userAgent = navigator.userAgent || navigator.vendor || window.opera;

    if (/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i.test(userAgent)
        || /1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(userAgent.substr(0, 4))
    ) {
        check = true;
    }

    return check;
};

// Report the button container height to the parent so the iframe can be resized.
setInterval(function () {
    var container = document.getElementById('paypal-button-container');

    if (window.oldHeightPaymentForm != container.offsetHeight) {
        window.oldHeightPaymentForm = container.offsetHeight;

        parent.postMessage({
            name: 'mecom-paypalBodyResizeCreditForm',
            value: container.offsetHeight
        }, '*');
    }
}, 50);

// While PayPal shows its own overlay, expand the iframe to full screen and hide the buttons.
setInterval(function () {
    var container = document.getElementById('paypal-button-container');

    if (document.querySelector('[id*="paypal-overlay-uid"]')) {
        parent.postMessage('mecom-paypalMakeFullIframeCreditForm', '*');
        container.classList.add('hide_paypal_btn');
    } else {
        parent.postMessage('mecom-paypalMakeIframeCreditFormNormal', '*');
        container.classList.remove('hide_paypal_btn');
    }
}, 50);

//202609101458
