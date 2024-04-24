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
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Checkout\Payment\PaymentEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PaymentEventSubscriber Class.
 */
class PaymentEventSubscriber implements EventSubscriberInterface
{
    /**
     * @var NovalnetHelper
     */
    protected $helper;
    
    /**
     * @var Request|null
     */
    private $request;
    
    /**
     * Constructs a `PaymentEventSubscriber`

     * @param NovalnetHelper $helper
     * @param RequestStack $requestStack
     *
    */
    public function __construct(NovalnetHelper $helper, RequestStack $requestStack)
    {
		$this->helper = $helper;
        if (!empty($requestStack->getCurrentRequest())) {
            $this->request       = $requestStack->getCurrentRequest();
        }
    }
    
    /**
     * Get subscribed events
     *
     * return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PaymentEvents::PAYMENT_METHOD_LOADED_EVENT   => 'afterPaymentMethodLoaded'
        ];
    }
    
    /**
     * Store the needed values in session after payment selection
     *
     * @params EntityLoadedEvent $event
     */
    public function afterPaymentMethodLoaded(EntityLoadedEvent $event): void
    {
         $paymentData = [];

        if (($this->request != null) && !empty($this->request->get('paymentMethodId')) && !empty($this->request->get('novalnetpaymentFormData'))) {
            $this->helper->setSession('novalnetpaymentFormData', $this->request->get('novalnetpaymentFormData'));
        }
    }
}
