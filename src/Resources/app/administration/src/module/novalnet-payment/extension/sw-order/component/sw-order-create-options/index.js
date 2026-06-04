
const { Component, Store, Mixin } = Shopware;
const Criteria = Shopware.Data.Criteria;

Component.override('sw-order-create-options', {

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
            isLoading: false
        };
    },

    computed: {
        salesChannelId() {
            return Store.get('swOrder').context?.salesChannel?.id ?? '';
        },

        paymentMethodCriteria() {
            const criteria = new Criteria();
            criteria.addFilter(Criteria.equals('active', 1));

            if (this.salesChannelId) {
                criteria.addFilter(Criteria.equals('salesChannels.id', this.salesChannelId));
            }
            criteria.addFilter(Criteria.multi('OR', [
                Criteria.equals('customFields.novalnet_payment_method_name', null),
                Criteria.equals('customFields.novalnet_payment_method_name', 'novalnetinvoice'),
                Criteria.equals('customFields.novalnet_payment_method_name', 'novalnetprepayment'),
                Criteria.equals('customFields.novalnet_payment_method_name', 'novalnetmultibanco'),
            ]));

            return criteria;
        },

    }


});
