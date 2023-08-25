import template from './sw-order-general-info.html.twig';


/**
 * @package customer-order
 */

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;


Component.override('sw-order-general-info', {
    template,

    inject: [
        'NovalPaymentApiCredentialsService',
        'repositoryFactory',
    ],

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        order: {
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
		
		order: {
            deep: true,
            handler() {
				if (this.order =='') {
                    return;
                } 
              
                if(this.order.transactions.last().paymentMethod.translated.distinguishableName == 'Novalnet Payment'){

					this.NovalPaymentApiCredentialsService.getNovalnetPaymentMethod(this.order.orderNumber).then((payment) => {
						
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
					
					this.paymentMethod = this.order.transactions.last().paymentMethod.translated.distinguishableName;
				}
			},
			immediate: true
		}
	}
    

   

});
