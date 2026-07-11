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
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionChainProcessor;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * OrderEventSubscriber Class.
 */
class OrderEventSubscriber implements EventSubscriberInterface
{
    /**
    * @var NovalnetHelper
    */
    protected $helper;

    /**
     * @var SalesChannelContextFactory
     */
    private $contextFactory;

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
     */
    public function __construct(
        NovalnetHelper $helper,
        object $contextFactory,
        PaymentTransactionChainProcessor $paymentProcessor,
        RouterInterface $router
    ) {

        $this->helper           = $helper;
        $this->router           = $router;
        $this->paymentProcessor = $paymentProcessor;
        $this->contextFactory   = $contextFactory;
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

    public function orderPlaced(CheckoutOrderPlacedEvent $event)
    {
        $RequestUrl = [];
        $request = Request::createFromGlobals();
        $serverParams = (array) $request->server;

        foreach ($serverParams as $values) {
            $RequestUrl = $values;
        }

        if (!empty($RequestUrl['REQUEST_URI']) && strpos($RequestUrl['REQUEST_URI'], '/api/_proxy-order') !== false && (strpos($RequestUrl['REQUEST_URI'], '/novalnet-subscription') == false)) {
            $order = $event->getOrder();
            $transaction = $order->getTransactions()->last();
            $paymentMethod = $transaction->getPaymentMethod();

            foreach (['NovalnetPrepayment', 'NovalnetInvoice', 'NovalnetMultibanco'] as $supportedPayment) {
                if (strpos($paymentMethod->getHandlerIdentifier(), $supportedPayment) !== false) {
                    $orderId = $order->getId();

                    // get order billing and shipping address ID
                    $delivery = $order->getDeliveries()->first();
                    $billingAddress  = [];
                    if (!is_null($order->getBillingAddress())) {
                        $billingAddress  = $order->getBillingAddress();
                    } else {
                        $billingAddressesId = $order->getBillingAddressId();
                        $addresses = $order->getAddresses()->getElements();
                        foreach ($addresses as $id => $value) {
                            if ($billingAddressesId == $id) {
                                $billingAddress = $value;
                            }
                        }
                    }

                    if (!empty($billingAddress)) {
                        $billingAddressId = $this->helper->getAddressId($billingAddress);

                        if (!empty($delivery) && $delivery->getShippingOrderAddress() != null) {
                            $shippingAddress = $delivery->getShippingOrderAddress();
                        } else {
                            $shippingAddress =  $billingAddress;
                        }

                        $shippingAddressId = $this->helper->getAddressId($shippingAddress);
                    }

                    $options = [
                        SalesChannelContextService::CUSTOMER_ID => $order->getOrderCustomer()->getCustomerId(),
                        SalesChannelContextService::CURRENCY_ID => !empty($order->getCurrencyId()) ? $order->getCurrencyId() : $order->getCurrency()->getId(),
                        SalesChannelContextService::PAYMENT_METHOD_ID => $paymentMethod->getId()
                    ];

                    if (!empty($shippingAddressId)) {
                        $options[SalesChannelContextService::SHIPPING_ADDRESS_ID] = $shippingAddressId;
                    }

                    if (!empty($billingAddressId)) {
                        $options[SalesChannelContextService::BILLING_ADDRESS_ID] = $billingAddressId;
                    }
                    $salesChannelContext =  $this->contextFactory->create(Uuid::randomHex(), $order->getSalesChannelId(), $options);
                    $finishUrl = $this->router->generate('frontend.checkout.finish.page', ['orderId' => $orderId]);
                    $errorUrl = $this->router->generate('frontend.account.edit-order.page', ['orderId' => $orderId]);
                    $dataBag = new RequestDataBag();
                    $dataBag->set('isBackendOrderCreation', true);
                    try {
                        $this->paymentProcessor->process($orderId, $dataBag, $salesChannelContext, $finishUrl, $errorUrl);
                    } catch (\Exception $e) {
                        throw $e;
                    }
                }
            }
        }
    }
}
