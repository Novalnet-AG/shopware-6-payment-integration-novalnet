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
 * If you wish to customize Novalnet payment extension for your needs, please contact technic@novalnet.de for more information.
 *
 * @category    Novalnet
 * @package     NovalnetPayment
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */
declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Controller\Administration;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route(defaults={"_routeScope"={"api"}})
 */
class AdminController extends AbstractController
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
     * @var NovalnetOrderTransactionHelper
     */
    private $transactionHelper;
   
    /**
     * Constructs a `AdminController`
     *
     * @param NovalnetHelper $helper
     * @param TranslatorInterface $translator
     * @param NovalnetOrderTransactionHelper $transactionHelper

    */
    public function __construct(
        NovalnetHelper $helper,
        TranslatorInterface $translator,
        NovalnetOrderTransactionHelper $transactionHelper
    ) {
        $this->helper        = $helper;
        $this->translator    = $translator;
        $this->transactionHelper = $transactionHelper;
    }
    
    /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/validate-api-credentials",
     *     name="api.action.noval.payment.compatibility.validate.api.credentials",
     *     methods={"POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/validate-api-credentials",
     *     name="api.action.noval.payment.validate.api.credentials",
     *     methods={"POST"}
     * )
     */
    public function validateApiCredentials(Request $request, Context $context): JsonResponse
    {
        $data = [];

        if ($request->get('clientId') && $request->get('accessKey')) {
            $parameters['merchant']       = [
                'signature' => $request->get('clientId'),
            ];
            $parameters['custom']       = [
                'lang'      => $this->helper->getLocaleCodeFromContext($context),
            ];

            $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('merchant_details'), $request->get('accessKey'));
            if (! empty($response['result']['status']) && $response['result']['status'] == 'SUCCESS') {
                if (!empty($response['merchant']['tariff'])) {
                    $tariffs = [];
                    foreach ($response['merchant']['tariff'] as $key => $values) {
                        $tariffs[] = [
                            'id' => $key,
                            'name' => $values['name'],
                        ];
                    }
                    sort($tariffs);
                    $data = ['serverResponse' => $response, 'tariffResponse' => $tariffs];
                }
            } else {
                $data = ['serverResponse' => $response];
            }
        }
        return new JsonResponse($data);
    }
    
    /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/transaction-amount",
     *     name="api.action.noval.payment.compatibility.transaction.amount",
     *     methods={"POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/transaction-amount",
     *     name="api.action.noval.payment.transaction.amount",
     *     methods={"POST"}
     * )
     */
    public function getNovalnetData(Request $request, Context $context): JsonResponse
    {
        $novalnetTransactionData = [];
        if (!empty($request->get('orderNumber'))) {
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('orderNumber'), $context);
            if (!empty($transactionData)) {
                $transactionData->setPaymentType($this->helper->getUpdatedPaymentType($transactionData->getpaymentType()));
                $novalnetTransactionData = ['data' => $transactionData ];
            }
        }
        return new JsonResponse($novalnetTransactionData);
    }

     /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/refund-amount",
     *     name="api.action.noval.payment.compatibility.refund.amount",
     *     methods={"POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/refund-amount",
     *     name="api.action.noval.payment.refund.amount",
     *     methods={"POST"}
     * )
     */
     
    public function refundAmount(Request $request, Context $context): JsonResponse
    {
        $refundResponse =[];
        if (!empty($request->get('orderNumber'))) {
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('orderNumber'), $context);
            $refundAmount = (int) round($request->get('refundAmount'));
            
            if (!empty($transactionData)) {
                $orderReference = $this->transactionHelper->getOrder($request->request->get('orderNumber'), $context);
                $localeCode = $this->helper->getLocaleFromOrder($orderReference->getOrderId());
                
                if ((int) $transactionData->getRefundedAmount() >= (int) $transactionData->getAmount()) {
                    return new JsonResponse(['result' => ['status_text' => $this->translator->trans('NovalnetPayment.text.refundAlreadyExists', [], null, $localeCode)]]);
                }
                
                if ($refundAmount > (int) $transactionData->getAmount()) {
                    return new JsonResponse(['result' => ['status_text' => $this->translator->trans('NovalnetPayment.text.invalidRefundAmount', [], null, $localeCode)]]);
                }
                
                $orderReference = $this->transactionHelper->getOrder($request->get('orderNumber'), $context);
                
                if (!empty($orderReference)) {
                    $refundResponse =  $this->transactionHelper->refundTransaction($transactionData, $orderReference, $context, $refundAmount, $request);
                }
            }
        }
        return new JsonResponse($refundResponse);
    }
    
     /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/manage-payment",
     *     name="api.action.noval.payment.compatibility.manage.payment",
     *     methods={"POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/manage-payment",
     *     name="api.action.noval.payment.manage.payment",
     *     methods={"POST"}
     * )
     */
     
    public function managePayment(Request $request, Context $context): JsonResponse
    {
        $managePayment = [];
        if (!empty($request->get('orderNumber'))&& !empty($request->get('status'))) {
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('orderNumber'), $context);
            
            if (!empty($transactionData)) {
                $orderReference = $this->transactionHelper->getOrder($request->get('orderNumber'), $context);
                if (!empty($orderReference)) {
                    $status = ($request->get('status')) =='100' ? 'transaction_capture' : 'transaction_cancel';
                    $managePayment =  $this->transactionHelper->manageTransaction($transactionData, $orderReference, $context, $status);
                }
            }
        }
        return new JsonResponse($managePayment);
    }
    
     /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/instalment-cancel",
     *     name="api.action.noval.payment.compatibility.instalment.cancel",
     *     methods={"POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/instalment-cancel",
     *     name="api.action.noval.payment.instalment.cancel",
     *     methods={"POST"}
     * )
     */
    public function instalmentCancel(Request $request, Context $context): JsonResponse
    {
        $instalmentCancel = [];
        
        if (!empty($request->get('orderNumber'))  && !empty($request->get('cancelType'))) {
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('orderNumber'), $context);
            
            if (!empty($transactionData)) {
                $orderReference = $this->transactionHelper->getOrder($request->get('orderNumber'), $context);
                if (!empty($orderReference)) {
                    $instalmentCancel =  $this->transactionHelper->instalmentCancelType($transactionData, $orderReference, $context, $request);
                }
            }
        }
        return new JsonResponse($instalmentCancel);
    }
    
    /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/book-amount",
     *     name="api.action.noval.payment.compatibility.book.amount",
     *     methods={"POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/book-amount",
     *     name="api.action.noval.payment.book.amount",
     *     methods={"POST"}
     * )
     */
    public function bookAmount(Request $request, Context $context): JsonResponse
    {
        $bookResponse =[];
        
        if (!empty($request->get('orderNumber'))) {
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('orderNumber'), $context);
            $bookAmount = (int) round($request->get('bookAmount'));
          
            if (!empty($transactionData)) {
                $orderEntity = $this->transactionHelper->getOrderEntity($request->get('orderNumber'), $context);
                
                if (!empty($orderEntity)) {
                    $bookResponse =  $this->transactionHelper->bookOrderAmount($transactionData, $orderEntity, $context, $bookAmount);
                }
            }
        }
        return new JsonResponse($bookResponse);
    }
    
    /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/novalnet-paymentmethod",
     *     name="api.action.noval.payment.compatibility.novalnet.paymentmethod",
     *     methods={"POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/novalnet-paymentmethod",
     *     name="api.action.noval.payment.novalnet.paymentmethod",
     *     methods={"POST"}
     * )
     */
    public function getNovalnetPaymentMethodName(Request $request, Context $context): JsonResponse
    {
        if (!empty($request->get('orderNumber'))) {
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('orderNumber'), $context);
            
            if (!empty($transactionData)) {
                $paymentName = $transactionData->getAdditionalDetails();
                $paymentmethod = $this->helper->unserializeData($paymentName);
                if (isset($paymentmethod['payment_name']) && !empty($paymentmethod['payment_name'])) {
                    return new JsonResponse(['paymentName' => $paymentmethod['payment_name']]);
                }
            }
        }
        
        return new JsonResponse([]);
    }
    
    /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/webhook-url-configure",
     *     name="api.action.noval.payment.compatibility.webhook.url.configure",
     *     methods={"GET","POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/webhook-url-configure",
     *     name="api.action.noval.payment.webhook.url.configure",
     *     methods={"GET","POST"}
     * )
     */
    public function configureWebhook(Request $request, Context $context): JsonResponse
    {
        if (!empty($request->get('url')) && !empty($request->get('productActivationKey')) && !empty($request->get('paymentAccessKey'))) {
            $parameters = [
                'merchant' => [
                    'signature' => $request->get('productActivationKey'),
                ],
                'webhook'  => [
                    'url' => $request->get('url'),
                ],
                'custom'   => [
                    'lang' => $this->helper->getLocaleCodeFromContext($context),
                ],
            ];
                
            $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('webhook_configure'), $request->get('paymentAccessKey'));
            return new JsonResponse($response);
        }
        return new JsonResponse(['result' => '']);
    }
    /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/novalnet-Payment",
     *     name="api.action.noval.payment.compatibility.novalnet.Payment",
     *     methods={"GET","POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/novalnet-payment",
     *     name="api.action.noval.payment.novalnet.payment",
     *     methods={"GET","POST"}
     * )
     */
    public function novalnetPayment(Request $request, Context $context): JsonResponse
    {
        if (!empty($request->get('shippingaddress')) && !empty($request->get('billingaddress')) && !empty($request->get('amount')) && !empty($request->get('currency')) && !empty($request->get('customer'))) {
            $customer = $request->get('customer');
            $paymentSettings = $this->helper->getNovalnetPaymentSettings($customer['salesChannel']['id']);
            
            $themename = $this->helper->getThemeName($customer['salesChannel']['id'], $context);
            
            // Built merchant parameters.
            $parameters['merchant'] = [
                'signature' => str_replace(' ', '', $paymentSettings['NovalnetPayment.settings.clientId']),
                'tariff'    => $paymentSettings['NovalnetPayment.settings.tariff']
            ];
            
            if (!empty($customer)) {
                $parameters['customer'] = $this->customerDetails($customer, $request->get('shippingaddress'), $request->get('billingaddress'));
            }
            $parameters['transaction'] = [
                'amount'           => $request->get('amount') * 100,
                'currency'         => $request->get('currency'),
                'system_ip'        => $_SERVER['SERVER_ADDR'],
                'system_name'      => 'shopware6',
                'system_version'   => $this->helper->getVersionInfo($context) . '-NNT' .$themename,
            ];
            $parameters['hosted_page'] = [
                'hide_blocks'      => ['ADDRESS_FORM', 'SHOP_INFO', 'LANGUAGE_MENU', 'TARIFF','HEADER'],
                'skip_pages'       => ['CONFIRMATION_PAGE', 'SUCCESS_PAGE', 'PAYMENT_PAGE'],
                'type' => 'PAYMENTFORM',
                'display_payments' => ['INVOICE', 'PREPAYMENT', 'CASHPAYMENT', 'MULTIBANCO']
            ];
        
            $parameters['custom'] = [
                'lang'      => $this->helper->getLocaleCodeFromContext($context),
            ];

            $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('seamless_payment'), str_replace(' ', '', $paymentSettings['NovalnetPayment.settings.accessKey']));
            return new JsonResponse($response);
        }
      
        return new JsonResponse(['result' => '']);
    }
    
    /**
     * Form customer details.
     *
     * @param array $customer
     * @param array $shipping
     * @param array $billing
     *
     * @return array
     */
    public function customerDetails($customer, $shipping, $billing) : array
    {
        $data = [];
        $data =[
            'first_name' => $customer['firstName'],
            'last_name' => $customer['lastName'],
            'email' => $customer['email'],
            'customer_no' => $customer['customerNumber']
        ];
       
        $data['customer_ip'] = $this->helper->getIp();
        if (empty($data['customer_ip'])) {
            $data['customer_ip'] = $customer['remoteAddress'];
        }
        if (!empty($shipping)) {
            $shippingaddress = [
                'street' => $shipping['street'].' '.$shipping['additionalAddressLine1'].' '.$shipping['additionalAddressLine2'],
                'city' => $shipping['city'],
                'zip' => $shipping['zipcode'],
                'country_code' => !empty($shipping['country']['iso']) ? $shipping['country']['iso'] : $this->helper->getCountry($billing['countryId'])
            ];
            if (!empty($shipping['company'])) {
                $shippingaddress['company'] = $shipping['company'];
            }
        }
        
        if (!empty($billing)) {
            $billingaddress = [
                'street' => $billing['street'].' '.$billing['additionalAddressLine1'].' '.$billing['additionalAddressLine2'],
                'city' => $billing['city'],
                'zip' => $billing['zipcode'],
                'country_code' => !empty($billing['country']['iso']) ? $billing['country']['iso'] : $this->helper->getCountry($billing['countryId'])
            ];
            if (!empty($billing['company'])) {
                $billingaddress['company'] = $billing['company'];
            }
        }
        if ($shippingaddress == $billingaddress) {
             $data ['shipping'] ['same_as_billing'] = 1;
        } else {
            $data ['shipping'] = $shippingaddress;
        }
        $data ['billing'] = $billingaddress;
        return $data;
    }
    
    /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/novalnet-select-payment",
     *     name="api.action.noval.payment.compatibility.novalnet.select.payment",
     *     methods={"GET","POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/novalnet-select-payment",
     *     name="api.action.noval.payment.novalnet.select.payment",
     *     methods={"GET","POST"}
     * )
     */
    public function novalnetSelectPayment(Request $request, Context $context)
    {
        if (!empty($request->get('paymentSelected'))) {
            $transactionData = $this->transactionHelper->orderBackend((string) $request->get('paymentSelected'), $context);
        }
    }
    
    /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/payment-value-data",
     *     name="api.action.noval.payment.compatibility.payment.value.data",
     *     methods={"GET","POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/payment-value-data",
     *     name="api.action.noval.payment.payment.value.data",
     *     methods={"GET","POST"}
     * )
     */
    public function paymentValueData(Request $request, Context $context): JsonResponse
    {
        if (!empty($request->get('value')) && !empty($request->get('customer'))) {
            $paymentDetails = $this->helper->unserializeData($request->get('value'));
            $result = $this->helper->orderBackendPaymentData($paymentDetails, $request->get('customer'), $context);
            return new JsonResponse(['success' => $result ]);
        }
        
        return new JsonResponse([]);
    }
}
