import Plugin from 'src/plugin-system/plugin.class';

export default class NovalnetInvoicePayment extends Plugin {
    
    init() {
		
		const paymentNameHtmlContent = document.getElementById('novalnetinvoice-payment-name');
		const paymentName = paymentNameHtmlContent.value;
		const selectedPaymentId = document.querySelector(this.getSelectors().selectedPaymentId);
		const submitButton = document.querySelector(this.getSelectors().submitButton);
        const invoiceId = document.querySelector(this.getSelectors().invoiceId);
        const paymentRadioButton = document.querySelectorAll(this.getSelectors().paymentRadioButton);
        
		this._createScript(() => {
            document.getElementById('novalnet-payment');
        });
        
        if( selectedPaymentId !== undefined && selectedPaymentId !== null && invoiceId !== undefined && selectedPaymentId.value === invoiceId.value )
        {
			document.getElementById("novalnetinvoiceguarantee-payment").style.display = "block";
		}
		
        // Submit handler
        submitButton.addEventListener('click', (event) => {
			
			const selectedPaymentId = document.querySelector(this.getSelectors().selectedPaymentId);
			const config	= JSON.parse(document.getElementById('novalnetinvoiceguarantee-payment').getAttribute('data-novalnetinvoiceguarantee-payment-config'));
			const dob		= document.getElementById('novalnetinvoiceguaranteeDob');
			
			if( invoiceId.value !== undefined && invoiceId.value !== '' && selectedPaymentId.value === invoiceId.value )
            {
				
				if ( paymentName === 'novalnetinvoiceguarantee' && ( dob === undefined || dob.value === '' ) )
				{	
					if( document.getElementById('novalnetinvoiceId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 )
					{
						selectedPaymentId.value = document.getElementById('novalnetinvoiceId').value;
						document.getElementById('doForceInvoicePayment').value = 1;
					}
					else
					{	
						this.preventForm(dob, 'novalnetinvoiceguarantee', config.text.dobEmpty);
					}
						
				} else if ( paymentName === 'novalnetinvoiceguarantee' && dob !== undefined && dob.value !== '' )
				{
					const age = this.validateAge(dob.value);
					
					if ( age < 18 && config.forceGuarantee != undefined && config.forceGuarantee != 1 )
					{
						this.preventForm(dob, 'novalnetinvoiceguarantee', config.text.dobInvalid);
					}
					else if( age < 18 && document.getElementById('novalnetinvoiceId') !== undefined && config.forceGuarantee != undefined && config.forceGuarantee == 1 )
					{
						selectedPaymentId.value = document.getElementById('novalnetinvoiceId').value;
						document.getElementById('doForceInvoicePayment').value = 1;
					}
				}
			}
		});
		
		// Show/hide the payment form based on the payment selected
        paymentRadioButton.forEach((element) => {
            element.addEventListener('click', () => {
                this.showPaymentForm(element);
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
            invoiceId: '#novalnetinvoiceguaranteeId',
            selectedPaymentId: '#confirmPaymentForm input[name=paymentMethodId]:checked',
            submitButton: '#confirmPaymentForm button[type="submit"]',
            paymentRadioButton: '#confirmPaymentForm input[name="paymentMethodId"]'
        };
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
	
	showPaymentForm( el ) {
		
		const invoiceId = document.querySelector(this.getSelectors().invoiceId);
		
		if( invoiceId !== undefined && invoiceId.value !== '' && el.value === invoiceId.value )
        {
			document.getElementById("novalnetinvoiceguarantee-payment").style.display = "block";
		} else {
			document.getElementById("novalnetinvoiceguarantee-payment").style.display = "none";
		}
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
}
