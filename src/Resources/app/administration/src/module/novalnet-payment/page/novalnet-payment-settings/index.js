import template from './novalnet-payment-settings.html.twig';
import './novalnet-payment-settings.scss';

const { Component, Mixin, Defaults } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('novalnet-payment-settings', {
    template,

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('sw-inline-snippet')
    ],

    inject: [
        'repositoryFactory',
        'cacheApiService',
        'acl',
        'NovalPaymentApiCredentialsService'
    ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
            clientIdFilled: false,
            clientSecretFilled: false,
            config: {},
            salesChannels: []
        };
    },

    computed: {
        salesChannelRepository() {
            return this.repositoryFactory.create('sales_channel');
        }
    },

    watch: {
        config: {
            handler(configData) {
                if (!configData) {
                    return;
                }
                const defaultConfig = this.$refs.configComponent.allConfigs.null;
                const salesChannelId = this.$refs.configComponent.selectedSalesChannelId;

                if (salesChannelId !== null) {
					
					if (!this.config['NovalnetPayment.settings.clientId'])
					{
						this.config['NovalnetPayment.settings.clientId'] = defaultConfig['NovalnetPayment.settings.clientId'];
					}
					
					if (!this.config['NovalnetPayment.settings.accessKey'])
					{
						this.config['NovalnetPayment.settings.accessKey'] = defaultConfig['NovalnetPayment.settings.accessKey'];
					}
                }
                
                this.$emit('salesChannelChanged');
                this.$emit('update:value', configData);
            },
            deep: true,
        },
    },
    
    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    created() {
        this.createdComponent();
    },

    methods: {
		createdComponent() {
            this.isLoading = true;
            const criteria = new Criteria();
            criteria.addFilter(Criteria.equalsAny('typeId', [
                Defaults.storefrontSalesChannelTypeId,
                Defaults.apiSalesChannelTypeId
            ]));
            this.salesChannelRepository.search(criteria, Shopware.Context.api).then(res => {
                res.add({
                    id: null,
                    translated: {
                        name: this.$tc('sw-sales-channel-switch.labelDefaultOption')
                    }
                });
                this.salesChannels = res;
            }).finally(() => {
                this.isLoading = false;
            });
        },

		onSave() {
			this.isSaveSuccessful = false;
            this.isLoading = true;
            
            let clientId = this.getConfigValue('clientId');
            let accessKey = this.getConfigValue('accessKey');
            
            if(clientId !== '' && clientId !== undefined)
            {
				clientId = clientId.replace(/\s/g, "");
			}
			
			if(accessKey !== '' && accessKey !== undefined)
            {
				accessKey = accessKey.replace(/\s/g, "");
			}
            

			if (clientId == undefined || clientId == '')
			{
				this.isLoading = false;
				this.createNotificationError({
							title: this.$tc('novalnet-payment.settingForm.titleError'),
							message: this.$tc('novalnet-payment.settingForm.emptyMessage')
					});

				return;
			} else if (accessKey == undefined || accessKey == '') {

				this.isLoading = false;
				this.createNotificationError({
							title: this.$tc('novalnet-payment.settingForm.titleError'),
							message: this.$tc('novalnet-payment.settingForm.emptyAccessKeyMessage')
					});

				return;
			}

			this.checkBackendConfiguration();
		},

		getConfigValue(field) {
            const defaultConfig = this.$refs.configComponent.allConfigs.null;
            const salesChannelId = this.$refs.configComponent.selectedSalesChannelId;

            if (salesChannelId === null) {
                return this.config[`NovalnetPayment.settings.${field}`];
            }

            return this.config[`NovalnetPayment.settings.${field}`]
                || defaultConfig[`NovalnetPayment.settings.${field}`];
        },

		checkBackendConfiguration() {
			const me = this;
			const clientId = this.getConfigValue('clientId').replace(/\s/g, "");
			const accessKey = this.getConfigValue('accessKey').replace(/\s/g, "");

			this.NovalPaymentApiCredentialsService.validateApiCredentials(clientId, accessKey).then((response) => {

                if(response.serverResponse == undefined || response.serverResponse == '')
                {
					this.createNotificationError({
							title: this.$tc('novalnet-payment.settingForm.titleError'),
							message: this.$tc('novalnet-payment.settingForm.apiFailureMessage')
					});

					return;
				}

                const status = response.serverResponse.result.status_code;
                if(status != 100)
                {
					this.createNotificationError({
							title: this.$tc('novalnet-payment.settingForm.titleError'),
							message: response.serverResponse.result.status_text
					});

					return;
				}else
				{
					response.tariffResponse.forEach(((tariff) => {
						if(this.config['NovalnetPayment.settings.tariff'] == undefined || this.config['NovalnetPayment.settings.tariff'] == '')
						{
							this.config['NovalnetPayment.settings.tariff'] = tariff.id;
						}
					}));

					this.config['NovalnetPayment.settings.clientKey']	= response.serverResponse.merchant.client_key;
 					
 					this.$refs.configComponent.save().then((res) => {
						this.isSaveSuccessful = true;

						if (res) {
							this.config = res;
						}
						this.isLoading = false;
						if (this.acl.can('system.clear_cache')) {
							this.cacheApiService.clear().then(() => {
							}).catch(() => {
							});
						}
						
						this.createNotificationSuccess({
							title: this.$tc('novalnet-payment.settingForm.titleSuccess'),
							message: this.$tc('novalnet-payment.settingForm.successMessage')
						});
					}).catch(() => {
						this.isLoading = false;
						this.createNotificationError({
							title: this.$tc('novalnet-payment.settingForm.titleError'),
							message: this.$tc('novalnet-payment.settingForm.errorMessage')
						});
					});

					return;
                }
            }).catch((errorResponse) => {
                    this.createNotificationError({
                        title: this.$tc('novalnet-payment.settingForm.titleError'),
                        message: this.$tc('novalnet-payment.settingForm.errorMessage')
                    });
                    this.isLoading = false;
                    this.isTestSuccessful = false;
            });
		},
	},
});
