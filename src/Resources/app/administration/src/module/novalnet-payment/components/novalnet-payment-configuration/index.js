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
            paypalInformation: this.$tc('novalnet-payment.settingForm.paymentSettings.paypal.configureLink'),
            sellerNamePlaceHolder: window.location.host
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
        onHoldZeroOptions() {
            return [
                {
                    id: 'capture',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.onHold.capture')
                },
                {
                    id: 'authroize',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.onHold.authroize')
                },
                {
                    id: 'zero_amount',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.onHold.zeroAmountBooking')
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
                    id: 'black',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonTheme.dark')
                },
                {
                    id: 'white',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonTheme.light')
                },
                {
                    id: 'white-outline',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonTheme.outline')
                }
            ];
        },
        buttonGpayThemeOptions() {
            return [
                {
                    id: 'default',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.googlepay.buttonTheme.default')
                },
                {
                    id: 'black',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.googlepay.buttonTheme.black')
                },
                {
                    id: 'white',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.googlepay.buttonTheme.white')
                }
            ];
        },
        buttonTypeOptions() {
            return [
                {
                    id: 'plain',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.default')
                },
                {
                    id: 'buy',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.buy')
                },
                {
                    id: 'donate',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.donate')
                },
                {
                    id: 'book',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.book')
                },
                {
                    id: 'contribute',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.contribute')
                },
                {
                    id: 'check-out',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.checkout')
                },
                {
                    id: 'order',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.order')
                },
                {
                    id: 'pay',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.pay')
                },
                {
                    id: 'subscribe',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.subscribe')
                },
                {
                    id: 'tip',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.tip')
                },
                {
                    id: 'rent',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.rent')
                },
                {
                    id: 'reload',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.reload')
                },
                {
                    id: 'support',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.applepay.buttonType.support')
                }
            ];
        },
        buttonGpayTypeOptions() {
            return [
                {
                    id: 'book',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.googlepay.buttonType.book')
                },
                {
                    id: 'buy',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.googlepay.buttonType.buy')
                },
                {
                    id: 'checkout',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.googlepay.buttonType.checkout')
                },
                {
                    id: 'donate',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.googlepay.buttonType.donate')
                },
                {
                    id: 'order',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.googlepay.buttonType.order')
                },
                {
                    id: 'pay',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.googlepay.buttonType.pay')
                },
                {
                    id: 'plain',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.googlepay.buttonType.plain')
                },
                {
                    id: 'subscribe',
                    name: this.$tc('novalnet-payment.settingForm.paymentSettings.googlepay.buttonType.subscribe')
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
