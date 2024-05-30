import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import DomAccess from 'src/helper/dom-access.helper';
import ButtonLoadingIndicator from 'src/utility/loading-indicator/button-loading-indicator.util';

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
		
		const selectedPaymentId  = document.querySelector('input[name=paymentMethodId]:checked'),
		      paymentRadioButton = document.querySelectorAll('input[name="paymentMethodId"]'),
		      googlePayPaymentId = document.querySelector('#novalnetgooglepayId'),
		      applePayPaymentId  = document.querySelector('#novalnetapplepayId');
		
		if (selectedPaymentId !== undefined && selectedPaymentId !== null && googlePayPaymentId !== undefined && googlePayPaymentId !== null && googlePayPaymentId.value === selectedPaymentId.value)
		{
			this.showButton('novalnetgooglepay', true);
		}
		
		if (selectedPaymentId !== undefined && selectedPaymentId !== null && applePayPaymentId !== undefined && applePayPaymentId !== null && applePayPaymentId.value === selectedPaymentId.value)
		{
			this.showButton('novalnetapplepay', true);
		}
		
		if (paymentRadioButton !== undefined && paymentRadioButton !== null) 
		{
			// Show/hide the payment form based on the payment selected
			paymentRadioButton.forEach((payment) => {
				payment.addEventListener('click', () => {
					const selectedPaymentId  = document.querySelector('input[name=paymentMethodId]:checked');
					if (selectedPaymentId !== undefined && selectedPaymentId !== null && googlePayPaymentId !== undefined && googlePayPaymentId !== null && googlePayPaymentId.value === selectedPaymentId.value)
					{
						this.showButton('novalnetapplepay', false);
						this.showButton('novalnetgooglepay', true);
					} else if (selectedPaymentId !== undefined && selectedPaymentId !== null && applePayPaymentId !== undefined && applePayPaymentId !== null && applePayPaymentId.value === selectedPaymentId.value) 
					{
						this.showButton('novalnetgooglepay', false);
						this.showButton('novalnetapplepay', true);
					} else 
					{
						this.showButton('novalnetgooglepay', false);
						this.showButton('novalnetapplepay', false);
					}
				});
			});
		}
	}
	
	showButton (paymentName, hideDefault) {
		if (hideDefault)
		{
			var displayType = 'block';
			var changePaymentForm = 'none';
		} else {
			var displayType = 'none';
			var changePaymentForm = 'inline';
		}
		
		if (document.getElementById(paymentName + "-payment") != undefined && document.getElementById(paymentName + "-payment") != null)
		{
			document.getElementById(paymentName + "-payment").style.display = displayType;
		}
			
		if (document.querySelector('#novalnetchangePaymentForm button[class="btn btn-primary"]') != undefined && document.querySelector('#novalnetchangePaymentForm button[class="btn btn-primary"]') != null)
		{
			document.querySelector('#novalnetchangePaymentForm button[class="btn btn-primary"]').style.display = changePaymentForm;
		} else if(document.getElementById(paymentName + "ZeroAmountNotify") != undefined && document.getElementById(paymentName + "ZeroAmountNotify") != null)
		{
			document.getElementById(paymentName + "ZeroAmountNotify").style.display = displayType;
		}
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
									var paymentForm = DomAccess.querySelector(document, '#confirmOrderForm');

									if(element != undefined && element.className == 'nn-apple-pay-button')
									{
										bookingResult({status: "SUCCESS", statusText: ""});
										document.getElementById('novalnetapplepay-wallet-token').value = response.transaction.token;
										paymentForm.submit();
									} else {
										document.getElementById('novalnetgooglepay-wallet-token').value = response.transaction.token;
										document.getElementById('novalnetgooglepay-do-redirect').value  = response.transaction.doRedirect;

										if (response.transaction.doRedirect && response.transaction.doRedirect != false)
										{
											const successUrl = DomAccess.getAttribute(element, 'data-success-url');
											const paymentId  = DomAccess.getAttribute(element, 'data-paymentMethodId');
											const orderId    = DomAccess.getAttribute(element, 'data-orderId');
											const payLoad = { serverResponse : response, paymentMethodId : paymentId, paymentName : paymentName, orderId : orderId };
											
											client.post(successUrl, JSON.stringify(payLoad), (res) => {
												const novalnetResponse = JSON.parse(res);
												if(novalnetResponse.success == true)
												{
													bookingResult({status: "SUCCESS", statusText: ""});
													window.location.replace(novalnetResponse.url);
												} else {
													window.location.reload();
												}
											});
										} else {
											bookingResult({status: "SUCCESS", statusText: ""});
											paymentForm.submit();
										}
									}
									return true;
								} else if (DomAccess.getDataAttribute(element, 'data-page-type') == 'changePayment') 
								{
									var paymentForm = document.querySelector('#novalnetchangePaymentForm');
									
									if(element != undefined && element.className == 'nn-apple-pay-button') 
									{
										var paymentData = { wallet_token: response.transaction.token,   paymentName: 'applepay', parentOrderNumber: document.getElementById('parentOrderNumber').value, aboId: document.getElementById('aboId').value, paymentMethodId: document.getElementById('novalnetapplepayId').value };
									} else {
										var paymentData = { wallet_token: response.transaction.token,  doRedirect: response.transaction.doRedirect, paymentName: 'googlepay', parentOrderNumber: document.getElementById('parentOrderNumber').value, aboId:document.getElementById('aboId').value, paymentMethodId: document.getElementById('novalnetgooglepayId').value };
									}
									
									client.post(document.getElementById('storeCustomerDataUrl').value, JSON.stringify(paymentData), response => {
										const res = JSON.parse(response);
										if(res.success == true && res.redirect_url != undefined)
										{
											window.location.replace(res.redirect_url);
										} else if(res.success == true)
										{
											paymentForm.submit();
										} else {
											bookingResult({status: "FAILURE", statusText: res.message});
										}
									});	
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
										} else if (response.success == false) {
											bookingResult({status: "FAILURE", statusText: response.message});
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
					
					onCouponCodeChange: function(couponCode, couponHandledResult) {

						let couponInformationToUpdate = {};
						
						const requestUrl = DomAccess.getAttribute(element, 'data-couponCodeUpdate-url');
						const payLoad    = { couponCode : couponCode };
						
						return new Promise((resolve, reject) => {
							client.post(requestUrl, JSON.stringify(payLoad), (response, request) => {
								try {
									response = JSON.parse(response);
									resolve(response);
								} catch (error) {
									reject(error);
									couponHandledResult({status: "FAILURE"});
								}
							});
						}).then(function (result) {
							if(result.status == 'success'){
								couponHandledResult({
									amount: result.totalPrice,
									lineItems: result.lineItem
								});
							} else {
								couponHandledResult({
									error: 'INVALID',
									errorText : 'The coupon code entered seems to be invalid or expired'
								});
							}
                           
                        });
					},				  					
					
					onPaymentButtonClicked: function(clickResult) {
						var tosInput = document.querySelector('#tos');

						if(tosInput != undefined && !tosInput.checked && DomAccess.getDataAttribute(element, 'data-page-type') == 'checkoutPage')
						{
							var paymentForm = DomAccess.querySelector(document, '#confirmOrderForm');
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
			} else {
				if(element.className == 'nn-apple-pay-button' && document.querySelectorAll('.nn-apple-pay-error-message') !== undefined && document.querySelectorAll('.nn-apple-pay-error-message') !== null)
				{
					if (document.querySelector('input[name=paymentMethodId]:checked') != null && document.querySelector('input[name=paymentMethodId]:checked').closest('.payment-method') != null)
					{
						document.querySelector('input[name=paymentMethodId]:checked').closest('.payment-method').style.display = 'none';
					}
				}
			}			
		});
	}
	
	_displayErrorMsgSubs(errorMessage) {
		const elements = document.getElementsByClassName('alert alert-danger alert-has-icon');
		while(elements.length > 0){
			elements[0].parentNode.removeChild(elements[0]);
		}
		
        var formElement = document.getElementById('novalnetchangePaymentForm');
        let parentDiv  = document.createElement('div');
        let childDiv1  = document.createElement('div');
        let childDiv2  = document.createElement('div');
        let spanTag    = document.createElement('span');
        parentDiv.className= "alert alert-danger alert-has-icon";childDiv1.className= "alert-content-container";childDiv2.className= "alert-content";spanTag.className= "icon icon-blocked";
        spanTag.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24" height="24" viewBox="0 0 24 24"><defs><path d="M12 24C5.3726 24 0 18.6274 0 12S5.3726 0 12 0s12 5.3726 12 12-5.3726 12-12 12zm0-2c5.5228 0 10-4.4772 10-10S17.5228 2 12 2 2 6.4772 2 12s4.4772 10 10 10zm4.2929-15.7071c.3905-.3905 1.0237-.3905 1.4142 0 .3905.3905.3905 1.0237 0 1.4142l-10 10c-.3905.3905-1.0237.3905-1.4142 0-.3905-.3905-.3905-1.0237 0-1.4142l10-10z" id="icons-default-blocked"></path></defs><use xlink:href="#icons-default-blocked" fill="#758CA3" fill-rule="evenodd"></use></svg>';
        parentDiv.appendChild(spanTag);parentDiv.appendChild(childDiv1);childDiv1.appendChild(childDiv2);
        childDiv2.innerHTML = errorMessage;
        
        formElement.parentNode.insertBefore(parentDiv, formElement);
        parentDiv.scrollIntoView();

    }

}
