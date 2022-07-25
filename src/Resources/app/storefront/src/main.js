// import all necessary storefront plugins
import NovalnetPayment from './novalnet-payment/novalnet-payment.plugin';
import NovalnetApplePayPayment from './novalnet-applepay-payment/novalnet-applepay-payment.plugin';

// register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('NovalnetPayment', NovalnetPayment, '#novalnet-payment-script');
PluginManager.register('NovalnetApplePayPayment', NovalnetApplePayPayment, '.nn-apple-pay-button');
