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
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Struct\Struct;

class NovalnetInvoiceGuarantee extends AbstractNovalnetPaymentHandler
{
    /** @var string */
    protected $novalnetPaymentType = 'GUARANTEED_INVOICE';

    /** @var string */
    protected $paymentCode         = 'novalnetinvoiceguarantee';

    /** @var string */
    protected $paymentHandler      = NovalnetInvoiceGuarantee::class;

    /** @var int */
    protected $position            = -1019;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Rechnung mit Zahlungsgarantie',
            'description' => 'Sie erhalten eine E-Mail mit den Bankdaten von Novalnet, um die Zahlung abzuschließen',
        ],
        'en-GB' => [
            'name'        => 'Invoice with payment guarantee',
            'description' => 'You will receive an e-mail with the Novalnet account details to complete the payment',
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
        try {
            $transactionDetails = $this->transactionHelper->getOrderTransactionDetails($transaction->getOrderTransactionId(), $context);
            $response = $this->handlePaymentProcess($context, $transactionDetails, $request, $transaction->getReturnUrl());
            $this->checkTransactionStatus($transactionDetails, $response, $context, $transaction);
        } catch (\Exception $e) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), 'An error occurred during the communication with external payment gateway'.PHP_EOL.$e->getMessage());
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
     * @param Mixed $transaction
     * @param Request|null $data
     * @param array $parameters
     * @param string $salesChannelId
     * @param string $returnUrl
     * 
     *
     * @return void
     */
    public function generatePaymentParameters($transaction, Request $data = null, array &$parameters, string $salesChannelId, string $returnUrl): void
    {
        $sessionData = [];
		$order = $transaction->getOrder();
        if (!empty($data->get($this->paymentCode . 'FormData'))) {
            $sessionData = $data->get($this->paymentCode . 'FormData');
        } elseif (!empty($_REQUEST[$this->paymentCode . 'FormData'])) {
            $sessionData = $_REQUEST[$this->paymentCode . 'FormData'];
        }

        $invoiceTestMode  = $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.novalnetinvoiceTestMode", $salesChannelId);

        if (!empty($sessionData['doForceInvoicePayment'])) {
            $this->paymentCode = 'novalnetinvoice';
            $parameters['transaction']['payment_type'] = 'INVOICE';
            $parameters['transaction']['test_mode'] = (int) !empty($invoiceTestMode);
            $paymentMethodEntity = $this->helper->getPaymentMethodEntity(NovalnetInvoice::class);
            if (!is_null($paymentMethodEntity)) {
                $transaction->setPaymentMethodId($paymentMethodEntity->getId());
                $this->orderTransactionRepository->upsert([[
                    'id' => $transaction->getId(),
                    'paymentMethodId' => $paymentMethodEntity->getId()
                ]], Context::createDefaultContext());
            }
        }
        if (!empty($sessionData['dob']) || !empty($order->getOrderCustomer()->getCustomer()->getBirthday())) {
            $parameters['customer']['birth_date'] = !empty($sessionData['dob']) ? date('Y-m-d', strtotime($sessionData['dob'])) : date('Y-m-d', strtotime($order->getOrderCustomer()->getCustomer()->getBirthday()->format('Y-m-d')));
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
        return $this->helper->formBankDetails($response, $context, $languageId);
    }
}
