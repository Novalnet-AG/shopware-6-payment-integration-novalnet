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
use Shopware\Core\Checkout\Payment\PaymentProcessor;
use Shopware\Core\Framework\Uuid\Uuid;
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
     * @var PaymentProcessor
     */
    private $paymentProcessor;

    /**
     * Constructs a `OrderEventSubscriber`
     *
     * @param NovalnetHelper $helper
     * @param object $salesChannelContextFactory
     * @param PaymentProcessor $paymentProcessor
     * @param RouterInterface $router
     */
    public function __construct(
        NovalnetHelper $helper,
        private readonly object $salesChannelContextFactory,
        PaymentProcessor $paymentProcessor,
        private readonly RouterInterface $router
    ) {
        $this->helper = $helper;
        $this->paymentProcessor = $paymentProcessor;
    }

    /**
     * Get subscribed events
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'orderPlaced',
        ];
    }

    /**
     * Checkout order placed event
     *
     * @param CheckoutOrderPlacedEvent $event
     */
    public function orderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $requestUrl = !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        if (!empty($requestUrl) && str_contains((string) $requestUrl, '/api/_proxy-order') && (!str_contains((string) $requestUrl, '/novalnet-subscription'))) {
            $order = $event->getOrder();
            $transaction = $order->getTransactions()->last();
            $paymentMethod = $transaction->getPaymentMethod();
            preg_match('/\w+$/', $paymentMethod->getHandlerIdentifier(), $match);
            $payment = $match[0];

            if ($payment === 'NovalnetPayment') {
                $orderId = $order->getId();

                // get order billing and shipping address ID
                $delivery = $order->getDeliveries()->first();
                $billingAddress = [];
                if ($order->getBillingAddress() !== null) {
                    $billingAddress = $order->getBillingAddress();
                } else {
                    $billingAddressesId = $order->getBillingAddressId();
                    $addresses = $order->getAddresses()->getElements();
                    foreach ($addresses as $id => $value) {
                        if ($billingAddressesId === $id) {
                            $billingAddress = $value;
                        }
                    }
                }

                if (!empty($billingAddress)) {
                    $billingAddressId = $this->helper->getAddressId($billingAddress);

                    if (!empty($delivery) && $delivery->getShippingOrderAddress() !== null) {
                        $shippingAddress = $delivery->getShippingOrderAddress();
                    } else {
                        $shippingAddress = $billingAddress;
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
                $salesChannelContext = $this->salesChannelContextFactory->create(Uuid::randomHex(), $order->getSalesChannelId(), $options);

                $finishUrl = $this->router->generate('frontend.checkout.finish.page', ['orderId' => $orderId]);
                $errorUrl = $this->router->generate('frontend.account.edit-order.page', ['orderId' => $orderId]);
                $dataBag = new Request();
                $dataBag->request->set('isBackendOrderCreation', true);
                $dataBag->request->set('BackendPaymentDetails', $order->getOrderCustomer()->getCustomerId());

                try {
                    $this->paymentProcessor->pay($orderId, $dataBag, $salesChannelContext, $finishUrl, $errorUrl);
                } catch (\Exception) {
                }
            }
        }
    }
}
