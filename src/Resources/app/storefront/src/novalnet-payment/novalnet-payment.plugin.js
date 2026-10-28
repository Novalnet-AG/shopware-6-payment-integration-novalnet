import PageLoadingIndicatorUtil from 'src/utility/loading-indicator/page-loading-indicator.util';
import CookieStorageHelper from 'src/helper/storage/cookie-storage.helper';
import ButtonLoadingIndicator from 'src/utility/loading-indicator/button-loading-indicator.util';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';


const { PluginBaseClass, PluginManager } = window;

export default class NovalnetPayment extends PluginBaseClass {
    init() {
        this._createScript(function () {
            const walletConfiguration = JSON.parse(this.el.dataset.lineitems),
                paymentForm = new NovalnetPaymentForm(),
                novalnetPaymentId = document.querySelector('#novalnetId'),
                submitButton = document.querySelectorAll('#confirmOrderForm button[type="submit"]'),
                subscriptionSubmitButton = document.querySelector('#novalnetchangePaymentForm button[type="submit"]'),
                me = this,
                paymentMethods = document.querySelectorAll('input[name="paymentMethodId"]'),
                cookieName = 'novalnetPaymentCookie';

            paymentForm.addSkeleton('#novalnetPaymentIframe');

            if (novalnetPaymentId != null) {
                if (document.querySelector('input[name=paymentMethodId]:checked') === undefined ||
                    document.querySelector('input[name=paymentMethodId]:checked') === null
                ) {
                    document.querySelector('#paymentMethod' + novalnetPaymentId.value).checked = true;
                    me.sendFormPostData();
                }

                const selectedPaymentId = document.querySelector('input[name=paymentMethodId]:checked');

                let request = {
                    iframe: '#novalnetPaymentIframe',
                    initForm: {
                        orderInformation: {
                            lineItems: walletConfiguration,
                        },
                        showButton: false,
                        uncheckPayments: true,
                    },
                };

                if (novalnetPaymentId.value === selectedPaymentId.value) {
                    request.initForm['uncheckPayments'] = false;
                    me.disableSubmitButton(true);
                }

                if (CookieStorageHelper.getItem(cookieName) != false && CookieStorageHelper.getItem(cookieName) != undefined && novalnetPaymentId.value === selectedPaymentId.value) {
                    request.initForm['checkPayment'] = CookieStorageHelper.getItem(cookieName);
                }

                if (document.querySelectorAll('form[action$="/account/payment"]').length == 1) {
                    request.initForm['styleText'] = { forceStyling: { text: ".payment-type-container > .payment-type > .payment-form{display: none !important;}" } };
                }

                paymentForm.initiate(request);

                paymentForm.validationResponse((data) => {
                    paymentForm.initiate(request);

                    if (data.result.statusCode == '340' && data.result.nnpf_subType == 'NO_PAYMENT_METHODS') {
                        if (novalnetPaymentId.value === selectedPaymentId.value) {
                            me.displayErrorMsg(
                                document.getElementById('iframeErrorMessage')
                                    .value,
                                'info'
                            );
                            return;
                        }
                    }
                    me.disableSubmitButton(false);
                });

                paymentMethods.forEach(function (paymentMethod) {
                    if (paymentMethod.getAttribute('type') === 'hidden') {
                        return;
                    }

                    // Coding to uncheck payment while change payment method
                    paymentMethod.addEventListener('click', (event) => {
                        if (event.target.value !== novalnetPaymentId.value) {
                            paymentForm.uncheckPayment();
                        }
                    });

                    if (paymentMethod.checked === true && paymentMethod.value !== novalnetPaymentId.value) {
                        paymentForm.uncheckPayment();
                    }
                });

                // Get the selected payment from the Iframe
                paymentForm.selectedPayment(function (selectPaymentData) {
                    CookieStorageHelper.setItem(cookieName, selectPaymentData.payment_details.type);
                    const subscriptionRestrict = document.querySelector("#isSubscriptionRestrict");

                    if (selectPaymentData.payment_details.type == 'GOOGLEPAY' || selectPaymentData.payment_details.type == 'APPLEPAY') {
                        me.hideSubmitButton('none');
                    } else if (subscriptionRestrict === undefined || subscriptionRestrict === null || subscriptionRestrict.value === 0) {
                        me.hideSubmitButton('inline-block');
                    }

                    if (novalnetPaymentId.value !== selectedPaymentId.value) {
                        document.querySelector('#paymentMethod' + novalnetPaymentId.value).checked = true;

                        if (subscriptionSubmitButton === undefined || subscriptionSubmitButton === null) {
                            me.sendFormPostData(true);
                        }
                    }
                });

                // receive wallet payment Response like gpay and applepay
                paymentForm.walletResponse({
                    onProcessCompletion: function (response) {
                        if (response.result.status == 'SUCCESS') {
                            if (document.querySelector('#isSubscriptionRestrict') !== undefined && document.querySelector('#isSubscriptionRestrict') !== null) {
                                if (document.querySelector('#isSubscriptionRestrict').value == 1) {
                                    window.scrollTo(0, 0);
                                    return {
                                        status: 'FAILURE',
                                        statusText: 'failure',
                                    };
                                }
                            }

                            if (subscriptionSubmitButton !== undefined && subscriptionSubmitButton !== null) {
                                return me.subscriptionFetchData(response);
                            } else {
                                document.querySelector('#novalnet-paymentdata').value = JSON.stringify(response);
                                document.querySelector('#confirmOrderForm').submit();
                                return {
                                    status: 'SUCCESS',
                                    statusText: 'successfull',
                                };
                            }
                        }
                    },
                    onPaymentButtonClicked: function () {
                        if (document.querySelector('#isSubscriptionRestrict') !== undefined && document.querySelector('#isSubscriptionRestrict') !== null) {
                            if (document.querySelector('#isSubscriptionRestrict').value == 1) {
                                window.scrollTo(0, 0);
                                return 'FAILURE';
                            }
                        }
                        return 'SUCCESS';
                    },
                });

                if (submitButton !== undefined && submitButton !== null) {
                    submitButton.forEach(function (e) {
                        e.addEventListener('click', (event) => {
                            if (novalnetPaymentId.value === selectedPaymentId.value) {
                                if ((document.querySelector('#tos') !== null && document.querySelector('#tos').checked !== true) ||
                                    (document.querySelector('#revocation') !== null && document.querySelector('#revocation').checked !== true)
                                ) {
                                    return false;
                                }

                                const subscriptionRestrict = document.querySelector("#isSubscriptionRestrict");

                                if (subscriptionRestrict !== undefined && subscriptionRestrict !== null && subscriptionRestrict.value === 1) {
                                    window.scrollTo(0, 0);
                                    return false;
                                }

                                me.handleFormSubmit(event, paymentForm);
                            }
                        });
                    });
                }

                if (subscriptionSubmitButton != undefined && subscriptionSubmitButton != null) {
                    subscriptionSubmitButton.addEventListener(
                        'click',
                        (event) => {
                            const selectedPaymentId = document.querySelector('input[name=paymentMethodId]:checked');
                            if (novalnetPaymentId.value === selectedPaymentId.value) {
                                me.handleFormSubmit(event, paymentForm, true);
                            }
                        }
                    );
                }
            }
        });
    }

