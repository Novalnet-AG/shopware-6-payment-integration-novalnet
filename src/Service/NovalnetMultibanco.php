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
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class NovalnetMultibanco extends AbstractNovalnetPaymentHandler implements SynchronousPaymentHandlerInterface
{
    /** @var string */
    protected $novalnetPaymentType = 'MULTIBANCO';

    /** @var string */
    protected $paymentCode         = 'novalnetmultibanco';

    /** @var string */
    protected $paymentHandler      = NovalnetMultibanco::class;

    /** @var int */
    protected $position            = -1005;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Multibanco',
            'description' => 'Nach erfolgreichem Bestellabschluss erhalten Sie eine Zahlungsreferenz. Damit können Sie entweder an einem Multibanco-Geldautomaten oder im Onlinebanking bezahlen.',
        ],
        'en-GB' => [
            'name'        => 'Multibanco',
            'description' => 'On successful checkout, you will receive a payment reference. Using this payment reference, you can either pay in the Multibanco ATM or through your online bank account',
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
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws PaymentException
     */
    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        try {
            $response = $this->handlePaymentProcess($salesChannelContext, $transaction, $dataBag);
            $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
        } catch (\Exception $e) {
            throw PaymentException::syncProcessInterrupted($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway'.PHP_EOL.$e->getMessage());
        }
    }

    /**
     * Prepare payment related parameters
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $data
     * @param array $parameters
     * @param string $salesChannelId
     *
     * @return void
     */
    public function generatePaymentParameters($transaction, RequestDataBag $data = null, array &$parameters, string $salesChannelId): void
    {
    }

    /**
     * The recurring function will be called during recurring payment.
     * Allows to process the order and store additional information.
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @return boolean
     */
    public function recurring(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): bool
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
        return $this->helper->formMultibancoDetails($response, $context, $languageId);
    }
}
