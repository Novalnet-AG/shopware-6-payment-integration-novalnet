import template from './sw-order.html.twig';
import './sw-order.scss';

const { Component, Mixin, Filter, Context } = Shopware;
const Criteria = Shopware.Data.Criteria;
const { currency } = Shopware.Utils.format;

Component.override('sw-order-detail-base', {
    template,
    
    inject: [
        'NovalPaymentApiCredentialsService',
        'repositoryFactory',
        'acl',
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
            orderAmount : 0,
            isNovalnetPayment: false,
            stateMachineState : null,
            novalnetComments : '',
            orderTotalAmount : '',
            orderPaidAmount: 0,
            orderRefundedAmount: 0,
            canRefund : false,
            refundModalVisible : false,
            confirmModalVisible: false,
            cancelModalVisible: false,
            item: {},
            status: 0,
            canCaptureVoid: false,
            refundableAmount : 0,
            canInstalmentShow: false,
            InstalmentInfo: [],
            cancelType : '',
            canInstalmentCancel: false,
            canInstalmentAllCancel: false,
            canInstalmentRemainCancel: false,
            instalmentRefundAmount : 0,
            instalmentRefundModalVisible: false,
            canZeroAmountBooking: false,
            zeroAmountVisible:false,
            payLater: [
				'INVOICE',
				'CASHPAYMENT',
				'MULTIBANCO',
				'PREPAYMENT'
			],
            instalmentPayments: [
				'INSTALMENT_INVOICE',
				'INSTALMENT_DIRECT_DEBIT_SEPA'
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
            }, 
            {
                property: 'reference',
                dataIndex: 'reference',
                label: this.$tc('novalnet-payment.settingForm.instalmentReference'),
                width: '120px'
            },
            {
                property: 'amount',
                dataIndex: 'amount',
                label: this.$tc('novalnet-payment.settingForm.instalmentAmount'),
                width: '80px'
            },{
                property: 'totalAmount',
                dataIndex: 'totalAmount',
                visible: false
            },
             {
                property: 'refundAmount',
                dataIndex: 'refundAmount',
                visible: false
            },
            {
                property: 'nextCycle',
                dataIndex: 'nextCycle',
                label: this.$tc('novalnet-payment.settingForm.instalmentDate'),
                width: '120px'
            },   {
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
			handler(){
				
				if(!this.orderId){
					this.setNovalnetPayment(null);
					return;
				}
				
				const orderRepository = this.repositoryFactory.create('order');
				const orderCriteria = new Criteria(1, 1);
				orderCriteria.addAssociation('transactions');
				orderCriteria.addAssociation('currency');
				orderCriteria.addFilter(Criteria.equals('id', this.orderId));
				
				orderRepository.search(orderCriteria, Context.api).then((searchResult) => {
					const order = searchResult.first();
					if(!order){
						return
					}
					
					if (!this.identifier) {
						this.identifier = order.orderNumber;
					}
					let isNovalnet  =   false;
					let comments    =   '';
					let translation = this.$tc('novalnet-payment.module.comments');
					
					order.transactions.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt) ).forEach((orderTransaction) => {
						if (orderTransaction.customFields &&
							orderTransaction.customFields.novalnet_comments
						) {							
							this.stateMachineState = orderTransaction.stateMachineState.name;							
							
							isNovalnet  = true;
							if(comments !=''){
								comments  += "<dt>" + translation + "</dt>";
							}
							comments += orderTransaction.customFields.novalnet_comments.split("/ ").join("<br />");
							return true;
						}
					});
					
					if( isNovalnet ) {
						this.novalnetComments  = comments.split("&&").join("<dt><strong>" + translation + "</strong></dt>");
						this.setNovalnetPayment( true );
						this.isNovalnetPayment =  true;
					} else {
						this.setNovalnetPayment(false);
						this.isNovalnetPayment =  false;
					}
					
					this.canRefund = false; this.canCaptureVoid = false; this.canInstalmentShow = false; this.InstalmentInfo = []; this.canInstalmentCancel = false; this.canZeroAmountBooking = false; this.canInstalmentAllCancel = false; this.canInstalmentRemainCancel = false;
					
					this.NovalPaymentApiCredentialsService.getNovalnetAmount(order.orderNumber).then((payment) => {

						if(payment.data != '' && payment.data != undefined)
						{
							if(payment.data.gatewayStatus) {
								
								if ( payment.data.amount != 0) {
									this.orderTotalAmount = currency (payment.data.amount / 100, order.currency.shortName);
								} else {
									this.orderTotalAmount = currency (order.price.totalPrice, order.currency.shortName);
								}
								
								if (payment.data.paidAmount != 0) {
									this.orderPaidAmount = currency (payment.data.paidAmount / 100, order.currency.shortName);
								} else {                   
									this.orderPaidAmount = currency (0, order.currency.shortName);
								}
								
								if (payment.data.refundedAmount != 0) {
									this.orderRefundedAmount = currency (payment.data.refundedAmount / 100, order.currency.shortName);
								} else {                
									this.orderRefundedAmount = currency (0, order.currency.shortName);
								}
								
								let additionalDetails = JSON.parse(payment.data.additionalDetails);
								
								if(((payment.data.amount > 0 && payment.data.gatewayStatus == 'CONFIRMED' && !this.instalmentPayments.includes(payment.data.paymentType) && Number(payment.data.refundedAmount) < Number(payment.data.amount)) || (payment.data.gatewayStatus == 'PENDING' && this.payLater.includes(payment.data.paymentType))) && payment.data.paymentType != 'MULTIBANCO'  ) 
								{
									this.canRefund = true;
								}
								else if(payment.data.gatewayStatus == 'ON_HOLD' || this.onholdStatus.includes(payment.data.gatewayStatus) ) 
								{
									this.canCaptureVoid = true;
								}
								else if (this.instalmentPayments.includes(payment.data.paymentType) && payment.data.gatewayStatus == 'CONFIRMED' && !additionalDetails.cancelType)
                                {
                                    this.canInstalmentCancel = true;
                                }
                                else if (((payment.data.paymentType == 'CREDITCARD') || (payment.data.paymentType == 'DIRECT_DEBIT_SEPA') || (payment.data.paymentType == 'GOOGLEPAY')) && Number(payment.data.amount) == 0)
                                {
									this.canZeroAmountBooking = true;
								}
                                
								this.refundableAmount = Number(payment.data.amount) - Number(payment.data.refundedAmount);
								this.orderAmount      = Math.round(Number(order.price.totalPrice) * 100);
								
								
								if( payment.data.gatewayStatus == 'CONFIRMED' && this.instalmentPayments.includes(payment.data.paymentType) && additionalDetails.InstalmentDetails != '' ) {
									
									this.canInstalmentShow = true;
									this.instalmentRefundAmount = payment.data.refundedAmount;
									var counter = 1;
									
									Object.values(additionalDetails.InstalmentDetails).forEach(values => {
										this.InstalmentInfo.push({
											'nextCycle': values.cycleDate,
											'amount': currency (values.amount / 100, order.currency.shortName),
											'reference': values.reference,
											'status': values.status,
											'totalAmount': values.amount,
											'refundAmount': values.refundAmount,
											'number': counter
										});
										counter++;
									});

									if(payment.data.refundedAmount != 0){
										this.canInstalmentCancel = false;
										this.canInstalmentAllCancel = false;
									}
									
									if (this.InstalmentInfo != undefined && this.InstalmentInfo != null) {
										this.InstalmentInfo.forEach(value => {
											if(value['reference'] == '' || value['reference'] == null)
											{
												this.canInstalmentRemainCancel = true;
											}
										});
								    }
									
									if (this.InstalmentInfo != undefined && this.InstalmentInfo != null) {
										this.InstalmentInfo.forEach(value => {
											if(value['reference'] == '' || value['reference'] == null)
											{
												this.canInstalmentRemainCancel = true;
											}
										});
									}
										
									if(this.canInstalmentRemainCancel == false && payment.data.refundedAmount == 0 ){
										this.canInstalmentCancel = false;
										this.canInstalmentAllCancel = true;
									} else if(this.canInstalmentCancel == true){
										this.canInstalmentRemainCancel = false;
									}
								}

							}	
								
						}

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
        
        showRefundModal(){
			
			this.refundModalVisible = true;
        },
        
        showConfirmModal() {
			this.status = 100;
            this.confirmModalVisible = true;
        },

        showCancelModal() {
			this.status = 103;
            this.cancelModalVisible = true;
        },
        
        instalmentRefund(item) {
			this.refundableAmount = item.totalAmount - item.refundAmount;
			this.item = item;
			this.refundModalVisible = true;
		},
		
		disableInstalmentRefund(item) {
			if( item.reference == undefined || item.reference == ''  || item.refundAmount >= item.totalAmount || !this.acl.can('novalnet_extension.editor'))
			{
				return true;
			}
	
			return false;
		},
		
		showInstalmentCancelModal() {
			if (this.InstalmentInfo != undefined && this.InstalmentInfo != null)
            {
                this.InstalmentInfo.forEach(value => {
                    if(value['reference'] == '' || value['reference'] == null)
                    {
                        this.canInstalmentRemainCancel = true;
                    }
                });
            }
            if(this.instalmentRefundAmount == 0){
				this.canInstalmentAllCancel = true;
			}
            this.canInstalmentCancel = false;
        },
        
        showInstalmentAllCancelModal() {
			this.instalmentRefundModalVisible = true;
			this.cancelType = 'CANCEL_ALL_CYCLES';
        },
        
        showInstalmentRemainCancelModal(){
			this.instalmentRefundModalVisible = true;
			this.cancelType = 'CANCEL_REMAINING_CYCLES';
        },
        
        showZeroAmountBlock() {
            this.zeroAmountVisible = true;
        },
        
        closeModals() {
			this.refundModalVisible = false;
			this.confirmModalVisible = false;
			this.cancelModalVisible = false;
			this.instalmentRefundModalVisible = false;
			this.zeroAmountVisible = false;
		},
		
		reloadPaymentDetails() {
			this.closeModals();
			this.$nextTick().then(() => {
				this.$emit('reload-payment');
			});
		}
    }	
    
});
