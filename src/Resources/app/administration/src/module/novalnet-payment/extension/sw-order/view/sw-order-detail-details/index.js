import template from './sw-order-detail-details.html.twig';
import './sw-order-detail-details.scss';

const { Context, Component } = Shopware;
const { Criteria } = Shopware.Data;
const { currency } = Shopware.Utils.format;

Component.override('sw-order-detail-details', {
    template,

    inject: [
        'NovalPaymentApiCredentialsService',
        'repositoryFactory',
        'acl',
    ],

    mixins: ['notification'],

    props: {
        orderId: {
            type: String,
            required: true
        },

        isSaveSuccessful: {
            type: Boolean,
            required: true,
        },
    },

    data() {
        return {
            status: 0,
            displayAmount: 0,
            displayPaidAmount: 0,
            refundedAmount: 0,
            InstalmentInfo: [],
            item: {},
            novalnetComments: '',
            isNovalnetPayment: false,
            refundModalVisible: false,
            confirmModalVisible: false,
            zeroAmountVisible: false,
            cancelModalVisible: false,
            canInstalmentAllCancel: false,
            canInstalmentRemainCancel: false,
            instalmentRefundModalVisible: false,
            canCaptureVoid: false,
            canRefund: false,
            canZeroAmountBooking: false,
            canInstalmentCancel: false,
            canInstalmentShow: false,
            payLater: [
                'novalnetinvoice',
                'novalnetprepayment',
                'novalnetmultibanco'
            ],
            instalmentPayments: [
                'novalnetinvoiceinstalment',
                'novalnetsepainstalment'
            ],
            onholdStatus: ['91', '99', '98', '85']
        }
    },

    computed: {

        getInstalmentColums() {
            const columnDefinitions = [{
                property: 'number',
                dataIndex: 'number',
                label: this.$tc('novalnet-payment.settingForm.instalmentNumber'),
                width: '50px'
            }, {
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
            }, {
                property: 'totalAmount',
                dataIndex: 'totalAmount',
                visible: false
            },
            {
                property: 'refundAmount',
                dataIndex: 'refundAmount',
                visible: false
            }, {
                property: 'nextCycle',
                dataIndex: 'nextCycle',
                label: this.$tc('novalnet-payment.settingForm.instalmentDate'),
                width: '120px'
            }, {
                property: 'status',
                dataIndex: 'status',
                label: this.$tc('novalnet-payment.settingForm.instalmentStatus'),
                width: '80px'
            }];

            return columnDefinitions;
        },

        assetFilter() {
            return Shopware.Filter.getByName('asset');
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },
    },

    watch: {
        orderId: {
            deep: true,
            handler() {
                if (!this.orderId) {
                    this.setNovalnetPayment(false);
                    return;
                }

                const orderRepository = this.repositoryFactory.create('order');
                const orderCriteria = new Criteria(1, 1);
                orderCriteria.addAssociation('transactions');
                orderCriteria.addAssociation('transactions.stateMachineState');
                orderCriteria.addAssociation('currency');

                orderCriteria.addFilter(Criteria.equals('id', this.orderId));

                orderRepository.search(orderCriteria, Context.api).then((searchResult) => {
                    const order = searchResult.first();

                    if (!order) {
                        return;
                    }

                    let isNovalnet = false;
                    let comments = '';
                    let translation = this.$tc('novalnet-payment.module.comments');

                    order.transactions.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt)).forEach((orderTransaction) => {
                        if (orderTransaction.customFields &&
                            orderTransaction.customFields.novalnet_comments
                        ) {
                            if (this.stateMachineState == null && orderTransaction.stateMachineState != null) {
                                this.stateMachineState = orderTransaction.stateMachineState.name;
                            }
                            isNovalnet = true;
                            if (comments != '') {
                                comments += "<dt>" + translation + "</dt>";
                            }
                            comments += orderTransaction.customFields.novalnet_comments.split("/ ").join("<br />");

                            return true;
                        }
                    });

                    if (isNovalnet) {
                        this.novalnetComments = comments;
                        this.setNovalnetPayment(true);
                        this.displayAmount = currency(order.price.totalPrice, order.currency.shortName);
                    } else {
                        this.setNovalnetPayment(false);
                    }

                    this.canCaptureVoid = false; this.canRefund = false; this.canZeroAmountBooking = false; this.canInstalmentCancel = false; this.canInstalmentShow = false; this.InstalmentInfo = [];

                    this.NovalPaymentApiCredentialsService.getNovalnetAmount(order.orderNumber).then((payment) => {

                        if (payment.data != null && payment.data != undefined) {
                            this.refundableAmount = Number(payment.data.amount) - Number(payment.data.refundedAmount);
                            this.orderAmount = Math.round(Number(order.price.totalPrice) * 100);
                            this.displayAmount = (payment.data.amount != 0) ? currency(payment.data.amount / 100, order.currency.shortName) : this.displayAmount;
                            this.displayPaidAmount = currency(payment.data.paidAmount / 100, order.currency.shortName);
                            this.refundedAmount = currency(payment.data.refundedAmount / 100, order.currency.shortName);
                            let additionalDetails = JSON.parse(payment.data.additionalDetails);

                            if (payment.data.gatewayStatus == 'ON_HOLD' || this.onholdStatus.includes(payment.data.gatewayStatus)) {
                                this.canCaptureVoid = true;
                            } else if (!['novalnetinvoiceinstalment', 'novalnetsepainstalment', 'novalnetmultibanco'].includes(payment.data.paymentType) && ((payment.data.gatewayStatus == 'CONFIRMED' && Number(payment.data.refundedAmount) < Number(payment.data.amount)) || (payment.data.gatewayStatus == 'PENDING' && this.payLater.includes(payment.data.paymentType)))) {
                                this.canRefund = true;
                            } else if (this.instalmentPayments.includes(payment.data.paymentType) && payment.data.gatewayStatus == 'CONFIRMED' && !additionalDetails.cancelType) {
                                this.canInstalmentCancel = true;

                            } else if ((payment.data.paymentType == 'novalnetdirectdebitach' || payment.data.paymentType == 'novalnetcreditcard' || payment.data.paymentType == 'novalnetsepa' || payment.data.paymentType == 'novalnetgooglepay' || payment.data.paymentType == 'novalnetapplepay') && Number(payment.data.amount) == 0 && Number(order.price.totalPrice) != 0) {
                                this.canZeroAmountBooking = true;
                            }

                            if ((this.instalmentPayments.includes(payment.data.paymentType)) && payment.data.gatewayStatus == 'CONFIRMED' && additionalDetails.InstalmentDetails != undefined && additionalDetails.InstalmentDetails != '') {
                                this.canInstalmentShow = true;
                                var counter = 1;

                                Object.values(additionalDetails.InstalmentDetails).forEach(values => {
                                    this.InstalmentInfo.push({
                                        'amount': currency(values.amount / 100, order.currency.shortName),
                                        'totalAmount': values.amount,
                                        'nextCycle': additionalDetails.InstalmentDetails[counter + 1] != undefined && additionalDetails.InstalmentDetails[counter + 1].cycleDate ? additionalDetails.InstalmentDetails[counter + 1].cycleDate : '',
                                        'reference': values.reference,
                                        'status': values.status,
                                        'refundAmount': values.refundAmount,
                                        'number': counter
                                    });
                                    counter++;
                                });
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
        setNovalnetPayment(novalnetPayment) {
            if (novalnetPayment) {
                this.isNovalnetPayment = novalnetPayment;
            }
        },

        showConfirmModal() {
            this.status = 100;
            this.confirmModalVisible = true;
        },

        showRefundModal() {
            this.refundModalVisible = true;
        },

        closeModals() {
            this.refundModalVisible = false;
            this.confirmModalVisible = false;
            this.cancelModalVisible = false;
            this.zeroAmountVisible = false;
            this.instalmentRefundModalVisible = false;
        },

        showInstalmentAllCancelModal() {
            this.instalmentRefundModalVisible = true;
            this.cancelType = 'CANCEL_ALL_CYCLES';
        },

        showInstalmentRemainCancelModal() {
            this.instalmentRefundModalVisible = true;
            this.cancelType = 'CANCEL_REMAINING_CYCLES';
        },

        showCancelModal() {
            this.status = 103;
            this.cancelModalVisible = true;
        },

        showZeroAmountBlock() {
            this.zeroAmountVisible = true;
        },

        reloadPaymentDetails() {
            this.closeModals();
            // Wait for the next tick to trigger the reload. Otherwise the Modal won't be hidden correctly.
            this.$nextTick().then(() => {
                this.$emit('reload-payment');
            });
        },

        instalmentRefund(item) {
            this.refundableAmount = item.totalAmount - item.refundAmount;
            this.item = item;
            this.refundModalVisible = true;
        },

        showInstalmentCancelModal() {
            if (this.InstalmentInfo != undefined && this.InstalmentInfo != null) {
                this.InstalmentInfo.forEach(value => {
                    if (value['reference'] == '' || value['reference'] == null) {
                        this.canInstalmentRemainCancel = true;
                    }
                });
            }
            this.canInstalmentAllCancel = true;
            this.canInstalmentCancel = false;
        },

        disableInstalmentRefund(item) {
            if (item.reference == undefined || item.reference == '' || item.refundAmount >= item.totalAmount || !this.acl.can('novalnet_extension.editor')) {
                return true;
            }

            return false;
        }
    }
});
