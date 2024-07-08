import template from './novalnet-payment-credentials.html.twig';
import './novalnet-payment-credentials.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;
const { object, types } = Shopware.Utils;

Component.register('novalnet-payment-credentials', {
    template,

	mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('sw-inline-snippet')
    ],
    
    name: 'NovalnetPaymentCredentials',
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
		const url = window.location .protocol + "//" + window.location.host + window.location.pathname;
		const generatedUrl = url.split("/admin").join("");
        return {
			onHoldOptions:[],
			completeOptions:[],
			onhold: 'authorized',
			completed: 'paid',
			shouldDisable :false,
			projectMode: false,
			apiActivationKey: '',
			paymentAccessKey: '',
			isLoading: false,
			isRequested : '',
			showMessage: false,
			buttonLoad: false,
			tariffId: '',
			tariffOptions: [],
			NovalnetPaymentCallBackUrl : generatedUrl + "/novalnet/callback"
			
		};
	},

	inject: [
        'repositoryFactory',
        'NovalPaymentApiCredentialsService',
        'systemConfigApiService',
        'acl',
    ],

    watch: {
        actualConfigData: {
            handler(configData) {
				
                if (!configData) {
                    return;
                }
                this.$emit('update:value', configData);
            },
            deep: true
        }
    },

    computed: {
        actualConfigData: {
            get() {
                return this.allConfigs[this.selectedSalesChannelId];
            },
            set(config) {
                this.allConfigs = {
                    ...this.allConfigs,
                    [this.selectedSalesChannelId]: config
                };
            }
        },
    },

	created() {
		this.createdComponent();
    },

    updated() {
		this.createdComponent();
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
        },

		getInheritValue(key) {
            if (this.selectedSalesChannelId == null ) {
				return this.actualConfigData[key];
			} else {
				return this.allConfigs['null'][key];
			}
        },

        onCheckApi() {
			if(event.target.name === 'NovalnetPayment.settings.clientId') {
				this.apiActivationKey = this.actualConfigData['NovalnetPayment.settings.clientId'] = event.target.value;
			} else if(event.target.name === 'NovalnetPayment.settings.accessKey') {
				this.paymentAccessKey = this.actualConfigData['NovalnetPayment.settings.accessKey'] = event.target.value;
			}

			if (this.apiActivationKey === '' && this.paymentAccessKey === '')
			{
				this.createNotificationError({
						title: this.$tc('novalnet-payment.settingForm.titleError'),
						message: this.$tc('novalnet-payment.settingForm.apiFailureMessage')
				});
				return;
			}
			this.isRequested = '';
			this.showMessage = true;
			this.createdComponent();
		},

        createdComponent() {
			const me = this;
			if(this.actualConfigData !== undefined && this.isRequested !== this.selectedSalesChannelId)
			{
				this.isRequested		= this.selectedSalesChannelId;
				this.apiActivationKey	= this.actualConfigData['NovalnetPayment.settings.clientId'] || this.allConfigs.null['NovalnetPayment.settings.clientId'];
				this.paymentAccessKey	= (this.actualConfigData['NovalnetPayment.settings.accessKey'] || this.allConfigs.null['NovalnetPayment.settings.accessKey']);

				if(this.apiActivationKey !== undefined && this.apiActivationKey !== '' && this.paymentAccessKey !== undefined && this.paymentAccessKey !== '' )
				{
                    this.apiActivationKey = this.apiActivationKey.replace(/\s/g, "");
                    this.paymentAccessKey = this.paymentAccessKey.replace(/\s/g, "");
					this.isLoading = true;
					this.NovalPaymentApiCredentialsService.validateApiCredentials(this.apiActivationKey, this.paymentAccessKey).then((response) => {
						const status = response.serverResponse.result.status_code;
						this.isLoading = false;
						if(status !== 100)
						{
							if(this.showMessage === true)
							{
								this.createNotificationError({
									title: this.$tc('novalnet-payment.settingForm.titleError'),
									message: response.serverResponse.result.status_text,
									autoClose: true
								});
							}
							this.showMessage = false;

						} else {
							me.tariffOptions = [];
							response.tariffResponse.forEach(((tariff) => {

								this.actualConfigData['NovalnetPayment.settings.clientKey']	= response.serverResponse.merchant.client_key;

								me.tariffOptions.push({
									value: tariff.id,
									label: tariff.name
								});

                                if(this.tariffId === undefined || this.tariffId === '')
                                {
                                    this.tariffId = {value: tariff.id, label: tariff.name};
                                }

								if(this.showMessage === true)
								{
									this.createNotificationSuccess({
										title: this.$tc('novalnet-payment.settingForm.titleSuccess'),
										message: this.$tc('novalnet-payment.settingForm.successMessage'),
										autoClose: true
									});
								}

								this.showMessage = false;
								if(response.serverResponse.merchant.test_mode === 1)
								{
									this.projectMode = true;
								}
							}));

						}
					}).catch((errorResponse) => {
						this.isLoading = false;
					});
				}
			}
			
			this.onHoldOptions = [
				{
                    value: 'open',
                    label: this.$tc('novalnet-payment.onhold.open')
                },
                {
                    value: 'process',
                    label: this.$tc('novalnet-payment.onhold.process')
                },
                {
                    value: 'authorized',
                    label: this.$tc('novalnet-payment.onhold.authorized')
                },
                {
                    value: 'cancel',
                    label: this.$tc('novalnet-payment.onhold.cancel')
                },
                {
                    value: 'failed',
                    label: this.$tc('novalnet-payment.onhold.failed')
                }];
                
                this.completeOptions = [
                
					{
						value: 'paid',
						label: this.$tc('novalnet-payment.onhold.paid')
					},
					{
						value: 'paidPartially',
						label: this.$tc('novalnet-payment.onhold.paidPartially')
					},
					{
						value: 'cancel',
						label: this.$tc('novalnet-payment.onhold.cancel')
					},
					{
						value: 'failed',
						label: this.$tc('novalnet-payment.onhold.failed')
					}
				]; 
		},

        configureWebhookUrl() {
			const productActivationKey	= this.actualConfigData['NovalnetPayment.settings.clientId'] || this.allConfigs.null['NovalnetPayment.settings.clientId'];
			const accessKey				= this.actualConfigData['NovalnetPayment.settings.accessKey'] || this.allConfigs.null['NovalnetPayment.settings.accessKey'];

			if ( productActivationKey === undefined || productActivationKey === '' || accessKey === undefined || accessKey === '')
			{
				this.createNotificationError({
							title: this.$tc('novalnet-payment.settingForm.titleError'),
							message: this.$tc('novalnet-payment.settingForm.apiFailureMessage')
					});

				return;
			}

			if( this.NovalnetPaymentCallBackUrl)
			{
				if (/^(http|https):\/\/[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}(:[0-9]{1,5})?(\/.*)?$/i.test(this.NovalnetPaymentCallBackUrl) === false)
				{
					this.createNotificationError({
						message: this.$tc('novalnet-payment.settingForm.webhookUrlFailure')
					});
					return false;
				}

				this.buttonLoad = true;

				this.NovalPaymentApiCredentialsService.configureWebhookUrl(this.NovalnetPaymentCallBackUrl, productActivationKey, accessKey).then((response) => {

						if(response.result.status !== undefined && response.result.status != null && response.result.status !== '' && response.result.status === 'SUCCESS')
						{
							this.createNotificationSuccess({
								message: this.$tc('novalnet-payment.settingForm.webhookUrlSuccess')
							});
						} else if(response.result.status_text !== undefined && response.result.status_text != null && response.result.status_text !== '') {
							this.createNotificationError({
								message: response.result.status_text,
							});
						} else {
							this.createNotificationError({
								message: this.$tc('novalnet-payment.settingForm.webhookUrlFailure')
							});
						}
					this.buttonLoad = false;

				}).catch((errorResponse) => {
					this.buttonLoad = false;
				});
			}
		}
    }
});
