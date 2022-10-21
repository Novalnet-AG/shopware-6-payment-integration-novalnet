import template from './sw-order.html.twig';
import './sw-order.scss';

const { Component, Mixin, Filter, Context } = Shopware;
const Criteria = Shopware.Data.Criteria;
const { currency } = Shopware.Utils.format;

Component.override('sw-order-detail-base', {
    template,

    inject: [
        'NovalPaymentApiCredentialsService',
        'repositoryFactory'
    ],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {

        orderId: {
            type: String,
            required: true
        },
        paymentDetails: {
            type: Object,
            required: true
        },
    },

    data() {
        return {
            isLoading: false,
            order: {},
            InstalmentInfo: [],
            orderTransaction: {},
            amount : 0,
            orderAmount : 0,
            refundableAmount : 0,
            status : 0,
            counter : 1,
            canCaptureVoid : false,
            canRefund : false,
            canZeroAmountBooking : false,
            isNovalnetPayment : false,
            novalnetComments : '',
            refundModalVisible : false,
            instalmentRefundModalVisible : false,
            confirmModalVisible: false,
            zeroAmountVisible: false,
            cancelModalVisible: false,
            canInstalmentShow: false,
            updateModalVisible: false,
            canInstalmentCancel: false,
            displayAmount: '',
            item: {},
            displayPaidAmount: 0,
            tid : '',
            stateMachineState : '',
            refundedAmount: 0,
            payLater: [
				'novalnetinvoice',
				'novalnetprepayment',
				'novalnetcashpayment',
				'novalnetmultibanco'
			],
            instalmentPayments: [
				'novalnetinvoiceinstalment',
				'novalnetsepainstalment'
			],
			onholdStatus: ['91', '99', '98', '85']
        };
    },

    computed: {

		getInstalmentColums() {
			const columnDefinitions = [{
                property: 'number',
                dataIndex: 'number',
                label: this.$tc('novalnet-payment.settingForm.instalmentNumber'),
                width: '50px'
            }, {
                property: 'nextCycle',
                dataIndex: 'nextCycle',
                label: this.$tc('novalnet-payment.settingForm.instalmentDate'),
                width: '120px'
            }, {
                property: 'amount',
                dataIndex: 'amount',
                label: this.$tc('novalnet-payment.settingForm.instalmentAmount'),
                width: '80px'
            }, {
                property: 'totalAmount',
                dataIndex: 'totalAmount',
                visible: false
            }, {
                property: 'reference',
                dataIndex: 'reference',
                label: this.$tc('novalnet-payment.settingForm.instalmentReference'),
                width: '120px'
            }, {
                property: 'status',
                dataIndex: 'status',
                label: this.$tc('novalnet-payment.settingForm.instalmentStatus'),
                width: '80px'
            }];

            return columnDefinitions;
		}
	},

    watch: {
        orderId: {
            deep: true,
            handler() {
                if (!this.orderId) {
                    this.setNovalnetPayment(null);
                    return;
                } else if( this.isVerified ) {
                    return;
                }

				const orderRepository = this.repositoryFactory.create('order');
				const orderCriteria = new Criteria(1, 1);
				orderCriteria.addAssociation('transactions');
				orderCriteria.addAssociation('currency');

				orderCriteria.addFilter(Criteria.equals('id', this.orderId));

				orderRepository.search(orderCriteria, Context.api).then((searchResult) => {
					const order = searchResult.first();

					if (!order) {
						return;
					}

					if (!this.identifier) {
						this.identifier = order.orderNumber;
					}
					let isNovalnet =   false;
					let comments   =   '';
					let translation	=	this.$tc('novalnet-payment.module.comments');

					order.transactions.reverse().forEach((orderTransaction) => {
						if (orderTransaction.customFields &&
							orderTransaction.customFields.novalnet_comments
						) {
							this.stateMachineState = orderTransaction.stateMachineState.name;
							isNovalnet  = true;
							if(comments != '')
                            {
								comments  += "<dt>" + translation + "</dt>";
							}
                            comments   += orderTransaction.customFields.novalnet_comments.split("/ ").join("<br />");

							return true;
						}
					});
					if( isNovalnet ) {
						this.novalnetComments  = comments;
						this.setNovalnetPayment( true );
						this.isNovalnetPayment =  true;
					} else {
						this.setNovalnetPayment(false);
						this.isNovalnetPayment =  false;
					}
					
					this.canCaptureVoid = false;this.canRefund = false;this.canZeroAmountBooking = false;this.canInstalmentCancel = false;this.canInstalmentShow = false;this.InstalmentInfo = [];
					
					this.NovalPaymentApiCredentialsService.getNovalnetAmount(order.orderNumber).then((payment) => {
						if(payment.data != '' && payment.data != undefined)
						{
							if(payment.data.gatewayStatus) {
								if(payment.data.gatewayStatus == 'ON_HOLD' || this.onholdStatus.includes(payment.data.gatewayStatus)) 
								{
									this.canCaptureVoid = true;
								} else if ((payment.data.amount > 0 && (payment.data.gatewayStatus == 'CONFIRMED' || payment.data.gatewayStatus == 100) && !this.instalmentPayments.includes(payment.data.paymentType) && Number(payment.data.refundedAmount) < Number(payment.data.amount)) || ((payment.data.gatewayStatus == 'PENDING' || payment.data.gatewayStatus == 100) && this.payLater.includes(payment.data.paymentType)) && payment.data.paymentType != 'novalnetmultibanco'  ) 
								{
									this.canRefund = true;
								} else if (this.instalmentPayments.includes(payment.data.paymentType) && payment.data.gatewayStatus == 'CONFIRMED' && Number(payment.data.amount) > Number(payment.data.refundedAmount))
                                {
                                    this.canInstalmentCancel = true;
                                } else if ((payment.data.paymentType == 'novalnetcreditcard' || payment.data.paymentType == 'novalnetsepa') && Number(payment.data.amount) == 0)
                                {
									this.canZeroAmountBooking = true;
								}

								let additionalDetails = JSON.parse(payment.data.additionalDetails);
								this.amount           = payment.data.amount;
								this.orderAmount      = Number(order.price.totalPrice * 100);
								this.tid			  = payment.data.tid;
								this.refundableAmount = Number(payment.data.amount) - Number(payment.data.refundedAmount);
				
								if ( payment.data.amount != 0) {
									this.displayAmount = currency (payment.data.amount / 100, order.currency.shortName);
								} else {
									this.displayAmount = currency (order.price.totalPrice, order.currency.shortName);
								}
								
								if (payment.data.paidAmount != 0) {
									this.displayPaidAmount = currency (payment.data.paidAmount / 100, order.currency.shortName);
								} else {                   
									this.displayPaidAmount = currency (0, order.currency.shortName);
								}

								if (payment.data.refundedAmount != 0) {
									this.refundedAmount = currency (payment.data.refundedAmount / 100, order.currency.shortName);
								} else {                
									this.refundedAmount = currency (0, order.currency.shortName);
								}

								if( (this.instalmentPayments.includes(payment.data.paymentType)) && payment.data.gatewayStatus == 'CONFIRMED' && payment.data.amount > payment.data.refundedAmount && additionalDetails.InstalmentDetails != '')
								{
									this.canInstalmentShow = true;

									Object.values(additionalDetails.InstalmentDetails).forEach(values => {
									    this.InstalmentInfo.push ({
											'amount': currency (values.amount / 100, order.currency.shortName),
											'totalAmount': values.amount,
											'nextCycle': values.cycleDate,
											'reference': values.reference,
											'status': values.status,
											'number': this.counter
										});
										this.counter++;
									});
								}
							}
						}

					}).catch((errorResponse) => {
						this.createNotificationError({
							message: `${errorResponse.title}: ${errorResponse.message}`
						});
					});
				}).finally(() => {
					this.setNovalnetPayment(false);
				});
            },
            immediate: true
        }
    },

    methods: {

        setNovalnetPayment( novalnetPayment ) {
            if( novalnetPayment ) {
                this.isNovalnetPayment = novalnetPayment;
            }
        },

        getOrderRepository() {
            return this.repositoryFactory.create('order');
        },

		showConfirmModal() {
			this.status = 100;
            this.confirmModalVisible = true;
        },

        showCancelModal() {
			this.status = 103;
            this.cancelModalVisible = true;
        },

        showRefundModal() {
            this.refundModalVisible = true;
        },
        
        showZeroAmountBlock() {
            this.zeroAmountVisible = true;
        },

        showInstalmentCancelModal() {
			this.instalmentRefundModalVisible = true;
        },

		closeModals() {
			this.refundModalVisible = false;
			this.confirmModalVisible = false;
			this.cancelModalVisible = false;
			this.zeroAmountVisible = false;
			this.instalmentRefundModalVisible = false;
		},

		reloadPaymentDetails() {
			this.closeModals();

			// Wait for the next tick to trigger the reload. Otherwise the Modal won't be hidden correctly.
			this.$nextTick().then(() => {
				this.$emit('reload-payment');
			});
		},

		instalmentRefund(item) {
			this.refundableAmount = item.totalAmount;
			this.item = item;
			this.refundModalVisible = true;
		},

		disableInstalmentRefund(item) {

			if( item.reference == undefined || item.reference == '' )
			{
				return true;
			}

			return false;
		}
    }
});
