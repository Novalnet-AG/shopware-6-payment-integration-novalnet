import template from './sw-order-create-details-payment.twig';


/**
 * @package customer-order
 */
const { Component, Mixin, Filter, Context } = Shopware;
const Criteria = Shopware.Data.Criteria;
const { currency } = Shopware.Utils.format;


Component.register('sw-order-create-details-payment', {
    template,

    inject: [
        'NovalPaymentApiCredentialsService',
        'repositoryFactory',
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
       customer: {
            type: Object,
        },

        cartPrice: {
            type: Object,
        },
      
        currency: {
            type: Object,
        },
        isLoading: {
            type: Boolean,
            required: true,
        }
    },

    data() {
        return {
           isLoading : false,
			loaded : false,
			shouldDisable : true,
			iframe: {
				src: ''
			},
			paymentformurl : '',
			novalnetPayment : false
			
        };
    },
	
	watch: {
		
		customer: {
            deep: true,
            handler() {
				
				if(this.customer == null){
					return;
				}
					
				const paymentRepository = this.repositoryFactory.create('payment_method');
				const paymentCriteria = new Criteria(1, 1);
				paymentCriteria.addFilter(Criteria.equals('id', this.customer.salesChannel.paymentMethodId));
				paymentRepository.search(paymentCriteria, Context.api).then((searchResult) => {
					const payment = searchResult.first();
					if(!payment){
						return
					}
					
					if( (payment.customFields != null) && (payment.customFields.novalnet_payment_method_name  == 'novalnetpay')){
						
						if(this.currency == null){
							this.createNotificationError({
								title: this.$tc('novalnet-payment.settingForm.titleError'),
								message: this.$tc('novalnet-payment.settingForm.currencyFailureMessage')
							});

							return;
						}
						
						if(this.cartPrice != null &&  (this.cartPrice.totalPrice == 0 || this.cartPrice.totalPrice == null) ){
							this.createNotificationError({
								title: this.$tc('novalnet-payment.settingForm.titleError'),
								message: this.$tc('novalnet-payment.settingForm.lineitemFailureMessage')
							});

							return;
						}
							
						this.novalnetPayment = true;
						
						let billingaddress = '';
						let shippingaddress = '';
						
						if( (this.customer.billingAddress !== null) || (this.customer.defaultBillingAddress !== null)){
							
							billingaddress = this.customer.billingAddress ? this.customer.billingAddress : this.customer.defaultBillingAddress;
						}
							
						if( (this.customer.shippingAddress !== null)  || (this.customer.defaultShippingAddress !== null)){
							
							shippingaddress = this.customer.shippingAddress ? this.customer.shippingAddress : this.customer.defaultShippingAddress;
						}
						let me = this.NovalPaymentApiCredentialsService;
						let customerPaymentDetails = this.customer;

						this.NovalPaymentApiCredentialsService.novalnetPayment(shippingaddress, billingaddress, this.cartPrice.totalPrice , this.currency.isoCode, this.customer ).then((payment) => {
						
							if(payment != '' && payment != undefined)
							{
								if(payment.result.status =='SUCCESS' && payment.result.redirect_url != '' && payment.result.redirect_url != undefined ){
									
									 this.iframe.src = payment.result.redirect_url;
									 
									 this.loaded = true;
										const recaptchaScript = document.createElement('script');
										recaptchaScript.setAttribute('src', 'https://cdn.novalnet.de/js/pv13/checkout.js?' + new Date().getTime());
										recaptchaScript.type = 'text/javascript'; 
										document.head.appendChild(recaptchaScript); 
										this.paymentformurl = recaptchaScript;
										this.paymentformurl.addEventListener('load', ()=>{
												this.onWindowLoad(me, customerPaymentDetails);
										} );
								}
							}
								
						}).catch((errorResponse) => {
							this.createNotificationError({
								message: `${errorResponse.title}: ${errorResponse.message}`
							});
						});
					}
				
				});
			},
			immediate: true
		
		}
		
	},
	
	
	methods: {
		onWindowLoad(e, customer) {
			const paymentForm = new NovalnetPaymentForm();
			const submit = document.querySelector('.sw-button-process');
			const keyname = 'ordernovalnetpayment';
			let paymentType = '';
				let request = { 
					iframe: '#adminnovalnetPaymentiframe', 
					initForm: { 
						uncheckPayments: true, 
						showButton: false, 
						} 
				};
		
			paymentForm.initiate(request); 		
			paymentForm.validationResponse((data) => { 
				paymentForm.initiate(request); 
			});
			
			paymentForm.selectedPayment((function(selectPaymentData) {
				paymentType = selectPaymentData.payment_details.type;
				
			})); 
			
			submit.addEventListener('click', (event) => {
				event.preventDefault();
                event.stopImmediatePropagation();
                paymentForm.getPayment((function(paymentDetails) {
					let value = JSON.stringify(paymentDetails);
					e.paymentValue(value, customer).then((payment) => {
					});
				}));   
			});	
			
		},
		
	},
   

});
