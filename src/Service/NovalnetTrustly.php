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

class NovalnetTrustly extends AbstractNovalnetPaymentHandler
{
    /** @var string */
    protected $novalnetPaymentType = 'TRUSTLY';

    /** @var string */
    protected $paymentCode         = 'novalnettrustly';

    /** @var string */
    protected $paymentHandler      = NovalnetTrustly::class;

    /** @var int */
    protected $position            = -1001;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Trustly',
            'description' => 'Sie werden zu Trustly weitergeleitet. Um eine erfolgreiche Zahlung zu gewährleisten, darf die Seite nicht geschlossen oder neu geladen werden, bis die Bezahlung abgeschlossen ist.',
        ],
        'en-GB' => [
            'name'        => 'Trustly',
            'description' => 'You will be redirected to Trustly. Please don’t close or refresh the browser until the payment is completed.',
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
            $redirectUrl = $transaction->getReturnUrl();
            $transactionDetails = $this->transactionHelper->getOrderTransactionDetails($transaction->getOrderTransactionId(), $context);
            $response = $this->handlePaymentProcess($context, $transactionDetails, $request, $redirectUrl);
            if (!empty($response['result']['redirect_url'])) {
                $this->helper->setSession('novalnetTxnSecret', $response['transaction']['txn_secret']);
                $redirectUrl = $response['result']['redirect_url'];
            }
        } catch (\Exception $e) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }

        if ($response['result']['status_code'] != 100) {
            $this->helper->setSession('novalnetErrorMessage', $response['result']['status_text']);
            throw PaymentException::customerCanceled($transaction->getOrderTransactionId(), $response['result']['status_text']);
        }

        // Redirect to external gateway
        return new RedirectResponse($redirectUrl);
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false;
    }

    /**
     * This method will be called after the redirect, if the `pay` method returns a RedirectResponse.
     * If the `pay` method is not returning a RedirectResponse, this method will not and *cannot* be called.
     */
    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        try {
            $transactionDetails = $this->transactionHelper->getOrderTransactionDetails($transaction->getOrderTransactionId(), $context);
            $response = $this->handleRedirectResponse($request, $context, $transactionDetails);
            $this->checkTransactionStatus($transactionDetails, $response, $context, $transaction);
        } catch (\Exception $e) {
            throw PaymentException::asyncFinalizeInterrupted($transaction->getOrderTransactionId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }
    }

    /**
     * Prepare payment related parameters
     *
     * @param Mixed|null $transaction
     * @param Request|null $data
     * @param array $parameters
     * @param string $salesChannelId
     * @param string $returnUrl
     *
     * @return void
     */
    public function generatePaymentParameters($transaction, Request $data = null, array &$parameters, string $salesChannelId, string $returnUrl): void
    {
        $this->redirectParameters($returnUrl, $parameters);
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
