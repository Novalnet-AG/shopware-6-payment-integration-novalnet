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

class NovalnetMbway extends AbstractNovalnetPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /** @var string */
    protected $novalnetPaymentType = 'MBWAY';

    /** @var string */
    protected $paymentCode         = 'novalnetmbway';

    /** @var string */
    protected $paymentHandler      = NovalnetMbway::class;

    /** @var int */
    protected $position            = -1027;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'MB Way',
            'description' => 'Nach Abschluss Ihrer Bestellung wird eine Zahlungsaufforderung an Ihr Mobilgerät gesendet. Sie können die PIN eingeben und die Zahlung autorisieren.',
        ],
        'en-GB' => [
            'name'        => 'MB Way',
            'description' => 'After completing your order, a payment request notification will be sent to your mobile device. You can enter the PIN and authorises the payment.',
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
     * Prepare payment related parameters
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $data
     * @param array $parameters
     * @param string $salesChannelId
     */
    public function generatePaymentParameters($transaction, RequestDataBag $data = null, array &$parameters, string $salesChannelId): void
    {
        $sessionData = [];

        if (!empty($data->get($this->paymentCode . 'FormData'))) {
            $sessionData = $data->get($this->paymentCode . 'FormData')->all();
        } elseif (!empty($_REQUEST[$this->paymentCode . 'FormData'])) {
            $sessionData = $_REQUEST[$this->paymentCode . 'FormData'];
        }
                
        if (! empty($sessionData['mbway_mobile_no']) && !empty($sessionData['mbway_mobile_dialcode'])) {
            $parameters['customer']['mobile'] = $sessionData['mbway_mobile_dialcode'].$sessionData['mbway_mobile_no'];
        }
        $this->redirectParameters($transaction, $parameters);
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