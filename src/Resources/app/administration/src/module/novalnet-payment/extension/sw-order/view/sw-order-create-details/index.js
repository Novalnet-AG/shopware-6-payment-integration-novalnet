import template from './sw-order-create-details.html.twig';
const { Component, State, Mixin, Filter, Context, ContextSwitchParameters} = Shopware;
const Criteria = Shopware.Data.Criteria;
const { currency } = Shopware.Utils.format;

Component.override('sw-order-create-details', {
    template,

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
        salesChannelId() {
            return this.salesChannelContext?.salesChannel.id || '';
        },
       
        salesChannelContext(){
            return State.get('swOrder').context;
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
                    Criteria.equals('customFields.novalnet_payment_method_name', 'novalnetcashpayment'),
                ]));
            return criteria;
        },
    },
  
});
