<?php

/**
 * Novalnet payment plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to Novalnet End User License Agreement
 *
 * DISCLAIMER
 *
 * If you wish to customize Novalnet payment extension for your needs,
 * please contact technic@novalnet.de for more information.
 *
 * @category    Novalnet
 * @package     NovalnetPayment
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */
declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Helper;

use Doctrine\DBAL\Connection;
use Novalnet\NovalnetPayment\Content\PaymentTransaction\NovalnetPaymentTransactionEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

class NovalnetOrderTransactionHelper
{
    /**
     * @var string
     */
    public $newLine = '/ ';

    /**
     * @var EntityRepository
     */
    public $orderRepository;

    /**
     * @var EntityRepository
     */
    public $orderTransactionRepository;

    /**
     * @var EntityRepository
     */
    public $novalnetTransactionRepository;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var EntityRepository
     */
    private $mailTemplateRepository;

    /**
     * @var EntityRepository
     */
    private $stateMachineRepository;

    /**
     * Constructs a `NovalnetOrderTransactionHelper`
     *
     * @param NovalnetHelper $helper
     * @param OrderTransactionStateHandler $orderTransactionState
     * @param TranslatorInterface $translator
     * @param EntityRepository $orderRepository
     * @param EntityRepository $orderTransactionRepository
     * @param ContainerInterface $container
     * @param AbstractMailService $mailService
     * @param MediaService $mediaService,
    */
    public function __construct(
        private readonly NovalnetHelper $helper,
        private readonly OrderTransactionStateHandler $orderTransactionState,
        private readonly TranslatorInterface $translator,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        ContainerInterface $container,
        private readonly AbstractMailService $mailService,
        private readonly MediaService $mediaService,
        private readonly LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->container = $container;
        $this->novalnetTransactionRepository = $this->container->get('novalnet_transaction_details.repository');
        $this->stateMachineRepository = $this->container->get('state_machine_state.repository');
        $this->mailTemplateRepository = $this->container->get('mail_template.repository');
    }

