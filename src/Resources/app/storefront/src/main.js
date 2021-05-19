// import all necessary storefront plugins
import NovalnetCreditCardPayment from './credit-card/credit-card-payment.plugin';
import NovalnetPaypalPayment from './paypal/paypal-payment.plugin';
import NovalnetSepaPayment from './sepa/sepa-payment.plugin';
import NovalnetInvoicePayment from './invoice/invoice-payment.plugin';

// register them via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('NovalnetCreditCardPayment', NovalnetCreditCardPayment, '[data-novalnetcreditcard-payment]');
PluginManager.register('NovalnetPaypalPayment', NovalnetPaypalPayment, '[data-novalnetpaypal-payment]');
PluginManager.register('NovalnetSepaPayment', NovalnetSepaPayment, '[data-novalnetsepa-payment]');
PluginManager.register('NovalnetSepaPayment', NovalnetSepaPayment, '[data-novalnetsepaguarantee-payment]');
PluginManager.register('NovalnetInvoicePayment', NovalnetInvoicePayment, '[data-novalnetinvoiceguarantee-payment]');
