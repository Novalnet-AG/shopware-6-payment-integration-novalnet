import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import DomAccess from 'src/helper/dom-access.helper';

export default class NovalnetApplePayPayment extends Plugin {

	init() {
		const client = new HttpClient();
		this._createScript(() => {
			const elements = DomAccess.querySelectorAll(document, '.nn-apple-pay-button'),
				  isApplePayAvailable = NovalnetUtility.isApplePayAllowed();
			
			if (isApplePayAvailable != undefined && isApplePayAvailable == true) {
				if(document.querySelectorAll('.nn-apple-pay-error-message') !== undefined && document.querySelectorAll('.nn-apple-pay-error-message') !== null)
				{
					document.querySelectorAll('.nn-apple-pay-error-message').forEach(function(message) {
					  message.remove();
					});
				}
				elements.forEach((element) => {
					var config = DomAccess.getDataAttribute(element, 'data-novalnetapplepay-payment-config', false),
					    pageType = DomAccess.getDataAttribute(element, 'data-novalnetapplepay-page-type', false);
						element.style.display = 'block';
						element.style.height = config.settings.buttonHeight + 'px';
						element.style.borderRadius = config.settings.buttonRadius + 'px';
						element.classList.add(config.settings.buttonType);
						element.classList.add(config.settings.buttonTheme);
						  
						  element.addEventListener('click', (event) => {
							event.preventDefault();
							event.stopImmediatePropagation();
							if(pageType == 'productDetailPage' || pageType == 'productListingPage')
							{
								var quantity = 1;
								const productId = DomAccess.querySelector(element, '#productId').value,
								      quantityObj = document.querySelector('.product-detail-quantity-select'),
								      addToCartUrl = DomAccess.getDataAttribute(element, 'data-novalnetapplepay-addToCartUrl', false);
								
								if(quantityObj != undefined)
								{
									quantity = quantityObj.value;
								}
								
								client.post(addToCartUrl, JSON.stringify({productId, quantity, type: 'product'}), (res) => {
									const response = JSON.parse(res);
								
									if(response.success == false)
									{
										window.location.replace(response.url);
									}
								});
							}
							
							this._loadApplePayForm(config, event);
					    });
				});
			}
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
    
    _loadApplePayForm(config, event) {
		// Set Your Client Key
		NovalnetUtility.setClientKey(config.clientKey);

		const targetElement = event.target || event.srcElement,
			  shopVersion = DomAccess.querySelector(document, '#nnShopVersion');
		
		if (DomAccess.getAttribute(targetElement, 'data-novalnetapplepay-page-type') != undefined && ((DomAccess.getAttribute(targetElement, 'data-novalnetapplepay-page-type') != 'checkoutPage' && DomAccess.getAttribute(targetElement, 'data-novalnetapplepay-customer-login') != undefined && !DomAccess.getAttribute(targetElement, 'data-novalnetapplepay-customer-login')) || DomAccess.getAttribute(targetElement, 'data-novalnetapplepay-page-type') == 'productListingPage' || DomAccess.getAttribute(targetElement, 'data-novalnetapplepay-page-type') == 'productDetailPage'))
		{
			var requiredFields = {shipping : ['postalAddress', 'email', 'name', 'phone'], contact: ['postalAddress']};
		} else {
			var requiredFields = {};
		}
		
		// Preparing the Apple request
		var requestData = {
			transaction: config.transaction,
			merchant: config.merchant,
            custom: config.custom,
			wallet: {
				shop_name: config.wallet.shop_name,
				order_info: config.wallet.order_info,
				shipping_methods: config.wallet.shipping_methods,
				required_fields: requiredFields,
				shipping_configuration:
				{
					type: 'shipping',
					calc_final_amount_from_shipping : '0'
				},
			},
			callback: {
				on_completion: function (responseData, processedStatus) 
				{
					// Handle response here and setup the processedStatus
					if (responseData.result && responseData.result.status) {
						// Only on success, we proceed further with the booking
						if (responseData.result.status == 'SUCCESS') {
							if(DomAccess.getAttribute(targetElement, 'data-novalnetapplepay-page-type') == 'checkoutPage')
							{
								if(shopVersion != undefined && shopVersion.value >= '6.4')
								{
									var paymentForm = DomAccess.querySelector(document, '#confirmOrderForm');
								} else {
									var paymentForm = DomAccess.querySelector(document, '#confirmPaymentForm');
								}
								document.getElementById('novalnetapplepay-wallet-token').value = responseData.transaction.token;
								paymentForm.submit();
								return true;
							} else {
								const successUrl = DomAccess.getAttribute(targetElement, 'data-novalnetapplepay-success-url');
								const client = new HttpClient();
								client.post(successUrl, JSON.stringify(responseData), (res) => {
									const response = JSON.parse(res);
									
									if(response.success == true)
									{
										window.location.replace(response.url);
									} else {
										window.location.reload();
									}
								});
							}
						} else {
							// Upon failure, displaying the error text
							if (responseData.result.status_text) {
								alert(responseData.result.status_text);
							}
						}
					}
				},
				on_shippingcontact_change: function (shippingContact, updatedData) {
					const requestUrl = DomAccess.getAttribute(targetElement, 'data-novalnetapplepay-shipping-url');
					const client = new HttpClient();
					client.post(requestUrl, JSON.stringify(shippingContact), (res) => {
						const response = JSON.parse(res);
						if(response)
						{
							var updatedInfo = {
								amount: response.totalPrice,
								order_info: response.lineItem,
								shipping_methods: response.shipping
							};
							updatedData(updatedInfo);
						} else {
							updatedData('ERROR');
						}
					});
				},
				on_shippingmethod_change: function (choosenShippingMethod, updatedData) {
					const payload = {shippingInfo : choosenShippingMethod, shippingMethodChange : '1'};
					const requestUrl = DomAccess.getAttribute(targetElement, 'data-novalnetapplepay-shipping-url');
					const client = new HttpClient();
					client.post(requestUrl, JSON.stringify(payload), (res) => {
						const response = JSON.parse(res);
						if(response)
						{
							var updatedInfo = {
								amount: response.totalPrice,
								order_info: response.lineItem,
							};
							updatedData(updatedInfo);
						} else {
							updatedData('ERROR');
						}
					});
				}
			}
		};
		// Setting up the payment request to initiate the Apple Payment sheet
		NovalnetUtility.processApplePay(requestData);
	}
}
