import template from './novalnet-payment-configuration.html.twig';
import './novalnet-payment-configuration.scss';

const { Component } = Shopware;

Component.register('novalnet-payment-configuration', {
    template,

	name: 'NovalnetPaymentConfiguration',
	icon: 'default-action-settings',

	props: {
        actualConfigData: {
            type: Object,
            required: true
        },
        allConfigs: {
            type: Object,
            required: true
        },
        selectedSalesChannelId: {
            required: true
        }
    },

    data() {
        return {
            selected: 'capture',
            paypalInformation: this.$tc('novalnet-payment.settingForm.paymentSettings.paypal.configureLink')
        };
    },

    computed: {
		onholdOptions() {
            return [
                {
                    id: 'capture',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.onHold.capture')
                },
                {
                    id: 'authroize',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.onHold.authroize')
                }
            ];
        },

        instalmentOptions() {

			let options = [];
			const cycles = ['2','3','4','5','6','7','8','9','10','11','12','15','18','21','24'];
			const translated = this.$tc('novalnet-payment.settingForm.paymentSettings.instalmentCycleInfo.cycle');
			cycles.forEach(function(index) {
				options.push({
					id: index,
					name: index + translated
				});
			});
			return options;
		}
	},

    methods: {
        checkTextFieldInheritance(value) {
            if (typeof value !== 'string') {
                return true;
            }

            return value.length <= 0;
        },

        checkBoolFieldInheritance(value) {
            return typeof value !== 'boolean';
        }
    }
});
