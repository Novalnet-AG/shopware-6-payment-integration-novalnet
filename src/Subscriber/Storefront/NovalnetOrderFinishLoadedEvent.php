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
 * @package     NovalnetSubscription
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */

declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Subscriber\Storefront;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class NovalnetOrderFinishLoadedEvent implements EventSubscriberInterface
{
    /**
     * @var NovalnetHelper
     */
    private $helper;

    public function __construct(
        NovalnetHelper $helper
    ) {
        $this->helper   = $helper;
    }

    /**
     * Register subscribed events
     *
     * return array
     */
    public static function getSubscribedEvents(): array
    {
        return [

            CheckoutFinishPageLoadedEvent::class => 'onFinshPage'
        ];
    }

    public function onFinshPage(CheckoutFinishPageLoadedEvent $event)
    {
        if ($this->helper->hasSession('novalnetPaymentResponse')) {
            $this->helper->removeSession('novalnetPaymentResponse');
        }
    }
}
