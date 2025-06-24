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

namespace Novalnet\NovalnetPayment\Controller\Storefront;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class FrontendController extends StorefrontController
{
    /**
     * @var NovalnetHelper
     */
    private $helper;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        NovalnetHelper $helper,
        TranslatorInterface $translator
    ) {
        $this->helper = $helper;
        $this->translator = $translator;
    }

    #[Route(path: '/store/changePaymentData', name: 'frontend.novalnet.storeCustomerData', options: ['seo' => false], defaults: ['XmlHttpRequest' => true], methods: ['GET', 'POST'])]
    public function storeCustomerData(Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = $this->helper->unserializeData((string) $request->getContent());
        $paymentAccessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $context->getSalesChannel()->getId());
        $paymentMethodId = $parentOrderNumber = '';
        if(!isset($data['parentOrderNumber'])) {
            $parentOrderNumber = $this->helper->getOrderNumber($data['aboId'], $context->getContext());
        } else {
            $parentOrderNumber = $data['parentOrderNumber'];
        }

        if(!isset($data['paymentMethodId'])) {
            $paymentMethodId = $this->helper->getNovalnetPaymentId($context->getContext());
        }

        $parameters = $this->helper->getNovalnetRequestData(0, $parentOrderNumber, $data, $context);

        if ((isset($data['booking_details']['do_redirect']) && ($data['booking_details']['do_redirect'] == 1)) || (isset($data['payment_details']['process_mode']) && $data['payment_details']['process_mode'] == 'redirect')) {
            $parameters['transaction']['return_url'] = $parameters['transaction']['error_return_url'] = $this->generateUrl('frontend.novalnet.returnAction', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        // Built custom parameters.
        $parameters['transaction']['create_token'] = 1;
        $parameters['custom']['input1'] = 'subscriptionId';
        $parameters['custom']['input2'] = 'paymentMethodId';
        $parameters['custom']['input3'] = 'change_payment';
        $parameters['custom']['inputval1'] = $data['aboId'];
        $parameters['custom']['inputval2'] = isset($data['paymentMethodId']) ? $data['paymentMethodId'] : $paymentMethodId;
        $parameters['custom']['inputval3'] = 1;

        if (!empty($data['payment_details']['name'])) {
            $parameters['custom']['input5'] = 'Paymentname';
            $parameters['custom']['inputval5'] = $data['payment_details']['name'];
        }

        $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('payment'), $paymentAccessKey);
        if ($response['result']['status'] === 'FAILURE') {
            return new JsonResponse(['success' => false, 'message' => $response['result']['status_text']]);
        } else {
            if (isset($response['result']['redirect_url'])) {
                $this->helper->setSession('iframeData', $data);
                return new JsonResponse(['success' => true, 'redirect_url' => $response['result']['redirect_url']]);
            }
            $insertData = $this->insertTransactionData($response, $data, $context);

            // Upsert data into novalnet_transaction_details.repository
            $this->helper->updateTransactionData($insertData, $context->getContext());

            return new JsonResponse(['success' => true]);
        }
    }

    #[Route(path: '/novalnet/returnAction', name: 'frontend.novalnet.returnAction', options: ['seo' => false], methods: ['GET', 'POST'])]
    public function returnAction(Request $request, SalesChannelContext $context): RedirectResponse
    {
        $response = $this->helper->fetchTransactionDetails($request, $context);
        $localeCode = $this->helper->getLocaleCodeFromContext($context->getContext());
        if ($request->query->get('status') == 'SUCCESS') {
            $data = $this->helper->getSession('iframeData');
            $insertData = $this->insertTransactionData($response, $data, $context);
            // Upsert data into novalnet_transaction_details.repository
            $this->helper->updateTransactionData($insertData, $context->getContext());

            return $this->redirectToRoute(
                'frontend.novalnet.subscription.change.payment',
                ['aboId' => $response['custom']['inputval1'], 'paymentMethodId' => $response['custom']['inputval2']]
            );
        } else {
            $this->addFlash(self::DANGER, $request->query->get('status_text') ?? $this->translator->trans('NovalnetPayment.text.changePaymentError', [], null, $localeCode));
            if (!empty($response['custom']['subscriptionId']) || !empty($response['custom']['inputval1'])) {
                return $this->redirectToRoute(
                    'frontend.novalnet.subscription.orders.detail',
                    ['aboId' => !empty($response['custom']['subscriptionId']) ? $response['custom']['subscriptionId'] : $response['custom']['inputval1']]
                );
            }
            return $this->redirectToRoute('frontend.novalnet.subscription.orders');
        }
    }

    public function insertTransactionData(array $response, array $data, SalesChannelContext $context): array
    {
        $paymentType = $response['transaction']['payment_type'] ?? 'NOVALNET_PAYMENT';
        $localeCode = $this->helper->getLocaleCodeFromContext($context->getContext());

        $datas['payment_details'] = $data['payment_details'];
        if(isset($data['booking_details']) && isset($data['booking_details']['test_mode'])) {
            $datas['booking_details']['test_mode'] = $data['booking_details']['test_mode'];
        }

        // insert novalnet transaction details
        $insertData = [
            'id'    => Uuid::randomHex(),
            'paymentType' => $paymentType,
            'paidAmount' => 0,
            'tid' => $response['transaction']['tid'],
            'gatewayStatus' => $response['transaction']['status'],
            'amount' => $response['transaction']['amount'],
            'currency' => $response['transaction']['currency'],
            'orderNo' => $response['transaction']['order_no'],
            'customerNo' => !empty($response['customer']['customer_no']) ? $response['customer']['customer_no'] : '',
             'additionalDetails' => [
                'payment_name' => (isset($data['payment_details']['name']) && !empty($data['payment_details']['name'])) ? $data['payment_details']['name'] : $this->helper->getUpdatedPaymentName('NOVALNET_PAYMENT', $localeCode),
                'change_payment' => true,
                'subscription' => $datas
            ]
        ];

        if (!empty($response['transaction']['payment_data']['token'])) {
            $insertData['tokenInfo'] = $response['transaction']['payment_data']['token'];
        }

        $insertData['additionalDetails'] = $this->helper->serializeData($insertData['additionalDetails']);

        return $insertData;
    }
}
