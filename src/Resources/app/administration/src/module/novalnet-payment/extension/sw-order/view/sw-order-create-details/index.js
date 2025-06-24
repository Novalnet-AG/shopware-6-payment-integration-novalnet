import template from './sw-order-create-details.html.twig';

const { Component, Store, Mixin, Context } = Shopware;
const Criteria = Shopware.Data.Criteria;

Component.override('sw-order-create-details', {
    template,

    inject: [
        'NovalPaymentApiCredentialsService',
        'repositoryFactory',
        'acl',
    ],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            loaded : false,
            shouldDisable : true,
            iframe: {
                src: ''
            },
            paymentformurl : '',
            novalnetPayment : false
        };
    },
    computed: {
		
		customer() {
            return Store.get('swOrder').customer;
        },
        cart() {
            return Store.get('swOrder').cart;
        },
        currency(){
            return Store.get('swOrder').context.currency;
        },
        cartPrice() {
            return this.cart.price;
        },
        salesChannelContext(){
            return Store.get('swOrder').context;
        },
        
        salesChannelId() {
            return this.salesChannelContext?.salesChannel.id || '';
        },
    },

    watch: {
        salesChannelContext: {
            deep: true,
            handler() {
                if (!this.customer || !this.isCartTokenAvailable) {
                    return;
                }

                this.isLoading = true;
                const paymentRepository = this.repositoryFactory.create('payment_method');
                const paymentCriteria = new Criteria(1, 1);
                paymentCriteria.addFilter(Criteria.equals('id', this.salesChannelContext.paymentMethod.id));
                paymentRepository.search(paymentCriteria, Context.api).then((searchResult) => {
                    const payment = searchResult.first();
                    if (!payment) {
                        return
                    }

                    this.novalnetPayment = false;
                    if ((payment.customFields != null) && (payment.customFields.novalnet_payment_method_name  == 'novalnetpay')) {
                        if (this.currency == null) {
                            this.createNotificationError({
                                title: this.$tc('novalnet-payment.settingForm.titleError'),
                                message: this.$tc('novalnet-payment.settingForm.currencyFailureMessage')
                            });

                            return;
                        }

                        if (this.cartPrice != null &&  (this.cartPrice.totalPrice == 0 || this.cartPrice.totalPrice == null)) {
                            this.createNotificationError({
                                title: this.$tc('novalnet-payment.settingForm.titleError'),
                                message: this.$tc('novalnet-payment.settingForm.lineitemFailureMessage')
                            });

                            return;
                        }

                        this.novalnetPayment = true;
                        let billingaddress = '';
                        let shippingaddress = '';

                        if(this.salesChannelContext.customer.defaultBillingAddress !== null) {
							billingaddress = this.salesChannelContext.customer.defaultBillingAddress;
						} else if (this.context.billingAddressId != null) {
							this.customer.addresses.forEach(value => {
								 if (value.id == this.context.billingAddressId) {
									 billingaddress = value;
								 }
							 });
						}
						
						if(this.salesChannelContext.customer.defaultShippingAddress !== null) {
							shippingaddress = this.salesChannelContext.customer.defaultShippingAddress;
						} else if (this.context.shippingAddressId != null) {
							this.customer.addresses.forEach(value => {
								 if (value.id == this.context.shippingAddressId) {
									 billingaddress = value;
								 }
							 });
						}
                        
                        let me = this.NovalPaymentApiCredentialsService;
                        let customerPaymentDetails = this.customer;
                        this.NovalPaymentApiCredentialsService.novalnetPayment(shippingaddress, billingaddress, this.cartPrice.totalPrice , this.currency.isoCode, this.customer ).then((payment) => {
                            if (payment != '' && payment != undefined) {
                                if (payment.result.status =='SUCCESS' && payment.result.redirect_url != '' && payment.result.redirect_url != undefined) {
                                    this.iframe.src = payment.result.redirect_url;
                                    this.loaded = true;
                                    const recaptchaScript = document.createElement('script');
                                    recaptchaScript.setAttribute('src', 'https://cdn.novalnet.de/js/pv13/checkout.js?' + new Date().getTime());
                                    recaptchaScript.type = 'text/javascript';
                                    document.head.appendChild(recaptchaScript);
                                    this.paymentformurl = recaptchaScript;
                                    this.paymentformurl.addEventListener('load', ()=> {
										 document.querySelector('.sw-button-process').disabled = false;
                                        this.onWindowLoad(me, customerPaymentDetails);
                                    });
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
        },

        customer: {
            deep: true,
            handler() {
                if (this.customer == null) {
                    return;
                }
            },
            immediate: true
        }
    },

    methods: {
        onWindowLoad(e, customer) {
            const paymentForm = new NovalnetPaymentForm();
            const submit = document.querySelector('.sw-button-process');
        
            let request = {
                iframe: '#adminnovalnetPaymentiframe',
                initForm: {
                    uncheckPayments: false,
                    showButton: false,
				}
            };

            paymentForm.initiate(request);
            paymentForm.validationResponse(() => {
                paymentForm.initiate(request);
            });

            submit.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopImmediatePropagation();
                paymentForm.getPayment((function(paymentDetails) {
                    let value = JSON.stringify(paymentDetails);
                    e.paymentValue(value, customer).then(() => {
                    });
                }));
            });
        }
    },
});
