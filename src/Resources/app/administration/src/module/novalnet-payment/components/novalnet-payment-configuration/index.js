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
        displayFieldOptions() {
            return [
                {
                    id: 'cart',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.displayFields.cartPage')
                },
                {
                    id: 'register',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.displayFields.registerPage')
                },
                {
                    id: 'ajaxCart',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.displayFields.miniCartPage')
                },
                {
                    id: 'productDetailPage',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.displayFields.productPage')
                },
                {
                    id: 'productListingPage',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.displayFields.productListingPage')
                }
            ];
        },
        buttonThemeOptions() {
            return [
                {
                    id: 'apple-pay-button-black-with-text',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonTheme.dark')
                },
                {
                    id: 'apple-pay-button-white-with-text',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonTheme.light')
                },
                {
                    id: 'apple-pay-button-white-with-line-with-text',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonTheme.outline')
                }
            ];
        },
        buttonTypeOptions() {
            return [
                {
                    id: 'apple-pay-button-text-plain',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.default')
                },
                {
                    id: 'apple-pay-button-text-buy',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.buy')
                },
                {
                    id: 'apple-pay-button-text-donate',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.donate')
                },
                {
                    id: 'apple-pay-button-text-book',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.book')
                },
                {
                    id: 'apple-pay-button-text-contribute',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.contribute')
                },
                {
                    id: 'apple-pay-button-text-check-out',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.checkout')
                },
                {
                    id: 'apple-pay-button-text-order',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.order')
                },
                {
                    id: 'apple-pay-button-text-subscribe',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.subscribe')
                },
                {
                    id: 'apple-pay-button-text-tip',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.tip')
                },
                {
                    id: 'apple-pay-button-text-rent',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.rent')
                },
                {
                    id: 'apple-pay-button-text-reload',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.reload')
                },
                {
                    id: 'apple-pay-button-text-support',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.support')
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
