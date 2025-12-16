import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import ButtonLoadingIndicator from 'src/utility/loading-indicator/button-loading-indicator.util';

export default class NovalnetPayment extends Plugin {

    init() {
        this.client = new HttpClient();
        const paymentName = document.querySelectorAll('.novalnet-payment-name');
        const SepaPaymentTypes = ['novalnetsepa', 'novalnetsepaguarantee', 'novalnetsepainstalment'];
        const InvoicePaymentTypes = ['novalnetinvoiceguarantee', 'novalnetinvoiceinstalment'];

        this._createScript(() => {
            paymentName.forEach((element) => {
                const selectedPaymentId = document.querySelector('input[name=paymentMethodId]:checked'),
                    paymentMethodId = document.querySelector('#' + element.value + 'Id'),
                    radioInputChecked = document.querySelector('input[type="radio"].' + element.value + '-SavedPaymentMethods-tokenInput:checked'),
                    radioInputs = document.querySelectorAll('input[type="radio"].' + element.value + '-SavedPaymentMethods-tokenInput'),
                    paymentRadioButton = document.querySelectorAll('input[name="paymentMethodId"]'),
                    submitButton = document.querySelector('#confirmOrderForm button[type="submit"]'),
                    subsChangePaymentFormButton = document.querySelector('#novalnetchangePaymentForm button[type="submit"]');

                if (selectedPaymentId !== undefined && selectedPaymentId !== null && paymentMethodId !== undefined && selectedPaymentId.value === paymentMethodId.value) {
                    if (document.getElementById(element.value + "-payment") != undefined && document.getElementById(element.value + "-payment") != null) {
                        document.getElementById(element.value + "-payment").style.display = "block";
                    }

                    if (document.getElementById(element.value + "ZeroAmountNotify") != undefined && document.getElementById(element.value + "ZeroAmountNotify") != null) {
                        document.getElementById(element.value + "ZeroAmountNotify").style.display = "block";
                    }
                }

                if (radioInputChecked !== undefined && radioInputChecked !== null) {
                    this.showComponents(radioInputChecked, element.value);
                }

                if (element.value == 'novalnetcreditcard') {
                    const config = JSON.parse(document.getElementById(element.value + '-payment').getAttribute('data-' + element.value + '-payment-config'));
                    this.loadIframe(config, element.value);
                    if (document.getElementById('novalnetcreditcard-payment-new') !== undefined && document.getElementById('novalnetcreditcard-payment-new') !== null) {
                        document.getElementById('novalnetcreditcard-payment-new').addEventListener('click', () => {
                            NovalnetUtility.setCreditCardFormHeight();
                        });
                    } else if (selectedPaymentId !== undefined && selectedPaymentId !== null && paymentMethodId !== undefined && selectedPaymentId.value === paymentMethodId.value) {
                        NovalnetUtility.setCreditCardFormHeight();
                    }
                } else if (SepaPaymentTypes.includes(element.value) || InvoicePaymentTypes.includes(element.value)) {
                    const config = JSON.parse(document.getElementById(element.value + '-payment').getAttribute('data-' + element.value + '-payment-config')),
                        accountHolder = document.getElementById(element.value + 'AccountHolder'),
                        iban = document.getElementById(element.value + 'AccountData'),
                        bic = document.getElementById(element.value + 'AccountBic'),
                        mandateInfo = document.getElementById(element.value + 'Mandate');

                    if (element.value != 'novalnetsepa' && document.getElementById(element.value + 'DobField') !== null && (config.company === null || !NovalnetUtility.isValidCompanyName(config.company))) {
                        document.getElementById(element.value + 'DobField').style.display = "block";
                    }

                    if (iban !== undefined && iban !== null) {
                        iban.onpaste = e => e.preventDefault();
                        ['click', 'keydown', 'keyup'].forEach((evt) => {
                            iban.addEventListener(evt, (event) => {
                                var result = event.target.value;
                                if (result != undefined && result != null) {
                                    result = result.toUpperCase();
                                    if (result.match(/(?:CH|MC|SM|GB|GI)/)) {
                                        document.querySelector('.nn-bic-field').classList.remove("nnhide");
                                    } else {
                                        document.querySelector('.nn-bic-field').classList.add("nnhide");
                                    }
                                }
                            });
                        });
                    }

                    if (accountHolder !== undefined && accountHolder !== null) {
                        accountHolder.onpaste = e => e.preventDefault();
                        this.handleInputKeyPress(document.getElementById(element.value + 'AccountHolder'));
                    }

                    if (mandateInfo !== undefined && mandateInfo !== null) {
                        mandateInfo.addEventListener('click', () => {
                            if (document.getElementById(element.value + 'AboutMandate') != undefined && document.getElementById(element.value + 'AboutMandate').classList.contains("nnhide") == true) {
                                document.getElementById(element.value + 'AboutMandate').classList.remove("nnhide");
                            } else {
                                document.getElementById(element.value + 'AboutMandate').classList.add("nnhide");
                            }
                        });
                    }

                    if (bic !== undefined && bic !== null) {
                        bic.onpaste = e => e.preventDefault();
                    }

                } else if (element.value == 'novalnetmbway') {
                    if (typeof window.intlTelInput === 'function') {
                        const mbwayMobileNo = document.getElementById('novalnetmbwayMobileNo');
                        const mbwayMobileDialcode = document.getElementById('novalnetmbwayMobileDialcode');

                        const iti = window.intlTelInput(mbwayMobileNo, {
                            preferredCountries: ["pt"],
                            separateDialCode: true,
                            autoPlaceholder: "off",
                            placeholderNumberType: 'MOBILE',
                            showFlags: true,
                            autoInsertDialCode: true,
                            formatOnDisplay: false,
                        });

                        mbwayMobileDialcode.value = iti.getSelectedCountryData().dialCode;

                        let countryCode = '';
                        mbwayMobileNo.addEventListener('countrychange', (e) => {
                            countryCode = iti.getSelectedCountryData().dialCode;
                            if (countryCode != undefined) {
                                mbwayMobileDialcode.value = countryCode;
                            }
                        });
                    }
                } else if (element.value == 'novalnetdirectdebitach') {
                    document.getElementById('novalnetdirectdebitachAccountHolder').onpaste = e => e.preventDefault();
                    this.handleInputKeyPress(document.getElementById('novalnetdirectdebitachAccountHolder'));
                }

                const removeCreditData = document.querySelectorAll('.remove_cc_card_details');
                const removeSepaData = document.querySelectorAll('.remove_card_details');
                const removeDirectDebitAchData = document.querySelectorAll('.remove_ach_card_details');
                const removeSepaGuaranteeData = document.querySelectorAll('.remove_guarantee_card_details ');
                const removeInstalmentData = document.querySelectorAll('.remove_instalment_card_details');

                if (element.value === 'novalnetcreditcard') {
                    // Remove the card data
                    removeCreditData.forEach((el) => {
                        el.addEventListener('click', () => {
                            this.removeStoredCard(el, element.value);
                        });
                    });
                } else if (element.value === 'novalnetsepa') {
                    // Remove the card data
                    removeSepaData.forEach((el) => {
                        el.addEventListener('click', () => {
                            this.removeStoredCard(el, element.value);
                        });
                    });
                } else if (element.value === 'novalnetsepaguarantee') {
                    // Remove the card data
                    removeSepaGuaranteeData.forEach((el) => {
                        el.addEventListener('click', () => {
                            this.removeStoredCard(el, element.value);
                        });
                    });
                } else if (element.value === 'novalnetsepainstalment') {
                    // Remove the card data
                    removeInstalmentData.forEach((el) => {
                        el.addEventListener('click', () => {
                            this.removeStoredCard(el, element.value);
                        });
                    });
                } else if (element.value === 'novalnetdirectdebitach') {
                    // Remove the card data
                    removeDirectDebitAchData.forEach((el) => {
                        el.addEventListener('click', () => {
                            this.removeStoredCard(el, element.value);
                        });
                    });
                }

                if (element.value === 'novalnetinvoiceinstalment' || element.value === 'novalnetsepainstalment') {
                    if (document.getElementById(element.value + 'Duration') != undefined) {
                        document.getElementById(element.value + 'Duration').selectedIndex = 0;
                        // Instalment summary
                        document.getElementById(element.value + 'Duration').addEventListener('change', (event) => {
                            const duration = event.target.value;
                            const elements = document.querySelectorAll('.' + element.value + 'Detail');

                            elements.forEach(function (instalmentElement) {
                                if (instalmentElement.dataset.duration === duration) {
                                    instalmentElement.hidden = false;
                                } else {
                                    instalmentElement.hidden = 'hidden';
                                }
                            });
                        });
                    }

                    if (document.getElementById(element.value + 'Info') != undefined) {
                        document.getElementById(element.value + 'Info').addEventListener('click', (el) => {
                            this.hideSummary(element.value);
                        });
                    }
                }

                if (radioInputs !== undefined && radioInputs !== null) {
                    // Show/hide the components form based on the selected radio input
                    radioInputs.forEach((radioElement, i) => {
                        radioElement.addEventListener('click', () => {
                            this.showComponents(radioElement, element.value);
                        });
                    });
                }

                if (subsChangePaymentFormButton !== undefined && subsChangePaymentFormButton !== null) {
                    if (selectedPaymentId === null) {
                        subsChangePaymentFormButton.disabled = true;
                    }

                    if (paymentRadioButton !== undefined && paymentRadioButton !== null) {
                        // Show/hide the payment form based on the payment selected
                        paymentRadioButton.forEach((payment) => {
                            payment.addEventListener('click', () => {
                                this.showPaymentForm(payment, element.value);
                                subsChangePaymentFormButton.disabled = false;
                            });
                        });
                    }

                    subsChangePaymentFormButton.addEventListener('click', (event) => {
                        // validate form when change payment form is submit
                        if (element.value == 'novalnetcreditcard' || element.value == 'novalnetsepa' || element.value == 'novalnetdirectdebitach') {
                            this.validatePaymentForm(element.value, subsChangePaymentFormButton, 'changePayment', event);
                        }
                    });
                }

                if (submitButton !== undefined && submitButton !== null) {
                    // Submit handler
                    submitButton.addEventListener('click', (event) => {
                        // validate form when confirm payment form is submit
                        if (element.value == 'novalnetcreditcard' || SepaPaymentTypes.includes(element.value) || InvoicePaymentTypes.includes(element.value) || element.value == 'novalnetmbway' || element.value == 'novalnetdirectdebitach') {
                            this.validatePaymentForm(element.value, submitButton, 'checkout', event);
                        }
                    });
                }
            });
        });
    }

