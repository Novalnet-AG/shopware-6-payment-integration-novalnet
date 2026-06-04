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

namespace Novalnet\NovalnetPayment\Subscriber\Storefront;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Shopware\Core\Content\Product\Events\ProductListingResultEvent;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\Payment\PaymentMethodCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Register\CheckoutRegisterPageLoadedEvent;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductPageSubscriber implements EventSubscriberInterface
{
    /**
    * @var NovalnetHelper
    */
    protected $helper;

    /**
     * Constructs a `ProductPageSubscriber`
     */
    public function __construct(
        NovalnetHelper $helper
    ) {
        $this->helper           = $helper;
    }


    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
            ProductListingResultEvent::class => 'onProductListing',
            CheckoutRegisterPageLoadedEvent::class => 'onCheckoutRegisterLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $this->addPaymentMethodsToPage($event->getSalesChannelContext(), $event->getPage());
    }

    public function onProductListing(ProductListingResultEvent $event): void
    {
        $this->addPaymentMethodsToPage($event->getSalesChannelContext(), $event->getResult());
    }

    public function onCheckoutRegisterLoaded(CheckoutRegisterPageLoadedEvent $event): void
    {
        $this->addPaymentMethodsToPage($event->getSalesChannelContext(), $event->getPage());
    }

    /**
     * Reusable method to fetch and add payment methods to the result.
     */
    private function addPaymentMethodsToPage(SalesChannelContext $context, $event): void
    {
        $paymentMethods = $this->helper->getSalesChannelPaymentMethods($context, $context->getContext());
        $event->addExtension('salesChannelPaymentMethods', $paymentMethods);
    }
}
