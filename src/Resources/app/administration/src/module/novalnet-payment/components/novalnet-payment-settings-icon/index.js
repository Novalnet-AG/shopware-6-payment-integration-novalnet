import template from './novalnet-payment-settings-icon.html.twig';

const { Component } = Shopware;

Component.register('novalnet-payment-settings-icon', {
    template,
    
    computed: {
        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },
    },
});
