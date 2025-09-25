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
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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
     *
     * @param NovalnetHelper $helper
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

    /**
     * Clears session variables which are not required after order confirmation
     *
     * @param StorefrontRenderEvent $event
     *
     * @return void
     */
    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        // Clear session variables only on pages other than the checkout confirm page
        if (!empty($_SERVER['REQUEST_URI']) && !preg_match('/checkout\/confirm/', $_SERVER['REQUEST_URI'])) {
            // Remove session variables
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
