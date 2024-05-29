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

class NovalnetSepaInstalment extends AbstractNovalnetPaymentHandler implements SynchronousPaymentHandlerInterface
{
    /** @var string */
    protected $novalnetPaymentType = 'INSTALMENT_DIRECT_DEBIT_SEPA';

    /** @var string */
    protected $paymentCode         = 'novalnetsepainstalment';

    /** @var string */
    protected $paymentHandler      = NovalnetSepaInstalment::class;

    /** @var int */
    protected $position            = -1016;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Ratenzahlung per SEPA-Lastschrift',
            'description' => 'Der Betrag wird durch Novalnet von Ihrem Konto abgebucht',
        ],
        'en-GB' => [
            'name'        => 'Instalment by Direct Debit SEPA',
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
        $sessionData = [];

        if (!empty($data->get($this->paymentCode . 'FormData'))) {
            $sessionData = $data->get($this->paymentCode . 'FormData')->all();
        } elseif (!empty($_REQUEST[$this->paymentCode . 'FormData'])) {
            $sessionData = $_REQUEST[$this->paymentCode . 'FormData'];
        }

        $this->setPaymentToken($this->paymentCode, $data, $sessionData, $parameters);

        if (! empty($sessionData['dob']) || !empty($transaction->getOrder()->getOrderCustomer()->getCustomer()->getBirthday())) {
            $parameters['customer']['birth_date'] = !empty($sessionData['dob']) ? date('Y-m-d', strtotime($sessionData['dob'])) : date('Y-m-d', strtotime($transaction->getOrder()->getOrderCustomer()->getCustomer()->getBirthday()->format('Y-m-d')));
        }

        $cycles  = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.sepainstalment.cycles', $salesChannelId);
        $dueDate = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.sepainstalmentDueDate', $salesChannelId);

        // Instalment data
        if (! empty($sessionData['duration']) || !empty($cycles)) {
            $parameters['instalment']['interval']   = '1m';
            $parameters['instalment']['cycles']     = !empty($sessionData['duration']) ? $sessionData['duration'] : $cycles[0];
        }

        if (empty($parameters ['transaction'] ['payment_data'] ['token'])) {
            $parameters['transaction'] ['payment_data']['iban'] = $sessionData['accountData'] ? strtoupper(str_replace(' ', '', $sessionData['accountData'])) : '';

            if (!empty($sessionData['accountBic']) && preg_match("/(?:CH|MC|SM|GB)/", $parameters['transaction'] ['payment_data']['iban'])) {
                $parameters['transaction'] ['payment_data']['bic'] = !empty($sessionData['accountBic']) ? strtoupper(str_replace(' ', '', $sessionData['accountBic'])) : '';
            }
        }

        if (!empty($dueDate) && $dueDate >= 2 && $dueDate <= 14) {
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
