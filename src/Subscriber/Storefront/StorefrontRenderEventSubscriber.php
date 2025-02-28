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
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Event\StorefrontRenderEvent;

/**
 * StorefrontRenderEventSubscriber Class.
 */
class StorefrontRenderEventSubscriber implements EventSubscriberInterface
{
    /**
     * @var NovalnetHelper
     */
    protected $helper;


    /**
     * Constructs a `StorefrontRenderEventSubscriber`

     * @param NovalnetHelper $helper
     *
    */
    public function __construct(NovalnetHelper $helper)
    {
        $this->helper = $helper;
    }

    /**
      * Get subscribed events
      *
      * return array
      */
    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender'
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $getCurrentRequest = $this->helper->getCurrentRequest();
        $server = $getCurrentRequest->server->all();
        $requestUrl = !empty($server['REQUEST_URI']) ? $server['REQUEST_URI'] : '';

        if (!empty($requestUrl) && !preg_match('/checkout\/confirm/', $requestUrl)) {

            foreach ([
            'nnIframeUrl',
            'cartAmount',
            'billingAddress',
            'shippingAddress',
            ] as $sessionKey) {
                if ($this->helper->hasSession($sessionKey)) {
                    $this->helper->removeSession($sessionKey);
                }
            }
        }
    }

}
