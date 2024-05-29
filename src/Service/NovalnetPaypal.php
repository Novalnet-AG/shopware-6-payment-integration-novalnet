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

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class NovalnetPaypal extends AbstractNovalnetPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /** @var string */
    protected $novalnetPaymentType = 'PAYPAL';

    /** @var string */
    protected $paymentCode         = 'novalnetpaypal';

    /** @var string */
    protected $paymentHandler      = NovalnetPaypal::class;

    /** @var int */
    protected $position            = -1009;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'PayPal',
            'description' => 'Sie werden zu PayPal weitergeleitet. Um eine erfolgreiche Zahlung zu gewährleisten, darf die Seite nicht geschlossen oder neu geladen werden, bis die Bezahlung abgeschlossen ist.',
        ],
        'en-GB' => [
            'name'        => 'PayPal',
            'description' => 'You will be redirected to PayPal. Please don’t close or refresh the browser until the payment is completed',
        ],
    ];

    /**
     * The pay function will be called after the customer completed the order.
     * Allows to process the order and store additional information.
     *
     * A redirect to the url will be performed
     *
     * Throw a @see PaymentException::PAYMENT_ASYNC_PROCESS_INTERRUPTED exception if an error ocurres while processing the payment
     * Throw a @see PaymentException::PAYMENT_CUSTOMER_CANCELED_EXTERNAL exception if the customer canceled the payment process on payment provider page
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws PaymentException
     */
    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        // Method that sends the return URL to the external gateway and gets a redirect URL back
        try {
            $redirectUrl = $transaction->getReturnUrl();
            $response = $this->handlePaymentProcess($salesChannelContext, $transaction, $dataBag);
            if (!empty($response['result']['redirect_url'])) {
                $this->helper->setSession('novalnetTxnSecret', $response['transaction']['txn_secret']);
                $redirectUrl = $response['result']['redirect_url'];
            }
        } catch (\Exception $e) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }

        if ($response['result']['status_code'] != 100) {
            $this->helper->setSession('novalnetErrorMessage', $response['result']['status_text']);
            throw PaymentException::customerCanceled($transaction->getOrderTransaction()->getId(), $response['result']['status_text']);
        }

        // Redirect to external gateway
        return new RedirectResponse($redirectUrl);
    }

    /**
     * The finalize function will be called when the user is redirected back to shop from the payment gateway.
     *
     * Throw a @see PaymentException::PAYMENT_ASYNC_FINALIZE_INTERRUPTED exception if an error ocurres while calling an external payment API
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws PaymentException
     */
    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        try {
            $response = $this->handleRedirectResponse($request, $salesChannelContext, $transaction->getOrderTransaction());
            $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
        } catch (\Exception $e) {
            throw PaymentException::asyncFinalizeInterrupted($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }
    }

    /**
     * The recurring function will be called during recurring payment.
     * Allows to process the order and store additional information.
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @return boolean
     */
    public function recurring(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): bool
    {
        $response = $this->handlePaymentProcess($salesChannelContext, $transaction, $dataBag);
        $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction, '1');
        if ($this->validator->isSuccessStatus($response)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Prepare payment related parameters
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $data
     * @param array $parameters
     * @param string $salesChannelId
     */
    public function generatePaymentParameters($transaction, RequestDataBag $data = null, array &$parameters, string $salesChannelId): void
    {
        // add paypal redirect parameters only for initial orders
        if (empty($data->get('isRecurringOrder'))) {
            $this->redirectParameters($transaction, $parameters);
        }

        $this->paypalSheetDetails($transaction, $parameters);

        // add create token param for subscription orders
        if ($data->get('isSubscriptionOrder') == 1) {
            $parameters['transaction']['create_token'] = 1;
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
