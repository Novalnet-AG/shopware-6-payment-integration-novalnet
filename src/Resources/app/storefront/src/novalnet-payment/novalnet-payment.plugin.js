import Plugin from 'src/plugin-system/plugin.class';

export default class NovalnetPayment extends Plugin {
    
    init() {
		
        this._createScript(() => {	
			const container = document.getElementById('novalnet-payment');
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
			paymentName: {
				radioInputChecked: '#confirmPaymentForm input[type="radio"].' + paymentName + '-SavedPaymentMethods-tokenInput:checked',
			}
        };
    }
    
    getPaymentName(containerId)
    {
		const paymentName = containerId.split('-payment');
		return paymentName[0];
	}
	
	showComponents(el, paymentName) {
        
            if ( el.value !== 'new' ) {
				document.getElementById(paymentName + "-payment-form").classList.add("nnhide");
				
            } else {
				document.getElementById(paymentName + "-payment-form").classList.remove("nnhide");
            }
    }
}
