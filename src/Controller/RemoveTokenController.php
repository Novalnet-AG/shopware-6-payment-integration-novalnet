<?php declare(strict_types=1);

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
 
namespace Novalnet\NovalnetPayment\Controller;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Novalnet\NovalnetPayment\Components\NovalnetPaymentTokenRepository;
use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @RouteScope(scopes={"storefront"})
 */
class RemoveTokenController extends StorefrontController
{
    /**
     * @var NovalnetPaymentTokenRepository
     */
    private $paymentTokenRepository;
    
    /**
     * @var NovalnetHelper
     */
    private $helper;
    
    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(NovalnetPaymentTokenRepository $paymentTokenRepository, NovalnetHelper $helper, TranslatorInterface $translator)
    {
        $this->paymentTokenRepository = $paymentTokenRepository;
        $this->helper                 = $helper;
        $this->translator             = $translator;
    }

    /**
     * @Route("/remove/paymentToken", name="frontend.checkout.removePaymentToken", options={"seo"="false"}, defaults={"csrf_protected"=false,"XmlHttpRequest"=true}, methods={"GET", "POST"})
     */
    public function removePaymentToken(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $data = $this->helper->unserializeData((string) $request->getContent());
        $this->paymentTokenRepository->removePaymentToken($salesChannelContext, $data);
        $this->addFlash('success', $this->translator->trans('Novalnetpayment.text.paymentMethodDeleted'));
        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }
}
