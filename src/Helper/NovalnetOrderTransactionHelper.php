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
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Content\MailTemplate\Service\MailServiceInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Novalnet\NovalnetPayment\Content\PaymentTransaction\NovalnetPaymentTransactionEntity;
use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Shopware\Core\Checkout\Cart\Exception\OrderNotFoundException;
use Novalnet\NovalnetPayment\Helper\NovalnetValidator;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;

class NovalnetOrderTransactionHelper
{
    /**
     * @var EntityRepository
     */
    private $mailTemplateRepository;

    /**
     * @var MailServiceInterface
     */
    private $mailService;
    
    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var ContainerInterface
     */
    private $container;
    
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

    public function __construct(
        NovalnetHelper $helper,
        NovalnetValidator $validator,
        OrderTransactionStateHandler $orderTransactionState,
        EntityRepository $mailTemplateRepository,
        MailServiceInterface $mailService,
        ContainerInterface $container,
        TranslatorInterface $translator,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $orderTransactionRepository,
        EntityRepositoryInterface $paymentMethodRepository,
        EntityRepositoryInterface $novalnetTransactionRepository
    ) {
        $this->mailService                   = $mailService;
        $this->mailTemplateRepository        = $mailTemplateRepository;
        $this->container                     = $container;
        $this->helper            	         = $helper;
        $this->translator                    = $translator;
        $this->validator                     = $validator;
        $this->orderTransactionState         = $orderTransactionState;
        $this->orderRepository               = $orderRepository;
        $this->orderTransactionRepository    = $orderTransactionRepository;
        $this->paymentMethodRepository       = $paymentMethodRepository;
        $this->novalnetTransactionRepository = $novalnetTransactionRepository;
    }

