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

use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionChainProcessor;
use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Novalnet\NovalnetPayment\Helper\NovalnetHelper;

/**
 * OrderEventSubscriber Class.
 */
class OrderEventSubscriber implements EventSubscriberInterface
{
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
     * @var NovalnetHelper
     */
    protected $helper;
    
    /**
     * Constructs a `OrderEventSubscriber`
     *
     * @param NovalnetHelper $helper
     * @param object $salesChannelContextFactory
     * @param PaymentTransactionChainProcessor $paymentProcessor
     * @param RouterInterface $router
     */
    public function __construct(
        NovalnetHelper $helper,
        object $salesChannelContextFactory,
        PaymentTransactionChainProcessor $paymentProcessor,
        RouterInterface $router
    ) {
        $this->helper = $helper;
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
            CheckoutOrderPlacedEvent::class => 'orderPlaced',
        ];
    }
    
    /**
     * Get order Placed
     *
     * @param CheckoutOrderPlacedEvent $event
     *
     */
    
    public function orderPlaced(CheckoutOrderPlacedEvent $event)
    {

        $requestUrl =!empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        
        if (!empty($requestUrl) && strpos($requestUrl, '/api/_proxy-order') !== false && (strpos($requestUrl, '/novalnet-subscription') == false)) {
            $order = $event->getOrder();
            $transaction = $order->getTransactions()->last();
            $paymentMethod = $transaction->getPaymentMethod();
            $novalnetPaymentMethod = preg_match('/\w+$/', $paymentMethod->getHandlerIdentifier(), $match);
            $payment = $match[0];
            
            if ($payment == 'NovalnetPayment') {
                $orderId = $order->getId();
                
                $salesChannelContext =  $this->salesChannelContextFactory->create(Uuid::randomHex(), $order->getSalesChannelId(), [
                    SalesChannelContextService::CUSTOMER_ID => $order->getOrderCustomer()->getCustomerId(),
                    SalesChannelContextService::CURRENCY_ID => $order->getCurrencyId() ?? $order->getCurrency()->getId(),
                    SalesChannelContextService::PAYMENT_METHOD_ID => $paymentMethod->getId()
                ]);

                $finishUrl = $this->router->generate('frontend.checkout.finish.page', ['orderId' => $orderId]);
                $errorUrl = $this->router->generate('frontend.account.edit-order.page', ['orderId' => $orderId]);
                $dataBag = new  RequestDataBag();
                $dataBag->set('isBackendOrderCreation', true);
                
                $paymentData = $this->helper->getCustomerDetails($order->getOrderCustomer()->getCustomerId());
                $paymentDatas = !empty($paymentData['novalnetOrderBackendParameters']) ? $paymentData['novalnetOrderBackendParameters']: [];

                $dataBag->set('BackendPaymentDetails', $paymentDatas);
                
                try {
                    $this->paymentProcessor->process($orderId, $dataBag, $salesChannelContext, $finishUrl, $errorUrl);
                } catch (PaymentProcessException $e) {
                }
            }
        }
    }
}