    _createScript(callback) {
        const url = 'https://cdn.novalnet.de/js/v2/NovalnetUtility.js';
        const script = document.createElement('script');
        script.type = 'text/javascript';
        script.src = url;
        script.addEventListener('load', callback.bind(this), false);
        document.head.appendChild(script);
    }

    loadIframe(config, paymentName) {
        if (paymentName === 'novalnetcreditcard') {
            var me = this;
            NovalnetUtility.setClientKey(config.clientKey);

            if (document.querySelector('#novalnetchangePaymentForm') != undefined) {
                var paymentForm = document.querySelector('#novalnetchangePaymentForm');
            } else {
                var paymentForm = document.querySelector('#confirmOrderForm');
            }

            var configurationObject = {
                callback: {
                    on_success: function (data) {
                        document.getElementById('novalnetcreditcard-panhash').value = data['hash'];
                        document.getElementById('novalnetcreditcard-uniqueid').value = data['unique_id'];
                        document.getElementById('novalnetcreditcard-doRedirect').value = data['do_redirect'];
                        if (data['card_exp_month'] != undefined && data['card_exp_year'] != undefined) {
                            document.getElementById('novalnetcreditcard-expiry-date').value = data['card_exp_month'] + '/' + data['card_exp_year'];
                        }
                        document.getElementById('novalnetcreditcard-masked-card-no').value = data['card_number'];
                        document.getElementById('novalnetcreditcard-card-type').value = data['card_type'];

                        if (document.querySelector('#novalnetchangePaymentForm button[type="submit"]') != undefined) {
                            const client = new HttpClient();
                            me._disableSubmitButton(document.querySelector('#novalnetchangePaymentForm button[type="submit"]'));

                            // send change payment call to controller
                            client.post(document.getElementById('storeCustomerDataUrl').value, JSON.stringify({ pan_hash: data['hash'], unique_id: data['unique_id'], doRedirect: data['do_redirect'], paymentName: 'creditcard', parentOrderNumber: document.getElementById('parentOrderNumber').value, aboId: document.getElementById('aboId').value, paymentMethodId: document.getElementById('novalnetcreditcardId').value }), response => {
                                const res = JSON.parse(response);
                                if (res.success == true && res.redirect_url != undefined) {
                                    window.location.replace(res.redirect_url);
                                } else if (res.success == true) {
                                    paymentForm.submit();
                                    return true;
                                } else {
                                    me.preventForm('novalnetcreditcard', res.message);
                                    me._removeButtonLoader(document.querySelector('#novalnetchangePaymentForm button[type="submit"]'));
                                    return false;
                                }
                            });
                        } else {
                            paymentForm.submit();
                            return true;
                        }
                    },
                    on_error: function (data) {
                        if (data['error_message'] !== undefined && data['error_message'] !== '') {
                            if (document.getElementById("confirmFormSubmit") != undefined) {
                                var submitButton = document.getElementById("confirmFormSubmit");
                            } else if (document.querySelector('#novalnetchangePaymentForm button[type="submit"]') != undefined) {
                                var submitButton = document.querySelector('#novalnetchangePaymentForm button[type="submit"]');
                            } else {
                                var submitButton = document.querySelector('#confirmOrderForm button[type="submit"]');
                            }
                            me.preventForm('novalnetcreditcard', data['error_message']);
                            me._removeButtonLoader(submitButton);
                        }
                        return false;
                    },
                    on_show_overlay: function (data) {
                        document.getElementById('novalnetCreditcardIframe').classList.add("novalnet-challenge-window-overlay");
                    },
                    // Called in case the Challenge window Overlay (for 3ds2.0) hided
                    on_hide_overlay: function (data) {
                        document.getElementById('novalnetCreditcardIframe').classList.remove("novalnet-challenge-window-overlay");
                    },
                    on_show_captcha: function () {
                        // Scroll to top.
                        elementContent.innerHTML = 'Your Credit Card details are Invalid';
                        element.style.display = "block";
                        element.scrollIntoView();
                        elementContent.focus();
                        return false;
                    }
                },

                // You can customize your Iframe container styel, text etc.
                iframe: config.iframe,

                // Add Customer data
                customer: config.customer,

                // Add transaction data
                transaction: config.transaction,

                // Add custom data
                custom: config.custom
            }

            // Create the Credit Card form
            NovalnetUtility.createCreditCardForm(configurationObject);
        }
    }

