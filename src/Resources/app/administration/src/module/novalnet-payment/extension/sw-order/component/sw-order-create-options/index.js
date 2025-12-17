
const { Component, State, Mixin, Filter, Context, ContextSwitchParameters } = Shopware;
const Criteria = Shopware.Data.Criteria;
const { currency } = Shopware.Utils.format;

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
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
            return State.get('swOrder').context?.salesChannel?.id ?? '';
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
