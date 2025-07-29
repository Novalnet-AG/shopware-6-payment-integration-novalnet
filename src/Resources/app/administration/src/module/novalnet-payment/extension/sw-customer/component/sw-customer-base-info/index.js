import template from './sw-customer-base-info.html.twig';


/**
 * @package customer-order
 */

const { Component, Mixin } = Shopware;


Component.override('sw-customer-base-info', {
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
            required: true,
        },
    },

    data() {
        return {
           paymentMethod : ''
        };
    },
	
	watch: {
		
		customer: {
            deep: true,
            handler() {
				if (this.customer == '') {
                    return;
                }

				if(this.customer.defaultPaymentMethod.customFields != null  && this.customer.defaultPaymentMethod.customFields.novalnet_payment_method_name != undefined && this.customer.defaultPaymentMethod.customFields.novalnet_payment_method_name == "novalnetpay"){
					this.NovalPaymentApiCredentialsService.getCustomerPaymentMethod(this.customer.customerNumber).then((paymentDetails) => {
						if(paymentDetails != undefined && paymentDetails != null)
						{	
							if(paymentDetails.paymentName != undefined && paymentDetails.paymentName != null){
								this.paymentMethod = paymentDetails.paymentName;
							}
							else {
								this.paymentMethod = this.customer.defaultPaymentMethod.translated.distinguishableName;
							}
						}
						else {
							this.paymentMethod = this.customer.defaultPaymentMethod.translated.distinguishableName;
						}
					}).catch((errorResponse) => {
						this.createNotificationError({
							message: `${errorResponse.title}: ${errorResponse.message}`
						});
					});
					
				} else {
					this.paymentMethod = this.customer.defaultPaymentMethod.translated.distinguishableName;
				}

			},
			immediate: true
		}
	}
    

   

});
