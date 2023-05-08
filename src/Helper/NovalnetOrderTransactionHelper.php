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

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\MailService as ArchiveMailService;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Novalnet\NovalnetPayment\Content\PaymentTransaction\NovalnetPaymentTransactionEntity;
use Shopware\Core\Checkout\Cart\Exception\OrderNotFoundException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;

class NovalnetOrderTransactionHelper
{
    /**
     * @var AbstractMailService
     */
    private $mailService;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var NovalnetHelper
     */
    private $helper;

    /**
     * @var NovalnetValidator
     */
    private $validator;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionState;

    /**
     * @var string
     */
    public $newLine = '/ ';

    /**
     * @var EntityRepositoryInterface
     */
    public $orderRepository;

    /**
     * @var EntityRepositoryInterface
     */
    public $orderTransactionRepository;

    /**
     * @var EntityRepositoryInterface
     */
    public $paymentMethodRepository;

    /**
     * @var EntityRepositoryInterface
     */
    public $novalnetTransactionRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $mailTemplateRepository;

    public function __construct(
        NovalnetHelper $helper,
        NovalnetValidator $validator,
        OrderTransactionStateHandler $orderTransactionState,
        TranslatorInterface $translator,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $orderTransactionRepository,
        EntityRepositoryInterface $paymentMethodRepository,
        EntityRepositoryInterface $novalnetTransactionRepository,
        ArchiveMailService $archiveMailService = null,
        AbstractMailService $mailService = null,
        EntityRepositoryInterface $mailTemplateRepository
    ) {
        $this->helper                        = $helper;
        $this->validator                     = $validator;
        $this->orderTransactionState         = $orderTransactionState;
        $this->translator                    = $translator;
        $this->orderRepository               = $orderRepository;
        $this->orderTransactionRepository    = $orderTransactionRepository;
        $this->paymentMethodRepository       = $paymentMethodRepository;
        $this->novalnetTransactionRepository = $novalnetTransactionRepository;
        $this->mailService                   = $archiveMailService ?? $mailService;
        $this->mailTemplateRepository        = $mailTemplateRepository;
    }

    /**
     * send novalnet order mail.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param OrderEntity $order
     * @param string $note
     * @param boolean $instalmentRecurring
     */
    public function sendMail(SalesChannelContext $salesChannelContext, OrderEntity $order, string $note , bool $instalmentRecurring = false): void
    {
        $customer = $order->getOrderCustomer();
        if (null === $customer) {
            return;
        }
        
        $transaction = $order->getTransactions()->last();
        
        $instalmentInfo = [];

        if(strpos($transaction->getPaymentMethod()->getHandlerIdentifier(), 'Instalment') !== false)
        {
            $instalmentInfo = $this->getNovalnetInstalmentInfo($salesChannelContext, $order->getOrderNumber());
        }

        $mailTemplate =  $this->getMailTemplate($salesChannelContext->getContext(), 'novalnet_order_confirmation_mail');

        if(is_null($mailTemplate))
        {
            return;
        }
        $data = new ParameterBag();
        $data->set(
            'recipients',
            [
                $customer->getEmail() => $customer->getFirstName().' '.$customer->getLastName(),
            ]
        );
        $data->set('senderName', $mailTemplate->getTranslation('senderName'));
        $data->set('salesChannelId', $order->getSalesChannelId());

        $data->set('contentHtml',$mailTemplate->getTranslation('contentHtml'));
        $data->set('contentPlain',$mailTemplate->getTranslation('contentPlain'));

        if($instalmentRecurring)
        {
            $data->set('subject', sprintf($this->translator->trans('NovalnetPayment.text.instalmentMailSubject'), $mailTemplate->getTranslation('senderName'), $order->getOrderNumber()));
        } else {
            $data->set('subject', $mailTemplate->getTranslation('subject'));
        }

        try {
            $this->mailService->send(
                $data->all(),
                $salesChannelContext->getContext(),
                [
                    'order' => $order,
                    'note' => $note,
                    'instalment' => $instalmentRecurring,
                    'salesChannel' => $salesChannelContext->getSalesChannel(),
                    'context' => $salesChannelContext,
                    'instalmentInfo' => $instalmentInfo,
                ]
            );
        } catch (\RuntimeException $e) {
            //Ignore
        }
    }

