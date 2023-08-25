import Plugin from 'src/plugin-system/plugin.class';
import PageLoadingIndicatorUtil from 'src/utility/loading-indicator/page-loading-indicator.util';
import CookieStorageHelper from 'src/helper/storage/cookie-storage.helper';
import HttpClient from 'src/service/http-client.service';
import ButtonLoadingIndicator from 'src/utility/loading-indicator/button-loading-indicator.util';
import FormSerializeUtil from 'src/utility/form/form-serialize.util';
import DomAccess from 'src/helper/dom-access.helper';

export default class NovalnetPayment extends Plugin {
    init() {
        this._createScript((function() {
            let paymentMethods = document.querySelectorAll('input[name="paymentMethodId"]'),
            selectedPaymentId = document.querySelector('input[name=paymentMethodId]:checked'),
            novalnetpayId = document.querySelector("#novalnetId"),
            submitButton = document.querySelector('#confirmOrderForm button[type="submit"]'),
            accountPayment = document.querySelectorAll('form[action$="/account/payment"]'),
            accountsubmitButton = document.querySelector('form[action$="/account/payment"]'),
            me = this,
            paymentForm = new NovalnetPaymentForm(),
            cookieName = "novalnetpayNameCookie",
            client = new HttpClient(),
            selectPayment = CookieStorageHelper.getItem(cookieName),
            form = novalnetpayId.closest('form'),
            nnCheckPayment = true,
            walletConfiguration = DomAccess.getDataAttribute(me.el, 'data-lineitems', false);
			
			if(selectedPaymentId == undefined){
				selectedPaymentId = document.querySelector("#novalnetId").checked = true;
			}
			
            if(novalnetpayId.value === selectedPaymentId.value) {
                nnCheckPayment = false;
            }

            let request = {
                iframe: "#novalnetPaymentIframe",
                initForm: {
                    orderInformation : {
                        lineItems: walletConfiguration
                    },
                    showButton: false,
                    uncheckPayments : nnCheckPayment,
                }
            };

            if (CookieStorageHelper.getItem(cookieName) != false && CookieStorageHelper.getItem(cookieName) != undefined && !nnCheckPayment && novalnetpayId.value === selectedPaymentId.value ) {
                request.initForm.checkPayment = selectPayment;
            }

            if (accountPayment.length == 1) {
                request.initForm.styleText = {forceStyling:{text: ".payment-type-container > .payment-type > .payment-form{display: none !important;}"}};
            }
            paymentForm.initiate(request);
            paymentForm.validationResponse((data) => {
                paymentForm.initiate(request);
            });

            paymentMethods.forEach((function(e) {
              !0 === e.checked && paymentForm.uncheckPayment()
            })),

            paymentForm.selectedPayment((function(selectPaymentData) {
                CookieStorageHelper.setItem(cookieName, selectPaymentData.payment_details.type);

                if (document.getElementById("confirmOrderForm") != undefined) {
                    if (selectPaymentData.payment_details.type == 'GOOGLEPAY' || selectPaymentData.payment_details.type == 'APPLEPAY') {
                        document.getElementById("confirmOrderForm").style.display = "none";
                    } else {
                        document.getElementById("confirmOrderForm").style.display = "block";
                    }
                }

                if (accountPayment.length == 0) {
                    if (novalnetpayId.value !== selectedPaymentId.value) {
                        PageLoadingIndicatorUtil.create();
                        document.querySelector('#paymentMethod' + novalnetpayId.value).checked = true;
                        const data = FormSerializeUtil.serialize(form);
                        const action = form.getAttribute('action');
                        client.post(action, data, response => {
                            PageLoadingIndicatorUtil.remove();
                            window.PluginManager.initializePlugins();
                            document.querySelector("#novalnetId").closest('form').submit();
                        });
                    }
                } else {
                    document.querySelector('#paymentMethod' + novalnetpayId.value).checked = true;
                }
            }));

            if (accountPayment.length == 1) {
                document.querySelectorAll('input[name="paymentMethodId"]').forEach((payment) => payment.addEventListener('click', (event) => {
                    if (payment.value != novalnetpayId.value) {
                        paymentForm.uncheckPayment();
                    }
                }));
            }

            // receive wallet payment Response like gpay and applepay
            paymentForm.walletResponse({
                onProcessCompletion: function (response) {
                    if (response.result.status == 'SUCCESS') {
                        document.querySelector('#novalnet-paymentdata').value = JSON.stringify(response);
                        setTimeout(function() {
                            document.querySelector('#confirmOrderForm').submit();
                        }, 500);

                        return {status: 'SUCCESS', statusText: 'successfull'};
                    } else {
                        return {status: 'FAILURE', statusText: 'failure'};
                    }
                }
            });

            if (accountPayment.length == 0) {
                submitButton.addEventListener('click', (event) => {
                    if (novalnetpayId.value === selectedPaymentId.value) {
                        let tosInput = document.querySelector('#tos');
                        let revocation = document.querySelector('#revocation');

                        if ((tosInput != undefined && !tosInput.checked) && (revocation != undefined && !revocation.checked)) {
                            return false;
                        }

                        if (document.getElementById("confirmFormSubmit") != undefined) {
                            document.getElementById("confirmFormSubmit").disabled = true;
                            const loader = new ButtonLoadingIndicator(document.getElementById("confirmFormSubmit"));
                            loader.create();
                        } else {
                            let submitButton = document.querySelector('#confirmOrderForm button[type="submit"]');
                            submitButton.disabled = true;
                            const loader = new ButtonLoadingIndicator(submitButton);
                            loader.create();
                        }

                        event.preventDefault();
                        event.stopImmediatePropagation();
                       paymentForm.getPayment((function(paymentDetails) {
                            if (paymentDetails.result.statusCode == '100' || paymentDetails.result.status == 'SUCCESS') {
                                document.querySelector('#novalnet-paymentdata').value = JSON.stringify(paymentDetails);
                                document.querySelector('#confirmOrderForm').submit();

                            } else {
                                 document.querySelector('#novalnet-paymentdata').value = '';
                                 me._displayErrorMsg(paymentDetails.result.message);
                                 me._showSubmitForm();
                            }
                        }));
                    }
                });
            }

            if (accountsubmitButton != null && accountsubmitButton != undefined) {
                accountsubmitButton.addEventListener('submit', (event) => {
                    if (document.querySelector('input[name="paymentMethodId"]:checked').value == novalnetpayId.value) {
                        const data = FormSerializeUtil.serialize(form);
                        const action = form.getAttribute('action');
                        client.post(action, data, response => {
                            PageLoadingIndicatorUtil.remove();
                            window.PluginManager.initializePlugins();
                        });
                    }
                });
            }
        }));
    }

