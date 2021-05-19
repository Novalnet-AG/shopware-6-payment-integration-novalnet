import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

export default class NovalnetPaypalPayment extends Plugin {
    
    init() {
		this.client = new HttpClient();
		
        const paymentRadioButton = document.querySelectorAll(this.getSelectors().paymentRadioButton);
        const selectedPaymentId = document.querySelector(this.getSelectors().selectedPaymentId);
        const paypalId = document.querySelector(this.getSelectors().paypalId);
        const radioInputs = document.querySelectorAll(this.getSelectors().radioInputs);
        const radioInputChecked = document.querySelector(this.getSelectors().radioInputChecked);
		
		if( radioInputChecked !== undefined && radioInputChecked !== null )
        {
			this.showComponents( radioInputChecked );
		}
		
		this._createScript(() => {
            document.getElementById('novalnet-payment');
        });
        
        if( selectedPaymentId !== undefined && selectedPaymentId !== null && paypalId !== undefined && selectedPaymentId.value === paypalId.value )
        {
			document.getElementById("novalnetpaypal-payment").style.display = "block";
		}
		
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
        
        const removeCards = document.querySelectorAll('#confirmPaymentForm .remove_paypal_account_details');
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
    
    getSelectors() {
        return {
			paypalId: '#novalnetpaypalId',
            paymentRadioButton: '#confirmPaymentForm input[name="paymentMethodId"]',
            selectedPaymentId: '#confirmPaymentForm input[name=paymentMethodId]:checked',
            radioInputs: '#confirmPaymentForm input[type="radio"].novalnetpaypal-SavedPaymentMethods-tokenInput',
            radioInputChecked: '#confirmPaymentForm input[type="radio"].novalnetpaypal-SavedPaymentMethods-tokenInput:checked',
        };
    }
	
	showPaymentForm( el ) {
		
		const paypalId = document.querySelector(this.getSelectors().paypalId);
		
		if( paypalId !== undefined && paypalId.value !== '' && el.value === paypalId.value )
        {
			document.getElementById("novalnetpaypal-payment").style.display = "block";
		} else {
			document.getElementById("novalnetpaypal-payment").style.display = "none";
		}
    }
    
    removeStoredCard(el) {
		
		var checked = document.querySelector('input[name="novalnetpaypalFormData[paymentToken]"]:checked');
        
		if( checked !== undefined && checked !== '' )
		{
			var r_paypal = confirm(document.getElementById("removeConfirmMessage").value);
				if (r_paypal == true) {
					this.client.post($('#cardRemoveUrl').val(), JSON.stringify({ token: checked.value}), '');
					window.location.reload();
				}
		}
	}
	
	showComponents(el) {
			
            if ( el.value !== 'new' ) {
				document.getElementById("novalnetpaypal-payment-form").classList.add("nnhide");
            } else {
				document.getElementById("novalnetpaypal-payment-form").classList.remove("nnhide");
            }
    }
}
