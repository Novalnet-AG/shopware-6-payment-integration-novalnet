import template from './novalnet-payment-refund-modal.html.twig';

const { Component, Mixin } = Shopware;
const { currency } = Shopware.Utils.format;

Component.register('novalnet-payment-refund-modal', {
    template,

    props: {
        refundableAmount: {
            type: Number,
            required: true
        },
        amount: {
            type: Number,
            required: true
        },
        order: {
            type: Object,
            required: true
        },
        item: {
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
        };
    },

    methods: {

		closeModal() {
            this.$emit('modal-close');
        },

        novalnetRefund()
        {
            let refundAmount	= this.refundableAmount;
            const reason		= this.reason;
            const orderNumber	= this.order.orderNumber;
            const orderId		= this.order.id;

			if(refundAmount == '0')
			{
				this.createNotificationError({
					message: this.$tc('novalnet-payment.settingForm.amountRefundError')
				});
				return;
			}

			this.disable = true;
            this.NovalPaymentApiCredentialsService.refundPayment(
                orderId,
                orderNumber,
                refundAmount,
                reason,
                this.item.reference,
                0
            ).then((response) => {

				if(	response.result != undefined && response.result != null && response.result != '')
				{
					if(response.result.status != undefined && response.result.status != null && response.result.status != '' && response.result.status == 'SUCCESS')
					{
						this.createNotificationSuccess({
							message: this.$tc('novalnet-payment.settingForm.extension.refundSuccess')
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
