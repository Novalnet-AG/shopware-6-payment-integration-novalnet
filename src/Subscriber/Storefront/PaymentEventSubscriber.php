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

use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Checkout\Payment\PaymentEvents;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * PaymentEventSubscriber Class.
 */
class PaymentEventSubscriber implements EventSubscriberInterface
{
    /**
     * @var SessionInterface
     */
    private $session;
    
    /**
     * @var Request
     */
    private $request;
    
    /**
     * Constructs a `PaymentEventSubscriber`
     *
     * @param RequestStack $requestStack
     */
    public function __construct(RequestStack $requestStack)
    {
        if (!is_null($requestStack->getCurrentRequest())) {
            $this->request  = $requestStack->getCurrentRequest();
            $this->session  = $requestStack->getSession();
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
        if (!empty($this->request) && !empty($this->request->request->get('paymentMethodId'))) {
            $formDataSession = [];
            $formData     = [];
            $paymentMethod   = '';
            
            if ($this->request->request->get('novalnetcreditcardId') && $this->request->request->get('novalnetcreditcardId') == $this->request->request->get('paymentMethodId')) {
                $paymentMethod = 'novalnetcreditcard';
            } elseif (($this->request->request->get('novalnetsepaId') && $this->request->request->get('novalnetsepaId') == $this->request->request->get('paymentMethodId')) || !empty($this->request->request->get('doForceSepaPayment'))) {
                $paymentMethod = 'novalnetsepa';
            } elseif ($this->request->request->get('novalnetsepaguaranteeId') && $this->request->request->get('novalnetsepaguaranteeId') == $this->request->request->get('paymentMethodId')) {
                $paymentMethod = 'novalnetsepaguarantee';
            } elseif (($this->request->request->get('novalnetinvoiceId') && $this->request->request->get('novalnetinvoiceId') == $this->request->request->get('paymentMethodId')) || $this->request->request->get('doForceInvoicePayment')) {
                $paymentMethod = 'novalnetinvoice';
            } elseif ($this->request->request->get('novalnetinvoiceguaranteeId') && $this->request->request->get('novalnetinvoiceguaranteeId') == $this->request->request->get('paymentMethodId')) {
                $paymentMethod = 'novalnetinvoiceguarantee';
            } elseif ($this->request->request->get('novalnetinvoiceinstalmentId') && $this->request->request->get('novalnetinvoiceinstalmentId') == $this->request->request->get('paymentMethodId')) {
                $paymentMethod = 'novalnetinvoiceinstalment';
            } elseif ($this->request->request->get('novalnetsepainstalmentId') && $this->request->request->get('novalnetsepainstalmentId') == $this->request->request->get('paymentMethodId')) {
                $paymentMethod = 'novalnetsepainstalment';
            }
            
            if (! empty($paymentMethod)) {
                if ($this->request->request->get('doForceSepaPayment')) {
                    $formData = $this->request->request->get("novalnetsepaguaranteeFormData");
                } elseif ($this->request->request->get('doForceInvoicePayment')) {
                    $formData = $this->request->request->get("novalnetinvoiceguaranteeFormData");
                } else {
                    $formData = $this->request->request->get($paymentMethod . 'FormData');
                }
                
                if (! empty($formData)) {
                    $this->session->set($paymentMethod . 'FormData', $formData);
                }
                
                $formDataSession = $this->session->get($paymentMethod . 'FormData');
                
                if (!empty($formData['paymentToken']) && $formData['paymentToken'] !== 'new') {
                    if (!empty($formDataSession['accountData'])) {
                        unset($formDataSession['accountData']);
                    }
                    
                    $this->session->set($paymentMethod . 'FormData', $formDataSession);
                }
            }
        }
    }
    
}
