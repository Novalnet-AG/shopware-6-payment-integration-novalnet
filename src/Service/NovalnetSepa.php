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

class NovalnetSepa extends AbstractNovalnetPaymentHandler
{
    /** @var string */
    protected $novalnetPaymentType = 'DIRECT_DEBIT_SEPA';

    /** @var string */
    protected $paymentCode         = 'novalnetsepa';

    /** @var string */
    protected $paymentHandler      = NovalnetSepa::class;

    /** @var int */
    protected $position            = -1024;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'SEPA-Lastschrift',
            'description' => 'Der Betrag wird durch Novalnet von Ihrem Konto abgebucht',
        ],
        'en-GB' => [
            'name'        => 'Direct Debit SEPA',
            'description' => 'The amount will be debited from your account by Novalnet',
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
     * @return void
     */
    public function generatePaymentParameters($transaction, Request $data = null, array &$parameters, string $salesChannelId, string $returnUrl): void
    {
        $sessionData = [];

        if (!empty($data->get('novalnetsepaFormData'))) {
            $sessionData = $data->get('novalnetsepaFormData');
        } elseif (!empty($_REQUEST['novalnetsepaFormData'])) {
            $sessionData = $_REQUEST['novalnetsepaFormData'];
        }

        if (!empty($data->get('isSubscriptionOrder'))) {
            $this->helper->setSession('isSubscriptionOrder', true);
        };

        $this->setPaymentToken('novalnetsepa', $data, $sessionData, $parameters);

        if (!empty($sessionData) && empty($parameters ['transaction'] ['payment_data'] ['token'])) {
            $parameters['transaction'] ['payment_data']['iban'] = $sessionData['accountData'] ? strtoupper(str_replace(' ', '', $sessionData['accountData'])) : '';
            $parameters['transaction'] ['payment_data']['account_holder'] = $parameters['customer']['first_name'].' '.$parameters['customer']['last_name'];

            if (!empty($sessionData['accountBic']) && preg_match("/(?:CH|MC|SM|GB)/", $parameters['transaction'] ['payment_data']['iban'])) {
                $parameters['transaction'] ['payment_data']['bic'] = !empty($sessionData['accountBic']) ? strtoupper(str_replace(' ', '', $sessionData['accountBic'])) : '';
            }
        }

        $dueDate = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.sepaDueDate', $salesChannelId);

        if (!empty($dueDate) && $dueDate >= 3 && $dueDate <= 14) {
            $parameters['transaction'] ['due_date'] = $this->helper->formatDueDate($dueDate);
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
