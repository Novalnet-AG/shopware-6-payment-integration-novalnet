import template from './sw-order-create-base.html.twig';


const { Component, State, Mixin, Filter, Context } = Shopware;
const Criteria = Shopware.Data.Criteria;
const { currency } = Shopware.Utils.format;

Component.override('sw-order-create-base', {
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
		
		customer() {
            return State.get('swOrder').customer;
        },
        
        cart() {
            return State.get('swOrder').cart;
        },
        cartPrice() {
            return this.cart.price;
        },

        currency() {
            return State.get('swOrder').context.currency;
        },
        displayRounded() {
            if (!this.cartPrice) {
                return false;
            }
            return this.cartPrice.rawTotal !== this.cartPrice.totalPrice;
        },
        
        orderTotal() {
            if (!this.cartPrice) {
                return 0;
            }

            if (this.displayRounded) {
                return this.cartPrice.rawTotal;
            }
            return this.cartPrice.totalPrice;
            
        },
	},
	
});