    handleInputKeyPress(inputElement) {
        const regex = /[^0-9\[\]\/\\#,+@!^()$~%'"=:;<>{}\_\|*?`]/;
        inputElement.addEventListener('keypress', (e) => {
            const key = e.keyCode || e.which;
            const char = String.fromCharCode(key);
            if (!regex.test(char)) {
                e.preventDefault();
                return false;
            }
        });
    }

    showPaymentForm(el, paymentName) {
        const paymentMethodId = document.querySelector('#' + paymentName + 'Id');

        if (paymentMethodId !== undefined && paymentMethodId.value !== '' && el.value === paymentMethodId.value) {
            if (paymentName == 'novalnetcreditcard') {
                NovalnetUtility.setCreditCardFormHeight();
            }

            if (document.getElementById(paymentName + "-payment") !== undefined && document.getElementById(paymentName + "-payment") !== null) {
                document.getElementById(paymentName + "-payment").style.display = "block";
            }
        } else {
            if (document.getElementById(paymentName + "-payment") !== undefined && document.getElementById(paymentName + "-payment") !== null) {
                document.getElementById(paymentName + "-payment").style.display = "none";
            }
        }
    }

    showComponents(el, paymentName) {
        if (el.value === 'new' && el.checked === true) {
            document.getElementById(paymentName + "-payment-form").classList.remove("nnhide");
        } else {
            document.getElementById(paymentName + "-payment-form").classList.add("nnhide");
        }
    }

    hideSummary(paymentName) {
        const el = document.getElementById(paymentName + 'Summary');

        if (el.classList.contains("nnhide")) {
            el.classList.remove("nnhide");
        } else {
            el.classList.add("nnhide");
        }
    }

    removeStoredCard(el, paymentName) {
        var checked = document.querySelector('input[name="' + paymentName + 'FormData[paymentToken]"]:checked');

        if (checked != undefined && checked != '') {
            this.client.post(document.getElementById('cardRemoveUrl').value, JSON.stringify({ token: checked.value }), '');
            setTimeout(() => window.location.reload(), 2000);
        }
    }

    _disableSubmitButton(button) {
        button.setAttribute('disabled', 'disabled');
        const loader = new ButtonLoadingIndicator(button);
        loader.create();
    }

    _removeButtonLoader(button) {
        button.removeAttribute('disabled');
        const loader = new ButtonLoadingIndicator(button);
        loader.remove();
        return false;
    }

    validateAge(DOB) {
        var today = new Date();

        if (DOB === undefined || DOB === '') {
            return NaN;
        }

        var birthDate = DOB.split(".");
        var age = today.getFullYear() - birthDate[2];
        var m = today.getMonth() - birthDate[1];
        m = m + 1;

        if (birthDate[2] !== undefined && birthDate[2].length !== 4) {
            return NaN;
        }

        if (m < 0 || (m == '0' && today.getDate() < birthDate[0])) {
            age--;
        }
        return age;
    }

    preventForm(paymentName, errorMessage, event = '', field = '') {
        if (event) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
        var element = document.getElementById(paymentName + '-error-container');
        var elementContent = element.querySelector(".alert-content");
        elementContent.innerHTML = '';

        if (errorMessage !== undefined && errorMessage !== '') {
            elementContent.innerHTML = errorMessage;
            element.classList.remove("nnhide");
        } else {
            element.classList.add("nnhide");
        }

        if (field) {
            field.style.borderColor = "red";
        }
        element.scrollIntoView();
        return false;
    }

    sendChangePaymentRequest(paymentName, paymentData, button, event) {
        event.preventDefault();
        event.stopImmediatePropagation();

        paymentData.parentOrderNumber = document.getElementById('parentOrderNumber').value;
        paymentData.aboId = document.getElementById('aboId').value;

        this.client.post(document.getElementById('storeCustomerDataUrl').value, JSON.stringify(paymentData), response => {
            const res = JSON.parse(response);
            if (res.success == true) {
                document.querySelector('#novalnetchangePaymentForm').submit();
            } else {
                this.preventForm(paymentName, res.message, event);
                this._removeButtonLoader(button);
            }
        });
    }

    validatePaymentForm(paymentName, button, buttonType, event) {
        const paymentMethodId = document.querySelector('#' + paymentName + 'Id'),
            selectedPaymentId = document.querySelector('input[name=paymentMethodId]:checked'),
            radioInputChecked = document.querySelector('input[type="radio"].' + paymentName + '-SavedPaymentMethods-tokenInput:checked');

        if (['novalnetcreditcard', 'novalnetdirectdebitach', 'novalnetsepa', 'novalnetsepaguarantee', 'novalnetsepainstalment', 'novalnetmbway'].includes(paymentName)) {
            if (paymentMethodId !== undefined && paymentMethodId.value !== '' && selectedPaymentId.value === paymentMethodId.value) {
                if (paymentName == 'novalnetcreditcard') {
                    if (radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new') {
                        var tosInput = document.querySelector('#tos');

                        if (tosInput != undefined && !tosInput.checked) {
                            return false;
                        }

                        this._disableSubmitButton(button);
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        paymentMethodId.scrollIntoView();
                        NovalnetUtility.getPanHash();
                    } else if (buttonType == 'changePayment' && radioInputChecked !== undefined && radioInputChecked !== null && radioInputChecked.value != 'new') {
                        this.sendChangePaymentRequest(paymentName, { token: radioInputChecked.value, paymentName: 'creditcard', paymentMethodId: document.getElementById('novalnetcreditcardId').value }, button, event);
                    }
                } else if (['novalnetsepa', 'novalnetsepaguarantee', 'novalnetsepainstalment'].includes(paymentName)) {
                    const iban = document.getElementById(paymentName + 'AccountData'),
                        accountHolder = document.getElementById(paymentName + 'AccountHolder'),
                        bic = document.getElementById(paymentName + 'AccountBic'),
                        dob = document.getElementById(paymentName + 'Dob'),
                        config = JSON.parse(document.getElementById(paymentName + '-payment').getAttribute('data-' + paymentName + '-payment-config'));

                    if ((radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new') && (accountHolder === undefined || (accountHolder !== undefined && accountHolder.value === ''))) {
                        this.preventForm(paymentName, config.text.invalidIban, event, accountHolder);
                    } else if ((radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new') && (iban === undefined || (iban !== undefined && iban.value === ''))) {
                        this.preventForm(paymentName, config.text.invalidIban, event, iban);
                    } else if ((radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new') && (bic === undefined || (bic !== undefined && bic.value === '')) && document.querySelector('.nn-bic-field').classList.contains("nnhide") == false) {
                        this.preventForm(paymentName, config.text.invalidIban, event, bic);
                    } else if ((paymentName === 'novalnetsepainstalment' || paymentName === 'novalnetsepaguarantee') && (config.company === null || !NovalnetUtility.isValidCompanyName(config.company) || config.allowB2B === 0)) {
                        if (dob === undefined || dob.value === '') {
                            if (document.getElementById('novalnetsepaId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 && paymentName === 'novalnetsepaguarantee') {
                                document.getElementById('doForceSepaPayment').value = 1;
                                document.getElementById('SepaForcePayment').value = 1;
                                return true;
                            } else {
                                this.preventForm(paymentName, config.text.dobEmpty, event, dob);
                            }
                        } else if (dob !== undefined && dob.value !== '') {
                            const age = this.validateAge(dob.value);
                            if ((age < 18 || isNaN(age)) && document.getElementById('novalnetsepaId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 && paymentName === 'novalnetsepaguarantee') {
                                document.getElementById('doForceSepaPayment').value = 1;
                                document.getElementById('SepaForcePayment').value = 1;
                                return true;
                            } else if (age < 18 || isNaN(age)) {
                                this.preventForm(paymentName, config.text.dobInvalid, event, dob);
                            }
                        }
                    } else if (buttonType == 'changePayment' && paymentName == 'novalnetsepa') {
                        if (radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new') {
                            var paymentData = { iban: iban.value, paymentName: 'direct_debit_sepa', paymentMethodId: document.getElementById('novalnetsepaId').value };

                            if (bic != undefined && bic.value != '') {
                                paymentData.push({ bic: bic.value });
                            }
                        } else {
                            var paymentData = { token: radioInputChecked.value, paymentName: 'direct_debit_sepa', paymentMethodId: document.getElementById('novalnetsepaId').value };
                        }

                        this.sendChangePaymentRequest(paymentName, paymentData, button, event);
                    }
                } else if (paymentName == 'novalnetdirectdebitach') {
                    const accountHolder = document.querySelector('input[id =' + paymentName + 'AccountHolder'),
                        accountNumber = document.getElementById(paymentName + 'AccountNo'),
                        routingNumber = document.getElementById(paymentName + 'RoutingAbaNo'),
                        config = JSON.parse(document.getElementById(paymentName + '-payment').getAttribute('data-' + paymentName + '-payment-config'));

                    if (radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new') {
                        if ((accountHolder === undefined || accountHolder.value === '')) {
                            this.preventForm(paymentName, config.text.accountHolderEmpty, event, accountHolder);
                            return false;
                        } else if ((accountNumber === undefined || accountNumber.value === '')) {
                            this.preventForm(paymentName, config.text.accountNumberEmpty, event, accountNumber);
                            return false;
                        } else if (routingNumber === undefined || routingNumber.value === '') {
                            this.preventForm(paymentName, config.text.routingNumberEmpty, event, routingNumber);
                            return false;
                        }
                    }

                    if (buttonType == 'changePayment' && paymentName == 'novalnetdirectdebitach') {

                        if (radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new') {
                            var paymentData = { paymentName: 'direct_debit_ach', paymentMethodId: document.getElementById('novalnetdirectdebitachId').value, account_holder: accountHolder.value, account_number: accountNumber.value, routing_number: routingNumber.value };
                        } else {
                            var paymentData = { paymentName: 'direct_debit_ach', paymentMethodId: document.getElementById('novalnetdirectdebitachId').value, token: radioInputChecked.value };
                        }

                        this.sendChangePaymentRequest(paymentName, paymentData, button, event);
                    }

                } else if (paymentName == 'novalnetmbway') {

                    const mobileNumber = document.getElementById(paymentName + 'MobileNo'),
                        config = JSON.parse(document.getElementById(paymentName + '-payment').getAttribute('data-' + paymentName + '-payment-config'));

                    if (mobileNumber === undefined || mobileNumber.value === '') {
                        this.preventForm(paymentName, config.text.mobileEmpty, event, mobileNumber);

                    }

                }
            }
        } else if (['novalnetinvoiceguarantee', 'novalnetinvoiceinstalment'].includes(paymentName) && paymentMethodId !== undefined && paymentMethodId.value !== '' && selectedPaymentId.value === paymentMethodId.value) {
            const dob = document.getElementById(paymentName + 'Dob'),
                config = JSON.parse(document.getElementById(paymentName + '-payment').getAttribute('data-' + paymentName + '-payment-config'));

            if (config.company === null || !NovalnetUtility.isValidCompanyName(config.company) || config.allowB2B === 0) {
                if (dob === undefined || dob.value === '') {
                    if (document.getElementById('novalnetinvoiceId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 && paymentName === 'novalnetinvoiceguarantee') {
                        document.getElementById('doForceInvoicePayment').value = 1;
                        document.getElementById('InvoiceForcePayment').value = 1;
                        return true;
                    }
                    else {
                        this.preventForm(paymentName, config.text.dobEmpty, event, dob);
                    }

                } else if (dob !== undefined && dob.value !== '') {
                    const age = this.validateAge(dob.value);

                    if ((age < 18 || isNaN(age)) && document.getElementById('novalnetinvoiceId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 && paymentName === 'novalnetinvoiceguarantee') {
                        document.getElementById('doForceInvoicePayment').value = 1;
                        document.getElementById('InvoiceForcePayment').value = 1;
                        return true;
                    } else if (age < 18 || isNaN(age)) {
                        this.preventForm(paymentName, config.text.dobInvalid, event, dob);
                    }
                }
            }
        }
    }
}
