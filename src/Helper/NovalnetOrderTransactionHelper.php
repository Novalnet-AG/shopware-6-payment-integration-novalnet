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
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Novalnet\NovalnetPayment\Content\PaymentTransaction\NovalnetPaymentTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Uuid\Uuid;

class NovalnetOrderTransactionHelper
{
    /**
     * @var AbstractMailService
     */
    private $mailService;
    
    /**
     * @var NovalnetHelper
     */
    private $helper;

    /**
     * @var TranslatorInterface
     */
    private $translator;
  
    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionState;

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
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var EntityRepository
     */
    public $novalnetTransactionRepository;
    
    /**
     * @var EntityRepository
     */
    public $paymentMethodRepository;
    
    /**
     * @var EntityRepository
     */
    private $mailTemplateRepository;
   
    /**
     * @var EntityRepository
     */
    private $stateMachineRepository;
    
    /**
     * @var LoggerInterface
     */
    private $logger;
    
    /**
     * @var MediaService
     */
    private $mediaService;
    
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
        NovalnetHelper $helper,
        OrderTransactionStateHandler $orderTransactionState,
        TranslatorInterface $translator,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        ContainerInterface $container,
        AbstractMailService $mailService,
        MediaService $mediaService,
        LoggerInterface $logger
    ) {
        $this->helper                        = $helper;
        $this->orderTransactionState         = $orderTransactionState;
        $this->translator                    = $translator;
        $this->orderRepository               = $orderRepository;
        $this->orderTransactionRepository    = $orderTransactionRepository;
        $this->container                     = $container;
        $this->paymentMethodRepository       = $this->container->get('payment_method.repository');
        $this->novalnetTransactionRepository = $this->container->get('novalnet_transaction_details.repository');
        $this->stateMachineRepository        = $this->container->get('state_machine_state.repository');
        $this->mailService                   = $mailService;
        $this->mailTemplateRepository        = $this->container->get('mail_template.repository');
        $this->mediaService                  = $mediaService;
        $this->logger     = $logger;
    }

    /**
     * Fetch Novalnet transaction data.
     *
     * @param string $orderNumber
     * @param Context|null $context
     * @param string|null $tid
     * @param bool $changePayment
     *
     * @return NovalnetPaymentTransactionEntity
     */
    public function fetchNovalnetTransactionData(string $orderNumber, $context = null, string $tid = null, bool $changePayment = false) : ? NovalnetPaymentTransactionEntity
    {
        $criteria = new criteria();
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
        return $this->novalnetTransactionRepository->search($criteria, $context ?? Context::createDefaultContext())->first();
    }
    
    /**
     * Fetch payment name.
     *
     * @param salesChannelContext $salesChannelContext
     * @param string $orderNumber
     * @param bool $changePayment
     *
     * @return string
     */
    public function getPaymentName(SalesChannelContext $salesChannelContext, string $orderNumber, bool $changePayment = false): ?string
    {
        $paymentMethodName = '';
        $transactionData = $this->fetchNovalnetTransactionData((string) $orderNumber, $salesChannelContext->getContext(), null, $changePayment);
        if (!empty($transactionData) && !empty($transactionData->getAdditionalDetails()) && strpos($transactionData->getAdditionalDetails(), 'payment_name') !== false) {
            $additionalDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());
            $paymentMethodName = !empty($additionalDetails['payment_name']) ? $additionalDetails['payment_name'] : '';
        } else {
            $order = $this->getOrderEntity($orderNumber, $salesChannelContext->getContext());
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
     * Fetch order.
     *
     * @param string $orderNumber
     * @param Context $context
     *
     * @return OrderEntity|null
     */
    public function getOrderEntity(string $orderNumber, Context $context) : ?OrderEntity
    {
        $criteria = new criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('transactions.paymentMethod');
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
        if (!empty($transaction->getCustomFields()) && !empty($comments)) {
            $oldComments = $transaction->getCustomFields()['novalnet_comments'];

            if (!empty($oldComments) && !empty($append)) {
                $oldCommentsAppend = explode("&&", $oldComments);
                
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
        if (!empty($order->getOrderCustomer())) {
            $orderReference = $this->getOrderCriteria($order->getId(), $salesChannelContext->getContext(), $order->getOrderCustomer()->getCustomerId());
            try {
                $emailConfigs = $this->helper->getNovalnetPaymentSettings($salesChannelContext->getSalesChannel()->getId());
                if (!empty($emailConfigs['NovalnetPayment.settings.emailMode']) && $emailConfigs['NovalnetPayment.settings.emailMode'] == 1) {
                    $this->sendMail($salesChannelContext, $orderReference, $note, $instalmentRecurring);
                }
            } catch (\RuntimeException $e) {
				$this->setWarningMessage($e->getMessage());
            }
        }
    }
    
    
    /**
     * get the order reference details.
     *
     * @param string|null $orderId
     * @param Context $context
     * @param string|null $customerId
     *
     * @return OrderEntity|null
     */
    public function getOrderCriteria(string $orderId = null, Context $context, string $customerId = null): ?OrderEntity
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
     * send novalnet order mail.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param OrderEntity $order
     * @param string $note
     * @param boolean $instalmentRecurring
     */
    public function sendMail(SalesChannelContext $salesChannelContext, OrderEntity $order, string $note, bool $instalmentRecurring = false): void
    {
        $customer = $order->getOrderCustomer();
        if (null === $customer) {
            return;
        }
        $paymentName = '';
        $transaction = $order->getTransactions()->last();
        $instalmentInfo = [];
        
        $novalnetTransaction = $this->fetchNovalnetTransactionData($order->getOrderNumber(), $salesChannelContext->getContext());
        $novalnetTransaction->setPaymentType($this->helper->getUpdatedPaymentType($novalnetTransaction->getPaymentType()));
     
        if (!empty($novalnetTransaction)) {
                $additionalDetails = $this->helper->unserializeData($novalnetTransaction->getAdditionalDetails());
                $paymentName = $this->getPaymentName($salesChannelContext , $order->getOrderNumber());

            if (strpos($novalnetTransaction->getPaymentType(), 'INSTALMENT') !== false && $novalnetTransaction->getGatewayStatus() === 'CONFIRMED') {
                $instalmentInfo = $additionalDetails;
            }
        }
       
        $mailTemplate =  $this->getMailTemplate($salesChannelContext->getContext(), 'novalnet_order_confirmation_mail');

        if (empty($mailTemplate)) {
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

        $data->set('contentHtml', $mailTemplate->getTranslation('contentHtml'));
        $data->set('contentPlain', $mailTemplate->getTranslation('contentPlain'));

        if ($instalmentRecurring) {
            $data->set('subject', sprintf($this->translator->trans('NovalnetPayment.text.instalmentMailSubject'), $mailTemplate->getTranslation('senderName'), $order->getOrderNumber()));
        } else {
            $data->set('subject', $mailTemplate->getTranslation('subject'));
        }
        
        if (!empty($mailTemplate->getMedia()) && !empty($mailTemplate->getMedia()->first()))
        {
			$data->set('binAttachments', $this->mailAttachments($mailTemplate, $salesChannelContext->getContext()));
		}
		
        $finishNovalnetComments = explode("&&", $note);

        $notes = isset($finishNovalnetComments[0]) && !empty($finishNovalnetComments[0])  ? $finishNovalnetComments[0] : $note;
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
                    'paymentName' => $paymentName,
                    'novalnetDetails' => $novalnetTransaction
                ]
            );
        } catch (\RuntimeException $e) {
			$this->setWarningMessage($e->getMessage());
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
        $criteria->addAssociation('media.media');
        $criteria->setLimit(1);

        /** @var MailTemplateEntity|null $mailTemplate */
        $mailTemplate = $this->mailTemplateRepository->search($criteria, $context)->first();

        return $mailTemplate;
    }
    
    /**
     * Refund Transaction data.
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
        $parameter = [];
        $paymentType = $this->helper->getUpdatedPaymentType($transactionData->getpaymentType());
        
        $parameter['transaction'] = [
            'tid'    => !empty($request->get('instalmentCycleTid')) ? $request->get('instalmentCycleTid') : $transactionData->getTid(),
        ];
        
        $localeCode = $this->helper->getLocaleFromOrder($transaction->getOrderId());
        $parameter['custom'] = [
            'shop_invoked' => 1,
            'lang'      => strtoupper(substr($localeCode, 0, 2)),
        ];
        
        if ($request->get('reason')) {
            $parameter['transaction']['reason'] = $request->get('reason');
        }
        
        if (!empty($refundAmount)) {
            $parameter['transaction']['amount'] = $refundAmount;
        }
        $paymentSettings = $this->helper->getNovalnetPaymentSettings($this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));

        $response = $this->helper->sendPostRequest($parameter, $this->helper->getActionEndpoint('transaction_refund'), $paymentSettings['NovalnetPayment.settings.accessKey']);

        if ($this->helper->isSuccessStatus($response)) {
            $currency = !empty($response['transaction']['currency']) ? $response['transaction']['currency'] : $response['transaction']['refund']['currency'];
           
            if (!empty($response['transaction']['refund']['amount'])) {
                $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction']['refund']['amount'], $currency, $context);
            } else {
                $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($refundAmount, $transactionData->getCurrency(), $context);
            }

            $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.refundComment', [], null, $localeCode), $transactionData->getTid(), $refundedAmountInBiggerUnit);

            if (! empty($response['transaction']['refund']['tid'])) {
                $message .= sprintf($this->translator->trans('NovalnetPayment.text.refundCommentForNewTid', [], null, $localeCode), $response ['transaction']['refund']['tid']);
            }
            
            $additionalDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());
            
            if (preg_match('/INSTALMENT/', $paymentType)) {
                $additionalDetails['InstalmentDetails'] = $this->updateInstalmentCycle($additionalDetails['InstalmentDetails'], $refundAmount, (string)$request->get('instalmentCycleTid'), $localeCode);
            }
            
            $totalRefundedAmount = (int) $transactionData->getRefundedAmount() + (int) $refundAmount;
            $this->postProcess($transaction, $context, $message, [
                    'id'             => $transactionData->getId(),
                    'refundedAmount' => $totalRefundedAmount,
                    'gatewayStatus'  => $response['transaction']['status'],
                    'additionalDetails'  => !empty($additionalDetails) ? $this->helper->serializeData($additionalDetails) : null,
                    
                ]);
                
            if ($totalRefundedAmount >= $transactionData->getAmount()) {
                try {
                    $this->orderTransactionState->refund($transaction->getId(), $context);
                } catch (IllegalTransitionException $exception) {
                    $this->orderTransactionState->cancel($transaction->getId(), $context);
                }
            }
        }
        return $response;
    }
    
     /**
     * Refund Transaction data.
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $status
     *
     *
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
                    'tid' => $transactionData->getTid()
                ],
                'custom' => [
                    'shop_invoked' => 1,
                    'lang' => strtoupper(substr($localeCode, 0, 2))
                ]
            ];
            $paymentSettings = $this->helper->getNovalnetPaymentSettings($this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));

            $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint($status), $paymentSettings['NovalnetPayment.settings.accessKey']);
            if ($this->helper->isSuccessStatus($response)) {
                $message        = '';
                $appendComments = true;
                
                $response['manageEvent'] = $status;
                $responsePaymentType = $response['transaction']['payment_type'];

                if (! empty($response['transaction']['status'])) {
                     $transactionStatus = $response['transaction']['status'];
                     $upsertData = [
                        'id'            => $transactionData->getId(),
                        'gatewayStatus' => $transactionStatus
                     ];
                    
                     if (in_array($transactionStatus, ['CONFIRMED', 'PENDING'])) {
                         $transactionAdditionDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());
                         if (!empty($transactionData->getAdditionalDetails()) && !empty($paymentType) && preg_match('/INVOICE/', $paymentType)  || preg_match('/PREPAYMENT/', $paymentType)) {
                             $appendComments = false;
                             $response['transaction']['bank_details'] = !empty($transactionAdditionDetails['bankDetails']) ? $transactionAdditionDetails['bankDetails'] : $transactionAdditionDetails;
                             $message .= $this->helper->formBankDetails($response, $context, $languageId) . $this->newLine;
                         }
                        
                         if ($transactionStatus == 'CONFIRMED') {
                             $upsertData['paidAmount'] =  $transactionData->getAmount();
                            
                             if (preg_match('/INSTALMENT/', $paymentType)) {
                                 $upsertData['additionalDetails'] = $this->helper->unserializeData($transactionData->getAdditionalDetails());
                                 $response['transaction']['amount'] = $transactionData->getAmount();
                                 $upsertData['additionalDetails']['InstalmentDetails'] = $this->getInstalmentInformation($response, $localeCode);
                                 $upsertData['additionalDetails'] = $this->helper->serializeData($upsertData['additionalDetails']);
                             }
                         }
                        
                         $message .= sprintf($this->translator->trans('NovalnetPayment.text.confirmMessage', [], null, $localeCode), date('d/m/Y H:i:s')). $this->newLine;
                     } elseif ($transactionStatus === 'DEACTIVATED') {
                         $appendComments = false;
                         $message .= $this->helper->formBankDetails($response, $context, $languageId);
                         $message .= $this->newLine . $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage', [], null, $localeCode), date('d/m/Y H:i:s'));
                     }

                     $this->postProcess($transaction, $context, $message, $upsertData, $appendComments);

                     if ($transactionStatus === 'CONFIRMED') {
                         if (!empty($paymentSettings['NovalnetPayment.settings.completeStatus'])) {
                             $completeStatus = $paymentSettings['NovalnetPayment.settings.completeStatus'];
                             $this->managePaymentStatus($completeStatus, $transaction->getId(), $context);
                         } else {
                             $this->orderTransactionState->paid($transaction->getId(), $context);
                         }
                     } elseif ($transactionStatus === 'PENDING') {
                         $this->managePaymentStatus('process', $transaction->getId(), $context);
                     } elseif ($transactionStatus !== 'PENDING') {
                         $this->orderTransactionState->cancel($transaction->getId(), $context);
                     }
                }
            }
        }
        return $response;
    }
    
    
    /**
    * get the SalesChannel Id By OrderId
    *
    *  @param string $orderId
    *  @param Context $context
    *
    *  @return string
    */
    private function getSalesChannelIdByOrderId(string $orderId, Context $context): string
    {
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->first();
        if ($order === null) {
           throw new \RuntimeException('Order not founded');
        }

        return $order->getSalesChannelId();
    }
    
    /**
     * Update Novalnet instalment cycles
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
            if ($values['reference'] == $instalmentCycleTid) {
                $instalmentDetails[$key]['refundAmount'] = (int) $values['refundAmount'] + $amount;
                if ($instalmentDetails[$key]['refundAmount'] >= $values['amount']) {
                    $instalmentDetails[$key]['status'] = $this->translator->trans('NovalnetPayment.text.refundedMsg', [], null, $localeCode);
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
    
    public function instalmentCancelType(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, Request $request) : array
    {
        $localeCode = $this->helper->getLocaleFromOrder($transaction->getOrderId());

        $parameter = [
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
        $response = $this->helper->sendPostRequest($parameter, $this->helper->getActionEndpoint('instalment_cancel'), $paymentSettings['NovalnetPayment.settings.accessKey']);

        if ($this->helper->isSuccessStatus($response)) {
            $additionalDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());
            if (isset($response['transaction']['refund'])) {
                $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction']['refund']['amount'], $transactionData->getCurrency(), $context);
                $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentRefundComment', [], null, $localeCode), $transactionData->getTid(), date('Y-m-d H:i:s'), $refundedAmountInBiggerUnit);
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
                'id'             => $transactionData->getId(),
                'refundedAmount' => $totalRefundedAmount,
                'gatewayStatus'  => $response['transaction']['status'],
                'additionalDetails'  => $this->helper->serializeData($additionalDetails),
            ]);

            if ($totalRefundedAmount >= $transactionData->getAmount()) {
                try {
                    $this->orderTransactionState->cancel($transaction->getId(), $context);
                } catch (IllegalTransitionException $exception) {
					$this->setWarningMessage($exception->getMessage());
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
            if ($cycleType == 'ALL_CYCLES' || empty($cycleType)) {
                $instalmentDetails[$key]['refundAmount'] = !empty($values['reference']) ? $values['amount'] : 0;
                $instalmentDetails[$key]['status'] = !empty($values['reference']) ? $this->translator->trans('NovalnetPayment.text.refundedMsg', [], null, $localeCode) : $this->translator->trans('NovalnetPayment.text.cancelMsg', [], null, $localeCode);
            } elseif ($cycleType == 'REMAINING_CYCLES' && empty($values['reference'])) {
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
    public function bookOrderAmount(NovalnetPaymentTransactionEntity $transactionData, OrderEntity $order, Context $context, int $bookAmount) : array
    {
        $localeCode = $this->helper->getLocaleFromOrder($order->getId());
        $response = [];
        $parameters = [];
		$paymentSettings = $this->helper->getNovalnetPaymentSettings($order->getSalesChannelId());
		$orderCollection = $this->getOrderCriteria($order->getId(), $context, $order->getOrderCustomer()->getCustomerId());
		$parameters['merchant'] = [
			'signature' => $paymentSettings['NovalnetPayment.settings.clientId'],
			'tariff'    => $paymentSettings['NovalnetPayment.settings.tariff']
		];

		// Built custom parameters.
		$parameters['custom'] = [
			'lang'      => $localeCode
		];
		$parameters['transaction'] = [
			'order_no'       => $order->getOrderNumber(),
			'currency'       => $transactionData->getCurrency(),
			'payment_type'   => $transactionData->getPaymentType(),
			'system_name'    => 'Shopware6',
			'system_ip'      => $this->helper->getIp('SYSTEM'),
			'system_version' => $this->helper->getVersionInfo($context),
		];
		$parameters['transaction']['payment_type'] = $transactionData->getPaymentType();
		$parameters['customer'] = $this->bookAmountRequest($orderCollection, $context);
		$parameters['customer']['email'] = $order->getOrderCustomer()->getEmail();
		$parameters['customer']['customer_ip'] = $order->getOrderCustomer()->getRemoteAddress();
		$parameters['customer']['customer_no'] = $order->getOrderCustomer()->getCustomerNumber();
		$parameters['transaction']['amount'] = $bookAmount;
		$parameters['transaction']['payment_data'] = ['token' => $transactionData->getTokenInfo()];

		$response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('payment'), $paymentSettings['NovalnetPayment.settings.accessKey']);

		if ($this->helper->isSuccessStatus($response)) {
			$bookAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction'] ['amount'], $response['transaction'] ['currency'], $context);
			$message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.bookedComment', [], null, $localeCode), $bookAmountInBiggerUnit, $response['transaction'] ['tid']);
			$transaction = $this->getOrder($order->getOrderNumber(), $context);

			$this->postProcess($transaction, $context, $message, [
			   'id'      => $transactionData->getId(),
			   'tid'     => $response['transaction']['tid'],
			   'amount'  => $response['transaction']['amount'],
			   'paidAmount'  => $response['transaction']['amount'],
			   'gatewayStatus'  => $response['transaction']['status'],
			]);

			try {
				if (!empty($paymentSettings['NovalnetPayment.settings.completeStatus'])) {
					$completeStatus = $paymentSettings['NovalnetPayment.settings.completeStatus'];
					$this->managePaymentStatus($completeStatus, $transaction->getId(), $context);
				} else {
					 $this->orderTransactionState->paid($transaction->getId(), $context);
				}
			} catch (IllegalTransitionException $exception) {
				$this->setWarningMessage($exception->getMessage());
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
                    'amount'        => $instalmentData['cycle_amount'],
                    'cycleDate'     => !empty($futureInstalmentDate[$cycle + 1]) ? date('Y-m-d', strtotime($futureInstalmentDate[$cycle + 1])) : '',
                    'cycleExecuted' => '',
                    'dueCycles'     => '',
                    'paidDate'      => '',
                    'status'        => $this->translator->trans('NovalnetPayment.text.pendingMsg', [], null, $localeCode),
                    'reference'     => '',
                    'refundAmount'  => 0,
                ];

                if ($cycle == count($instalmentData['cycle_dates'])) {
                    $amount = $response['transaction']['amount'] - ($instalmentData['cycle_amount'] * ($cycle - 1));
                    $additionalDetails[$cycle] = array_merge($additionalDetails[$cycle], [
                       'amount'    => $amount
                    ]);
                }

                if ($cycle == 1) {
                    $additionalDetails[$cycle] = array_merge($additionalDetails[$cycle], [
                        'cycleExecuted' => !empty($instalmentData['cycles_executed']) ? $instalmentData['cycles_executed'] : '',
                        'dueCycles'     => !empty($instalmentData['pending_cycles']) ? $instalmentData['pending_cycles'] : '',
                        'paidDate'      => date('Y-m-d'),
                        'status'        => $this->translator->trans('NovalnetPayment.text.paidMsg', [], null, $localeCode),
                        'reference'     => (string) $response['transaction']['tid'],
                        'refundAmount'  => 0,
                    ]);
                }
            }
        }

        return $additionalDetails;
    }
    
    /**
     * Get Subscription Details.
     *
     * @param Context $context
     * @param string $orderNumber
     *
     * @return array
     */
    public function getSubscriptionDetails(Context $context, string $orderNumber) : array
    {
        $criteria = new criteria();
        $criteria->addFilter(new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $orderDetails = $this->novalnetTransactionRepository->search($criteria, $context)->first();
        $subscription = $this->helper->unserializeData($orderDetails->getAdditionalDetails());
        $datas = [];
        if (!empty($subscription['subscription'])) {
			$subscription['subscription']['booking_details']['payment_action'] = 'payment';
            return $subscription['subscription'];
        } else {
            $datas = [
                'payment_details' => [
                        'type' => $this->helper->getUpdatedPaymentType($orderDetails->getPaymentType()),
                        'process_mode' => 'direct',
                ],
                'booking_details' => [
                        'test_mode' => 0,
                        'payment_action' => 'payment'
                ]
            ];
        }

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
    public function fetchNovalnetReferenceData(string $customerNumber, string $orderNumber, string $paymentType, Context $context = null): ? NovalnetPaymentTransactionEntity
    {
        
        $oldpaymentName = $this->helper->getUpdatedPaymentType($paymentType, true);
        $criteria = new Criteria();
        $criteria->addFilter(new AndFilter([
            new EqualsFilter('novalnet_transaction_details.customerNo', $customerNumber),
            new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber),
            new EqualsAnyFilter('novalnet_transaction_details.paymentType', [$paymentType, $oldpaymentName]),
        ]));

        if ($paymentType == 'GUARANTEED_INVOICE') {
            $criteria->addFilter(new ContainsFilter('novalnet_transaction_details.additionalDetails', 'dob'));
        } else {
            $criteria->addFilter(new NotFilter('AND', [new EqualsFilter('novalnet_transaction_details.tokenInfo', null)]));
        }
        
        $criteria->addSorting(
            new FieldSorting('createdAt', FieldSorting::DESCENDING)
        );
        /** @var NovalnetPaymentTransactionEntity|null */
        $novalnetReferenceData = $this->novalnetTransactionRepository->search($criteria, $context ?? Context::createDefaultContext())->first();

        return $novalnetReferenceData;
    }
   
    
    /**
     * Zero  book amount Request .
     *
     * @param OrderEntity $order
     * @param Context $context

     * @return array
     */
    public function bookAmountRequest(OrderEntity $order, Context $context): array
    {
        $customer = [];
		$billingAddress = $shippingAddress = [];
		$addresses = $order->getAddresses()->getelements();
		foreach ($addresses as $id => $value) {
			if ($order->getBillingAddressId() == $id) {
				$billingAddress = $this->customAddress($value);
				$customer ['billing'] = $billingAddress;
				$customer['first_name'] = $value->getFirstName();
				$customer['last_name'] = $value->getLastName();
			}
		}

		if(!empty($order->getDeliveries()->first())){
			$shipping = $order->getDeliveries()->first();
			$shippingAddress = $this->customAddress($shipping->getshippingOrderAddress());
		} else {
			$shippingAddress = !empty($billingAddress) ? $billingAddress : [];
		}
		
		if (!empty($shippingAddress)) {
			if ($billingAddress === $shippingAddress) {
				$customer ['shipping'] ['same_as_billing'] = 1;
			} else {
				$customer ['shipping'] = $shippingAddress;
			}
		}
 
        return $customer;
    }
    
    public function customAddress($addressData) : array
    {
        $address = [];
        
        if (!empty($addressData) && !empty($addressData->getCountry())) {
            if (!empty($addressData->getCompany())) {
                $address['company'] = $addressData->getCompany();
            }
            
            $address['street'] = $addressData->getStreet().' '.$addressData->getAdditionalAddressLine1().' '.$addressData->getAdditionalAddressLine2();
            $address['city'] = $addressData->getCity();
            $address['zip'] = $addressData->getZipCode();
            $address['country_code'] = $addressData->getCountry()->getIso();
        }
        return $address;
    }
    
    /**
     * Update payment transaction status.
     *
     * @param string $paymentStatus
     * @param string $transactionId
     * @param Context $context
     *
     *
     */
    public function managePaymentStatus(string $paymentStatus, string $transactionId, Context $context)
    {
		$status = strtolower($paymentStatus);

        if ($status == 'paid') {
            $this->orderTransactionState->paid($transactionId, $context);
        } elseif (in_array($status, ['cancel', 'cancelled'])) {
            $this->orderTransactionState->cancel($transactionId, $context);
        } elseif ($status =='failed') {
            $this->orderTransactionState->fail($transactionId, $context);
        } elseif ($status == 'paidpartially') {
            $this->orderTransactionState->payPartially($transactionId, $context);
        } elseif (in_array($status,['process', 'pending'])) {
            try {
                $criteria = new criteria();
                $criteria->addFilter(new EqualsFilter('technicalName', 'in_progress'));
                $criteria->addFilter(new EqualsFilter('stateMachine.technicalName', 'order_transaction.state'));
                $criteria->addAssociation('stateMachine');
                $status = $this->stateMachineRepository->search($criteria, $context)->first();
                if (!empty($status)) {
                     $connection = $this->container->get(Connection::class);
                     $connection->exec(sprintf("
						UPDATE `order_transaction`
						SET `state_id` = UNHEX('%s') WHERE `id` = UNHEX('%s');
					 ", $status->getId(), $transactionId));
                }
            } catch (IllegalTransitionException $exception) {
				$this->setWarningMessage($exception->getMessage());
            }
        } elseif ($status =='open') {
            $this->orderTransactionState->reopen($transactionId, $context);
        } elseif ($status =='authorized') {
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
	
	public function updateSubscriptionData(string $status, string $paymentMethodId, string $orderId)
    {
        $connection = $this->container->get(Connection::class);
        if (method_exists($connection, 'getSchemaManager')) {
            $schemaManager = $connection->getSchemaManager();
        } else {
            $schemaManager = $connection->createSchemaManager();
        }
        
        if ($schemaManager->tablesExist(array('novalnet_subscription')) == true) {
            $data = $connection->fetchOne(sprintf("SELECT `id` FROM `novalnet_subscription` WHERE `order_id` = UNHEX('%s')", $orderId));
            if (!empty($data)) {
                if (in_array($status, ['ON_HOLD',  'PENDING',  'CONFIRMED'])) {
                    $connection->exec(sprintf("UPDATE `novalnet_subscription` SET `status` = 'ACTIVE', payment_method_id = UNHEX('%s') WHERE `order_id` = UNHEX('%s')", $paymentMethodId, $orderId));
                    $connection->exec(sprintf("UPDATE `novalnet_subs_cycle` SET `status` = 'SUCCESS', payment_method_id = UNHEX('%s') WHERE `order_id` = UNHEX('%s')", $paymentMethodId, $orderId));
                } else {
                    $connection->exec(sprintf("UPDATE `novalnet_subscription` SET `status` = 'CANCELLED', cancel_reason = 'Parent order getting failed', cancelled_at = '%s', payment_method_id = UNHEX('%s') WHERE `order_id` = UNHEX('%s')", date('Y-m-d H:i:s'), $paymentMethodId, $orderId));
                    $connection->exec(sprintf("UPDATE `novalnet_subs_cycle` SET `status` = 'FAILURE', payment_method_id = UNHEX('%s') WHERE `order_id` = UNHEX('%s')", $paymentMethodId, $orderId));
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
	
	public function mailAttachments(MailTemplateEntity $mailTemplate, Context $context) : array
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
	
	public function updateChangePayment(array $input, string $orderId, Context $context)
    {
        $paymentType = $input['transaction']['payment_type'] ?? 'NOVALNET_PAYMENT';
        $data = [
            'payment_details' => [
                    'type' => $paymentType,
                    'process_mode' => 'direct',
            ],
            'booking_details' => [
                    'test_mode' => $input ['transaction']['test_mode']
            ],
            'aboId' => $input ['custom']['subscriptionId'],
            'paymentMethodId' => $input ['custom']['paymentMethodId']
        ];

        // insert novalnet transaction details
        $insertData = [
            'id'    => Uuid::randomHex(),
            'paymentType' => $paymentType,
            'paidAmount' => 0,
            'tid' => $input['transaction']['tid'],
            'gatewayStatus' => $input['transaction']['status'],
            'amount' => $input['transaction']['amount'],
            'currency' => $input ['transaction']['currency'],
            'orderNo' => $input ['transaction']['order_no'],
            'customerNo' => !empty($input['customer']['customer_no']) ? $input['customer']['customer_no'] : '',
             'additionalDetails' => [
                'payment_name' => $this->helper->getUpdatedPaymentName($paymentType, $this->helper->getLocaleCodeFromContext($context)),
                'change_payment' => true,
                'subscription' => $data
            ]
        ];
        
        if (!empty($input['transaction']['payment_data']['token'])) {
            $insertData['tokenInfo'] = $input['transaction']['payment_data']['token'];
        }
        
        $insertData['additionalDetails'] = $this->helper->serializeData($insertData['additionalDetails']);

        // Upsert data into novalnet_transaction_details.repository
        $this->helper->updateTransactionData($insertData, $context);
        
        $connection = $this->container->get(Connection::class);
        if (method_exists($connection, 'getSchemaManager')) {
            $schemaManager = $connection->getSchemaManager();
        } else {
            $schemaManager = $connection->createSchemaManager();
        }
        if ($schemaManager->tablesExist(array('novalnet_subscription')) == true) {
            $data = $connection->fetchOne(sprintf("SELECT `id` FROM `novalnet_subscription` WHERE `order_id` = UNHEX('%s')", $orderId));
            if (!empty($data)) {
                $connection->exec(sprintf("UPDATE `novalnet_subscription` SET `payment_method_id` = UNHEX('%s') WHERE `order_id` = UNHEX('%s')", $input ['custom']['paymentMethodId'], $orderId));
                $connection->exec(sprintf("UPDATE `novalnet_subs_cycle` SET `payment_method_id` = UNHEX('%s') WHERE `order_id` = UNHEX('%s')", $input ['custom']['paymentMethodId'], $orderId));
            }
        }
    }
    
    /**
     * Update the order transaction details
     *
     * @param array $data
     * @param Context $context
     
     */
    public function orderTransactionUpsert(array $data, Context $context) 
    {
		$this->orderTransactionRepository->upsert([$data], $context);
		
	}
    
    
}
