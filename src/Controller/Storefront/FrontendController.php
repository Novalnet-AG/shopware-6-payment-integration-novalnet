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
 
namespace Novalnet\NovalnetPayment\Controller\Storefront;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetCartHelper;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\Exception\InvalidCartException;
use Shopware\Core\Checkout\Order\Exception\EmptyCartException;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerRegistry;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;
use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\UnknownPaymentMethodException;
use Shopware\Core\Checkout\Payment\PaymentService;
use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoader;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedHook;
use Shopware\Storefront\Framework\AffiliateTracking\AffiliateTrackingListener;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @RouteScope(scopes={"storefront"})
 */
class FrontendController extends StorefrontController
{   
    /**
     * @var NovalnetHelper
     */
    private $helper;
    
    /**
     * @var NovalnetCartHelper
     */
    private $cartHelper;
    
    /**
     * @var TranslatorInterface
     */
    private $translator;
    
    /**
     * @var CheckoutCartPageLoader
     */
    private $cartPageLoader;
    
    /**
     * @var OrderService
     */
    private $orderService;
    
    /**
     * @var PaymentService
     */
    private $paymentService;
    
    /**
     * @var AbstractSalesChannelContextFactory
     */
    private $salesChannelContextFactory;
    
    /**
     * @var PaymentHandlerRegistry
     */
    private $paymentHandlerRegistry;
    
    /**
     * @var SessionInterface
     */
    private $sessionInterface;

    public function __construct(
        NovalnetHelper $helper,
        NovalnetCartHelper $cartHelper,
        TranslatorInterface $translator,
        CheckoutCartPageLoader $cartPageLoader,
        OrderService $orderService,
        PaymentService $paymentService,
        AbstractSalesChannelContextFactory $salesChannelContextFactory,
        PaymentHandlerRegistry $paymentHandlerRegistry,
        SessionInterface $sessionInterface
    )
    {
        $this->helper           = $helper;
        $this->cartHelper       = $cartHelper;
        $this->translator       = $translator;
        $this->cartPageLoader   = $cartPageLoader;
        $this->orderService     = $orderService;
        $this->paymentService   = $paymentService;
        $this->sessionInterface = $sessionInterface;
        $this->paymentHandlerRegistry = $paymentHandlerRegistry;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
    }

    /**
     * @Route("/remove/paymentToken", name="frontend.checkout.removePaymentToken", options={"seo"="false"}, defaults={"csrf_protected"=false, "XmlHttpRequest"=true}, methods={"GET", "POST"})
     */
    public function removePaymentToken(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $data = $this->helper->unserializeData((string) $request->getContent());
        $this->helper->paymentTokenRepository->removePaymentToken($salesChannelContext, $data);
        $this->addFlash(self::SUCCESS, $this->translator->trans('NovalnetPayment.text.paymentMethodDeleted'));
        return new Response($this->translator->trans('NovalnetPayment.text.paymentMethodDeleted'));
    }
    
    /**
     * @Route("/novalnet/addToCart", name="frontend.novalnet.addToCart", options={"seo"="false"}, defaults={"csrf_protected"=false, "XmlHttpRequest"=true}, methods={"GET", "POST"})
     */
    public function addToCart(Cart $cart, RequestDataBag $requestDataBag, Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
		$data = $this->helper->unserializeData((string) $request->getContent());
		try {
			$cart = $this->cartHelper->addToCart($data, $cart, $salesChannelContext);
			return new JsonResponse(['success' => true]);
        } catch (ProductNotFoundException $exception) {
            $this->addFlash(self::DANGER, $this->trans('error.addToCartError'));
			return new JsonResponse(['success' => false, 'url' => $this->generateUrl('frontend.checkout.cart.page')]);
        }
	}
	
	/**
     * @Route("/novalnet/updateShipping", name="frontend.novalnet.updateShipping", options={"seo"="false"}, defaults={"csrf_protected"=false, "XmlHttpRequest"=true}, methods={"GET", "POST"})
     */
    public function updateShipping(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
		$data = $this->helper->unserializeData((string) $request->getContent());
		if(!empty($data))
		{
			$salesChannelContext = $this->cartHelper->updateShippingMethod($data['shippingMethod']['identifier'], $salesChannelContext);
			$recalculatedCart    = $this->cartHelper->getCalculatedCart($salesChannelContext);
			$orderDetails        = $this->cartHelper->getFormattedCart($recalculatedCart, $salesChannelContext);
			return new JsonResponse(['lineItem' => $orderDetails, 'totalPrice' => round($recalculatedCart->getPrice()->getTotalPrice() * 100)]);
		}
		return new JsonResponse([]);
	}
    
