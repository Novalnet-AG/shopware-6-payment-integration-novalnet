import './page/novalnet-payment-settings';
import './components/novalnet-payment-credentials';
import './components/novalnet-payment-settings-icon';
import './extension/sw-order/view/sw-order-detail-base';
import './extension/sw-order/view/sw-order-detail-details';
import './extension/sw-order/view/sw-order-create-details';
import './extension/sw-order/view/sw-order-create-base';
import './extension/sw-order/view/sw-order-create-general';
import './extension/sw-order/view/novalnet-payment-refund-modal';
import './extension/sw-order/view/novalnet-payment-manage-transaction-modal';
import './extension/sw-order/view/novalnet-payment-instalment-cancel-modal';
import './extension/sw-order/view/novalnet-payment-book-amount-modal';
import './extension/sw-order/component/sw-order-user-card';
import './extension/sw-order/component/sw-order-create-details-payment';
import './extension/sw-order/component/sw-order-general-info';

import deDE from './snippet/de_DE.json';
import enGB from './snippet/en_GB.json';

const { Module } = Shopware;

Module.register('novalnet-payment', {
	
	type	: 'plugin',
	name	: 'NovalnetPayment',
	title	: 'novalnet-payment.module.title',
	description	:'novalnet-payment.module.description',
	
	
	snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    },
	routes: {
        index: {
			component: 'novalnet-payment-settings',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'novalnet_payment.viewer',
            }
        },
        detail: {
            component: 'novalnet-payment-settings',
            path: 'settings',
            redirect: {
                name: 'novalnet.payment.credentials'
            },
            children: {
				credentials: {
					component: 'novalnet-payment-credentials',
					path: 'credentials',
					meta: {
						parentPath: 'sw.settings.index',
						privilege: 'novalnet_payment.viewer',
					}
				}
			}
		}
        
    },
	settingsItem: {
        group: 'plugins',
        to: 'novalnet.payment.detail.credentials',
        iconComponent: 'novalnet-payment-settings-icon',
        backgroundEnabled: true,
        privilege: 'novalnet_payment.viewer'
    }
});
