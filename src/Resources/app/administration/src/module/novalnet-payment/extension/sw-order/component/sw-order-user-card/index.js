import template from './sw-order-user-card.html.twig';


/**
 * @package customer-order
 */

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;


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
				if (this.currentOrder =='') {
                    return;
                } 
              
                if(this.currentOrder.transactions.last().paymentMethod.translated.distinguishableName == 'Novalnet Payment'){

					this.NovalPaymentApiCredentialsService.getNovalnetPaymentMethod(this.currentOrder.orderNumber).then((payment) => {
						
							if(payment != '' && payment != undefined)
							{
								if(payment.paymentName) {
									
									if(payment.paymentName != null && payment.paymentName !='' ){
										this.paymentMethod = payment.paymentName;
									}
									else {
										this.paymentMethod = 'Novalnet Payment';
									}
								}
							}
							else {
								this.paymentMethod = 'Novalnet Payment';
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
