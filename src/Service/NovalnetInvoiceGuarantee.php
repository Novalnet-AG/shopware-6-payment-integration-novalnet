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

use Novalnet\NovalnetPayment\Service\NovalnetInvoice;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\SyncPaymentProcessException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class NovalnetInvoiceGuarantee extends AbstractNovalnetPaymentHandler implements SynchronousPaymentHandlerInterface
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
     * The recurring function will be called during recurring payment.
     * Allows to process the order and store additional information.
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws SyncPaymentProcessException
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
     * Prepare payment related parameters
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $data
     * @param array $parameters
     * @param string $salesChannelId
     */
    public function generatePaymentParameters($transaction, RequestDataBag $data = null, array &$parameters, string $salesChannelId): void
    {
        $sessionData = [];
        if ($this->helper->hasSession($this->paymentCode . 'FormData') && \version_compare($this->helper->getShopVersion(), '6.4.0', '<')) {
            $sessionData = $this->helper->getSession($this->paymentCode . 'FormData');
        } elseif (!empty($data->get($this->paymentCode . 'FormData'))) {
            $sessionData = $data->get($this->paymentCode . 'FormData')->all();
        } elseif (!empty($_REQUEST[$this->paymentCode . 'FormData'])) {
            $sessionData = $_REQUEST[$this->paymentCode . 'FormData'];
        }
        
        $invoiceTestMode  = $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.novalnetinvoiceTestMode", $salesChannelId);
        
        if(!empty($sessionData['doForceInvoicePayment']))
        {
            $this->paymentCode = 'novalnetinvoice';
            $parameters['transaction']['payment_type'] = 'INVOICE';
            $this->helper->setSession('currentNovalnetPaymentmethod', $this->paymentCode);
            $parameters['transaction']['test_mode'] = (int) !empty($invoiceTestMode);
            $paymentMethodEntity = $this->helper->getPaymentMethodEntity(NovalnetInvoice::class);
            if (!is_null($paymentMethodEntity))
            {
                $transaction->getOrderTransaction()->setPaymentMethodId($paymentMethodEntity->getId());
                $this->orderTransactionRepository->upsert([[
                    'id' => $transaction->getOrderTransaction()->getId(),
                    'paymentMethodId' => $paymentMethodEntity->getId()
                ]], Context::createDefaultContext());
            }
        }
        
        if (! empty($sessionData['dob']) || !empty($transaction->getOrder()->getOrderCustomer()->getCustomer()->getBirthday())) {
            $parameters['customer']['birth_date'] = !empty($sessionData['dob']) ? date('Y-m-d', strtotime($sessionData['dob'])) : date('Y-m-d', strtotime($transaction->getOrder()->getOrderCustomer()->getCustomer()->getBirthday()->format('Y-m-d')));
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
