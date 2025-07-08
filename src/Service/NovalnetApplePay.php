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

use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Struct\Struct;

class NovalnetApplePay extends AbstractNovalnetPaymentHandler
{
    /** @var string */
    protected $novalnetPaymentType = 'APPLEPAY';

    /** @var string */
    protected $paymentCode         = 'novalnetapplepay';

    /** @var string */
    protected $paymentHandler      = NovalnetApplePay::class;

    /** @var int */
    protected $position            = -1022;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Apple Pay',
            'description' => 'Der Betrag wird nach erfolgreicher Authentifizierung von Ihrer Karte abgebucht',
        ],
        'en-GB' => [
            'name'        => 'Apple Pay',
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
             $this->checkTransactionStatus($transactionDetails, $response, $context, $transaction);
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

        if (!empty($data->get('novalnetapplepayFormData'))) {
            $sessionData = $data->get('novalnetapplepayFormData');
        } elseif (!empty($_REQUEST['novalnetapplepayFormData'])) {
            $sessionData = $_REQUEST['novalnetapplepayFormData'];
        }

        if ($data->get('isRecurringOrder') == null) {
            if (! empty($sessionData['walletToken'])) {
                $parameters['transaction'] ['payment_data']['wallet_token'] = $sessionData['walletToken'];
            }
            if ($data->get('isSubscriptionOrder') == 1) {
                $parameters['transaction']['create_token'] = 1;
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
