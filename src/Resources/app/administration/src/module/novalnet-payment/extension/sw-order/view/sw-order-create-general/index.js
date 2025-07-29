
const { Component, State, Mixin,  Context} = Shopware;
const Criteria = Shopware.Data.Criteria;

Component.override('sw-order-create-general', {
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
        };
    },

    computed: {
        customer() {
            return State.get('swOrder').customer;
        },
        cart() {
            return State.get('swOrder').cart;
        },
        currency(){
            return State.get('swOrder').context.currency;
        },
        cartPrice() {
            return this.cart.price;
        },
        salesChannelContext(){
            return State.get('swOrder').context;
        },
    },

    watch: {
        salesChannelContext: {
            deep: true,
            handler() {

                if (!this.customer) {
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
                        this.onWindowLoad();
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
        onWindowLoad() {
            document.querySelector('.sw-button-process').disabled = true;
	    }    
    },
});
