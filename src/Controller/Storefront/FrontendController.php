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
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
class FrontendController extends StorefrontController
{
    /**
     * @var NovalnetHelper
     */
    private $helper;
    
    /**
     * @var RouterInterface
     */
    private $router;
    
    /**
     * {@inheritdoc}
     */
    public function __construct(
        NovalnetHelper $helper,
        RouterInterface $router
    ) {
        $this->helper = $helper;
        $this->router = $router;
    }
    
    /**
     * @Route("/store/changePaymentData", name="frontend.novalnet.storeCustomerData", options={"seo"="false"}, defaults={"csrf_protected"=false, "XmlHttpRequest"=true}, methods={"GET", "POST"})
     */
    public function storeCustomerData(Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = $this->helper->unserializeData((string) $request->getContent());
        
        $paymentSettings = $this->helper->getNovalnetPaymentSettings($context->getSalesChannel()->getId());
        
         // Built merchant parameters.
        $parameters['merchant'] = [
            'signature' => str_replace(' ', '', $paymentSettings['NovalnetPayment.settings.clientId']),
            'tariff'    => $paymentSettings['NovalnetPayment.settings.tariff']
        ];
        
        $customer = $context->getCustomer();
        
        // Built customer parameters.
        if (!empty($customer)) {
            $parameters['customer'] = $this->helper->getCustomerData($customer);
        }
        
        //Build Transaction paramters.
        $parameters['transaction'] = [
            'amount'         => 0,
            'order_no'       => $data['parentOrderNumber'],
            'currency'       => $context->getCurrency()->getIsoCode() ? $context->getCurrency()->getIsoCode() : $context->getSalesChannel()->getCurrency()->getIsoCode(),
            'create_token'   => 1,
            'test_mode'      => (int) $data['booking_details']['test_mode'],
            'payment_type'   => $data['payment_details']['type'],
            'system_name'    => 'Shopware',
            'system_ip'      => $this->helper->getIp('SYSTEM'),
            'system_version' => $this->helper->getVersionInfo($context->getContext()),
        ];
        
        if (!empty($data['booking_details']['do_redirect']) || (!empty($data['payment_details']['process_mode']) && $data['payment_details']['process_mode'] == 'redirect')) {
            $parameters['transaction']['return_url'] = $parameters['transaction']['error_return_url'] = $this->generateAbsoluteUrl('frontend.novalnet.returnAction');
        }
        
        $paymentDataKeys = ['account_holder', 'iban', 'bic', 'wallet_token', 'pan_hash', 'unique_id', 'account_number', 'routing_number'];

        foreach ($paymentDataKeys as $paymentDataKey) {
            if (!empty($data['booking_details'][$paymentDataKey])) {
                $parameters['transaction']['payment_data'][$paymentDataKey] = $data['booking_details'][$paymentDataKey];
            }
        }

        if (!empty($data['booking_details']['payment_ref']['token'])) {
            $parameters['transaction']['payment_data']['token'] = $data['booking_details']['payment_ref']['token'];
        }
        
        // Built custom parameters.
        $parameters['custom'] = [
            'lang'      => $this->helper->getLocaleCodeFromContext($context->getContext()),
            'input1'    => 'subscriptionId',
            'inputval1' => $data['aboId'],
            'input2'    => 'paymentMethodId',
            'inputval2' => $data['paymentMethodId'],
            'input3'    => 'change_payment',
            'inputval3' => 1
        ];
        
        $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('payment'), $paymentSettings['NovalnetPayment.settings.accessKey']);
        
