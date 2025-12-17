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
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(defaults: ['_routeScope' => ['api']])]
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
     * @var EntityRepository
     */
    private $tokenRepository;

    public function __construct(
        NovalnetHelper $helper,
        TranslatorInterface $translator,
        NovalnetOrderTransactionHelper $transactionHelper,
        EntityRepository $tokenRepository
    ) {
        $this->helper        = $helper;
        $this->translator    = $translator;
        $this->transactionHelper = $transactionHelper;
        $this->tokenRepository   = $tokenRepository;
    }

    #[Route(path: '/api/_action/novalnet-payment/validate-api-credentials', name: 'api.action.noval.payment.validate.api.credentials', methods: ['POST'])]
    public function validateApiCredentials(Request $request, Context $context): JsonResponse
    {
        $data = [];
        if ($request->request->get('clientId') && $request->request->get('accessKey')) {
            $parameters['merchant']       = [
                'signature' => $request->request->get('clientId'),
            ];
            $parameters['custom']       = [
                'lang'      => $this->helper->getLocaleCodeFromContext($context),
            ];

            $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('merchant_details'), $request->request->get('accessKey'));

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

    #[Route(path: '/api/_action/novalnet-payment/webhook-url-configure', name: 'api.action.noval.payment.webhook.url.configure', methods: ['POST'])]
    public function configureWebhook(Request $request, Context $context): JsonResponse
    {
        if (!empty($request->request->get('url')) && !empty($request->request->get('productActivationKey')) && !empty($request->request->get('paymentAccessKey'))) {
            $parameters = [
                    'merchant' => [
                        'signature' => $request->request->get('productActivationKey'),
                    ],
                    'webhook'  => [
                        'url' => $request->request->get('url'),
                    ],
                    'custom'   => [
                        'lang' => $this->helper->getLocaleCodeFromContext($context),
                    ],
                ];

            $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint('webhook_configure'), $request->request->get('paymentAccessKey'));
            return new JsonResponse($response);
        }
        return new JsonResponse(['result' => '']);
    }

    #[Route(path: '/api/_action/novalnet-payment/refund-payment', name: 'api.action.noval.payment.refund.payment', methods: ['POST'])]
    public function refundAmount(Request $request, Context $context): JsonResponse
    {
        $refundedAmount = (int) round($request->request->get('refundAmount'));
        $response       = [];

        if ($request->request->get('orderNumber')) {
            // Fetch novalnet transaction data
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->request->get('orderNumber'), $context);
            if (!is_null($transactionData)) {
                $orderReference = $this->transactionHelper->getOrder($request->request->get('orderNumber'), $context);

                $localeCode = $this->helper->getLocaleFromOrder($orderReference->getOrderId());

                if ((int) $transactionData->getAmount() <= (int) $transactionData->getRefundedAmount()) {
                    return new JsonResponse(['result' => ['status_text' => $this->translator->trans('NovalnetPayment.text.refundAlreadyExists', [], null, $localeCode)]]);
                }

                if ((int) $transactionData->getAmount() < $refundedAmount) {
                    return new JsonResponse(['result' => ['status_text' => $this->translator->trans('NovalnetPayment.text.invalidRefundAmount', [], null, $localeCode)]]);
                }

                if (!is_null($orderReference)) {
                    $response =  $this->transactionHelper->refundTransaction($transactionData, $orderReference, $context, (int) $refundedAmount, $request);
                }
            }
        }
        return new JsonResponse($response);
    }

    #[Route(path: '/api/_action/novalnet-payment/instalment-cancel', name: 'api.action.noval.payment.instalment.cancel', methods: ['POST'])]
    public function instalmentCancel(Request $request, Context $context): JsonResponse
    {
        $response = [];
        if ($request->request->get('orderNumber')) {
            // Fetch novalnet transaction data
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->request->get('orderNumber'), $context);
            if (!is_null($transactionData)) {
                $orderReference  = $this->transactionHelper->getOrder($request->request->get('orderNumber'), $context);

                // create payment paramaters for request
                if (!is_null($orderReference)) {
                    $response =  $this->transactionHelper->cancelInstalmentPayment($transactionData, $orderReference, $context, $request);
                }
            }
        }
        return new JsonResponse($response);
    }

    #[Route(path: '/api/_action/novalnet-payment/book-amount', name: 'api.action.noval.payment.book.amount', methods: ['POST'])]
    public function bookAmount(Request $request, Context $context): JsonResponse
    {
        $response = [];
        $bookedAmount = (int) round($request->request->get('bookedAmount'));

        if ($request->request->get('orderId') && $bookedAmount) {
            $orderEntity = $this->helper->getOrderCriteria($request->request->get('orderId'), $context);
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $orderEntity->getOrderNumber(), $context);

            if (!is_null($transactionData) && !is_null($orderEntity)) {
                // create payment paramaters for request
                $response =  $this->transactionHelper->bookOrderAmount($bookedAmount, $transactionData, $orderEntity, $context);
            }
        }
        return new JsonResponse($response);
    }

    #[Route(path: '/api/_action/novalnet-payment/manage-payment', name: 'api.action.noval.payment.manage.payment', methods: ['POST'])]
    public function managePaymentTransaction(Request $request, Context $context): JsonResponse
    {
        $response = [];
        if ($request->request->get('status') && $request->request->get('orderNumber')) {
            $status = '';
            // Fetch novalnet transaction data
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->request->get('orderNumber'), $context);

            if (!is_null($transactionData)) {
                $orderReference  = $this->transactionHelper->getOrder($request->request->get('orderNumber'), $context);
                if (!is_null($orderReference)) {
                    $status = ($request->request->get('status') == '100') ? 'transaction_capture' : 'transaction_cancel';
                    $response =  $this->transactionHelper->manageTransaction($transactionData, $orderReference, $context, $status);
                }
            }
        }
        return new JsonResponse($response);
    }

    #[Route(path: '/api/_action/novalnet-payment/transaction-amount', name: 'api.action.noval.payment.transaction.amount', methods: ['POST'])]
    public function getNovalnetData(Request $request, Context $context): JsonResponse
    {
        $result = [];
        if (!empty($request->request->get('orderNumber'))) {
            // Fetch novalnet transaction data
            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->request->get('orderNumber'), $context);
            $result = ['data' => $transactionData];
        }

        return new JsonResponse($result);
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

        if (!empty($data['tid'])) {
            $criteria->addFilter(
                new EqualsFilter('novalnet_payment_token.tid', $data['tid'])
            );
        }
        /** @var NovalnetPaymentTokenEntity|null */
        $result = $this->tokenRepository->search($criteria, $context)->first();

        return $result;
    }
}
