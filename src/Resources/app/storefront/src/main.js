// import all necessary storefront plugins
import NovalnetPayment from './novalnet-payment/novalnet-payment.plugin';
import NovalnetWalletPayment from './novalnet-wallet-payments/novalnet-wallet-payments.plugin';

// register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('NovalnetPayment', NovalnetPayment, '#novalnet-payment-script');
PluginManager.register('NovalnetWalletPayment', NovalnetWalletPayment, '[data-wallet-payments]');