    /**
     * get the order mail template.
     *
     * @param Context $context
     * @param string $technicalName
     *
     * @return MailTemplateEntity|null
     */
    public function getMailTemplate(Context $context, string $technicalName): ? MailTemplateEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', $technicalName));
        $criteria->setLimit(1);

        /** @var MailTemplateEntity|null $mailTemplate */
        $mailTemplate = $this->mailTemplateRepository->search($criteria, $context)->first();

        return $mailTemplate;
    }

    /**
     * Finds a transaction by id.
     *
     * @param string $transactionId
     * @param Context|null $context
     *
     * @return OrderTransactionEntity|null
     */
    public function getTransactionById(string $transactionId, Context $context = null): ?OrderTransactionEntity
    {
        $transactionCriteria = new Criteria();
        $transactionCriteria->addFilter(new EqualsFilter('id', $transactionId));

        $transactionCriteria->addAssociation('order');

        $transactions = $this->orderTransactionRepository->search(
            $transactionCriteria,
            $context ?? Context::createDefaultContext()
        );

        if ($transactions->count() === 0) {
            return null;
        }

        return $transactions->first();
    }

    /**
     * Finds a payment method by id.
     *
     * @param string $paymentMethodId
     * @param Context|null $context
     *
     * @return PaymentMethodEntity|null
     */
    public function getPaymentMethodById(string $paymentMethodId, Context $context = null): ?PaymentMethodEntity
    {
        $paymentMethodCriteria = new Criteria();
        $paymentMethodCriteria->addFilter(new EqualsFilter('id', $paymentMethodId));

        $paymentMethod = $this->paymentMethodRepository->search(
            $paymentMethodCriteria,
            $context ?? Context::createDefaultContext()
        );

        if ($paymentMethod->count() === 0) {
            return null;
        }

        return $paymentMethod->first();
    }

    /**
     * send novalnet mail.
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     * @param string $note
     * @param boolean $instalmentRecurring
     *
     */
    public function prepareMailContent(OrderEntity $order, SalesChannelContext $salesChannelContext, string $note, $instalmentRecurring = false): void
    {
        if (!is_null($order->getOrderCustomer())) {
            $orderReference = $this->helper->getOrderCriteria($order->getId(), $salesChannelContext->getContext(), $order->getOrderCustomer()->getCustomerId());
            try {
                $emailConfigs = $this->helper->getNovalnetPaymentSettings($salesChannelContext->getSalesChannel()->getId());
                if(!empty($emailConfigs['NovalnetPayment.settings.emailMode']) && $emailConfigs['NovalnetPayment.settings.emailMode'] == 1)
                {
                    $this->sendMail($salesChannelContext, $orderReference, $note, $instalmentRecurring);

                }
            } catch (\RuntimeException $e) {
                //Ignore
            }
        }
    }

    /**
     * Fetch Novalnet transaction data.
     *
     * @param string $orderNumber
     * @param Context|null $context
     * @param string $tid
     *
     * @return NovalnetPaymentTransactionEntity
     */
    public function fetchNovalnetTransactionData(string $orderNumber = null, Context $context = null, string $tid = null): ? NovalnetPaymentTransactionEntity
    {
        $criteria = new Criteria();

        if (!is_null($tid)) {
            $criteria->addFilter(new OrFilter([
                new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber),
                new EqualsFilter('novalnet_transaction_details.tid', $tid)
            ]));
        } else {
            $criteria->addFilter(new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber));
        }

        $criteria->addSorting(
            new FieldSorting('createdAt', FieldSorting::DESCENDING)
        );

        return $this->novalnetTransactionRepository->search($criteria, $context ?? Context::createDefaultContext())->first();
    }

    /**
     * @throws OrderNotFoundException
     */
    private function getSalesChannelIdByOrderId(string $orderId, Context $context): string
    {
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->first();

        if ($order === null) {
            throw new OrderNotFoundException($orderId);
        }

        return $order->getSalesChannelId();
    }

    /**
     * Instalment cancel
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param Request $request
     *
     * @return array
     */
    public function cancelInstalmentPayment(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, Request $request): array
    {
        // set the shop locale to display the message
        $localeCode = $this->helper->getLocaleFromOrder($transaction->getOrderId());

        $endPoint   = $this->helper->getActionEndpoint('instalment_cancel');

        $parameters = [
            'instalment' => [
                'tid'    => $transactionData->getTid(),
                'cancel_type' => $request->get('cancelType')
            ],
            'custom' => [
                'shop_invoked' => 1,
                'lang' => strtoupper(substr($localeCode, 0, 2))
            ]
        ];

        $paymentSettings = $this->helper->getNovalnetPaymentSettings($this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));

        $response = $this->helper->sendPostRequest($parameters, $endPoint, $paymentSettings['NovalnetPayment.settings.accessKey']);
        
        if ($this->validator->isSuccessStatus($response)) {
            $additionalDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());
            if (isset($response['transaction']['refund'])) {
                $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction']['refund']['amount'], $transactionData->getCurrency(), $context);
                $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentRefundComment' , [], null, $localeCode), $transactionData->getTid(), date('Y-m-d H:i:s'), $refundedAmountInBiggerUnit);
                $totalRefundedAmount = $transactionData->getAmount();
            } else {
                $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentRemainRefundComment' , [], null, $localeCode), $transactionData->getTid(), date('Y-m-d H:i:s'));
                $totalRefundedAmount = $transactionData->getRefundedAmount();
                foreach ($additionalDetails['InstalmentDetails'] as $instalment)
                {
                    $totalRefundedAmount += empty($instalment['reference']) ? $instalment['amount'] : 0;
                }
            }

            $additionalDetails['InstalmentDetails'] = $this->updateInstalmentCancel($additionalDetails['InstalmentDetails'], $request->get('cancelType'));
            $additionalDetails['cancelType'] = $request->get('cancelType');

            $this->postProcess($transaction, $context, $message, [
                'id'             => $transactionData->getId(),
                'refundedAmount' => $totalRefundedAmount,
                'gatewayStatus'  => $response['transaction']['status'],
                'additionalDetails'  => $this->helper->serializeData($additionalDetails),
            ]);

            if ($totalRefundedAmount >= $transactionData->getAmount()) {
                try {
                    $this->orderTransactionState->cancel($transaction->getId(), $context);
                } catch (IllegalTransitionException $exception) {
                }
            }
        }

        return $response;
    }

    /**
     * Manage transaction
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $type
     *
     * @return array
     */
    public function manageTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, string $type = 'transaction_capture'): array
    {
        // set the shop locale to display the message
        $localeCode = $this->helper->getLocaleFromOrder($transaction->getOrderId());

        $response = [];
        if ($type) {
            $parameters = [
                'transaction' => [
                    'tid' => $transactionData->getTid()
                ],
                'custom' => [
                    'shop_invoked' => 1,
                    'lang' => strtoupper(substr($localeCode, 0, 2))
                ]
            ];
            $endPoint   = $this->helper->getActionEndpoint($type);

            $paymentSettings = $this->helper->getNovalnetPaymentSettings($this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));

            $response = $this->helper->sendPostRequest($parameters, $endPoint, $paymentSettings['NovalnetPayment.settings.accessKey']);

            if ($this->validator->isSuccessStatus($response)) {
                $message        = '';
                $appendComments = true;

                if (! empty($response['transaction']['status'])) {
                    $upsertData = [
                        'id'            => $transactionData->getId(),
                        'gatewayStatus' => $response['transaction']['status']
                    ];
                    if (in_array($response['transaction']['status'], ['CONFIRMED', 'PENDING'])) {
                        if ($response['transaction']['status'] === 'CONFIRMED') {
                            $upsertData['paidAmount'] = $transactionData->getAmount();
                        }
                        if (!empty($transactionData->getAdditionalDetails()) && !empty($transactionData->getPaymentType()) && in_array($transactionData->getPaymentType(), ['novalnetinvoice', 'novalnetinvoiceguarantee', 'novalnetprepayment', 'novalnetinvoiceinstalment'])) {
                            $appendComments = false;
                            $response['transaction']['bank_details'] = $this->helper->unserializeData($transactionData->getAdditionalDetails());
                            $message .=  $this->helper->formBankDetails($response, $context) . $this->newLine;
                        }

                        if (! empty($response['instalment']['cycles_executed']) && !empty($transactionData->getPaymentType()) && in_array($transactionData->getPaymentType(), ['novalnetsepainstalment', 'novalnetinvoiceinstalment']))
                        {
                            $response['transaction']['amount'] = $transactionData->getAmount();
                            $upsertData['additionalDetails'] = $this->getInstalmentInformation($response);
                            $upsertData['additionalDetails'] = $this->helper->serializeData($upsertData['additionalDetails']);
                        }

                        $message .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.confirmMessage' , [], null, $localeCode), date('d/m/Y H:i:s'));

                    } elseif ($response['transaction']['status'] === 'DEACTIVATED') {
                        $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage' , [], null, $localeCode), date('d/m/Y H:i:s'));
                    }

                    $this->postProcess($transaction, $context, $message, $upsertData, $appendComments);

                    if ($response['transaction']['status'] === 'CONFIRMED') {
                        $this->orderTransactionState->paid($transaction->getId(), $context);
                    } elseif ($response['transaction']['status'] === 'PENDING') {
                        $this->orderTransactionState->process($transaction->getId(), $context);
                    } elseif ($response['transaction']['status'] !== 'PENDING') {
                        $this->orderTransactionState->cancel($transaction->getId(), $context);
                    }
                }
            }
        }
        return $response;
    }

    /**
     * Book Amount
     *
     * @param integer $amount
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderEntity $order
     * @param Context $context
     *
     * @return array
     */
    public function bookOrderAmount(int $amount, NovalnetPaymentTransactionEntity $transactionData, OrderEntity $order, Context $context): array
    {
        $response = [];$message = '';
        // set the shop locale to display the message
        $localeCode = $this->helper->getLocaleFromOrder($order->getId());

        if (!is_null($transactionData->getAdditionalDetails()))
        {
            $paymentSettings = $this->helper->getNovalnetPaymentSettings($order->getSalesChannelId());

            $serverRequest = $this->helper->unserializeData($transactionData->getAdditionalDetails());
            $serverRequest ['transaction']['amount'] = $amount;
            // Built Payment token
            $serverRequest['transaction'] ['payment_data'] = ['token' => $serverRequest['token']];
            unset($serverRequest['token'], $serverRequest['transaction']['create_token']);
            // send request to server
            $response = $this->helper->sendPostRequest($serverRequest, $this->helper->getActionEndpoint('payment'), $paymentSettings['NovalnetPayment.settings.accessKey']);
            if ($this->validator->isSuccessStatus($response)) {
                $bookedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction'] ['amount'], $response['transaction'] ['currency'], $context);

                $message .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.bookedComment' , [], null, $localeCode), $bookedAmountInBiggerUnit, $response['transaction'] ['tid']);

                $transaction = $this->getOrder($order->getOrderNumber(), $context);

                $this->postProcess($transaction, $context, $message, [
                    'id'      => $transactionData->getId(),
                    'tid'     => $response['transaction']['tid'],
                    'amount'  => $response['transaction']['amount'],
                    'paidAmount'  => $response['transaction']['amount'],
                    'gatewayStatus'  => $response['transaction']['status'],
                ]);

                try {
                    $this->orderTransactionState->paid($transaction->getId(), $context);
                } catch (IllegalTransitionException $exception) {
                }
            }
        }
        return $response;
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
    public function refundTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, int $refundAmount, Request $request) : array
    {
        // set the shop locale to display the message
        $localeCode = $this->helper->getLocaleFromOrder($transaction->getOrderId());

        $endPoint   = $this->helper->getActionEndpoint('transaction_refund');

        $parameters = [
            'transaction' => [
                'tid'    => !empty($request->get('instalmentCycleTid')) ? $request->get('instalmentCycleTid') : $transactionData->getTid()
            ],
            'custom' => [
                'shop_invoked' => 1,
                'lang' => strtoupper(substr($localeCode, 0, 2))
            ]
        ];

        if ($request->get('reason')) {
            $parameters['transaction']['reason'] = $request->get('reason');
        }

        if (!empty($refundAmount)) {
            $parameters['transaction']['amount'] = $refundAmount;
        }
        $paymentSettings = $this->helper->getNovalnetPaymentSettings($this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));

        $response = $this->helper->sendPostRequest($parameters, $endPoint, $paymentSettings['NovalnetPayment.settings.accessKey']);

        if ($this->validator->isSuccessStatus($response)) {
            if (! empty($response['transaction']['status'])) {
                $currency = !empty($response['transaction'] ['currency']) ? $response['transaction'] ['currency'] : $response ['transaction'] ['refund'] ['currency'];
                $additionalDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());

                if(!empty($response['transaction']['refund']['amount'])) {
                    $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction']['refund']['amount'], $currency, $context);
                } else {
                    $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($refundAmount, $transactionData->getCurrency(), $context);
                }

                $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.refundComment', [], null, $localeCode), $transactionData->getTid(), $refundedAmountInBiggerUnit);

                if (! empty($response['transaction']['refund']['tid']))
                {
                    $message .= sprintf($this->translator->trans('NovalnetPayment.text.refundCommentForNewTid', [], null, $localeCode), $response ['transaction']['refund']['tid']);
                }

                if (in_array($transactionData->getpaymentType(), ['novalnetinvoiceinstalment', 'novalnetsepainstalment']))
                {
                    $additionalDetails['InstalmentDetails'] = $this->updateInstalmentCycle($additionalDetails['InstalmentDetails'], $refundAmount, $request->get('instalmentCycleTid'));
                }
                $totalRefundedAmount = (int) $transactionData->getRefundedAmount() + (int) $refundAmount;

                $this->postProcess($transaction, $context, $message, [
                    'id'             => $transactionData->getId(),
                    'refundedAmount' => $totalRefundedAmount,
                    'gatewayStatus'  => $response['transaction']['status'],
                    'additionalDetails'  => $this->helper->serializeData($additionalDetails),
                ]);

                if ($totalRefundedAmount >= $transactionData->getAmount()) {
                    try {
                        $this->orderTransactionState->refund($transaction->getId(), $context);
                    } catch (IllegalTransitionException $exception) {
                        // we can not ensure that the refund or refund partially status change is allowed
                        $this->orderTransactionState->cancel($transaction->getId(), $context);
                    }
                }
            }
        }
        return $response;
    }

    /**
     * Post payment process
     *
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $comments
     * @param array $upsertData
     * @param bool $append
     */
    public function postProcess(OrderTransactionEntity $transaction, Context $context, string $comments, array $upsertData = [], bool $append = true) : void
    {
        if (!empty($upsertData)) {
            $this->novalnetTransactionRepository->update([$upsertData], $context);
        }

        if (!is_null($transaction->getCustomFields()) && !empty($comments)) {
            $oldComments = $transaction->getCustomFields()['novalnet_comments'];

            if (!empty($oldComments) && !empty($append)) {
                $comments = $oldComments . $this->newLine . $comments;
            }

            $data = [
                'id' => $transaction->getId(),
                'customFields' => [
                    'novalnet_comments' => $comments,
                ],
            ];
            $this->orderTransactionRepository->update([$data], $context);
        }
    }

    /**
     * Get order.
     *
     * @param string $orderNumber
     * @param Context $context
     *
     * @return OrderTransactionEntity|null
     */
    public function getOrder(string $orderNumber, Context $context): ?OrderTransactionEntity
    {
        $order = $this->getOrderEntity($orderNumber, $context);

        if (null === $order) {
            return null;
        }

        $transactionCollection = $order->getTransactions();

        if (null === $transactionCollection) {
            return null;
        }

        $firstTransaction = $transactionCollection->first();
        $lastTransaction = $transactionCollection->last();
        if($firstTransaction->getCreatedAt()->format('Y-m-d H:i:s') > $lastTransaction->getCreatedAt()->format('Y-m-d H:i:s'))
        {
            $transaction = $firstTransaction;
        } else {
            $transaction = $lastTransaction;
        }

        if (null === $transaction) {
            return null;
        }

        return $transaction;
    }

    /**
     * Get order.
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
        $criteria->addSorting(
            new FieldSorting('transactions.createdAt', FieldSorting::ASCENDING)
        );
        $order = $this->orderRepository->search($criteria, $context)->first();

        if (null === $order) {
            return null;
        }

        return $order;
    }

    /**
     * Form instalment information.
     *
     * @param array $response
     *
     * @return array
     */
    public function getInstalmentInformation(array $response): array
    {
        $instalmentData = $response['instalment'];
        $additionalDetails = [];
        sort($instalmentData['cycle_dates']);
        foreach ($instalmentData['cycle_dates'] as $cycle => $futureInstalmentDate) {
            $cycle = $cycle + 1;
            $additionalDetails['InstalmentDetails'][$cycle] = [
                'amount'        => $instalmentData['cycle_amount'],
                'cycleDate'     => $futureInstalmentDate ? date('Y-m-d', strtotime($futureInstalmentDate)) : '',
                'cycleExecuted' => '',
                'dueCycles'     => '',
                'paidDate'      => '',
                'status'        => $this->translator->trans('NovalnetPayment.text.pendingMsg'),
                'reference'     => '',
                'refundAmount'  => 0,
            ];

            if($cycle == count($instalmentData['cycle_dates']))
            {
                $amount = $response['transaction']['amount'] - ($instalmentData['cycle_amount'] * ($cycle - 1));
                $additionalDetails['InstalmentDetails'][$cycle] = array_merge($additionalDetails['InstalmentDetails'][$cycle], [
                   'amount'    => $amount
                ]);
            }

            if($cycle == 1)
            {
                $additionalDetails['InstalmentDetails'][$cycle] = array_merge($additionalDetails['InstalmentDetails'][$cycle], [
                    'cycleExecuted' => !empty($instalmentData['cycles_executed']) ? $instalmentData['cycles_executed'] : '',
                    'dueCycles'     => !empty($instalmentData['pending_cycles']) ? $instalmentData['pending_cycles'] : '',
                    'paidDate'      => date('Y-m-d'),
                    'status'        => $this->translator->trans('NovalnetPayment.text.paidMsg'),
                    'reference'     => (string) $response['transaction']['tid'],
                    'refundAmount'  => 0,
                ]);
            }
        }

        return $additionalDetails;
    }

    /**
     * Return the novalnet instalment information.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $orderNumber
     *
     * @return array
     */
    public function getNovalnetInstalmentInfo(SalesChannelContext $salesChannelContext, $orderNumber): array
    {
        $transactionData = $this->fetchNovalnetTransactionData((string) $orderNumber, $salesChannelContext->getContext());

        if ($transactionData->getGatewayStatus() === 'CONFIRMED') {
            return $this->helper->unserializeData($transactionData->getAdditionalDetails());
        }

        return [];
    }

    /**
     * Fetch Novalnet last transaction data by customer id .
     *
     * @param string $customerNumber
     * @param string $paymentName
     * @param Context|null $context
     *
     * @return NovalnetPaymentTransactionEntity
     */
    public function fetchNovalnetReferenceData(string $customerNumber,string $paymentName, Context $context = null): ? NovalnetPaymentTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new AndFilter([
            new EqualsFilter('novalnet_transaction_details.customerNo', $customerNumber),
            new EqualsFilter('novalnet_transaction_details.paymentType', $paymentName),
            new EqualsFilter('novalnet_transaction_details.gatewayStatus', 'CONFIRMED'),
        ]));

        $criteria->addSorting(
            new FieldSorting('createdAt', FieldSorting::ASCENDING)
        );

        return $this->novalnetTransactionRepository->search($criteria, $context ?? Context::createDefaultContext())->first();
    }

    /**
     * Update Novalnet instalment cycles
     *
     * @param array $instalmentDetails
     * @param int $amount
     * @param string $instalmentCycleTid
     *
     * @return array
     */
    public function updateInstalmentCycle(array $instalmentDetails, int $amount, string $instalmentCycleTid): array
    {
        foreach ($instalmentDetails as $key => $values)
        {
            if ($values['reference'] == $instalmentCycleTid)
            {
                $instalmentDetails[$key]['refundAmount'] = (int) $values['refundAmount'] + $amount;
                if ($instalmentDetails[$key]['refundAmount'] >= $values['amount'])
                {
                    $instalmentDetails[$key]['status'] = $this->translator->trans('NovalnetPayment.text.refundedMsg');
                }
            }
        }
        return $instalmentDetails;
    }

    /**
     * Update Novalnet instalment cycles
     *
     * @param array $instalmentDetails
     * @param string|null $cycleType
     *
     * @return array
     */
    public function updateInstalmentCancel(array $instalmentDetails, ?string $cycleType): array
    {
        foreach ($instalmentDetails as $key => $values)
        {
            if ($cycleType == 'CANCEL_ALL_CYCLES' || empty($cycleType))
            {
                $instalmentDetails[$key]['refundAmount'] = !empty($values['reference']) ? $values['amount'] : 0;
                $instalmentDetails[$key]['status'] = !empty($values['reference']) ? $this->translator->trans('NovalnetPayment.text.refundedMsg') : $this->translator->trans('NovalnetPayment.text.cancelMsg');
            } elseif ($cycleType == 'CANCEL_REMAINING_CYCLES' && empty($values['reference']))
            {
                $instalmentDetails[$key]['status'] = $this->translator->trans('NovalnetPayment.text.cancelMsg');
            }
        }

        return $instalmentDetails;
    }


}
