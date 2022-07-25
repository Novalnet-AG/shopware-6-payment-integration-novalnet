import './extension/sw-order/view/sw-order-detail-base';
import './page/novalnet-payment-settings';
import './components/novalnet-payment-credentials';
import './components/novalnet-payment-settings-icon';
import './components/novalnet-payment-configuration';
import './extension/sw-order/view/novalnet-payment-refund-modal';
import './extension/sw-order/view/novalnet-payment-instalment-refund-modal';
import './extension/sw-order/view/novalnet-payment-manage-transaction-modal';

import deDE from './snippet/de_DE.json';
import enGB from './snippet/en_GB.json';

const { Module } = Shopware;

Module.register('novalnet-payment', {
    type: 'plugin',
    name: 'NovalnetPayment',
    title: 'novalnet-payment.module.title',
    description: 'novalnet-payment.module.description',
    version: '1.0.0',
    targetVersion: '1.0.0',
    icon: 'default-action-settings',

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
					}
				},
				configuration: {
					component: 'novalnet-payment-configuration',
					path: 'configuration',
					meta: {
						parentPath: 'sw.settings.index',
					}
				}
			}
		}
    },
    
    settingsItem: {
        group: 'plugins',
        to: 'novalnet.payment.detail.credentials',
        iconComponent: 'novalnet-payment-settings-icon',
        backgroundEnabled: true
    }
});
