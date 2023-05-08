import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import DomAccess from 'src/helper/dom-access.helper';

export default class NovalnetGooglePayPayment extends Plugin {

	init() {
		const client = new HttpClient();
		this._createScript(() => {
			try {
				const elements = DomAccess.querySelectorAll(document, '[data-wallet-payments]');
				this._loadWalletPaymentForm();
			} catch (e) {
				// Handling the errors from the payment intent setup
				console.log(e.message);
			}
		});
	}
	
	_createScript(callback) {
        const url = 'https://cdn.novalnet.de/js/v3/payment.js';
		const script = document.createElement('script');
		script.type = 'text/javascript';
		script.src = url;
		script.addEventListener('load', callback.bind(this), false);
		document.head.appendChild(script);
    }
    
    _loadWalletPaymentForm() {
		// Loading the payment instances
		var novalnetPaymentObj = NovalnetPayment().createPaymentObject(),
		    element = this.el,
		    walletConfiguration = DomAccess.getDataAttribute(this.el, 'data-payment-config', false),
		    shopVersion = DomAccess.querySelector(document, '#nnShopVersion'),
		    client = new HttpClient();
		
		if (element != undefined && (element.className == 'nn-apple-pay-button' || element.className.indexOf('nn-apple-pay-button') >= 0))
		{
			var paymentName = 'novalnetapplepay';
		} else {
			var paymentName = 'novalnetgooglepay';
		}

		var paymentIntent = {
			clientKey: walletConfiguration.clientKey,
			paymentIntent: {
				merchant: walletConfiguration.merchant,
				transaction: walletConfiguration.transaction,
				order: walletConfiguration.order,
				custom: walletConfiguration.custom,
				button: walletConfiguration.button,
				callbacks: {
					onProcessCompletion: function(response, bookingResult) {
						// Handle response here and setup the bookingresult
						if (response.result && response.result.status) {
							// Only on success, we proceed further with the booking   
							if (response.result.status == 'SUCCESS') {
								// Sending the token and amount to Novalnet server for the booking
								if (DomAccess.getDataAttribute(element, 'data-page-type') == 'checkoutPage')
								{
									bookingResult({status: "SUCCESS", statusText: ""});
									if(shopVersion != undefined && shopVersion.value >= '6.4')
									{
										var paymentForm = DomAccess.querySelector(document, '#confirmOrderForm');
									} else {
										var paymentForm = DomAccess.querySelector(document, '#confirmPaymentForm');
									}
									
									if(element != undefined && element.className == 'nn-apple-pay-button')
									{
										document.getElementById('novalnetapplepay-wallet-token').value = response.transaction.token;
										paymentForm.submit();
									} else {
										document.getElementById('novalnetgooglepay-wallet-token').value = response.transaction.token;
										document.getElementById('novalnetgooglepay-do-redirect').value  = response.transaction.doRedirect;
										
										if (response.transaction.doRedirect && response.transaction.doRedirect != false)
										{
											const successUrl = DomAccess.getAttribute(element, 'data-success-url');
											const paymentId  = DomAccess.getAttribute(element, 'data-paymentMethodId');
											const payLoad = { serverResponse : response, paymentMethodId : paymentId, paymentName : paymentName };
											client.post(successUrl, JSON.stringify(payLoad), (res) => {
												const response = JSON.parse(res);
												if(response.success == true)
												{
													bookingResult({status: "SUCCESS", statusText: ""});
													window.location.replace(response.url);
												} else {
													window.location.reload();
												}
											});
										} else {
											paymentForm.submit();
										}
									}
									return true;
								} else {
									const successUrl = DomAccess.getAttribute(element, 'data-success-url');
									const paymentId  = DomAccess.getAttribute(element, 'data-paymentMethodId');
									const payLoad = { serverResponse : response, paymentMethodId : paymentId, paymentName : paymentName };
									client.post(successUrl, JSON.stringify(payLoad), (res) => {
										const response = JSON.parse(res);
										if(response.success == true)
										{
											bookingResult({status: "SUCCESS", statusText: ""});
											window.location.replace(response.url);
										} else {
											window.location.reload();
										}
									});
								}
							} else {
								bookingResult({status: "FAILURE", statusText: response.result.status_text});
								// Upon failure, displaying the error text 
								if (response.result.status_text) {
									alert(response.result.status_text);
								}
							}
						}
					},
					onShippingContactChange: function(shippingContact, updatedRequestData) {
						const requestUrl = DomAccess.getDataAttribute(element, 'data-shipping-url');
						const paymentId  = DomAccess.getAttribute(element, 'data-paymentMethodId');
						const payLoad = { shippingInfo : shippingContact, paymentMethodId : paymentId };
						return new Promise((resolve, reject) => {
							client.post(requestUrl, JSON.stringify(payLoad), (response, request) => {
								if (request.status >= 400) {
									reject(response);
								}

								try {
									response = JSON.parse(response);
									resolve(response);
								} catch (error) {
									reject(error);
								}
							});
						}).then(function (result) {
                                if (!result.shipping.length) {
                                    updatedRequestData({methodsNotFound :"No Shipping Contact Available, please enter a valid contact"});
                                } else {
                                    updatedRequestData({
                                        amount: result.totalPrice,
                                        lineItems: result.lineItem,
                                        methods: result.shipping,
                                        defaultIdentifier: result.shipping[0].identifier
                                    });
                                }
                        });
					},
					onShippingMethodChange: function(shippingMethod, updatedRequestData) {
						const requestUrl = DomAccess.getAttribute(element, 'data-shippingUpdate-url');
						const payLoad    = { shippingMethod : shippingMethod };
						return new Promise((resolve, reject) => {
							client.post(requestUrl, JSON.stringify(payLoad), (response, request) => {
								if (request.status >= 400) {
									reject(response);
									updatedRequestData({status: "FAILURE"});
								}

								try {
									response = JSON.parse(response);
									resolve(response);
								} catch (error) {
									reject(error);
									updatedRequestData({status: "FAILURE"});
								}
							});
						}).then(function (result) {
                            updatedRequestData({
								amount: result.totalPrice,
								lineItems: result.lineItem
                            });
                        });
					},
					onPaymentButtonClicked: function(clickResult) {
						var tosInput = document.querySelector('#tos');
						
						if(tosInput != undefined && !tosInput.checked && DomAccess.getDataAttribute(element, 'data-page-type') == 'checkoutPage')
						{
							if(shopVersion != undefined && shopVersion.value >= '6.4')
							{
								var paymentForm = DomAccess.querySelector(document, '#confirmOrderForm');
							} else {
								var paymentForm = DomAccess.querySelector(document, '#confirmPaymentForm');
							}
							clickResult({status: "FAILURE"});
							paymentForm.submit();
							return false;
						} else if (DomAccess.getDataAttribute(element, 'data-page-type') == 'productDetailPage' || DomAccess.getDataAttribute(element, 'data-page-type') == 'productListingPage') {
								clickResult({status: "SUCCESS"});
								var quantity = 1;
								const productId = DomAccess.querySelector(element, '#productId').value,
								      quantityObj = document.querySelector('.product-detail-quantity-select'),
								      addToCartUrl = DomAccess.getDataAttribute(element, 'data-addToCartUrl', false);
								
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
						} else {
							clickResult({status: "SUCCESS"});
						}
                    }
				}
			}
		};
		// Setting up the payment intent in your object 
		novalnetPaymentObj.setPaymentIntent(paymentIntent);
		
		// Checking for the payment method availability
		novalnetPaymentObj.isPaymentMethodAvailable(function(displayPaymentButton) {
			if(element === undefined) {return}
			
			if (displayPaymentButton) {
				if(element.className == 'nn-apple-pay-button' && document.querySelectorAll('.nn-apple-pay-error-message') !== undefined && document.querySelectorAll('.nn-apple-pay-error-message') !== null)
				{
					document.querySelectorAll('.nn-apple-pay-error-message').forEach(function(message) {
					  message.remove();
					});
				}
				// Initiating the Payment Request for the Wallet Payment
				novalnetPaymentObj.addPaymentButton("." + element.className);
				if (element.querySelector('button') != null)
				{
					element.querySelector('button').style.width = "100%";
				} else if (element.querySelector('apple-pay-button') != null)
				{
					element.querySelector('apple-pay-button').style.width = "100%";
				}
			}
		});
	}
	
}
