import template from './sw-order-user-card.html.twig';


/**
 * @package customer-order
 */

const { Component, Mixin } = Shopware;

Component.override('sw-order-user-card', {
    template,

    inject: [
        'NovalPaymentApiCredentialsService',
        'repositoryFactory',
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        currentOrder: {
            type: Object,
            required: true,
        },
        isLoading: {
            type: Boolean,
            required: true,
        }
    },

    data() {
        return {
           paymentMethod : ''
        };
    },
	
	watch: {
		
		currentOrder: {
            deep: true,
            handler() {
				if (this.currentOrder == '') {
                    return;
                }
             
                if (this.currentOrder.transactions.last().paymentMethod.customFields != null && this.currentOrder.transactions.last().paymentMethod.customFields.novalnet_payment_method_name != undefined && this.currentOrder.transactions.last().paymentMethod.customFields.novalnet_payment_method_name == "novalnetpay"){

					this.NovalPaymentApiCredentialsService.getNovalnetPaymentMethod(this.currentOrder.orderNumber).then((payment) => {
							if(payment != undefined && payment != null)
							{	
								if(payment.paymentName != undefined && payment.paymentName != null){
									this.paymentMethod = payment.paymentName;
								}
								else {
									this.paymentMethod = this.currentOrder.transactions.last().paymentMethod.translated.distinguishableName;
								}
							}
							else {
								this.paymentMethod = this.currentOrder.transactions.last().paymentMethod.translated.distinguishableName;
							}

					}).catch((errorResponse) => {
						this.createNotificationError({
							message: `${errorResponse.title}: ${errorResponse.message}`
						});
					});
				}
				else {
					this.paymentMethod = this.currentOrder.transactions.last().paymentMethod.translated.distinguishableName;
				}
			},
			immediate: true
		}
	}
    

   

});