    handleFormSubmit(event, paymentForm, subscription = false) {
        const me = this;
        this.disableSubmitButton(true, true);
        event.preventDefault();
        event.stopImmediatePropagation();

        paymentForm.getPayment(function (paymentDetails) {
            if (
                paymentDetails.result.statusCode === '100' ||
                paymentDetails.result.status === 'SUCCESS'
            ) {
                if (subscription) {
                    me.subscriptionFetchData(paymentDetails);
                } else {
                    document.querySelector('#novalnet-paymentdata').value = JSON.stringify(paymentDetails);
                    document.querySelector('#confirmOrderForm').submit();
                }
            } else {
                document.querySelector('#novalnet-paymentdata').value = '';
                me.displayErrorMsg(
                    paymentDetails.result.message,
                    'danger',
                    subscription
                );
                me.disableSubmitButton(false, true);
            }
        });
    }

    async subscriptionFetchData(request) {
        const novalnetPaymentId = document.querySelector('#novalnetId');

        if (document.getElementById('parentOrderNumber') !== undefined) {
            request.parentOrderNumber = document.getElementById('parentOrderNumber').value;
            request.aboId = document.getElementById('aboId').value;
            request.paymentMethodId = novalnetPaymentId.value;
        }

        return await fetch(document.getElementById('storeCustomerDataUrl').value, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(request),
        }).then((response) => response.json())
            .then((res) => {
                {
                    if (res.success == true && res.redirect_url != undefined) {
                        window.location.replace(res.redirect_url);
                        return { status: 'SUCCESS', statusText: 'successfull' };
                    } else if (res.success == true) {
                        document.querySelector('#novalnetchangePaymentForm').submit();
                        return { status: 'SUCCESS', statusText: 'successfull' };
                    } else {
                        this.displayErrorMsg(res.message, 'danger', true);
                        this.disableSubmitButton(false, true);
                        return { status: 'FAILURE', statusText: 'failure' };
                    }
                }
            });
    }

    disableSubmitButton(value, loader = false) {
        const submitButton = document.querySelectorAll(
            '#confirmOrderForm button[type="submit"]'
        ),
            subscriptionSubmitButton = document.querySelector(
                '#novalnetchangePaymentForm button[type="submit"]'
            );

        if (submitButton.length > 0) {
            submitButton.forEach(function (e) {
                e.disabled = value;
                if (loader) {
                    const loader = new ButtonLoadingIndicator(e);
                    if (value) loader.create();
                    else loader.remove();
                }
            });
        } else if (
            subscriptionSubmitButton !== undefined &&
            subscriptionSubmitButton !== null
        ) {
            subscriptionSubmitButton.disabled = value;
            if (loader) {
                const loader = new ButtonLoadingIndicator(
                    subscriptionSubmitButton
                );
                if (value) loader.create();
                else loader.remove();
            }
        }
    }

    hideSubmitButton(value) {
        const submitButton = document.querySelectorAll(
            '#confirmOrderForm button[type="submit"]'
        );
        const subscriptionSubmitButton = document.querySelector(
            '#novalnetchangePaymentForm button[type="submit"]'
        );

        if (submitButton.length > 0) {
            submitButton.forEach(function (e) {
                e.style.display = value;
            });
        } else if (
            subscriptionSubmitButton !== undefined &&
            subscriptionSubmitButton !== null
        ) {
            subscriptionSubmitButton.style.display = value;
        }
    }

    sendFormPostData(submitForm = false) {
        const selectedPaymentId = document.querySelector(
            'input[name=paymentMethodId]:checked'
        );
        const data = FormSerializeUtil.serialize(
            selectedPaymentId.closest('form')
        );
        const action = selectedPaymentId.closest('form').getAttribute('action');

        if (submitForm) PageLoadingIndicatorUtil.create();

        fetch(action, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: data,
        })
            .then((response) => response.text())
            .then((response) => {
                PluginManager.initializePlugins();
                if (submitForm) {
                    PageLoadingIndicatorUtil.remove();
                    document
                        .querySelector('#novalnetId')
                        .closest('form')
                        .submit();
                }
            });
    }

    _createScript(callback) {
        const url =
            'https://cdn.novalnet.de/js/pv13/checkout.js?' +
            new Date().getTime();
        const script = document.createElement('script');
        script.type = 'text/javascript';
        script.src = url;
        script.addEventListener('load', callback.bind(this), false);
        document.head.appendChild(script);
    }

    displayErrorMsg(errorMessage, messageType, subscriptionPage = false) {
        let parentDiv = document.createElement('div');
        let childDiv1 = document.createElement('div');
        let childDiv2 = document.createElement('div');
        let spanTag = document.createElement('span');

        if (messageType == 'danger') {
            parentDiv.className =
                'alert alert-danger d-flex align-items-center';
        } else {
            parentDiv.className = 'alert alert-info d-flex align-items-center';
        }

        childDiv1.className = 'alert-content-container';
        childDiv2.className = 'alert-content';
        spanTag.className = 'icon icon-blocked';
        spanTag.innerHTML =
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24" height="24" viewBox="0 0 24 24"><defs><path d="M12 24C5.3726 24 0 18.6274 0 12S5.3726 0 12 0s12 5.3726 12 12-5.3726 12-12 12zm0-2c5.5228 0 10-4.4772 10-10S17.5228 2 12 2 2 6.4772 2 12s4.4772 10 10 10zm4.2929-15.7071c.3905-.3905 1.0237-.3905 1.4142 0 .3905.3905.3905 1.0237 0 1.4142l-10 10c-.3905.3905-1.0237.3905-1.4142 0-.3905-.3905-.3905-1.0237 0-1.4142l10-10z" id="icons-default-blocked"></path></defs><use xlink:href="#icons-default-blocked" fill="#758CA3" fill-rule="evenodd"></use></svg>';
        parentDiv.appendChild(spanTag);
        parentDiv.appendChild(childDiv1);
        childDiv1.appendChild(childDiv2);
        childDiv2.innerHTML = errorMessage;

        if (subscriptionPage) {
            const elements = document.getElementsByClassName(
                'alert alert-danger d-flex align-items-center'
            );
            const formElement = document.getElementById(
                'novalnetchangePaymentForm'
            );

            while (elements.length > 0) {
                elements[0].parentNode.removeChild(elements[0]);
            }

            formElement.parentNode.insertBefore(parentDiv, formElement);
            parentDiv.scrollIntoView();
        } else {
            document.querySelector('.flashbags').innerHTML = '';
            document.querySelector('.flashbags').appendChild(parentDiv);
            document.querySelector('.flashbags').scrollIntoView();
        }
    }
}
