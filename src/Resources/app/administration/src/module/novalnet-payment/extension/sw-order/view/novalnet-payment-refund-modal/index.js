import template from './novalnet-payment-refund-modal.html.twig';

const { Component, Mixin } = Shopware;
const { currency } = Shopware.Utils.format;

Component.register('novalnet-payment-refund-modal', {
	template,
	
	props:{
		
		refundableAmount : {
			type : Number,
			required : true
		},
		
		order: {
			type : Object,
			required : true
		},
		
		item: {
			type : Object,
			required : true
		}
		
	},
	
	inject: [
	
		'NovalPaymentApiCredentialsService',
        'repositoryFactory',
	],
	
	mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('sw-inline-snippet')
    ],
    
    data(){
		return {
			reason: '',
			disable: false,
			refundAmount: this.refundableAmount
		};
	},
	
	 methods: {
		 
		closeModal() {
            this.$emit('modal-close');
        },
        
        novalnetRefund()
        {
            const reason		= this.reason;
            const orderNumber	= this.order.orderNumber;

			if(this.refundAmount == '0')
			{
				this.createNotificationError({
					message: this.$tc('novalnet-payment.settingForm.amountRefundError')
				});
				return;
			}

			this.disable = true;
			
            this.NovalPaymentApiCredentialsService.refundPayment(
                orderNumber,
                this.refundAmount,
                reason,
                this.item.reference,
            ).then((response) => {
				if(response.result != undefined && response.result != null) {
					
					if(response.result.status != undefined && response.result.status != null && response.result.status == 'SUCCESS'){
						
						this.createNotificationSuccess({
							message: this.$tc('novalnet-payment.settingForm.extension.refundSuccess')
						});
					}
					else if(response.result.status_text != undefined && response.result.status_text != null && response.result.status_text != '') {
					
						this.createNotificationError({
								message: response.result.status_text,
							});
					} 
					else {
				
						this.createNotificationError({
							message: this.$tc('novalnet-payment.settingForm.failureMessage')
						});
					}
				} 
				else{
					
					this.createNotificationError({
							message: this.$tc('novalnet-payment.settingForm.failureMessage')
						});
				}
                this.$emit('modal-close');
                setTimeout(this.$router.go, 3000);
            }).catch((errorResponse) => {
                    this.createNotificationError({
                        message: `${errorResponse.title}: ${errorResponse.message}`,
                        autoClose: false
                    });
                    this.$emit('modal-close');
            });
		},
	}
});
