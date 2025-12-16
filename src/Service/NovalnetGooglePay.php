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

namespace Novalnet\NovalnetPayment\Service;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class NovalnetGooglePay extends AbstractNovalnetPaymentHandler
{
    /** @var string */
    protected $novalnetPaymentType = 'GOOGLEPAY';

    /** @var string */
    protected $paymentCode         = 'novalnetgooglepay';

    /** @var string */
    protected $paymentHandler      = NovalnetGooglePay::class;

    /** @var int */
    protected $position            = -1021;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Google Pay',
            'description' => 'Der Betrag wird nach erfolgreicher Authentifizierung von Ihrer Karte abgebucht',
        ],
        'en-GB' => [
            'name'        => 'Google Pay',
            'description' => 'Amount will be booked from your card after successful authentication',
        ],
    ];

    /**
     * The pay function will be called after the customer completed the order.
     * Allows to process the order and store additional information.
     *
     * A redirect to the url will be performed
     *
     * Throw a @see PaymentException::PAYMENT_SYNC_PROCESS_INTERRUPTED exception if an error ocurres while processing the payment
     *
     * @param Request $request
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @param Struct $validateStruct
     *
     * @throws PaymentException
     */
    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        // Method that sends the return URL to the external gateway and gets a redirect URL back
        try {
            $transactionDetails = $this->transactionHelper->getOrderTransactionDetails($transaction->getOrderTransactionId(), $context);
            $response = $this->handlePaymentProcess($context, $transactionDetails, $request, $transaction->getReturnUrl());
            $this->helper->setSession('novalnetResponse', $response);
            if (empty($response['result']['redirect_url'])) {
                $this->checkTransactionStatus($transactionDetails, $response, $context, $transaction);
            } elseif (!empty($response['transaction']['txn_secret'])) {
                $this->helper->setSession('novalnetTxnSecret', $response['transaction']['txn_secret']);
            }
        } catch (\Exception $e) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }

        // Redirect to external gateway
        return new RedirectResponse($transaction->getreturnUrl());
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return $type === PaymentHandlerType::RECURRING;
    }

    /**
    * The recurring function will be called during recurring payment.
    * Allows to process the order and store additional information.
    *
    * @param PaymentTransactionStruct $transaction
    * @param Context $context
    *
    *
    */

    public function recurring(PaymentTransactionStruct $transaction, Context $context): void
    {
        $transactionDetails = $this->transactionHelper->getOrderTransactionDetails($transaction->getOrderTransactionId(), $context);
        $orderNumber = $this->helper->getSubParentOrderNumber($transaction->getRecurring()->getSubscriptionId(), $context);
        $request = new Request();
        $request->request->set('isRecurringOrder', true);
        $request->request->set('parentOrderNumber', $orderNumber);
        $response = $this->handlePaymentProcess($context, $transactionDetails, $request, $transaction->getReturnUrl());
        $this->checkTransactionStatus($transactionDetails, $response, $context, $transaction, '1');
    }

    /**
     * Prepare payment related parameters
     *
     * @param mixed $transaction
     * @param Request|null $data
     * @param array $parameters
     * @param string $salesChannelId
     * @param string $returnUrl
     *
     * @return void
     */
    public function generatePaymentParameters($transaction, Request $data = null, array &$parameters, string $salesChannelId, string $returnUrl): void
    {
        $sessionData = [];

        if (!empty($data->get('novalnetgooglepayFormData'))) {
            $sessionData = $data->get('novalnetgooglepayFormData');
        } elseif (!empty($_REQUEST['novalnetgooglepayFormData'])) {
            $sessionData = $_REQUEST['novalnetgooglepayFormData'];
        }

        $enforce3D  = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.googlepayEnforcecc3D', $salesChannelId);

        if ($data->get('isRecurringOrder') == null) {
            if (! empty($sessionData['walletToken'])) {
                $parameters['transaction'] ['payment_data']['wallet_token'] = $sessionData['walletToken'];
            }

            if ($data->get('isSubscriptionOrder') == 1) {
                $parameters['transaction']['create_token'] = 1;
            }

            if (!empty($sessionData['doRedirect']) && $sessionData['doRedirect'] == 'true') {
                $this->helper->getRedirectParams($parameters);
            }
            if (!empty($enforce3D)) {
                $parameters['transaction']['enforce_3d'] = 1;
            }
        }
    }

    /**
     * Prepare transaction comments
     *
     * @param array $response
     * @param Context $context
     * @param string|null $languageId
     *
     * @return string
     */
    public function prepareComments(array $response, Context $context, string $languageId = null): string
    {
        return $this->helper->formBasicComments($response, $context, $languageId);
    }
}
