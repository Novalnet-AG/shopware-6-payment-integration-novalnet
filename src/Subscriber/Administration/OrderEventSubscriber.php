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

namespace Novalnet\NovalnetPayment\Subscriber\Administration;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetValidator;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionChainProcessor;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * OrderEventSubscriber Class.
 */
class OrderEventSubscriber implements EventSubscriberInterface
{
    /**
     * @var NovalnetHelper
     */
    private $helper;

    /**
     * @var NovalnetOrderTransactionHelper
     */
    private $transactionHelper;
    /**
     * @var NovalnetValidator
     */
    private $validator;
    
    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepository;
    
    /**
     * @var SalesChannelContextFactory
     */
    private $salesChannelContextFactory;
    
    /**
     * @var PaymentTransactionChainProcessor
     */
    private $paymentProcessor;
    
    /**
     * @var RouterInterface
     */
    private $router;
    
    /**
     * Constructs a `OrderEventSubscriber`
     *
     * @param NovalnetHelper $helper
     * @param NovalnetOrderTransactionHelper $transactionHelper
     * @param NovalnetValidator $validator
     */
    public function __construct(NovalnetHelper $helper, NovalnetOrderTransactionHelper $transactionHelper, NovalnetValidator $validator,EntityRepositoryInterface $orderRepository,
    object $salesChannelContextFactory,PaymentTransactionChainProcessor $paymentProcessor,RouterInterface $router)
    {
        $this->helper            = $helper;
        $this->transactionHelper = $transactionHelper;
        $this->validator         = $validator;
        $this->orderRepository  = $orderRepository;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->paymentProcessor = $paymentProcessor;
         $this->router = $router;
    }

    /**
     * Get subscribed events
     *
     * return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_TRANSACTION_WRITTEN_EVENT => 'onPaymentStateChange',
            CheckoutOrderPlacedEvent::class => 'orderPlaced',
        ];
    }

    /**
     * Handle API calls on payment status change.
     *
     * @params EntityWrittenEvent $event
     */
    public function onPaymentStateChange(EntityWrittenEvent $event): void
    {
        foreach ($event->getPayloads() as $payload) {
            $transaction = $this->transactionHelper->getTransactionById($payload['id']);
            $paymentMethodName = '';
            if (!is_null($transaction)) {
                $paymentMethod     = $this->transactionHelper->getPaymentMethodById($transaction->getPaymentMethodId());
                if (!is_null($paymentMethod)) {
                    $paymentMethodName = $this->helper->getPaymentMethodName($paymentMethod);
                }

                if (!is_null($paymentMethodName) && $this->validator->checkString($paymentMethodName)) {
                    if ($transaction !== null && $transaction->getStateMachineState() !== null && $transaction->getOrder() !== null) {
                        $context = Context::createDefaultContext();
                        if ($transaction->getOrder()->getOrderNumber() !== null) {
                            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData($transaction->getOrder()->getOrderNumber());

                            if (!is_null($transactionData)) {
                                if (($transactionData->getGatewayStatus() === 'ON_HOLD' || in_array($transactionData->getGatewayStatus(), ['91', '98', '99', '85'])) && in_array($transaction->getStateMachineState()->getTechnicalName(), ['paid', 'cancelled', 'in_progress'])) {
                                    if (in_array($transaction->getStateMachineState()->getTechnicalName(), ['paid', 'in_progress'])) {
                                        if (($transaction->getStateMachineState()->getTechnicalName() === 'in_progress' && $this->helper->getSupports('payLater', $paymentMethodName)) || $transaction->getStateMachineState()->getTechnicalName() === 'paid') {
                                            $response = $this->transactionHelper->manageTransaction($transactionData, $transaction, $context, 'transaction_capture');
                                        }
                                    } elseif ($transaction->getStateMachineState()->getTechnicalName() === 'cancelled') {
                                        $response = $this->transactionHelper->manageTransaction($transactionData, $transaction, $context, 'transaction_cancel');
                                    }
                                } elseif ($transactionData->getRefundedAmount() < $transactionData->getAmount() && ($transactionData->getGatewayStatus() === 'CONFIRMED' || ($transactionData->getGatewayStatus() === 'PENDING' && $this->helper->getSupports('payLater', $paymentMethodName)) || $transactionData->getGatewayStatus() == '100') && in_array($transaction->getStateMachineState()->getTechnicalName(), ['refunded', 'cancelled'])) {
                                    if($this->helper->getSupports('instalment', $paymentMethodName))
                                    {
										$this->transactionHelper->refundTransaction($transactionData, $transaction, $context, 0, null, true);
									} else {
										$this->transactionHelper->refundTransaction($transactionData, $transaction, $context);
									}
										
                                }
                            }
                        }
                    }
                }
                
                return;
            }
        }
    }
    
    
    public function orderPlaced(CheckoutOrderPlacedEvent $event)
    {
		$context = Context::createDefaultContext();
		$RequestUrl=[];
    	$request = Request::createFromGlobals();
		$serverParams = (array) $request->server;	
		foreach ($serverParams as $values) {
            $RequestUrl = $values;
        }	
        if(!empty($RequestUrl['REQUEST_URI']) && strpos($RequestUrl['REQUEST_URI'], '/api'))
			{
				foreach ($event->getOrder()->getTransactions()->getElements() as $key => $orderTransaction) {
						$paymentHandler = $orderTransaction->getPaymentMethod()->getHandlerIdentifier();
						if(strpos($paymentHandler, 'NovalnetPayment')){
							$criteria = new Criteria([$orderTransaction->getOrderId()]);
							$criteria->addAssociation('transactions.stateMachineState');
							$criteria->addAssociation('transactions.paymentMethod');
							$criteria->addAssociation('lineItems');
							$criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));
							$order = $this->orderRepository->search($criteria, $context)->first();
							$orderId = $order->getId();
							$supportedPayments = ['NovalnetPrepayment','NovalnetInvoice','NovalnetMultibanco','NovalnetCashpayment'];
							$paymentSupport = false;
							foreach ($supportedPayments as $paymentName)
							{
								if(strpos($paymentHandler, $paymentName) !== false)
								{
									$paymentSupport = true;
								}
							}
							if($paymentSupport){
								$orderTransactions = $order->getTransactions();
								$transactions = $orderTransactions->filterByState(OrderTransactionStates::STATE_OPEN);
								$transaction = $orderTransactions->last();	
								$paymentMethodId = $transaction->getPaymentMethodId();
								$paymentName = $transaction->getPaymentMethod()->getCustomFields()['novalnet_payment_method_name'];
								$salesChannelContext =  $this->salesChannelContextFactory->create(
										Uuid::randomHex(),
										$order->getSalesChannelId(),
										[SalesChannelContextService::CUSTOMER_ID => $order->getOrderCustomer()->getCustomerId()]
								);		
								$finishUrl = $this->router->generate('frontend.checkout.finish.page', ['orderId' => $orderId]);
								$errorUrl = $this->router->generate('frontend.account.edit-order.page', ['orderId' => $orderId]);
								$dataBag = new  RequestDataBag();
								$dataBag->set('isBackendOrderCreation', true);
								$this->paymentProcessor->process($orderId, $dataBag, $salesChannelContext, $finishUrl, $errorUrl);
							} else {
								throw new AsyncPaymentProcessException($orderId, 'An error occurred during the communication with external payment gateway'.PHP_EOL.' Backend Orders is not supported for this payment');
							}
				     }
				}
			}
	}
      
}
