import template from './sw-order-general-info.html.twig';

const { Component, Mixin } = Shopware;

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
			paymentMethod: ''
		};
	},

	watch: {

		order: {
			deep: true,
			handler() {
				if (this.order == '') {
					return;
				}

				if (this.order.transactions.last().paymentMethod.customFields != null && this.order.transactions.last().paymentMethod.customFields.novalnet_payment_method_name != undefined && this.order.transactions.last().paymentMethod.customFields.novalnet_payment_method_name == "novalnetpay") {

					if (this.order.transactions.last().customFields != null && this.order.transactions.last().customFields.novalnet_payment_name != undefined && this.order.transactions.last().customFields.novalnet_payment_name != '') {
						this.paymentMethod = this.order.transactions.last().customFields.novalnet_payment_name;
					} else {

						this.NovalPaymentApiCredentialsService.getNovalnetPaymentMethod(this.order.orderNumber).then((payment) => {
							if (payment != undefined && payment != null) {
								if (payment.paymentName != undefined && payment.paymentName != null) {
									this.paymentMethod = payment.paymentName;
								}
								else {
									this.paymentMethod = this.order.transactions.last().paymentMethod.translated.distinguishableName;
								}
							}
							else {
								this.paymentMethod = this.order.transactions.last().paymentMethod.translated.distinguishableName;
							}
						}).catch((errorResponse) => {
							this.createNotificationError({
								message: `${errorResponse.title}: ${errorResponse.message}`
							});
						});
					}
				}
				else {

					this.paymentMethod = this.order.transactions.last().paymentMethod.translated.distinguishableName;
				}
			},
			immediate: true
		}
	}




});
