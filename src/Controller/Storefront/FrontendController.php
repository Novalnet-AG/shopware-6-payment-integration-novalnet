<?php

declare(strict_types=1);

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
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractSetPaymentOrderRoute;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerRegistry;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentService;
use Shopware\Core\Content\Product\Exception\ProductNotFoundException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Storefront\Event\RouteRequest\SetPaymentOrderRouteRequestEvent;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\AffiliateTracking\AffiliateTrackingListener;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Shopware\Core\Checkout\Promotion\Cart\PromotionCartAddedInformationError;

#[Route(defaults: ['_routeScope' => ['storefront']])]
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
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var AbstractSetPaymentOrderRoute
     */
    private $setPaymentOrderRoute;

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
        EventDispatcherInterface $eventDispatcher,
        AbstractSetPaymentOrderRoute $setPaymentOrderRoute,
        NumberRangeValueGeneratorInterface $numberRangeValueGenerator
    ) {
        $this->helper           = $helper;
        $this->cartHelper       = $cartHelper;
        $this->translator       = $translator;
        $this->orderService     = $orderService;
        $this->paymentService   = $paymentService;
        $this->eventDispatcher  = $eventDispatcher;
        $this->paymentHandlerRegistry = $paymentHandlerRegistry;
        $this->setPaymentOrderRoute   = $setPaymentOrderRoute;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->numberRangeValueGenerator    = $numberRangeValueGenerator;
    }

    #[Route(path: '/remove/paymentToken', name: 'frontend.checkout.removePaymentToken', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false], options: ['seo' => false])]
    public function removePaymentToken(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $data = $this->helper->unserializeData((string) $request->getContent());
        $this->helper->paymentTokenRepository->removePaymentToken($salesChannelContext, $data);
        $localeCode = $this->helper->getLocaleCodeFromContext($salesChannelContext->getContext(), true);
        $this->addFlash(self::SUCCESS, $this->translator->trans('NovalnetPayment.text.paymentMethodDeleted', [], null, $localeCode));
        return new Response($this->translator->trans('NovalnetPayment.text.paymentMethodDeleted', [], null, $localeCode));
    }

    #[Route(path: '/novalnet/addToCart', name: 'frontend.novalnet.addToCart', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false], options: ['seo' => false])]
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

    #[Route(path: '/novalnet/updateShipping', name: 'frontend.novalnet.updateShipping', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false], options: ['seo' => false])]
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

    #[Route(path: '/novalnet/loadShipping', name: 'frontend.novalnet.loadShipping', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false], options: ['seo' => false])]
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

    #[Route(path: '/novalnet/successOrder', name: 'frontend.novalnet.successOrder', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false], options: ['seo' => false])]
    public function successOrder(Request $request, SalesChannelContext $salesChannelContext)
    {
        $serverData = $this->helper->unserializeData((string) $request->getContent());
        $requestData = $serverData['serverResponse'];
        $previousCurrencyId = $salesChannelContext->getCurrency()->getId();
        $newToken = $salesChannelContext->getToken();

        try {
            if (is_null($salesChannelContext->getCustomer())) {
                $localeCode = $this->helper->getLocaleCodeFromContext($salesChannelContext->getContext(), true);
                $requiredFields = ['firstName', 'lastName', 'addressLines', 'postalCode', 'locality', 'countryCode'];
                foreach ($requiredFields as $key) {
                    if (empty($requestData['order']['billing']['contact'][$key])) {
                        $this->addFlash(self::DANGER, $this->translator->trans('NovalnetPayment.text.billingAddressError'));
                        return new JsonResponse(['success' => false, 'message' => $this->translator->trans('NovalnetPayment.text.billingAddressError', [], null, $localeCode)]);
                    } elseif (empty($requestData['order']['shipping']['contact'][$key])) {
                        $this->addFlash(self::DANGER, $this->translator->trans('NovalnetPayment.text.shippingAddressError'));
                        return new JsonResponse(['success' => false, 'message' => $this->translator->trans('NovalnetPayment.text.shippingAddressError', [], null, $localeCode)]);
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
                [SalesChannelContextService::LANGUAGE_ID => !empty($salesChannelContext->getContext()->getLanguageId()) ? $salesChannelContext->getContext()->getLanguageId() : $salesChannelContext->getSalesChannel()->getLanguageId()]
            );
            $salesChannelContext = $this->cartHelper->updatePaymentMethodId($serverData['paymentMethodId'], $salesChannelContext, $previousCurrencyId);

            if (!empty($serverData['orderId'])) {
                $orderId = $serverData['orderId'];
                $finishUrl = $this->generateUrl('frontend.checkout.finish.page', [
                    'orderId' => $orderId,
                    'changedPayment' => true,
                ]);

                $errorUrl = $this->generateUrl('frontend.account.edit-order.page', [
                    'orderId' => $orderId
                ]);

                // load order from order ID
                $order = $this->helper->getOrderCriteria($orderId, $salesChannelContext->getContext());

                $setPaymentRequest = new Request();
                $setPaymentRequest->request->set('orderId', $orderId);
                $setPaymentRequest->request->set('paymentMethodId', $serverData['paymentMethodId']);

                $setPaymentOrderRouteRequestEvent = new SetPaymentOrderRouteRequestEvent($request, $setPaymentRequest, $salesChannelContext);
                $this->eventDispatcher->dispatch($setPaymentOrderRouteRequestEvent);

                $this->setPaymentOrderRoute->setPayment($setPaymentOrderRouteRequestEvent->getStoreApiRequest(), $salesChannelContext);

                $data = new RequestDataBag([$serverData['paymentName'].'FormData' => ['walletToken' => $requestData['transaction']['token'], 'doRedirect' => $requestData['transaction']['doRedirect']], 'ExpressCheckout' => true]);
            } else {
                $cart = $this->cartHelper->getCalculatedCart($salesChannelContext);
                $novalnetConfiguration = [];

                if ($cart->hasExtension('novalnetConfiguration')) {
                    $novalnetConfiguration = $cart->getExtension('novalnetConfiguration')->all();
                } elseif (!empty($this->helper->getSession('novalnetConfiguration'))) {
                    $novalnetConfiguration = $this->helper->getSession('novalnetConfiguration');
                }

                $isSubscriptionOrder = false;

                if (!empty($novalnetConfiguration)) {
                    $isSubscriptionOrder = true;
                    $this->helper->setSession('novalnetConfiguration', $novalnetConfiguration);
                }

                $data = new RequestDataBag(['tos' => true, 'revocation' => true, 'isSubscriptionOrder' =>  $isSubscriptionOrder, $serverData['paymentName'].'FormData' => ['walletToken' => $requestData['transaction']['token'], 'doRedirect' => $requestData['transaction']['doRedirect']], 'ExpressCheckout' => true]);
                $this->addAffiliateTracking($data, $request->getSession());
                $orderId = $this->orderService->createOrder($data, $salesChannelContext);
            }
        } catch (ConstraintViolationException $formViolations) {
            return new JsonResponse(['success' => true, 'url' => $this->generateUrl('frontend.checkout.confirm.page', ['formViolations' => $formViolations])]);
        } catch (\Exception $error) {
            return new JsonResponse(['success' => true, 'url' => $this->generateUrl('frontend.checkout.confirm.page')]);
        }

        try {
            if (!empty($serverData['orderId'])) {
                $finishUrl = $this->generateUrl('frontend.checkout.finish.page', ['orderId' => $orderId, 'changedPayment' => true]);
            } else {
                $finishUrl = $this->generateUrl('frontend.checkout.finish.page', ['orderId' => $orderId]);
            }
            $errorUrl = $this->generateUrl('frontend.account.edit-order.page', [
                    'orderId' => $orderId,
                    'error-code' => 'CHECKOUT__UNKNOWN_ERROR',
            ]);

            $response = $this->paymentService->handlePaymentByOrder($orderId, $data, $salesChannelContext, $finishUrl, $errorUrl);
            $novalnetResponse = $this->helper->getSession('novalnetResponse') ?? $response;
            if ($novalnetResponse['result']['status'] === 'FAILURE') {
                $this->helper->removeSession('novalnetResponse');
                return new JsonResponse(['success' => false, 'message' => $novalnetResponse['result']['status_text']]);
            } elseif ($novalnetResponse['result']['status'] === 'SUCCESS' && !empty($novalnetResponse['result']['redirect_url'])) {
                $finishUrl = $novalnetResponse['result']['redirect_url'];
            }
            return $response ?? new JsonResponse(['success' => true, 'url' => $finishUrl]);
        } catch (\Exception $e) {
            $errorUrl = $this->generateUrl('frontend.checkout.finish.page', ['orderId' => $orderId, 'changedPayment' => false, 'paymentFailed' => true, 'error-code' => 'CHECKOUT__UNKNOWN_ERROR']);
            return new JsonResponse(['success' => true, 'url' => $errorUrl]);
        }
    }

    #[Route(path: '/novalnet/googlepay', name: 'frontend.novalnet.googlePayRedirect', methods: ['POST', 'GET'], defaults: ['csrf_protected' => false], options: ['seo' => false])]
    public function authenticateGooglePay(Request $request, SalesChannelContext $salesChannelContext)
    {
        $response = $this->helper->retrieveTransactionDetails($request, $salesChannelContext);
        $orderId  = !empty($response ['custom']['orderId']) ? $response ['custom']['orderId'] : (!empty($response ['custom']['inputval1']) ? $response ['custom']['inputval1'] : '');

        $orderEntity = $this->helper->getOrderCriteria(
            $orderId,
            $salesChannelContext->getContext(),
            $salesChannelContext->getCustomer()->getId()
        );

        $novalnetConfiguration = $this->helper->getSession('novalnetConfiguration');

        $this->helper->removeSession('novalnetConfiguration');

        if (!empty($novalnetConfiguration)) {
            $this->insertSubscription($orderEntity, $novalnetConfiguration, $salesChannelContext);
        }

        $firstTransaction = $orderEntity->getTransactions()->first();
        $lastTransaction = $orderEntity->getTransactions()->last();
        if ($firstTransaction->getCreatedAt()->format('Y-m-d H:i:s') > $lastTransaction->getCreatedAt()->format('Y-m-d H:i:s')) {
            $transaction = $firstTransaction;
        } else {
            $transaction = $lastTransaction;
        }

        $paymentHandler = $this->paymentHandlerRegistry->getPaymentMethodHandler(
            $transaction->getPaymentMethod()->getId()
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

    #[Route(path: '/store/changePaymentData', name: 'frontend.novalnet.storeCustomerData', methods: ['POST'], defaults: ['csrf_protected' => false, 'XmlHttpRequest' => true], options: ['seo' => false])]
    public function storeCustomerData(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $data = $this->helper->unserializeData((string) $request->getContent());
        // Built merchant parameters.
        $parameters['merchant'] = [
            'signature' => str_replace(' ', '', $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.clientId', $salesChannelContext->getSalesChannel()->getId())),
            'tariff'    => $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.tariff', $salesChannelContext->getSalesChannel()->getId())
        ];

        // Built customer parameters.
        if (!is_null($salesChannelContext->getCustomer())) {
            $parameters['customer'] = $this->helper->getCustomerData($salesChannelContext->getCustomer());
        }

        // Built transaction parameters.
        $parameters['transaction'] = [
            'amount'         => 0,
            'test_mode'      => (int) $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.". $data['paymentName'] ."TestMode", $salesChannelContext->getSalesChannel()->getId()),
            'create_token'	 => 1,
            'payment_type'   => strtoupper($data['paymentName']),
            'system_name'    => 'Shopware6',
            'currency'       => $salesChannelContext->getCurrency()->getIsoCode() ? $salesChannelContext->getCurrency()->getIsoCode() : $salesChannelContext->getSalesChannel()->getCurrency()->getIsoCode(),
            'system_ip'      => $this->helper->getIp('SYSTEM'),
            'system_version' => $this->helper->getVersionInfo($salesChannelContext->getContext()),
            'order_no'       => $data['parentOrderNumber'],
        ];

        // Built custom parameters.
        $parameters['custom'] = [
            'lang'      => $this->helper->getLocaleCodeFromContext($salesChannelContext->getContext()),
            'input1'    => 'subscriptionId',
            'inputval1' => $data['aboId'],
            'input2'    => 'paymentMethodId',
            'inputval2' => $data['paymentMethodId'],
            'input3'    => 'change_payment',
            'inputval3' => 1
        ];

        // Built payment parameters
        foreach ([
            'pan_hash', 'unique_id', 'iban', 'bic', 'wallet_token', 'token', 'account_holder', 'account_number', 'routing_number'
        ] as $key) {
            if (! empty($data[$key])) {
                $parameters['transaction'] ['payment_data'][$key] = ($key == 'iban') ? strtoupper(str_replace(' ', '', $data[$key])) : $data[$key];
            }
        }

        if ($data['paymentName'] == 'googlepay') {
            $enforce3D  = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.googlepayEnforcecc3D', $salesChannelContext->getSalesChannel()->getId());

            if (!empty($enforce3D)) {
                $parameters['transaction']['enforce_3d'] = 1;
            }
        }

        if (isset($data['doRedirect']) && ($data['doRedirect'] == 1)) {
            $this->helper->getRedirectParams($parameters, false);
        }

        $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('payment'), $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $salesChannelContext->getSalesChannel()->getId()));

        if ($response['result']['status'] === 'FAILURE') {
            return new JsonResponse(['success' => false, 'message' => $response['result']['status_text']]);
        } else {
            if (!empty($response['result']['redirect_url'])) {
                return new JsonResponse(['success' => true, 'redirect_url' => $response['result']['redirect_url']]);
            }

            $insertData = $this->formTransactionData($response);
            // Upsert data into novalnet_transaction_details.repository
            $this->helper->upsertTransactionData($insertData, $salesChannelContext->getContext());

            return new JsonResponse(['success' => true]);
        }
    }

    #[Route(path: '/novalnet/returnAction', name: 'frontend.novalnet.returnAction', methods: ['GET','POST'], defaults: ['csrf_protected' => false], options: ['seo' => false])]
    public function returnAction(Request $request, SalesChannelContext $context): RedirectResponse
    {
        $response = $this->helper->retrieveTransactionDetails($request, $context);
        if ($request->query->get('status') == 'SUCCESS') {
            $insertData = $this->formTransactionData($response);
            // Upsert data into novalnet_transaction_details.repository
            $this->helper->upsertTransactionData($insertData, $context->getContext());

            return $this->redirectToRoute(
                'frontend.novalnet.subscription.change.payment',
                ['aboId' => $response['custom']['inputval1'], 'paymentMethodId' => $response['custom']['inputval2']]
            );
        } else {
            $this->addFlash(self::DANGER, $request->query->get('status_text') ?? $this->translator->trans('NovalnetPayment.text.changePaymentError', [], null, $this->helper->getLocaleCodeFromContext($context->getContext())));
            if (!empty($response['custom']['subscriptionId']) || !empty($response['custom']['inputval1'])) {
                return $this->redirectToRoute(
                    'frontend.novalnet.subscription.orders.detail',
                    ['aboId' => !empty($response['custom']['subscriptionId']) ? $response['custom']['subscriptionId'] : $response['custom']['inputval1']]
                );
            }
            return $this->redirectToRoute('frontend.novalnet.subscription.orders');
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
                $amount = $orderLineItem->getPrice()->getUnitPrice() * $orderLineItem->getQuantity();
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

                $customerCancelOption = $this->helper->getNovalnetPaymentSettings('NovalnetSubscription.config.customerCancelOption', $context->getSalesChannel()->getId());
                $calculateShippingOnce = $this->helper->getNovalnetPaymentSettings('NovalnetSubscription.config.calculateShippingOnce', $context->getSalesChannel()->getId());

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
                    'customerCancelOption' => $customerCancelOption ?? false,
                    'shippingCalculateOnce' => $calculateShippingOnce ?? false
                ];

                $subsCycleData = [
                    'orderId'  => $order->getId(),
                    'status'   => 'PENDING',
                    'interval' => $subcriptionSettings['interval'],
                    'period'   => $formattedPeriod,
                    'amount'   => $amount,
                    'paymentMethodId' => $context->getPaymentMethod()->getId(),
                    'cycleDate' => date('Y-m-d H:i:s')
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
     * Get Affiliate Tracking Code.
     *
     * @param RequestDataBag $dataBag
     * @param SessionInterface $session
     *
     * @return void
     */
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
    private function getFormattedDate(int $interval, string $period, string $date): string
    {
        return date('Y-m-d H:i:s', strtotime('+ '. $interval . $period, strtotime($date)));
    }

    /**
     * Form the transaction data
     *
     * @param array $response
     *
     * @return array
     */
    public function formTransactionData(array $response): array
    {
        $insertData = [
            'id'         => Uuid::randomHex(),
            'tid'        => $response['transaction']['tid'],
            'gatewayStatus' => $response['transaction']['status'],
            'currency' => $response['transaction']['currency'],
            'orderNo'    => $response['transaction']['order_no'],
            'amount'     => 0,
            'paidAmount' => 0,
            'customerNo' => !empty($response['customer']['customer_no']) ? $response['customer']['customer_no'] : '',
            'paymentType' => $response['transaction']['payment_type'] != 'DIRECT_DEBIT_SEPA' ? 'novalnet' . strtolower(str_replace('_', '', $response['transaction']['payment_type'])) : 'novalnetsepa'
        ];

        if (!empty($response['transaction']['payment_data']['token'])) {
            $insertData['additionalDetails'] = $this->helper->serializeData(['token' => $response['transaction']['payment_data']['token'], 'change_payment' => true]);
        }

        return $insertData;
    }

    #[Route(path: '/novalnet/couponCodeUpdate', name: 'frontend.novalnet.couponCodeUpdate', methods: ['POST'], defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false], options: ['seo' => false])]
    public function couponCodeUpdate(Cart $cart, RequestDataBag $requestDataBag, Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $data = $this->helper->unserializeData((string) $request->getContent());

        if (!empty($data)) {
            $cart = $this->cartHelper->updateCouponCode($cart, $data['couponCode'], $salesChannelContext);
            $addedEvents = $cart->getErrors()->filterInstance(PromotionCartAddedInformationError::class);
            if ($addedEvents->count() > 0) {
                $orderDetails = $this->cartHelper->getFormattedCart($cart, $salesChannelContext);
                return new JsonResponse(['status' => 'success','lineItem' => $orderDetails, 'totalPrice' => round($cart->getPrice()->getTotalPrice() * 100)]);
            } else {
                return new JsonResponse(['status' => 'failure']);
            }


        }
        return new JsonResponse([]);
    }
}
