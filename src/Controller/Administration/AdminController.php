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

use Novalnet\NovalnetPayment\Content\PaymentToken\NovalnetPaymentTokenEntity;
use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
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

    /**
     * @var EntityRepositoryInterface
     */
    private $tokenRepository;

    public function __construct(
        NovalnetHelper $helper,
        TranslatorInterface $translator,
        NovalnetOrderTransactionHelper $transactionHelper,
        EntityRepositoryInterface $tokenRepository
    ) {
        $this->helper        = $helper;
        $this->translator    = $translator;
        $this->transactionHelper = $transactionHelper;
        $this->tokenRepository   = $tokenRepository;
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
        $refundedAmount = (int) round($request->get('refundAmount'));
        $response       = [];
        if ($request->get('orderNumber')) {

            // Fetch novalnet transaction data
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('orderNumber'), $context);
            if (!is_null($transactionData)) {
                $orderReference = $this->transactionHelper->getOrder($request->get('orderNumber'), $context);

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
     *     "/api/v{version}/_action/novalnet-payment/instalment-cancel",
     *     name="api.action.noval.payment.compatibility.instalment.cancel",
     *     methods={"GET","POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/instalment-cancel",
     *     name="api.action.noval.payment.instalment.cancel",
     *     methods={"GET","POST"}
     * )
     */
    public function instalmentCancel(Request $request, Context $context): JsonResponse
    {
        $response = [];
        if ($request->get('orderNumber'))
        {
            // Fetch novalnet transaction data
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('orderNumber'), $context);
            if (!is_null($transactionData)) {
                $orderReference  = $this->transactionHelper->getOrder($request->get('orderNumber'), $context);

                // create payment paramaters for request
                if (!is_null($orderReference)) {
                    $response =  $this->transactionHelper->cancelInstalmentPayment($transactionData, $orderReference, $context, $request);
                }
            }
        }
        return new JsonResponse($response);
    }

    /**
     * @Route(
     *     "/api/v{version}/_action/novalnet-payment/book-amount",
     *     name="api.action.noval.payment.compatibility.book.amount",
     *     methods={"GET","POST"}
     * )
     *
     * @Route(
     *     "/api/_action/novalnet-payment/book-amount",
     *     name="api.action.noval.payment.book.amount",
     *     methods={"GET","POST"}
     * )
     */
    public function bookAmount(Request $request, Context $context): JsonResponse
    {
        $response = [];
        $bookedAmount = (int) round($request->get('bookedAmount'));

        if ($request->get('orderNumber') && $bookedAmount)
        {
            // Fetch novalnet transaction data
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('orderNumber'), $context);
            if (!is_null($transactionData)) {
                $orderEntity = $this->transactionHelper->getOrderEntity($request->get('orderNumber'), $context);

                // create payment paramaters for request
                if (!is_null($orderEntity)) {
                    $response =  $this->transactionHelper->bookOrderAmount($bookedAmount, $transactionData, $orderEntity, $context);
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

            $status = '';
            // Fetch novalnet transaction data
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('orderNumber'), $context);

            if (!is_null($transactionData)) {
                $orderReference  = $this->transactionHelper->getOrder($request->get('orderNumber'), $context);
                if (!is_null($orderReference)) {
                    $status = ($request->get('status') == '100') ? 'transaction_capture' : 'transaction_cancel';
                    $response =  $this->transactionHelper->manageTransaction($transactionData, $orderReference, $context, $status);
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
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('orderNumber'), $context);
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
                        'lang' => $this->helper->getLocaleCodeFromContext($context),
                    ],
                ];

            $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('webhook_configure'), $request->get('paymentAccessKey'));
            return new JsonResponse($response);
        }
        return new JsonResponse(['result' => '']);
    }

    /**
     * Get existing paymennt token details
     *
     * @param Context $context
     * @param array $data
     *
     * @return NovalnetPaymentTokenEntity|null
     */
    public function getExistingPaymentToken(Context $context, array $data): ?NovalnetPaymentTokenEntity
    {
        $criteria = new Criteria();

        if(! empty($data['tid'])) {
            $criteria->addFilter(
                new EqualsFilter('novalnet_payment_token.tid', $data['tid'])
            );
        }
        $result = $this->tokenRepository->search($criteria, $context)->first();

        return $result;
    }
}