    /**
     * @Route("/novalnet/loadShipping", name="frontend.novalnet.loadShipping", options={"seo"="false"}, defaults={"csrf_protected"=false, "XmlHttpRequest"=true}, methods={"GET", "POST"})
     */
    public function loadShippingMethod(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
		$data = $this->helper->unserializeData((string) $request->getContent());
		$shippingMethods = $orderDetails = [];
		
		if(!empty($data))
		{
			$salesChannelContext = $this->cartHelper->updatePaymentMethodId($data['paymentMethodId'], $salesChannelContext);
			$shippingMethods     = $this->cartHelper->getAvailableShippingMethod($data['shippingInfo']['countryCode'], $salesChannelContext);
			$recalculatedCart    = $this->cartHelper->getCalculatedCart($salesChannelContext);
			$orderDetails        = $this->cartHelper->getFormattedCart($recalculatedCart, $salesChannelContext);
			return new JsonResponse(['lineItem' => $orderDetails, 'shipping' => $shippingMethods, 'totalPrice' => round($recalculatedCart->getPrice()->getTotalPrice() * 100)]);
		}
		return new JsonResponse([]);
	}
	
	/**
     * @Route("/novalnet/successOrder", name="frontend.novalnet.successOrder", options={"seo"="false"}, defaults={"csrf_protected"=false, "XmlHttpRequest"=true}, methods={"GET", "POST"})
     */
    public function successApplePayOrder(Request $request, SalesChannelContext $salesChannelContext)
    {
		$serverData = $this->helper->unserializeData((string) $request->getContent());
		$requestData = $serverData['serverResponse'];
		$previousCurrencyId = $salesChannelContext->getCurrency()->getId();
		$newToken = $salesChannelContext->getToken();
		try {
			if(is_null($salesChannelContext->getCustomer()))
			{
				$requiredFields = ['firstName', 'lastName', 'addressLines', 'postalCode', 'locality', 'countryCode'];
				foreach ($requiredFields as $key)
				{
					if(empty($requestData['order']['billing']['contact'][$key])) {
						$this->addFlash(self::DANGER, $this->translator->trans('NovalnetPayment.text.billingAddressError'));
						return new JsonResponse(['success' => false, 'message' => $this->translator->trans('NovalnetPayment.text.billingAddressError')]);
					} elseif(empty($requestData['order']['shipping']['contact'][$key])) {
						$this->addFlash(self::DANGER, $this->translator->trans('NovalnetPayment.text.shippingAddressError'));
						return new JsonResponse(['success' => false, 'message' => $this->translator->trans('NovalnetPayment.text.shippingAddressError')]);
					}
				}
				$newToken = $this->cartHelper->createNewCustomer($requestData, $salesChannelContext);
			} elseif (!empty($requestData['order']['billing']['contact']) && !empty($requestData['order']['shipping']['contact'])) {
				// update customer addresses
				$this->cartHelper->updateCustomer($salesChannelContext->getCustomer(), $requestData, $salesChannelContext);
			}
			$salesChannelContext = $this->salesChannelContextFactory->create(
                $newToken,
                $salesChannelContext->getSalesChannel()->getId()
            );
            $salesChannelContext = $this->cartHelper->updatePaymentMethodId($serverData['paymentMethodId'], $salesChannelContext, $previousCurrencyId);
            $this->cartHelper->getCalculatedCart($salesChannelContext);
			$data = new RequestDataBag(['tos' => true, $serverData['paymentName'].'FormData' => ['walletToken' => $requestData['transaction']['token'], 'doRedirect' => $requestData['transaction']['doRedirect']], 'ExpressCheckout' => true]);
            $this->addAffiliateTracking($data, $request->getSession());
            $orderId = $this->orderService->createOrder($data, $salesChannelContext);
        } catch (ConstraintViolationException $formViolations) {
            return new JsonResponse(['success' => true, 'url' => $this->generateUrl('frontend.checkout.confirm.page', ['formViolations' => $formViolations])]);
        } catch (InvalidCartException | Error | EmptyCartException $error) {
            return new JsonResponse(['success' => true, 'url' => $this->generateUrl('frontend.checkout.confirm.page')]);
        }
        
        try {
            $finishUrl = $this->generateUrl('frontend.checkout.finish.page', ['orderId' => $orderId]);
            $errorUrl = $this->generateUrl('frontend.account.edit-order.page', [
                    'orderId' => $orderId,
                    'error-code' => 'CHECKOUT__UNKNOWN_ERROR',
            ]);

            $response = $this->paymentService->handlePaymentByOrder($orderId, $data, $salesChannelContext, $finishUrl, $errorUrl);
            $novalnetResponse = $this->sessionInterface->get('novalnetResponse');
            if($novalnetResponse['result']['status'] === 'FAILURE')
            {
				$this->sessionInterface->remove('novalnetResponse');
				return new JsonResponse(['success' => true, 'url' => $errorUrl]);
			} elseif ($novalnetResponse['result']['status'] === 'SUCCESS' && !empty($novalnetResponse['result']['redirect_url'])) {
				$finishUrl = $novalnetResponse['result']['redirect_url'];
			}
            return $response ?? new JsonResponse(['success' => true, 'url' => $finishUrl]);
        } catch (PaymentProcessException | InvalidOrderException | UnknownPaymentMethodException $e) {
            $errorUrl = $this->generateUrl('frontend.checkout.finish.page', ['orderId' => $orderId, 'changedPayment' => false, 'paymentFailed' => true, 'error-code' => 'CHECKOUT__UNKNOWN_ERROR']);
            return new JsonResponse(['success' => true, 'url' => $errorUrl]);
        }
	}
	
