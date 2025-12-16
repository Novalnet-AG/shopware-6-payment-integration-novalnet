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

class NovalnetCreditCard extends AbstractNovalnetPaymentHandler
{
    protected $novalnetPaymentType = 'CREDITCARD';

    /** @var string */
    protected $paymentCode = 'novalnetcreditcard';

    /** @var string */
    protected $paymentHandler = NovalnetCreditCard::class;

    /** @var array */
    protected $translations = [
        'de-DE' => [
            'name'        => 'Kredit- / Debitkarte',
            'description' => 'Ihre Karte wird nach Bestellabschluss sofort belastet',
        ],
        'en-GB' => [
            'name'        => 'Credit/Debit Cards',
            'description' => 'Your credit/debit card will be charged immediately after the order is completed',
        ],
    ];

    /** @var int */
    protected $position = -1023;

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
        try {
            $transactionDetails = $this->transactionHelper->getOrderTransactionDetails($transaction->getOrderTransactionId(), $context);
            $response = $this->handlePaymentProcess($context, $transactionDetails, $request, $transaction->getReturnUrl());
        } catch (\Exception $e) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), 'An error occurred during the communication with external payment gateway'.PHP_EOL.$e->getMessage());
        }

        if (!empty($response['result']['redirect_url'])) {
            $this->helper->setSession('novalnetTxnSecret', $response['transaction']['txn_secret']);

            // Redirect to external gateway
            return new RedirectResponse($response['result']['redirect_url']);
        }

        $this->helper->setSession($this->paymentCode . 'Response', $response);
        return new RedirectResponse($transaction->getreturnUrl(), 302, []);
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return $type === PaymentHandlerType::RECURRING;
    }

    /**
     * This method will be called after the redirect, if the `pay` method returns a RedirectResponse.
     * If the `pay` method is not returning a RedirectResponse, this method will not and *cannot* be called.
     */
    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        try {
            $response = $this->helper->getSession($this->paymentCode . 'Response');
            $transactionDetails = $this->transactionHelper->getOrderTransactionDetails($transaction->getOrderTransactionId(), $context);
            if (empty($response)) {
                $response = $this->handleRedirectResponse($request, $context, $transactionDetails);
            }
            $this->checkTransactionStatus($transactionDetails, $response, $context, $transaction);
        } catch (\Exception $e) {
            throw PaymentException::asyncFinalizeInterrupted($transaction->getOrderTransactionId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }
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

        if (!empty($data->get('novalnetcreditcardFormData'))) {
            $sessionData = $data->get('novalnetcreditcardFormData');
        } elseif (!empty($_REQUEST['novalnetcreditcardFormData'])) {
            $sessionData = $_REQUEST['novalnetcreditcardFormData'];
        }

        if (!empty($data->get('isSubscriptionOrder'))) {
            $this->helper->setSession('isSubscriptionOrder', true);
        };

        $this->setPaymentToken('novalnetcreditcard', $data, $sessionData, $parameters);

        if (! empty($sessionData) && empty($parameters ['transaction'] ['payment_data'] ['token'])) {
            foreach ([
                'panhash' => 'pan_hash',
                'uniqueid' => 'unique_id',
            ] as $key => $value) {
                if (! empty($sessionData[$key])) {
                    $parameters['transaction'] ['payment_data'][$value] = $sessionData[$key];
                }
            }
        }

        $enforce3D  = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.creditcardEnforcecc3D', $salesChannelId);

        if (!empty($sessionData['doRedirect'])) {
            if (!empty($enforce3D)) {
                $parameters['transaction']['enforce_3d'] = 1;
            }
            $this->redirectParameters($returnUrl, $parameters);
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