    _createScript(callback) {
        const url = 'https://cdn.novalnet.de/js/pv13/checkout.js?' + new Date().getTime();
        const script = document.createElement('script');
        script.type = 'text/javascript';
        script.src = url;
        script.addEventListener('load', callback.bind(this), false); document.head.appendChild(script);
    }

    _displayErrorMsg(errorMessage) {
        document.querySelector('.flashbags').innerHTML = '';
        let parentDiv  = document.createElement('div');
        let childDiv1  = document.createElement('div');
        let childDiv2  = document.createElement('div');
        let spanTag    = document.createElement('span');
        parentDiv.className= "alert alert-danger alert-has-icon";childDiv1.className= "alert-content-container";childDiv2.className= "alert-content";spanTag.className= "icon icon-blocked";
        spanTag.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24" height="24" viewBox="0 0 24 24"><defs><path d="M12 24C5.3726 24 0 18.6274 0 12S5.3726 0 12 0s12 5.3726 12 12-5.3726 12-12 12zm0-2c5.5228 0 10-4.4772 10-10S17.5228 2 12 2 2 6.4772 2 12s4.4772 10 10 10zm4.2929-15.7071c.3905-.3905 1.0237-.3905 1.4142 0 .3905.3905.3905 1.0237 0 1.4142l-10 10c-.3905.3905-1.0237.3905-1.4142 0-.3905-.3905-.3905-1.0237 0-1.4142l10-10z" id="icons-default-blocked"></path></defs><use xlink:href="#icons-default-blocked" fill="#758CA3" fill-rule="evenodd"></use></svg>';
        parentDiv.appendChild(spanTag);parentDiv.appendChild(childDiv1);childDiv1.appendChild(childDiv2);
        childDiv2.innerHTML = errorMessage;
        document.querySelector('.flashbags').appendChild(parentDiv);
        document.querySelector('.flashbags').scrollIntoView();
    }

    _showSubmitForm() {
        if(document.getElementById("confirmFormSubmit") != undefined) {
            document.getElementById("confirmFormSubmit").disabled = false;
            const loader = new ButtonLoadingIndicator(document.getElementById("confirmFormSubmit"));
            loader.remove();
        } else {
            let submitButton = document.querySelector('#confirmOrderForm button[type="submit"]');
            submitButton.disabled = false;
            const loader = new ButtonLoadingIndicator(submitButton);
            loader.remove();
        }
    }
}