	private function addAffiliateTracking(RequestDataBag $dataBag, SessionInterface $session): void
    {
        $affiliateCode = $session->get(AffiliateTrackingListener::AFFILIATE_CODE_KEY);
        $campaignCode  = $session->get(AffiliateTrackingListener::CAMPAIGN_CODE_KEY);
        if ($affiliateCode) {
            $dataBag->set(AffiliateTrackingListener::AFFILIATE_CODE_KEY, $affiliateCode);
        }

        if ($campaignCode) {
            $dataBag->set(AffiliateTrackingListener::CAMPAIGN_CODE_KEY, $campaignCode);
        }
    }
    
    /**
     * @Route("/novalnet/googlepay", name="frontend.novalnet.googlePayRedirect", options={"seo"="false"}, defaults={"csrf_protected"=false, "XmlHttpRequest"=false}, methods={"GET", "POST"})
     */
    public function authenticateGooglePay(Request $request, SalesChannelContext $salesChannelContext)
    {
		$response = $this->helper->retrieveTransactionDetails($request, $salesChannelContext);
		$orderId  = $response ['custom']['orderId'] ?? $response ['custom']['inputval4'];
		$orderEntity = $this->helper->getOrderCriteria($orderId, $salesChannelContext->getContext(), $salesChannelContext->getCustomer()->getId());
		$transaction = $orderEntity->getTransactions()->last();
		$paymentHandler = $this->paymentHandlerRegistry->getHandlerForPaymentMethod($transaction->getPaymentMethod());
		$paymentTransaction = new SyncPaymentTransactionStruct($transaction, $orderEntity);
		$response = $paymentHandler->handleRedirectResponse($request, $salesChannelContext, $paymentTransaction->getOrderTransaction());
		$paymentHandler->checkTransactionStatus($paymentTransaction->getOrderTransaction(), $response, $salesChannelContext, $paymentTransaction);
		$finishUrl = $this->generateUrl('frontend.checkout.finish.page', ['orderId' => $orderId]);
        $errorUrl = $this->generateUrl('frontend.account.edit-order.page', [
                'orderId' => $orderId,
                'error-code' => 'CHECKOUT__UNKNOWN_ERROR',
        ]);
        
        if ($response['result']['status'] === 'FAILURE')
        {
			$this->addFlash(self::DANGER, $response['result']['status_text']);
			return new RedirectResponse($errorUrl);
		} else {
			return new RedirectResponse($finishUrl);
		}
	}
}
