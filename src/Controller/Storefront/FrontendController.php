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
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
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
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Storefront\Controller\StorefrontController;
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
    
    /**
     * @var NumberRangeValueGeneratorInterface
     */
    private $numberRangeValueGenerator;

    public function __construct(
        NovalnetHelper $helper,
        NovalnetCartHelper $cartHelper,
        TranslatorInterface $translator,
        OrderService $orderService,
        PaymentService $paymentService,
        AbstractSalesChannelContextFactory $salesChannelContextFactory,
        PaymentHandlerRegistry $paymentHandlerRegistry,
        SessionInterface $sessionInterface,
        NumberRangeValueGeneratorInterface $numberRangeValueGenerator
    ) {
        $this->helper           = $helper;
        $this->cartHelper       = $cartHelper;
        $this->translator       = $translator;
        $this->orderService     = $orderService;
        $this->paymentService   = $paymentService;
        $this->sessionInterface = $sessionInterface;
        $this->paymentHandlerRegistry = $paymentHandlerRegistry;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->numberRangeValueGenerator    = $numberRangeValueGenerator;
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
        if (!empty($data)) {
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
        
        if (!empty($data)) {
            $shippingMethods     = $this->cartHelper->getAvailableShippingMethod($data['shippingInfo']['countryCode'], $salesChannelContext);
            $salesChannelContext = $this->cartHelper->updatePaymentMethodId($data['paymentMethodId'], $salesChannelContext);
            $recalculatedCart    = $this->cartHelper->getCalculatedCart($salesChannelContext);
            $orderDetails        = $this->cartHelper->getFormattedCart($recalculatedCart, $salesChannelContext);
            return new JsonResponse(['lineItem' => $orderDetails, 'shipping' => $shippingMethods, 'totalPrice' => round($recalculatedCart->getPrice()->getTotalPrice() * 100)]);
        }
        return new JsonResponse([]);
    }
    
    /**
     * @Route("/novalnet/successOrder", name="frontend.novalnet.successOrder", options={"seo"="false"}, defaults={"csrf_protected"=false, "XmlHttpRequest"=true}, methods={"GET", "POST"})
     */
    public function successOrder(Request $request, SalesChannelContext $salesChannelContext)
    {
        $serverData = $this->helper->unserializeData((string) $request->getContent());
        $requestData = $serverData['serverResponse'];
        $previousCurrencyId = $salesChannelContext->getCurrency()->getId();
        $newToken = $salesChannelContext->getToken();
        try {
            if (is_null($salesChannelContext->getCustomer())) {
                $requiredFields = ['firstName', 'lastName', 'addressLines', 'postalCode', 'locality', 'countryCode'];
                foreach ($requiredFields as $key) {
                    if (empty($requestData['order']['billing']['contact'][$key])) {
                        $this->addFlash(self::DANGER, $this->translator->trans('NovalnetPayment.text.billingAddressError'));
                        return new JsonResponse(['success' => false, 'message' => $this->translator->trans('NovalnetPayment.text.billingAddressError')]);
                    } elseif (empty($requestData['order']['shipping']['contact'][$key])) {
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
                $salesChannelContext->getSalesChannel()->getId(),
                [SalesChannelContextService::LANGUAGE_ID => $salesChannelContext->getSalesChannel()->getLanguageId()]
            );
            $salesChannelContext = $this->cartHelper->updatePaymentMethodId($serverData['paymentMethodId'], $salesChannelContext, $previousCurrencyId);
            $cart = $this->cartHelper->getCalculatedCart($salesChannelContext);
            
            $novalnetConfiguration = [];
            if ($cart->hasExtension('novalnetConfiguration')) {
                $novalnetConfiguration = $cart->getExtension('novalnetConfiguration')->all();
            } elseif ($this->sessionInterface->get('novalnetConfiguration')) {
                $novalnetConfiguration = $this->sessionInterface->get('novalnetConfiguration');
            }
            
            $isSubscriptionOrder = false;
            
            if (!empty($novalnetConfiguration)) {
                $isSubscriptionOrder = true;
                $this->sessionInterface->set('novalnetConfiguration', $novalnetConfiguration);
            }
            
            $data = new RequestDataBag(['tos' => true, 'revocation' => true, 'isSubscriptionOrder' =>  $isSubscriptionOrder, $serverData['paymentName'].'FormData' => ['walletToken' => $requestData['transaction']['token'], 'doRedirect' => $requestData['transaction']['doRedirect']], 'ExpressCheckout' => true]);
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
            if ($novalnetResponse['result']['status'] === 'FAILURE') {
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
            $dataBag->set(
                AffiliateTrackingListener::CAMPAIGN_CODE_KEY,
                $campaignCode
            );
        }
    }
    
    /**
     * @Route("/novalnet/googlepay", name="frontend.novalnet.googlePayRedirect", options={"seo"="false"}, defaults={"csrf_protected"=false, "XmlHttpRequest"=false}, methods={"GET", "POST"})
     */
    public function authenticateGooglePay(Request $request, SalesChannelContext $salesChannelContext)
    {
        $response = $this->helper->retrieveTransactionDetails($request, $salesChannelContext);
        
        $orderId  = $response ['custom']['orderId'] ?? $response ['custom']['inputval4'];
        
        $orderEntity = $this->helper->getOrderCriteria(
            $orderId,
            $salesChannelContext->getContext(),
            $salesChannelContext->getCustomer()->getId()
        );
        
        $novalnetConfiguration = $this->sessionInterface->get('novalnetConfiguration');
        $this->sessionInterface->remove('novalnetConfiguration');
        
        if (!empty($novalnetConfiguration)) {
            $this->insertSubscription($orderEntity, $novalnetConfiguration, $salesChannelContext);
        }
        
        $transaction = $orderEntity->getTransactions()->last();
        
        $paymentHandler = $this->paymentHandlerRegistry->getHandlerForPaymentMethod(
            $transaction->getPaymentMethod()
        );
        
        $paymentTransaction = new SyncPaymentTransactionStruct($transaction, $orderEntity);
        
        $response = $paymentHandler->handleRedirectResponse(
            $request,
            $salesChannelContext,
            $paymentTransaction->getOrderTransaction()
        );
        
        $paymentHandler->checkTransactionStatus(
            $paymentTransaction->getOrderTransaction(),
            $response,
            $salesChannelContext,
            $paymentTransaction,
            '1'
        );
        
        $finishUrl = $this->generateUrl('frontend.checkout.finish.page', ['orderId' => $orderId]);
        
        $errorUrl = $this->generateUrl('frontend.account.edit-order.page', [
                'orderId' => $orderId,
                'error-code' => 'CHECKOUT__UNKNOWN_ERROR',
        ]);
        
        if ($response['result']['status'] === 'FAILURE') {
            $this->addFlash(self::DANGER, $response['result']['status_text']);
            return new RedirectResponse($errorUrl);
        } else {
            return new RedirectResponse($finishUrl);
        }
    }
    
    /**
     * Insert the subscription data.
     *
     * @param object $order
     * @param array $novalnetConfiguration
     * @param SalesChannelContext $context
     *
     * @return void
     */
    private function insertSubscription($order, array $novalnetConfiguration, SalesChannelContext $context): void
    {
        foreach ($order->getLineItems()->getElements() as $orderLineItem) {
            if ($orderLineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }
            
            $lineItemId = $this->getLineItemId($orderLineItem);
            
            if (in_array($lineItemId, array_keys($novalnetConfiguration))) {
                if (!empty($lineItemAmount[$orderLineItem->getIdentifier()])) {
                    $amount = $lineItemAmount[$orderLineItem->getIdentifier()] * $orderLineItem->getQuantity();
                } else {
                    $amount = $orderLineItem->getPrice()->getUnitPrice() * $orderLineItem->getQuantity();
                }
                
                $subcriptionSettings = (array) $novalnetConfiguration[$lineItemId];
                
                $discount = 0;
                if (! empty($subcriptionSettings['discount'])) {
                     $discount = $subcriptionSettings['discount'];
                }

                $formattedPeriod = $this->getFormattedPeriod($subcriptionSettings['period']);
                $length = $subcriptionSettings['subscriptionLength'] == 0 ? $subcriptionSettings['subscriptionLength'] : $subcriptionSettings['subscriptionLength'] / $subcriptionSettings['interval'];
                
                $nextDate = $endingDate = date('Y-m-d H:i:s');
                if (!empty($subcriptionSettings['freeTrial'])) {
                    $nextDate   = $this->getFormattedDate($subcriptionSettings['freeTrial'], $subcriptionSettings['freeTrialPeriod'], date('Y-m-d H:i:s'));
                    $endingDate = $nextDate;
                } else {
                    $nextDate   = $this->getFormattedDate($subcriptionSettings['interval'], $subcriptionSettings['period'], date('Y-m-d H:i:s'));
                }
                
                $subSettings = $this->helper->getNovalnetSubscriptionSettings($context->getSalesChannel()->getId());
                
                $subsData = [
                    'orderId'    => $order->getId(),
                    'subsNumber' => $this->numberRangeValueGenerator->getValue('novalnet_subscription', $context->getContext(), $context->getSalesChannel()->getId()),
                    'lineItemId' => $orderLineItem->getId(),
                    'customerId' => $context->getCustomer()->getId(),
                    'interval'   => $subcriptionSettings['interval'],
                    'unit'       => $formattedPeriod,
                    'length'     => $length,
                    'amount'     => $amount,
                    'discount'   => (int) $discount,
                    'status'     => 'PENDING',
                    'nextDate'   => $nextDate,
                    'endingAt'   => $this->getFormattedDate($subcriptionSettings['subscriptionLength'], $subcriptionSettings['period'], $endingDate),
                    'paymentMethodId' => $context->getPaymentMethod()->getId(),
                    'customerCancelOption' => $subSettings['NovalnetSubscription.config.customerCancelOption'] ?? false,
                    'shippingCalculateOnce' => $subSettings['NovalnetSubscription.config.calculateShippingOnce'] ?? false
                ];

                $subsCycleData = [
                    'orderId'  => $order->getId(),
                    'status'   => 'PENDING',
                    'interval' => $subcriptionSettings['interval'],
                    'period'   => $formattedPeriod,
                    'amount'   => $amount,
                    'paymentMethodId' => $context->getPaymentMethod()->getId(),
                    'cycleDate'=> date('Y-m-d H:i:s')
                ];
                
                if (!empty($subcriptionSettings['freeTrial'])) {
                    $subsData['trialInterval']  = $subcriptionSettings['freeTrial'];
                    $subsData['trialUnit']      = $this->getFormattedPeriod($subcriptionSettings['freeTrialPeriod']);
                }
                
                // Save Subscription data
                $subsId = $this->helper->insertSubscriptionData($subsData, $context->getContext());
                
                $subsCycleData['subsId'] = $subsId;
                
                // Save Subscription cycle data
                $this->helper->insertSubscriptionCycleData($subsCycleData, $context->getContext());
                
                $subsCycleData['orderId']   = null;
                $subsCycleData['cycleDate'] = $this->getFormattedDate($subcriptionSettings['interval'], $subcriptionSettings['period'], date('Y-m-d H:i:s'));
                
                // Save Next Subscription cycle data
                $this->helper->insertSubscriptionCycleData($subsCycleData, $context->getContext());
            }
        }
    }
    
    /**
     * Returns the line item ID.
     *
     * @param object $orderLineItem
     *
     * @return string
     */
    private function getLineItemId(object $orderLineItem): string
    {
        $lineItemSettings = str_replace($orderLineItem->getReferencedId(), '', $orderLineItem->getIdentifier());
        
        return $orderLineItem->getReferencedId(). '_' . substr($lineItemSettings, 0, 1). '_' .substr($lineItemSettings, 1);
    }
    
    /**
     * Returns the formatted period.
     *
     * @param string $period
     *
     * @return string
     */
    private function getFormattedPeriod(string $period): string
    {
        return substr($period, 0, 1);
    }
    
    /**
     * Returns the formatted date.
     *
     * @param int $interval
     * @param string $period
     * @param string $date
     *
     * @return string
     */
    private function getFormattedDate(int $interval, string $period, $date): string
    {
        return date('Y-m-d H:i:s', strtotime('+ '. $interval . $period, strtotime($date)));
    }
}
