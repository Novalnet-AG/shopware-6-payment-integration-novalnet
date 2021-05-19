import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

export default class NovalnetCreditCardPayment extends Plugin {
    
    init() {
		this.client = new HttpClient();
		
        const submitButton = document.querySelector(this.getSelectors().submitButton);
        const creditCardId = document.querySelector(this.getSelectors().creditCardId);
        const radioInputs = document.querySelectorAll(this.getSelectors().radioInputs);
        const radioInputChecked = document.querySelector(this.getSelectors().radioInputChecked);
        const selectedPaymentId = document.querySelector(this.getSelectors().selectedPaymentId);
        const paymentRadioButton = document.querySelectorAll(this.getSelectors().paymentRadioButton);
		
		if( selectedPaymentId !== undefined && selectedPaymentId !== null && creditCardId !== undefined && selectedPaymentId.value === creditCardId.value )
        {
			if(document.getElementById("novalnetcreditcardHideButton") != undefined && document.getElementById("novalnetcreditcardHideButton").value == 1)
			{
				this._disableSubmitButton();
			}
			
			document.getElementById("novalnetcreditcard-payment").style.display = "block";
		}
		
        this._createScript(() => {
            const config	= JSON.parse(document.getElementById('novalnetcreditcard-payment').getAttribute('data-novalnetcreditcard-payment-config'));
            this.loadIframe(config);
        });
        
        if( radioInputChecked !== undefined && radioInputChecked !== null )
        {
			this.showComponents( radioInputChecked );
		}
        
        // Submit handler
        submitButton.addEventListener('click', (event) => {
            const selectedPaymentId = document.querySelector(this.getSelectors().selectedPaymentId);
            const radioInputChecked = document.querySelector(this.getSelectors().radioInputChecked);
            
            if( creditCardId !== undefined && creditCardId.value !== '' && selectedPaymentId.value === creditCardId.value && (radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new') )
            {
				event.preventDefault();
				event.stopImmediatePropagation();
				NovalnetUtility.getPanHash();
			}
        });
        
        // Show/hide the components form based on the selected radio input
        radioInputs.forEach((element) => {
            element.addEventListener('click', () => {
                this.showComponents(element);
            });
        });
        
        // Show/hide the payment form based on the payment selected
        paymentRadioButton.forEach((element) => {
            element.addEventListener('click', () => {
                this.showPaymentForm(element);
            });
        });
        
        const removeCards = document.querySelectorAll('#confirmPaymentForm .remove_cc_card_details');
        removeCards.forEach((el) => {
            el.addEventListener('click', () => {
				this.removeStoredCard(el);
            });
        });
    }
    
    _createScript(callback) {
        const url = 'https://cdn.novalnet.de/js/v2/NovalnetUtility.js';
		
		if( document.querySelectorAll(`script[src="${url}"]`).length === 0 )
		{
			const script = document.createElement('script');
			script.type = 'text/javascript';
			script.src = url;
			script.addEventListener('load', callback.bind(this), false);
			document.head.appendChild(script);
		}
    }

    loadIframe(config) {
		const paymentForm = document.querySelector(this.getSelectors().paymentForm);
        NovalnetUtility.setClientKey(config.clientKey);
        
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
                    $( '#novalnetcreditcard-secure-data' ).val( JSON.stringify( data ) );
                    paymentForm.submit();
                    return true;
                },
                on_error:  function (data) {
					var element = document.getElementById('novalnetcreditcard-error-container');
					
					var elementContent = element.querySelector(".alert-content");
					elementContent.innerHTML = '';
                    if ( data['error_message'] !== undefined && data['error_message'] !== '' ) {
						
						elementContent.innerHTML = data['error_message'];
						element.style.display = "block";
						elementContent.focus();
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
                }
            },
            
            // You can customize your Iframe container styel, text etc. 
            iframe: config.iframe,
            
            // Add Customer data
            customer: config.customer,
            
            // Add transaction data
            transaction: config.transaction
        }
        
        // Create the Credit Card form
        NovalnetUtility.createCreditCardForm(configurationObject);
	}
	
	getSelectors() {
        return {
            creditCardId: '#novalnetcreditcardId',
            paymentForm: '#confirmPaymentForm',
            panHash: '#novalnetcreditcard-panhash',
            paymentRadioButton: '#confirmPaymentForm input[name="paymentMethodId"]',
            selectedPaymentId: '#confirmPaymentForm input[name=paymentMethodId]:checked',
            submitButton: '#confirmPaymentForm button[type="submit"]',
            radioInputs: '#confirmPaymentForm input[type="radio"].novalnetcreditcard-SavedPaymentMethods-tokenInput',
            radioInputChecked: '#confirmPaymentForm input[type="radio"].novalnetcreditcard-SavedPaymentMethods-tokenInput:checked',
        };
    }
    
    showComponents(el) {
			
            if ( el.value !== 'new' ) {
				document.getElementById("novalnetcreditcard-payment-form").classList.add("nnhide");
            } else {
				NovalnetUtility.setCreditCardFormHeight();
				document.getElementById("novalnetcreditcard-payment-form").classList.remove("nnhide");
            }
    }
    
    showPaymentForm( el ) {
		
		const creditCardId = document.querySelector(this.getSelectors().creditCardId);
		
		if( creditCardId !== undefined && creditCardId.value !== '' && el.value === creditCardId.value )
        {
			NovalnetUtility.setCreditCardFormHeight();
			document.getElementById("novalnetcreditcard-payment").style.display = "block";
		} else {
			document.getElementById("novalnetcreditcard-payment").style.display = "none";
		}
    }
    
    removeStoredCard(el) {
		
		var checked = document.querySelector('input[name="novalnetcreditcardFormData[paymentToken]"]:checked');
        
		if( checked !== undefined && checked !== '' )
		{
			var r_cc = confirm(document.getElementById("removeConfirmMessage").value);
				if (r_cc == true) {
					this.client.post($('#cardRemoveUrl').val(), JSON.stringify({ token: checked.value}), '');
					window.location.reload();
				}
		}
	}
	
	_disableSubmitButton() {
		
		var button = document.querySelector('#confirmOrderForm button');

        if (button) {
            button.setAttribute('disabled', 'disabled');
        }
    }
}
