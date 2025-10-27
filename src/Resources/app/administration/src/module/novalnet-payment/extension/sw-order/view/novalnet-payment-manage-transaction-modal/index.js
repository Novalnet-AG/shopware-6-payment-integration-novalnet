import template from './novalnet-payment-manage-transaction-modal.html.twig';

const { Component, Mixin } = Shopware;

Component.register('novalnet-payment-manage-transaction-modal', {
    template,

    props: {
        status: {
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
            confirm: true,
            cancel: false,
            disable: false
        };
    },

    methods: {

        closeModal() {
            this.$emit('modal-close');
        },

        novalnetOnhold() {
            let status = this.status;
            const orderNumber = this.order.orderNumber;

            if (status == '' || status == undefined) {
                this.createNotificationError({
                    message: this.$tc('novalnet-payment.settingForm.extension.onholdLabel')
                });
                return;
            }

            this.disable = true;

            this.NovalPaymentApiCredentialsService.managePayment(
                orderNumber,
                status
            ).then((response) => {

                if (response != '') {

                    if (response.result.status == 'SUCCESS') {
                        if (status == 100) {
                            this.createNotificationSuccess({
                                message: this.$tc('novalnet-payment.settingForm.extension.onholdSuccess')
                            });
                        } else {
                            this.createNotificationSuccess({
                                message: this.$tc('novalnet-payment.settingForm.extension.onholdCancel')
                            });
                        }
                    } else if (response.result.status_text != undefined && response.result.status_text != null && response.result.status_text != '') {

                        this.createNotificationError({
                            message: response.result.status_text,
                        });
                    } else {
                        this.createNotificationError({
                            message: this.$tc('novalnet-payment.settingForm.failureMessage')
                        });
                    }
                }
                else {
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
        }
    }
});
