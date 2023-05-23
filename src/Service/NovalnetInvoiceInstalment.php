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
use Shopware\Core\Checkout\Payment\Exception\SyncPaymentProcessException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class NovalnetInvoiceInstalment extends AbstractNovalnetPaymentHandler implements SynchronousPaymentHandlerInterface
{
    /** @var string */
    protected $novalnetPaymentType = 'INSTALMENT_INVOICE';
    
    /** @var string */
    protected $paymentCode         = 'novalnetinvoiceinstalment';
    
    /** @var string */
    protected $paymentHandler      = NovalnetInvoiceInstalment::class;
    
    /** @var int */
    protected $position            = -1017;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Ratenzahlung per Rechnung',
            'description' => 'Sie erhalten eine E-Mail mit den Bankdaten von Novalnet, um die Zahlung abzuschließen',
        ],
        'en-GB' => [
            'name'        => 'Instalment by Invoice',
            'description' => 'You will receive an e-mail with the Novalnet account details to complete the payment',
        ],
    ];
    
    /**
     * The pay function will be called after the customer completed the order.
     * Allows to process the order and store additional information.
     *
     * A redirect to the url will be performed
     *
     * Throw a @see SyncPaymentProcessException exception if an error ocurres while processing the payment
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws SyncPaymentProcessException
     */
    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        try {
            $response = $this->handlePaymentProcess($salesChannelContext, $transaction, $dataBag);
            $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
        } catch (\Exception $e) {
            throw new SyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway'.PHP_EOL.$e->getMessage());
        }
    }
        
    /**
     * Prepare payment related parameters
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $data
     * @param array $parameters
     * @param array $paymentSettings
     */
    public function generatePaymentParameters($transaction, RequestDataBag $data = null, array &$parameters, array $paymentSettings): void
    {
        $sessionData = [];
        if ($this->sessionInterface->has($this->paymentCode . 'FormData') && \version_compare($this->helper->getShopVersion(), '6.4.0', '<')) {
            $sessionData = $this->sessionInterface->get($this->paymentCode . 'FormData');
        } elseif (!empty($data->get('novalnetinvoiceinstalmentFormData'))) {
            $sessionData = $data->get('novalnetinvoiceinstalmentFormData')->all();
        } elseif (!empty($_REQUEST['novalnetinvoiceinstalmentFormData'])) {
            $sessionData = $_REQUEST['novalnetinvoiceinstalmentFormData'];
        }
        
        if (! empty($sessionData['dob']) || !empty($transaction->getOrder()->getOrderCustomer()->getCustomer()->getBirthday())) {
            $parameters['customer']['birth_date'] = !empty($sessionData['dob']) ? date('Y-m-d', strtotime($sessionData['dob'])) : date('Y-m-d', strtotime($transaction->getOrder()->getOrderCustomer()->getCustomer()->getBirthday()->format('Y-m-d')));
        }
        
        // Instalment data
        if (! empty($sessionData['duration']) || !empty($paymentSettings['NovalnetPayment.settings.invoiceinstalment.cycles'])) {
            $parameters['instalment']['interval']   = '1m';
            $parameters['instalment']['cycles']     = !empty($sessionData['duration']) ? $sessionData['duration'] : $paymentSettings['NovalnetPayment.settings.invoiceinstalment.cycles'][0];
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
