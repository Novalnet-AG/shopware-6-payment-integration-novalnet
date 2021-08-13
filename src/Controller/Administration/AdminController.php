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
 * @category 	Novalnet
 * @package 	NovalnetPayment
 * @copyright 	Copyright (c) Novalnet
 * @license 	https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */
declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Controller\Administration;

use Shopware\Core\Framework\Context;
use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @RouteScope(scopes={"api"})
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

    public function __construct(
        NovalnetHelper $helper,
        TranslatorInterface $translator,
        NovalnetOrderTransactionHelper $transactionHelper
    ) {
        $this->helper		                = $helper;
        $this->translator	                = $translator;
        $this->transactionHelper            = $transactionHelper;
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
    public function validateApiCredentials(Request $request): JsonResponse
    {
        $data = [];

        if ($request->get('clientId') && $request->get('accessKey')) {
            $parameters['merchant']       = [
                'signature' => $request->get('clientId'),
            ];
            $parameters['custom']       = [
                'lang'      => $this->transactionHelper->getAdminLanguage($request),
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
     *     "/api/v{version}/_action/novalnet-payment/refund-payment",
     *     name="api.action.noval.payment.compatibility.refund.payment",
     *     methods={"GET","POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/refund-payment",
     *     name="api.action.noval.payment.refund.payment",
     *     methods={"GET","POST"}
     * )
     */
    public function refundAmount(Request $request, Context $context): JsonResponse
    {
        $refundedAmount = (int) $request->get('refundAmount');
        $response           = [];
        if ($request->get('orderNumber')) {

            // Fetch novalnet transaction data
            $transactionData	= $this->transactionHelper->fetchNovalnetTransactionData($request->get('orderNumber'), $context);
            if (!is_null($transactionData)) {
                $orderReference		= $this->transactionHelper->getOrder($request->get('orderNumber'), $context);

                if ((int) $transactionData->getAmount() <= (int) $transactionData->getRefundedAmount()) {
                    return new JsonResponse(['result' => ['status_text' => $this->translator->trans('NovalnetPayment.text.refundAlreadyExists')]]);
                }
                if ((int) $transactionData->getAmount() < $refundedAmount) {
                    return new JsonResponse(['result' => ['status_text' => $this->translator->trans('NovalnetPayment.text.invalidRefundAmount')]]);
                }

                if (!is_null($orderReference)) {
                    $response =  $this->transactionHelper->refundTransaction($transactionData, $orderReference, $context, (int) $refundedAmount, $request);
                }
            }
        }
        return new JsonResponse($response);
    }

    /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/manage-payment",
     *     name="api.action.noval.payment.compatibility.manage.payment",
     *     methods={"GET","POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/manage-payment",
     *     name="api.action.noval.payment.manage.payment",
     *     methods={"GET","POST"}
     * )
     */
    public function managePaymentTransaction(Request $request, Context $context): JsonResponse
    {
        $response           = [];
        if ($request->get('status') && $request->get('orderNumber')) {

            // Fetch novalnet transaction data
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData($request->get('orderNumber'), $context);

            if (!is_null($transactionData)) {
                $orderReference	 = $this->transactionHelper->getOrder($request->get('orderNumber'), $context);
                if (!is_null($orderReference)) {
                    $status = ($request->get('status') == '100') ? 'transaction_capture' : 'transaction_cancel';
                    $response =  $this->transactionHelper->manageTransaction($transactionData, $orderReference, $context, $status, $request);
                }
            }
        }
        return new JsonResponse($response);
    }

    /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/transaction-amount",
     *     name="api.action.noval.payment.compatibility.transaction.amount",
     *     methods={"GET","POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/transaction-amount",
     *     name="api.action.noval.payment.transaction.amount",
     *     methods={"GET","POST"}
     * )
     */
    public function getNovalnetData(Request $request, Context $context): JsonResponse
    {
        $result = [];
        if (!empty($request->get('orderNumber'))) {

            // Fetch novalnet transaction data
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData($request->get('orderNumber'), $context);
            $result = ['data' => $transactionData];
        }
        
        return new JsonResponse($result);
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
                        'lang' => $this->transactionHelper->getAdminLanguage($request),
                    ],
                ];

            $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('webhook_configure'), $request->get('paymentAccessKey'));
            return new JsonResponse($response);
        }
        return new JsonResponse(['result' => '']);
    }
}