    /**
     * send novalnet order mail.
     *
     * @param Context $context
     * @param MailTemplateEntity $mailTemplate
     * @param OrderEntity $order
     * @param string $note
     */
    public function sendMail(Context $context, MailTemplateEntity $mailTemplate, OrderEntity $order, string $note): void
    {
        $customer = $order->getOrderCustomer();
        if (null === $customer) {
            return;
        }

        $data = new ParameterBag();
        $data->set(
            'recipients',
            [
                $customer->getEmail() => $customer->getFirstName().' '.$customer->getLastName(),
            ]
        );
        $data->set('senderName', $mailTemplate->getSenderName());
        $data->set('salesChannelId', $order->getSalesChannelId());

        $data->set('contentHtml', $mailTemplate->getContentHtml());
        $data->set('contentPlain', $mailTemplate->getContentPlain());
        $data->set('subject', $mailTemplate->getSubject());
        
        try {
            $this->mailService->send(
                $data->all(),
                $context,
                [
                    'order' => $order,
                    'salesChannel' => $order->getSalesChannel(),
                    'note' => $note,
                ]
            );
        } catch (\Exception $e) {
            throw($e);
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
    public function getMailTemplate(Context $context, string $technicalName): ?MailTemplateEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', $technicalName));
        $criteria->setLimit(1);
        return $this->mailTemplateRepository->search($criteria, $context)->first();
    }

    /**
     * get the order reference details.
     *
     * @param string $orderId
     * @param string|null $customerId
     *
     * @return Criteria
     */
    public function getOrderCriteria(string $orderId = null, string $customerId = null): Criteria
    {
        if ($orderId) {
            $orderCriteria = new Criteria([$orderId]);
        } else {
            $orderCriteria = new Criteria([]);
        }

        if (null !== $customerId) {
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
        
        $result = $paymentMethod->first();
        return $result;
    }
    
    /**
     * send novalnet mail.
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @param string $note
     */
    public function prepareMailContent(OrderEntity $order, SalesChannelContext $salesChannelContext, string $note)
    {
        if (!is_null($order->getOrderCustomer())) {
            $orderEntity = $this->getOrderCriteria($order->getId(), $order->getOrderCustomer()->getCustomerId());
            $orderReference = $this->orderRepository->search($orderEntity, $salesChannelContext->getContext())->first();
            $mailTemplate = $this->getMailTemplate($salesChannelContext->getContext(), 'novalnet_order_confirmation_mail');
            if (!is_null($mailTemplate)) {
                $this->sendMail($salesChannelContext->getContext(), $mailTemplate, $orderReference, $note);
            }
        }
    }
        
    /**
     * Fetch Novalnet transaction data.
     *
     * @param string $orderNumber
     * @param Context $context
     * @param int $tid
     */
    public function fetchNovalnetTransactionData(string $orderNumber = null, Context $context = null, int $tid = null): ?NovalnetPaymentTransactionEntity
    {
        $criteria = new Criteria();
        
        if (!is_null($orderNumber)) {
            $criteria->addFilter(new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber));
        }
        
        if (!is_null($tid)) {
            $criteria->addFilter(new EqualsFilter('novalnet_transaction_details.tid', $tid));
        }
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
     *
     * @return array
     */
    public function manageTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, string $type = 'transaction_capture'): array
    {
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
                        $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.confirmMessage'), date('d/m/Y H:i:s'));
                        if ($response['transaction']['status'] === 'CONFIRMED') {
                            $upsertData['paidAmount'] = $transactionData->getAmount();
                        }
                        if(!empty($transactionData->getAdditionalDetails() && !empty($transactionData->getPaymentType()) && in_array($transactionData->getPaymentType(), ['novalnetinvoice', 'novalnetinvoiceguarantee', 'novalnetprepayment']))) {
							$appendComments = false;
                            $response['transaction']['bank_details'] = $this->helper->unserializeData($transactionData->getAdditionalDetails());
                            $message .= $this->newLine . $this->newLine . $this->helper->formBankDetails($response);
                        }
                    } elseif ($response['transaction']['status'] === 'DEACTIVATED') {
                        $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage'), date('d/m/Y H:i:s'));
                    }
                
                    $this->postProcess($transaction, $context, $message, $upsertData, $appendComments);
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
     * @param string $reason
     *
     * @return array
     */
    public function refundTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, int $refundAmount = 0, string $reason = '') : array
    {
        $parameters = [
            'transaction' => [
                'tid'    => $transactionData->getTid()
            ],
            'custom' => [
				'shop_invoked' => 1
            ]
        ];
        
        if ($reason) {
            $parameters['transaction']['reason'] = $reason;
        }
        if (!empty($refundAmount)) {
            $parameters['transaction']['amount'] = (int) $refundAmount;
        }
        
        
        $endPoint   = $this->helper->getActionEndpoint('transaction_refund');
        $paymentSettings = $this->helper->getNovalnetPaymentSettings($this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));
        
        $response = $this->helper->sendPostRequest($parameters, $endPoint, $paymentSettings['NovalnetPayment.settings.accessKey']);
        
        if ($this->validator->isSuccessStatus($response)) {
            if (! empty($response['transaction']['status'])) {
                if (empty($refundAmount)) {
                    if (! empty($response['transaction']['refund']['amount'])) {
                        $refundAmount = $response['transaction']['refund']['amount'];
                    } else {
                        $refundAmount = (int) $transactionData->getAmount() - (int) $transactionData->getRefundedAmount();
                    }
                }
                $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($refundAmount, $response['transaction'] ['currency'], $context);
                $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.refundComment'), $transactionData->getTid(), $refundedAmountInBiggerUnit);
                
                if (! empty($response['transaction']['refund']['tid'])) {
                    $message .= sprintf($this->translator->trans('NovalnetPayment.text.refundCommentForNewTid'), $response ['transaction']['refund']['tid']);
                }
                
                $totalRefundedAmount = (int) $transactionData->getRefundedAmount() + (int) $refundAmount;
                
                $this->postProcess($transaction, $context, $message, [
                    'id'             => $transactionData->getId(),
                    'refundedAmount' => $totalRefundedAmount,
                    'gatewayStatus'  => $response['transaction']['status'],
                ]);
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
        
        if (!empty($upsertData)) {
            $this->novalnetTransactionRepository->update([$upsertData], $context);
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

        $transaction = $transactionCollection->first();
        
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
     * @return OrderTransactionEntity|null
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
}
