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
use Shopware\Core\Checkout\Payment\PaymentEvents;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
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
    private $sessionInterface;
    
    /**
     * @var Request
     */
    private $request;
    
    /**
     * Constructs a `PaymentEventSubscriber`
     *
     * @param SessionInterface $sessionInterface
     * @param RequestStack $requestStack
     */
    public function __construct(SessionInterface $sessionInterface, RequestStack $requestStack)
    {
        $this->sessionInterface  = $sessionInterface;
        if (!is_null($requestStack->getCurrentRequest())) {
            $this->request           = $requestStack->getCurrentRequest();
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
            PaymentEvents::PAYMENT_METHOD_LOADED_EVENT   => 'afterPaymentMethodLoaded',
            ProductPageLoadedEvent::class   => 'onProductLoadEvent',
        ];
    }
    
    /**
     * Store the needed values in session after payment selection
     *
     * @params EntityLoadedEvent $event
     */
    public function afterPaymentMethodLoaded(EntityLoadedEvent $event): void
    {
        if (!empty($this->request) && !empty($this->request->get('paymentMethodId'))) {
            $formDataSession = [];
            $formData     = [];
            $paymentMethod   = '';
            
            if ($this->request->get('novalnetcreditcardId') && $this->request->get('novalnetcreditcardId') == $this->request->get('paymentMethodId')) {
                $paymentMethod = 'novalnetcreditcard';
            } elseif (($this->request->get('novalnetsepaId') && $this->request->get('novalnetsepaId') == $this->request->get('paymentMethodId')) || !empty($this->request->get('doForceSepaPayment'))) {
                $paymentMethod = 'novalnetsepa';
            } elseif ($this->request->get('novalnetsepaguaranteeId') && $this->request->get('novalnetsepaguaranteeId') == $this->request->get('paymentMethodId')) {
                $paymentMethod = 'novalnetsepaguarantee';
            } elseif (($this->request->get('novalnetinvoiceId') && $this->request->get('novalnetinvoiceId') == $this->request->get('paymentMethodId')) || $this->request->get('doForceInvoicePayment')) {
                $paymentMethod = 'novalnetinvoice';
            } elseif ($this->request->get('novalnetinvoiceguaranteeId') && $this->request->get('novalnetinvoiceguaranteeId') == $this->request->get('paymentMethodId')) {
                $paymentMethod = 'novalnetinvoiceguarantee';
            } elseif ($this->request->get('novalnetpaypalId') && $this->request->get('novalnetpaypalId') == $this->request->get('paymentMethodId')) {
                $paymentMethod = 'novalnetpaypal';
            } elseif ($this->request->get('novalnetinvoiceinstalmentId') && $this->request->get('novalnetinvoiceinstalmentId') == $this->request->get('paymentMethodId')) {
                $paymentMethod = 'novalnetinvoiceinstalment';
            } elseif ($this->request->get('novalnetsepainstalmentId') && $this->request->get('novalnetsepainstalmentId') == $this->request->get('paymentMethodId')) {
                $paymentMethod = 'novalnetsepainstalment';
            }
            
            if (! empty($paymentMethod)) {
                if ($this->request->get('doForceSepaPayment')) {
                    $formData        = $this->request->get("novalnetsepaguaranteeFormData");
                } elseif ($this->request->get('doForceInvoicePayment')) {
                    $formData        = $this->request->get("novalnetinvoiceguaranteeFormData");
                } else {
                    $formData        = $this->request->get($paymentMethod . 'FormData');
                }
                
                if (! empty($formData)) {
                    $this->sessionInterface->set($paymentMethod . 'FormData', $formData);
                }
                
                $formDataSession = $this->sessionInterface->get($paymentMethod . 'FormData');
                
                if (!empty($formData['paymentToken']) && $formData['paymentToken'] !== 'new') {
                    if (!empty($formDataSession['accountData'])) {
                        unset($formDataSession['accountData']);
                    }
                    
                    $this->sessionInterface->set($paymentMethod . 'FormData', $formDataSession);
                }
            }
        }
    }
    
    /**
     * Remove the Error Message
     *
     * @params ProductPageLoadedEvent $event
     */
    public function onProductLoadEvent(ProductPageLoadedEvent $event): void
    {
		$this->sessionInterface->remove('novalnetErrorMessage');
	}
}
