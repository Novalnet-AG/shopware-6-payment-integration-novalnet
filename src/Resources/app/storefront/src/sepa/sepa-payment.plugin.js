import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';

export default class NovalnetSepaPayment extends Plugin {
    
    init() {
		this.client = new HttpClient();
		
		const paymentNameHtmlContent = document.getElementById('novalnet-payment-name');
		const paymentName = paymentNameHtmlContent.value;
        const submitButton = document.querySelector(this.getSelectors(paymentName).submitButton);
        const sepaId = document.querySelector(this.getSelectors(paymentName).sepaId);
        const radioInputs = document.querySelectorAll(this.getSelectors(paymentName).radioInputs);
        const radioInputChecked = document.querySelector(this.getSelectors(paymentName).radioInputChecked);
        const selectedPaymentId = document.querySelector(this.getSelectors(paymentName).selectedPaymentId);
        const paymentRadioButton = document.querySelectorAll(this.getSelectors(paymentName).paymentRadioButton);
        
        if( selectedPaymentId !== undefined && selectedPaymentId !== null && sepaId !== undefined && selectedPaymentId.value === sepaId.value )
        {
			if(document.getElementById(paymentName + "HideButton") != undefined && document.getElementById(paymentName + "HideButton").value == 1)
			{
				this._disableSubmitButton();
			}
			
			document.getElementById(paymentName + "-payment").style.display = "block";
		}
		
        this._createScript(() => {
            document.getElementById('novalnet-payment');
        });
        
        if( radioInputChecked !== undefined && radioInputChecked !== null )
        {
			this.showComponents( radioInputChecked, paymentName );
		}
		
		// Submit handler
        submitButton.addEventListener('click', (event) => {
			const selectedPaymentId = document.querySelector(this.getSelectors(paymentName).selectedPaymentId);
            const radioInputChecked = document.querySelector(this.getSelectors(paymentName).radioInputChecked);
			const iban = document.getElementById( paymentName + 'AccountData');
			const dob = document.getElementById(paymentName + 'Dob');
			const config	= JSON.parse(document.getElementById(paymentName + '-payment').getAttribute('data-'+ paymentName +'-payment-config'));
            
            if( sepaId.value !== undefined && sepaId.value !== '' && selectedPaymentId.value === sepaId.value && (radioInputChecked === undefined || radioInputChecked === null || radioInputChecked.value == 'new') )
            {	
				if( iban === undefined || iban.value === '' )
				{
					this.preventForm(iban, paymentName, config.text.invalidIban);
				} else if ( paymentName === 'novalnetsepaguarantee' && ( dob === undefined || dob.value === '' ) )
				{	
					if( document.getElementById('novalnetsepaId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 )
					{
						selectedPaymentId.value = document.getElementById('novalnetsepaId').value;
						document.getElementById('doForceSepaPayment').value = 1;
					}
					else
					{	
						this.preventForm(dob, paymentName, config.text.dobEmpty);
					}
						
				} else if ( paymentName === 'novalnetsepaguarantee' && dob !== undefined && dob.value !== '' )
				{
					const age = this.validateAge(dob.value);
					if ( age < 18 && config.forceGuarantee != undefined && config.forceGuarantee != 1 )
					{
						this.preventForm(dob, paymentName, config.text.dobInvalid);
					}
					else if( age < 18 && document.getElementById('novalnetsepaId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 )
					{
						selectedPaymentId.value = document.getElementById('novalnetsepaId').value;
						document.getElementById('doForceSepaPayment').value = 1;
					}
				}
			}
        });
		
		// Show/hide the components form based on the selected radio input
        radioInputs.forEach((element) => {
            element.addEventListener('click', () => {
                this.showComponents(element, paymentName);
            });
        });
                
        // Show/hide the payment form based on the payment selected
        paymentRadioButton.forEach((element) => {
            element.addEventListener('click', () => {
                this.showPaymentForm(element, paymentName);
            });
        });
        
        const removeCards = document.querySelectorAll('#confirmPaymentForm .remove_card_details');
        removeCards.forEach((el) => {
            el.addEventListener('click', () => {
				this.removeStoredCard(el, paymentName);
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
    
    getSelectors(paymentName) {
        return {
            sepaId: '#' + paymentName + 'Id',
            paymentForm: '#confirmPaymentForm',
            iban: '#novalnetsepaAccountData',
            paymentRadioButton: '#confirmPaymentForm input[name="paymentMethodId"]',
            selectedPaymentId: '#confirmPaymentForm input[name=paymentMethodId]:checked',
            submitButton: '#confirmPaymentForm button[type="submit"]',
            radioInputs: '#confirmPaymentForm input[type="radio"].' + paymentName + '-SavedPaymentMethods-tokenInput',
            radioInputChecked: '#confirmPaymentForm input[type="radio"].' + paymentName + '-SavedPaymentMethods-tokenInput:checked',
        };
    }
    
    showComponents( el, paymentName ) {
			
            if ( el.value !== 'new' ) {
				document.getElementById( paymentName + "-payment-form" ).classList.add("nnhide");
				
            } else {
				document.getElementById( paymentName + "-payment-form" ).classList.remove("nnhide");
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
		event.preventDefault();
		event.stopImmediatePropagation();
		var element = document.getElementById(paymentName + '-error-container');
					
		var elementContent = element.querySelector(".alert-content");
		elementContent.innerHTML = '';
		if ( errorMessage !== undefined && errorMessage !== '' ) {
			
			elementContent.innerHTML = errorMessage;
			element.style.display = "block";
			elementContent.focus();
		} else {
			element.style.display = "none";
		}
		return false;
	}
	
	showPaymentForm( el, paymentName ) {
		
		const sepaId = document.querySelector(this.getSelectors(paymentName).sepaId);
		
		if( sepaId.value !== undefined && sepaId.value !== '' && el.value === sepaId.value )
        {
			document.getElementById(paymentName + "-payment").style.display = "block";
		} else {
			document.getElementById(paymentName + "-payment").style.display = "none";
		}
    }
    
    removeStoredCard(el, paymentName) {
		
		var checked = document.querySelector('input[name="'+ paymentName + 'FormData[paymentToken]"]:checked');
		
		if( checked !== undefined && checked !== '' )
		{
			var r_sepa = confirm(document.getElementById("removeConfirmMessage").value);
				if (r_sepa == true) {
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
