import template from './novalnet-payment-book-amount-modal.html.twig';

const { Component, Mixin } = Shopware;

Component.register('novalnet-payment-book-amount-modal', {
    template,

    props: {
        orderAmount: {
            type: Number,
            required: true
        },
        order: {
            type: Object,
            required: true
        }
    },

    inject: [
        'NovalPaymentApiCredentialsService',
        'repositoryFactory'
    ],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('sw-inline-snippet')
    ],

    data() {
        return {
            reason: '',
            disable: false,
            bookAmount: this.orderAmount
        };
    },

    methods: {

		closeModal() {
            this.$emit('modal-close');
        },
        
        novalnetBookAmount()
        {
           
            const orderNumber = this.order.orderNumber;
            
			if(this.bookAmount == 0)
			{
				this.createNotificationError({
					message: this.$tc('novalnet-payment.settingForm.amountError')
				});
				return;
			}

			this.disable = true;
            this.NovalPaymentApiCredentialsService.BookOrderAmount(
				orderNumber,
                this.bookAmount
            ).then((response) => {

				if(	response.result != undefined && response.result != null )
				{
					if(response.result.status != undefined && response.result.status != null && response.result.status == 'SUCCESS')
					{
						this.createNotificationSuccess({
							message: this.$tc('novalnet-payment.settingForm.extension.bookedSuccess')
						});
					} else if(response.result.status_text != undefined && response.result.status_text != null && response.result.status_text != '') {

						this.createNotificationError({
							message: response.result.status_text,
						});
					} else {
						this.createNotificationError({
							message: this.$tc('novalnet-payment.settingForm.failureMessage')
						});
					}
				} else {

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