    /**
     * Fetch Novalnet last transaction data by order number.
     *
     * @param string $orderNumber
     * @param Context $context
     * @param string|null $tid
     * @param bool $changePayment
     * @return NovalnetPaymentTransactionEntity|null
     */
    public function fetchNovalnetTransactionData(string $orderNumber, $context, ?string $tid = null, bool $changePayment = false): ?NovalnetPaymentTransactionEntity
    {
        $criteria = new Criteria();
        if (!empty($tid)) {
            if ($changePayment) {
                $criteria->addFilter(new AndFilter([
                    new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber),
                    new EqualsFilter('novalnet_transaction_details.tid', $tid)
                ]));
            } else {
                $criteria->addFilter(new OrFilter([
                    new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber),
                    new EqualsFilter('novalnet_transaction_details.tid', $tid)
                ]));
            }
        } else {
            $criteria->addFilter(new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber));
        }

        if (!$changePayment) {
            $criteria->addFilter(
                new MultiFilter(
                    'OR',
                    [
                        new EqualsFilter('novalnet_transaction_details.additionalDetails', null),
                        new NotFilter('AND', [new ContainsFilter('novalnet_transaction_details.additionalDetails', 'change_payment')]),
                    ]
                )
            );
        }

        $criteria->addSorting(
            new FieldSorting('createdAt', FieldSorting::DESCENDING)
        );

        /** @var NovalnetPaymentTransactionEntity|null */
        return $this->novalnetTransactionRepository->search($criteria, $context)->first();
    }

    /**
     * Get the Payment Name from the Novalnet Transaction Data
     *
     * @param Context $context
     * @param string $orderNumber
     * @param bool $changePayment
     * @return string|null
     */
    public function getPaymentName(Context $context, string $orderNumber, bool $changePayment = false): ?string
    {
        $paymentMethodName = '';
        $transactionData = $this->fetchNovalnetTransactionData((string) $orderNumber, $context, null, $changePayment);
        if (!empty($transactionData) && !empty($transactionData->getAdditionalDetails()) && str_contains($transactionData->getAdditionalDetails(), 'payment_name')) {
            $additionalDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());
            $paymentMethodName = !empty($additionalDetails['payment_name']) ? $additionalDetails['payment_name'] : '';
        } else {
            $order = $this->getOrderEntity($orderNumber, $context);
            if (!empty($order) && !empty($order->getTransactions())) {
                $transaction = $order->getTransactions()->last();
                if (!empty($transaction->getCustomFields()) && (!empty($transaction->getCustomFields()['novalnet_payment_name']))) {
                    $paymentMethodName = $transaction->getCustomFields()['novalnet_payment_name'];
                }
            }
        }

        return $paymentMethodName;
    }

    /**
     * Fetch the customer payment details
     *
     * @param Context $context
     * @param string $customerNo
     * @return array|null
     */
    public function getCustomerPaymentDetails(Context $context, string $customerNo): ?array
    {
        $customerPaymentMethod = [];
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('novalnet_transaction_details.customerNo', $customerNo));
        $criteria->addSorting(
            new FieldSorting('createdAt', FieldSorting::DESCENDING)
        );

        /** @var NovalnetPaymentTransactionEntity|null */
        $paymentDetails = $this->novalnetTransactionRepository->search($criteria, $context)->first();

        if (!empty($paymentDetails)) {
            $localeCode = $this->helper->getLocaleCodeFromContext($context, true);
            $paymentName = $this->getPaymentName($context, $paymentDetails->getOrderNo());
            $paymentDescription = $this->helper->getPaymentDescription($paymentDetails->getPaymentType(), $localeCode);

            $customerPaymentMethod = [
                'paymentName' => $paymentName,
                'paymentDescription' => $paymentDescription,
            ];
        }

        return $customerPaymentMethod;
    }

    /**
     * Get the order entity for a given order number
     *
     * @param string $orderNumber
     * @param Context $context
     *
     * @return OrderEntity|null
     */
    public function getOrderEntity(string $orderNumber, Context $context): ?OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('deliveries.shippingOrderAddress');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('addresses');
        $criteria->addAssociation('addresses.country');
        $criteria->addAssociation('orderCustomer.customer');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addSorting(
            new FieldSorting('transactions.createdAt', FieldSorting::ASCENDING)
        );
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            return null;
        }

        /** @var OrderEntity|null */
        return $order;
    }

    /**
     * Get the last order transaction for a given order number
     *
     * @param string $orderNumber
     * @param Context $context
     * @return OrderTransactionEntity|null
     */
    public function getOrder(string $orderNumber, Context $context): ?OrderTransactionEntity
    {
        $order = $this->getOrderEntity($orderNumber, $context);
        if ($order === null) {
            return null;
        }

        $transactionCollection = $order->getTransactions();

        if ($transactionCollection === null) {
            return null;
        }

        $transaction = $transactionCollection->last();

        if ($transaction === null) {
            return null;
        }

        return $transaction;
    }

    /**
     * Process the order transaction update
     *
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $comments
     * @param array $upsertData
     * @param bool $append
     */
    public function postProcess(OrderTransactionEntity $transaction, Context $context, string $comments, array $upsertData = [], bool $append = true): void
    {
        if (!empty($upsertData)) {
            $this->novalnetTransactionRepository->update([$upsertData], $context);
        }
        if (!empty($transaction->getCustomFields()) && !empty($comments)) {
            $oldComments = $transaction->getCustomFields()['novalnet_comments'];

            if (!empty($oldComments) && !empty($append)) {
                $oldCommentsAppend = explode('&&', (string) $oldComments);

                $oldCommentsAppend['0'] = $oldCommentsAppend['0'] . $this->newLine . $comments;

                $comments = implode('&&', $oldCommentsAppend);
            }
            $data = [
                'id' => $transaction->getId(),
                'customFields' => [
                    'novalnet_comments' => $comments,
                ],
            ];
            $this->orderTransactionUpsert($data, $context);
        }
    }

    /**
     * Send the mail notification to the customer based on the order status.
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     * @param string $note
     * @param bool $instalmentRecurring
     */
    public function prepareMailContent(OrderEntity $order, SalesChannelContext $salesChannelContext, string $note, $instalmentRecurring = false): void
    {
        if (!empty($order->getOrderCustomer())) {
            $orderReference = $this->getOrderCriteria($order->getId(), $salesChannelContext->getContext(), $order->getOrderCustomer()->getCustomerId());
            try {
                $emailMode = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.emailMode', $salesChannelContext->getSalesChannel()->getId());
                if (!empty($emailMode)) {
                    $this->sendMail($salesChannelContext, $orderReference, $note, $instalmentRecurring);
                }
            } catch (\RuntimeException $e) {
                $this->setWarningMessage($e->getMessage());
            }
        }
    }

    /**
     * Retrieves the order entity for a given order id and customer id
     *
     * @param string|null $orderId
     * @param Context $context
     * @param string|null $customerId
     *
     * @return OrderEntity|null
     */
    public function getOrderCriteria(?string $orderId, Context $context, ?string $customerId = null): ?OrderEntity
    {
        if (!empty($orderId)) {
            $orderCriteria = new Criteria([$orderId]);
        } else {
            $orderCriteria = new Criteria([]);
        }
        if (!empty($customerId)) {
            $orderCriteria->addFilter(
                new EqualsFilter('order.orderCustomer.customerId', $customerId)
            );
        }
        $orderCriteria->addAssociation('orderCustomer.salutation');
        $orderCriteria->addAssociation('orderCustomer.customer');
        $orderCriteria->addAssociation('currency');
        $orderCriteria->addAssociation('stateMachineState');
        $orderCriteria->addAssociation('lineItems');
        $orderCriteria->addAssociation('transactions');
        $orderCriteria->addAssociation('transactions.paymentMethod');
        $orderCriteria->addAssociation('addresses');
        $orderCriteria->addAssociation('deliveries.shippingMethod');
        $orderCriteria->addAssociation('addresses.country');
        $orderCriteria->addAssociation('deliveries.shippingOrderAddress.country');
        $orderCriteria->addAssociation('salesChannel');
        $orderCriteria->addAssociation('salesChannel.domains');
        $orderCriteria->addAssociation('salesChannel.mailHeaderFooter');
        $orderCriteria->addAssociation('price');
        $orderCriteria->addAssociation('taxStatus');

        /** @var OrderEntity|null */
        return $this->orderRepository->search($orderCriteria, $context)->first();
    }

    /**
     * Sends the mail notification to the customer based on the order status.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param OrderEntity $order
     * @param string $note
     * @param bool $instalmentRecurring
     */
    public function sendMail(SalesChannelContext $salesChannelContext, OrderEntity $order, string $note, bool $instalmentRecurring = false): void
    {
        $customer = $order->getOrderCustomer();
        if ($customer === null) {
            return;
        }
        $paymentName = '';
        $paymentDes = '';
        $transaction = $order->getTransactions()->last();
        $instalmentInfo = [];

        $novalnetTransaction = $this->fetchNovalnetTransactionData($order->getOrderNumber(), $salesChannelContext->getContext());
        $novalnetTransaction->setPaymentType($this->helper->getUpdatedPaymentType($novalnetTransaction->getPaymentType()));

        if (!empty($novalnetTransaction)) {
            $localeCode = $this->helper->getLocaleCodeFromContext($salesChannelContext->getContext(), true);
            $additionalDetails = $this->helper->unserializeData($novalnetTransaction->getAdditionalDetails());
            $paymentName = $this->getPaymentName($salesChannelContext->getContext(), $order->getOrderNumber());
            if (!empty($transaction) && !empty($transaction->getCustomFields()) && (!empty($transaction->getCustomFields()['novalnet_payment_description']))) {
                $paymentDes = $transaction->getCustomFields()['novalnet_payment_description'];
            } else {
                $paymentDes = $this->helper->getPaymentDescription($novalnetTransaction->getPaymentType(), $localeCode);
            }
            if (str_contains($novalnetTransaction->getPaymentType(), 'INSTALMENT') && $novalnetTransaction->getGatewayStatus() === 'CONFIRMED') {
                $instalmentInfo = $additionalDetails;
            }
        }

        $mailTemplate = $this->getMailTemplate($salesChannelContext->getContext(), 'novalnet_order_confirmation_mail');

        if (empty($mailTemplate)) {
            return;
        }
        $data = new ParameterBag();
        $data->set(
            'recipients',
            [
                $customer->getEmail() => $customer->getFirstName() . ' ' . $customer->getLastName(),
            ]
        );
        $data->set('senderName', $mailTemplate->getTranslation('senderName'));
        $data->set('salesChannelId', $order->getSalesChannelId());

        $data->set('contentHtml', $mailTemplate->getTranslation('contentHtml'));
        $data->set('contentPlain', $mailTemplate->getTranslation('contentPlain'));

        if ($instalmentRecurring) {
            $data->set('subject', sprintf($this->translator->trans('NovalnetPayment.text.instalmentMailSubject'), $mailTemplate->getTranslation('senderName'), $order->getOrderNumber()));
        } else {
            $data->set('subject', $mailTemplate->getTranslation('subject'));
        }

        if (!empty($mailTemplate->getMedia()) && !empty($mailTemplate->getMedia()->first())) {
            $data->set('binAttachments', $this->mailAttachments($mailTemplate, $salesChannelContext->getContext()));
        }

        $finishNovalnetComments = explode('&&', $note);

        $qrImage = $this->getQrImage($salesChannelContext->getContext(), $order->getOrderNumber());

        $notes = !empty($finishNovalnetComments[0]) ? $finishNovalnetComments[0] : $note;
        try {
            $this->mailService->send(
                $data->all(),
                $salesChannelContext->getContext(),
                [
                    'order' => $order,
                    'note' => $notes,
                    'instalment' => $instalmentRecurring,
                    'salesChannel' => $order->getSalesChannel(),
                    'context' => $salesChannelContext,
                    'instalmentInfo' => $instalmentInfo,
                    'qrImage' => $qrImage,
                    'paymentName' => $paymentName,
                    'paymentDescription' => $paymentDes,
                    'novalnetDetails' => $novalnetTransaction
                ]
            );
        } catch (\RuntimeException $e) {
            $this->setWarningMessage($e->getMessage());
        }
    }

    /**
     * Return the payment QR image.
     *
     * @param Context $context
     * @param string $orderNumber
     *
     * @return string
     */
    public function getQrImage(Context $context, string $orderNumber): string
    {
        $transactionData = $this->fetchNovalnetTransactionData((string) $orderNumber, $context);

        if (!empty($transactionData)) {
            if (!in_array($transactionData->getGatewayStatus(), ['FAILURE', 'DEACTIVATED']) && (in_array($transactionData->getPaymentType(), ['PREPAYMENT', 'INVOICE']) || (in_array($transactionData->getPaymentType(), ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE']) && $transactionData->getGatewayStatus() !== 'PENDING'))) {
                $serilazedData = $this->helper->unserializeData($transactionData->getAdditionalDetails());
                if (!empty($serilazedData['bankDetails']) && !empty($serilazedData['bankDetails']['qr_image'])) {
                    return $serilazedData['bankDetails']['qr_image'];
                }
            }
        }
        return '';
    }

    /**
     * Retrieves the mail template entity that is used for sending the order confirmation mail.
     * The template is identified by its technical name.
     *
     * @param Context $context
     * @param string $technicalName
     *
     * @return MailTemplateEntity|null
     */
    public function getMailTemplate(Context $context, string $technicalName): ?MailTemplateEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', $technicalName));
        $criteria->addAssociation('media.media');
        $criteria->setLimit(1);

        /** @var MailTemplateEntity|null $mailTemplate */
        $mailTemplate = $this->mailTemplateRepository->search($criteria, $context)->first();

        return $mailTemplate;
    }

    /**
     * Refund transaction
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param int $refundAmount
     * @param Request $request
     *
     * @return array
     */
    public function refundTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, int $refundAmount, Request $request): array
    {
        $parameter = [];
        $paymentType = $this->helper->getUpdatedPaymentType($transactionData->getpaymentType());

        $parameter['transaction'] = [
            'tid' => !empty($request->get('instalmentCycleTid')) ? $request->get('instalmentCycleTid') : $transactionData->getTid(),
        ];

        $localeCode = $this->helper->getLocaleFromOrder($transaction->getOrderId());
        $parameter['custom'] = [
            'shop_invoked' => 1,
            'lang' => strtoupper(substr($localeCode, 0, 2)),
        ];

        if ($request->get('reason')) {
            $parameter['transaction']['reason'] = $request->get('reason');
        }

        if (!empty($refundAmount)) {
            $parameter['transaction']['amount'] = $refundAmount;
        }
        $paymentAccessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));

        $response = $this->helper->sendPostRequest($parameter, $this->helper->getActionEndpoint('transaction_refund'), $paymentAccessKey);

        if ($this->helper->isSuccessStatus($response)) {
            $currency = !empty($response['transaction']['currency']) ? $response['transaction']['currency'] : $response['transaction']['refund']['currency'];

            if (!empty($response['transaction']['refund']['amount'])) {
                $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction']['refund']['amount'], $currency, $context);
            } else {
                $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($refundAmount, $transactionData->getCurrency(), $context);
            }

            $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.refundComment', [], null, $localeCode), $transactionData->getTid(), $refundedAmountInBiggerUnit);

            if (!empty($response['transaction']['refund']['tid'])) {
                $message .= sprintf($this->translator->trans('NovalnetPayment.text.refundCommentForNewTid', [], null, $localeCode), $response['transaction']['refund']['tid']);
            }

            $additionalDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());

            if (preg_match('/INSTALMENT/', $paymentType)) {
                $additionalDetails['InstalmentDetails'] = $this->updateInstalmentCycle($additionalDetails['InstalmentDetails'], $refundAmount, (string) $request->get('instalmentCycleTid'), $localeCode);
            }

            $totalRefundedAmount = (int) $transactionData->getRefundedAmount() + (int) $refundAmount;
            $this->postProcess($transaction, $context, $message, [
                'id' => $transactionData->getId(),
                'refundedAmount' => $totalRefundedAmount,
                'gatewayStatus' => $response['transaction']['status'],
                'additionalDetails' => !empty($additionalDetails) ? $this->helper->serializeData($additionalDetails) : null,
            ]);

            if ($totalRefundedAmount >= $transactionData->getAmount()) {
                try {
                    $this->orderTransactionState->refund($transaction->getId(), $context);
                } catch (IllegalTransitionException) {
                    $this->orderTransactionState->cancel($transaction->getId(), $context);
                }
            } elseif ($transactionData->getGatewayStatus() !== 'PENDING' && !preg_match('/INSTALMENT/', $paymentType)) {
                $this->orderTransactionState->refundPartially($transaction->getId(), $context);
            }
        }

        return $response;
    }

    /**
     * Handle transaction status update
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $status
     * @return array
     */
    public function manageTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, string $status): array
    {
        $response = [];
        $languageId = $this->helper->getLocaleFromOrder($transaction->getOrderId(), true);
        $localeCode = $this->helper->getLocaleFromOrder($transaction->getOrderId());
        $paymentType = $this->helper->getUpdatedPaymentType($transactionData->getpaymentType());

        if ($status) {
            $parameters = [
                'transaction' => [
                    'tid' => $transactionData->getTid(),
                ],
                'custom' => [
                    'shop_invoked' => 1,
                    'lang' => strtoupper(substr($localeCode, 0, 2))
                ]
            ];
            $paymentAccessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));
            $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint($status), $paymentAccessKey);

            if ($this->helper->isSuccessStatus($response)) {
                $message = '';
                $appendComments = true;

                if (!empty($response['transaction']['status'])) {
                    $transactionStatus = $response['transaction']['status'];
                    $upsertData = [
                        'id' => $transactionData->getId(),
                        'gatewayStatus' => $transactionStatus,
                    ];

                    if (in_array($transactionStatus, ['CONFIRMED', 'PENDING'])) {
                        if (!empty($transactionData->getAdditionalDetails()) && in_array($paymentType, ['INVOICE', 'GUARANTEED_INVOICE', 'PREPAYMENT', 'INSTALMENT_INVOICE'])) {
                            $appendComments = false;
                            $transactionAdditionDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());
                            $response['transaction']['bank_details'] = !empty($transactionAdditionDetails['bankDetails']) ? $transactionAdditionDetails['bankDetails'] : $transactionAdditionDetails;
                            $message .= $this->helper->formBankDetails($response, $context, $languageId) . $this->newLine;
                        }

                        if ($transactionStatus === 'CONFIRMED') {
                            $upsertData['paidAmount'] = $transactionData->getAmount();

                            if (preg_match('/INSTALMENT/', $paymentType)) {
                                $upsertData['additionalDetails'] = $this->helper->unserializeData($transactionData->getAdditionalDetails());
                                $response['transaction']['amount'] = $transactionData->getAmount();
                                $upsertData['additionalDetails']['InstalmentDetails'] = $this->getInstalmentInformation($response, $localeCode);
                                $upsertData['additionalDetails'] = $this->helper->serializeData($upsertData['additionalDetails']);
                            }
                        }

                        $message .= sprintf($this->translator->trans('NovalnetPayment.text.confirmMessage', [], null, $localeCode), date('d/m/Y H:i:s')) . $this->newLine;
                    } else {
                        $appendComments = false;
                        $message .= $this->helper->formBankDetails($response, $context, $languageId);
                        $message .= $this->newLine . $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage', [], null, $localeCode), date('d/m/Y H:i:s'));
                    }

                    $this->postProcess($transaction, $context, $message, $upsertData, $appendComments);

                    if ($transactionStatus === 'CONFIRMED') {
                        $completeStatus = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.completeStatus', $this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));
                        if (!empty($completeStatus)) {
                            $this->managePaymentStatus($completeStatus, $transaction->getId(), $context);
                        } else {
                            $this->orderTransactionState->paid($transaction->getId(), $context);
                        }
                    } elseif ($transactionStatus === 'PENDING') {
                        $this->managePaymentStatus('process', $transaction->getId(), $context);
                    } elseif ($transactionStatus !== 'PENDING') {
                        $this->orderTransactionState->cancel($transaction->getId(), $context);
                    }

                    if (in_array($paymentType, ['INVOICE', 'GUARANTEED_INVOICE', 'INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA']) && in_array($response['transaction']['status'], ['CONFIRMED', 'PENDING'])) {
                        $order = $this->getOrderCriteria($transaction->getOrderId(), $context);
                        $options = [
                            SalesChannelContextService::CUSTOMER_ID => $order->getOrderCustomer()->getCustomerId(),
                            SalesChannelContextService::CURRENCY_ID => !empty($order->getCurrencyId()) ? $order->getCurrencyId() : $order->getCurrency()->getId(),
                            SalesChannelContextService::PAYMENT_METHOD_ID => $transaction->getPaymentMethod()->getId()
                        ];
                        $salesChannelContext = $this->container->get(SalesChannelContextFactory::class)->create(Uuid::randomHex(), $order->getSalesChannelId(), $options);
                        $this->prepareMailContent($order, $salesChannelContext, $message);
                    }
                }
            }
        }

        return $response;
    }

    /**
     * Update instalment cycle
     *
     * @param array $instalmentDetails
     * @param int $amount
     * @param string $instalmentCycleTid
     * @param string $localeCode
     *
     * @return array
     */
    public function updateInstalmentCycle(array $instalmentDetails, int $amount, string $instalmentCycleTid, string $localeCode): array
    {
        foreach ($instalmentDetails as $key => $values) {
            if ($values['reference'] === $instalmentCycleTid) {
                $instalmentDetails[$key]['refundAmount'] = (int) $values['refundAmount'] + $amount;
                if ($instalmentDetails[$key]['refundAmount'] >= $values['amount']) {
                    $instalmentDetails[$key]['status'] = $this->translator->trans('NovalnetPayment.text.refundedMsg', [], null, $localeCode);
                } else {
                    $instalmentDetails[$key]['status'] = $this->translator->trans('NovalnetPayment.text.refundPartialMsg', [], null, $localeCode);
                }
            }
        }

        return $instalmentDetails;
    }

    /**
     * instalment Cancel Type
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param Request $request
     * @return array
     */
    public function instalmentCancelType(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, Request $request): array
    {
        $localeCode = $this->helper->getLocaleFromOrder($transaction->getOrderId());

        $parameter = [
            'instalment' => [
                'tid' => $transactionData->getTid(),
                'cancel_type' => $request->get('cancelType'),
            ],
            'custom' => [
                'shop_invoked' => 1,
                'lang' => strtoupper(substr($localeCode, 0, 2))
            ]
        ];

        $paymentAccessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));
        $response = $this->helper->sendPostRequest($parameter, $this->helper->getActionEndpoint('instalment_cancel'), $paymentAccessKey);

        if ($this->helper->isSuccessStatus($response)) {
            $additionalDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());
            if (isset($response['transaction']['refund'])) {
                $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentRefundComment', [], null, $localeCode), $transactionData->getTid(), date('Y-m-d H:i:s'));
                $totalRefundedAmount = $transactionData->getAmount();
            } else {
                $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentRemainRefundComment', [], null, $localeCode), $transactionData->getTid(), date('Y-m-d H:i:s'));
                $totalRefundedAmount = $transactionData->getRefundedAmount();
                foreach ($additionalDetails['InstalmentDetails'] as $instalment) {
                    $totalRefundedAmount += empty($instalment['reference']) ? $instalment['amount'] : 0;
                }
            }
            $additionalDetails['InstalmentDetails'] = $this->updateInstalmentCancel($additionalDetails['InstalmentDetails'], $response['instalment']['cancel_type'], $localeCode);
            $additionalDetails['cancelType'] = $request->get('cancelType');

            $this->postProcess($transaction, $context, $message, [
                'id' => $transactionData->getId(),
                'refundedAmount' => $totalRefundedAmount,
                'gatewayStatus' => $response['transaction']['status'],
                'additionalDetails' => $this->helper->serializeData($additionalDetails),
            ]);

            if ($totalRefundedAmount >= $transactionData->getAmount()) {
                try {
                    $this->orderTransactionState->cancel($transaction->getId(), $context);
                } catch (IllegalTransitionException) {
                }
            }
        }

        return $response;
    }

    /**
     * Update Novalnet instalment cycles
     *
     * @param array $instalmentDetails
     * @param string|null $cycleType
     * @param string $localeCode
     *
     * @return array
     */
    public function updateInstalmentCancel(array $instalmentDetails, ?string $cycleType, string $localeCode): array
    {
        foreach ($instalmentDetails as $key => $values) {
            if ($cycleType === 'ALL_CYCLES' || empty($cycleType)) {
                $instalmentDetails[$key]['refundAmount'] = !empty($values['reference']) ? $values['amount'] : 0;
                $instalmentDetails[$key]['status'] = !empty($values['reference']) ? $this->translator->trans('NovalnetPayment.text.refundedMsg', [], null, $localeCode) : $this->translator->trans('NovalnetPayment.text.cancelMsg', [], null, $localeCode);
            } elseif ($cycleType === 'REMAINING_CYCLES' && empty($values['reference'])) {
                $instalmentDetails[$key]['status'] = $this->translator->trans('NovalnetPayment.text.cancelMsg', [], null, $localeCode);
            }
        }

        return $instalmentDetails;
    }

    /**
     * Zero  book amount .
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderEntity $order
     * @param Context $context
     * @param int $bookAmount
     *
     *
     * @return array
     */
    public function bookOrderAmount(NovalnetPaymentTransactionEntity $transactionData, OrderEntity $order, Context $context, int $bookAmount): array
    {
        $localeCode = $this->helper->getLocaleFromOrder($order->getId());
        $response = [];
        $parameters = [];
        $paymentAccessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $order->getSalesChannelId());

        $parameters['merchant'] = $this->helper->merchantParameter($order->getSalesChannelId());

        // Built custom parameters.
        $parameters['custom'] = [
            'lang' => strtoupper(substr($localeCode, 0, 2))
        ];
        $parameters['transaction'] = [
            'order_no' => $order->getOrderNumber(),
            'currency' => $transactionData->getCurrency(),
            'payment_type' => $transactionData->getPaymentType(),
        ];
        $salesChannelContext = $this->helper->getSalesChannelDetails($order);
        if (!empty($salesChannelContext->getCustomer())) {
            $parameters['customer'] = $this->helper->getCustomerData($salesChannelContext->getCustomer());
        }
        $parameters['transaction'] = $this->helper->systemParameter($context, $parameters['transaction']);
        $parameters['transaction']['payment_type'] = $transactionData->getPaymentType();
        $parameters['transaction']['amount'] = $bookAmount;
        $parameters['transaction']['payment_data'] = ['token' => $transactionData->getTokenInfo()];

        $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('payment'), $paymentAccessKey);

        if ($this->helper->isSuccessStatus($response)) {
            $bookAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction']['amount'], $response['transaction']['currency'], $context);
            $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.bookedComment', [], null, $localeCode), $bookAmountInBiggerUnit, $response['transaction']['tid']);
            $transaction = $this->getOrder($order->getOrderNumber(), $context);

            $this->postProcess($transaction, $context, $message, [
                'id' => $transactionData->getId(),
                'tid' => $response['transaction']['tid'],
                'amount' => $response['transaction']['amount'],
                'paidAmount' => $response['transaction']['amount'],
                'gatewayStatus' => $response['transaction']['status'],
            ]);

            try {
                $completeStatus = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.completeStatus', $order->getSalesChannelId());
                if (!empty($completeStatus)) {
                    $this->managePaymentStatus($completeStatus, $transaction->getId(), $context);
                } else {
                    $this->orderTransactionState->paid($transaction->getId(), $context);
                }
            } catch (IllegalTransitionException) {
            }
        }

        return $response;
    }

    /**
     * Form instalment information.
     *
     * @param array $response
     * @param string $localeCode
     *
     * @return array
     */
    public function getInstalmentInformation(array $response, string $localeCode): array
    {
        $instalmentData = $response['instalment'];
        $additionalDetails = [];

        if (!empty($instalmentData['cycle_dates'])) {
            $futureInstalmentDate = $instalmentData['cycle_dates'];
            foreach (array_keys($futureInstalmentDate) as $cycle) {
                $additionalDetails[$cycle] = [
                    'amount' => $instalmentData['cycle_amount'],
                    'cycleDate' => !empty($futureInstalmentDate[$cycle + 1]) ? date('Y-m-d', strtotime((string) $futureInstalmentDate[$cycle + 1])) : '',
                    'cycleExecuted' => '',
                    'dueCycles' => '',
                    'paidDate' => '',
                    'status' => $this->translator->trans('NovalnetPayment.text.pendingMsg', [], null, $localeCode),
                    'reference' => '',
                    'refundAmount' => 0,
                ];

                if ($cycle === count($instalmentData['cycle_dates'])) {
                    $amount = $response['transaction']['amount'] - ($instalmentData['cycle_amount'] * ($cycle - 1));
                    $additionalDetails[$cycle] = array_merge($additionalDetails[$cycle], [
                        'amount' => $amount
                    ]);
                }

                if ($cycle === 1) {
                    $additionalDetails[$cycle] = array_merge($additionalDetails[$cycle], [
                        'cycleExecuted' => !empty($instalmentData['cycles_executed']) ? $instalmentData['cycles_executed'] : '',
                        'dueCycles' => !empty($instalmentData['pending_cycles']) ? $instalmentData['pending_cycles'] : '',
                        'paidDate' => date('Y-m-d'),
                        'status' => $this->translator->trans('NovalnetPayment.text.paidMsg', [], null, $localeCode),
                        'reference' => (string) $response['transaction']['tid'],
                        'refundAmount' => 0,
                    ]);
                }
            }
        }

        return $additionalDetails;
    }

    /**
     * Get Payment Details for Recurring process.
     *
     * @param Context $context
     * @param string $orderNumber
     *
     * @return array
     */
    public function getBookingInfoForRecurring(Context $context, string $orderNumber, string $orderId): array
    {
        $transactionDetails = $this->fetchNovalnetTransactionData($orderNumber, $context, null, true);
        $additionalDetails = $this->helper->unserializeData($transactionDetails->getAdditionalDetails());
        $locale = $this->helper->getLocaleFromOrder($orderId);
        $datas = [
            'payment_details' => [
                'type' => (!empty($additionalDetails['subscription']['payment_details']['type'])) ? $additionalDetails['subscription']['payment_details']['type'] : $this->helper->getUpdatedPaymentType($transactionDetails->getPaymentType()),
                'process_mode' => 'direct',
                'name' => (!empty($additionalDetails['subscription']['payment_details']['name'])) ? $additionalDetails['subscription']['payment_details']['name'] : $this->helper->getUpdatedPaymentName($transactionDetails->getPaymentType(), $locale),
            ],
            'booking_details' => [
                'test_mode' => (!empty($additionalDetails['subscription']['booking_details']['test_mode'])) ? $additionalDetails['subscription']['booking_details']['test_mode'] : 0,
                'payment_action' => 'payment',
            ],
        ];

        return $datas;
    }

    /**
     * Fetch Novalnet last transaction data by customer id .
     *
     * @param string $customerNumber
     * @param string $orderNumber
     * @param string $paymentType
     * @param Context|null $context
     *
     * @return NovalnetPaymentTransactionEntity|null
     */
    public function fetchNovalnetReferenceData(string $customerNumber, string $orderNumber, string $paymentType, Context $context): ?NovalnetPaymentTransactionEntity
    {
        $oldpaymentName = $this->helper->getUpdatedPaymentType($paymentType, true);
        $criteria = new Criteria();
        $criteria->addFilter(new AndFilter([
            new EqualsFilter('novalnet_transaction_details.customerNo', $customerNumber),
            new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber),
            new EqualsAnyFilter('novalnet_transaction_details.paymentType', [$paymentType, $oldpaymentName]),
        ]));

        if ($paymentType === 'GUARANTEED_INVOICE') {
            $criteria->addFilter(new ContainsFilter('novalnet_transaction_details.additionalDetails', 'dob'));
        }

        $criteria->addSorting(
            new FieldSorting('createdAt', FieldSorting::DESCENDING)
        );

        /** @var NovalnetPaymentTransactionEntity|null */
        $novalnetReferenceData = $this->novalnetTransactionRepository->search($criteria, $context)->first();

        return $novalnetReferenceData;
    }

    /**
     * Update payment transaction status.
     *
     * @param string $paymentStatus
     * @param string $transactionId
     * @param Context $context
     *
     */
    public function managePaymentStatus(string $paymentStatus, string $transactionId, Context $context): void
    {
        $status = strtolower($paymentStatus);

        if ($status === 'paid') {
            $this->orderTransactionState->paid($transactionId, $context);
        } elseif (in_array($status, ['cancel', 'cancelled'])) {
            $this->orderTransactionState->cancel($transactionId, $context);
        } elseif ($status === 'failed') {
            $this->orderTransactionState->fail($transactionId, $context);
        } elseif ($status === 'paidpartially') {
            $this->orderTransactionState->paidPartially($transactionId, $context);
        } elseif (in_array($status, ['process', 'pending'])) {
            try {
                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('technicalName', 'in_progress'));
                $criteria->addFilter(new EqualsFilter('stateMachine.technicalName', 'order_transaction.state'));
                $criteria->addAssociation('stateMachine');
                /** @var StateMachineStateEntity|null */
                $status = $this->stateMachineRepository->search($criteria, $context)->first();
                if (!empty($status)) {
                    $connection = $this->container->get(Connection::class);
                    $connection->executeQuery(sprintf('
						UPDATE `order_transaction`
						SET `state_id` = UNHEX(\'%s\') WHERE `id` = UNHEX(\'%s\');
					 ', $status->getId(), $transactionId));
                }
            } catch (IllegalTransitionException) {
            }
        } elseif ($status === 'open') {
            $this->orderTransactionState->reopen($transactionId, $context);
        } elseif ($status === 'authorized') {
            $this->orderTransactionState->authorize($transactionId, $context);
        }
    }

    /**
     * Update payment transaction status.
     *
     * @param string $message
     *
     * @return void
     */
    public function setWarningMessage(string $message): void
    {
        $this->logger->warning($message);
    }

    public function updateSubscriptionData(string $status, string $paymentMethodId, string $orderId): void
    {
        $novalnetSubsriptionTableExist = $this->getSchemaManager();

        if (!empty($novalnetSubsriptionTableExist) && $this->container->has('novalnet_subscription.repository')) {
            $connection = $this->container->get(Connection::class);
            $data = $connection->fetchOne(sprintf('SELECT `id` FROM `novalnet_subscription` WHERE `order_id` = UNHEX(\'%s\')', $orderId));
            if (!empty($data)) {
                if (in_array($status, ['ON_HOLD',  'PENDING',  'CONFIRMED'])) {
                    $subscriptionData = $connection->fetchAllAssociative(sprintf('SELECT * FROM `novalnet_subscription` WHERE `order_id` = UNHEX(\'%s\')', $orderId));
                    if (!empty($subscriptionData)) {
                        $subscriptionData = array_values($subscriptionData)[0];
                        $unit = ['d' => 'days', 'w' => 'weeks', 'm' => 'months', 'y' => 'years'];
                        $nextDate = $endingDate = date('Y-m-d H:i:s');

                        if (!empty($subscriptionData['trial_interval'])) {
                            $nextDate = date('Y-m-d H:i:s', strtotime('+ ' . $subscriptionData['trial_interval'] . $unit[$subscriptionData['trial_unit']], strtotime(date('Y-m-d H:i:s'))));
                            $endingDate = $nextDate;
                        } else {
                            $nextDate = date('Y-m-d H:i:s', strtotime('+ ' . $subscriptionData['interval'] . $unit[$subscriptionData['unit']], strtotime(date('Y-m-d H:i:s'))));
                        }
                        $endingDate = empty($subscriptionData['length']) ? null : date('Y-m-d H:i:s', strtotime('+ ' . $subscriptionData['length'] . $unit[$subscriptionData['unit']], strtotime($endingDate)));

                        if (!empty($subscriptionData['length'])) {
                            $connection->executeQuery(sprintf('UPDATE `novalnet_subscription` SET `status` = \'ACTIVE\', next_date = \'%s\', ending_at = \'%s\', payment_method_id = UNHEX(\'%s\') WHERE `order_id` = UNHEX(\'%s\')', $nextDate, $endingDate, $paymentMethodId, $orderId));
                        } else {
                            $connection->executeQuery(sprintf('UPDATE `novalnet_subscription` SET `status` = \'ACTIVE\', next_date = \'%s\', payment_method_id = UNHEX(\'%s\') WHERE `order_id` = UNHEX(\'%s\')', $nextDate, $paymentMethodId, $orderId));
                        }
                        $connection->executeQuery(sprintf('UPDATE `novalnet_subs_cycle` SET `status` = \'SUCCESS\', payment_method_id = UNHEX(\'%s\') WHERE `order_id` = UNHEX(\'%s\')', $paymentMethodId, $orderId));
                    }
                } else {
                    $connection->executeQuery(sprintf('UPDATE `novalnet_subscription` SET `status` = \'CANCELLED\', cancel_reason = \'Parent order getting failed\', cancelled_at = \'%s\', payment_method_id = UNHEX(\'%s\') WHERE `order_id` = UNHEX(\'%s\')', date('Y-m-d H:i:s'), $paymentMethodId, $orderId));
                    $connection->executeQuery(sprintf('UPDATE `novalnet_subs_cycle` SET `status` = \'FAILURE\', payment_method_id = UNHEX(\'%s\') WHERE `order_id` = UNHEX(\'%s\')', $paymentMethodId, $orderId));
                }
            }
        }
    }

    /**
     * Get the mail attachment
     *
     * @param MailTemplateEntity $mailTemplate
     * @param Context $context
     *
     * @retrun array
     */
    public function mailAttachments(MailTemplateEntity $mailTemplate, Context $context): array
    {
        foreach ($mailTemplate->getMedia() ?? [] as $mailTemplateMedia) {
            if ($mailTemplateMedia->getMedia() === null) {
                continue;
            }

            $attachments[] = $this->mediaService->getAttachment(
                $mailTemplateMedia->getMedia(),
                $context
            );
        }

        return $attachments ?? [];
    }

    public function updateChangePayment(array $input, string $orderId, Context $context, bool $ordeUpdatedPayment = false): void
    {
        $paymentType = $input['transaction']['payment_type'] ?? 'NOVALNET_PAYMENT';
        $locale = $this->helper->getLocaleCodeFromContext($context, true);

        // insert novalnet transaction details
        $insertData = [
            'id' => Uuid::randomHex(),
            'paymentType' => $paymentType,
            'paidAmount' => 0,
            'tid' => $input['transaction']['tid'],
            'currency' => $input['transaction']['currency'],
            'gatewayStatus' => $input['transaction']['status'],
            'customerNo' => !empty($input['customer']['customer_no']) ? $input['customer']['customer_no'] : '',
            'additionalDetails' => [
                'payment_name' => (isset($input['custom']['input5']) && $input['custom']['input5'] === 'Paymentname' && (isset($input['custom']['inputval5']) && !empty($input['custom']['inputval5']))) ? $input['custom']['inputval5'] : $this->helper->getUpdatedPaymentName('NOVALNET_PAYMENT', $this->helper->getLocaleCodeFromContext($context)),
                'change_payment' => true
            ]
        ];

        if (empty($ordeUpdatedPayment)) {
            $dataSubscription = [
                'payment_details' => [
                    'type' => $paymentType,
                    'name' => $this->helper->getUpdatedPaymentName($paymentType, $locale),
                ],
                'booking_details' => [
                    'test_mode' => $input['transaction']['test_mode'],
                ],
                'aboId' => $input['custom']['subscriptionId'],
                'paymentMethodId' => $input['custom']['paymentMethodId'],
            ];
            $amount = $input['transaction']['amount'];
            $orderNo = $input['transaction']['order_no'];
        } else {
            $dataSubscription['payment_details'] = $input['paymentData']['payment_details'];
            if (isset($input['paymentData']['booking_details']) && isset($input['paymentData']['booking_details']['test_mode'])) {
                $dataSubscription['booking_details']['test_mode'] = $input['paymentData']['booking_details']['test_mode'];
            }
            $amount = 0;
            $orderNo = $input['custom']['inputval2'];
        }

        $insertData['amount'] = $amount;
        $insertData['orderNo'] = $orderNo;

        $insertData['additionalDetails']['subscription'] = $dataSubscription;

        if (!empty($input['transaction']['payment_data']['token'])) {
            $insertData['tokenInfo'] = $input['transaction']['payment_data']['token'];
        }

        $insertData['additionalDetails'] = $this->helper->serializeData($insertData['additionalDetails']);

        // Upsert data into novalnet_transaction_details.repository
        $this->helper->updateTransactionData($insertData, $context);

        if (isset($input['event']) && isset($input['event']['type'])) {
            $novalnetSubsriptionTableExist = $this->getSchemaManager();
            $connection = $this->container->get(Connection::class);
            if (!empty($novalnetSubsriptionTableExist) && $this->container->has('novalnet_subscription.repository')) {
                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('orderId', $orderId));
                $criteria->addAssociation('novalnetSubscription');

                $subCycleData = $this->container->get('novalnet_subs_cycle.repository')->search($criteria, $context)->first();

                $data = $connection->fetchOne(sprintf('SELECT `id` FROM `novalnet_subscription` WHERE `order_id` = UNHEX(\'%s\')', $orderId));

                if (!empty($data)) {
                    $connection->executeQuery(sprintf('UPDATE `novalnet_subscription` SET `payment_method_id` = UNHEX(\'%s\') WHERE `order_id` = UNHEX(\'%s\')', $input['custom']['paymentMethodId'], $orderId));
                    $connection->executeQuery(sprintf('UPDATE `novalnet_subs_cycle` SET `payment_method_id` = UNHEX(\'%s\') WHERE `order_id` = UNHEX(\'%s\')', $input['custom']['paymentMethodId'], $orderId));
                }

                if (!empty($subCycleData) && isset($input['custom']['input2']) && $input['custom']['input2'] === 'subParentOrderNumber') {
                    $connection->executeQuery(sprintf('UPDATE `novalnet_subs_cycle` SET `status` = \'SUCCESS\', payment_method_id = UNHEX(\'%s\') WHERE `order_id` = UNHEX(\'%s\')', $input['custom']['paymentMethodId'], $orderId));

                    $criteria = new Criteria([$subCycleData->get('novalnetSubscription')->getId()]);
                    $criteria->addAssociation('subsOrders');
                    $subscriptiondata = $this->container->get('novalnet_subscription.repository')->search($criteria, $context)->first();

                    $subordercount = $subscriptiondata->get('subsOrders')->count();
                    $subOrdersLast = $subscriptiondata->get('subsOrders')->last();
                    $count = (empty($subscriptiondata->getTrialInterval()) ? $subordercount : ($subordercount - 1));
                    $unit = ($subscriptiondata->getUnit() === 'd') ? 'days' : ($subscriptiondata->getUnit() === 'w' ? 'weeks' : ($subscriptiondata->getUnit() === 'm' ? 'months' : 'years'));
                    if ($subCycleData->getCycles() === null) {
                        $data['cycles'] = $count;
                    }
                    $subscriptionDate = $this->getNextDate($subscriptiondata, $input['custom']['inputval4']);

                    $subscription = [
                        'id' => $subscriptiondata->getId(),
                        'status' => $subscriptiondata->getLength() === $count ? 'PENDING_CANCEL' : 'ACTIVE',
                        'nextDate' => $subscriptionDate['nextDate'],
                        'paymentMethodId' => $input['custom']['paymentMethodId'],
                    ];
                    if (!empty($subscriptionDate['endingDate'])) {
                        $subscription['endingAt'] = $subscriptionDate['endingDate'];
                    }
                    $this->container->get('novalnet_subscription.repository')->upsert([$subscription], $context);
                    $subData = ['id' => $subCycleData->getId(), 'status' => 'SUCCESS'];
                    $this->container->get('novalnet_subs_cycle.repository')->upsert([$subData], $context);

                    if (!empty($subOrdersLast->getOrderId()) && $subOrdersLast->getStatus() !== 'PENDING' && ($subscriptiondata->getLength() === 0 || $subscriptiondata->getLength() > $count)) {
                        $subData = [
                            'id' => Uuid::randomHex(),
                            'orderId' => null,
                            'interval' => $subscriptiondata->getInterval(),
                            'subsId' => $subscriptiondata->getId(),
                            'period' => $subscriptiondata->getUnit(),
                            'amount' => $subscriptiondata->getAmount(),
                            'paymentMethodId' => $input['custom']['paymentMethodId'],
                            'status' => 'PENDING',
                            'cycles' => $count + 1,
                            'cycleDate' => date('Y-m-d H:i:s', strtotime('+ ' . $subscriptiondata->getInterval() . $unit, strtotime((string) $subscriptiondata->getNextDate()->format('Y-m-d H:i:s')))),
                        ];
                        $this->container->get('novalnet_subs_cycle.repository')->upsert([$subData], $context);
                    }
                }
            }
        }
    }

    /**
     * Update the order transaction details
     *
     * @param array $data
     * @param Context $context

     */
    public function orderTransactionUpsert(array $data, Context $context): void
    {
        $this->orderTransactionRepository->upsert([$data], $context);
    }

    /**
     * Returns the updated next date.
     *
     * @param int $interval
     * @param string $period
     * @param string $date
     * @param  $subscription
     * @param string $id
     *
     * @return string
     */
    public function getUpdatedNextDate(int $interval, string $period, $date, $subscription, string $id): string
    {
        if (!empty($subscription->getLastDayMonth()) && $period === 'months') {
            return date('Y-m-d H:i:s', strtotime("last day of +$interval month", strtotime($date)));
        } elseif ($period === 'months') {
            if (date('d', strtotime($date)) === date('d', strtotime($date . "+ $interval month"))) {
                $nextDate = date('Y-m-d H:i:s', strtotime($date . " +$interval month"));
            } else {
                $connection = $this->container->get(Connection::class);
                $connection->executeQuery(sprintf('UPDATE `novalnet_subscription` SET `last_day_month` = \'%s\' WHERE `id` = UNHEX(\'%s\')', 1, $id));
                $nextDate = date('Y-m-d H:i:s', strtotime("last day of +$interval month", strtotime($date)));
            }

            return $nextDate;
        }

        return date('Y-m-d H:i:s', strtotime('+ ' . $interval . $period, strtotime($date)));
    }

    /**
     * Updates the subscription status for a given order ID.
     *
     * @param string $orderId
     * @param Context $context
     * @param string $locale
     */
    public function subscriptionStatusUpdate(string $orderId, Context $context, string $locale): void
    {
        $novalnetSubsriptionTableExist = $this->getSchemaManager();

        if (!empty($novalnetSubsriptionTableExist) && $this->container->has('novalnet_subscription.repository')) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $orderId));
            $criteria->addAssociation('novalnetSubscription');
            $subsCycleData = $this->container->get('novalnet_subs_cycle.repository')->search($criteria, $context)->first();
            if (!empty($subsCycleData)) {
                $cancelReason = sprintf($this->translator->trans('NovalnetPayment.text.subscriptionChargeback', [], null, $locale), date('Y-m-d H:i:s'));
                $subscription = [
                    'id' => $subsCycleData->getSubsId(),
                    'status' => 'CANCELLED',
                    'cancelReason' => $cancelReason,
                    'cancelledAt' => date('Y-m-d H:i:s'),
                ];

                $this->container->get('novalnet_subscription.repository')->upsert([$subscription], $context);
                $subData = ['id' => $subsCycleData->getId(), 'status' => 'FAILURE'];
                $this->container->get('novalnet_subs_cycle.repository')->upsert([$subData], $context);
            }
        }
    }

    /**
     * Update the subscription status for a given order ID.
     *
     * @param string $orderId
     * @param Context $context
     * @param string $locale
     * @param string $salesChannelId
     * @param string $customerId
     * @return void
     */
    public function subscriptionCreditStatusUpdate(string $orderId, Context $context, string $locale, string $salesChannelId, string $customerId): void
    {
        $schemaManager = $this->getSchemaManager();

        if (empty($schemaManager) || !$this->container->has('novalnet_subscription.repository')) {
            return;
        }

        $subscriptionRepo = $this->container->get('novalnet_subscription.repository');
        $subsCycleRepo = $this->container->get('novalnet_subs_cycle.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addAssociation('novalnetSubscription');

        $subsCycleData = $subsCycleRepo->search($criteria, $context)->first();
        $novalnetSubscription = $subsCycleData?->get('novalnetSubscription');

        if (empty($novalnetSubscription)) {
            return;
        }

        $systemConfig = $this->container->get(SystemConfigService::class);
        $subscriptionConfig = $systemConfig->getDomain(
            'NovalnetSubscription.config.',
            $salesChannelId,
            true
        );

        $restrictMultipleOrders = $subscriptionConfig['NovalnetSubscription.config.restrictMultipleOrders'] ?? false;

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId))
                 ->addFilter(new EqualsFilter('order.salesChannelId', $salesChannelId))
                 ->addFilter(new NotFilter('AND', [new EqualsFilter('status', 'CANCELLED')]))
                 ->addSorting(new FieldSorting('createdAt', 'DESC'));

        $existingSubscription = $subscriptionRepo->search($criteria, $context)->first();

        if ((!empty($restrictMultipleOrders) && empty($existingSubscription)) || empty($restrictMultipleOrders)) {
            $subscriptionId = $novalnetSubscription->getId();

            $criteria = new Criteria([$subscriptionId]);
            $criteria->addAssociation('subsOrders');

            $subscriptionData = $subscriptionRepo->search($criteria, $context)->first();

            if (empty($subscriptionData) || $subscriptionData->getStatus() !== 'CANCELLED') {
                return;
            }
            $subscriptionDate = $this->getNextDate($subscriptionData, $subscriptionId);
            $subscriptionUpdate = [
                'id' => $subscriptionData->getId(),
                'status' => 'ACTIVE',
                'nextDate' => $subscriptionDate['nextDate'],
            ];

            if (!empty($subscriptionDate['endingDate'])) {
                $subscriptionUpdate['endingAt'] = $subscriptionDate['endingDate'];
            }

            $subscriptionRepo->upsert([$subscriptionUpdate], $context);

            $subsCycleRepo->upsert([
                ['id' => $subsCycleData->getId(), 'status' => 'SUCCESS'],
            ], $context);
        }
    }

    /**
     * Checks if the novalnet_subscription table exists in the database.
     *
     * @return bool
     */
    public function getSchemaManager(): bool
    {
        $connection = $this->container->get(Connection::class);
        if (method_exists($connection, 'getSchemaManager')) {
            $schemaManager = $connection->getSchemaManager();
        } else {
            $schemaManager = $connection->createSchemaManager();
        }
        if ($schemaManager->tablesExist(['novalnet_subscription']) === true) {
            return true;
        }

        return false;
    }

    /**
     * Gets the next date of a subscription.
     *
     * @param $subscriptionData
     * @param string $subscriptionId
     * @return array
     */
    public function getNextDate($subscriptionData, string $subscriptionId): array
    {
        $formatDate = $subscriptionData->getNextDate()?->format('Y-m-d H:i:s') ?? date('Y-m-d H:i:s');
        $endingDate = '';
        $nextDate = '';
        $unitValue = ($subscriptionData->getUnit() === 'd') ? 'days' : ($subscriptionData->getUnit() === 'w' ? 'weeks' : ($subscriptionData->getUnit() === 'm' ? 'months' : 'years'));

        if (!empty($subscriptionData->getId())) {
            $endingDate = date('Y-m-d H:i:s');

            if (!empty($subscriptionData->getTrialInterval())) {
                $nextDate = $this->getUpdatedNextDate((int) $subscriptionData->getTrialInterval(), $unitValue, $endingDate, $subscriptionData, $subscriptionId);
                $endingDate = $nextDate;
            } else {
                $nextDate = $this->getUpdatedNextDate((int) $subscriptionData->getInterval(), $unitValue, $endingDate, $subscriptionData, $subscriptionId);
            }

            $endingDate = empty($subscriptionData->getLength()) ? null :
            $this->getUpdatedNextDate((int) $subscriptionData->getLength(), $unitValue, $endingDate, $subscriptionData, $subscriptionId);
        } else {
            $nextDate = $this->getUpdatedNextDate((int) $subscriptionData->getInterval(), $unitValue, $formatDate, $subscriptionData, $subscriptionId);
        }

        return [
            'nextDate' => $nextDate,
            'endingDate' => $endingDate,
        ];
    }

    /**
     * Retrieves the order transaction entity for a given transaction id.
     *
     * @param string $transactionId
     * @param Context $context
     * @return OrderTransactionEntity|null
     */
    public function getOrderTransactionDetails(string $transactionId, Context $context): ?OrderTransactionEntity
    {
        $orderCriteria = new Criteria([$transactionId]);

        $orderCriteria->addAssociation('order');
        $orderCriteria->addAssociation('order.lineItems');
        $orderCriteria->addAssociation('order.deliveries.');
        $orderCriteria->addAssociation('order.deliveries.shippingOrderAddress');
        $orderCriteria->addAssociation('order.deliveries.shippingOrderAddress.country');
        $orderCriteria->addAssociation('order.addresses');
        $orderCriteria->addAssociation('order.addresses.country');
        $orderCriteria->addAssociation('order.orderCustomer.customer');
        $orderCriteria->addAssociation('order.salesChannel');
        $orderCriteria->addAssociation('order.salesChannel.salesChannels');
        $orderCriteria->addAssociation('order.salesChannel.customers');
        $orderCriteria->addAssociation('order.salesChannel.currency');
        $orderCriteria->addAssociation('order.salesChannel.domains');
        $orderCriteria->addAssociation('order.language.locale');
        $orderTransaction = $this->orderTransactionRepository->search($orderCriteria, $context)->first();

        /** @var OrderTransactionEntity|null */
        return $orderTransaction;
    }

    /**
     * Get the parent order number for the given subscription id.
     * @param string $id The subscription id to find the parent order number for.
     * @param Context $context The sales channel context to use for the query.
     * @return null|string The parent order number if found, otherwise null.
     */
    public function getSubParentOrderNumber(string $id, Context $context): ?string
    {
        $orderNumber = '';
        $novalnetSubsriptionTableExist = $this->getSchemaManager();
        $connection = $this->container->get(Connection::class);
        if (!empty($novalnetSubsriptionTableExist) && $this->container->has('novalnet_subscription.repository')) {
            $criteria = new Criteria([$id]);
            $criteria->addAssociation('order');
            $subData = $this->container->get('novalnet_subscription.repository')->search($criteria, $context)->first();
            $orderNumber = $subData->getOrder()->getOrderNumber();
        }

        return $orderNumber;
    }

    /**
     * Gets the sales channel ID by order ID.
     *
     * @param string $orderId The order ID to find the sales channel ID for.
     * @param Context $context The context to use for the query.
     *
     * @return string The sales channel ID if found, otherwise throws an exception.
     *
     * @throws \RuntimeException If no order is found for the given order ID.
     */
    private function getSalesChannelIdByOrderId(string $orderId, Context $context): string
    {
        /** @var OrderEntity|null */
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->first();
        if ($order === null) {
            throw new \RuntimeException('Order not founded');
        }

        return $order->getSalesChannelId();
    }
}
