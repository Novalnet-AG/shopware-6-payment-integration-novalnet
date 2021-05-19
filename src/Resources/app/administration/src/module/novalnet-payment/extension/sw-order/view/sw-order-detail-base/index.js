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
            orderTransaction: {},
            amount : 0,
            refundableAmount : 0,
            status : 0,
            canCaptureVoid : false,
            canRefund : false,
            isNovalnetPayment : false,
            novalnetComments : '',
            refundModalVisible : false,
            confirmModalVisible: false,
            cancelModalVisible: false,
            updateModalVisible: false,
            displayAmount: '',
            displayPaidAmount: '',
            tid : '',
            stateMachineState : '',
            refundedAmount: '',
            payLater: [
				'novalnetinvoice',
				'novalnetprepayment',
				'novalnetcashpayment',
				'novalnetmultibanco'
			]
        };
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
					order.transactions.forEach((orderTransaction) => {
						if (orderTransaction.customFields &&
							orderTransaction.customFields.novalnet_comments
						) {
							this.stateMachineState = orderTransaction.stateMachineState.name;
							isNovalnet  = true;
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
                    
					this.NovalPaymentApiCredentialsService.getNovalnetAmount(order.orderNumber).then((payment) => {
						
						if(payment.data != '' && payment.data != undefined)
						{
							if(payment.data.gatewayStatus) {
								if(payment.data.gatewayStatus == 'ON_HOLD') {
									this.canCaptureVoid = true;
								}
								if( (payment.data.amount > 0 && payment.data.gatewayStatus == 'CONFIRMED' && Number(payment.data.refundedAmount) < Number(payment.data.amount)) || (payment.data.gatewayStatus == 'PENDING' && this.payLater.includes(payment.data.paymentType)) && payment.data.paymentType != 'novalnetmultibanco'  ) {
									this.canRefund = true;
								}
								
								this.displayAmount	  = currency (payment.data.amount / 100, order.currency.shortName);
								this.amount           = payment.data.amount;
								this.tid			  = payment.data.tid;
								this.refundableAmount = Number(payment.data.amount) - Number(payment.data.refundedAmount);
								
								if(payment.data.paidAmount != '') {
									this.displayPaidAmount	  = currency (payment.data.paidAmount / 100, order.currency.shortName);
								}
									
								if(payment.data.refundedAmount != '') {
									this.refundedAmount	  = currency (payment.data.refundedAmount / 100, order.currency.shortName);
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
        
        showUpdateModal() {
            this.updateModalVisible = true;
        },

        showRefundModal() {
            this.refundModalVisible = true;
        },

		closeModals() {
			this.refundModalVisible = false;
			this.confirmModalVisible = false;
			this.cancelModalVisible = false;
			this.updateModalVisible = false;
		},

		reloadPaymentDetails() {
			this.closeModals();

			// Wait for the next tick to trigger the reload. Otherwise the Modal won't be hidden correctly.
			this.$nextTick().then(() => {
				this.$emit('reload-payment');
			});
		}
    }
});
