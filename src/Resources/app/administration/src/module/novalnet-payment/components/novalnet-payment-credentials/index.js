import template from './novalnet-payment-credentials.html.twig';
import './novalnet-payment-credentials.scss';

const { Component, Mixin } = Shopware;

Component.register('novalnet-payment-credentials', {
    template,

	mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('sw-inline-snippet')
    ],

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
			shouldDisable :false,
			projectMode: false,
			isLoading: false,
			isRequested : '',
			showMessage: false,
			buttonLoad: false,
			apiActivationKey: '',
			paymentAccessKey: '',
			tariffId: '',
			tariffOptions: [],
			NovalnetPaymentCallBackUrl : generatedUrl + "/novalnet/callback",
			generalInformation: this.$tc('novalnet-payment.module.generalInfo'),
			installationInformation: this.$tc('novalnet-payment.module.installationInfo')
		};
	},

	inject: [
        'NovalPaymentApiCredentialsService',
        'repositoryFactory',
        'acl',
        'systemConfigApiService',
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
        
        onCheckApi() {
			if(event.target.name === 'NovalnetPayment.settings.clientId') {
					this.apiActivationKey = this.actualConfigData['NovalnetPayment.settings.clientId'] = event.target.value;
			} else if(event.target.name === 'NovalnetPayment.settings.accessKey') {
				this.paymentAccessKey = this.actualConfigData['NovalnetPayment.settings.accessKey'] = event.target.value;
			}

			if (this.actualConfigData['NovalnetPayment.settings.clientId'] === '' || this.actualConfigData['NovalnetPayment.settings.accessKey'] === '')
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
			if(this.actualConfigData !== undefined && this.isRequested !== this.selectedSalesChannelId)
			{
				this.isRequested		= this.selectedSalesChannelId;
				this.apiActivationKey	= this.actualConfigData['NovalnetPayment.settings.clientId'] || this.allConfigs.null['NovalnetPayment.settings.clientId'];
				this.paymentAccessKey	= (this.actualConfigData['NovalnetPayment.settings.accessKey'] || this.allConfigs.null['NovalnetPayment.settings.accessKey']);
				
				if(this.selectedSalesChannelId == null &&  this.actualConfigData['NovalnetPayment.settings.callbackUrl'] === undefined  || this.actualConfigData['NovalnetPayment.settings.callbackUrl'] === null) {
					this.actualConfigData['NovalnetPayment.settings.callbackUrl'] = this.NovalnetPaymentCallBackUrl;
				}
				
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
							this.tariffOptions = [];
							response.tariffResponse.forEach(((tariff) => {

								this.actualConfigData['NovalnetPayment.settings.clientKey']	= response.serverResponse.merchant.client_key;

								this.tariffOptions.push({
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
					}).catch(() => {
						this.isLoading = false;
					});
				}
			}
		},

        configureWebhookUrl() {
			const productActivationKey	= this.actualConfigData['NovalnetPayment.settings.clientId'] || this.allConfigs.null['NovalnetPayment.settings.clientId'];
			const accessKey				= this.actualConfigData['NovalnetPayment.settings.accessKey'] || this.allConfigs.null['NovalnetPayment.settings.accessKey'];
			const callbackUrl			= this.actualConfigData['NovalnetPayment.settings.callbackUrl'] || this.allConfigs.null['NovalnetPayment.settings.callbackUrl'];

			if ( productActivationKey === undefined || productActivationKey === '' || accessKey === undefined || accessKey === '')
			{
				this.createNotificationError({
							title: this.$tc('novalnet-payment.settingForm.titleError'),
							message: this.$tc('novalnet-payment.settingForm.apiFailureMessage')
					});

				return;
			}

			if(callbackUrl)
			{
				if (/^(http|https):\/\/[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}(:[0-9]{1,5})?(\/.*)?$/i.test(callbackUrl) === false)
				{
					this.createNotificationError({
						message: this.$tc('novalnet-payment.settingForm.webhookUrlFailure')
					});
					return false;
				}

				this.buttonLoad = true;

				this.NovalPaymentApiCredentialsService.configureWebhookUrl(callbackUrl, productActivationKey, accessKey).then((response) => {

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

				}).catch(() => {
					this.buttonLoad = false;
				});
			}
		}
    }
});
