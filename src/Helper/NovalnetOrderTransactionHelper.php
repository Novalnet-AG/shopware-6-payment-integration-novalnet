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
     * get the order reference details.
     *
     * @param string|null $orderId
     * @param string|null $customerId
     *
     * @return Criteria
     */
    public function getOrderCriteria(string $orderId = null, string $customerId = null): Criteria
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
        $orderCriteria->addAssociation('price');
        $orderCriteria->addAssociation('taxStatus');

        return $orderCriteria;
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
            $orderEntity = $this->getOrderCriteria($order->getId(), $order->getOrderCustomer()->getCustomerId());
            $orderReference = $this->orderRepository->search($orderEntity, $salesChannelContext->getContext())->first();
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
     * Manage transaction
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $type
     * @param Request $request
     *
     * @return array
     */
    public function manageTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, string $type = 'transaction_capture', Request $request = null): array
    {
		// set the shop locale to display the message
        $localeCode = $this->helper->getShopLocale($context);
        
        $response = [];
        if ($type) {
            $parameters = [
                'transaction' => [
                    'tid' => $transactionData->getTid()
                ],
                'custom' => [
                    'shop_invoked' => 1
                ]
            ];
            
            if(!is_null($request))
            {
                $parameters['custom']['lang'] = $this->getAdminLanguage($request);
            }

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
                        $message = sprintf($this->translator->trans('NovalnetPayment.text.confirmMessage' , [], null, $localeCode), date('d/m/Y H:i:s')) . $this->newLine;
                        if ($response['transaction']['status'] === 'CONFIRMED') {
                            $upsertData['paidAmount'] = $transactionData->getAmount();
                        }
                        if(!empty($transactionData->getAdditionalDetails()) && !empty($transactionData->getPaymentType()) && in_array($transactionData->getPaymentType(), ['novalnetinvoice', 'novalnetinvoiceguarantee', 'novalnetprepayment', 'novalnetinvoiceinstalment'])) {
                            $appendComments = false;
                            $response['transaction']['bank_details'] = $this->helper->unserializeData($transactionData->getAdditionalDetails());
                            $message .=  $this->newLine . $this->helper->formBankDetails($response, $context);
                        }

                        if(! empty($response['instalment']['cycles_executed']) && !empty($transactionData->getPaymentType()) && in_array($transactionData->getPaymentType(), ['novalnetsepainstalment', 'novalnetinvoiceinstalment']))
                        {
                            $response['transaction']['amount'] = $transactionData->getAmount();
                            $upsertData['additionalDetails'] = $this->getInstalmentInformation($response);
                            $upsertData['additionalDetails'] = $this->helper->serializeData($upsertData['additionalDetails']);
                        }
                    } elseif ($response['transaction']['status'] === 'DEACTIVATED') {
                        $message = sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage' , [], null, $localeCode), date('d/m/Y H:i:s')) . $this->newLine;
                    }

                    $this->postProcess($transaction, $context, $message, $upsertData, $appendComments);

                    if(!is_null($request))
                    {
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
     * @param Request|null $request
     * @param boolean $instalmentCancel
     *
     * @return array
     */
    public function refundTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, int $refundAmount = 0, Request $request = null, bool $instalmentCancel = false) : array
    {
        // set the shop locale to display the message
        $localeCode = $this->helper->getShopLocale($context);
        
        $endPoint   = $this->helper->getActionEndpoint('transaction_refund');
        
        if((!is_null($request) && !empty($request->get('instalmentCancel'))) || !empty($instalmentCancel))
        {
            $parameters = [
                'instalment' => [
                    'tid'    => $transactionData->getTid()
                ],
                'custom' => [
                    'shop_invoked' => 1
                ]
            ];
            $endPoint   = $this->helper->getActionEndpoint('instalment_cancel');
        } else {
            $parameters = [
                'transaction' => [
                    'tid'    => (!is_null($request) && !empty($request->get('instalmentCycleTid'))) ? $request->get('instalmentCycleTid') : $transactionData->getTid()
                ],
                'custom' => [
                    'shop_invoked' => 1
                ]
            ];
            
            if (!is_null($request) && $request->get('reason')) {
                $parameters['transaction']['reason'] = $request->get('reason');
            }
        
            if (!empty($refundAmount)) {
                $parameters['transaction']['amount'] = $refundAmount;
            }
        }
        
        if(!is_null($request))
        {
            $parameters['custom']['lang'] = $this->getAdminLanguage($request);
        }
            
        $paymentSettings = $this->helper->getNovalnetPaymentSettings($this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));
        
        $response = $this->helper->sendPostRequest($parameters, $endPoint, $paymentSettings['NovalnetPayment.settings.accessKey']);

        if ($this->validator->isSuccessStatus($response)) {
            if (! empty($response['transaction']['status'])) {
                if (empty($refundAmount)) {
                    if (!empty($response['transaction']['refund']['amount'])) {
                        $refundAmount = $response['transaction']['refund']['amount'];
                    } else {
                        $refundAmount = (int) $transactionData->getAmount() - (int) $transactionData->getRefundedAmount();
                    }
                }
                $currency = !empty($response['transaction'] ['currency']) ? $response['transaction'] ['currency'] : $response ['transaction'] ['refund'] ['currency'];
                if(!empty($response['transaction']['refund']['amount'])) {
                    $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction']['refund']['amount'], $currency, $context);
                } else {
                    $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($refundAmount, $currency, $context);
                }
                
                $message = '';
                if(!empty($request) && !empty($request->get('instalmentCancel')))
                {
                    $message .= sprintf($this->translator->trans('NovalnetPayment.text.instalmentRefundComment' , [], null, $localeCode), $transactionData->getTid(), date('Y-m-d H:i:s'), $refundedAmountInBiggerUnit) . $this->newLine;
                } else {
                    $message .= sprintf($this->translator->trans('NovalnetPayment.text.refundComment', [], null, $localeCode), $transactionData->getTid(), $refundedAmountInBiggerUnit) . $this->newLine;
                    if (! empty($response['transaction']['refund']['tid'])) {
                        $message .= sprintf($this->translator->trans('NovalnetPayment.text.refundCommentForNewTid', [], null, $localeCode), $response ['transaction']['refund']['tid']);
                    }
                }

                $totalRefundedAmount = !empty($instalmentCancel) ? (int) $transactionData->getAmount() : (int) $transactionData->getRefundedAmount() + (int) $refundAmount;

                $this->postProcess($transaction, $context, $message, [
                    'id'             => $transactionData->getId(),
                    'refundedAmount' => $totalRefundedAmount,
                    'gatewayStatus'  => $response['transaction']['status'],
                ]);

                if ($totalRefundedAmount >= $transactionData->getAmount() && !is_null($request)) {
                    try {
                        
                        if(!empty($request->get('instalmentCancel')))
                        {
                            $this->orderTransactionState->cancel($transaction->getId(), $context);
                        } else {
                            $this->orderTransactionState->refund($transaction->getId(), $context);
                        }
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

            if (!empty($oldComments) && ! empty($append)) {
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

        $transaction = $transactionCollection->last();

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
                'reference'     => ''
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
                        'reference'     => (string) $response['transaction']['tid']
                ]);
            }
        }
        return $additionalDetails;
    }
    
    /**
     * Form admin language
     *
     * @param Request $request
     * @return string
     */
    public function getAdminLanguage(Request $request) : string
    {
        $langCode = '';
        if (!is_null($request->getPreferredLanguage())) {
            $language = explode('_', $request->getPreferredLanguage());
            $langCode = 'EN';
            if (! empty($language['0'])) {
                $langCode = strtoupper($language['0']);
            }
        }
        return $langCode;
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
		
		if($transactionData->getGatewayStatus() === 'CONFIRMED')
		{
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
}
