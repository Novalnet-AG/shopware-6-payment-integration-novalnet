import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import ButtonLoadingIndicator from 'src/utility/loading-indicator/button-loading-indicator.util';

export default class NovalnetPayment extends Plugin {

    init() {
		this.client = new HttpClient();
		const paymentName = document.querySelectorAll('.novalnet-payment-name');
		const shopVersion = document.querySelector('#nnShopVersion').value;
		const SepaPaymentTypes = ['novalnetsepa', 'novalnetsepaguarantee', 'novalnetsepainstalment'];
		const InvoicePaymentTypes = ['novalnetinvoiceguarantee', 'novalnetinvoiceinstalment'];
		
		paymentName.forEach((element) => {
			const selectedPaymentId = document.querySelector('input[name=paymentMethodId]:checked'),
			      paymentMethodId	= document.querySelector('#' + element.value + 'Id'),
			      radioInputChecked = document.querySelector('input[type="radio"].' + element.value + '-SavedPaymentMethods-tokenInput:checked'),
			      radioInputs		= document.querySelectorAll('input[type="radio"].' + element.value + '-SavedPaymentMethods-tokenInput'),
			      paymentRadioButton = document.querySelectorAll('input[name="paymentMethodId"]');

			if( selectedPaymentId !== undefined && selectedPaymentId !== null && paymentMethodId !== undefined && selectedPaymentId.value === paymentMethodId.value )
			{
				if( document.getElementById(element.value + "PaymentNotification") !== undefined && document.getElementById(element.value + "PaymentNotification") !== null )
				{
					document.getElementById(element.value + "PaymentNotification").style.display = "block";
				}
				if(document.getElementById(element.value + "HideButton") != undefined && document.getElementById(element.value + "HideButton").value == 1)
				{
					this._disableSubmitButton();
				}

				if(document.getElementById(element.value + "-payment") != undefined && document.getElementById(element.value + "-payment") != null)
				{
					document.getElementById(element.value + "-payment").style.display = "block";
				}
			}
			
			if( radioInputChecked !== undefined && radioInputChecked !== null )
			{
				this.showComponents( radioInputChecked , element.value );
			}
			
			if(shopVersion >= '6.4')
			{
				var submitButton = document.querySelector('#confirmOrderForm button[type="submit"]');
			} else {
				var submitButton = document.querySelector('#confirmPaymentForm button[type="submit"]');
			}
			
			if(element.value == 'novalnetcreditcard')
			{
				this._createScript(() => {
					const config	= JSON.parse(document.getElementById(element.value + '-payment').getAttribute('data-' + element.value + '-payment-config'));
					this.loadIframe(config, element.value);
				});
			} else if(SepaPaymentTypes.includes(element.value) || InvoicePaymentTypes.includes(element.value))
			{
				this._createScript(() => {
					const config	= JSON.parse(document.getElementById(element.value + '-payment').getAttribute('data-' + element.value + '-payment-config'));

					if( element.value != 'novalnetsepa' && config.company === null || !NovalnetUtility.isValidCompanyName(config.company) ) {
						const dob = document.getElementById(element.value + 'DobField');
						dob.style.display = "block";
					}
				});
				
				const iban = document.getElementById( element.value + 'AccountData');
				if(iban !== undefined && iban !== null)
				{
					['click','paste','keydown','keyup'].forEach((evt) => {
						iban.addEventListener(evt, (event) => {
							var result = event.target.value;
							if (result != undefined && result != '')
							{
								result = result.toUpperCase();
                                if (result.match(/(?:CH|MC|SM|GB)/)) {
									document.querySelector('.nn-bic-field').classList.remove("nnhide");
                                } else {
									document.querySelector('.nn-bic-field').classList.add("nnhide");
								}
							}
						});
					});
				}
			}
			const removeCreditData	= document.querySelectorAll('.remove_cc_card_details');
			const removePaypalData	= document.querySelectorAll('.remove_paypal_account_details');
			const removeSepaData	= document.querySelectorAll('.remove_card_details');
			const removeSepaGuaranteeData	= document.querySelectorAll('.remove_guarantee_card_details ');
			const removeInstalmentData	= document.querySelectorAll('.remove_instalment_card_details');
			
			if(element.value === 'novalnetcreditcard')
			{
				// Remove the card data
				removeCreditData.forEach((el) => {
					el.addEventListener('click', () => {
						this.removeStoredCard(el, element.value);
					});
				});
			} else if(element.value === 'novalnetsepa')
			{
				// Remove the card data
				removeSepaData.forEach((el) => {
					el.addEventListener('click', () => {
						this.removeStoredCard(el, element.value);
					});
				});
			} else if(element.value === 'novalnetsepaguarantee')
			{
				// Remove the card data
				removeSepaGuaranteeData.forEach((el) => {
					el.addEventListener('click', () => {
						this.removeStoredCard(el, element.value);
					});
				});
			} else if(element.value === 'novalnetpaypal')
			{
				// Remove the card data
				removePaypalData.forEach((el) => {
					el.addEventListener('click', () => {
						this.removeStoredCard(el, element.value);
					});
				});
			} else if(element.value === 'novalnetsepainstalment')
			{
				// Remove the card data
				removeInstalmentData.forEach((el) => {
					el.addEventListener('click', () => {
						this.removeStoredCard(el, element.value);
					});
				});
			}
			
			if(element.value === 'novalnetinvoiceinstalment' || element.value === 'novalnetsepainstalment')
			{
				if(document.getElementById(element.value + 'Duration') != undefined)
				{
					document.getElementById(element.value + 'Duration').selectedIndex = 0;
					// Instalment summary
					document.getElementById(element.value + 'Duration').addEventListener('change', (event) => {
						const duration = event.target.value;
						const elements = document.querySelectorAll('.' + element.value + 'Detail');

						elements.forEach(function(instalmentElement) {
							if (instalmentElement.dataset.duration === duration) {
								instalmentElement.hidden = false;
							} else {
								instalmentElement.hidden = 'hidden';
							}
						});
					});
				}
				
				if(document.getElementById(element.value + 'Info') != undefined)
				{
					document.getElementById(element.value + 'Info').addEventListener('click', (el) => {
						this.hideSummary( element.value );
					});
				}
			}
			
			// Show/hide the components form based on the selected radio input
			radioInputs.forEach((radioElement, i) => {
				radioElement.addEventListener('click', () => {
					this.showComponents( radioElement , element.value );
				});
			});
			
			var disableSubmitButton = true;
			// Show/hide the payment form based on the payment selected
			paymentRadioButton.forEach((payment) => {
				if(payment.checked === true){
					disableSubmitButton = false;
				}
				
				payment.addEventListener('click', () => {
					this.showPaymentForm(payment, element.value);
				});
			});
			
			if(disableSubmitButton == true)
			{
				this._disableSubmitButton();
			}
			
			// Submit handler
			submitButton.addEventListener('click', (event) => {
				const paymentMethodId = document.querySelector('#' + element.value + 'Id'),
				      selectedPaymentId = document.querySelector('input[name=paymentMethodId]:checked'),
				      radioInputChecked = document.querySelector('input[type="radio"].' + element.value + '-SavedPaymentMethods-tokenInput:checked');
				
				if(element.value == 'novalnetcreditcard')
				{
					if ( paymentMethodId !== undefined && paymentMethodId.value !== '' && selectedPaymentId.value === paymentMethodId.value && (radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new'))
					{
						var tosInput = document.querySelector('#tos');
						
						if(tosInput != undefined && !tosInput.checked)
						{
							return false;
						}
						if(document.getElementById("confirmFormSubmit") != undefined)
						{
							document.getElementById("confirmFormSubmit").disabled = true;
							const loader = new ButtonLoadingIndicator(document.getElementById("confirmFormSubmit"));
							loader.create();
						} else {
							var submitButton = document.querySelector('#confirmOrderForm button[type="submit"]');
							submitButton.disabled = true;
							const loader = new ButtonLoadingIndicator(submitButton);
							loader.create();
						}
						
						event.preventDefault();			
						event.stopImmediatePropagation();
						paymentMethodId.scrollIntoView();
						NovalnetUtility.getPanHash();

					}
				} else if (SepaPaymentTypes.includes(element.value))
				{
					const iban = document.getElementById( element.value + 'AccountData');
					const bic = document.getElementById( element.value + 'AccountBic');
					const dob = document.getElementById(element.value + 'Dob');
					const config = JSON.parse(document.getElementById(element.value + '-payment').getAttribute('data-' + element.value + '-payment-config'));
					
					if( paymentMethodId !== undefined && paymentMethodId.value !== '' && selectedPaymentId.value === paymentMethodId.value )
					{
						if ((iban === undefined || iban.value === '') && (radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new'))
						{
							this.preventForm(iban, element.value, config.text.invalidIban);
						} else if ((bic === undefined || bic.value === '') && document.querySelector('.nn-bic-field').classList.contains("nnhide") == false && (radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new')) {
							this.preventForm(bic, element.value, config.text.invalidIban);
						}else if (( element.value === 'novalnetsepainstalment' && (config.company === null || !NovalnetUtility.isValidCompanyName(config.company))) || (element.value === 'novalnetsepaguarantee' && (config.company === null || !NovalnetUtility.isValidCompanyName(config.company) || config.allowB2B === 0)))
						{
							if( dob === undefined || dob.value === '' )
							{
								if( document.getElementById('novalnetsepaId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 && element.value === 'novalnetsepaguarantee')
								{
									if(shopVersion < '6.4')
									{
										selectedPaymentId.value = document.getElementById('novalnetsepaId').value;
									}
									document.getElementById('doForceSepaPayment').value = 1;
									document.getElementById('SepaForcePayment').value = 1;
									return true;
								}
								else
								{
									this.preventForm(dob, element.value, config.text.dobEmpty);
								}
							} else if (dob !== undefined && dob.value !== '')
							{
								const age = this.validateAge(dob.value);
								if((age < 18 || isNaN(age)) && document.getElementById('novalnetsepaId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 && element.value === 'novalnetsepaguarantee')
								{
									if(shopVersion < '6.4')
									{
										selectedPaymentId.value = document.getElementById('novalnetsepaId').value;
									}
									document.getElementById('doForceSepaPayment').value = 1;
									document.getElementById('SepaForcePayment').value = 1;
									return true;
								} else if (age < 18 || isNaN(age)) {
									this.preventForm(dob, element.value, config.text.dobInvalid);
								}
							}
						}
					}
				} else if (InvoicePaymentTypes.includes(element.value))
				{
					const dob		= document.getElementById(element.value + 'Dob');
					const config = JSON.parse(document.getElementById(element.value + '-payment').getAttribute('data-' + element.value + '-payment-config'));
					
					if( paymentMethodId.value !== undefined && paymentMethodId.value !== '' && selectedPaymentId.value === paymentMethodId.value )
					{
						if ( config.company === null || !NovalnetUtility.isValidCompanyName(config.company) || config.allowB2B === 0 ) {
							if ( dob === undefined || dob.value === '' )
							{
								if( document.getElementById('novalnetinvoiceId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 && element.value === 'novalnetinvoiceguarantee')
								{
									if(shopVersion < '6.4')
									{
										selectedPaymentId.value = document.getElementById('novalnetinvoiceId').value;
									}
									document.getElementById('doForceInvoicePayment').value = 1;
									document.getElementById('InvoiceForcePayment').value = 1;
									return true;
								}
								else
								{
									this.preventForm(dob, element.value, config.text.dobEmpty);
								}

							} else if ( dob !== undefined && dob.value !== '' )
							{
								const age = this.validateAge(dob.value);
								
								if( ( age < 18 || isNaN(age) )  && document.getElementById('novalnetinvoiceId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 && element.value === 'novalnetinvoiceguarantee')
								{
									if(shopVersion < '6.4')
									{
										selectedPaymentId.value = document.getElementById('novalnetinvoiceId').value;
									}
									document.getElementById('doForceInvoicePayment').value = 1;
									document.getElementById('InvoiceForcePayment').value = 1;
									return true;
								} else if ( age < 18 || isNaN(age) )
								{
									this.preventForm(dob, element.value, config.text.dobInvalid);
								}
							}
						}
					}
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
		if(paymentName === 'novalnetcreditcard')
		{
			NovalnetUtility.setClientKey(config.clientKey);
			
			const shopVersion = document.querySelector('#nnShopVersion').value;
			
			if(shopVersion >= '6.4')
			{
				var paymentForm = document.querySelector('#confirmOrderForm');
			} else {
				var paymentForm = document.querySelector('#confirmPaymentForm');
			}

			var configurationObject = {
				callback: {
					on_success: function (data) {
						document.getElementById('novalnetcreditcard-panhash').value = data ['hash'];
						document.getElementById('novalnetcreditcard-uniqueid').value = data ['unique_id'];
						document.getElementById('novalnetcreditcard-doRedirect').value = data ['do_redirect'];
						if(data ['card_exp_month'] != undefined && data ['card_exp_year'] != undefined) {
							document.getElementById('novalnetcreditcard-expiry-date').value = data ['card_exp_month'] + '/' + data ['card_exp_year'];
						}
						document.getElementById('novalnetcreditcard-masked-card-no').value = data ['card_number'];
						document.getElementById('novalnetcreditcard-card-type').value = data ['card_type'];
						paymentForm.disabled = false;
						paymentForm.submit();
						return true;
					},
					on_error:  function (data) {							
						var element = document.getElementById('novalnetcreditcard-error-container');
						var elementContent = element.querySelector(".alert-content");
						elementContent.innerHTML = '';
						if ( data['error_message'] !== undefined && data['error_message'] !== '' ) {
                            paymentForm.disabled = false;                            				
                            if(document.getElementById("confirmFormSubmit") != undefined)
							{
								document.getElementById("confirmFormSubmit").disabled = false;
								const loader = new ButtonLoadingIndicator(document.getElementById("confirmFormSubmit"));
								loader.remove();
							} else {
								var submitButton = document.querySelector('#confirmOrderForm button[type="submit"]');
								submitButton.disabled = false;
								const loader = new ButtonLoadingIndicator(submitButton);
								loader.remove();
							}
							elementContent.innerHTML = data['error_message'];
							element.style.display = "block";
							element.scrollIntoView();
						} else {
							element.style.display = "none";
						}
						return false;
					},
					on_show_overlay:  function (data) {
						document.getElementById('novalnetCreditcardIframe').classList.add("novalnet-challenge-window-overlay");
					},
					// Called in case the Challenge window Overlay (for 3ds2.0) hided
					on_hide_overlay:  function (data) {
						document.getElementById('novalnetCreditcardIframe').classList.remove("novalnet-challenge-window-overlay");
					},
					on_show_captcha:  function () {
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

    showComponents(el, paymentName) {
        if ( el.value === 'new'  && el.checked === true) {
			document.getElementById(paymentName + "-payment-form").classList.remove("nnhide");
        } else {
			document.getElementById(paymentName + "-payment-form").classList.add("nnhide");
        }
    }
    
    hideSummary(paymentName) {
		const el = document.getElementById(paymentName + 'Summary');
		
		if ( el.classList.contains("nnhide") ) {
			el.classList.remove("nnhide");
		} else {
			el.classList.add("nnhide");
		}
    }

    showPaymentForm( el, paymentName ) {

		const paymentMethodId = document.querySelector('#' + paymentName + 'Id');
		if( paymentMethodId !== undefined && paymentMethodId.value !== '' && el.value === paymentMethodId.value )
        {
			if(paymentName == 'novalnetcreditcard')
			{
				NovalnetUtility.setCreditCardFormHeight();
			}

			if( document.getElementById(paymentName + "-payment") !== undefined && document.getElementById(paymentName + "-payment") !== null )
			{
				document.getElementById(paymentName + "-payment").style.display = "block";
			}
			
			if( document.getElementById(paymentName + "PaymentNotification") !== undefined && document.getElementById(paymentName + "PaymentNotification") !== null )
			{
				document.getElementById(paymentName + "PaymentNotification").style.display = "block";
			}
		} else {
			if( document.getElementById(paymentName + "-payment") !== undefined && document.getElementById(paymentName + "-payment") !== null )
			{
				document.getElementById(paymentName + "-payment").style.display = "none";
			}

			if( document.getElementById(paymentName + "PaymentNotification") !== undefined && document.getElementById(paymentName + "PaymentNotification") !== null )
			{
				document.getElementById(paymentName + "PaymentNotification").style.display = "none";
			}
		}
    }

    removeStoredCard(el, paymentName) {
		var checked = document.querySelector('input[name="'+ paymentName + 'FormData[paymentToken]"]:checked');

		if( checked != undefined && checked != '' )
		{
			this.client.post($('#cardRemoveUrl').val(), JSON.stringify({ token: checked.value}), '');
			setTimeout(() => window.location.reload(), 2000);
		}
	}

	_disableSubmitButton() {

		var button = document.querySelector('#confirmOrderForm button');

        if (button) {
            button.setAttribute('disabled', 'disabled');
        }
    }
    
    validateAge(DOB) {
		var today = new Date();

        if(DOB === undefined || DOB === '')
		{
			return NaN;
		}

        var birthDate = DOB.split(".");
		var age = today.getFullYear() - birthDate[2];
		var m = today.getMonth() - birthDate[1];
		m = m + 1
		if (m < 0 || (m == '0' && today.getDate() < birthDate[0])) {
			age--;
		}
		return age;
	}
    
    preventForm(field, paymentName, errorMessage)
	{
		field.style.borderColor = "red";
		var element = document.getElementById(paymentName + '-error-container');
		event.preventDefault();
		event.stopImmediatePropagation();
		element.scrollIntoView();
			
		var elementContent = element.querySelector(".alert-content");
		elementContent.innerHTML = '';
		if ( errorMessage !== undefined && errorMessage !== '' ) {

			elementContent.innerHTML = errorMessage;
			element.style.display = "block";
			element.scrollIntoView();
		} else {
			element.style.display = "none";
		}
		return false;
	}
}