        if ($response['result']['status'] === 'FAILURE') {
            return new JsonResponse(['success' => false, 'message' => $response['result']['status_text']]);
        } else {
            if (!empty($response['result']['redirect_url'])) {
                $this->helper->setSession('iframeData', $data);
                return new JsonResponse(['success' => true, 'redirect_url' => $response['result']['redirect_url']]);
            }
            
            $insertData = [
                'id'    => Uuid::randomHex(),
                'paymentType' => $response['transaction']['payment_type'],
                'paidAmount' => 0,
                'tid' => $response['transaction']['tid'],
                'gatewayStatus' => $response['transaction']['status'],
                'amount' => $response['transaction']['amount'],
                'currency' => $response['transaction']['currency'],
                'orderNo' => $response['transaction']['order_no'],
                'customerNo' => !empty($response['customer']['customer_no']) ? $response['customer']['customer_no'] : '',
                 'additionalDetails' => [
                    'payment_name' => !empty($data['payment_details']['name']) ? $data['payment_details']['name'] : $this->helper->getUpdatedPaymentName($response['transaction']['payment_type']),
                    'change_payment' => true,
                    'subscription' => $data
                ]
            ];
            
            if (!empty($response['transaction']['payment_data']['token'])) {
                $insertData['tokenInfo'] = $response['transaction']['payment_data']['token'];
            }
            
            $insertData['additionalDetails'] = $this->helper->serializeData($insertData['additionalDetails']);
            
            // Upsert data into novalnet_transaction_details.repository
            $this->helper->updateTransactionData($insertData, $context->getContext());
            
            return new JsonResponse(['success' => true]);
        }
    }
    
    /**
     * Generate the absolute URL.
     *
     * @param string $name
     * @param array $parameter
     *
     * @return string
     */
    public function generateAbsoluteUrl(string $name, array $parameter = [])
    {
        return $this->router->generate($name, $parameter, UrlGeneratorInterface::ABSOLUTE_URL);
    }
    
    /**
     * @Route("/novalnet/returnAction", name="frontend.novalnet.returnAction", options={"seo"="false"}, defaults={"csrf_protected"=false}, methods={"GET", "POST"})
     */
    public function returnAction(Request $request, SalesChannelContext $context): RedirectResponse
    {
        if ($request->query->get('status') == 'SUCCESS') {
            $data = $this->helper->getSession('iframeData');
            $response = $this->helper->fetchTransactionDetails($request, $context);
            
            // insert novalnet transaction details
            $insertData = [
                'id'    => Uuid::randomHex(),
                'paymentType' => $response['transaction']['payment_type'],
                'paidAmount' => 0,
                'tid' => $response['transaction']['tid'],
                'gatewayStatus' => $response['transaction']['status'],
                'amount' => $response['transaction']['amount'],
                'currency' => $response['transaction']['currency'],
                'orderNo' => $response['transaction']['order_no'],
                'customerNo' => !empty($response['customer']['customer_no']) ? $response['customer']['customer_no'] : '',
                'tokenInfo' => $response['transaction']['payment_data']['token'],
                 'additionalDetails' => [
                    'payment_name' => $this->helper->getUpdatedPaymentName($response['transaction']['payment_type']),
                    'change_payment' => true,
                    'subscription' => $data
                ]
            ];
            
            if (!empty($response['transaction']['payment_data']['token'])) {
                $insertData['additionalDetails']['token'] = $response['transaction']['payment_data']['token'];
            }
            
            $insertData['additionalDetails'] = $this->helper->serializeData($insertData['additionalDetails']);
            
            // Upsert data into novalnet_transaction_details.repository
            $this->helper->updateTransactionData($insertData, $context->getContext());
            
            return $this->redirectToRoute(
                'frontend.novalnet.subscription.change.payment',
                ['aboId' => $response['custom']['inputval1'], 'paymentMethodId' => $response['custom']['inputval2']]
            );
        } else {
            $response = $this->helper->fetchTransactionDetails($request, $context);
            $this->addFlash(self::DANGER, $request->query->get('status_text') ?? 'Payment method not able to change');
            if (!empty($response['custom']['subscriptionId']) || !empty($response['custom']['inputval1'])) {
                return $this->redirectToRoute(
                    'frontend.novalnet.subscription.orders.detail',
                    ['aboId' => !empty($response['custom']['subscriptionId']) ? $response['custom']['subscriptionId'] : $response['custom']['inputval1']]
                );
            }
            return $this->redirectToRoute('frontend.novalnet.subscription.orders');
        }
    }
}
