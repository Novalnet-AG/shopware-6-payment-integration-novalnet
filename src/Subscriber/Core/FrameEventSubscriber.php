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

namespace Novalnet\NovalnetPayment\Subscriber\Core;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\Annotation\RouteScope as RouteScopeAnnotation;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * EmailEventSubscriber Class.
 */
class FrameEventSubscriber implements EventSubscriberInterface
{
    /**
     * Get subscribed events
     *
     * return array
     */
     
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'setCustomXFrameOptions',
        ];
    }
    
     /**
     * set Custom XFrame Options
     *
     * @param ResponseEvent $event
     *
     */

    public function setCustomXFrameOptions(ResponseEvent $event)
    {
        $response = $event->getResponse();
        $response->headers->set('X-Frame-Options', 'same-origin');
    }
}
